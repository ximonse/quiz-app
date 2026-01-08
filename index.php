<?php
require_once 'config.php';

// Hantera inloggning
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Kolla super admin
    if ($username === 'superadmin' && $password === SUPER_ADMIN_PASSWORD) {
        $_SESSION['super_admin'] = true;
        header('Location: super-admin.php');
        exit;
    }

    // Kolla lärare
    $teachers = readJSON(TEACHERS_FILE);
    foreach ($teachers as $tid => $teacher) {
        if ($teacher['username'] === $username &&
            $teacher['active'] &&
            password_verify($password, $teacher['password_hash'])) {
            $_SESSION['teacher_id'] = $tid;
            $_SESSION['teacher_name'] = $teacher['name'];
            header('Location: admin.php');
            exit;
        }
    }

    $error = "Felaktigt användarnamn eller lösenord";
}

// Hantera utloggning
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz App - Inloggning</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-purple-50 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-lg p-8 max-w-md w-full">
        <h1 class="text-3xl font-bold text-gray-800 mb-6 text-center">📚 Quiz App</h1>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-gray-700 font-medium mb-2">Användarnamn</label>
                <input type="text" name="username" required
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div>
                <label class="block text-gray-700 font-medium mb-2">Lösenord</label>
                <input type="password" name="password" required
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <button type="submit"
                    class="w-full bg-blue-500 hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-lg transition">
                Logga in
            </button>
        </form>

        <p class="text-gray-500 text-sm text-center mt-6">
            Använd <code class="bg-gray-100 px-2 py-1 rounded">superadmin</code> för administratörsåtkomst
        </p>
    </div>
</body>
</html>
