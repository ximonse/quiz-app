<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit(json_encode(['error'=>'POST required'])); }
$student=classroomStudent();
if (!$student) { http_response_code(401); exit(json_encode(['error'=>'Logga in igen.'])); }
requireValidCsrf(true);
$input=json_decode(file_get_contents('php://input'),true);
try { if (!is_array($input)) throw new DomainException('Ogiltig aktivitet.'); classroomRecord($student,$input); echo json_encode(['saved'=>true]); }
catch (DomainException $e) { http_response_code(403); echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
catch (RuntimeException $e) { http_response_code(503); echo json_encode(['error'=>'Övningen kunde inte sparas. Försök igen.'],JSON_UNESCAPED_UNICODE); }
