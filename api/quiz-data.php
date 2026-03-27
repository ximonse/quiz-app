<?php
// api/quiz-data.php — Serve quiz data to student player
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$quizId = $_GET['id'] ?? '';
if (!$quizId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing quiz id']);
    exit;
}

$quizzes = readJSON(DATA_DIR . '/quizzes.json');
if (!isset($quizzes[$quizId])) {
    http_response_code(404);
    echo json_encode(['error' => 'Quiz not found']);
    exit;
}

$quiz = $quizzes[$quizId];
$now = date('Y-m-d H:i');
$status = 'open';

if (!empty($quiz['settings']['time_lock'])) {
    $lock = $quiz['settings']['time_lock'];
    if (!empty($lock['opens']) && $now < $lock['opens']) {
        $status = 'not_yet';
        echo json_encode([
            'status' => $status,
            'opens' => $lock['opens'],
            'title' => $quiz['title']
        ]);
        exit;
    }
    if (!empty($lock['closes']) && $now > $lock['closes']) {
        $status = 'training_only';
    }
}

// Strip teacher-only fields before sending
$studentQuiz = [
    'id' => $quiz['id'],
    'title' => $quiz['title'],
    'type' => $quiz['type'],
    'settings' => $quiz['settings'],
    'items' => $quiz['items'],
    'status' => $status
];

echo json_encode($studentQuiz);
