<?php require __DIR__.'/config.php';
writeJSON(TEACHERS_FILE,['t1'=>['username'=>'fixture','name'=>'T','active'=>true,'password_hash'=>password_hash('pw',PASSWORD_DEFAULT)]]);
$o=fn($id,$text,$field)=>['id'=>$id,'text'=>$text,'image'=>null,'field'=>$field];
writeJSON(QUIZZES_FILE,['q_sort'=>['id'=>'q_sort','teacher_id'=>'t1','publication'=>'published','title'=>'Rikena','type'=>'sort',
'settings'=>['quiz_mode'=>'training','answer_mode'=>'multiple_choice','required_correct'=>1,'language'=>'sv','generate_flashcards'=>true,'tts_enabled'=>true,'reverse_enabled'=>false],
'items'=>[['question'=>'Vilket rike tillhör?','flashcard_prompt'=>'Vilket rike tillhör','image'=>null,
'fields'=>[['id'=>'svamp','title'=>'Svampar','text'=>'Bryter ner','image'=>null],['id'=>'vaxt','title'=>'Växter','text'=>'','image'=>null],['id'=>'djur','title'=>'Djur','text'=>'','image'=>null]],
'objects'=>[$o('champinjon','Champinjon','svamp'),$o('ek','Ek','vaxt'),$o('rav','Räv','djur'),$o('myra','Myra','djur'),$o('maskros','Maskros','vaxt'),$o('sten','Sten',null)]]],'results'=>[]]]);
writeJSON(STATS_FILE,[]);
$quizzes=readJSON(QUIZZES_FILE);
$p=fn($id,$text)=>['id'=>$id,'text'=>$text,'image'=>null];
$quizzes['q_order']=['id'=>'q_order','teacher_id'=>'t1','publication'=>'published','title'=>'Ordning','type'=>'order',
'settings'=>['quiz_mode'=>'training','answer_mode'=>'multiple_choice','required_correct'=>1,'language'=>'sv','generate_flashcards'=>false,'tts_enabled'=>true,'reverse_enabled'=>false],
'items'=>[['question'=>'Från först till sist','image'=>null,'orientation'=>'horizontal','labels'=>['start'=>'Först','middle'=>'Mitten','end'=>'Sist'],
'objects'=>[$p('a','Bakterier'),$p('b','Fiskar'),$p('c','Dinosaurier'),$p('d','Människor')]],
['question'=>'Jordlager uppifrån','image'=>null,'orientation'=>'vertical','labels'=>['start'=>'Ytan','middle'=>'','end'=>'Djupast'],
'objects'=>[$p('m','Matjord'),$p('s','Sand'),$p('g','Grus')]]],'results'=>[]];
writeJSON(QUIZZES_FILE,$quizzes);
$quizzes=readJSON(QUIZZES_FILE);
$quizzes['q_fact']=['id'=>'q_fact','teacher_id'=>'t1','publication'=>'published','title'=>'Faktaquiz','type'=>'fact',
'settings'=>['quiz_mode'=>'training','answer_mode'=>'multiple_choice','required_correct'=>1,'language'=>'sv','generate_flashcards'=>true,'tts_enabled'=>true,'reverse_enabled'=>false,'question_direction'=>'concept','concept_language'=>'sv','spelling_mode'=>'student_choice','time_lock'=>null,'reverse_answer_mode'=>'multiple_choice','reverse_required_correct'=>1,'flashcard_mix_directions'=>false],
'items'=>[['concept'=>'Fotosyntes','description'=>'Växter gör socker av ljus','wrong_options'=>['Djur andas','Svampar bryter ner','Sten vittrar'],'reverse_wrong_options'=>[],'image'=>null]],'results'=>[]];
writeJSON(QUIZZES_FILE,$quizzes);
$quizzes=readJSON(QUIZZES_FILE);
$img=fn($alt)=>['file'=>'ai_'.str_repeat('a',32).'.png','alt'=>$alt,'crop'=>['x'=>50,'y'=>50,'zoom'=>100]];
$quizzes['q_img']=['id'=>'q_img','teacher_id'=>'t1','publication'=>'published','title'=>'Bildquiz','type'=>'image_choice',
'settings'=>['quiz_mode'=>'training','required_correct'=>1,'language'=>'sv','tts_enabled'=>true],
'items'=>[['question'=>'Vilken är en katt?','image'=>null,'choices'=>[['id'=>'c1','image'=>$img('Katt'),'correct'=>true],['id'=>'c2','image'=>$img('Hund'),'correct'=>false]]]],'results'=>[]];
writeJSON(QUIZZES_FILE,$quizzes);
