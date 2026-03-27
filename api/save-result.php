<?php
// api/save-result.php — Save student quiz results
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['quiz_id']) || empty($input['student_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$quizzes = readJSON(QUIZZES_FILE);
$quizId = $input['quiz_id'];

if (!isset($quizzes[$quizId])) {
    http_response_code(404);
    echo json_encode(['error' => 'Quiz not found']);
    exit;
}

// Check time lock — don't save after closing
$quiz = $quizzes[$quizId];
if (!empty($quiz['settings']['time_lock']['closes'])) {
    if (date('Y-m-d H:i') > $quiz['settings']['time_lock']['closes']) {
        echo json_encode(['saved' => false, 'reason' => 'training_only']);
        exit;
    }
}

$result = [
    'student_name' => substr(trim($input['student_name']), 0, 50),
    'timestamp' => date('Y-m-d H:i:s'),
    'score' => intval($input['score']),
    'total' => intval($input['total']),
    'errors' => $input['errors'] ?? []
];

$quizzes[$quizId]['results'][] = $result;
writeJSON(QUIZZES_FILE, $quizzes);

echo json_encode(['saved' => true]);
