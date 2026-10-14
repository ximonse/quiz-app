<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/browser-ids.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }

$teacherId = getCurrentTeacherID();
$quizzes = readJSON(QUIZZES_FILE);
$stats = is_file(STATS_FILE) ? readJSON(STATS_FILE) : [];
$flashcardsFile = DATA_DIR . 'flashcards.json';
$flashcards = is_file($flashcardsFile) ? readJSON($flashcardsFile) : [];
$groups = browserIdentityGroups($teacherId, $quizzes, $stats, $flashcards);
?>
<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Namn per webbläsare</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../engine/themes.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<main class="max-w-3xl mx-auto p-4">
    <a href="dashboard.php" class="text-blue-600 hover:underline text-sm">&larr; Mina quiz</a>
    <h1 class="text-2xl font-bold mt-4 mb-2" style="color: var(--text-primary)">Namn per webbläsare</h1>
    <p class="text-sm mb-6" style="color: var(--text-secondary)">Här visas alla namn som använts med samma webbläsar-ID i dina quiz och flashcards. Flera elever på en delad dator kan få samma ID. En elev som byter webbläsare eller rensar webbläsardata får ett nytt ID. Äldre resultat saknar ID och kan inte kopplas i efterhand.</p>
    <?php if (!$groups): ?>
        <p class="rounded-lg p-4" style="background: var(--card-bg); border: 1px solid var(--border)">Inga resultat med webbläsar-ID ännu.</p>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($groups as $id => $group): ?>
                <section class="rounded-lg p-4" style="background: var(--card-bg); border: 1px solid var(--border)">
                    <div class="flex flex-wrap justify-between gap-2 mb-2">
                        <h2 class="font-medium" style="color: var(--text-primary)">Webbläsare <?= htmlspecialchars(substr($id, 0, 8), ENT_QUOTES, 'UTF-8') ?></h2>
                        <span class="text-sm" style="color: var(--text-secondary)"><?= (int)$group['count'] ?> resultat</span>
                    </div>
                    <p class="text-sm" style="color: var(--text-primary)"><?= htmlspecialchars(implode(', ', array_keys($group['names'])), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if ($group['last_seen'] !== ''): ?>
                        <p class="text-xs mt-2" style="color: var(--text-secondary)">Senast: <?= htmlspecialchars($group['last_seen'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
