<?php
require_once 'config.php';
requireTeacher();

$teacher_id = getCurrentTeacherID();

// Läs all data
$multi_quizzes = readJSON(DATA_DIR . 'multi_quizzes.json');
$progress_file = DATA_DIR . 'multi_quiz_progress.json';
$all_progress = file_exists($progress_file) ? json_decode(file_get_contents($progress_file), true) : [];

// Filtrera lärarens quizzes
$my_quizzes = array_filter($multi_quizzes, function($mq) use ($teacher_id) {
    return $mq['teacher_id'] === $teacher_id;
});

// Hämta filter
$filter_quiz = $_GET['quiz'] ?? '';
$filter_subject = $_GET['subject'] ?? '';
$filter_grade = $_GET['grade'] ?? '';
$filter_tag = $_GET['tag'] ?? '';
$filter_student = $_GET['student'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';

// Samla alla unika värden för dropdowns
$all_subjects = [];
$all_grades = [];
$all_tags = [];
$all_students = [];
$all_quiz_titles = [];

foreach ($my_quizzes as $mq_id => $mq) {
    $all_quiz_titles[$mq_id] = $mq['title'];
    if (!empty($mq['subject'])) $all_subjects[] = $mq['subject'];
    if (!empty($mq['grade'])) $all_grades[] = $mq['grade'];
    if (!empty($mq['tags'])) {
        foreach (explode(',', $mq['tags']) as $tag) {
            $all_tags[] = trim($tag);
        }
    }
    
    if (isset($all_progress[$mq_id])) {
        foreach ($all_progress[$mq_id] as $student_id => $variants) {
            $all_students[] = $student_id;
        }
    }
}

$all_subjects = array_unique($all_subjects);
$all_grades = array_unique($all_grades);
$all_tags = array_unique($all_tags);
$all_students = array_unique($all_students);
sort($all_subjects);
sort($all_grades);
sort($all_tags);
sort($all_students);

// Bygg dataset för export/visning
$data_rows = [];

foreach ($my_quizzes as $mq_id => $mq) {
    // Applicera quiz-filter
    if ($filter_quiz && $mq_id !== $filter_quiz) continue;
    if ($filter_subject && $mq['subject'] !== $filter_subject) continue;
    if ($filter_grade && $mq['grade'] !== $filter_grade) continue;
    if ($filter_tag && strpos($mq['tags'], $filter_tag) === false) continue;
    
    $quiz_progress = $all_progress[$mq_id] ?? [];
    
    foreach ($quiz_progress as $student_id => $variants_done) {
        // Applicera student-filter
        if ($filter_student && $student_id !== $filter_student) continue;
        
        foreach ($variants_done as $variant => $timestamp) {
            // Applicera datumfilter
            if ($filter_date_from && $timestamp < $filter_date_from) continue;
            if ($filter_date_to && $timestamp > $filter_date_to . ' 23:59:59') continue;
            
            $data_rows[] = [
                'quiz_title' => $mq['title'],
                'subject' => $mq['subject'] ?? '',
                'grade' => $mq['grade'] ?? '',
                'tags' => $mq['tags'] ?? '',
                'student_id' => $student_id,
                'variant' => $variant,
                'timestamp' => $timestamp,
                'date' => date('Y-m-d', strtotime($timestamp)),
                'time' => date('H:i:s', strtotime($timestamp))
            ];
        }
    }
}

// Sortera efter tidsstämpel (senaste först)
usort($data_rows, function($a, $b) {
    return strtotime($b['timestamp']) - strtotime($a['timestamp']);
});

// Hantera CSV-export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="multi-quiz-statistik-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // BOM för UTF-8 (så Excel öppnar svenska tecken rätt)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header
    fputcsv($output, [
        'Quiz-titel',
        'Ämne',
        'Årskurs',
        'Taggar',
        'Elev',
        'Variant',
        'Datum',
        'Tid',
        'Tidsstämpel'
    ], ';');
    
    // Data
    foreach ($data_rows as $row) {
        fputcsv($output, [
            $row['quiz_title'],
            $row['subject'],
            $row['grade'],
            $row['tags'],
            $row['student_id'],
            $row['variant'],
            $row['date'],
            $row['time'],
            $row['timestamp']
        ], ';');
    }
    
    fclose($output);
    exit;
}

$variant_names = [
    'glossary' => '📚 Glosor',
    'reverse_glossary' => '🔄 Omvända Glosor',
    'flashcard' => '🗂️ Flashcard',
    'reverse_flashcard' => '🔄 Omvända Flashcard',
    'quiz' => '❓ Quiz'
];
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-Quiz Statistik</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen p-4">
    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">📊 Multi-Quiz Statistik</h1>
                    <p class="text-gray-600 mt-1">Översikt och export av alla aktiviteter</p>
                </div>
                <a href="multi-quiz-admin.php" class="text-gray-600 hover:text-gray-900 font-medium">
                    ← Tillbaka
                </a>
            </div>
        </div>

        <!-- Filter & Export -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">🔍 Filtrera & Exportera</h2>
            
            <form method="GET" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Quiz</label>
                        <select name="quiz" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <option value="">Alla quizzes</option>
                            <?php foreach ($all_quiz_titles as $qid => $title): ?>
                                <option value="<?= $qid ?>" <?= $filter_quiz === $qid ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($title) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ämne</label>
                        <select name="subject" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <option value="">Alla ämnen</option>
                            <?php foreach ($all_subjects as $subject): ?>
                                <option value="<?= htmlspecialchars($subject) ?>" <?= $filter_subject === $subject ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Årskurs</label>
                        <select name="grade" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <option value="">Alla årskurser</option>
                            <?php foreach ($all_grades as $grade): ?>
                                <option value="<?= htmlspecialchars($grade) ?>" <?= $filter_grade === $grade ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($grade) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tagg</label>
                        <select name="tag" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <option value="">Alla taggar</option>
                            <?php foreach ($all_tags as $tag): ?>
                                <option value="<?= htmlspecialchars($tag) ?>" <?= $filter_tag === $tag ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tag) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Elev</label>
                        <select name="student" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <option value="">Alla elever</option>
                            <?php foreach ($all_students as $student): ?>
                                <option value="<?= htmlspecialchars($student) ?>" <?= $filter_student === $student ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($student) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Från datum</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($filter_date_from) ?>" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Till datum</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($filter_date_to) ?>" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-2 rounded-lg font-medium transition">
                        🔍 Filtrera
                    </button>
                    <a href="multi-quiz-stats.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg font-medium transition inline-flex items-center">
                        ✕ Rensa filter
                    </a>
                    <button type="submit" name="export" value="csv" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-medium transition ml-auto">
                        📥 Exportera till CSV
                    </button>
                </div>
            </form>
        </div>

        <!-- Resultat -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold text-gray-800">Resultat (<?= count($data_rows) ?> aktiviteter)</h2>
            </div>
            
            <?php if (empty($data_rows)): ?>
                <p class="text-gray-500 text-center py-8">Inga aktiviteter matchar filtren.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Quiz</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Ämne</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Årskurs</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Elev</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Variant</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700">Datum & Tid</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($data_rows as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($row['quiz_title']) ?></td>
                                    <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($row['subject']) ?></td>
                                    <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($row['grade']) ?></td>
                                    <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($row['student_id']) ?></td>
                                    <td class="px-4 py-3">
                                        <span class="inline-block px-2 py-1 bg-purple-100 text-purple-700 rounded text-xs font-medium">
                                            <?= $variant_names[$row['variant']] ?? $row['variant'] ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600">
                                        <?= $row['date'] ?> <span class="text-gray-400"><?= $row['time'] ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
