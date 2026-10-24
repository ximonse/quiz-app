<?php
require_once __DIR__.'/../config.php'; require_once __DIR__.'/../ai/quiz-service.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }
$id=$_GET['id']??''; $error='';
try {
    $stored=aiOwn(aiRead(QUIZZES_FILE),$id,getCurrentTeacherID());
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        requireValidCsrf(); aiPublishQuiz($id,getCurrentTeacherID(),$_POST['revision']??'');
        header('Location: review.php?id='.urlencode($id).'&published=1'); exit;
    }
} catch (QuizAiError $e) { http_response_code($e->status); $error=$e->getMessage(); if (!isset($stored)) { http_response_code($e->status); echo htmlspecialchars($error); exit; } }
$view=aiView($stored); $quiz=aiEffectiveQuiz($stored);
function reviewEscape($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function reviewImage(array $image): void {
    $crop = $image['crop'] ?? ['x'=>50,'y'=>50,'zoom'=>100];
    $style = '--crop-x:'.(int)$crop['x'].'%;--crop-y:'.(int)$crop['y'].'%;--crop-zoom:'.((int)$crop['zoom']/100);
    ?><img src="../api/question-image.php?file=<?= rawurlencode($image['file']) ?>" alt="<?= reviewEscape($image['alt']) ?>" style="<?= reviewEscape($style) ?>"><?php
}
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Granska quiz</title><link rel="stylesheet" href="../engine/image-choice.css?v=<?= filemtime(__DIR__ . '/../engine/image-choice.css') ?>"></head>
<body><main class="review-main"><a href="dashboard.php">← Mina quiz</a><header><p class="eyebrow">Granska · justera · publicera</p><h1><?= reviewEscape($quiz['title']) ?></h1>
<p><?= $view['publication']==='draft'?'Inte publicerat. Elever kan inte nå quizet ännu.':($view['publication']==='pending'?'Du granskar nya ändringar. Eleverna ser fortfarande den publicerade versionen.':'Quizet är publicerat.') ?></p>
<nav><a class="button secondary" href="<?= reviewEscape($view['edit_url']) ?>">Redigera frågor</a><a class="button secondary" href="images.php?id=<?= urlencode($id) ?>">Infobilder</a>
<?php if (isset($quiz['items'])): ?><a class="button secondary" href="../play/<?= quizTypeRoute($quiz['type'])['player'] ?>?id=<?= urlencode($id) ?>&name=Förhandsvisning&preview=1">Prova quizet</a><?php endif; ?></nav></header>
<?php if ($error): ?><p class="error" role="alert"><?= reviewEscape($error) ?></p><?php endif; ?>
<?php if (isset($_GET['published']) && $view['publication']==='published'): ?><p class="success" role="status">Publicerat! <a href="<?= reviewEscape($view['student_url']) ?>">Öppna elevlänken</a></p><?php endif; ?>
<?php foreach ($quiz['items']??$quiz['questions'] as $index=>$item):
    $reverse=$quiz['type']==='fact' && ($quiz['settings']['question_direction']??'')==='description';
    $prompt=$reverse?($item['description']??''):($item['question']??$item['concept']??$item['sentence']??'');
    $answer=$reverse?($item['concept']??''):($item['description']??$item['translation']??$item['answer']??'');
    $wrongAnswers=$reverse?(($item['reverse_wrong_options']??[])?:array_values(array_map(fn($v)=>$v['concept'],array_filter($quiz['items'],fn($v)=>$v['concept']!==$answer)))):($item['wrong_options']??array_filter($item['options']??[],fn($v)=>$v!==($item['answer']??'')));
?>
<section class="question-card"><h2><?= $index+1 ?>. <?= reviewEscape($prompt) ?></h2>
<?php if (!empty($item['image'])): $info=is_array($item['image'])?$item['image']:['file'=>$item['image'],'alt'=>$item['image_alt']??'Infobild']; ?><div class="info-image"><?php reviewImage($info); ?></div><?php endif; ?>
<?php if ($quiz['type']==='image_choice'): ?><p><?= count(array_filter($item['choices'],fn($c)=>$c['correct']))>1?'Flera rätta bilder · eleven markerar och kontrollerar':'En rätt bild · eleven klickar och får svar' ?></p><div class="choice-grid">
<?php foreach ($item['choices'] as $i=>$choice): ?><figure class="review-choice <?= $choice['correct']?'correct':'' ?>"><div class="review-image-frame"><?php reviewImage($choice['image']); ?></div><figcaption>Bild <?= $i+1 ?> · <?= $choice['correct']?'Rätt':'Fel' ?></figcaption></figure><?php endforeach; ?></div>
<?php elseif ($quiz['type']==='sort'): ?><?php if ($item['flashcard_prompt']!==''): ?><p class="small">Ledtråd för flashcards: <?= reviewEscape($item['flashcard_prompt']) ?></p><?php endif; ?><div class="review-sort">
<?php foreach (array_merge($item['fields'],[['id'=>null,'title'=>'Hör inte till något fält','text'=>'','image'=>null]]) as $field): $members=array_filter($item['objects'],fn($o)=>$o['field']===$field['id']); if ($field['id']===null && !$members) continue; ?>
<div class="review-field<?= $field['id']===null?' loose':'' ?>"><?php if ($field['image']): ?><div class="review-image-frame"><?php reviewImage($field['image']); ?></div><?php endif; ?><strong><?= reviewEscape($field['title']) ?></strong><?php if ($field['text']!==''): ?><p class="small"><?= reviewEscape($field['text']) ?></p><?php endif; ?>
<ul><?php foreach ($members as $object): ?><li><?php if ($object['image']): ?><span class="review-thumb"><?php reviewImage($object['image']); ?></span><?php endif; ?><?= reviewEscape($object['text']!==''?$object['text']:$object['image']['alt']) ?></li><?php endforeach; ?></ul></div>
<?php endforeach; ?></div>
<?php else: ?><p><strong>Rätt svar:</strong> <?= reviewEscape($answer) ?></p><ul><?php foreach ($wrongAnswers as $wrong): ?><li><?= reviewEscape($wrong) ?></li><?php endforeach; ?></ul><?php endif; ?></section>
<?php endforeach; ?>
<footer class="publish-bar"><?php if ($view['publication']!=='published'): ?><form method="post"><?= csrfField() ?><input type="hidden" name="revision" value="<?= aiRevision($stored) ?>"><button>Publicera <?= $view['publication']==='pending'?'ändringarna':'quizet' ?></button></form><?php else: ?><a class="button" href="<?= reviewEscape($view['student_url']) ?>">Öppna elevlänken</a><?php endif; ?></footer>
</main></body></html>
