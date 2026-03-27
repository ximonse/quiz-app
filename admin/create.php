<?php
// admin/create.php — Create new quiz (glossary or fact)
require_once __DIR__ . '/../config.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }

$csrfToken = getCsrfToken();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                'created' => date('Y-m-d H:i:s'),
                'teacher_id' => getCurrentTeacherID(),
                'settings' => [
                    'answer_mode' => $answerMode,
                    'mc_count' => intval($_POST['mc_count'] ?? count($items)),
                    'text_count' => intval($_POST['text_count'] ?? 0),
                    'reverse_enabled' => $reverseEnabled,
                    'reverse_answer_mode' => $reverseAnswerMode,
                    'reverse_mc_count' => intval($_POST['reverse_mc_count'] ?? count($items)),
                    'reverse_text_count' => intval($_POST['reverse_text_count'] ?? 0),
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

            // Time lock
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

            header('Location: dashboard.php');
            exit;
        }
    }
}

function parseCSV($csvText, $type) {
    $lines = array_filter(array_map('trim', explode("\n", $csvText)));
    $items = [];

    // Skip header if present
    $first = strtolower($lines[0] ?? '');
    if (str_contains($first, 'mening') || str_contains($first, 'begrepp')) {
        array_shift($lines);
    }

    foreach ($lines as $line) {
        $cols = array_map('trim', explode(';', $line));

        if ($type === 'glossary') {
            // mening;ord;översättning;fel1;fel2;fel3;omvänt_fel1;omvänt_fel2;omvänt_fel3
            if (count($cols) < 6) continue;
            $item = [
                'sentence' => $cols[0],
                'word' => $cols[1],
                'translation' => $cols[2],
                'wrong_options' => array_filter([$cols[3] ?? '', $cols[4] ?? '', $cols[5] ?? '']),
                'reverse_wrong_options' => array_filter([$cols[6] ?? '', $cols[7] ?? '', $cols[8] ?? ''])
            ];
            $items[] = $item;
        } else {
            // begrepp;beskrivning;fel1;fel2;fel3
            if (count($cols) < 5) continue;
            $item = [
                'concept' => $cols[0],
                'description' => $cols[1],
                'wrong_options' => array_filter([$cols[2] ?? '', $cols[3] ?? '', $cols[4] ?? ''])
            ];
            $items[] = $item;
        }
    }

    return $items;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skapa Quiz</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../engine/themes.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<div class="max-w-2xl mx-auto p-4">
    <div class="flex items-center gap-4 mb-6">
        <a href="dashboard.php" class="text-blue-600 hover:underline text-sm">&larr; Tillbaka</a>
        <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Skapa Quiz</h1>
    </div>

    <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-6" style="background: var(--card-bg); border: 1px solid var(--border)" class="rounded-xl p-6">
        <?= csrfField() ?>

        <!-- Typ -->
        <div>
            <label class="block text-sm font-medium mb-2" style="color: var(--text-primary)">Typ</label>
            <div class="flex gap-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="type" value="glossary" checked onchange="toggleTypeFields()">
                    <span style="color: var(--text-primary)">Glosquiz</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="type" value="fact" onchange="toggleTypeFields()">
                    <span style="color: var(--text-primary)">Faktaquiz</span>
                </label>
            </div>
        </div>

        <!-- Titel -->
        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary)">Titel</label>
            <input type="text" name="title" required class="w-full px-3 py-2 border rounded-lg" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" placeholder="T.ex. Spanska vecka 12">
        </div>

        <!-- CSV Data -->
        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary)">CSV-data (semikolon-separerad)</label>
            <div id="csv-hint-glossary" class="text-xs mb-1" style="color: var(--text-secondary)">Format: mening;ord;översättning;fel1;fel2;fel3;omvänt_fel1;omvänt_fel2;omvänt_fel3</div>
            <div id="csv-hint-fact" class="text-xs mb-1 hidden" style="color: var(--text-secondary)">Format: begrepp;beskrivning;fel1;fel2;fel3</div>
            <textarea name="csv_data" required rows="8" class="w-full px-3 py-2 border rounded-lg font-mono text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" placeholder="Klistra in CSV här..."></textarea>
        </div>

        <!-- Inställningar -->
        <fieldset class="border rounded-lg p-4" style="border-color: var(--border)">
            <legend class="text-sm font-medium px-2" style="color: var(--text-primary)">Inställningar</legend>
            <div class="grid grid-cols-2 gap-4">

                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Svarsläge</label>
                    <select name="answer_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" onchange="toggleHybridFields()">
                        <option value="multiple_choice">Flerval</option>
                        <option value="text_only">Skrivsvar</option>
                        <option value="hybrid">Hybrid (flerval + skrivsvar)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Quizläge</label>
                    <select name="quiz_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        <option value="training">Träning (repetition)</option>
                        <option value="test">Test (en genomgång)</option>
                    </select>
                </div>

                <div id="mc-count-field" class="hidden">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal flerval</label>
                    <input type="number" name="mc_count" min="1" value="10" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>

                <div id="text-count-field" class="hidden">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal skrivsvar</label>
                    <input type="number" name="text_count" min="1" value="5" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>

                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Språk</label>
                    <select name="language" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        <option value="sv">Svenska</option>
                        <option value="en">Engelska</option>
                        <option value="es">Spanska</option>
                        <option value="fr">Franska</option>
                        <option value="de">Tyska</option>
                        <option value="uk">Ukrainska</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Stavningsläge</label>
                    <select name="spelling_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        <option value="student_choice">Eleven väljer</option>
                        <option value="easy">Generös</option>
                        <option value="puritan">Exakt</option>
                    </select>
                </div>

                <!-- Checkboxes -->
                <div class="col-span-2 flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary)">
                        <input type="checkbox" name="reverse_enabled" onchange="toggleReverseFields()"> Omvänd riktning
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer" id="tts-label" style="color: var(--text-primary)">
                        <input type="checkbox" name="tts_enabled" id="tts-checkbox" checked> Uppläsning (TTS)
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary)">
                        <input type="checkbox" name="generate_flashcards" checked> Generera flashcards
                    </label>
                </div>
            </div>
        </fieldset>

        <!-- Omvänd riktning (dold tills aktiverad) -->
        <fieldset id="reverse-fields" class="border rounded-lg p-4 hidden" style="border-color: var(--border)">
            <legend class="text-sm font-medium px-2" style="color: var(--text-primary)">Omvänd riktning</legend>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Omvänt svarsläge</label>
                    <select name="reverse_answer_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" onchange="toggleReverseHybridFields()">
                        <option value="multiple_choice">Flerval</option>
                        <option value="text_only">Skrivsvar</option>
                        <option value="hybrid">Hybrid</option>
                    </select>
                </div>
                <div></div>
                <div id="reverse-mc-count-field" class="hidden">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal flerval (omvänd)</label>
                    <input type="number" name="reverse_mc_count" min="1" value="10" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
                <div id="reverse-text-count-field" class="hidden">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal skrivsvar (omvänd)</label>
                    <input type="number" name="reverse_text_count" min="1" value="5" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
            </div>
        </fieldset>

        <!-- Tidsspärr -->
        <fieldset class="border rounded-lg p-4" style="border-color: var(--border)">
            <legend class="text-sm font-medium px-2" style="color: var(--text-primary)">Tidsspärr (valfritt)</legend>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Öppnar</label>
                    <input type="datetime-local" name="time_lock_opens" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Stänger</label>
                    <input type="datetime-local" name="time_lock_closes" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
            </div>
        </fieldset>

        <button type="submit" class="w-full py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">Skapa Quiz</button>
    </form>
</div>

<script>
function toggleTypeFields() {
    const isGlossary = document.querySelector('input[name="type"][value="glossary"]').checked;
    document.getElementById('csv-hint-glossary').classList.toggle('hidden', !isGlossary);
    document.getElementById('csv-hint-fact').classList.toggle('hidden', isGlossary);
    // TTS default: on for glossary, off for fact
    document.getElementById('tts-checkbox').checked = isGlossary;
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
</script>
</body>
</html>
