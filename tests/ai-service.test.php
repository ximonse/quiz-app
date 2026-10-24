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
rejects(fn()=>aiCall($actor,'quiz_publish',['id'=>$created['id']]),400);
// MCP publication is explicit, owner-scoped, revision-safe and retry-safe.
check(!in_array('quiz_publish',array_column(aiTools(false),'name'),true),'Read-only token advertises publication.');
$target=aiCall($actor,'quiz_create',['quiz'=>$quiz,'request_id'=>'publish-target-01']);
$publishArgs=['id'=>$target['id'],'revision'=>$target['revision'],'approved'=>true,'request_id'=>'publish-request-01'];
rejects(fn()=>aiCall($readonly,'quiz_publish',$publishArgs),403);
rejects(fn()=>aiCall($other,'quiz_publish',$publishArgs),404);
foreach ([false,1,'true',null] as $approval) rejects(fn()=>aiCall($actor,'quiz_publish',array_replace($publishArgs,['approved'=>$approval])),403);
rejects(fn()=>aiCall($actor,'quiz_publish',array_replace($publishArgs,['revision'=>'bad'])),400);
rejects(fn()=>aiCall($actor,'quiz_publish',array_replace($publishArgs,['request_id'=>'bad'])),400);
$beforePublish=aiRead(QUIZZES_FILE)[$target['id']];
rejects(fn()=>aiCall($actor,'quiz_publish',array_replace($publishArgs,['revision'=>str_repeat('0',64)])),409);
check(aiRead(QUIZZES_FILE)[$target['id']]===$beforePublish,'Rejected publication mutated data.');
aiUpdate(QUIZZES_FILE,function (&$quizzes) use ($target) {
    $quizzes[$target['id']]['results']=[['student_name'=>'Private publish result']];
    $quizzes[$target['id']]['custom_field']='keep';
});
$toolPublished=aiCall($actor,'quiz_publish',$publishArgs);
check($toolPublished['publication']==='published' && $toolPublished['student_url']!==null,'MCP publication did not return student link.');
check(!str_contains(json_encode($toolPublished),'Private publish result'),'MCP publication leaked student data.');
$storedPublished=aiRead(QUIZZES_FILE)[$target['id']];
check(count($storedPublished['results'])===1 && $storedPublished['custom_field']==='keep','MCP publication lost unrelated data.');
$publishRetry=aiCall($actor,'quiz_publish',$publishArgs);
check($publishRetry['revision']===$toolPublished['revision'] && aiRead(QUIZZES_FILE)[$target['id']]===$storedPublished,'Publication retry changed data.');
rejects(fn()=>aiCall($actor,'quiz_publish',array_replace($publishArgs,['revision'=>$toolPublished['revision']])),409);
rejects(fn()=>aiCall($actor,'quiz_publish',array_replace($publishArgs,['id'=>$created['id']])),409);
$laterDoc=$toolPublished['quiz']; $laterDoc['title']='Not approved later edit';
$later=aiCall($actor,'quiz_update',['id'=>$target['id'],'revision'=>$toolPublished['revision'],'quiz'=>$laterDoc,'request_id'=>'publish-later-edit']);
$laterStored=aiRead(QUIZZES_FILE)[$target['id']];
check(aiCall($actor,'quiz_publish',$publishArgs)['publication']==='pending' && aiRead(QUIZZES_FILE)[$target['id']]===$laterStored,'Retry published later unapproved changes.');
rejects(fn()=>aiCall($actor,'quiz_publish',array_replace($publishArgs,['request_id'=>'publish-stale-request'])),409);
$laterPublished=aiCall($actor,'quiz_publish',['id'=>$target['id'],'revision'=>$later['revision'],'approved'=>true,'request_id'=>'publish-later-request']);
check($laterPublished['quiz']['title']===$laterDoc['title'] && !isset(aiRead(QUIZZES_FILE)[$target['id']]['pending_document']),'MCP failed to publish pending edits.');
$legacyRead=aiCall($actor,'quiz_get',['id'=>$legacy['id']]);
$legacyToolPublished=aiCall($actor,'quiz_publish',['id'=>$legacy['id'],'revision'=>$legacyRead['revision'],'approved'=>true,'request_id'=>'publish-legacy-request']);
check(str_ends_with($legacyToolPublished['student_url'],'/q/aabbccdd.html') && !isset(aiRead(QUIZZES_FILE)[$legacy['id']]['items']),'MCP publication changed legacy format or URL.');
$secondImage=aiStoreImage($png,'teacher-a');
$pictureQuiz=['title'=>'Bildval','type'=>'image_choice','items'=>[['question'=>'Vilken visar en ramp?',
    'choices'=>[['id'=>'a','image'=>['file'=>$uploaded['image'],'alt'=>'Bild A','crop'=>['x'=>70,'y'=>25,'zoom'=>150]],'correct'=>true],
                ['id'=>'b','image'=>['file'=>$secondImage['image'],'alt'=>'Bild B'],'correct'=>false]]]]];
$picture=aiCall($actor,'quiz_create',['quiz'=>$pictureQuiz,'request_id'=>'image-quiz-0001']);
check($picture['publication']==='draft' && str_contains($picture['markdown'],'Bild 1 — **Rätt**'),'Image quiz creation/Markdown failed.');
check(aiCall($actor,'quiz_get',['id'=>$picture['id']])['quiz']['items'][0]['choices'][0]['image']['crop']===['x'=>70,'y'=>25,'zoom'=>150],
    'Image crop was not preserved.');
$invalidCrop=$pictureQuiz; $invalidCrop['items'][0]['choices'][0]['image']['crop']['zoom']=301;
rejects(fn()=>aiCall($actor,'quiz_preview',['quiz'=>$invalidCrop]),400);

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
$picturePublished=aiCall($actor,'quiz_publish',['id'=>$picture['id'],'revision'=>$picture['revision'],'approved'=>true,'request_id'=>'publish-image-request']);
check($picturePublished['publication']==='published' && aiStudentQuiz(aiRead(QUIZZES_FILE)[$picture['id']])['type']==='image_choice','MCP image quiz publication failed.');

$sortQuiz=['title'=>'Rikena','type'=>'sort','settings'=>['generate_flashcards'=>true],'items'=>[['question'=>'Vilket rike tillhör?','flashcard_prompt'=>'Vilket rike tillhör',
    'fields'=>[['id'=>'svamp','title'=>'Svampar','text'=>'Bryter ner'],['id'=>'vaxt','title'=>'','image'=>['file'=>$secondImage['image'],'alt'=>'Växter']]],
    'objects'=>[['id'=>'champinjon','text'=>'Champinjon','field'=>'svamp'],['id'=>'ek','image'=>['file'=>$uploaded['image'],'alt'=>'Ek'],'field'=>'vaxt'],['id'=>'sten','text'=>'Sten','field'=>null]]]]];
$sort=aiCall($actor,'quiz_create',['quiz'=>$sortQuiz,'request_id'=>'sort-quiz-0001']);
check($sort['publication']==='draft' && str_ends_with($sort['edit_url'],'/admin/sort-edit.php?id='.$sort['id']),'Sort quiz creation/edit URL failed.');
check(str_contains($sort['markdown'],'### Svampar — Bryter ner') && str_contains($sort['markdown'],'- Champinjon') && str_contains($sort['markdown'],"Hör inte till något fält\n\n- Sten"),'Sort Markdown failed.');
$sortRead=aiCall($actor,'quiz_get',['id'=>$sort['id']]);
check($sortRead['quiz']['items'][0]['objects'][2]['field']===null && $sortRead['quiz']['items'][0]['fields'][1]['image']['alt']==='Växter' && $sortRead['quiz']['settings']['generate_flashcards']===true,'Sort document was not preserved.');
$edited=$sortRead['quiz']; $edited['items'][0]['objects'][0]['text']='Kantarell';
$sortUpdated=aiCall($actor,'quiz_update',['id'=>$sort['id'],'revision'=>$sortRead['revision'],'quiz'=>$edited,'request_id'=>'sort-update-0001']);
check($sortUpdated['quiz']['items'][0]['objects'][0]['id']==='champinjon' && $sortUpdated['quiz']['items'][0]['objects'][0]['text']==='Kantarell','Sort update lost stable IDs.');
foreach ([
    fn(&$q)=>$q['items'][0]['objects'][0]['field']='djur',
    fn(&$q)=>$q['items'][0]['objects'][1]['id']='champinjon',
    fn(&$q)=>$q['items'][0]['objects'][0]['id']='svamp',
    fn(&$q)=>$q['items'][0]['fields']=array_fill(0,6,$q['items'][0]['fields'][0]),
    fn(&$q)=>$q['items'][0]['objects']=array_slice($q['items'][0]['objects'],0,1),
    fn(&$q)=>$q['items'][0]['objects'][0]['text']='',
    fn(&$q)=>$q['items'][0]['fields'][0]['title']='',
    fn(&$q)=>$q['items'][0]['objects'][0]['correct']=true,
] as $break) {
    $invalid=$sortQuiz; $break($invalid);
    check($invalid!==$sortQuiz,"Broken sort fixture did not change.");
    rejects(fn()=>aiCall($actor,'quiz_preview',['quiz'=>$invalid]),400);
}
$invalid=$sortQuiz; foreach ($invalid['items'][0]['objects'] as &$o) $o['field']=null; unset($o);
rejects(fn()=>aiCall($actor,'quiz_preview',['quiz'=>$invalid]),400);
$invalid=$sortQuiz; $invalid['items'][0]['objects']=array_map(fn($n)=>['id'=>"o$n",'text'=>"O$n",'field'=>'svamp'],range(1,13));
rejects(fn()=>aiCall($actor,'quiz_preview',['quiz'=>$invalid]),400);
$invalid=$sortQuiz; $invalid['settings']['reverse_enabled']=true;
rejects(fn()=>aiCall($actor,'quiz_preview',['quiz'=>$invalid]),400);
rejects(fn()=>aiCall($other,'quiz_preview',['quiz'=>$sortQuiz]),400);
$sortPublished=aiCall($actor,'quiz_publish',['id'=>$sort['id'],'revision'=>$sortUpdated['revision'],'approved'=>true,'request_id'=>'publish-sort-request']);
check($sortPublished['publication']==='published' && str_ends_with($sortPublished['student_url'],'/play/index.php?id='.$sort['id']) && aiStudentQuiz(aiRead(QUIZZES_FILE)[$sort['id']])['type']==='sort','MCP sort quiz publication failed.');
check(quizTypeRoute('sort')['player']==='sort-quiz.php' && quizTypeRoute('fact')['editor']==='edit.php' && quizTypeRoute(null)['label']==='faktaquiz','Type routes failed.');

echo "PASS: $passed service checks, including publication, draft isolation, ownership, retries, conflicts, image choices, sort quizzes and legacy edits.\n";
