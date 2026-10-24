<?php
// Shared editor page for drag-and-drop quiz types (sort, order).
// $config: type, label, intro, prompt, template, script, uploads (groups of entries with images), flashcards (bool).
require_once __DIR__.'/../config.php'; require_once __DIR__.'/../ai/quiz-service.php';
// Uploaded files arrive as {group}_files[question][index]; attach them to the matching entry.
function dragEditorAttach(array &$entries, string $group, int $i, string $teacherId): void {
    $upload=$_FILES[$group.'_files']??null;
    foreach ($entries as $j=>&$entry) {
        $code=$upload['error'][$i][$j]??UPLOAD_ERR_NO_FILE;
        if ($code===UPLOAD_ERR_NO_FILE || !is_array($entry)) continue;
        $temporary=$upload['tmp_name'][$i][$j]??'';
        if ($code!==UPLOAD_ERR_OK || !is_uploaded_file($temporary)) throw new QuizAiError('Kunde inte ladda upp bilden (högst 5 MB).');
        $entry['image']=['file'=>aiStoreImage(file_get_contents($temporary),$teacherId)['image'],'alt'=>$entry['image']['alt']??''];
    }
}
function dragQuizEditor(array $config): void {
    if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }
    $id=$_GET['id']??''; $error=''; $teacherId=getCurrentTeacherID(); $self=basename($_SERVER['SCRIPT_NAME']);
    $doc=['title'=>'','type'=>$config['type'],'settings'=>['tts_enabled'=>true,'quiz_mode'=>'training','required_correct'=>1,'language'=>'sv','generate_flashcards'=>false],'items'=>[]];
    $revision='';
    try {
        if ($id) { $stored=aiOwn(aiRead(QUIZZES_FILE),$id,$teacherId); $doc=aiDocument(aiEffectiveQuiz($stored)); $revision=aiRevision($stored);
            if ($doc['type']!==$config['type']) throw new QuizAiError('Det här quizet redigeras i en annan redigerare.'); }
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            requireValidCsrf();
            try { $items=json_decode($_POST['items_json']??'',true,128,JSON_THROW_ON_ERROR); } catch (Throwable $e) { throw new QuizAiError('Kunde inte läsa frågorna.'); }
            if (!is_array($items) || !array_is_list($items) || count($items)<1 || count($items)>200) throw new QuizAiError('Använd 1–200 frågor.');
            foreach ($items as $i=>&$item) {
                if (!is_array($item)) throw new QuizAiError('Ogiltig fråga.');
                foreach ($config['uploads'] as $key=>$group) {
                    if (!is_array($item[$key]??null)) throw new QuizAiError('Ogiltig fråga.');
                    dragEditorAttach($item[$key],$group,$i,$teacherId);
                }
            }
            unset($item);
            $doc=array_replace($doc,['title'=>$_POST['title']??'','items'=>$items]);
            $doc['settings']=array_replace($doc['settings'],['tts_enabled'=>isset($_POST['tts_enabled']),'generate_flashcards'=>$config['flashcards'] && isset($_POST['generate_flashcards']),
                'quiz_mode'=>$_POST['quiz_mode']??'training','required_correct'=>(int)($_POST['required_correct']??1),'language'=>$_POST['language']??'sv']);
            $args=['quiz'=>$doc,'request_id'=>$_POST['request_id']??''];
            if ($id) { $args['id']=$id; $args['revision']=$_POST['revision']??''; }
            $saved=aiSave(['teacher_id'=>$teacherId,'write'=>true],$args,!$id);
            header('Location: '.$self.'?id='.urlencode($saved['id']).'&saved=1'); exit;
        }
    } catch (QuizAiError $e) { $error=$e->getMessage(); }
    $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); $s=$doc['settings']; $v=fn($f)=>filemtime(__DIR__.'/../engine/'.$f);
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Redigera <?= $e($config['label']) ?></title><link rel="stylesheet" href="../engine/image-choice.css?v=<?= $v('image-choice.css') ?>"><link rel="stylesheet" href="../engine/sort-editor.css?v=<?= $v('sort-editor.css') ?>"></head>
<body><main><a href="dashboard.php">← Mina quiz</a><header><p class="eyebrow"><?= $e(ucfirst($config['label'])) ?></p><h1><?= $id?'Redigera ':'Skapa ' ?><?= $e($config['label']) ?></h1><p><?= $e($config['intro']) ?></p>
<?php if ($id): ?><nav><a class="button secondary" href="review.php?id=<?= urlencode($id) ?>">Granska och publicera</a><a class="button secondary" href="images.php?id=<?= urlencode($id) ?>">Lägg till infobild</a></nav><?php endif; ?></header>
<?php if ($error): ?><p class="error" role="alert"><?= $e($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p class="success" role="status">Sparat för granskning. Publicera när du är nöjd.</p><?php endif; ?>
<details class="question-card"><summary><strong>Skapa med AI eller klistra in JSON</strong></summary>
<p>Kopiera prompten till valfri AI, skriv vad quizet ska handla om och klistra in svaret här. Bilder lägger du till efteråt i formuläret.</p>
<div class="actions"><button type="button" id="copy-prompt" class="secondary">Kopiera prompt</button><a class="button secondary" href="../assets/templates/<?= $e($config['template']) ?>" download>Ladda ner mall (JSON)</a><span id="copy-status" role="status" aria-live="polite"></span></div>
<pre id="ai-prompt" hidden><?= $e($config['prompt']) ?></pre>
<label for="import-json">AI-svar eller JSON</label><textarea id="import-json" rows="6" placeholder='{"title": "...", "type": "<?= $e($config['type']) ?>", "items": [...]}'></textarea>
<div class="actions"><button type="button" id="import-replace" class="secondary">Ersätt frågorna</button><button type="button" id="import-append" class="secondary">Lägg till frågorna</button></div><p id="import-status" class="small" role="status" aria-live="polite"></p></details>
<form method="post" enctype="multipart/form-data" id="quiz-editor"><?= csrfField() ?><input type="hidden" name="revision" value="<?= $revision ?>"><input type="hidden" name="request_id" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="items_json" id="items-json">
<label for="title">Titel</label><input id="title" name="title" type="text" required maxlength="300" value="<?= $e($doc['title']) ?>">
<section class="question-card"><h2>Inställningar</h2><label><input type="checkbox" name="tts_enabled" id="tts-enabled" <?= $s['tts_enabled']?'checked':'' ?>> Uppläsning (eleven håller fingret stilla på en text)</label>
<?php if ($config['flashcards']): ?><label><input type="checkbox" name="generate_flashcards" id="generate-flashcards" <?= $s['generate_flashcards']?'checked':'' ?>> Skapa flashcards</label><?php endif; ?>
<label for="quiz-mode">Quizläge</label><select id="quiz-mode" name="quiz_mode"><option value="training" <?= $s['quiz_mode']==='training'?'selected':'' ?>>Träning med repetition</option><option value="test" <?= $s['quiz_mode']==='test'?'selected':'' ?>>Test — en genomgång</option></select>
<label for="required"><?= $e($config['required_label']) ?></label><input id="required" name="required_correct" type="number" min="1" max="10" value="<?= (int)$s['required_correct'] ?>">
<label for="language">Språk för uppläsning</label><select id="language" name="language"><?php foreach (['sv'=>'Svenska','en'=>'Engelska','es'=>'Spanska','de'=>'Tyska','fr'=>'Franska','fi'=>'Finska','uk'=>'Ukrainska'] as $code=>$label): ?><option value="<?= $code ?>" <?= $s['language']===$code?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></section>
<div id="editor-questions"></div><button type="button" id="add-question" class="secondary">+ Lägg till fråga</button>
<footer class="publish-bar"><button>Spara för granskning</button></footer></form></main>
<script>const INITIAL_ITEMS=<?= json_encode($doc['items'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script><script src="../engine/editor-kit.js?v=<?= $v('editor-kit.js') ?>"></script><script src="../engine/<?= $e($config['script']) ?>?v=<?= $v($config['script']) ?>"></script></body></html>
<?php
}
