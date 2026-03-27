<?php
// admin/dashboard.php — Compact quiz list for teachers
require_once __DIR__ . '/../config.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }

$quizzes = readJSON(QUIZZES_FILE);
$teacherId = getCurrentTeacherID();

// Filter to current teacher's quizzes
$myQuizzes = array_filter($quizzes, fn($q) => ($q['teacher_id'] ?? '') === $teacherId);
$myQuizzes = array_values($myQuizzes);

// Sort by created date, newest first
usort($myQuizzes, fn($a, $b) => strcmp($b['created'] ?? '', $a['created'] ?? ''));

$csrfToken = getCsrfToken();

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    requireValidCsrf();
    $deleteId = $_POST['quiz_id'] ?? '';
    if (isset($quizzes[$deleteId]) && ($quizzes[$deleteId]['teacher_id'] ?? '') === $teacherId) {
        unset($quizzes[$deleteId]);
        writeJSON(QUIZZES_FILE, $quizzes);
        header('Location: dashboard.php');
        exit;
    }
}
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
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<div class="max-w-4xl mx-auto p-4">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Mina Quiz</h1>
        <a href="create.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">+ Skapa quiz</a>
    </div>

    <?php if (empty($myQuizzes)): ?>
        <p class="text-center py-12" style="color: var(--text-secondary)">Inga quiz ännu. Skapa ditt första!</p>
    <?php else: ?>
    <div class="space-y-1">
        <?php foreach ($myQuizzes as $quiz):
            $type = $quiz['type'] ?? 'glossary';
            $typeBadge = $type === 'glossary' ? 'glosquiz' : 'faktaquiz';
            $badgeColor = $type === 'glossary' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700';
            $itemCount = count($quiz['items'] ?? []);
            $resultCount = count($quiz['results'] ?? []);
            $itemWord = $type === 'glossary' ? 'glosor' : 'frågor';

            // Time lock status
            $lockText = 'Alltid öppen';
            if (!empty($quiz['settings']['time_lock'])) {
                $lock = $quiz['settings']['time_lock'];
                $opens = !empty($lock['opens']) ? date('j/n', strtotime($lock['opens'])) : '';
                $closes = !empty($lock['closes']) ? date('j/n', strtotime($lock['closes'])) : '';
                if ($opens && $closes) $lockText = "Öppen $opens–$closes";
                elseif ($opens) $lockText = "Öppnar $opens";
                elseif ($closes) $lockText = "Stänger $closes";
            }

            $playUrl = '../play/index.php?id=' . urlencode($quiz['id']);
        ?>
        <div class="flex items-center justify-between px-4 py-2 rounded-lg" style="background: var(--card-bg); border: 1px solid var(--border)">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                    <span class="font-medium truncate" style="color: var(--text-primary)"><?= htmlspecialchars($quiz['title']) ?></span>
                    <span class="text-xs px-2 py-0.5 rounded-full <?= $badgeColor ?>"><?= $typeBadge ?></span>
                </div>
                <div class="text-xs" style="color: var(--text-secondary)">
                    <?= $itemCount ?> <?= $itemWord ?> · <?= $lockText ?> · <?= $resultCount ?> resultat
                </div>
            </div>
            <div class="flex items-center gap-1 ml-4">
                <button onclick="copyLink('<?= htmlspecialchars($playUrl) ?>')" class="p-1.5 rounded hover:bg-gray-100 text-gray-500" title="Kopiera länk">📋</button>
                <a href="edit.php?id=<?= urlencode($quiz['id']) ?>" class="p-1.5 rounded hover:bg-gray-100 text-gray-500" title="Redigera">✏️</a>
                <a href="stats.php?id=<?= urlencode($quiz['id']) ?>" class="p-1.5 rounded hover:bg-gray-100 text-gray-500" title="Statistik">📊</a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Radera detta quiz?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="quiz_id" value="<?= htmlspecialchars($quiz['id']) ?>">
                    <button type="submit" class="p-1.5 rounded hover:bg-red-50 text-gray-500" title="Radera">🗑️</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function copyLink(path) {
    const url = window.location.origin + path;
    navigator.clipboard.writeText(url).then(() => {
        alert('Länk kopierad!');
    });
}
</script>
</body>
</html>
