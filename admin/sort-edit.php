<?php
require_once __DIR__.'/../includes/drag-quiz-editor.php'; require_once __DIR__.'/../ai/sort-prompt.php';
dragQuizEditor(['type'=>'sort','label'=>'sorteringsquiz','flashcards'=>true,'required_label'=>'Rätt per objekt i träning',
    'intro'=>'Varje fråga har 1–5 fält och 2–12 objekt. Eleven drar objekten till rätt fält. Objekt som inte hör till något fält ska ligga kvar utanför.',
    'prompt'=>aiSortPrompt(),'template'=>'sorteringsquiz-mall.json','script'=>'sort-editor.js','uploads'=>['fields'=>'field','objects'=>'object']]);
