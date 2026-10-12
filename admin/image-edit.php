<?php
require_once __DIR__.'/../config.php'; require_once __DIR__.'/../ai/quiz-service.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }
$id=$_GET['id']??''; $error=''; $teacherId=getCurrentTeacherID();
$actor=['teacher_id'=>$teacherId,'write'=>true];
$doc=['title'=>'','type'=>'image_choice','settings'=>['tts_enabled'=>true,'quiz_mode'=>'training','required_correct'=>1,'language'=>'sv'],
    'items'=>[['question'=>'','choices'=>[['id'=>'picture_1','image'=>null,'correct'=>true],['id'=>'picture_2','image'=>null,'correct'=>false]],'image'=>null]]];
$revision='';
try {
    if ($id) { $stored=aiOwn(aiRead(QUIZZES_FILE),$id,$teacherId); $doc=aiDocument(aiEffectiveQuiz($stored)); $revision=aiRevision($stored);
        if ($doc['type']!=='image_choice') throw new QuizAiError('Textquiz redigeras i den vanliga redigeraren.'); }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        requireValidCsrf();
        try { $items=json_decode($_POST['items_json']??'',true,128,JSON_THROW_ON_ERROR); } catch (Throwable $e) { throw new QuizAiError('Kunde inte läsa frågorna.'); }
        if (!is_array($items) || count($items)>200) throw new QuizAiError('Använd högst 200 frågor.');
        foreach ($items as $i=>&$item) {
            if (!is_array($item) || !is_array($item['choices']??null)) throw new QuizAiError('Ogiltiga bildalternativ.');
            foreach ($item['choices'] as $j=>&$choice) {
                $upload=$_FILES['choice_files']??null;
                $errorCode=$upload['error'][$i][$j]??UPLOAD_ERR_NO_FILE;
                if ($errorCode===UPLOAD_ERR_NO_FILE) continue;
                $temporary=$upload['tmp_name'][$i][$j]??'';
                if ($errorCode!==UPLOAD_ERR_OK || !is_uploaded_file($temporary)) throw new QuizAiError('Kunde inte ladda upp bilden (högst 5 MB).');
                $image=aiStoreImage(file_get_contents($temporary),$teacherId);
                $choice['image']['file']=$image['image'];
            }
            unset($choice);
        }
        unset($item);
        $doc=array_replace($doc,['title'=>$_POST['title']??'', 'items'=>$items]);
        $doc['settings']=array_replace($doc['settings'],['tts_enabled'=>isset($_POST['tts_enabled']), 'quiz_mode'=>$_POST['quiz_mode']??'training',
            'required_correct'=>(int)($_POST['required_correct']??1),'language'=>$_POST['language']??'sv']);
        $args=['quiz'=>$doc,'request_id'=>$_POST['request_id']??''];
        if ($id) { $args['id']=$id; $args['revision']=$_POST['revision']??''; }
        $saved=aiSave($actor,$args,!$id);
        header('Location: image-edit.php?id='.urlencode($saved['id']).'&saved=1'); exit;
    }
} catch (QuizAiError $e) { $error=$e->getMessage(); }
function editorEscape($v) { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Redigera bildvalsquiz</title><link rel="stylesheet" href="../engine/image-choice.css"></head>
<body><main><a href="dashboard.php">← Mina quiz</a><header><p class="eyebrow">Bildvalsquiz</p><h1><?= $id?'Redigera bildfrågorna':'Skapa bildvalsquiz' ?></h1><p>Skriv frågan, lägg till 2–6 bilder och markera alla rätta bilder. En rätt bild ger envalsfråga; flera rätta bilder ger flervalsfråga.</p>
<?php if ($id): ?><nav><a class="button secondary" href="review.php?id=<?= urlencode($id) ?>">Granska och publicera</a><a class="button secondary" href="images.php?id=<?= urlencode($id) ?>">Lägg till infobild</a></nav><?php endif; ?></header>
<?php if ($error): ?><p class="error" role="alert"><?= editorEscape($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p class="success" role="status">Sparat för granskning. Publicera när du är nöjd.</p><?php endif; ?>
<form method="post" enctype="multipart/form-data" id="image-editor"><?= csrfField() ?><input type="hidden" name="revision" value="<?= $revision ?>"><input type="hidden" name="request_id" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="items_json" id="items-json">
<label for="title">Titel</label><input id="title" name="title" type="text" required maxlength="300" value="<?= editorEscape($doc['title']) ?>">
<div id="editor-questions"></div><button type="button" id="add-question" class="secondary">+ Lägg till fråga</button>
<section class="question-card"><h2>Inställningar</h2><label><input type="checkbox" name="tts_enabled" <?= $doc['settings']['tts_enabled']?'checked':'' ?>> Läs upp frågan</label>
<label for="quiz-mode">Quizläge</label><select id="quiz-mode" name="quiz_mode"><option value="training" <?= $doc['settings']['quiz_mode']==='training'?'selected':'' ?>>Träning med repetition</option><option value="test" <?= $doc['settings']['quiz_mode']==='test'?'selected':'' ?>>Test — en genomgång</option></select>
<label for="required">Rätt per fråga i träning</label><input id="required" name="required_correct" type="number" min="1" max="10" value="<?= (int)$doc['settings']['required_correct'] ?>">
<label for="language">Språk för uppläsning</label><select id="language" name="language"><?php foreach (['sv'=>'Svenska','en'=>'Engelska','es'=>'Spanska','de'=>'Tyska','fr'=>'Franska','uk'=>'Ukrainska'] as $code=>$label): ?><option value="<?= $code ?>" <?= $doc['settings']['language']===$code?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></section>
<footer class="publish-bar"><button>Spara för granskning</button></footer></form></main>
<script>const INITIAL_IMAGE_ITEMS=<?= json_encode($doc['items'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script><script src="../engine/image-choice-editor.js"></script></body></html>
