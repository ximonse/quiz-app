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
        $quiz['settings']['mc_count'] = intval($_POST['mc_count'] ?? count($quiz['items']));
        $quiz['settings']['text_count'] = intval($_POST['text_count'] ?? 0);
        $quiz['settings']['required_correct'] = max(1, intval($_POST['required_correct'] ?? ($quiz['settings']['required_correct'] ?? 1)));
        $quiz['settings']['reverse_enabled'] = isset($_POST['reverse_enabled']);
        $quiz['settings']['reverse_answer_mode'] = $_POST['reverse_answer_mode'] ?? 'multiple_choice';
        $quiz['settings']['reverse_mc_count'] = intval($_POST['reverse_mc_count'] ?? count($quiz['items']));
        $quiz['settings']['reverse_text_count'] = intval($_POST['reverse_text_count'] ?? 0);
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
            $items[] = [
                'sentence' => $cols[0], 'word' => $cols[1], 'translation' => $cols[2],
                'wrong_options' => array_filter([$cols[3] ?? '', $cols[4] ?? '', $cols[5] ?? '']),
                'reverse_wrong_options' => array_filter([$cols[6] ?? '', $cols[7] ?? '', $cols[8] ?? ''])
            ];
        } else {
            if (count($cols) < 5) continue;
            $items[] = [
                'concept' => $cols[0], 'description' => $cols[1],
                'wrong_options' => array_filter([$cols[2] ?? '', $cols[3] ?? '', $cols[4] ?? ''])
            ];
        }
    }
    return $items;
}

// Convert items back to CSV for textarea display
function itemsToCSV($items, $type) {
    $lines = [];
    foreach ($items as $item) {
        if ($type === 'glossary') {
            $parts = [$item['sentence'], $item['word'], $item['translation']];
            $parts = array_merge($parts, $item['wrong_options'] ?? []);
            $parts = array_merge($parts, $item['reverse_wrong_options'] ?? []);
            $lines[] = implode(';', $parts);
        } else {
            $parts = [$item['concept'], $item['description']];
            $parts = array_merge($parts, $item['wrong_options'] ?? []);
            $lines[] = implode(';', $parts);
        }
    }
    return implode("\n", $lines);
}

$s = $quiz['settings'];
$csvText = itemsToCSV($quiz['items'], $quiz['type']);
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

    <form method="POST" class="space-y-6 rounded-xl p-6" style="background: var(--card-bg); border: 1px solid var(--border)">
        <?= csrfField() ?>

        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary)">Titel</label>
            <input type="text" name="title" value="<?= htmlspecialchars($quiz['title']) ?>" required class="w-full px-3 py-2 border rounded-lg" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
        </div>

        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary)">
                CSV-data <span class="text-xs font-normal" style="color: var(--text-secondary)">(lämna tomt för att behålla befintliga frågor)</span>
            </label>
            <textarea name="csv_data" rows="8" class="w-full px-3 py-2 border rounded-lg font-mono text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)"><?= htmlspecialchars($csvText) ?></textarea>
        </div>

        <fieldset class="border rounded-lg p-4" style="border-color: var(--border)">
            <legend class="text-sm font-medium px-2" style="color: var(--text-primary)">Inställningar</legend>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Svarsläge</label>
                    <select name="answer_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" onchange="toggleHybridFields()">
                        <option value="multiple_choice" <?= $s['answer_mode'] === 'multiple_choice' ? 'selected' : '' ?>>Flerval</option>
                        <option value="text_only" <?= $s['answer_mode'] === 'text_only' ? 'selected' : '' ?>>Skrivsvar</option>
                        <option value="hybrid" <?= $s['answer_mode'] === 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Quizläge</label>
                    <select name="quiz_mode" id="quiz-mode-select" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" onchange="onQuizModeChange()">
                        <option value="training" <?= ($s['quiz_mode'] ?? 'training') === 'training' ? 'selected' : '' ?>>Träning</option>
                        <option value="test" <?= ($s['quiz_mode'] ?? '') === 'test' ? 'selected' : '' ?>>Test</option>
                    </select>
                </div>
                <div id="mc-count-field" class="<?= $s['answer_mode'] !== 'hybrid' ? 'hidden' : '' ?>">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal flerval</label>
                    <input type="number" name="mc_count" min="1" value="<?= $s['mc_count'] ?? 10 ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
                <div id="text-count-field" class="<?= $s['answer_mode'] !== 'hybrid' ? 'hidden' : '' ?>">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal skrivsvar</label>
                    <input type="number" name="text_count" min="1" value="<?= $s['text_count'] ?? 5 ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
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
                    <select name="reverse_answer_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" onchange="toggleReverseHybridFields()">
                        <option value="multiple_choice" <?= ($s['reverse_answer_mode'] ?? 'multiple_choice') === 'multiple_choice' ? 'selected' : '' ?>>Flerval</option>
                        <option value="text_only" <?= ($s['reverse_answer_mode'] ?? '') === 'text_only' ? 'selected' : '' ?>>Skrivsvar</option>
                        <option value="hybrid" <?= ($s['reverse_answer_mode'] ?? '') === 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Rätt per fråga (omvänd)</label>
                    <input type="number" name="reverse_required_correct" min="1" max="10" value="<?= $s['reverse_required_correct'] ?? 1 ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
                <div id="reverse-mc-count-field" class="<?= ($s['reverse_answer_mode'] ?? '') !== 'hybrid' ? 'hidden' : '' ?>">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal flerval (omvänd)</label>
                    <input type="number" name="reverse_mc_count" min="1" value="<?= $s['reverse_mc_count'] ?? 10 ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
                <div id="reverse-text-count-field" class="<?= ($s['reverse_answer_mode'] ?? '') !== 'hybrid' ? 'hidden' : '' ?>">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal skrivsvar (omvänd)</label>
                    <input type="number" name="reverse_text_count" min="1" value="<?= $s['reverse_text_count'] ?? 5 ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
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
function onQuizModeChange() {
    // Test-läge passar sällan ihop med flashcards (repetitionsverktyg) —
    // avmarkera som förvalt, men läraren kan fritt återaktivera det.
    if (document.getElementById('quiz-mode-select').value === 'test') {
        document.getElementById('generate-flashcards-checkbox').checked = false;
    }
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
