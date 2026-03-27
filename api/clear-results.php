<?php
// api/clear-results.php — Clear all results for a quiz (teacher only)
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
requireTeacher();
requireValidCsrf(true);

$input = json_decode(file_get_contents('php://input'), true);
$quizId = $input['quiz_id'] ?? '';

if (!$quizId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing quiz_id']);
    exit;
}

$quizzes = readJSON(DATA_DIR . '/quizzes.json');
if (!isset($quizzes[$quizId])) {
    http_response_code(404);
    echo json_encode(['error' => 'Quiz not found']);
    exit;
}

$quizzes[$quizId]['results'] = [];
writeJSON(DATA_DIR . '/quizzes.json', $quizzes);

echo json_encode(['success' => true]);
