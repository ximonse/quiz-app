<?php
// admin/stats.php — Quiz statistics + clear results
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/classroom.php';
require_once __DIR__ . '/../includes/teacher-shell.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }
header('Cache-Control: no-store');

$quizId = $_GET['id'] ?? '';
$quizzes = readJSON(QUIZZES_FILE);
$teacher = getCurrentTeacherID();

if (!is_string($quizId) || !isset($quizzes[$quizId]) || ($quizzes[$quizId]['teacher_id'] ?? '') !== $teacher) {
    header('Location: dashboard.php');
    exit;
}

$quiz = $quizzes[$quizId];
$results = $quiz['results'] ?? [];
$classroom = classroomRead();
$classes = array_filter($classroom['classes'] ?? [], fn($class) => $class['teacher_id'] === $teacher);
uasort($classes, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
$classId = $_GET['class'] ?? '';
$pupilResults = [];
if ($classId !== '') {
    if (!is_string($classId) || !isset($classes[$classId])) {
        http_response_code(404);
        exit('Klassen finns inte. Öppna quizstatistiken igen för att välja en klass.');
    }
    $pupils = array_filter($classroom['students'] ?? [], fn($pupil) => $pupil['teacher_id'] === $teacher && $pupil['class_id'] === $classId);
    $results = array_values(array_filter($results, fn($result) => isset($pupils[$result['student_id'] ?? ''])));
    foreach ($results as $result) {
        $id = $result['student_id'];
        $pupilResults[$id] = ['latest' => $result, 'count' => ($pupilResults[$id]['count'] ?? 0) + 1];
    }
    uksort($pupilResults, fn($a, $b) => strnatcasecmp($pupils[$a]['name'], $pupils[$b]['name']));
}
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
    <link rel="stylesheet" href="../assets/vendor/tailwind-3.4.19.css">
    <link rel="stylesheet" href="../engine/themes.css">
    <link rel="stylesheet" href="../assets/teacher-shell.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<div class="teacher-page">
    <?php teacherShell('innehall',$quiz['title'],'Statistik för quizet'); ?>

    <?php if ($classes): ?>
    <div class="teacher-workflow mb-4"><form method="get" class="workflow-toolbar">
        <input type="hidden" name="id" value="<?= classroomEscape($quizId) ?>">
        <label for="class-filter">Klass</label>
        <select id="class-filter" name="class">
            <option value="">Alla spelare</option>
            <?php foreach ($classes as $id => $class): ?>
            <option value="<?= classroomEscape($id) ?>"<?= $id === $classId ? ' selected' : '' ?>><?= classroomEscape($class['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="workflow-primary">Visa</button>
    </form></div>
    <?php endif; ?>

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

    <?php if ($classId !== ''): ?>
    <section class="rounded-lg p-4 mb-6" style="background: var(--card-bg); border: 1px solid var(--border)">
        <h2 class="font-medium mb-3">Resultat per elev i <?= classroomEscape($classes[$classId]['name']) ?></h2>
        <?php if (!$pupilResults): ?><p>Inga sparade resultat för klassen.</p><?php else: ?>
        <div class="space-y-1">
        <?php foreach ($pupilResults as $id => $entry): $latest = $entry['latest']; ?>
            <div class="flex items-center justify-between gap-3 py-2 border-b" style="border-color: var(--border)">
                <a class="text-blue-700 underline" href="pupil.php?id=<?= rawurlencode($id) ?>#quiz-<?= rawurlencode($quizId) ?>"><?= classroomEscape($pupils[$id]['name']) ?></a>
                <span class="text-sm">Senast <strong><?= (int)($latest['score'] ?? 0) ?>/<?= (int)($latest['total'] ?? 0) ?></strong> · <?= $entry['count'] ?> försök</span>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
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
                        <div class="text-sm" style="color: var(--text-primary)"><?= htmlspecialchars($r['student_name'] ?? 'Okänd') ?></div>
                        <?php if ($quiz['type'] === 'image_choice'): ?>
                            <?php if (isset($r['choice_counts']['right'], $r['choice_counts']['wrong'], $r['choice_counts']['missed'])): ?>
                                <div class="text-xs" style="color: var(--text-secondary)">Bildval: <?= (int)$r['choice_counts']['right'] ?> rätt · <?= (int)$r['choice_counts']['wrong'] ?> fel · <?= (int)$r['choice_counts']['missed'] ?> missade</div>
                            <?php else: ?>
                                <div class="text-xs" style="color: var(--text-secondary)">Bildval: uppdelning saknas för äldre resultat</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="text-sm font-medium" style="color: var(--accent)"><?= (int)($r['score'] ?? 0) ?>/<?= (int)($r['total'] ?? 0) ?></span>
                        <?php // Resultat sparade innan tidsstampeln fanns saknar den helt.
                        $stamp = !empty($r['timestamp']) ? strtotime((string)$r['timestamp']) : false; ?>
                        <span class="text-xs" style="color: var(--text-secondary)"><?= $stamp ? date('j/n H:i', $stamp) : 'okänt datum' ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Clear results -->
    <?php if ($classId === '' && !empty($results)): ?>
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
