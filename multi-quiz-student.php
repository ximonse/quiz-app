<?php
require_once 'config.php';

$mq_id = $_GET['id'] ?? '';
$student_id = $_GET['student_id'] ?? '';

// Läs multi-quiz data
$multi_quizzes_file = DATA_DIR . 'multi_quizzes.json';
$multi_quizzes = file_exists($multi_quizzes_file) ? json_decode(file_get_contents($multi_quizzes_file), true) : [];

if (!isset($multi_quizzes[$mq_id])) {
    die('<div class="p-8 text-center text-red-600 font-bold">Övningen hittades inte. Kontrollera länken.</div>');
}

$mq = $multi_quizzes[$mq_id];

// Om student_id skickas via GET, spara det i session och redirecta för att rensa URL
if ($student_id) {
    $_SESSION['student_id'] = $student_id;
    header("Location: multi-quiz-student.php?id=$mq_id");
    exit;
}

// Hantera utloggning
if (isset($_GET['logout'])) {
    unset($_SESSION['student_id']);
    header("Location: multi-quiz-student.php?id=$mq_id");
    exit;
}

// Hämta student_id från session
$current_student_id = $_SESSION['student_id'] ?? '';

// Läs student progress
$progress_file = DATA_DIR . 'multi_quiz_progress.json';
$all_progress = file_exists($progress_file) ? json_decode(file_get_contents($progress_file), true) : [];

// Hämta denna students progress för detta multi-quiz
$student_progress = [];
if ($current_student_id) {
    $student_progress = $all_progress[$mq_id][$current_student_id] ?? [];
    
    // Mergea med eventuellt nyare data från client-side i en riktig app, 
    // men här litar vi på servern
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($mq['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .variant-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .variant-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.1);
        }
        .completed-badge {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.8; }
        }
        .login-container {
            animation: slideUp 0.5s ease-out;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen text-gray-800 font-sans">

    <!-- BARA INLOGGNINGSPAGE OM INTE INLOGGAD -->
    <?php if (!$current_student_id): ?>
        <div class="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500">
            <div class="login-container bg-white p-8 rounded-3xl shadow-2xl w-full max-w-md text-center">
                <div class="text-6xl mb-6">👋</div>
                <h1 class="text-3xl font-bold text-gray-800 mb-2">Välkommen!</h1>
                <p class="text-gray-500 mb-8">
                    Du ska göra övningen: <br>
                    <span class="font-bold text-purple-600 text-lg"><?= htmlspecialchars($mq['title']) ?></span>
                </p>
                
                <form method="GET" class="space-y-4">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($mq_id) ?>">
                    
                    <div class="text-left">
                        <label class="block text-sm font-semibold text-gray-700 mb-2 ml-1">Vad heter du?</label>
                        <input type="text" name="student_id" required autofocus
                               placeholder="Skriv ditt namn eller ID..."
                               class="w-full px-5 py-4 border-2 border-gray-200 rounded-2xl focus:border-purple-500 focus:ring-4 focus:ring-purple-100 outline-none transition text-lg font-medium">
                    </div>
                    
                    <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-4 rounded-2xl shadow-lg hover:shadow-xl transition transform active:scale-95 text-lg">
                        Gå vidare →
                    </button>
                </form>
                
                <div class="mt-8 pt-6 border-t border-gray-100">
                    <div class="flex justify-center gap-2 text-sm text-gray-400">
                        <?php if (!empty($mq['subject'])): ?><span><?= htmlspecialchars($mq['subject']) ?></span><?php endif; ?>
                        <?php if (!empty($mq['grade'])): ?><span>• <?= htmlspecialchars($mq['grade']) ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
    <!-- DASHBOARD OM INLOGGAD -->
    <?php else: ?>
        <div class="max-w-5xl mx-auto p-4 md:p-8">
            
            <!-- Header -->
            <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-6 mb-8 flex flex-col md:flex-row justify-between items-center gap-4">
                <div>
                    <h1 class="text-2xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-purple-600 to-pink-600">
                        <?= htmlspecialchars($mq['title']) ?>
                    </h1>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-gray-500 text-sm">Inloggad som:</span>
                        <span class="font-bold text-gray-800"><?= htmlspecialchars($current_student_id) ?></span>
                    </div>
                </div>
                <div class="flex gap-3">
                    <div class="px-4 py-2 bg-green-50 text-green-700 rounded-xl text-sm font-medium border border-green-100">
                        🏆 Klara: <?= count($student_progress) ?> / <?= count(array_filter($mq['variants'])) ?>
                    </div>
                    <a href="?id=<?= $mq_id ?>&logout=1" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-xl text-sm font-medium transition">
                        Logga ut
                    </a>
                </div>
            </div>

            <!-- 1. TRÄNA (FLASHCARDS) - Längst upp -->
            <?php if ($mq['variants']['flashcard'] || $mq['variants']['reverse_flashcard']): ?>
                <div class="mb-10">
                    <div class="flex items-center gap-3 mb-4 px-2">
                        <span class="bg-green-100 text-green-700 p-2 rounded-lg text-xl">🧠</span>
                        <h2 class="text-xl font-bold text-gray-800">Träna & Plugga</h2>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Flashcard -->
                        <?php if ($mq['variants']['flashcard']): ?>
                            <?php 
                                $is_done = isset($student_progress['flashcard']); 
                                $time = $is_done ? strtotime($student_progress['flashcard']) : null;
                            ?>
                            <div class="variant-card bg-white rounded-2xl p-6 border-2 <?= $is_done ? 'border-green-400 bg-green-50' : 'border-gray-100' ?>">
                                <div class="flex justify-between items-start mb-4">
                                    <div class="bg-blue-50 text-blue-600 p-3 rounded-xl text-2xl">🗂️</div>
                                    <?php if ($is_done): ?>
                                        <div class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-bold flex items-center gap-1">
                                            ✓ KLAR <span class="text-gray-400 font-normal"><?= date('H:i', $time) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <h3 class="text-lg font-bold text-gray-800 mb-1">Vändkort</h3>
                                <p class="text-sm text-gray-500 mb-6">Se begreppet, gissa beskrivningen.</p>
                                <a href="multi-quiz-variant.php?mq_id=<?= $mq_id ?>&variant=flashcard" 
                                   class="block w-full py-3 rounded-xl font-bold text-center transition
                                   <?= $is_done ? 'bg-white text-green-600 border border-green-200 hover:bg-green-50' : 'bg-blue-500 text-white hover:bg-blue-600 shadow-md hover:shadow-lg' ?>">
                                    <?= $is_done ? 'Öva igen ↻' : 'Starta' ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Omvänd Flashcard -->
                        <?php if ($mq['variants']['reverse_flashcard']): ?>
                            <?php 
                                $is_done = isset($student_progress['reverse_flashcard']); 
                                $time = $is_done ? strtotime($student_progress['reverse_flashcard']) : null;
                            ?>
                            <div class="variant-card bg-white rounded-2xl p-6 border-2 <?= $is_done ? 'border-green-400 bg-green-50' : 'border-gray-100' ?>">
                                <div class="flex justify-between items-start mb-4">
                                    <div class="bg-indigo-50 text-indigo-600 p-3 rounded-xl text-2xl">🔄</div>
                                    <?php if ($is_done): ?>
                                        <div class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-bold flex items-center gap-1">
                                            ✓ KLAR <span class="text-gray-400 font-normal"><?= date('H:i', $time) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <h3 class="text-lg font-bold text-gray-800 mb-1">Omvända Vändkort</h3>
                                <p class="text-sm text-gray-500 mb-6">Se beskrivningen, gissa begreppet.</p>
                                <a href="multi-quiz-variant.php?mq_id=<?= $mq_id ?>&variant=reverse_flashcard" 
                                   class="block w-full py-3 rounded-xl font-bold text-center transition
                                   <?= $is_done ? 'bg-white text-green-600 border border-green-200 hover:bg-green-50' : 'bg-indigo-500 text-white hover:bg-indigo-600 shadow-md hover:shadow-lg' ?>">
                                    <?= $is_done ? 'Öva igen ↻' : 'Starta' ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 2. GLOSFÖRHÖR (GLOSSARY) -->
            <?php if ($mq['variants']['glossary'] || $mq['variants']['reverse_glossary']): ?>
                <div class="mb-10">
                    <div class="flex items-center gap-3 mb-4 px-2">
                        <span class="bg-purple-100 text-purple-700 p-2 rounded-lg text-xl">✍️</span>
                        <h2 class="text-xl font-bold text-gray-800">Skriv & Stava</h2>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Glossary -->
                        <?php if ($mq['variants']['glossary']): ?>
                            <?php 
                                $is_done = isset($student_progress['glossary']); 
                                $time = $is_done ? strtotime($student_progress['glossary']) : null;
                            ?>
                            <div class="variant-card bg-white rounded-2xl p-6 border-2 <?= $is_done ? 'border-green-400 bg-green-50' : 'border-gray-100' ?>">
                                <div class="flex justify-between items-start mb-4">
                                    <div class="bg-purple-50 text-purple-600 p-3 rounded-xl text-2xl">📚</div>
                                    <?php if ($is_done): ?>
                                        <div class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-bold flex items-center gap-1">
                                            ✓ KLAR <span class="text-gray-400 font-normal"><?= date('H:i', $time) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <h3 class="text-lg font-bold text-gray-800 mb-1">Glosförhör</h3>
                                <p class="text-sm text-gray-500 mb-6">Översätt från begrepp till mål.</p>
                                <a href="multi-quiz-variant.php?mq_id=<?= $mq_id ?>&variant=glossary" 
                                   class="block w-full py-3 rounded-xl font-bold text-center transition
                                   <?= $is_done ? 'bg-white text-green-600 border border-green-200 hover:bg-green-50' : 'bg-purple-600 text-white hover:bg-purple-700 shadow-md hover:shadow-lg' ?>">
                                    <?= $is_done ? 'Öva igen ↻' : 'Starta' ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Reverse Glossary -->
                        <?php if ($mq['variants']['reverse_glossary']): ?>
                            <?php 
                                $is_done = isset($student_progress['reverse_glossary']); 
                                $time = $is_done ? strtotime($student_progress['reverse_glossary']) : null;
                            ?>
                            <div class="variant-card bg-white rounded-2xl p-6 border-2 <?= $is_done ? 'border-green-400 bg-green-50' : 'border-gray-100' ?>">
                                <div class="flex justify-between items-start mb-4">
                                    <div class="bg-pink-50 text-pink-600 p-3 rounded-xl text-2xl">🔄</div>
                                    <?php if ($is_done): ?>
                                        <div class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-bold flex items-center gap-1">
                                            ✓ KLAR <span class="text-gray-400 font-normal"><?= date('H:i', $time) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <h3 class="text-lg font-bold text-gray-800 mb-1">Omvänt Glosförhör</h3>
                                <p class="text-sm text-gray-500 mb-6">Översätt tillbaka till begreppet.</p>
                                <a href="multi-quiz-variant.php?mq_id=<?= $mq_id ?>&variant=reverse_glossary" 
                                   class="block w-full py-3 rounded-xl font-bold text-center transition
                                   <?= $is_done ? 'bg-white text-green-600 border border-green-200 hover:bg-green-50' : 'bg-pink-500 text-white hover:bg-pink-600 shadow-md hover:shadow-lg' ?>">
                                    <?= $is_done ? 'Öva igen ↻' : 'Starta' ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 3. KUNSKAPSTEST (QUIZ) -->
            <?php if ($mq['variants']['quiz']): ?>
                <div class="mb-10">
                    <div class="flex items-center gap-3 mb-4 px-2">
                        <span class="bg-red-100 text-red-700 p-2 rounded-lg text-xl">🎯</span>
                        <h2 class="text-xl font-bold text-gray-800">Testa kunskaperna</h2>
                    </div>
                    
                    <!-- Quiz -->
                    <?php 
                        $is_done = isset($student_progress['quiz']); 
                        $time = $is_done ? strtotime($student_progress['quiz']) : null;
                    ?>
                    <div class="variant-card bg-white rounded-2xl p-6 border-2 max-w-2xl <?= $is_done ? 'border-green-400 bg-green-50' : 'border-gray-100' ?>">
                        <div class="flex justify-between items-start mb-4">
                            <div class="bg-red-50 text-red-600 p-3 rounded-xl text-2xl">❓</div>
                            <?php if ($is_done): ?>
                                <div class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-bold flex items-center gap-1">
                                    ✓ KLAR <span class="text-gray-400 font-normal"><?= date('H:i', $time) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <h3 class="text-lg font-bold text-gray-800 mb-1">Kunskapsquiz</h3>
                        <p class="text-sm text-gray-500 mb-6">Svara på frågor med flerval.</p>
                        <a href="multi-quiz-variant.php?mq_id=<?= $mq_id ?>&variant=quiz" 
                           class="block w-full py-3 rounded-xl font-bold text-center transition
                           <?= $is_done ? 'bg-white text-green-600 border border-green-200 hover:bg-green-50' : 'bg-red-500 text-white hover:bg-red-600 shadow-md hover:shadow-lg' ?>">
                            <?= $is_done ? 'Öva igen ↻' : 'Starta' ?>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
        </div>
    <?php endif; ?>

    <script>
        // Spara progress lokalt i localStorage också och sync vid laddning
        const mqId = '<?= $mq_id ?>';
        const studentId = '<?= $current_student_id ?>';
        
        if (studentId) {
            const serverProgress = <?= json_encode($student_progress) ?>;
            const localProgress = JSON.parse(localStorage.getItem('multiQuizProgress') || '{}');
            
            // Just nu bara sparar vi serverns sanning till local
            if (!localProgress[mqId]) localProgress[mqId] = {};
            localProgress[mqId][studentId] = serverProgress;
            
            localStorage.setItem('multiQuizProgress', JSON.stringify(localProgress));
        }
    </script>
</body>
</html>
