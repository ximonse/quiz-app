<?php
require_once __DIR__.'/../includes/drag-quiz-editor.php'; require_once __DIR__.'/../ai/order-prompt.php';
dragQuizEditor(['type'=>'order','label'=>'ordningsquiz','flashcards'=>false,'required_label'=>'Rätt per fråga i träning',
    'intro'=>'Varje fråga har en linje och 2–8 objekt. Skriv objekten i rätt ordning; eleven får dem blandade och placerar dem på linjen.',
    'prompt'=>aiOrderPrompt(),'template'=>'ordningsquiz-mall.json','script'=>'order-editor.js','uploads'=>['objects'=>'object']]);
