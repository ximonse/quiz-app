<?php
require_once 'config.php';

$mq_id = $_GET['id'] ?? '';
$student_id = $_GET['student_id'] ?? '';

// Läs multi-quiz data
$multi_quizzes_file = DATA_DIR . 'multi_quizzes.json';
$multi_quizzes = file_exists($multi_quizzes_file) ? json_decode(file_get_contents($multi_quizzes_file), true) : [];

if (!isset($multi_quizzes[$mq_id])) {
    die('Multi-quiz hittades inte');
}

$mq = $multi_quizzes[$mq_id];

// Om student_id finns, spara det i session
if ($student_id) {
    $_SESSION['student_id'] = $student_id;
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
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($mq['title']) ?> - Multi-Quiz</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .variant-card {
            transition: all 0.3s ease;
        }
        .variant-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        }
        .completed-badge {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-indigo-50 via-purple-50 to-pink-50 min-h-screen p-4">
    <div class="max-w-5xl mx-auto">
        <!-- Header -->
        <div class="bg-white/90 backdrop-blur-sm rounded-3xl shadow-2xl p-6 mb-6 border border-purple-200">
            <div class="text-center">
                <h1 class="text-4xl font-bold bg-gradient-to-r from-purple-600 to-pink-600 bg-clip-text text-transparent mb-2">
                    <?= htmlspecialchars($mq['title']) ?>
                </h1>
                <p class="text-gray-600 text-lg">Välj en övning nedan för att komma igång!</p>
                
                <?php if (!empty($mq['subject']) || !empty($mq['grade'])): ?>
                    <div class="flex justify-center gap-3 mt-3">
                        <?php if (!empty($mq['subject'])): ?>
                            <span class="inline-flex items-center gap-1 px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-sm font-semibold">
                                📖 <?= htmlspecialchars($mq['subject']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($mq['grade'])): ?>
                            <span class="inline-flex items-center gap-1 px-3 py-1 bg-purple-100 text-purple-700 rounded-full text-sm font-semibold">
                                🎓 <?= htmlspecialchars($mq['grade']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Student ID Input (if not set) -->
        <?php if (!$current_student_id): ?>
            <div class="bg-yellow-50 border-2 border-yellow-300 rounded-2xl p-6 mb-6 shadow-lg">
                <h2 class="text-xl font-bold text-yellow-800 mb-3">🔐 Logga in med ditt ID</h2>
                <p class="text-yellow-700 mb-4">För att spara din progress, ange ditt unika elev-ID:</p>
                <form method="GET" class="flex gap-3">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($mq_id) ?>">
                    <input type="text" name="student_id" required
                           placeholder="Ditt elev-ID (t.ex. anna123)"
                           class="flex-1 px-4 py-3 border-2 border-yellow-300 rounded-xl focus:ring-2 focus:ring-yellow-500 text-lg">
                    <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white px-6 py-3 rounded-xl font-bold shadow-lg transition">
                        Logga in
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="bg-green-50 border-2 border-green-300 rounded-2xl p-4 mb-6 shadow-lg">
                <div class="flex justify-between items-center">
                    <div>
                        <span class="text-green-700 font-semibold">✅ Inloggad som:</span>
                        <span class="text-green-900 font-bold ml-2"><?= htmlspecialchars($current_student_id) ?></span>
                    </div>
                    <a href="?id=<?= htmlspecialchars($mq_id) ?>" class="text-green-700 hover:text-green-900 text-sm underline">
                        Byt användare
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Variants Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php if ($mq['variants']['glossary']): ?>
                <?php
                    $is_completed = isset($student_progress['glossary']);
                    $completed_at = $is_completed ? $student_progress['glossary'] : null;
                ?>
                <div class="variant-card bg-white rounded-2xl shadow-xl p-6 border-2 <?= $is_completed ? 'border-green-400' : 'border-purple-200' ?>">
                    <div class="flex justify-between items-start mb-4">
                        <div class="text-5xl">📚</div>
                        <?php if ($is_completed): ?>
                            <span class="completed-badge bg-green-500 text-white px-3 py-1 rounded-full text-xs font-bold">
                                ✓ KLAR
                            </span>
                        <?php endif; ?>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 mb-2">Glosquiz</h3>
                    <p class="text-gray-600 mb-4">Begrepp + Mening → Översättning</p>
                    <div class="text-sm text-gray-500 mb-4">
                        <?= $mq['variants']['glossary']['mc_count'] ?> flerval + 
                        <?= $mq['variants']['glossary']['text_count'] ?> skrivsvar
                    </div>
                    <?php if ($is_completed): ?>
                        <div class="text-xs text-green-600 mb-3">
                            Avklarad: <?= date('Y-m-d H:i', strtotime($completed_at)) ?>
                        </div>
                    <?php endif; ?>
                    <a href="multi-quiz-variant.php?mq_id=<?= $mq_id ?>&variant=glossary" 
                       class="block w-full bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white text-center px-6 py-3 rounded-xl font-bold shadow-lg transition transform hover:scale-105">
                        <?= $is_completed ? '🔄 Gör igen' : '▶️ Starta' ?>
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($mq['variants']['reverse_glossary']): ?>
                <?php
                    $is_completed = isset($student_progress['reverse_glossary']);
                    $completed_at = $is_completed ? $student_progress['reverse_glossary'] : null;
                ?>
                <div class="variant-card bg-white rounded-2xl shadow-xl p-6 border-2 <?= $is_completed ? 'border-green-400' : 'border-blue-200' ?>">
                    <div class="flex justify-between items-start mb-4">
                        <div class="text-5xl">🔄</div>
                        <?php if ($is_completed): ?>
                            <span class="completed-badge bg-green-500 text-white px-3 py-1 rounded-full text-xs font-bold">
                                ✓ KLAR
                            </span>
                        <?php endif; ?>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 mb-2">Omvänd Glosquiz</h3>
                    <p class="text-gray-600 mb-4">Översättning → Begrepp</p>
                    <div class="text-sm text-gray-500 mb-4">
                        <?= $mq['variants']['reverse_glossary']['mc_count'] ?> flerval + 
                        <?= $mq['variants']['reverse_glossary']['text_count'] ?> skrivsvar
                    </div>
                    <?php if ($is_completed): ?>
                        <div class="text-xs text-green-600 mb-3">
                            Avklarad: <?= date('Y-m-d H:i', strtotime($completed_at)) ?>
                        </div>
                    <?php endif; ?>
                    <a href="multi-quiz-variant.php?mq_id=<?= $mq_id ?>&variant=reverse_glossary" 
                       class="block w-full bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white text-center px-6 py-3 rounded-xl font-bold shadow-lg transition transform hover:scale-105">
                        <?= $is_completed ? '🔄 Gör igen' : '▶️ Starta' ?>
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($mq['variants']['flashcard']): ?>
                <?php
                    $is_completed = isset($student_progress['flashcard']);
                    $completed_at = $is_completed ? $student_progress['flashcard'] : null;
                ?>
                <div class="variant-card bg-white rounded-2xl shadow-xl p-6 border-2 <?= $is_completed ? 'border-green-400' : 'border-green-200' ?>">
                    <div class="flex justify-between items-start mb-4">
                        <div class="text-5xl">🗂️</div>
                        <?php if ($is_completed): ?>
                            <span class="completed-badge bg-green-500 text-white px-3 py-1 rounded-full text-xs font-bold">
                                ✓ KLAR
                            </span>
                        <?php endif; ?>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 mb-2">Flashcard</h3>
                    <p class="text-gray-600 mb-4">Begrepp → Beskrivning</p>
                    <div class="text-sm text-gray-500 mb-4">
                        <?= count($mq['items']) ?> kort att lära
                    </div>
                    <?php if ($is_completed): ?>
                        <div class="text-xs text-green-600 mb-3">
                            Avklarad: <?= date('Y-m-d H:i', strtotime($completed_at)) ?>
                        </div>
                    <?php endif; ?>
                    <a href="multi-quiz-variant.php?mq_id=<?= $mq_id ?>&variant=flashcard" 
                       class="block w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white text-center px-6 py-3 rounded-xl font-bold shadow-lg transition transform hover:scale-105">
                        <?= $is_completed ? '🔄 Gör igen' : '▶️ Starta' ?>
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($mq['variants']['reverse_flashcard']): ?>
                <?php
                    $is_completed = isset($student_progress['reverse_flashcard']);
                    $completed_at = $is_completed ? $student_progress['reverse_flashcard'] : null;
                ?>
                <div class="variant-card bg-white rounded-2xl shadow-xl p-6 border-2 <?= $is_completed ? 'border-green-400' : 'border-yellow-200' ?>">
                    <div class="flex justify-between items-start mb-4">
                        <div class="text-5xl">🔄</div>
                        <?php if ($is_completed): ?>
                            <span class="completed-badge bg-green-500 text-white px-3 py-1 rounded-full text-xs font-bold">
                                ✓ KLAR
                            </span>
                        <?php endif; ?>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 mb-2">Omvänd Flashcard</h3>
                    <p class="text-gray-600 mb-4">Beskrivning → Begrepp</p>
                    <div class="text-sm text-gray-500 mb-4">
                        <?= count($mq['items']) ?> kort att lära
                    </div>
                    <?php if ($is_completed): ?>
                        <div class="text-xs text-green-600 mb-3">
                            Avklarad: <?= date('Y-m-d H:i', strtotime($completed_at)) ?>
                        </div>
                    <?php endif; ?>
                    <a href="multi-quiz-variant.php?mq_id=<?= $mq_id ?>&variant=reverse_flashcard" 
                       class="block w-full bg-gradient-to-r from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700 text-white text-center px-6 py-3 rounded-xl font-bold shadow-lg transition transform hover:scale-105">
                        <?= $is_completed ? '🔄 Gör igen' : '▶️ Starta' ?>
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($mq['variants']['quiz']): ?>
                <?php
                    $is_completed = isset($student_progress['quiz']);
                    $completed_at = $is_completed ? $student_progress['quiz'] : null;
                ?>
                <div class="variant-card bg-white rounded-2xl shadow-xl p-6 border-2 <?= $is_completed ? 'border-green-400' : 'border-red-200' ?>">
                    <div class="flex justify-between items-start mb-4">
                        <div class="text-5xl">❓</div>
                        <?php if ($is_completed): ?>
                            <span class="completed-badge bg-green-500 text-white px-3 py-1 rounded-full text-xs font-bold">
                                ✓ KLAR
                            </span>
                        <?php endif; ?>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 mb-2">Vanligt Quiz</h3>
                    <p class="text-gray-600 mb-4">Fråga → Begrepp (rätt svar)</p>
                    <div class="text-sm text-gray-500 mb-4">
                        <?= $mq['variants']['quiz']['mc_count'] ?> flervalsfrågor
                    </div>
                    <?php if ($is_completed): ?>
                        <div class="text-xs text-green-600 mb-3">
                            Avklarad: <?= date('Y-m-d H:i', strtotime($completed_at)) ?>
                        </div>
                    <?php endif; ?>
                    <a href="multi-quiz-variant.php?mq_id=<?= $mq_id ?>&variant=quiz" 
                       class="block w-full bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white text-center px-6 py-3 rounded-xl font-bold shadow-lg transition transform hover:scale-105">
                        <?= $is_completed ? '🔄 Gör igen' : '▶️ Starta' ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Progress Summary -->
        <?php if ($current_student_id && !empty($student_progress)): ?>
            <div class="mt-6 bg-white/90 backdrop-blur-sm rounded-2xl shadow-xl p-6 border border-green-200">
                <h3 class="text-xl font-bold text-gray-800 mb-4">📊 Din Progress</h3>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <div class="text-center">
                        <div class="text-3xl font-bold text-green-600"><?= count($student_progress) ?></div>
                        <div class="text-sm text-gray-600">Avklarade</div>
                    </div>
                    <div class="text-center">
                        <div class="text-3xl font-bold text-blue-600">
                            <?= count(array_filter($mq['variants'], function($v) { return $v !== null; })) - count($student_progress) ?>
                        </div>
                        <div class="text-sm text-gray-600">Kvar</div>
                    </div>
                    <div class="text-center">
                        <div class="text-3xl font-bold text-purple-600">
                            <?= round((count($student_progress) / count(array_filter($mq['variants'], function($v) { return $v !== null; }))) * 100) ?>%
                        </div>
                        <div class="text-sm text-gray-600">Klart</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Spara progress lokalt i localStorage också
        const mqId = '<?= $mq_id ?>';
        const studentId = '<?= $current_student_id ?>';
        
        if (studentId) {
            const localProgress = JSON.parse(localStorage.getItem('multiQuizProgress') || '{}');
            if (!localProgress[mqId]) {
                localProgress[mqId] = {};
            }
            if (!localProgress[mqId][studentId]) {
                localProgress[mqId][studentId] = <?= json_encode($student_progress) ?>;
            }
            localStorage.setItem('multiQuizProgress', JSON.stringify(localProgress));
        }
    </script>
</body>
</html>
