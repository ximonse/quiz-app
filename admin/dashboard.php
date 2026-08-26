<?php
// admin/dashboard.php — Quiz list + create on one page
require_once __DIR__ . '/../config.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }

$csrfToken = getCsrfToken();
$teacherId = getCurrentTeacherID();
$teacherName = $_SESSION['teacher_name'] ?? 'Lärare';
$error = '';
$success = '';

// --- Handle delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    requireValidCsrf();
    $deleteId = $_POST['quiz_id'] ?? '';
    $quizzes = readJSON(QUIZZES_FILE);
    if (isset($quizzes[$deleteId]) && ($quizzes[$deleteId]['teacher_id'] ?? '') === $teacherId) {
        unset($quizzes[$deleteId]);
        writeJSON(QUIZZES_FILE, $quizzes);
        $success = 'Quiz raderad!';
    }
}

// --- Handle create ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    requireValidCsrf();
    $type = $_POST['type'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $csvData = trim($_POST['csv_data'] ?? '');

    if (!$title || !$csvData || !in_array($type, ['glossary', 'fact'])) {
        $error = 'Fyll i alla fält.';
    } else {
        $items = parseCSV($csvData, $type);
        if (empty($items)) {
            $error = 'Kunde inte tolka CSV-data. Kontrollera formatet.';
        } else {
            $quizId = 'q_' . bin2hex(random_bytes(5));
            $answerMode = $_POST['answer_mode'] ?? 'multiple_choice';
            $reverseEnabled = isset($_POST['reverse_enabled']);
            $reverseAnswerMode = $_POST['reverse_answer_mode'] ?? 'multiple_choice';

            $quiz = [
                'id' => $quizId,
                'title' => $title,
                'type' => $type,
                'subject' => trim($_POST['subject'] ?? ''),
                'tags' => trim($_POST['tags'] ?? ''),
                'created' => date('Y-m-d H:i:s'),
                'teacher_id' => $teacherId,
                'settings' => [
                    'answer_mode' => $answerMode,
                    'mc_count' => intval($_POST['mc_count'] ?? count($items)),
                    'text_count' => intval($_POST['text_count'] ?? 0),
                    'required_correct' => max(1, intval($_POST['required_correct'] ?? 1)),
                    'reverse_enabled' => $reverseEnabled,
                    'reverse_answer_mode' => $reverseAnswerMode,
                    'reverse_mc_count' => intval($_POST['reverse_mc_count'] ?? count($items)),
                    'reverse_text_count' => intval($_POST['reverse_text_count'] ?? 0),
                    'reverse_required_correct' => max(1, intval($_POST['reverse_required_correct'] ?? 1)),
                    'quiz_mode' => $_POST['quiz_mode'] ?? 'training',
                    'tts_enabled' => isset($_POST['tts_enabled']),
                    'language' => $_POST['language'] ?? 'sv',
                    'spelling_mode' => $_POST['spelling_mode'] ?? 'student_choice',
                    'time_lock' => null,
                    'generate_flashcards' => isset($_POST['generate_flashcards']),
                ],
                'items' => $items,
                'results' => []
            ];

            $opens = trim($_POST['time_lock_opens'] ?? '');
            $closes = trim($_POST['time_lock_closes'] ?? '');
            if ($opens || $closes) {
                $quiz['settings']['time_lock'] = [
                    'opens' => $opens ?: null,
                    'closes' => $closes ?: null
                ];
            }

            $quizzes = readJSON(QUIZZES_FILE);
            $quizzes[$quizId] = $quiz;
            writeJSON(QUIZZES_FILE, $quizzes);
            $success = "Quiz \"{$title}\" skapad!";
        }
    }
}

function parseCSV($csvText, $type) {
    $lines = array_filter(array_map('trim', explode("\n", $csvText)));
    $items = [];
    $first = strtolower($lines[0] ?? '');
    if (str_contains($first, 'mening') || str_contains($first, 'begrepp')) {
        array_shift($lines);
    }
    foreach ($lines as $line) {
        $cols = array_map('trim', explode(';', $line));
        if ($type === 'glossary') {
            if (count($cols) < 6) continue;
            $items[] = [
                'sentence' => $cols[0],
                'word' => $cols[1],
                'translation' => $cols[2],
                'wrong_options' => array_filter([$cols[3] ?? '', $cols[4] ?? '', $cols[5] ?? '']),
                'reverse_wrong_options' => array_filter([$cols[6] ?? '', $cols[7] ?? '', $cols[8] ?? ''])
            ];
        } else {
            if (count($cols) < 5) continue;
            $items[] = [
                'concept' => $cols[0],
                'description' => $cols[1],
                'wrong_options' => array_filter([$cols[2] ?? '', $cols[3] ?? '', $cols[4] ?? ''])
            ];
        }
    }
    return $items;
}

// Load quizzes for list
$quizzes = readJSON(QUIZZES_FILE);
$myQuizzes = array_filter($quizzes, fn($q) => ($q['teacher_id'] ?? '') === $teacherId);
$myQuizzes = array_values($myQuizzes);
usort($myQuizzes, fn($a, $b) => strcmp($b['created'] ?? '', $a['created'] ?? ''));
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mina Quiz</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../engine/themes.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-50 to-purple-50">
<div class="max-w-4xl mx-auto p-4">

    <!-- Header -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Hej <?= htmlspecialchars($teacherName) ?>!</h1>
                <p class="text-gray-500 text-sm">Hantera dina quizzes</p>
            </div>
            <a href="../index.php?logout=1" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm">Logga ut</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="mb-4 p-3 bg-green-100 text-green-700 rounded-lg text-sm"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ═══════════ SKAPA QUIZ ═══════════ -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <h2 class="text-lg font-bold text-gray-800 mb-4">Skapa nytt quiz</h2>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">

            <!-- Typ (knappar) -->
            <input type="hidden" name="type" id="type-input" value="glossary">
            <div class="flex gap-3 mb-2">
                <button type="button" id="btn-glossary" onclick="selectType('glossary')"
                    class="flex-1 py-3 rounded-xl border-2 font-bold text-lg transition-all border-purple-500 bg-purple-50 text-purple-700 shadow-md">
                    Glosquiz
                </button>
                <button type="button" id="btn-fact" onclick="selectType('fact')"
                    class="flex-1 py-3 rounded-xl border-2 font-bold text-lg transition-all border-gray-200 bg-white text-gray-500 hover:border-blue-400">
                    Faktaquiz
                </button>
            </div>

            <!-- Titel + Ämne + Taggar -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Titel</label>
                    <input type="text" name="title" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="T.ex. Spanska vecka 12">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ämne</label>
                    <input type="text" name="subject" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="T.ex. Spanska">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Taggar</label>
                    <input type="text" name="tags" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="vecka12, åk6">
                </div>
            </div>

            <!-- CSV Data -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">CSV-data (semikolon-separerad)</label>
                <div id="csv-hint-glossary" class="text-xs text-gray-500 mb-1">Format: mening;ord;översättning;fel1;fel2;fel3;omvänt_fel1;omvänt_fel2;omvänt_fel3</div>
                <div id="csv-hint-fact" class="text-xs text-gray-500 mb-1 hidden">Format: begrepp;beskrivning;fel1;fel2;fel3</div>
                <textarea name="csv_data" required rows="6" class="w-full px-3 py-2 border border-gray-300 rounded-lg font-mono text-sm focus:ring-2 focus:ring-blue-500" placeholder="Klistra in CSV här..."></textarea>

                <!-- AI Prompt Helper -->
                <div class="mt-2">
                    <button type="button" onclick="toggleAiPrompt()" class="flex items-center text-purple-600 hover:text-purple-800 font-medium text-xs">
                        <span class="mr-1">🤖</span> Behöver du hjälp att skapa CSV? Klicka här för AI-prompt
                    </button>
                    <div id="ai-prompt-box" class="hidden mt-2 p-3 bg-purple-50 border border-purple-100 rounded-lg">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-xs font-bold text-purple-800">Kopiera denna prompt till ChatGPT/Claude:</span>
                            <button type="button" onclick="copyAiPrompt()" class="bg-purple-200 hover:bg-purple-300 text-purple-800 px-2 py-1 rounded text-xs transition">
                                📋 Kopiera prompt
                            </button>
                        </div>
                        <pre id="prompt-glossary" class="text-xs bg-white p-2 rounded border border-gray-200 overflow-x-auto whitespace-pre-wrap text-gray-600">Du är en expert på pedagogik. Jag vill skapa glosquiz-material.

Processen sker i två steg:

STEG 1: ANALYS & FÖRSLAG
1. Analysera texten/bilden jag bifogar.
2. Föreslå 10-20 relevanta glosor.
3. Fråga mig om jag vill ändra något.

STEG 2: GENERERING (Efter mitt godkännande)
Skapa CSV-text med semikolon som separator.

FORMAT (en rad per glosa):
mening;ord;översättning;fel1;fel2;fel3;omvänt_fel1;omvänt_fel2;omvänt_fel3

FÖRKLARING:
- "mening": En exempelmening där ordet används.
- "ord": Glosans ord på källspråket.
- "översättning": Översättning till svenska.
- "fel1-3": 3 trovärdiga felaktiga svenska översättningar.
- "omvänt_fel1-3": 3 trovärdiga felaktiga ord på källspråket.
- Inga citattecken. Inga radbrytningar i celler.
- Ingen header-rad.

EXEMPEL:
Hola, me llamo Roberto;llamo;heter;bor;läser;springer;vive;toma;mira

Nu, invänta mitt material.</pre>
                        <pre id="prompt-fact" class="text-xs bg-white p-2 rounded border border-gray-200 overflow-x-auto whitespace-pre-wrap text-gray-600 hidden">Du är en expert på pedagogik. Jag vill skapa faktaquiz-material.

Processen sker i två steg:

STEG 1: ANALYS & FÖRSLAG
1. Analysera texten/bilden jag bifogar.
2. Föreslå 10-20 relevanta begrepp/fakta.
3. Fråga mig om jag vill ändra något.

STEG 2: GENERERING (Efter mitt godkännande)
Skapa CSV-text med semikolon som separator.

FORMAT (en rad per begrepp):
begrepp;beskrivning;fel1;fel2;fel3

FÖRKLARING:
- "begrepp": Ordet/begreppet som ska läras.
- "beskrivning": Korrekt förklaring/definition.
- "fel1-3": 3 trovärdiga men felaktiga beskrivningar.
- Inga citattecken. Inga radbrytningar i celler.
- Ingen header-rad.

EXEMPEL:
Fotosyntes;Processen där växter omvandlar solljus till energi;Nedbrytning av proteiner;Transport av vatten i rötter;Cellandning i djur

Nu, invänta mitt material.</pre>
                    </div>
                </div>
            </div>

            <!-- Inställningar -->
            <fieldset class="border border-gray-200 rounded-lg p-4">
                <legend class="text-sm font-medium text-gray-700 px-2">Inställningar</legend>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Svarsläge</label>
                        <select name="answer_mode" class="w-full px-2 py-1 border border-gray-300 rounded text-sm" onchange="toggleHybridFields()">
                            <option value="multiple_choice">Flerval</option>
                            <option value="text_only">Skrivsvar</option>
                            <option value="hybrid">Hybrid (flerval + skrivsvar)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Quizläge</label>
                        <select name="quiz_mode" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                            <option value="training">Träning (repetition)</option>
                            <option value="test">Test (en genomgång)</option>
                        </select>
                    </div>
                    <div id="mc-count-field" class="hidden">
                        <label class="block text-xs text-gray-500 mb-1">Antal flerval</label>
                        <input type="number" name="mc_count" min="1" value="10" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                    </div>
                    <div id="text-count-field" class="hidden">
                        <label class="block text-xs text-gray-500 mb-1">Antal skrivsvar</label>
                        <input type="number" name="text_count" min="1" value="5" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Rätt per fråga</label>
                        <input type="number" name="required_correct" min="1" max="10" value="2" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Språk</label>
                        <select name="language" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                            <option value="sv">Svenska</option>
                            <option value="en">Engelska</option>
                            <option value="es">Spanska</option>
                            <option value="fr">Franska</option>
                            <option value="de">Tyska</option>
                            <option value="uk">Ukrainska</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Stavningsläge</label>
                        <select name="spelling_mode" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                            <option value="student_choice">Eleven väljer</option>
                            <option value="easy">Generös</option>
                            <option value="puritan">Exakt</option>
                        </select>
                    </div>
                    <div class="col-span-2 flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm cursor-pointer text-gray-700">
                            <input type="checkbox" name="reverse_enabled" onchange="toggleReverseFields()"> Omvänd riktning
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer text-gray-700">
                            <input type="checkbox" name="tts_enabled" id="tts-checkbox" checked> Uppläsning (TTS)
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer text-gray-700">
                            <input type="checkbox" name="generate_flashcards" checked> Generera flashcards
                        </label>
                    </div>
                </div>
            </fieldset>

            <!-- Omvänd riktning -->
            <fieldset id="reverse-fields" class="border border-gray-200 rounded-lg p-4 hidden">
                <legend class="text-sm font-medium text-gray-700 px-2">Omvänd riktning</legend>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Omvänt svarsläge</label>
                        <select name="reverse_answer_mode" class="w-full px-2 py-1 border border-gray-300 rounded text-sm" onchange="toggleReverseHybridFields()">
                            <option value="multiple_choice">Flerval</option>
                            <option value="text_only">Skrivsvar</option>
                            <option value="hybrid">Hybrid</option>
                        </select>
                    </div>
                    <div></div>
                    <div id="reverse-mc-count-field" class="hidden">
                        <label class="block text-xs text-gray-500 mb-1">Antal flerval (omvänd)</label>
                        <input type="number" name="reverse_mc_count" min="1" value="10" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                    </div>
                    <div id="reverse-text-count-field" class="hidden">
                        <label class="block text-xs text-gray-500 mb-1">Antal skrivsvar (omvänd)</label>
                        <input type="number" name="reverse_text_count" min="1" value="5" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                    </div>
                </div>
            </fieldset>

            <!-- Tidsspärr -->
            <fieldset class="border border-gray-200 rounded-lg p-4">
                <legend class="text-sm font-medium text-gray-700 px-2">Tidsspärr (valfritt)</legend>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Öppnar</label>
                        <input type="datetime-local" name="time_lock_opens" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Stänger</label>
                        <input type="datetime-local" name="time_lock_closes" class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                    </div>
                </div>
            </fieldset>

            <button type="submit" class="w-full py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium text-lg shadow-md">Skapa Quiz</button>
        </form>
    </div>

    <!-- ═══════════ MINA QUIZ ═══════════ -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-bold text-gray-800">Mina Quiz (<?= count($myQuizzes) ?>)</h2>
            <div class="flex items-center gap-2">
                <label class="text-xs text-gray-500">Sortera:</label>
                <select id="sort-select" onchange="sortQuizzes()" class="text-sm px-2 py-1 border border-gray-300 rounded">
                    <option value="created-desc">Nyast först</option>
                    <option value="created-asc">Äldst först</option>
                    <option value="title-asc">Titel A-Ö</option>
                    <option value="title-desc">Titel Ö-A</option>
                    <option value="type">Typ</option>
                    <option value="subject">Ämne</option>
                </select>
            </div>
        </div>

        <?php if (empty($myQuizzes)): ?>
            <p class="text-center py-8 text-gray-400">Inga quiz ännu. Skapa ditt första ovan!</p>
        <?php else: ?>
        <div id="quiz-list" class="space-y-1">
            <?php foreach ($myQuizzes as $quiz):
                $type = $quiz['type'] ?? 'glossary';
                $typeBadge = $type === 'glossary' ? 'glosquiz' : 'faktaquiz';
                $badgeColor = $type === 'glossary' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700';
                $itemCount = count($quiz['items'] ?? []);
                $resultCount = count($quiz['results'] ?? []);
                $itemWord = $type === 'glossary' ? 'glosor' : 'frågor';
                $subject = htmlspecialchars($quiz['subject'] ?? '');
                $tags = htmlspecialchars($quiz['tags'] ?? '');
                $created = $quiz['created'] ?? '';
                $createdShort = $created ? date('j/n', strtotime($created)) : '';
                $playUrl = '../play/index.php?id=' . urlencode($quiz['id']);
            ?>
            <div class="quiz-row flex items-center justify-between px-4 py-2 rounded-lg bg-gray-50 hover:bg-gray-100 border border-gray-200 transition"
                 data-created="<?= htmlspecialchars($created) ?>"
                 data-title="<?= htmlspecialchars($quiz['title']) ?>"
                 data-type="<?= $type ?>"
                 data-subject="<?= $subject ?>">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-gray-800 truncate"><?= htmlspecialchars($quiz['title']) ?></span>
                        <span class="text-xs px-2 py-0.5 rounded-full <?= $badgeColor ?>"><?= $typeBadge ?></span>
                        <?php if ($subject): ?>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"><?= $subject ?></span>
                        <?php endif; ?>
                        <?php if ($tags): foreach (explode(',', $tags) as $tag): $tag = trim($tag); if ($tag): ?>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700"><?= htmlspecialchars($tag) ?></span>
                        <?php endif; endforeach; endif; ?>
                    </div>
                    <div class="text-xs text-gray-500">
                        <?= $itemCount ?> <?= $itemWord ?> &middot; <?= $createdShort ?> &middot; <?= $resultCount ?> resultat
                    </div>
                </div>
                <div class="flex items-center gap-1 ml-4">
                    <button onclick="copyLink('<?= htmlspecialchars($playUrl) ?>')" class="p-1.5 rounded hover:bg-gray-200 text-gray-500" title="Kopiera elevlänk">📋</button>
                    <a href="edit.php?id=<?= urlencode($quiz['id']) ?>" class="p-1.5 rounded hover:bg-gray-200 text-gray-500" title="Redigera">✏️</a>
                    <a href="stats.php?id=<?= urlencode($quiz['id']) ?>" class="p-1.5 rounded hover:bg-gray-200 text-gray-500" title="Statistik">📊</a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Radera detta quiz?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="quiz_id" value="<?= htmlspecialchars($quiz['id']) ?>">
                        <button type="submit" class="p-1.5 rounded hover:bg-red-100 text-gray-500" title="Radera">🗑️</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function copyLink(path) {
    const url = new URL(path, window.location.href).href;
    navigator.clipboard.writeText(url).then(() => alert('Länk kopierad!'));
}

function sortQuizzes() {
    const list = document.getElementById('quiz-list');
    if (!list) return;
    const rows = [...list.querySelectorAll('.quiz-row')];
    const val = document.getElementById('sort-select').value;

    rows.sort((a, b) => {
        switch (val) {
            case 'created-desc': return b.dataset.created.localeCompare(a.dataset.created);
            case 'created-asc': return a.dataset.created.localeCompare(b.dataset.created);
            case 'title-asc': return a.dataset.title.localeCompare(b.dataset.title, 'sv');
            case 'title-desc': return b.dataset.title.localeCompare(a.dataset.title, 'sv');
            case 'type': return a.dataset.type.localeCompare(b.dataset.type);
            case 'subject': return a.dataset.subject.localeCompare(b.dataset.subject, 'sv');
            default: return 0;
        }
    });
    rows.forEach(r => list.appendChild(r));
}

function selectType(type) {
    document.getElementById('type-input').value = type;
    const btnG = document.getElementById('btn-glossary');
    const btnF = document.getElementById('btn-fact');
    if (type === 'glossary') {
        btnG.className = 'flex-1 py-3 rounded-xl border-2 font-bold text-lg transition-all border-purple-500 bg-purple-50 text-purple-700 shadow-md';
        btnF.className = 'flex-1 py-3 rounded-xl border-2 font-bold text-lg transition-all border-gray-200 bg-white text-gray-500 hover:border-blue-400';
    } else {
        btnF.className = 'flex-1 py-3 rounded-xl border-2 font-bold text-lg transition-all border-blue-500 bg-blue-50 text-blue-700 shadow-md';
        btnG.className = 'flex-1 py-3 rounded-xl border-2 font-bold text-lg transition-all border-gray-200 bg-white text-gray-500 hover:border-purple-400';
    }
    document.getElementById('csv-hint-glossary').classList.toggle('hidden', type !== 'glossary');
    document.getElementById('csv-hint-fact').classList.toggle('hidden', type !== 'fact');
    document.getElementById('prompt-glossary').classList.toggle('hidden', type !== 'glossary');
    document.getElementById('prompt-fact').classList.toggle('hidden', type !== 'fact');
    document.getElementById('tts-checkbox').checked = (type === 'glossary');
}
function toggleHybridFields() {
    const mode = document.querySelector('select[name="answer_mode"]').value;
    document.getElementById('mc-count-field').classList.toggle('hidden', mode !== 'hybrid');
    document.getElementById('text-count-field').classList.toggle('hidden', mode !== 'hybrid');
}
function toggleReverseFields() {
    const enabled = document.querySelector('input[name="reverse_enabled"]').checked;
    document.getElementById('reverse-fields').classList.toggle('hidden', !enabled);
}
function toggleReverseHybridFields() {
    const mode = document.querySelector('select[name="reverse_answer_mode"]').value;
    document.getElementById('reverse-mc-count-field').classList.toggle('hidden', mode !== 'hybrid');
    document.getElementById('reverse-text-count-field').classList.toggle('hidden', mode !== 'hybrid');
}
function toggleAiPrompt() {
    document.getElementById('ai-prompt-box').classList.toggle('hidden');
}
function copyAiPrompt() {
    const type = document.getElementById('type-input').value;
    const el = document.getElementById('prompt-' + type);
    navigator.clipboard.writeText(el.innerText).then(() => alert('Prompt kopierad! Klistra in den i din AI-chatt.'));
}
</script>
</body>
</html>
