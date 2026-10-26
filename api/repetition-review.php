<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom.php';
require_once __DIR__.'/../includes/result-storage.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit(json_encode(['error'=>'POST required'])); }
$student=classroomStudent();
if (!$student) { http_response_code(401); exit(json_encode(['error'=>'Logga in igen.'])); }
requireValidCsrf(true);
$input=json_decode(file_get_contents('php://input'),true);
$quizId=$input['quiz_id'] ?? null; $questionIndex=$input['question_index'] ?? null; $wrong=$input['wrong_attempts'] ?? null;
if (!is_string($quizId) || $quizId==='' || !is_int($questionIndex) || $questionIndex<0 || !is_int($wrong) || $wrong<0 || $wrong>1000) {
    http_response_code(400); exit(json_encode(['error'=>'Ogiltig begäran.']));
}
try {
    // Kortet måste redan finnas för den här eleven — det skapades av classroomRecord()
    // när quizet ursprungligen gjordes. Den här endpointen kan bara uppdatera, inte skapa.
    repetitionUpdate(function (&$data) use ($student,$quizId,$questionIndex,$wrong,$input) {
        $key=repetitionCardKey($student['id'],$quizId,$questionIndex);
        if (!isset($data['cards'][$key])) throw new DomainException('Frågan hittades inte i din repetition.');
        if (!resultRequest($data['cards'][$key], $input)) return;
        $rating=repetitionRatingFromWrongAttempts($wrong);
        $data['cards'][$key]=array_merge($data['cards'][$key],fsrsReview($data['cards'][$key],$rating));
    });
    echo json_encode(['saved'=>true]);
} catch (DomainException $e) { http_response_code(403); echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
catch (RuntimeException $e) { http_response_code(503); echo json_encode(['error'=>'Kunde inte sparas. Försök igen.'],JSON_UNESCAPED_UNICODE); }
