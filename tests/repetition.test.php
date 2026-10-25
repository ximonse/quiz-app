<?php
// Entirely synthetic temp storage: never load the real config.local.php or data.
$root=dirname(__DIR__); $fixture=sys_get_temp_dir().'/quiz-repetition-test-'.bin2hex(random_bytes(8));
mkdir($fixture.'/data',0755,true);
copy($root.'/config.php',$fixture.'/config.php');
copy($root.'/includes/repetition.php',$fixture.'/repetition.php');
register_shutdown_function(function () use ($fixture) {
    $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($fixture);
});
require $fixture.'/config.php'; require $fixture.'/repetition.php';
$passed=0;
function check($condition,string $message): void { global $passed; if (!$condition) throw new RuntimeException($message); $passed++; }
function backdated(array $card,int $days): array { $card['last_review']=(new DateTimeImmutable("-$days days"))->format('Y-m-d'); return $card; }

// A new card: a better rating must grow stability (and so the next interval) more.
$again=fsrsReview(['reps'=>0,'lapses'=>0],1);
$hard=fsrsReview(['reps'=>0,'lapses'=>0],2);
$good=fsrsReview(['reps'=>0,'lapses'=>0],3);
check($again['stability']<$hard['stability'] && $hard['stability']<$good['stability'],'Initial stability must grow Again < Hard < Good.');
check($again['due']<=$hard['due'] && $hard['due']<=$good['due'],'Due date must not come later for a worse rating.');

// From the same starting point, a lapse (Again) must shrink stability; a Good review must grow it.
$base=backdated($good,5);
$afterAgain=fsrsReview($base,1);
$afterGood=fsrsReview($base,3);
check($afterAgain['stability']<(float)$base['stability'],'A lapse (Again) must shrink stability, never grow it.');
check($afterGood['stability']>(float)$base['stability'],'A Good review must grow stability.');
check($afterAgain['lapses']===1 && $afterGood['lapses']===0,'Lapses must only increment on Again.');
check($afterAgain['due']<$afterGood['due'],'A lapse must schedule a sooner review than a Good answer.');

// Repeated lapses must not schedule further out than a single lapse did.
$twice=fsrsReview(backdated($afterAgain,1),1);
check($twice['due']<=$afterAgain['due'],'Repeated lapses must not schedule further out than a single lapse.');

// Wrong-attempt counts map to FSRS ratings as the plan specifies: 0=Good, 1=Hard, 2+=Again.
check(repetitionRatingFromWrongAttempts(0)===3,'0 wrong attempts must map to Good.');
check(repetitionRatingFromWrongAttempts(1)===2,'1 wrong attempt must map to Hard.');
check(repetitionRatingFromWrongAttempts(2)===1,'2 wrong attempts must map to Again.');
check(repetitionRatingFromWrongAttempts(7)===1,'Many wrong attempts must still map to Again.');

// The due queue: only this student's overdue cards, weakest/oldest first, capped at the limit.
writeJSON(REPETITION_FILE,['cards'=>[
    's1:qa:0'=>['student_id'=>'s1','quiz_id'=>'qa','question_index'=>0,'stability'=>1,'difficulty'=>5,'reps'=>1,'lapses'=>0,'last_review'=>'2020-01-01','due'=>'2020-01-01'],
    's1:qa:1'=>['student_id'=>'s1','quiz_id'=>'qa','question_index'=>1,'stability'=>5,'difficulty'=>5,'reps'=>1,'lapses'=>0,'last_review'=>'2020-01-01','due'=>'2020-01-02'],
    's1:qb:0'=>['student_id'=>'s1','quiz_id'=>'qb','question_index'=>0,'stability'=>2,'difficulty'=>5,'reps'=>1,'lapses'=>0,'last_review'=>'2099-01-01','due'=>'2099-01-01'],
    's2:qa:0'=>['student_id'=>'s2','quiz_id'=>'qa','question_index'=>0,'stability'=>1,'difficulty'=>5,'reps'=>1,'lapses'=>0,'last_review'=>'2020-01-01','due'=>'2020-01-01'],
]]);
$due=repetitionDueQueue('s1',10);
check(count($due)===2,'Only the two overdue cards for student s1 must be due (not the future one, not the other student).');
check($due[0]['question_index']===0,'The earliest-due overdue card must come first.');
$capped=repetitionDueQueue('s1',1);
check(count($capped)===1,'The limit parameter must cap the queue.');

// Recording answers: first answer creates a card; a later, worse answer adds a rep and a lapse.
@unlink(REPETITION_FILE);
repetitionRecordAnswers('s3','qc',[['q'=>'0','w'=>0]]);
$card=repetitionRead()['cards']['s3:qc:0'];
check($card['reps']===1 && $card['lapses']===0,'First answer must create a card with one rep and no lapse.');
repetitionRecordAnswers('s3','qc',[['q'=>'0','w'=>2]]);
$card2=repetitionRead()['cards']['s3:qc:0'];
check($card2['reps']===2 && $card2['lapses']===1,'A second, wrong-heavy answer must add a rep and a lapse.');

echo "PASS: $passed repetition/FSRS checks.\n";
