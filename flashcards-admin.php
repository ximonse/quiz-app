<?php
require_once 'config.php';
requireTeacher();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();
}

$csrf_field = csrfField();

$teacher_id = getCurrentTeacherID();
$teacher_name = $_SESSION['teacher_name'] ?? 'Lärare';

// Definiera flashcards-fil om den inte finns
if (!defined('FLASHCARDS_FILE')) {
    define('FLASHCARDS_FILE', DATA_DIR . 'flashcards.json');
}

$teachers = readJSON(TEACHERS_FILE);
$flashcards = readJSON(FLASHCARDS_FILE);
$stats = readJSON(STATS_FILE);

// Filtrera bara denna lärarens flashcard-decks
$my_decks = array_filter($flashcards, function($d) use ($teacher_id) {
    return $d['teacher_id'] === $teacher_id;
});

// Hantera deck-skapande
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create_deck_manual') {
        $title = trim($_POST['title'] ?? '');
        $cards_json = $_POST['cards'] ?? '';
        $language = $_POST['language'] ?? 'sv';
        $subject = trim($_POST['subject'] ?? '');
        $grade = trim($_POST['grade'] ?? '');
        $tags = trim($_POST['tags'] ?? '');

        if ($title && $cards_json) {
            $cards = json_decode($cards_json, true);
            if ($cards && count($cards) > 0) {
                $deck_id = generateID('deck_');
                $flashcards[$deck_id] = [
                    'id' => $deck_id,
                    'title' => $title,
                    'type' => 'flashcard',
                    'language' => $language,
                    'subject' => $subject,
                    'grade' => $grade,
                    'tags' => $tags,
                    'teacher_id' => $teacher_id,
                    'teacher_name' => $teacher_name,
                    'created' => date('Y-m-d H:i:s'),
                    'cards' => $cards
                ];
                writeJSON(FLASHCARDS_FILE, $flashcards);

                // Initiera statistik
                $stats[$deck_id] = [
                    'type' => 'flashcard',
                    'total_attempts' => 0,
                    'completed' => 0,
                    'avg_time_seconds' => 0,
                    'avg_grade' => 0,
                    'attempts' => [],
                    'card_difficulty' => []
                ];
                writeJSON(STATS_FILE, $stats);

                $success = "Flashcard-deck skapad! ID: $deck_id";
                $my_decks = array_filter($flashcards, function($d) use ($teacher_id) {
                    return $d['teacher_id'] === $teacher_id;
                });
            }
        }
    }

    if ($action === 'delete_deck') {
        $deck_id = $_POST['deck_id'] ?? '';
        if (isset($flashcards[$deck_id]) && $flashcards[$deck_id]['teacher_id'] === $teacher_id) {
            unset($flashcards[$deck_id]);
            writeJSON(FLASHCARDS_FILE, $flashcards);

            // Ta bort statistik också
            if (isset($stats[$deck_id])) {
                unset($stats[$deck_id]);
                writeJSON(STATS_FILE, $stats);
            }

            $success = "Deck raderat!";
            $my_decks = array_filter($flashcards, function($d) use ($teacher_id) {
                return $d['teacher_id'] === $teacher_id;
            });
        }
    }

    if ($action === 'toggle_deck') {
        $deck_id = $_POST['deck_id'] ?? '';
        if (isset($flashcards[$deck_id]) && $flashcards[$deck_id]['teacher_id'] === $teacher_id) {
            $flashcards[$deck_id]['active'] = !($flashcards[$deck_id]['active'] ?? true);
            writeJSON(FLASHCARDS_FILE, $flashcards);

            $status = $flashcards[$deck_id]['active'] ? 'aktiverat' : 'inaktiverat';
            $success = "Deck $status!";
            $my_decks = array_filter($flashcards, function($d) use ($teacher_id) {
                return $d['teacher_id'] === $teacher_id;
            });
        }
    }
}

// Hantera CSV-uppladdning eller inklistrad CSV
if ((isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) || !empty($_POST['csv_paste'])) {
    $title = trim($_POST['csv_title'] ?? '');
    $language = $_POST['csv_language'] ?? 'sv';
    $subject = trim($_POST['csv_subject'] ?? '');
    $grade = trim($_POST['csv_grade'] ?? '');
    $tags = trim($_POST['csv_tags'] ?? '');

    if (!$title) {
        $error = "Du måste ange en titel för decket";
    } else {
        $cards = [];

        // Om inklistrad CSV, skapa temporär fil
        if (!empty($_POST['csv_paste'])) {
            $file = tmpfile();
            fwrite($file, $_POST['csv_paste']);
            fseek($file, 0);
        } else {
            $file = $_FILES['csv_file']['tmp_name'];
            $file = fopen($file, 'r');
        }

        if ($file) {
            $row = 0;
            while (($data = fgetcsv($file, 1000, ',')) !== FALSE) {
                $row++;
                if ($row === 1) continue; // Skippa header

                // Format: Front, Back
                if (count($data) >= 2) {
                    $front = trim($data[0]);
                    $back = trim($data[1]);

                    if ($front && $back) {
                        $cards[] = [
                            'front' => $front,
                            'back' => $back
                        ];
                    }
                }
            }
            fclose($file);

            if (count($cards) > 0) {
                $deck_id = generateID('deck_');
                $flashcards[$deck_id] = [
                    'id' => $deck_id,
                    'title' => $title,
                    'type' => 'flashcard',
                    'language' => $language,
                    'subject' => $subject,
                    'grade' => $grade,
                    'tags' => $tags,
                    'teacher_id' => $teacher_id,
                    'teacher_name' => $teacher_name,
                    'created' => date('Y-m-d H:i:s'),
                    'cards' => $cards
                ];
                writeJSON(FLASHCARDS_FILE, $flashcards);

                // Initiera statistik
                $stats[$deck_id] = [
                    'type' => 'flashcard',
                    'total_attempts' => 0,
                    'completed' => 0,
                    'avg_time_seconds' => 0,
                    'avg_grade' => 0,
                    'attempts' => [],
                    'card_difficulty' => []
                ];
                writeJSON(STATS_FILE, $stats);

                $success = "Deck skapad från CSV! " . count($cards) . " kort laddades. ID: $deck_id";
                $my_decks = array_filter($flashcards, function($d) use ($teacher_id) {
                    return $d['teacher_id'] === $teacher_id;
                });
            } else {
                $error = "Inga giltiga kort hittades i CSV-filen";
            }
        } else {
            $error = "Kunde inte läsa CSV-filen";
        }
    }
}

// Räkna statistik för denna lärare
$my_total_attempts = 0;
$my_total_completed = 0;
foreach ($my_decks as $did => $deck) {
    if (isset($stats[$did])) {
        $my_total_attempts += $stats[$did]['total_attempts'] ?? 0;
        $my_total_completed += $stats[$did]['completed'] ?? 0;
    }
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flashcard Admin - <?= htmlspecialchars($teacher_name) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body class="bg-gradient-to-br from-green-50 to-blue-50 min-h-screen p-4">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">🗂️ Flashcard Admin</h1>
                    <p class="text-gray-500">Hej <?= htmlspecialchars($teacher_name) ?>! Hantera dina flashcard-decks här</p>
                </div>
                <div class="flex gap-2">
                    <a href="admin.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        📝 Quiz Admin
                    </a>
                    <a href="index.php?logout=1" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg">
                        Logga ut
                    </a>
                </div>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Statistik -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Mina flashcard-decks</div>
                <div class="text-3xl font-bold text-green-600"><?= count($my_decks) ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Totalt antal sessioner</div>
                <div class="text-3xl font-bold text-purple-600"><?= $my_total_attempts ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Antal genomförda</div>
                <div class="text-3xl font-bold text-blue-600"><?= $my_total_completed ?></div>
            </div>
        </div>

        <!-- Skapa nytt deck -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">➕ Skapa nytt flashcard-deck</h2>

            <!-- Tabs -->
            <div class="flex flex-wrap gap-2 mb-4 border-b">
                <button onclick="showTab('excel')" id="tab-excel" class="px-4 py-2 font-medium border-b-2 border-green-500 text-green-600">
                    📊 Excel med bilder
                </button>
                <button onclick="showTab('csv')" id="tab-csv" class="px-4 py-2 font-medium text-gray-500 hover:text-gray-700">
                    CSV-uppladdning
                </button>
                <button onclick="showTab('paste')" id="tab-paste" class="px-4 py-2 font-medium text-gray-500 hover:text-gray-700">
                    Klistra in CSV
                </button>
                <button onclick="showTab('manual')" id="tab-manual" class="px-4 py-2 font-medium text-gray-500 hover:text-gray-700">
                    Manuell inmatning
                </button>
            </div>

            <!-- Excel Tab -->
            <div id="content-excel" class="tab-content">
                <div class="space-y-4">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                        <h4 class="font-bold text-blue-800 mb-2">📊 Excel-format med bilder i celler</h4>
                        <p class="text-blue-700 text-sm mb-2">
                            Skapa en Excel-fil (.xlsx) med följande struktur:
                        </p>
                        <ul class="text-blue-700 text-sm list-disc list-inside space-y-1">
                            <li><strong>Kolumn A:</strong> Begrepp (framsidan av kortet)</li>
                            <li><strong>Kolumn B:</strong> Definition (baksidan av kortet)</li>
                            <li><strong>Kolumn C:</strong> Bild (klistra in bilden direkt i cellen - valfritt)</li>
                        </ul>
                        <p class="text-blue-600 text-xs mt-2">
                            💡 Tips: Första raden används som rubrikrad och hoppas över. Bilder extraheras automatiskt!
                        </p>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Deck-titel *</label>
                        <input type="text" id="excel_title" required
                               placeholder="t.ex. Biologibegrepp med bilder"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Ämne</label>
                            <input type="text" id="excel_subject"
                                   placeholder="t.ex. Biologi, NO"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Årskurs</label>
                            <input type="text" id="excel_grade"
                                   placeholder="t.ex. åk 7, åk 9"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Egna taggar</label>
                            <input type="text" id="excel_tags"
                                   placeholder="Komma-separerade"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Språk för TTS</label>
                            <select id="excel_language"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                                <option value="sv">Svenska</option>
                                <option value="en">Engelska</option>
                                <option value="mongolian">Mongoliska</option>
                                <option value="uk">Ukrainska</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Visa bilder på</label>
                            <select id="excel_image_side"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                                <option value="front">Framsidan (tillsammans med begreppet)</option>
                                <option value="back">Baksidan (tillsammans med definitionen)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Excel-fil (.xlsx) *</label>
                        <input type="file" id="excel_file" accept=".xlsx" required
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                    </div>

                    <button type="button" onclick="uploadExcel()" id="excel_submit_btn"
                            class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg">
                        📊 Importera från Excel
                    </button>

                    <div id="excel_status" class="hidden mt-4 p-4 rounded-lg"></div>
                </div>
            </div>

            <!-- CSV Tab -->
            <div id="content-csv" class="tab-content hidden">
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <?= $csrf_field ?>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Deck-titel</label>
                        <input type="text" name="csv_title" required
                               placeholder="t.ex. Spanska glosor vecka 1"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Ämne</label>
                            <input type="text" name="csv_subject"
                                   placeholder="t.ex. Spanska, Engelska"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Årskurs</label>
                            <input type="text" name="csv_grade"
                                   placeholder="t.ex. åk 7, åk 9"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Egna taggar</label>
                            <input type="text" name="csv_tags"
                                   placeholder="Komma-separerade"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Språk för TTS (text-to-speech)</label>
                        <select name="csv_language"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="sv">Svenska</option>
                            <option value="en">Engelska</option>
                            <option value="mongolian">Mongoliska (fallback: svenska)</option>
                            <option value="uk">Ukrainska</option>
                        </select>
                        <p class="text-sm text-gray-500 mt-1">
                            💡 Detta språk används för röstuppläsning av kort
                        </p>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-medium mb-2">CSV-fil</label>
                        <input type="file" name="csv_file" accept=".csv" required
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                        <p class="text-sm text-gray-500 mt-2">
                            <strong>Format:</strong> Front,Back<br>
                            <strong>Exempel:</strong><br>
                            perro,hund<br>
                            gato,katt<br>
                            casa,hus
                        </p>
                    </div>
                    <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg">
                        Skapa deck från CSV-fil
                    </button>
                </form>
            </div>

            <!-- Klistra in CSV Tab -->
            <div id="content-paste" class="tab-content hidden">
                <form method="POST" class="space-y-4">
                    <?= $csrf_field ?>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Deck-titel</label>
                        <input type="text" name="csv_title" required
                               placeholder="t.ex. Spanska glosor vecka 1"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Ämne</label>
                            <input type="text" name="csv_subject"
                                   placeholder="t.ex. Spanska, Engelska"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Årskurs</label>
                            <input type="text" name="csv_grade"
                                   placeholder="t.ex. åk 7, åk 9"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Egna taggar</label>
                            <input type="text" name="csv_tags"
                                   placeholder="Komma-separerade"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Språk för TTS</label>
                        <select name="csv_language"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="sv">Svenska</option>
                            <option value="en">Engelska</option>
                            <option value="mongolian">Mongoliska (fallback: svenska)</option>
                            <option value="uk">Ukrainska</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Klistra in CSV-data</label>
                        <textarea name="csv_paste" rows="10" required
                                  placeholder="Front,Back&#10;perro,hund&#10;gato,katt&#10;casa,hus"
                                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 font-mono text-sm"></textarea>
                        <p class="text-sm text-gray-500 mt-2">
                            <strong>Format:</strong> Front,Back (ett kort per rad)
                        </p>
                    </div>

                    <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg">
                        Skapa deck från inklistrad text
                    </button>
                </form>
            </div>

            <!-- Manuell Tab -->
            <div id="content-manual" class="tab-content hidden">
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Deck-titel</label>
                        <input type="text" id="manual_title"
                               placeholder="t.ex. Spanska glosor vecka 1"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Ämne</label>
                            <input type="text" id="manual_subject"
                                   placeholder="t.ex. Spanska, Engelska"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Årskurs</label>
                            <input type="text" id="manual_grade"
                                   placeholder="t.ex. åk 7, åk 9"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Egna taggar</label>
                            <input type="text" id="manual_tags"
                                   placeholder="Komma-separerade"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Språk för TTS</label>
                        <select id="manual_language"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="sv">Svenska</option>
                            <option value="en">Engelska</option>
                            <option value="mongolian">Mongoliska (fallback: svenska)</option>
                            <option value="uk">Ukrainska</option>
                        </select>
                    </div>

                    <div id="cards-container" class="space-y-4">
                        <!-- Kort läggs till här -->
                    </div>

                    <button onclick="addCard()" type="button" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                        + Lägg till kort
                    </button>

                    <button onclick="createManualDeck()" type="button" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg">
                        Skapa deck
                    </button>
                </div>
            </div>
        </div>

        <!-- Lista över decks -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">🗂️ Mina flashcard-decks (<?= count($my_decks) ?>)</h2>

            <?php if (!empty($my_decks)): ?>
                <!-- Filter -->
                <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Ämne</label>
                            <select id="filter-subject" onchange="filterDecks()" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                <option value="">Alla</option>
                                <?php
                                    $subjects = array_unique(array_filter(array_map(function($d) { return $d['subject'] ?? ''; }, $my_decks)));
                                    foreach ($subjects as $subject):
                                ?>
                                    <option value="<?= htmlspecialchars($subject) ?>"><?= htmlspecialchars($subject) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Årskurs</label>
                            <select id="filter-grade" onchange="filterDecks()" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                <option value="">Alla</option>
                                <?php
                                    $grades = array_unique(array_filter(array_map(function($d) { return $d['grade'] ?? ''; }, $my_decks)));
                                    foreach ($grades as $grade):
                                ?>
                                    <option value="<?= htmlspecialchars($grade) ?>"><?= htmlspecialchars($grade) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Språk</label>
                            <select id="filter-language" onchange="filterDecks()" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                <option value="">Alla</option>
                                <option value="sv">Svenska</option>
                                <option value="en">Engelska</option>
                                <option value="mongolian">Mongoliska</option>
                                <option value="uk">Ukrainska</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Sök</label>
                            <input type="text" id="filter-search" onkeyup="filterDecks()" placeholder="Titel eller taggar..."
                                   class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                        </div>
                    </div>
                    <div class="mt-2 text-right">
                        <button onclick="clearFilters()" class="text-xs text-gray-500 hover:text-gray-700 underline">
                            Rensa filter
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($my_decks)): ?>
                <p class="text-gray-500 text-center py-8">Inga flashcard-decks ännu. Skapa ditt första!</p>
            <?php else: ?>
                <div id="deck-list" class="space-y-4">
                    <?php foreach ($my_decks as $did => $deck): ?>
                        <?php
                            $deck_stats = $stats[$did] ?? ['total_attempts' => 0, 'completed' => 0];
                            $is_active = $deck['active'] ?? true;
                            $card_class = $is_active ? 'border-gray-200' : 'border-gray-300 bg-gray-50';
                            $text_class = $is_active ? 'text-gray-800' : 'text-gray-400';
                        ?>
                        <div class="deck-card border rounded-lg p-4 hover:shadow-md transition <?= $card_class ?>"
                             data-subject="<?= htmlspecialchars($deck['subject'] ?? '') ?>"
                             data-grade="<?= htmlspecialchars($deck['grade'] ?? '') ?>"
                             data-language="<?= htmlspecialchars($deck['language'] ?? '') ?>"
                             data-tags="<?= htmlspecialchars($deck['tags'] ?? '') ?>"
                             data-title="<?= htmlspecialchars($deck['title']) ?>">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-2xl">🗂️</span>
                                        <h3 class="text-xl font-bold <?= $text_class ?>"><?= htmlspecialchars($deck['title']) ?></h3>
                                    </div>
                                    <p class="text-sm <?= $is_active ? 'text-gray-500' : 'text-gray-400' ?>">
                                        <?= count($deck['cards']) ?> kort •
                                        Skapad <?= date('Y-m-d', strtotime($deck['created'])) ?> •
                                        Språk: <?= htmlspecialchars($deck['language'] ?? 'sv') ?>
                                        <?php if (!$is_active): ?>
                                            • <span class="text-red-500 font-medium">INAKTIV</span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!empty($deck['subject']) || !empty($deck['grade']) || !empty($deck['tags'])): ?>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <?php if (!empty($deck['subject'])): ?>
                                                <span class="inline-block px-2 py-1 bg-blue-100 text-blue-700 text-xs rounded">
                                                    📖 <?= htmlspecialchars($deck['subject']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($deck['grade'])): ?>
                                                <span class="inline-block px-2 py-1 bg-purple-100 text-purple-700 text-xs rounded">
                                                    🎓 <?= htmlspecialchars($deck['grade']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($deck['tags'])): ?>
                                                <?php foreach (explode(',', $deck['tags']) as $tag): ?>
                                                    <span class="inline-block px-2 py-1 bg-gray-100 text-gray-700 text-xs rounded">
                                                        🏷️ <?= htmlspecialchars(trim($tag)) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="mt-2 flex gap-4 text-sm">
                                        <span class="<?= $is_active ? 'text-gray-600' : 'text-gray-400' ?>">
                                            🎯 <?= $deck_stats['total_attempts'] ?> sessioner
                                        </span>
                                        <span class="<?= $is_active ? 'text-green-600' : 'text-gray-400' ?>">
                                            ✅ <?= $deck_stats['completed'] ?> genomförda
                                        </span>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <form method="POST" class="inline">
                                        <?= $csrf_field ?>
                                        <input type="hidden" name="action" value="toggle_deck">
                                        <input type="hidden" name="deck_id" value="<?= $did ?>">
                                        <button type="submit" class="<?= $is_active ? 'bg-yellow-500 hover:bg-yellow-600' : 'bg-green-500 hover:bg-green-600' ?> text-white px-3 py-1.5 rounded text-sm whitespace-nowrap">
                                            <?= $is_active ? '👁️ Inaktivera' : '✅ Aktivera' ?>
                                        </button>
                                    </form>
                                    <a href="q/flashcards.php?deck_id=<?= $did ?>" target="_blank"
                                       class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1.5 rounded text-center text-sm whitespace-nowrap">
                                        Öppna
                                    </a>
                                    <button onclick="copyLink('<?= $did ?>')"
                                            class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-3 py-1.5 rounded text-sm whitespace-nowrap">
                                        Kopiera
                                    </button>
                                    <button onclick="showQrCode(deckUrl('<?= $did ?>'), '<?= htmlspecialchars($deck['title'], ENT_QUOTES) ?>')"
                                            class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-3 py-1.5 rounded text-sm whitespace-nowrap">
                                        📱 QR
                                    </button>
                                    <a href="stats.php?flashcard_id=<?= $did ?>"
                                       class="bg-purple-500 hover:bg-purple-600 text-white px-3 py-1.5 rounded text-center text-sm whitespace-nowrap">
                                        Statistik
                                    </a>
                                    <a href="edit-flashcard.php?deck_id=<?= $did ?>"
                                       class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded text-center text-sm whitespace-nowrap">
                                        Redigera
                                    </a>
                                    <form method="POST" class="inline" onsubmit="return confirm('Är du säker?')">
                                        <?= $csrf_field ?>
                                        <input type="hidden" name="action" value="delete_deck">
                                        <input type="hidden" name="deck_id" value="<?= $did ?>">
                                        <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1.5 rounded text-sm whitespace-nowrap">
                                            Radera
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- QR-kod modal -->
    <div id="qr-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50" onclick="if(event.target===this) closeQrModal()">
        <div class="bg-white rounded-xl shadow-lg p-6 max-w-sm w-full mx-4 text-center">
            <h3 id="qr-modal-title" class="text-lg font-bold text-gray-800 mb-3"></h3>
            <div id="qr-modal-code" class="flex justify-center mb-3"></div>
            <p id="qr-modal-url" class="text-xs text-gray-500 break-all mb-4"></p>
            <div class="flex gap-2 justify-center">
                <a id="qr-modal-download" download="quiz-qr.png" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1.5 rounded text-sm">
                    Ladda ner
                </a>
                <button onclick="closeQrModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-3 py-1.5 rounded text-sm">
                    Stäng
                </button>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = <?= json_encode(getCsrfToken()) ?>;
        let cardCount = 0;

        function showTab(tab) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('[id^="tab-"]').forEach(el => {
                el.classList.remove('border-green-500', 'text-green-600');
                el.classList.add('text-gray-500');
            });

            document.getElementById('content-' + tab).classList.remove('hidden');
            document.getElementById('tab-' + tab).classList.add('border-green-500', 'text-green-600');
            document.getElementById('tab-' + tab).classList.remove('text-gray-500');
        }

        function addCard() {
            cardCount++;
            const container = document.getElementById('cards-container');
            const div = document.createElement('div');
            div.className = 'border border-gray-200 rounded-lg p-4';
            div.dataset.cardId = cardCount;

            div.innerHTML = `
                <div class="flex justify-between items-center mb-2">
                    <h4 class="font-bold">🗂️ Kort ${cardCount}</h4>
                    <button type="button" onclick="this.parentElement.parentElement.remove()" class="text-red-600 hover:text-red-800">Radera</button>
                </div>
                <div class="space-y-2">
                    <input type="text" placeholder="Front (t.ex. 'perro')" class="card-front w-full px-3 py-2 border rounded">
                    <input type="text" placeholder="Back (t.ex. 'hund')" class="card-back w-full px-3 py-2 border rounded">
                </div>
            `;
            container.appendChild(div);
        }

        function createManualDeck() {
            const title = document.getElementById('manual_title').value.trim();
            const language = document.getElementById('manual_language').value;
            const subject = document.getElementById('manual_subject').value.trim();
            const grade = document.getElementById('manual_grade').value.trim();
            const tags = document.getElementById('manual_tags').value.trim();

            if (!title) {
                alert('Du måste ange en titel');
                return;
            }

            const cards = [];
            document.querySelectorAll('#cards-container > div').forEach(div => {
                const front = div.querySelector('.card-front').value.trim();
                const back = div.querySelector('.card-back').value.trim();

                if (front && back) {
                    cards.push({
                        front: front,
                        back: back
                    });
                }
            });

            if (cards.length === 0) {
                alert('Du måste lägga till minst ett kort');
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="create_deck_manual">
                <input type="hidden" name="csrf_token" value="${csrfToken}">
                <input type="hidden" name="title" value="${title}">
                <input type="hidden" name="language" value="${language}">
                <input type="hidden" name="subject" value="${subject}">
                <input type="hidden" name="grade" value="${grade}">
                <input type="hidden" name="tags" value="${tags}">
                <input type="hidden" name="cards" value='${JSON.stringify(cards)}'>
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function deckUrl(deckId) {
            return window.location.origin + window.location.pathname.replace('flashcards-admin.php', '') + 'q/flashcards.php?deck_id=' + deckId;
        }

        function copyLink(deckId) {
            const url = deckUrl(deckId);
            navigator.clipboard.writeText(url).then(() => {
                alert('Länk kopierad! ' + url);
            });
        }

        function showQrCode(url, title) {
            const container = document.getElementById('qr-modal-code');
            container.innerHTML = '';
            new QRCode(container, {
                text: url,
                width: 220,
                height: 220
            });
            document.getElementById('qr-modal-title').textContent = title || 'QR-kod';
            document.getElementById('qr-modal-url').textContent = url;
            document.getElementById('qr-modal').classList.remove('hidden');
            document.getElementById('qr-modal').classList.add('flex');

            setTimeout(() => {
                const canvas = container.querySelector('canvas');
                const downloadLink = document.getElementById('qr-modal-download');
                if (canvas) {
                    downloadLink.href = canvas.toDataURL('image/png');
                } else {
                    const img = container.querySelector('img');
                    downloadLink.href = img ? img.src : '#';
                }
            }, 50);
        }

        function closeQrModal() {
            document.getElementById('qr-modal').classList.add('hidden');
            document.getElementById('qr-modal').classList.remove('flex');
        }

        function filterDecks() {
            const subjectFilter = document.getElementById('filter-subject').value.toLowerCase();
            const gradeFilter = document.getElementById('filter-grade').value.toLowerCase();
            const languageFilter = document.getElementById('filter-language').value.toLowerCase();
            const searchFilter = document.getElementById('filter-search').value.toLowerCase();

            const cards = document.querySelectorAll('.deck-card');
            cards.forEach(card => {
                const subject = card.dataset.subject.toLowerCase();
                const grade = card.dataset.grade.toLowerCase();
                const language = card.dataset.language.toLowerCase();
                const tags = card.dataset.tags.toLowerCase();
                const title = card.dataset.title.toLowerCase();

                const matchSubject = !subjectFilter || subject === subjectFilter;
                const matchGrade = !gradeFilter || grade === gradeFilter;
                const matchLanguage = !languageFilter || language === languageFilter;
                const matchSearch = !searchFilter ||
                                   title.includes(searchFilter) ||
                                   tags.includes(searchFilter) ||
                                   subject.includes(searchFilter);

                if (matchSubject && matchGrade && matchLanguage && matchSearch) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function clearFilters() {
            document.getElementById('filter-subject').value = '';
            document.getElementById('filter-grade').value = '';
            document.getElementById('filter-language').value = '';
            document.getElementById('filter-search').value = '';
            filterDecks();
        }

        async function uploadExcel() {
            const title = document.getElementById('excel_title').value.trim();
            const file = document.getElementById('excel_file').files[0];
            const statusDiv = document.getElementById('excel_status');
            const submitBtn = document.getElementById('excel_submit_btn');

            if (!title) {
                alert('Du måste ange en titel');
                return;
            }

            if (!file) {
                alert('Du måste välja en Excel-fil');
                return;
            }

            // Visa laddningsstatus
            statusDiv.className = 'mt-4 p-4 rounded-lg bg-blue-100 text-blue-700';
            statusDiv.innerHTML = '⏳ Importerar Excel-fil med bilder...';
            statusDiv.classList.remove('hidden');
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50');

            const formData = new FormData();
            formData.append('excel_file', file);
            formData.append('title', title);
            formData.append('language', document.getElementById('excel_language').value);
            formData.append('subject', document.getElementById('excel_subject').value.trim());
            formData.append('grade', document.getElementById('excel_grade').value.trim());
            formData.append('tags', document.getElementById('excel_tags').value.trim());
            formData.append('image_side', document.getElementById('excel_image_side').value);
            formData.append('csrf_token', csrfToken);

            try {
                const response = await fetch('api/import-excel-flashcard.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    statusDiv.className = 'mt-4 p-4 rounded-lg bg-green-100 text-green-700';
                    statusDiv.innerHTML = `
                        ✅ <strong>Import lyckades!</strong><br>
                        📝 ${result.cards_count} kort importerades<br>
                        🖼️ ${result.cards_with_images} kort med bilder<br>
                        ${result.skipped_rows > 0 ? `⚠️ ${result.skipped_rows} rader hoppades över (saknade text)` : ''}
                        <br><br>
                        <a href="q/flashcards.php?deck_id=${result.deck_id}" target="_blank" class="text-blue-600 underline">Öppna deck</a> |
                        <a href="edit-flashcard.php?deck_id=${result.deck_id}" class="text-blue-600 underline">Redigera</a>
                    `;

                    // Ladda om sidan efter 2 sekunder
                    setTimeout(() => window.location.reload(), 2000);
                } else {
                    statusDiv.className = 'mt-4 p-4 rounded-lg bg-red-100 text-red-700';
                    statusDiv.innerHTML = '❌ <strong>Fel:</strong> ' + (result.error || 'Okänt fel');
                }
            } catch (error) {
                statusDiv.className = 'mt-4 p-4 rounded-lg bg-red-100 text-red-700';
                statusDiv.innerHTML = '❌ <strong>Nätverksfel:</strong> ' + error.message;
            }

            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-50');
        }
    </script>
</body>
</html>
