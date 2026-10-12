<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ai/tools.php';
$paths=[];
foreach (aiTools() as $tool) {
    $paths['/api/ai-action.php?tool='.$tool['name']]=['post'=>[
        'operationId'=>$tool['name'],'description'=>$tool['description'],
        'x-openai-isConsequential'=>!$tool['annotations']['readOnlyHint'],
        'requestBody'=>['required'=>true,'content'=>['application/json'=>['schema'=>$tool['inputSchema']]]],
        'responses'=>['200'=>['description'=>'Quiz result or Markdown preview','content'=>['application/json'=>['schema'=>['type'=>'object','additionalProperties'=>true]]]]]
    ]];
}
// Paths must not contain query parameters. Expose each tool as a PATH_INFO route.
$clean=[];
foreach ($paths as $path=>$value) $clean[str_replace('?tool=','/',$path)]=$value;
aiJson(['openapi'=>'3.1.0','info'=>['title'=>'Quiz App AI','version'=>'1.0.0'], 'servers'=>[['url'=>aiBaseUrl()]],
    'security'=>[['quizKey'=>[]]],'components'=>['securitySchemes'=>['quizKey'=>['type'=>'http','scheme'=>'bearer']]],'paths'=>$clean]);
