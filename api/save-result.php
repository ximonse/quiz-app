<?php
// api/save-result.php — Save student quiz results
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/browser-ids.php';
require_once __DIR__.'/../includes/classroom.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (is_array($input)) classroomResultIdentity($input);
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
require_once __DIR__.'/../ai/quiz-service.php';
try { $quiz=aiStudentQuiz($quizzes[$quizId]); } catch (QuizAiError $e) { aiJson(['error'=>$e->getMessage()],$e->status); exit; }
if (!empty($quiz['settings']['time_lock']['closes'])) {
    if (date('Y-m-d H:i') > $quiz['settings']['time_lock']['closes']) {
        echo json_encode(['saved' => false, 'reason' => 'training_only']);
        exit;
    }
}

$result = [
    'student_id' => classroomStudent()['id'] ?? null,
    'student_name' => classroomStudent()['name'] ?? substr(trim($input['student_name']), 0, 50),
    'browser_id' => browserIdFromInput($input['browser_id'] ?? null),
    'timestamp' => date('Y-m-d H:i:s'),
    'score' => intval($input['score']),
    'total' => intval($input['total']),
    'errors' => $input['errors'] ?? []
];

if (($quiz['type'] ?? '') === 'image_choice' && isset($input['choice_counts']) && is_array($input['choice_counts'])) {
    $counts = $input['choice_counts'];
    if (count(array_filter(['right', 'wrong', 'missed'], fn($key) =>
        isset($counts[$key]) && is_int($counts[$key]) && $counts[$key] >= 0)) === 3) {
        $result['choice_counts'] = array_intersect_key($counts, array_flip(['right', 'wrong', 'missed']));
    }
}

updateJSONLocked(QUIZZES_FILE, function (&$lockedQuizzes) use ($quizId, $result) {
    if (!isset($lockedQuizzes[$quizId])) throw new RuntimeException('Quiz not found');
    $lockedQuizzes[$quizId]['results'][] = $result;
});

echo json_encode(['saved' => true]);
