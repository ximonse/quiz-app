<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom.php';
header('Cache-Control: no-store');
$student=classroomStudent();
if (!$student) { header('Location: ../student/'); exit; }

// v1: bara fakta/glosquiz, samma begränsning som classroomRecord() använder
// när den fyller repetitionspoolen (sort/ordning/bildval har ingen egen
// repetitionsspelare än). Kort vars quiz inte längre matchar hoppas tyst
// över — de ligger kvar i data/repetition.json och försvinner av sig själva
// om läraren avpublicerar eller avmarkerar quizet.
$quizzes=readJSON(QUIZZES_FILE);
$queue=[];
foreach (repetitionDueQueue($student['id'],10) as $card) {
    $quiz=$quizzes[$card['quiz_id']] ?? null;
    if (!$quiz || ($quiz['teacher_id'] ?? null)!==$student['teacher_id']) continue;
    if (!in_array($quiz['type'] ?? '',['fact','glossary'],true) || empty($quiz['settings']['spaced_repetition'])) continue;
    if (($quiz['publication'] ?? 'published')!=='published') continue;
    $item=$quiz['items'][$card['question_index']] ?? null;
    if (!is_array($item)) continue;
    if ($quiz['type']==='fact') { $prompt=$item['concept'] ?? ''; $answer=$item['description'] ?? ''; $wrongs=$item['wrong_options'] ?? []; }
    else { $prompt=$item['sentence'] ?? ''; $answer=$item['translation'] ?? ''; $wrongs=$item['wrong_options'] ?? []; }
    if (!is_string($prompt) || !is_string($answer) || $prompt==='' || $answer==='' || !is_array($wrongs)) continue;
    $options=array_values(array_slice($wrongs,0,3)); $options[]=$answer; shuffle($options);
    $queue[]=['quiz_id'=>$card['quiz_id'],'question_index'=>$card['question_index'],'prompt'=>$prompt,'answer'=>$answer,'options'=>$options];
}
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Repetition</title><link rel="stylesheet" href="../assets/classroom.css"></head>
<body class="classroom"><main class="pupil-home">
<header><div><p class="muted">Hej <?= classroomEscape($student['name']) ?></p><h1>Repetition</h1></div><a class="button secondary" href="../student/">Till mina uppgifter</a></header>
<?php if (!$queue): ?>
<section class="panel"><p>Inget att repetera just nu.</p></section>
<?php else: ?>
<section class="panel" id="review-root">
    <p id="review-progress" class="status"></p>
    <p id="review-question" style="font-size:1.3em;margin:16px 0"></p>
    <div id="review-options" style="display:flex;flex-direction:column;gap:8px"></div>
    <p id="review-feedback" role="status" style="min-height:1.4em;margin-top:12px"></p>
    <p id="review-done" hidden>Bra jobbat, klart för idag!</p>
    <p><a class="button secondary" href="../student/">Till mina uppgifter</a></p>
</section>
<script>window.reviewQueue=<?= json_encode($queue,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;window.reviewCsrf=<?= json_encode(getCsrfToken()) ?>;</script>
<script src="../engine/review-player.js?v=<?= filemtime(__DIR__.'/../engine/review-player.js') ?>"></script>
<?php endif; ?>
</main></body></html>
