<?php
// api/clear-results.php — Clear all results for a quiz (teacher only)
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

$quizzes = readJSON(QUIZZES_FILE);
if (!isset($quizzes[$quizId])) {
    http_response_code(404);
    echo json_encode(['error' => 'Quiz not found']);
    exit;
}

if (($quizzes[$quizId]['teacher_id'] ?? '') !== getCurrentTeacherID()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$quizzes[$quizId]['results'] = [];
writeJSON(QUIZZES_FILE, $quizzes);

echo json_encode(['success' => true]);
