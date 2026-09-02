<?php
// admin/edit.php — Edit existing quiz
require_once __DIR__ . '/../config.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }

$quizId = $_GET['id'] ?? '';
$quizzes = readJSON(QUIZZES_FILE);

if (!isset($quizzes[$quizId]) || ($quizzes[$quizId]['teacher_id'] ?? '') !== getCurrentTeacherID()) {
    header('Location: dashboard.php');
    exit;
}

$quiz = $quizzes[$quizId];
$csrfToken = getCsrfToken();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();

    $title = trim($_POST['title'] ?? '');
    if (!$title) {
        $error = 'Titel krävs.';
    } else {
        // Update settings
        $quiz['title'] = $title;
        $quiz['settings']['answer_mode'] = $_POST['answer_mode'] ?? 'multiple_choice';
        $quiz['settings']['required_correct'] = max(1, intval($_POST['required_correct'] ?? ($quiz['settings']['required_correct'] ?? 1)));
        $quiz['settings']['reverse_enabled'] = isset($_POST['reverse_enabled']);
        $quiz['settings']['reverse_answer_mode'] = $_POST['reverse_answer_mode'] ?? 'multiple_choice';
        $quiz['settings']['reverse_required_correct'] = max(1, intval($_POST['reverse_required_correct'] ?? ($quiz['settings']['reverse_required_correct'] ?? 1)));
        $quiz['settings']['quiz_mode'] = $_POST['quiz_mode'] ?? 'training';
        $quiz['settings']['tts_enabled'] = isset($_POST['tts_enabled']);
        $quiz['settings']['language'] = $_POST['language'] ?? 'sv';
        $quiz['settings']['spelling_mode'] = $_POST['spelling_mode'] ?? 'student_choice';
        $quiz['settings']['generate_flashcards'] = isset($_POST['generate_flashcards']);

        // Time lock
        $opens = trim($_POST['time_lock_opens'] ?? '');
        $closes = trim($_POST['time_lock_closes'] ?? '');
        $quiz['settings']['time_lock'] = ($opens || $closes) ? ['opens' => $opens ?: null, 'closes' => $closes ?: null] : null;

        // Re-parse CSV if provided
        $csvData = trim($_POST['csv_data'] ?? '');
        $newItems = null;
        if ($csvData) {
            $parsedItems = parseCSV($csvData, $quiz['type']);
            if (!empty($parsedItems)) {
                $newItems = $parsedItems;
                $quiz['items'] = $parsedItems;
            } else {
                $error = 'Kunde inte tolka CSV-data.';
            }
        }

        if (!$error) {
            $newTitle = $quiz['title'];
            $newSettings = $quiz['settings'];
            // Skriv bara title/settings/items — läs en färsk kopia under låset så
            // att t.ex. ett elevresultat som sparats samtidigt inte skrivs över.
            updateJSONLocked(QUIZZES_FILE, function (&$lockedQuizzes) use ($quizId, $newTitle, $newSettings, $newItems) {
                if (!isset($lockedQuizzes[$quizId])) return;
                $lockedQuizzes[$quizId]['title'] = $newTitle;
                $lockedQuizzes[$quizId]['settings'] = $newSettings;
                if ($newItems !== null) {
                    $lockedQuizzes[$quizId]['items'] = $newItems;
                }
            });
            header('Location: dashboard.php');
            exit;
        }
    }
}

// Same parseCSV function as create.php
function parseCSV($csvText, $type) {
    $lines = array_filter(array_map('trim', explode("\n", $csvText)));
    $items = [];
    $first = strtolower($lines[0] ?? '');
    if (str_contains($first, 'mening') || str_contains($first, 'begrepp')) array_shift($lines);

    foreach ($lines as $line) {
        $cols = array_map('trim', explode(';', $line));
        if ($type === 'glossary') {
            if (count($cols) < 6) continue;
            if ($cols[0] === '' || $cols[1] === '' || $cols[2] === '') continue; // saknar mening/ord/översättning
            $items[] = [
                'sentence' => $cols[0], 'word' => $cols[1], 'translation' => $cols[2],
                'wrong_options' => array_filter([$cols[3] ?? '', $cols[4] ?? '', $cols[5] ?? '']),
                'reverse_wrong_options' => array_filter([$cols[6] ?? '', $cols[7] ?? '', $cols[8] ?? ''])
            ];
        } else {
            if (count($cols) < 5) continue;
            if ($cols[0] === '' || $cols[1] === '') continue; // saknar begrepp/beskrivning
            $items[] = [
                'concept' => $cols[0], 'description' => $cols[1],
                'wrong_options' => array_filter([$cols[2] ?? '', $cols[3] ?? '', $cols[4] ?? ''])
            ];
        }
    }
    return $items;
}

$s = $quiz['settings'];
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redigera: <?= htmlspecialchars($quiz['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../engine/themes.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<div class="max-w-2xl mx-auto p-4">
    <div class="flex items-center gap-4 mb-6">
        <a href="dashboard.php" class="text-blue-600 hover:underline text-sm">&larr; Tillbaka</a>
        <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Redigera: <?= htmlspecialchars($quiz['title']) ?></h1>
    </div>

    <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" onsubmit="return prepareSubmit();" class="space-y-6 rounded-xl p-6" style="background: var(--card-bg); border: 1px solid var(--border)">
        <?= csrfField() ?>

        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary)">Titel</label>
            <input type="text" name="title" value="<?= htmlspecialchars($quiz['title']) ?>" required class="w-full px-3 py-2 border rounded-lg" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
        </div>

        <div>
            <div class="flex items-center justify-between mb-2">
                <label class="block text-sm font-medium" style="color: var(--text-primary)">Frågor (<?= count($quiz['items']) ?>)</label>
                <button type="button" onclick="addItemRow()" class="text-xs px-2 py-1 rounded bg-blue-100 hover:bg-blue-200 text-blue-700">+ Lägg till <?= $quiz['type'] === 'glossary' ? 'glosa' : 'fråga' ?></button>
            </div>
            <div id="items-editor" class="space-y-3">
                <?php foreach ($quiz['items'] as $item): ?>
                    <?php if ($quiz['type'] === 'glossary'): ?>
                    <div class="item-row border rounded-lg p-3" style="border-color: var(--border)">
                        <div class="flex items-start gap-2 mb-2">
                            <input type="text" data-field="mening" value="<?= htmlspecialchars($item['sentence'] ?? '') ?>" placeholder="Mening" class="flex-1 px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                            <button type="button" onclick="removeItemRow(this)" title="Ta bort" class="text-red-500 hover:text-red-700 shrink-0 px-1">🗑️</button>
                        </div>
                        <div class="grid grid-cols-2 gap-2 mb-2">
                            <input type="text" data-field="ord" value="<?= htmlspecialchars($item['word'] ?? '') ?>" placeholder="Ord" class="px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                            <input type="text" data-field="oversattning" value="<?= htmlspecialchars($item['translation'] ?? '') ?>" placeholder="Översättning" class="px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        </div>
                        <div class="grid grid-cols-3 gap-2 mb-2">
                            <input type="text" data-field="fel1" value="<?= htmlspecialchars($item['wrong_options'][0] ?? '') ?>" placeholder="Fel 1" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                            <input type="text" data-field="fel2" value="<?= htmlspecialchars($item['wrong_options'][1] ?? '') ?>" placeholder="Fel 2" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                            <input type="text" data-field="fel3" value="<?= htmlspecialchars($item['wrong_options'][2] ?? '') ?>" placeholder="Fel 3" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <input type="text" data-field="ofel1" value="<?= htmlspecialchars($item['reverse_wrong_options'][0] ?? '') ?>" placeholder="Omvänt fel 1 (valfri)" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                            <input type="text" data-field="ofel2" value="<?= htmlspecialchars($item['reverse_wrong_options'][1] ?? '') ?>" placeholder="Omvänt fel 2 (valfri)" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                            <input type="text" data-field="ofel3" value="<?= htmlspecialchars($item['reverse_wrong_options'][2] ?? '') ?>" placeholder="Omvänt fel 3 (valfri)" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="item-row border rounded-lg p-3" style="border-color: var(--border)">
                        <div class="flex items-start gap-2 mb-2">
                            <input type="text" data-field="begrepp" value="<?= htmlspecialchars($item['concept'] ?? '') ?>" placeholder="Begrepp" class="flex-1 px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                            <button type="button" onclick="removeItemRow(this)" title="Ta bort" class="text-red-500 hover:text-red-700 shrink-0 px-1">🗑️</button>
                        </div>
                        <textarea data-field="beskrivning" placeholder="Beskrivning" rows="2" class="w-full px-2 py-1 border rounded text-sm mb-2" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)"><?= htmlspecialchars($item['description'] ?? '') ?></textarea>
                        <div class="grid grid-cols-3 gap-2">
                            <input type="text" data-field="ffel1" value="<?= htmlspecialchars($item['wrong_options'][0] ?? '') ?>" placeholder="Fel 1" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                            <input type="text" data-field="ffel2" value="<?= htmlspecialchars($item['wrong_options'][1] ?? '') ?>" placeholder="Fel 2" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                            <input type="text" data-field="ffel3" value="<?= htmlspecialchars($item['wrong_options'][2] ?? '') ?>" placeholder="Fel 3" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="mt-3">
                <button type="button" onclick="toggleBulkReplace()" class="text-xs text-purple-600 hover:text-purple-800 font-medium">Eller klistra in en ny CSV-lista (ersätter alla frågor ovan)</button>
                <div id="bulk-replace-box" class="hidden mt-2">
                    <textarea id="bulk-csv-textarea" rows="6" placeholder="Klistra in ny CSV här för att ersätta ALLA frågor ovan..." class="w-full px-3 py-2 border rounded-lg font-mono text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)"></textarea>
                </div>
            </div>

            <input type="hidden" name="csv_data" id="csv_data_hidden">
        </div>

        <fieldset class="border rounded-lg p-4" style="border-color: var(--border)">
            <legend class="text-sm font-medium px-2" style="color: var(--text-primary)">Inställningar</legend>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Svarsläge</label>
                    <select name="answer_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        <option value="multiple_choice" <?= $s['answer_mode'] === 'multiple_choice' ? 'selected' : '' ?>>Flerval</option>
                        <option value="text_only" <?= $s['answer_mode'] === 'text_only' ? 'selected' : '' ?>>Skrivsvar</option>
                        <option value="hybrid" <?= $s['answer_mode'] === 'hybrid' ? 'selected' : '' ?>>Hybrid (alla ord som flerval, sedan alla som skrivsvar)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Quizläge</label>
                    <select name="quiz_mode" id="quiz-mode-select" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" onchange="onQuizModeChange()">
                        <option value="training" <?= ($s['quiz_mode'] ?? 'training') === 'training' ? 'selected' : '' ?>>Träning</option>
                        <option value="test" <?= ($s['quiz_mode'] ?? '') === 'test' ? 'selected' : '' ?>>Test</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Rätt per fråga</label>
                    <input type="number" name="required_correct" min="1" max="10" value="<?= $s['required_correct'] ?? 1 ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Språk</label>
                    <select name="language" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        <?php foreach (['sv'=>'Svenska','en'=>'Engelska','es'=>'Spanska','fr'=>'Franska','de'=>'Tyska','fi'=>'Finska','uk'=>'Ukrainska'] as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($s['language'] ?? 'sv') === $code ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Stavningsläge</label>
                    <select name="spelling_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        <option value="student_choice" <?= ($s['spelling_mode'] ?? 'student_choice') === 'student_choice' ? 'selected' : '' ?>>Eleven väljer</option>
                        <option value="easy" <?= ($s['spelling_mode'] ?? '') === 'easy' ? 'selected' : '' ?>>Generös</option>
                        <option value="puritan" <?= ($s['spelling_mode'] ?? '') === 'puritan' ? 'selected' : '' ?>>Exakt</option>
                    </select>
                </div>
                <div class="col-span-2 flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary)">
                        <input type="checkbox" name="reverse_enabled" <?= !empty($s['reverse_enabled']) ? 'checked' : '' ?> onchange="toggleReverseFields()"> Omvänd riktning
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary)">
                        <input type="checkbox" name="tts_enabled" <?= !empty($s['tts_enabled']) ? 'checked' : '' ?>> Uppläsning (TTS)
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary)">
                        <input type="checkbox" name="generate_flashcards" id="generate-flashcards-checkbox" <?= ($s['generate_flashcards'] ?? true) ? 'checked' : '' ?>> Flashcards
                    </label>
                </div>
            </div>
        </fieldset>

        <fieldset id="reverse-fields" class="border rounded-lg p-4 <?= empty($s['reverse_enabled']) ? 'hidden' : '' ?>" style="border-color: var(--border)">
            <legend class="text-sm font-medium px-2" style="color: var(--text-primary)">Omvänd riktning</legend>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Omvänt svarsläge</label>
                    <select name="reverse_answer_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        <option value="multiple_choice" <?= ($s['reverse_answer_mode'] ?? 'multiple_choice') === 'multiple_choice' ? 'selected' : '' ?>>Flerval</option>
                        <option value="text_only" <?= ($s['reverse_answer_mode'] ?? '') === 'text_only' ? 'selected' : '' ?>>Skrivsvar</option>
                        <option value="hybrid" <?= ($s['reverse_answer_mode'] ?? '') === 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Rätt per fråga (omvänd)</label>
                    <input type="number" name="reverse_required_correct" min="1" max="10" value="<?= $s['reverse_required_correct'] ?? 1 ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
            </div>
        </fieldset>

        <fieldset class="border rounded-lg p-4" style="border-color: var(--border)">
            <legend class="text-sm font-medium px-2" style="color: var(--text-primary)">Tidsspärr</legend>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Öppnar</label>
                    <input type="datetime-local" name="time_lock_opens" value="<?= htmlspecialchars($s['time_lock']['opens'] ?? '') ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Stänger</label>
                    <input type="datetime-local" name="time_lock_closes" value="<?= htmlspecialchars($s['time_lock']['closes'] ?? '') ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
            </div>
        </fieldset>

        <button type="submit" class="w-full py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">Spara ändringar</button>
    </form>
</div>

<script>
const QUIZ_TYPE = <?= json_encode($quiz['type']) ?>;
const GLOSSARY_ROW_HTML = `
    <div class="item-row border rounded-lg p-3" style="border-color: var(--border)">
        <div class="flex items-start gap-2 mb-2">
            <input type="text" data-field="mening" placeholder="Mening" class="flex-1 px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
            <button type="button" onclick="removeItemRow(this)" title="Ta bort" class="text-red-500 hover:text-red-700 shrink-0 px-1">🗑️</button>
        </div>
        <div class="grid grid-cols-2 gap-2 mb-2">
            <input type="text" data-field="ord" placeholder="Ord" class="px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
            <input type="text" data-field="oversattning" placeholder="Översättning" class="px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
        </div>
        <div class="grid grid-cols-3 gap-2 mb-2">
            <input type="text" data-field="fel1" placeholder="Fel 1" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
            <input type="text" data-field="fel2" placeholder="Fel 2" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
            <input type="text" data-field="fel3" placeholder="Fel 3" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
        </div>
        <div class="grid grid-cols-3 gap-2">
            <input type="text" data-field="ofel1" placeholder="Omvänt fel 1 (valfri)" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
            <input type="text" data-field="ofel2" placeholder="Omvänt fel 2 (valfri)" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
            <input type="text" data-field="ofel3" placeholder="Omvänt fel 3 (valfri)" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
        </div>
    </div>`;
const FACT_ROW_HTML = `
    <div class="item-row border rounded-lg p-3" style="border-color: var(--border)">
        <div class="flex items-start gap-2 mb-2">
            <input type="text" data-field="begrepp" placeholder="Begrepp" class="flex-1 px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
            <button type="button" onclick="removeItemRow(this)" title="Ta bort" class="text-red-500 hover:text-red-700 shrink-0 px-1">🗑️</button>
        </div>
        <textarea data-field="beskrivning" placeholder="Beskrivning" rows="2" class="w-full px-2 py-1 border rounded text-sm mb-2" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)"></textarea>
        <div class="grid grid-cols-3 gap-2">
            <input type="text" data-field="ffel1" placeholder="Fel 1" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
            <input type="text" data-field="ffel2" placeholder="Fel 2" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
            <input type="text" data-field="ffel3" placeholder="Fel 3" class="px-2 py-1 border rounded text-xs" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
        </div>
    </div>`;
const GLOSSARY_FIELDS = ['mening', 'ord', 'oversattning', 'fel1', 'fel2', 'fel3', 'ofel1', 'ofel2', 'ofel3'];
const FACT_FIELDS = ['begrepp', 'beskrivning', 'ffel1', 'ffel2', 'ffel3'];

function addItemRow() {
    const container = document.getElementById('items-editor');
    const wrapper = document.createElement('div');
    wrapper.innerHTML = QUIZ_TYPE === 'glossary' ? GLOSSARY_ROW_HTML : FACT_ROW_HTML;
    container.appendChild(wrapper.firstElementChild);
}
function removeItemRow(button) {
    button.closest('.item-row').remove();
}
function toggleBulkReplace() {
    document.getElementById('bulk-replace-box').classList.toggle('hidden');
}
function buildCsvFromRows() {
    const fields = QUIZ_TYPE === 'glossary' ? GLOSSARY_FIELDS : FACT_FIELDS;
    const rows = document.querySelectorAll('#items-editor .item-row');
    const lines = [];
    rows.forEach(row => {
        const values = fields.map(f => {
            const el = row.querySelector('[data-field="' + f + '"]');
            return (el.value || '').trim().replace(/;/g, ',');
        });
        lines.push(values.join(';'));
    });
    return lines.join('\n');
}
function prepareSubmit() {
    const bulkText = document.getElementById('bulk-csv-textarea').value.trim();
    if (!bulkText && document.querySelectorAll('#items-editor .item-row').length === 0) {
        alert('Du måste ha minst en fråga kvar. Lägg till en fråga eller klistra in en ny CSV-lista.');
        return false;
    }
    document.getElementById('csv_data_hidden').value = bulkText || buildCsvFromRows();
    return true;
}
function onQuizModeChange() {
    // Test-läge passar sällan ihop med flashcards (repetitionsverktyg) —
    // avmarkera som förvalt, men läraren kan fritt återaktivera det.
    if (document.getElementById('quiz-mode-select').value === 'test') {
        document.getElementById('generate-flashcards-checkbox').checked = false;
    }
}
function toggleReverseFields() {
    const enabled = document.querySelector('input[name="reverse_enabled"]').checked;
    document.getElementById('reverse-fields').classList.toggle('hidden', !enabled);
}
</script>
</body>
</html>
