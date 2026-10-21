<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom.php';
header('Cache-Control: no-store');
$student=classroomStudent();
if (!$student) { header('Location: ./'); exit; }
$data=classroomRead(); $id=(string)($_GET['assignment'] ?? ''); $a=$data['assignments'][$id] ?? null;
if (!$a || !classroomAllowed($data,$student,$a) || !classroomContent($a)) { http_response_code(403); exit('Uppgiften är inte tillgänglig.'); }
if ($a['kind']==='material') {
    $file=DATA_DIR.'materials/'.basename($a['file']);
    if (!is_file($file)) { http_response_code(404); exit('Materialet saknas. Kontakta din lärare.'); }
    classroomUpdate(function (&$latest) use ($student,$a) {
        if (!classroomStudent($latest) || !classroomAllowed($latest,$student,$a)) throw new DomainException('Logga in igen.');
        $key='download:'.$a['id'].':'.$student['id'];
        $latest['attempts'][$key]=['assignment_id'=>$a['id'],'student_id'=>$student['id'],'teacher_id'=>$student['teacher_id'],'completed'=>true,'updated'=>gmdate('c'),'started'=>gmdate('c'),'right'=>0,'wrong'=>0,'cards'=>0];
    });
    header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="material.pdf"'); header('X-Content-Type-Options: nosniff');
    header('Content-Length: '.filesize($file)); readfile($file); exit;
}
$content=classroomContent($a);
if ($a['kind']==='deck') $path='../q/flashcards.php?deck_id='.urlencode($a['content_id']);
elseif ($a['kind']==='cards') $path='../play/flashcards.php?id='.urlencode($a['content_id']);
elseif (isset($content['items'])) $path='../play/'.(($content['type'] ?? '')==='image_choice'?'image-quiz.php':'quiz.php').'?id='.urlencode($a['content_id']);
else $path='../q/'.$a['content_id'].'.html?';
$path.=(str_ends_with($path,'?')?'':'&').'assignment='.urlencode($id).'&name='.urlencode($student['name']);
header('Location: '.$path);
