<?php
// Vilka frågor en inloggad elev svarade fel på. Läser quizens resultatlista, som
// är det enda stället där svaren per fråga sparas; klassrummets försök
// (classroom.json) håller bara summor.
//
// Allt i ett resultats errors-lista kommer från elevens webbläsare och har inte
// granskats när det sparades. Varje fält typkontrolleras därför här, och inget
// skrivs ut utan classroomEscape() i vyn.
require_once __DIR__.'/classroom.php';

// Results for one pupil on an already authorized quiz, newest first.
function quizPupilResults(array $quiz, string $studentId): array {
    $rows=[];
    foreach ($quiz['results'] ?? [] as $result) {
        if (!is_array($result) || ($result['student_id'] ?? null)!==$studentId) continue;
        $rows[]=$result;
    }
    // Resultaten läggs till i tidsordning, så den sista är den senaste.
    return array_reverse($rows);
}
// Etiketten för en fråga: samma fält som statistiksidan använder. Frågan kan ha
// ändrats sedan försöket, så texten är bara en etikett — svaren nedan är sanningen.
function quizItemLabel(array $quiz, int $index): ?string {
    $item=($quiz['items'] ?? [])[$index] ?? null;
    if (!is_array($item)) return null;
    $label=($quiz['type'] ?? '')==='glossary' ? ($item['word'] ?? null) : ($item['concept'] ?? $item['question'] ?? null);
    return is_string($label) && $label!=='' ? $label : null;
}
// Ett svarsvärde kan vara text eller en lista av id:n beroende på quiztyp.
function quizErrorText($value): ?string {
    if (is_string($value)) return $value===''?null:$value;
    if (is_int($value) || is_float($value)) return (string)$value;
    if (is_array($value)) {
        $parts=array_filter(array_map(fn($v)=>is_string($v)||is_int($v)?(string)$v:null,$value),fn($v)=>$v!==null && $v!=='');
        return $parts?implode(', ',$parts):null;
    }
    return null;
}
// En rad per fråga eleven hade fel på, med antal gånger. Träningsläget ställer
// samma fråga igen tills den sitter, så samma index kan förekomma flera gånger.
function quizWrongQuestions(array $quiz, array $result): array {
    $rows=[];
    foreach ($result['errors'] ?? [] as $err) {
        if (!is_array($err) || !isset($err['item_index'])) continue;
        $index=$err['item_index'];
        if (!is_int($index) && !(is_string($index) && preg_match('/^[0-9]{1,6}$/D',$index))) continue;
        $index=(int)$index;
        if ($index<0) continue;
        $key=(string)$index;
        if (!isset($rows[$key])) $rows[$key]=['label'=>quizItemLabel($quiz,$index),'index'=>$index,'count'=>0,'given'=>null,'correct'=>null];
        $rows[$key]['count']++;
        // Första felsvaret är det intressanta: det eleven trodde innan hjälpen.
        if ($rows[$key]['given']===null) $rows[$key]['given']=quizErrorText($err['given'] ?? null);
        if ($rows[$key]['correct']===null) $rows[$key]['correct']=quizErrorText($err['correct'] ?? null);
    }
    usort($rows,fn($a,$b)=>[$b['count'],$a['index']]<=>[$a['count'],$b['index']]);
    return $rows;
}
function quizWrongQuestionsHtml(array $quiz, array $result): string {
    $wrong=quizWrongQuestions($quiz,$result);
    if (!$wrong) return '<p class="muted">Inga fel i elevens senaste försök på quizet.</p>';
    $html='<details><summary>'.count($wrong).' '.(count($wrong)===1?'fråga':'frågor').' blev fel</summary><ul class="wrong-list">';
    foreach ($wrong as $row) {
        $html.='<li><strong>'.classroomEscape($row['label'] ?? ('Fråga '.($row['index']+1))).'</strong>';
        if ($row['count']>1) $html.=' <span class="muted">'.$row['count'].' försök</span>';
        if ($row['given']!==null) $html.='<br>Eleven svarade: <q>'.classroomEscape($row['given']).'</q>';
        if ($row['correct']!==null) $html.='<br><span class="muted">Rätt svar: '.classroomEscape($row['correct']).'</span>';
        $html.='</li>';
    }
    return $html.'</ul></details>';
}
