<?php
require_once 'config.php';
requireTeacher();

$mq_id = $_GET['id'] ?? '';
$multi_quizzes = readJSON(DATA_DIR . 'multi_quizzes.json');

if (!isset($multi_quizzes[$mq_id])) {
    die("Multi-quiz hittades inte");
}

$mq = $multi_quizzes[$mq_id];

// Läs progress-data
$progress_file = DATA_DIR . 'multi_quiz_progress.json';
$all_progress = file_exists($progress_file) ? json_decode(file_get_contents($progress_file), true) : [];
$quiz_progress = $all_progress[$mq_id] ?? [];

// Räkna aktiva varianter
$active_variants = array_filter($mq['variants'], function($v) { return $v !== null; });
$total_variants = count($active_variants);

// Beräkna statistik
$students = [];
$variant_stats = [
    'glossary' => 0,
    'reverse_glossary' => 0,
    'flashcard' => 0,
    'reverse_flashcard' => 0,
    'quiz' => 0
];

foreach ($quiz_progress as $student_id => $variants_done) {
    $completed_count = count($variants_done);
    $students[$student_id] = [
        'id' => $student_id,
        'completed' => $completed_count,
        'total' => $total_variants,
        'percentage' => $total_variants > 0 ? round(($completed_count / $total_variants) * 100) : 0,
        'variants' => $variants_done,
        'finished_all' => $completed_count >= $total_variants
    ];
    
    // Räkna per variant
    foreach ($variants_done as $variant => $timestamp) {
        if (isset($variant_stats[$variant])) {
            $variant_stats[$variant]++;
        }
    }
}

// Sortera elever efter progress (mest klara först)
usort($students, function($a, $b) {
    if ($a['completed'] === $b['completed']) {
        return strcmp($a['id'], $b['id']);
    }
    return $b['completed'] - $a['completed'];
});

$total_students = count($students);
$students_finished_all = count(array_filter($students, function($s) { return $s['finished_all']; }));

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
    <title>Statistik - <?= htmlspecialchars($mq['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen p-4">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">📊 Statistik</h1>
                    <p class="text-gray-600 mt-1"><?= htmlspecialchars($mq['title']) ?></p>
                </div>
                <a href="multi-quiz-admin.php" class="text-gray-600 hover:text-gray-900 font-medium">
                    ← Tillbaka
                </a>
            </div>
        </div>

        <!-- Översikt -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow-sm p-6">
                <div class="text-sm text-gray-500 mb-1">Totalt elever</div>
                <div class="text-3xl font-bold text-blue-600"><?= $total_students ?></div>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-6">
                <div class="text-sm text-gray-500 mb-1">Klarat alla</div>
                <div class="text-3xl font-bold text-green-600"><?= $students_finished_all ?></div>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-6">
                <div class="text-sm text-gray-500 mb-1">Aktiva varianter</div>
                <div class="text-3xl font-bold text-purple-600"><?= $total_variants ?></div>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-6">
                <div class="text-sm text-gray-500 mb-1">Genomsnittlig progress</div>
                <div class="text-3xl font-bold text-orange-600">
                    <?= $total_students > 0 ? round(array_sum(array_column($students, 'percentage')) / $total_students) : 0 ?>%
                </div>
            </div>
        </div>

        <!-- Variant-statistik -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Varianter</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($active_variants as $variant_key => $variant_settings): ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <div class="flex justify-between items-center mb-2">
                            <span class="font-semibold text-gray-700"><?= $variant_names[$variant_key] ?? $variant_key ?></span>
                            <span class="text-2xl font-bold text-purple-600"><?= $variant_stats[$variant_key] ?></span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-purple-600 h-2 rounded-full" style="width: <?= $total_students > 0 ? round(($variant_stats[$variant_key] / $total_students) * 100) : 0 ?>%"></div>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            <?= $total_students > 0 ? round(($variant_stats[$variant_key] / $total_students) * 100) : 0 ?>% klarat
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Elevlista -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Elever (<?= $total_students ?>)</h2>
            
            <?php if (empty($students)): ?>
                <p class="text-gray-500 text-center py-8">Inga elever har börjat detta multi-quiz än.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Elev</th>
                                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Progress</th>
                                <?php foreach ($active_variants as $variant_key => $variant_settings): ?>
                                    <th class="px-4 py-3 text-center text-sm font-semibold text-gray-700">
                                        <?= $variant_names[$variant_key] ?? $variant_key ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($students as $student): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-800"><?= htmlspecialchars($student['id']) ?></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 bg-gray-200 rounded-full h-2 max-w-[120px]">
                                                <div class="<?= $student['finished_all'] ? 'bg-green-500' : 'bg-blue-500' ?> h-2 rounded-full" 
                                                     style="width: <?= $student['percentage'] ?>%"></div>
                                            </div>
                                            <span class="text-sm font-medium text-gray-600">
                                                <?= $student['completed'] ?>/<?= $student['total'] ?>
                                            </span>
                                        </div>
                                    </td>
                                    <?php foreach ($active_variants as $variant_key => $variant_settings): ?>
                                        <td class="px-4 py-3 text-center">
                                            <?php if (isset($student['variants'][$variant_key])): ?>
                                                <div class="inline-flex flex-col items-center">
                                                    <span class="text-green-600 text-xl">✓</span>
                                                    <span class="text-xs text-gray-500">
                                                        <?= date('Y-m-d H:i', strtotime($student['variants'][$variant_key])) ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-300 text-xl">○</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
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
