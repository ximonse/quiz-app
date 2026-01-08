<?php
require_once 'config.php';
requireSuperAdmin();

$teachers = readJSON(TEACHERS_FILE);
$quizzes = readJSON(QUIZZES_FILE);
$stats = readJSON(STATS_FILE);

// Hantera lärare-actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_teacher') {
        $username = trim($_POST['username'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username && $name && $password) {
            $teacher_id = generateID('teacher_');
            $teachers[$teacher_id] = [
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'name' => $name,
                'active' => true,
                'created' => date('Y-m-d H:i:s')
            ];
            writeJSON(TEACHERS_FILE, $teachers);
            $success = "Lärare skapad!";
        }
    }

    if ($action === 'toggle_active') {
        $teacher_id = $_POST['teacher_id'] ?? '';
        if (isset($teachers[$teacher_id])) {
            $teachers[$teacher_id]['active'] = !$teachers[$teacher_id]['active'];
            writeJSON(TEACHERS_FILE, $teachers);
            $success = "Status uppdaterad!";
        }
    }

    if ($action === 'delete_teacher') {
        $teacher_id = $_POST['teacher_id'] ?? '';
        if (isset($teachers[$teacher_id])) {
            unset($teachers[$teacher_id]);
            writeJSON(TEACHERS_FILE, $teachers);
            $success = "Lärare raderad!";
        }
    }

    if ($action === 'reset_password') {
        $teacher_id = $_POST['teacher_id'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        if (isset($teachers[$teacher_id]) && $new_password) {
            $teachers[$teacher_id]['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
            writeJSON(TEACHERS_FILE, $teachers);
            $success = "Lösenord återställt!";
        }
    }
}

// Räkna statistik
$total_quizzes = count($quizzes);
$total_attempts = 0;
$total_completed = 0;
foreach ($stats as $quiz_stats) {
    $total_attempts += $quiz_stats['total_attempts'] ?? 0;
    $total_completed += $quiz_stats['completed'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-purple-50 min-h-screen p-4">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="flex justify-between items-center">
                <h1 class="text-3xl font-bold text-gray-800">🔐 Super Admin Panel</h1>
                <a href="index.php?logout=1" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg">
                    Logga ut
                </a>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <!-- Statistik översikt -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Totalt antal lärare</div>
                <div class="text-3xl font-bold text-blue-600"><?= count($teachers) ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Totalt antal quizzes</div>
                <div class="text-3xl font-bold text-purple-600"><?= $total_quizzes ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Totalt antal försök</div>
                <div class="text-3xl font-bold text-green-600"><?= $total_attempts ?></div>
            </div>
        </div>

        <!-- Skapa ny lärare -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">➕ Skapa ny lärare</h2>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <input type="hidden" name="action" value="create_teacher">
                <input type="text" name="username" placeholder="Användarnamn" required
                       class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                <input type="text" name="name" placeholder="Namn" required
                       class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                <input type="password" name="password" placeholder="Lösenord" required
                       class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    Skapa
                </button>
            </form>
        </div>

        <!-- Lista över lärare -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">👥 Lärare (<?= count($teachers) ?>)</h2>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Namn</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Användarnamn</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Antal quizzes</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Status</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Skapad</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Åtgärder</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($teachers as $tid => $teacher): ?>
                            <?php
                            $teacher_quizzes = array_filter($quizzes, function($q) use ($tid) {
                                return $q['teacher_id'] === $tid;
                            });
                            ?>
                            <tr>
                                <td class="px-4 py-3"><?= htmlspecialchars($teacher['name']) ?></td>
                                <td class="px-4 py-3 font-mono text-sm"><?= htmlspecialchars($teacher['username']) ?></td>
                                <td class="px-4 py-3"><?= count($teacher_quizzes) ?></td>
                                <td class="px-4 py-3">
                                    <?php if ($teacher['active']): ?>
                                        <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-sm">Aktiv</span>
                                    <?php else: ?>
                                        <span class="bg-red-100 text-red-800 px-2 py-1 rounded text-sm">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500"><?= date('Y-m-d', strtotime($teacher['created'])) ?></td>
                                <td class="px-4 py-3">
                                    <div class="flex gap-2">
                                        <!-- Toggle aktiv -->
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="teacher_id" value="<?= $tid ?>">
                                            <button type="submit" class="text-blue-600 hover:text-blue-800 text-sm">
                                                <?= $teacher['active'] ? 'Inaktivera' : 'Aktivera' ?>
                                            </button>
                                        </form>

                                        <!-- Reset password -->
                                        <button onclick="resetPassword('<?= $tid ?>', '<?= htmlspecialchars($teacher['name']) ?>')"
                                                class="text-yellow-600 hover:text-yellow-800 text-sm">
                                            Återställ lösen
                                        </button>

                                        <!-- Radera -->
                                        <form method="POST" class="inline" onsubmit="return confirm('Är du säker?')">
                                            <input type="hidden" name="action" value="delete_teacher">
                                            <input type="hidden" name="teacher_id" value="<?= $tid ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-800 text-sm">
                                                Radera
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($teachers)): ?>
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                    Inga lärare ännu. Skapa den första!
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Lista över alla quizzes -->
        <div class="bg-white rounded-xl shadow-lg p-6 mt-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">📚 Alla quizzes (<?= count($quizzes) ?>)</h2>

            <?php if (!empty($quizzes)): ?>
                <div class="space-y-2">
                    <?php foreach ($quizzes as $qid => $quiz): ?>
                        <?php
                            $quiz_stats = $stats[$qid] ?? ['total_attempts' => 0, 'completed' => 0];
                            $is_active = $quiz['active'] ?? true;
                            $card_class = $is_active ? 'border-gray-200' : 'border-gray-300 bg-gray-50';
                            $text_class = $is_active ? 'text-gray-800' : 'text-gray-400';
                        ?>
                        <div class="border rounded-lg p-3 hover:shadow-md transition <?= $card_class ?>">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-xl"><?= $quiz['type'] === 'glossary' ? '📚' : '📝' ?></span>
                                        <h3 class="text-lg font-bold <?= $text_class ?>"><?= htmlspecialchars($quiz['title']) ?></h3>
                                    </div>
                                    <p class="text-sm <?= $is_active ? 'text-gray-500' : 'text-gray-400' ?>">
                                        👤 <?= htmlspecialchars($quiz['teacher_name'] ?? 'Okänd') ?> •
                                        <?= count($quiz['questions']) ?> frågor •
                                        Skapad <?= date('Y-m-d', strtotime($quiz['created'])) ?>
                                        <?php if (!$is_active): ?>
                                            • <span class="text-red-500 font-medium">INAKTIV</span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!empty($quiz['subject']) || !empty($quiz['grade']) || !empty($quiz['tags'])): ?>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <?php if (!empty($quiz['subject'])): ?>
                                                <span class="inline-block px-2 py-1 bg-blue-100 text-blue-700 text-xs rounded">
                                                    📖 <?= htmlspecialchars($quiz['subject']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($quiz['grade'])): ?>
                                                <span class="inline-block px-2 py-1 bg-purple-100 text-purple-700 text-xs rounded">
                                                    🎓 <?= htmlspecialchars($quiz['grade']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($quiz['tags'])): ?>
                                                <?php foreach (explode(',', $quiz['tags']) as $tag): ?>
                                                    <span class="inline-block px-2 py-1 bg-gray-100 text-gray-700 text-xs rounded">
                                                        🏷️ <?= htmlspecialchars(trim($tag)) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="mt-2 flex gap-4 text-sm">
                                        <span class="<?= $is_active ? 'text-gray-600' : 'text-gray-400' ?>">
                                            🎯 <?= $quiz_stats['total_attempts'] ?> försök
                                        </span>
                                        <span class="<?= $is_active ? 'text-green-600' : 'text-gray-400' ?>">
                                            ✅ <?= $quiz_stats['completed'] ?> klarat
                                        </span>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <a href="q/<?= $qid ?>.html" target="_blank"
                                       class="bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded text-center text-xs whitespace-nowrap">
                                        Öppna
                                    </a>
                                    <a href="stats.php?quiz_id=<?= $qid ?>"
                                       class="bg-purple-500 hover:bg-purple-600 text-white px-2 py-1 rounded text-center text-xs whitespace-nowrap">
                                        Statistik
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-gray-500 text-center py-8">Inga quizzes ännu.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Reset password modal -->
    <div id="resetPasswordModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-lg p-6 max-w-md w-full">
            <h3 class="text-xl font-bold mb-4">Återställ lösenord</h3>
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="teacher_id" id="reset_teacher_id">
                <div class="mb-4">
                    <label class="block text-gray-700 mb-2">Nytt lösenord för <span id="reset_teacher_name"></span></label>
                    <input type="password" name="new_password" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        Återställ
                    </button>
                    <button type="button" onclick="closeResetModal()" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                        Avbryt
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function resetPassword(teacherId, teacherName) {
            document.getElementById('reset_teacher_id').value = teacherId;
            document.getElementById('reset_teacher_name').textContent = teacherName;
            document.getElementById('resetPasswordModal').classList.remove('hidden');
        }

        function closeResetModal() {
            document.getElementById('resetPasswordModal').classList.add('hidden');
        }
    </script>
</body>
</html>
