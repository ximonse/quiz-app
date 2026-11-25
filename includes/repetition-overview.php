<?php
// Lärarens bild av elevernas repetition. Repetitionen har hittills bara synts
// för eleven ("Lite repetition idag"); läraren har inte kunnat se vem som har
// en växande skuld eller vad som sitter löst.
//
// Läser bara. repetitionDueQueue() används med flit INTE här: den kapar listan
// vid $limit för att elevens dagsdos ska kännas rimlig, så dess antal är inte
// elevens verkliga antal förfallna kort.
require_once __DIR__.'/repetition.php';
require_once __DIR__.'/quiz-errors.php';

// Samma "i dag" som repetitionen själv räknar med, inte serverns lokala datum.
function repetitionToday(): string {
    return (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');
}
// Per elev: antal förfallna kort och det kort som sitter lösast just nu.
// $quizzes behövs bara för att kunna sätta en läsbar etikett på frågan.
// Kort från andra lärares quiz räknas inte: de hör inte hemma i den här vyn.
function repetitionSummaryFor(array $studentIds, array $quizzes, string $teacher): array {
    $today=repetitionToday();
    $summary=[];
    foreach ($studentIds as $id) $summary[$id]=['due'=>0,'total'=>0,'weakest'=>null,'stability'=>null];
    foreach (repetitionRead()['cards'] ?? [] as $card) {
        if (!is_array($card)) continue;
        $student=$card['student_id'] ?? null;
        if (!isset($summary[$student])) continue;
        $quiz=$quizzes[$card['quiz_id'] ?? ''] ?? null;
        if (!is_array($quiz) || ($quiz['teacher_id'] ?? '')!==$teacher) continue;
        $summary[$student]['total']++;
        $isDue=($card['due'] ?? '')<=$today;
        if ($isDue) $summary[$student]['due']++;
        // Lösast = lägst stability bland de förfallna korten; har eleven inget
        // förfallet säger vi inget, för då är ingenting efter just nu.
        $stability=isset($card['stability']) && is_numeric($card['stability'])?(float)$card['stability']:null;
        if (!$isDue || $stability===null) continue;
        if ($summary[$student]['stability']===null || $stability<$summary[$student]['stability']) {
            $summary[$student]['stability']=$stability;
            $index=isset($card['question_index'])?(int)$card['question_index']:-1;
            $summary[$student]['weakest']=$index>=0?(quizItemLabel($quiz,$index) ?? ('Fråga '.($index+1))):null;
        }
    }
    return $summary;
}
