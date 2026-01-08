<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';
requireTeacher();

$teacher_id = getCurrentTeacherID();

// Definiera flashcards-fil om den inte finns
if (!defined('FLASHCARDS_FILE')) {
    define('FLASHCARDS_FILE', DATA_DIR . 'flashcards.json');
}

// Kolla om det är quiz eller flashcard
$quiz_id = $_GET['quiz_id'] ?? '';
$flashcard_id = $_GET['flashcard_id'] ?? '';
$is_flashcard = !empty($flashcard_id);
$item_id = $is_flashcard ? $flashcard_id : $quiz_id;

// Ladda rätt data
if ($is_flashcard) {
    $flashcards = readJSON(FLASHCARDS_FILE);
    $items = $flashcards;
    $item = $flashcards[$flashcard_id] ?? null;
    $item_type_name = 'Flashcard-deck';
} else {
    $quizzes = readJSON(QUIZZES_FILE);
    $items = $quizzes;
    $item = $quizzes[$quiz_id] ?? null;
    $item_type_name = 'Quiz';
}

$stats = readJSON(STATS_FILE);

// Kolla att item finns och tillhör läraren
if (!$item || $item['teacher_id'] !== $teacher_id) {
    header('Location: ' . ($is_flashcard ? 'flashcards-admin.php' : 'admin.php'));
    exit;
}

// För bakåtkompatibilitet, använd $quiz-variabeln
$quiz = $item;
// Standardvärden beroende på typ
if ($is_flashcard) {
    $quiz_stats = $stats[$item_id] ?? [
        'type' => 'flashcard',
        'total_attempts' => 0,
        'completed' => 0,
        'avg_time_seconds' => 0,
        'avg_grade' => 0,
        'attempts' => [],
        'card_difficulty' => []
    ];
} else {
    $quiz_stats = $stats[$item_id] ?? [
        'total_attempts' => 0,
        'completed' => 0,
        'avg_time_seconds' => 0,
        'avg_errors' => 0,
        'attempts' => [],
        'question_errors' => []
    ];
}

// Sortera frågor/kort efter svårighet
$question_difficulty = [];
if ($is_flashcard) {
    // För flashcards: sortera efter card_difficulty
    if (isset($quiz['cards']) && is_array($quiz['cards'])) {
        foreach ($quiz['cards'] as $index => $card) {
            $difficulty = $quiz_stats['card_difficulty'][$index] ?? 0;
            $question_difficulty[] = [
                'index' => $index + 1,
                'question' => $card['front'] ?? '',
                'answer' => $card['back'] ?? '',
                'errors' => $difficulty
            ];
        }
        usort($question_difficulty, function($a, $b) {
            return $b['errors'] - $a['errors'];
        });
    }
} else {
    // För quiz: sortera efter question_errors
    if (isset($quiz['questions']) && is_array($quiz['questions'])) {
        foreach ($quiz['questions'] as $index => $question) {
            $errors = $quiz_stats['question_errors'][$index] ?? 0;
            $question_difficulty[] = [
                'index' => $index + 1,
                'question' => $question['question'] ?? '',
                'answer' => $question['answer'] ?? '',
                'errors' => $errors
            ];
        }
        usort($question_difficulty, function($a, $b) {
            return $b['errors'] - $a['errors'];
        });
    }
}

// Räkna completion rate
$completion_rate = $quiz_stats['total_attempts'] > 0
    ? round(($quiz_stats['completed'] / $quiz_stats['total_attempts']) * 100)
    : 0;

// Formatera tid
function formatTime($seconds) {
    $minutes = floor($seconds / 60);
    $secs = $seconds % 60;
    return sprintf('%d:%02d', $minutes, $secs);
}

// Skapa fördelning av fel/grades (för stapeldiagram)
$error_distribution = [];
if ($is_flashcard) {
    // För flashcards: visa grade-fördelning (0-3)
    if (isset($quiz_stats['attempts']) && is_array($quiz_stats['attempts'])) {
        foreach ($quiz_stats['attempts'] as $attempt) {
            $grade_dist = $attempt['grade_distribution'] ?? ['0' => 0, '1' => 0, '2' => 0, '3' => 0];
            foreach ($grade_dist as $grade => $count) {
                if (!isset($error_distribution[$grade])) {
                    $error_distribution[$grade] = 0;
                }
                $error_distribution[$grade] += $count;
            }
        }
        ksort($error_distribution);
    }
} else {
    // För quiz: visa fel-fördelning
    if (isset($quiz_stats['attempts']) && is_array($quiz_stats['attempts'])) {
        foreach ($quiz_stats['attempts'] as $attempt) {
            $errors = $attempt['errors'] ?? 0;
            if (!isset($error_distribution[$errors])) {
                $error_distribution[$errors] = 0;
            }
            $error_distribution[$errors]++;
        }
        ksort($error_distribution);
    }
}

// Hitta maxvärde för skalning
$max_count = !empty($error_distribution) ? max($error_distribution) : 1;

// Gruppera försök per elev (används i båda flikarna)
$students_data = [];
if (isset($quiz_stats['attempts']) && is_array($quiz_stats['attempts'])) {
    foreach ($quiz_stats['attempts'] as $attempt) {
        $name = $attempt['student_name'] ?? 'Okänd';
        if (!isset($students_data[$name])) {
            $students_data[$name] = [
                'attempts' => [],
                'total_attempts' => 0,
                'completed' => 0,
                'best_score' => PHP_INT_MAX,
                'total_time' => 0,
                'total_errors' => 0
            ];
        }
        $students_data[$name]['attempts'][] = $attempt;
        $students_data[$name]['total_attempts']++;
        if (isset($attempt['completed']) && $attempt['completed']) $students_data[$name]['completed']++;
        $students_data[$name]['best_score'] = min($students_data[$name]['best_score'], $attempt['errors'] ?? 0);
        $students_data[$name]['total_time'] += $attempt['time_seconds'] ?? 0;
        $students_data[$name]['total_errors'] += $attempt['errors'] ?? 0;
    }
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistik - <?= htmlspecialchars($quiz['title'] ?? ($is_flashcard ? 'Flashcard-deck' : 'Quiz')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-purple-50 min-h-screen p-4">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">
                        <?= $is_flashcard ? '🗂️' : '📊' ?> <?= htmlspecialchars($quiz['title'] ?? ($is_flashcard ? 'Flashcard-deck' : 'Quiz')) ?>
                    </h1>
                    <p class="text-gray-500">
                        <?php if ($is_flashcard): ?>
                            <?= count($quiz['cards'] ?? []) ?> kort • Skapad <?= isset($quiz['created']) ? date('Y-m-d', strtotime($quiz['created'])) : 'Okänt datum' ?>
                        <?php else: ?>
                            <?= count($quiz['questions'] ?? []) ?> frågor • Skapad <?= isset($quiz['created']) ? date('Y-m-d', strtotime($quiz['created'])) : 'Okänt datum' ?>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="<?= $is_flashcard ? 'flashcards-admin.php' : 'admin.php' ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                    Tillbaka
                </a>
            </div>
        </div>

        <!-- Flikar -->
        <div class="bg-white rounded-xl shadow-lg p-4 mb-6">
            <div class="flex gap-2 border-b">
                <button onclick="showStatsTab('overview')" id="tab-overview" class="px-4 py-2 font-medium border-b-2 border-blue-500 text-blue-600">
                    📊 Översikt
                </button>
                <button onclick="showStatsTab('students')" id="tab-students" class="px-4 py-2 font-medium text-gray-500 hover:text-gray-700">
                    👥 Per elev
                </button>
                <button onclick="showStatsTab('attempts')" id="tab-attempts" class="px-4 py-2 font-medium text-gray-500 hover:text-gray-700">
                    📝 Alla försök
                </button>
            </div>
        </div>

        <!-- Tab: Översikt -->
        <div id="content-overview" class="stats-tab-content">
        <!-- Huvudstatistik -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Totalt försök</div>
                <div class="text-3xl font-bold text-blue-600"><?= $quiz_stats['total_attempts'] ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Antal klarat</div>
                <div class="text-3xl font-bold text-green-600"><?= $quiz_stats['completed'] ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Genomförandeprocent</div>
                <div class="text-3xl font-bold text-purple-600"><?= $completion_rate ?>%</div>
            </div>
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Genomsnittstid</div>
                <div class="text-3xl font-bold text-orange-600"><?= formatTime($quiz_stats['avg_time_seconds']) ?></div>
            </div>
            <?php if ($is_flashcard): ?>
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Genomsnittlig bedömning</div>
                <div class="text-3xl font-bold text-indigo-600"><?= number_format($quiz_stats['avg_grade'], 1) ?> / 3</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Resultatfördelning (stapeldiagram) -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <?php if ($is_flashcard): ?>
                    📊 Bedömningsfördelning
                <?php else: ?>
                    📊 Resultatfördelning
                <?php endif; ?>
            </h2>
            <?php if (empty($error_distribution)): ?>
                <p class="text-gray-500 text-center py-4">Ingen data ännu</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($error_distribution as $error_count => $student_count): ?>
                        <div class="flex items-center gap-4">
                            <div class="w-16 text-right text-sm font-medium text-gray-700">
                                <?php if ($is_flashcard): ?>
                                    <?php
                                        $grade_labels = ['0' => '❌ Fel', '1' => '🤔 Osäker', '2' => '✅ Rätt', '3' => '⭐ Perfekt'];
                                        echo $grade_labels[$error_count] ?? $error_count;
                                    ?>:
                                <?php else: ?>
                                    <?= $error_count ?> fel:
                                <?php endif; ?>
                            </div>
                            <div class="flex-1 bg-gray-100 rounded-full h-8 relative overflow-hidden">
                                <div class="bg-gradient-to-r from-blue-500 to-purple-500 h-full rounded-full flex items-center justify-end pr-3 text-white text-sm font-bold transition-all"
                                     style="width: <?= ($student_count / $max_count) * 100 ?>%">
                                    <?php if (($student_count / $max_count) > 0.15): ?>
                                        <?= $student_count ?> <?= $student_count === 1 ? 'försök' : 'försök' ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (($student_count / $max_count) <= 0.15): ?>
                                    <div class="absolute right-3 top-1/2 -translate-y-1/2 text-sm font-bold text-gray-700">
                                        <?= $student_count ?> <?= $student_count === 1 ? 'försök' : 'försök' ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <!-- Fel per omgång / Genomsnittlig bedömning -->
            <?php if ($is_flashcard): ?>
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">⭐ Genomsnittlig bedömning</h2>
                <div class="text-5xl font-bold text-indigo-600"><?= number_format($quiz_stats['avg_grade'], 1) ?></div>
                <p class="text-gray-500 mt-2">på en skala 0-3 (0=fel, 1=osäker, 2=rätt, 3=perfekt)</p>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">⚠️ Genomsnittligt antal fel</h2>
                <div class="text-5xl font-bold text-red-600"><?= $quiz_stats['avg_errors'] ?></div>
                <p class="text-gray-500 mt-2">fel per genomförd omgång</p>
            </div>
            <?php endif; ?>

            <!-- Quiz/Deck-länk -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">
                    <?php if ($is_flashcard): ?>
                        🔗 Dela flashcard-deck
                    <?php else: ?>
                        🔗 Dela quiz
                    <?php endif; ?>
                </h2>
                <div class="bg-gray-50 p-3 rounded border border-gray-200 font-mono text-sm break-all mb-2">
                    <?php if ($is_flashcard): ?>
                        <?= $_SERVER['HTTP_HOST'] ?? 'yoursite.com' ?>/quiz-app/q/flashcards.php?deck_id=<?= $item_id ?>
                    <?php else: ?>
                        <?= $_SERVER['HTTP_HOST'] ?? 'yoursite.com' ?>/quiz-app/q/<?= $item_id ?>.html
                    <?php endif; ?>
                </div>
                <button onclick="copyQuizLink()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg w-full">
                    Kopiera länk
                </button>
            </div>
        </div>

        <!-- Svåraste frågor/kort -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <?php if ($is_flashcard): ?>
                    🔥 Svåraste korten
                <?php else: ?>
                    🔥 Svåraste frågorna
                <?php endif; ?>
            </h2>
            <?php if (empty($question_difficulty) || $question_difficulty[0]['errors'] === 0): ?>
                <p class="text-gray-500 text-center py-4">Ingen data ännu</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach (array_slice($question_difficulty, 0, 5) as $q): ?>
                        <?php if ($q['errors'] > 0): ?>
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1">
                                        <div class="font-medium text-gray-800"><?= htmlspecialchars($q['question']) ?></div>
                                        <div class="text-sm text-gray-500 mt-1">Svar: <?= htmlspecialchars($q['answer']) ?></div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-2xl font-bold text-red-600"><?= $q['errors'] ?></div>
                                        <div class="text-sm text-gray-500">fel</div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Glosquiz felstavningar -->
        <?php if (isset($quiz['type']) && $quiz['type'] === 'glossary' && !empty($quiz_stats['misspellings'])): ?>
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">📝 Felstavade ord</h2>
            <p class="text-gray-500 mb-4">Här ser du hur elever har stavat orden fel</p>
            <div class="space-y-4">
                <?php foreach ($quiz_stats['misspellings'] as $correct => $misspellings_arr): ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <div class="font-bold text-lg text-gray-800 mb-2">
                            ✅ Rätt: <span class="text-green-600"><?= htmlspecialchars($correct) ?></span>
                        </div>
                        <div class="text-sm text-gray-600 mb-2">Felstavningar:</div>
                        <div class="flex flex-wrap gap-2">
                            <?php
                            if (is_array($misspellings_arr)) {
                                foreach ($misspellings_arr as $misspelled):
                                    // Hoppa över om det är en array eller objekt
                                    if (is_array($misspelled) || is_object($misspelled)) continue;
                            ?>
                                <span class="bg-red-100 text-red-800 px-3 py-1 rounded-lg text-sm">
                                    ❌ <?= htmlspecialchars((string)$misspelled) ?>
                                </span>
                            <?php
                                endforeach;
                            }
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        </div><!-- End Overview Tab -->

        <!-- Tab: Per elev -->
        <div id="content-students" class="stats-tab-content hidden">

            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">👥 Statistik per elev</h2>
                <?php if (empty($students_data)): ?>
                    <p class="text-gray-500 text-center py-4">Inga elever ännu</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($students_data as $student_name => $data): ?>
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex justify-between items-start mb-3">
                                    <div>
                                        <h3 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($student_name) ?></h3>
                                        <p class="text-sm text-gray-500">
                                            <?= $data['total_attempts'] ?> försök •
                                            <?= $data['completed'] ?> klarat •
                                            Bäst: <?= $data['best_score'] === PHP_INT_MAX ? '-' : $data['best_score'] . ' fel' ?>
                                        </p>
                                    </div>
                                    <button onclick="toggleStudentDetails('<?= htmlspecialchars($student_name) ?>')"
                                            class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm">
                                        Visa detaljer
                                    </button>
                                </div>
                                <div class="grid grid-cols-3 gap-4 text-center">
                                    <div>
                                        <div class="text-2xl font-bold text-blue-600"><?= round($data['total_time'] / $data['total_attempts']) ?>s</div>
                                        <div class="text-xs text-gray-500">Snitt tid</div>
                                    </div>
                                    <div>
                                        <div class="text-2xl font-bold text-red-600"><?= round($data['total_errors'] / $data['total_attempts'], 1) ?></div>
                                        <div class="text-xs text-gray-500">Snitt fel</div>
                                    </div>
                                    <div>
                                        <div class="text-2xl font-bold text-green-600">
                                            <?= $data['total_attempts'] > 0 ? round(($data['completed'] / $data['total_attempts']) * 100) : 0 ?>%
                                        </div>
                                        <div class="text-xs text-gray-500">Klarat</div>
                                    </div>
                                </div>

                                <!-- Elevens försök (dold som standard) -->
                                <div id="student-<?= htmlspecialchars($student_name) ?>" class="hidden mt-4 pt-4 border-t">
                                    <h4 class="font-bold text-sm mb-2">Alla försök:</h4>
                                    <div class="space-y-2">
                                        <?php
                                        $student_attempts = isset($data['attempts']) && is_array($data['attempts'])
                                            ? array_reverse($data['attempts'])
                                            : [];
                                        foreach ($student_attempts as $attempt):
                                        ?>
                                            <div class="flex justify-between items-center text-sm bg-gray-50 p-2 rounded">
                                                <span><?= isset($attempt['timestamp']) ? date('Y-m-d H:i', strtotime($attempt['timestamp'])) : '-' ?></span>
                                                <span><?= formatTime($attempt['time_seconds'] ?? 0) ?></span>
                                                <span class="<?= ($attempt['errors'] ?? 0) == 0 ? 'text-green-600 font-bold' : 'text-red-600' ?>">
                                                    <?= $attempt['errors'] ?? 0 ?> fel
                                                </span>
                                                <?php if (isset($attempt['completed']) && $attempt['completed']): ?>
                                                    <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-xs">✅ Klarat</span>
                                                <?php else: ?>
                                                    <span class="bg-gray-100 text-gray-800 px-2 py-1 rounded text-xs">⏸️ Ej klart</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div><!-- End Students Tab -->

        <!-- Tab: Alla försök -->
        <div id="content-attempts" class="stats-tab-content hidden">
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">📝 Alla försök</h2>

                <!-- Filter -->
                <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Elev</label>
                            <select id="filter-student" onchange="filterAttempts()" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                <option value="">Alla</option>
                                <?php if (!empty($students_data)): ?>
                                    <?php foreach (array_keys($students_data) as $student): ?>
                                        <option value="<?= htmlspecialchars($student) ?>"><?= htmlspecialchars($student) ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Status</label>
                            <select id="filter-status" onchange="filterAttempts()" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                <option value="">Alla</option>
                                <option value="completed">Klarat</option>
                                <option value="incomplete">Ej klarat</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Sortera</label>
                            <select id="sort-attempts" onchange="sortAttempts()" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                <option value="date-desc">Senaste först</option>
                                <option value="date-asc">Äldsta först</option>
                                <option value="errors-asc">Minst fel först</option>
                                <option value="errors-desc">Flest fel först</option>
                                <option value="time-asc">Snabbast först</option>
                                <option value="time-desc">Långsammast först</option>
                            </select>
                        </div>
                    </div>
                </div>

                <?php if (empty($quiz_stats['attempts'])): ?>
                    <p class="text-gray-500 text-center py-4">Inga försök ännu</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full" id="attempts-table">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Elev</th>
                                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Tidpunkt</th>
                                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Status</th>
                                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Tid</th>
                                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Antal fel</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php
                                $attempts = isset($quiz_stats['attempts']) && is_array($quiz_stats['attempts'])
                                    ? array_reverse($quiz_stats['attempts'])
                                    : [];
                                foreach ($attempts as $index => $attempt):
                                ?>
                                    <tr class="attempt-row"
                                        data-student="<?= htmlspecialchars($attempt['student_name'] ?? 'Okänd') ?>"
                                        data-status="<?= isset($attempt['completed']) && $attempt['completed'] ? 'completed' : 'incomplete' ?>"
                                        data-timestamp="<?= strtotime($attempt['timestamp'] ?? 'now') ?>"
                                        data-errors="<?= $attempt['errors'] ?? 0 ?>"
                                        data-time="<?= $attempt['time_seconds'] ?? 0 ?>">
                                        <td class="px-4 py-3 text-sm font-medium"><?= htmlspecialchars($attempt['student_name'] ?? 'Okänd') ?></td>
                                        <td class="px-4 py-3 text-sm"><?= isset($attempt['timestamp']) ? date('Y-m-d H:i', strtotime($attempt['timestamp'])) : '-' ?></td>
                                        <td class="px-4 py-3">
                                            <?php if (isset($attempt['completed']) && $attempt['completed']): ?>
                                                <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-sm">✅ Klarat</span>
                                            <?php else: ?>
                                                <span class="bg-gray-100 text-gray-800 px-2 py-1 rounded text-sm">⏸️ Ej klart</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm"><?= formatTime($attempt['time_seconds'] ?? 0) ?></td>
                                        <td class="px-4 py-3 text-sm font-bold <?= ($attempt['errors'] ?? 0) == 0 ? 'text-green-600' : 'text-red-600' ?>">
                                            <?= $attempt['errors'] ?? 0 ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div><!-- End Attempts Tab -->
    </div>

    <script>
        console.log('JavaScript loading...');

        function copyQuizLink() {
            <?php if ($is_flashcard): ?>
                const url = window.location.origin + window.location.pathname.replace('stats.php', '') + 'q/flashcards.php?deck_id=<?= $item_id ?>';
            <?php else: ?>
                const url = window.location.origin + window.location.pathname.replace('stats.php', '') + 'q/<?= $item_id ?>.html';
            <?php endif; ?>
            navigator.clipboard.writeText(url).then(() => {
                alert('Länk kopierad! ' + url);
            });
        }

        function showStatsTab(tab) {
            console.log('showStatsTab called with:', tab);
            // Dölj alla flikar
            document.querySelectorAll('.stats-tab-content').forEach(el => el.classList.add('hidden'));

            // Ta bort active styling från alla knappar
            document.querySelectorAll('[id^="tab-"]').forEach(el => {
                el.classList.remove('border-blue-500', 'text-blue-600');
                el.classList.add('text-gray-500');
            });

            // Visa vald flik
            document.getElementById('content-' + tab).classList.remove('hidden');
            document.getElementById('tab-' + tab).classList.add('border-blue-500', 'text-blue-600');
            document.getElementById('tab-' + tab).classList.remove('text-gray-500');
        }
        console.log('showStatsTab function defined');

        function toggleStudentDetails(studentName) {
            const element = document.getElementById('student-' + studentName);
            element.classList.toggle('hidden');
        }

        function filterAttempts() {
            const studentFilter = document.getElementById('filter-student').value.toLowerCase();
            const statusFilter = document.getElementById('filter-status').value;

            document.querySelectorAll('.attempt-row').forEach(row => {
                const student = row.dataset.student.toLowerCase();
                const status = row.dataset.status;

                const matchStudent = !studentFilter || student === studentFilter;
                const matchStatus = !statusFilter || status === statusFilter;

                if (matchStudent && matchStatus) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function sortAttempts() {
            const sortBy = document.getElementById('sort-attempts').value;
            const tbody = document.querySelector('#attempts-table tbody');
            const rows = Array.from(tbody.querySelectorAll('.attempt-row'));

            rows.sort((a, b) => {
                switch(sortBy) {
                    case 'date-desc':
                        return parseInt(b.dataset.timestamp) - parseInt(a.dataset.timestamp);
                    case 'date-asc':
                        return parseInt(a.dataset.timestamp) - parseInt(b.dataset.timestamp);
                    case 'errors-asc':
                        return parseInt(a.dataset.errors) - parseInt(b.dataset.errors);
                    case 'errors-desc':
                        return parseInt(b.dataset.errors) - parseInt(a.dataset.errors);
                    case 'time-asc':
                        return parseInt(a.dataset.time) - parseInt(b.dataset.time);
                    case 'time-desc':
                        return parseInt(b.dataset.time) - parseInt(a.dataset.time);
                    default:
                        return 0;
                }
            });

            rows.forEach(row => tbody.appendChild(row));
        }
    </script>
</body>
</html>
