<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ai/tools.php';
try {
    $actor=aiAuthenticate();
    if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Allow: POST'); throw new QuizAiError('Use POST.',405); }
    $name=trim($_SERVER['PATH_INFO']??'', '/');
    aiJson(aiCall($actor,$name,aiBody()));
} catch (QuizAiError $e) { aiJson(['error'=>$e->getMessage()],$e->status); }
catch (Throwable $e) { error_log('Quiz AI action: '.$e->getMessage()); aiJson(['error'=>'Server could not complete the request.'],500); }
