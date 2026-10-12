<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ai/tools.php';
try {
    $actor=aiAuthenticate();
    if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Allow: POST'); throw new QuizAiError('Use POST.',405); }
    $input=aiBody();
    if (!is_string($input['tool']??null) || !is_array($input['arguments']??null)) throw new QuizAiError('Send tool and arguments.');
    aiJson(aiCall($actor,$input['tool'],$input['arguments']));
} catch (QuizAiError $e) { aiJson(['error'=>$e->getMessage()],$e->status); }
catch (Throwable $e) { error_log('Quiz AI API: '.$e->getMessage()); aiJson(['error'=>'Server could not complete the request.'],500); }
