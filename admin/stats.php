<?php
// admin/stats.php — Quiz statistics + clear results
require_once __DIR__ . '/../config.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }

$quizId = $_GET['id'] ?? '';
$quizzes = readJSON(QUIZZES_FILE);

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

// Sort quizzes: which objects end up wrong, and where pupils put them.
$objectErrors = [];
if (($quiz['type'] ?? '') === 'sort') {
    foreach ($results as $result) foreach ($result['errors'] ?? [] as $err) {
        $item = $items[$err['item_index'] ?? -1] ?? null; if (!$item || !isset($err['object_id'])) continue;
        $key = $err['item_index'] . ':' . $err['object_id'];
        $objectErrors[$key] ??= ['item' => $item, 'object_id' => $err['object_id'], 'count' => 0, 'given' => []];
        $objectErrors[$key]['count']++;
        $given = $err['given'] ?? ''; $objectErrors[$key]['given'][$given] = ($objectErrors[$key]['given'][$given] ?? 0) + 1;
    }
    uasort($objectErrors, fn($a, $b) => $b['count'] <=> $a['count']);
}
// Order quizzes: objects that most often break the order.
if (($quiz['type'] ?? '') === 'order') {
    foreach ($results as $result) foreach ($result['errors'] ?? [] as $err) {
        $item = $items[$err['item_index'] ?? -1] ?? null; if (!$item) continue;
        foreach ($err['wrong'] ?? [] as $objectId) {
            $key = $err['item_index'] . ':' . $objectId;
            $objectErrors[$key] ??= ['item' => $item, 'object_id' => $objectId, 'count' => 0, 'given' => []];
            $objectErrors[$key]['count']++;
        }
    }
    uasort($objectErrors, fn($a, $b) => $b['count'] <=> $a['count']);
}
function statsSortLabel(array $item, ?string $id): string {
    if ($id === null || $id === '') return 'utanför fälten';
    foreach (array_merge($item['fields'] ?? [], $item['objects'] ?? []) as $entry)
        if ($entry['id'] === $id) return ($entry['title'] ?? '') ?: (($entry['text'] ?? '') ?: ($entry['image']['alt'] ?? $id));
    return $id;
}

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
        <a href="browser-ids.php" class="text-blue-600 hover:underline text-sm">Namn per webbläsare</a>
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
            $label = $quiz['type'] === 'glossary' ? ($item['word'] ?? '?') : ($item['concept'] ?? $item['question'] ?? '?');
            $pct = count($results) > 0 ? round(($errorCount / count($results)) * 100) : 0;
        ?>
        <div class="flex items-center justify-between py-1">
            <span class="text-sm" style="color: var(--text-primary)"><?= htmlspecialchars($label) ?></span>
            <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700"><?= $errorCount ?> fel (<?= $pct ?>%)</span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($objectErrors): ?>
    <div class="rounded-lg p-4 mb-6" style="background: var(--card-bg); border: 1px solid var(--border)">
        <h2 class="font-medium mb-3" style="color: var(--text-primary)"><?= $quiz['type'] === 'order' ? 'Objekt som oftast står på fel plats' : 'Objekt som oftast hamnar fel' ?></h2>
        <?php foreach (array_slice($objectErrors, 0, 10) as $entry):
            if ($quiz['type'] === 'order'): $position = array_search($entry['object_id'], array_column($entry['item']['objects'], 'id')); ?>
        <div class="flex items-center justify-between gap-3 py-1">
            <div class="text-sm" style="color: var(--text-primary)"><?= htmlspecialchars(statsSortLabel($entry['item'], $entry['object_id'])) ?> <span style="color: var(--text-secondary)">→ rätt plats: <?= $position === false ? '?' : $position + 1 ?> · <?= htmlspecialchars($entry['item']['question']) ?></span></div>
            <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700 shrink-0"><?= $entry['count'] ?> fel</span>
        </div>
        <?php continue; endif;
            $correct = null; foreach ($entry['item']['objects'] as $o) if ($o['id'] === $entry['object_id']) $correct = $o['field'];
            arsort($entry['given']); $places = [];
            foreach ($entry['given'] as $given => $n) $places[] = statsSortLabel($entry['item'], (string)$given) . ' ' . $n . '×';
        ?>
        <div class="flex items-center justify-between gap-3 py-1">
            <div>
                <div class="text-sm" style="color: var(--text-primary)"><?= htmlspecialchars(statsSortLabel($entry['item'], $entry['object_id'])) ?> <span style="color: var(--text-secondary)">→ rätt: <?= htmlspecialchars(statsSortLabel($entry['item'], $correct)) ?></span></div>
                <div class="text-xs" style="color: var(--text-secondary)">Placerat i: <?= htmlspecialchars(implode(' · ', $places)) ?></div>
            </div>
            <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700 shrink-0"><?= $entry['count'] ?> fel</span>
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
                <div class="flex items-center justify-between gap-3 py-2 border-b" style="border-color: var(--border)">
                    <div>
                        <div class="text-sm" style="color: var(--text-primary)"><?= htmlspecialchars($r['student_name']) ?></div>
                        <?php if ($quiz['type'] === 'image_choice'): ?>
                            <?php if (isset($r['choice_counts']['right'], $r['choice_counts']['wrong'], $r['choice_counts']['missed'])): ?>
                                <div class="text-xs" style="color: var(--text-secondary)">Bildval: <?= (int)$r['choice_counts']['right'] ?> rätt · <?= (int)$r['choice_counts']['wrong'] ?> fel · <?= (int)$r['choice_counts']['missed'] ?> missade</div>
                            <?php else: ?>
                                <div class="text-xs" style="color: var(--text-secondary)">Bildval: uppdelning saknas för äldre resultat</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
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
