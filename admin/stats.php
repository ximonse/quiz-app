<?php
// admin/stats.php — Quiz statistics + clear results
session_start();
require_once __DIR__ . '/../config.php';
requireTeacher();

$quizId = $_GET['id'] ?? '';
$quizzes = readJSON(DATA_DIR . '/quizzes.json');

if (!isset($quizzes[$quizId]) || ($quizzes[$quizId]['teacher_id'] ?? '') !== getCurrentTeacherID()) {
    header('Location: dashboard.php');
    exit;
}

$quiz = $quizzes[$quizId];
$results = $quiz['results'] ?? [];
$items = $quiz['items'] ?? [];
$csrfToken = getCsrfToken();

// Calculate per-question error rates
$questionErrors = [];
foreach ($results as $result) {
    foreach ($result['errors'] ?? [] as $err) {
        $idx = $err['item_index'] ?? -1;
        if ($idx >= 0) {
            $questionErrors[$idx] = ($questionErrors[$idx] ?? 0) + 1;
        }
    }
}
arsort($questionErrors);

// Average score
$avgScore = 0;
if (count($results) > 0) {
    $totalScore = array_sum(array_column($results, 'score'));
    $totalPossible = array_sum(array_column($results, 'total'));
    $avgScore = $totalPossible > 0 ? round(($totalScore / $totalPossible) * 100) : 0;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistik: <?= htmlspecialchars($quiz['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../engine/themes.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<div class="max-w-3xl mx-auto p-4">
    <div class="flex items-center gap-4 mb-6">
        <a href="dashboard.php" class="text-blue-600 hover:underline text-sm">&larr; Tillbaka</a>
        <h1 class="text-2xl font-bold" style="color: var(--text-primary)">📊 <?= htmlspecialchars($quiz['title']) ?></h1>
    </div>

    <!-- Overview cards -->
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="rounded-lg p-4 text-center" style="background: var(--card-bg); border: 1px solid var(--border)">
            <div class="text-2xl font-bold" style="color: var(--accent)"><?= count($results) ?></div>
            <div class="text-xs" style="color: var(--text-secondary)">Genomförda</div>
        </div>
        <div class="rounded-lg p-4 text-center" style="background: var(--card-bg); border: 1px solid var(--border)">
            <div class="text-2xl font-bold" style="color: var(--accent)"><?= $avgScore ?>%</div>
            <div class="text-xs" style="color: var(--text-secondary)">Snitträtt</div>
        </div>
        <div class="rounded-lg p-4 text-center" style="background: var(--card-bg); border: 1px solid var(--border)">
            <div class="text-2xl font-bold" style="color: var(--accent)"><?= count($items) ?></div>
            <div class="text-xs" style="color: var(--text-secondary)">Frågor</div>
        </div>
    </div>

    <!-- Hardest questions -->
    <?php if (!empty($questionErrors)): ?>
    <div class="rounded-lg p-4 mb-6" style="background: var(--card-bg); border: 1px solid var(--border)">
        <h2 class="font-medium mb-3" style="color: var(--text-primary)">Svåraste frågorna</h2>
        <?php foreach (array_slice($questionErrors, 0, 5, true) as $idx => $errorCount):
            $item = $items[$idx] ?? null;
            if (!$item) continue;
            $label = $quiz['type'] === 'glossary' ? ($item['word'] ?? '?') : ($item['concept'] ?? '?');
            $pct = count($results) > 0 ? round(($errorCount / count($results)) * 100) : 0;
        ?>
        <div class="flex items-center justify-between py-1">
            <span class="text-sm" style="color: var(--text-primary)"><?= htmlspecialchars($label) ?></span>
            <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700"><?= $errorCount ?> fel (<?= $pct ?>%)</span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Recent results -->
    <div class="rounded-lg p-4 mb-6" style="background: var(--card-bg); border: 1px solid var(--border)">
        <h2 class="font-medium mb-3" style="color: var(--text-primary)">Senaste resultat</h2>
        <?php if (empty($results)): ?>
            <p class="text-sm" style="color: var(--text-secondary)">Inga resultat ännu.</p>
        <?php else: ?>
            <div class="space-y-1">
            <?php foreach (array_reverse(array_slice($results, -20)) as $r): ?>
                <div class="flex items-center justify-between py-1">
                    <span class="text-sm" style="color: var(--text-primary)"><?= htmlspecialchars($r['student_name']) ?></span>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium" style="color: var(--accent)"><?= $r['score'] ?>/<?= $r['total'] ?></span>
                        <span class="text-xs" style="color: var(--text-secondary)"><?= date('j/n H:i', strtotime($r['timestamp'])) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Clear results -->
    <?php if (!empty($results)): ?>
    <form method="POST" action="../api/clear-results.php" onsubmit="return clearResults(event)" class="text-center">
        <button type="button" onclick="clearResults()" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm">Rensa alla resultat (<?= count($results) ?> st)</button>
    </form>
    <script>
    async function clearResults() {
        if (!confirm('Radera alla ' + <?= count($results) ?> + ' resultat? Detta går inte att ångra.')) return;
        const res = await fetch('../api/clear-results.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?= $csrfToken ?>' },
            body: JSON.stringify({ quiz_id: '<?= htmlspecialchars($quizId) ?>' })
        });
        if (res.ok) location.reload();
    }
    </script>
    <?php endif; ?>
</div>
</body>
</html>
