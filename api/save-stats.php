<?php
header('Content-Type: application/json');
require_once '../config.php';

// Läs inkommande JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['quiz_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$quiz_id = $input['quiz_id'];
$student_name = $input['student_name'] ?? 'Okänd';
$completed = $input['completed'] ?? false;
$time_seconds = $input['time_seconds'] ?? 0;
$total_errors = $input['total_errors'] ?? 0;
$question_errors = $input['question_errors'] ?? [];
$misspellings = $input['misspellings'] ?? [];

// Läs statistik
$stats = readJSON(STATS_FILE);

if (!isset($stats[$quiz_id])) {
    $stats[$quiz_id] = [
        'total_attempts' => 0,
        'completed' => 0,
        'avg_time_seconds' => 0,
        'avg_errors' => 0,
        'attempts' => [],
        'question_errors' => []
    ];
}

// Uppdatera statistik
$stats[$quiz_id]['total_attempts']++;
if ($completed) {
    $stats[$quiz_id]['completed']++;
}

// Lägg till detta försök
$stats[$quiz_id]['attempts'][] = [
    'student_name' => $student_name,
    'timestamp' => date('Y-m-d H:i:s'),
    'completed' => $completed,
    'time_seconds' => $time_seconds,
    'errors' => $total_errors,
    'question_errors' => $question_errors
];

// Uppdatera genomsnitt
$total_time = 0;
$total_err = 0;
$count = count($stats[$quiz_id]['attempts']);
foreach ($stats[$quiz_id]['attempts'] as $attempt) {
    $total_time += $attempt['time_seconds'];
    $total_err += $attempt['errors'];
}
$stats[$quiz_id]['avg_time_seconds'] = $count > 0 ? round($total_time / $count) : 0;
$stats[$quiz_id]['avg_errors'] = $count > 0 ? round($total_err / $count, 1) : 0;

// Uppdatera fråge-fel statistik
foreach ($question_errors as $q_index => $errors) {
    if (!isset($stats[$quiz_id]['question_errors'][$q_index])) {
        $stats[$quiz_id]['question_errors'][$q_index] = 0;
    }
    $stats[$quiz_id]['question_errors'][$q_index] += $errors;
}

// Spara felstavningar för glosquiz
if (!empty($misspellings)) {
    foreach ($misspellings as $correct => $misspelled) {
        if (!isset($stats[$quiz_id]['misspellings'][$correct])) {
            $stats[$quiz_id]['misspellings'][$correct] = [];
        }
        if (!in_array($misspelled, $stats[$quiz_id]['misspellings'][$correct])) {
            $stats[$quiz_id]['misspellings'][$correct][] = $misspelled;
        }
    }
}

// Uppdatera senaste användning och completion rate
$stats[$quiz_id]['last_attempt'] = date('Y-m-d H:i:s');
$stats[$quiz_id]['completion_rate'] = $stats[$quiz_id]['total_attempts'] > 0
    ? round(($stats[$quiz_id]['completed'] / $stats[$quiz_id]['total_attempts']) * 100, 1)
    : 0;

// Spara
writeJSON(STATS_FILE, $stats);

// Uppdatera teacher_stats.json och system_stats.json
updateExtendedStats($quiz_id, $completed);

echo json_encode(['success' => true]);

// Funktion för att uppdatera utökad statistik
function updateExtendedStats($quiz_id, $completed) {
    // Läs quiz-data för att få teacher_id och quiz-typ
    $quizzes = readJSON(QUIZZES_FILE);
    if (!isset($quizzes[$quiz_id])) return;

    $quiz = $quizzes[$quiz_id];
    $teacher_id = $quiz['teacher_id'];
    $quiz_type = $quiz['type'];

    // Uppdatera teacher_stats.json
    $teacher_stats_file = __DIR__ . '/../data/teacher_stats.json';
    $teacher_stats = file_exists($teacher_stats_file) ? json_decode(file_get_contents($teacher_stats_file), true) : [];

    if (!isset($teacher_stats[$teacher_id])) {
        $teacher_stats[$teacher_id] = [
            'total_quizzes' => 0,
            'total_attempts' => 0,
            'total_completed' => 0,
            'last_activity' => null,
            'quiz_types' => ['fact' => 0, 'glossary' => 0]
        ];
    }

    $teacher_stats[$teacher_id]['total_attempts']++;
    if ($completed) {
        $teacher_stats[$teacher_id]['total_completed']++;
    }
    $teacher_stats[$teacher_id]['last_activity'] = date('Y-m-d H:i:s');

    file_put_contents($teacher_stats_file, json_encode($teacher_stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Uppdatera system_stats.json
    $system_stats_file = __DIR__ . '/../data/system_stats.json';
    $system_stats = file_exists($system_stats_file) ? json_decode(file_get_contents($system_stats_file), true) : [
        'total_teachers' => 0,
        'total_quizzes' => 0,
        'total_attempts' => 0,
        'total_completed' => 0,
        'quiz_types' => ['fact' => 0, 'glossary' => 0],
        'last_updated' => null
    ];

    $system_stats['total_attempts']++;
    if ($completed) {
        $system_stats['total_completed']++;
    }
    $system_stats['last_updated'] = date('Y-m-d H:i:s');

    file_put_contents($system_stats_file, json_encode($system_stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
