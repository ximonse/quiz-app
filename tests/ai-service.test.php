<?php
// Entirely synthetic temp storage: never load the real config.local.php or data.
$root=dirname(__DIR__); $fixture=sys_get_temp_dir().'/quiz-ai-test-'.bin2hex(random_bytes(8));
mkdir($fixture.'/data',0755,true); mkdir($fixture.'/ai',0755,true);
copy($root.'/config.php',$fixture.'/config.php');
foreach (glob($root.'/ai/*.php') as $file) copy($file,$fixture.'/ai/'.basename($file));
register_shutdown_function(function () use ($fixture) {
    $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($fixture);
});
require $fixture.'/config.php'; require $fixture.'/ai/tools.php';
$passed=0;
function check($condition,string $message): void { global $passed; if (!$condition) throw new RuntimeException($message); $passed++; }
function rejects(callable $operation,int $status): void {
    try { $operation(); } catch (QuizAiError $e) { check($e->status===$status,'Wrong rejection status: '.$e->getMessage()); return; }
    throw new RuntimeException('Expected rejection.');
}
$actor=['teacher_id'=>'teacher-a','name'=>'Test','write'=>true];
$readonly=array_replace($actor,['write'=>false]); $other=array_replace($actor,['teacher_id'=>'teacher-b']);
writeJSON(TEACHERS_FILE,['teacher-a'=>['active'=>true,'name'=>'Test'],'teacher-b'=>['active'=>true,'name'=>'Other']]);
writeJSON(QUIZZES_FILE,[]);
$quiz=['title'=>'Ramp och hävstång','type'=>'fact','items'=>[
    ['concept'=>'Vilken maskin är en ramp?','description'=>'Lutande plan','wrong_options'=>['Hjul','Skruv','Hävstång']],
    ['concept'=>'Vad använder en gungbräda?','description'=>'Hävstång','wrong_options'=>['Hjul','Skruv','Lutande plan']]
]];
$args=['quiz'=>$quiz,'approved'=>true,'request_id'=>'create-test-0001'];
check(!in_array('approved',aiTools()[3]['inputSchema']['required'],true),'AI creation still requires Markdown approval.');
rejects(fn()=>aiCall($readonly,'quiz_create',$args),403);
$preview=aiCall($actor,'quiz_preview',['quiz'=>$quiz]);
check(!$preview['saved'] && count(aiRead(QUIZZES_FILE))===0,'Preview saved data.');
$created=aiCall($actor,'quiz_create',$args);
check($created['student_url']===null && $created['publication']==='draft' && str_contains($created['review_url'],'/admin/review.php?id=q_'),'New quiz was published instead of saved for review.');
rejects(fn()=>aiStudentQuiz(aiRead(QUIZZES_FILE)[$created['id']]),403);
check(str_contains($created['markdown'],'Rätt svar: Lutande plan'),'Missing answer in Markdown.');
$repeat=aiCall($actor,'quiz_create',$args);
check($created['id']===$repeat['id'] && count(aiRead(QUIZZES_FILE))===1,'Retry duplicated quiz.');
$changed=$quiz; $changed['title']='Changed';
rejects(fn()=>aiCall($actor,'quiz_create',array_replace($args,['quiz'=>$changed])),409);
check(aiCall($other,'quiz_search',[])['total_count']===0,'Cross-account search leak.');
rejects(fn()=>aiCall($other,'quiz_get',['id'=>$created['id']]),404);
aiUpdate(QUIZZES_FILE,function (&$quizzes) use ($created) { $quizzes[$created['id']]['results'][]=['student_name'=>'Synthetic student']; $quizzes[$created['id']]['extra']='preserve'; });
$fetched=aiCall($actor,'quiz_get',['id'=>$created['id']]);
check(!str_contains(json_encode($fetched),'Synthetic student') && !isset($fetched['quiz']['results']),'Results leaked.');
check($fetched['revision']===$created['revision'],'Student results changed content revision.');
$edit=$fetched['quiz']; $edit['title']='Godkänt nytt namn';
$update=['id'=>$created['id'],'revision'=>$fetched['revision'],'quiz'=>$edit,'approved'=>true,'request_id'=>'update-test-0001'];
$saved=aiCall($actor,'quiz_update',$update);
check(aiRead(QUIZZES_FILE)[$created['id']]['extra']==='preserve' && count(aiRead(QUIZZES_FILE)[$created['id']]['results'])===1,'Update lost data.');
check(aiCall($actor,'quiz_update',$update)['id']===$created['id'],'Update retry failed.');
$update['request_id']='update-test-0002'; rejects(fn()=>aiCall($actor,'quiz_update',$update),409);
rejects(fn()=>aiCall($other,'quiz_update',array_replace($update,['revision'=>$saved['revision']])),404);
$bad=$quiz; $bad['items'][0]['wrong_options'][0]='Lutande plan'; rejects(fn()=>aiCall($actor,'quiz_preview',['quiz'=>$bad]),400);
$bad=$quiz; $bad['teacher_id']='teacher-b'; rejects(fn()=>aiCall($actor,'quiz_preview',['quiz'=>$bad]),400);
$bad=$quiz; $bad['items'][0]['image']=['file'=>'../../config.local.php','alt'=>'test']; rejects(fn()=>aiCall($actor,'quiz_preview',['quiz'=>$bad]),400);
// 1x1 valid PNG tests raster handling without requiring GD.
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD6sAAAAASUVORK5CYII=');
$uploaded=aiCall($actor,'quiz_upload_image',['base64'=>base64_encode($png)]);
check(aiImageName($uploaded['image']) && is_file(IMAGE_DIR.$uploaded['image']),'Image was not stored.');
$illustrated=$quiz; $illustrated['items'][0]['image']=['file'=>$uploaded['image'],'alt'=>'En ramp'];
$imagePreview=aiCall($actor,'quiz_preview',['quiz'=>$illustrated]);
check(str_contains($imagePreview['markdown'],'![En ramp]('),'Image missing from draft.');
rejects(fn()=>aiCall($other,'quiz_preview',['quiz'=>$illustrated]),400);
rejects(fn()=>aiCall($actor,'quiz_upload_image',['base64'=>base64_encode('<?php echo 1; ?>')]),400);
rejects(fn()=>aiCall($actor,'quiz_upload_image',['base64'=>'!invalid']),400);
rejects(fn()=>aiCall($readonly,'quiz_upload_image',['base64'=>base64_encode($png)]),403);
// Legacy quizzes keep old URLs and settings; API edits do not migrate them.
$legacy=['id'=>'aabbccdd','title'=>'Gammalt quiz','type'=>'fact','teacher_id'=>'teacher-a','answer_mode'=>'hybrid',
    'questions'=>[['question'=>'Vad är en ramp?','answer'=>'Lutande plan','options'=>['Lutande plan','Hjul','Skruv','Hävstång']]], 'legacy_extra'=>true];
aiUpdate(QUIZZES_FILE,function (&$quizzes) use ($legacy) { $quizzes[$legacy['id']]=$legacy; });
$old=aiCall($actor,'quiz_get',['id'=>$legacy['id']]); $old['quiz']['title']='Ändrat gammalt quiz';
$oldSaved=aiCall($actor,'quiz_update',['id'=>$legacy['id'],'revision'=>$old['revision'],'quiz'=>$old['quiz'],'approved'=>true,'request_id'=>'legacy-update-01']);
check($oldSaved['publication']==='pending' && aiRead(QUIZZES_FILE)[$legacy['id']]['title']==='Gammalt quiz','Legacy edits changed the published version.');
$oldPublished=aiPublishQuiz($legacy['id'],'teacher-a',$oldSaved['revision']);
check(str_ends_with($oldPublished['student_url'],'/q/aabbccdd.html'),'Legacy link changed.');
check(aiRead(QUIZZES_FILE)[$legacy['id']]['answer_mode']==='hybrid' && !isset(aiRead(QUIZZES_FILE)[$legacy['id']]['items']),'Legacy storage changed.');
$_SERVER['REMOTE_ADDR']='127.0.0.1';
$issued=aiIssueToken('teacher-a','Test key',true); $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$issued['secret'];
check(aiAuthenticate()['teacher_id']==='teacher-a','Authentication failed.');
check(!str_contains(file_get_contents(aiTokenFile()),$issued['secret']),'Plaintext token stored.');
$_SERVER['HTTP_ORIGIN']='https://evil.example'; rejects(fn()=>aiAuthenticate(),403); unset($_SERVER['HTTP_ORIGIN']);
aiUpdate(aiTokenFile(),function (&$tokens) use ($issued) { $tokens[$issued['id']]['revoked']=true; });
rejects(fn()=>aiAuthenticate(),401);
$_SERVER['HTTP_AUTHORIZATION']='Bearer unknown'; rejects(fn()=>aiAuthenticate(),401);
$before=file_get_contents(QUIZZES_FILE); file_put_contents(QUIZZES_FILE,'{broken');
rejects(fn()=>aiCall($actor,'quiz_create',array_replace($args,['request_id'=>'corrupt-data-test'])),503);
check(file_get_contents(QUIZZES_FILE)==='{broken','Corrupt storage overwritten.');
file_put_contents(QUIZZES_FILE,$before);
check(aiCall($actor,'quiz_search',['query'=>'Godkänt','limit'=>1])['total_count']===1,'Search filter failed.');

$draft=aiCall($actor,'quiz_get',['id'=>$created['id']]);
rejects(fn()=>aiPublishQuiz($created['id'],'teacher-b',$draft['revision']),404);
$published=aiPublishQuiz($created['id'],'teacher-a',$draft['revision']);
check($published['publication']==='published' && $published['student_url']!==null,'Publication failed.');
$edit=$published['quiz']; $edit['title']='Nya frågor under granskning';
$pending=aiCall($actor,'quiz_update',['id'=>$created['id'],'revision'=>$published['revision'],'quiz'=>$edit,'request_id'=>'pending-edit-001']);
check($pending['publication']==='pending' && $pending['student_url']===null,'Published edit was not staged.');
check(aiStudentQuiz(aiRead(QUIZZES_FILE)[$created['id']])['title']==='Godkänt nytt namn','Student saw unreviewed changes.');
check(aiCall($actor,'quiz_get',['id'=>$created['id']])['quiz']['title']==='Nya frågor under granskning','Teacher cannot read pending changes.');
rejects(fn()=>aiPublishQuiz($created['id'],'teacher-a',$published['revision']),409);
$republished=aiPublishQuiz($created['id'],'teacher-a',$pending['revision']);
check(aiStudentQuiz(aiRead(QUIZZES_FILE)[$created['id']])['title']==='Nya frågor under granskning','Published edits were not visible.');
check(count(aiRead(QUIZZES_FILE)[$created['id']]['results'])===1,'Publishing lost student results.');
rejects(fn()=>aiCall($actor,'quiz_publish',['id'=>$created['id']]),404);
$secondImage=aiStoreImage($png,'teacher-a');
$pictureQuiz=['title'=>'Bildval','type'=>'image_choice','items'=>[['question'=>'Vilken visar en ramp?',
    'choices'=>[['id'=>'a','image'=>['file'=>$uploaded['image'],'alt'=>'Bild A'],'correct'=>true],
                ['id'=>'b','image'=>['file'=>$secondImage['image'],'alt'=>'Bild B'],'correct'=>false]]]]];
$picture=aiCall($actor,'quiz_create',['quiz'=>$pictureQuiz,'request_id'=>'image-quiz-0001']);
check($picture['publication']==='draft' && str_contains($picture['markdown'],'Bild 1 — **Rätt**'),'Image quiz creation/Markdown failed.');
rejects(fn()=>aiStudentQuiz(aiRead(QUIZZES_FILE)[$picture['id']],true),403);
$_SESSION['teacher_id']='teacher-a';
check(aiStudentQuiz(aiRead(QUIZZES_FILE)[$picture['id']],true)['type']==='image_choice','Owner cannot preview draft.');
unset($_SESSION['teacher_id']);
$invalid=$pictureQuiz; $invalid['items'][0]['choices'][0]['correct']=false;
rejects(fn()=>aiCall($actor,'quiz_preview',['quiz'=>$invalid]),400);
$invalid=$pictureQuiz; $invalid['items'][0]['choices']=array_fill(0,7,$invalid['items'][0]['choices'][0]);
rejects(fn()=>aiCall($actor,'quiz_preview',['quiz'=>$invalid]),400);
$invalid=$pictureQuiz; $invalid['items'][0]['choices'][1]['id']='a';
rejects(fn()=>aiCall($actor,'quiz_preview',['quiz'=>$invalid]),400);
rejects(fn()=>aiCall($other,'quiz_preview',['quiz'=>$pictureQuiz]),400);

echo "PASS: $passed service checks, including publication, draft isolation, ownership, retries, conflicts, image choices and legacy edits.\n";
