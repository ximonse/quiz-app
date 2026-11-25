<?php
// Läsvyer över elevers övning: per utdelning i en klass, och per elev.
// Inget här skriver. Titlar tas från uppgiftens egen ögonblicksbild
// ($assignment['title']), aldrig via classroomContent(), så övningen syns kvar
// även när quizet senare avpublicerats, ändrats eller tagits bort.
require_once __DIR__.'/classroom.php';

// Alla elever som är relevanta för en utdelning: dagens mottagare plus alla som
// har övat på den. En borttagen klass eller grupp gör mottagarlistan otillgänglig;
// då står historiken kvar i stället för att vyn kraschar.
function classroomAssignmentPupils(array $data, array $assignment, string $teacher): array {
    try { $pupils=classroomRecipients($data,$assignment); } catch (DomainException $e) { $pupils=[]; }
    foreach ($data['attempts'] ?? [] as $attempt) {
        if ($attempt['assignment_id']!==$assignment['id']) continue;
        $student=$data['students'][$attempt['student_id']] ?? null;
        if ($student && $student['teacher_id']===$teacher) $pupils[$attempt['student_id']]=$student;
    }
    return $pupils;
}
// Utdelningar som når en klass: de som delats direkt till klassen, plus de som
// delats till en grupp där minst en av klassens elever ingår.
function classroomClassAssignments(array $data, string $classId, string $teacher): array {
    $members=[];
    foreach ($data['students'] ?? [] as $sid=>$s) if ($s['teacher_id']===$teacher && $s['class_id']===$classId) $members[$sid]=true;
    $rows=[];
    foreach ($data['assignments'] ?? [] as $aid=>$a) {
        if ($a['teacher_id']!==$teacher) continue;
        $via=null;
        if ($a['target_type']==='classes' && $a['target_id']===$classId) $via='';
        elseif ($a['target_type']==='groups') {
            $group=$data['groups'][$a['target_id']] ?? null;
            if (!$group || $group['teacher_id']!==$teacher) continue;
            foreach ($group['students'] as $sid) if (isset($members[$sid])) { $via=$group['name']; break; }
        }
        if ($via===null) continue;
        $rows[$aid]=$a+['via_group'=>$via];
    }
    return array_reverse($rows,true);
}
// Utdelningar en elev är berörd av: dagens mottagarlistor plus allt eleven övat på.
function classroomPupilAssignments(array $data, array $student): array {
    $rows=[];
    foreach ($data['assignments'] ?? [] as $aid=>$a) {
        if ($a['teacher_id']!==$student['teacher_id']) continue;
        if (classroomAllowed($data,$student,$a)) $rows[$aid]=$a;
    }
    foreach ($data['attempts'] ?? [] as $attempt) {
        if ($attempt['student_id']!==$student['id']) continue;
        $a=$data['assignments'][$attempt['assignment_id']] ?? null;
        if ($a && $a['teacher_id']===$student['teacher_id']) $rows[$a['id']]=$a;
    }
    return array_reverse($rows,true);
}
// En rads siffror för en elev på en utdelning. Returnerar null-fälten när eleven
// inte börjat, så vyn kan visa "–" utan att räkna om något.
function classroomPracticeRow(array $data, string $assignmentId, string $studentId, string $kind): array {
    $rows=classroomAttempts($data,$assignmentId,$studentId);
    $latest=$rows[0] ?? null;
    $when=null;
    if ($latest) $when=(new DateTimeImmutable($latest['updated']))->setTimezone(new DateTimeZone('Europe/Stockholm'))->format('Y-m-d H:i');
    $effort='—';
    if ($latest && $kind==='quiz') $effort=$latest['right'].' rätt · '.$latest['wrong'].' fel';
    elseif ($latest && in_array($kind,['cards','deck'],true)) $effort=max(array_column($rows,'cards')).' kort som mest';
    elseif ($latest) $effort='Hämtad';
    return ['status'=>classroomStatus($rows,$kind),'effort'=>$effort,'tries'=>count($rows),'when'=>$when];
}
// Gemener för namnsökning. mbstring finns inte överallt där appen körs, och
// strtolower() rör bara ASCII, så nordiska versaler mappas för hand — samma
// mönster som classroomCode() använder för ordkoderna.
function classroomFold(string $value): string {
    return strtr(strtolower($value),['Å'=>'å','Ä'=>'ä','Ö'=>'ö','É'=>'é','Ü'=>'ü','Ø'=>'ø','Æ'=>'æ']);
}
function classroomKindLabel(string $kind): string {
    return ['quiz'=>'Quiz','cards'=>'Flashcards','deck'=>'Flashcards','material'=>'PDF'][$kind] ?? $kind;
}
// Gemensam tabell för båda vyerna. $label avgör vad första kolumnen heter och
// vad varje rad pekar på: elevens namn i klassvyn, uppgiftens namn i elevvyn.
function classroomPracticeTable(string $first, array $rows): void {
    echo '<div class="table-wrap"><table><thead><tr><th>'.classroomEscape($first).'</th><th>Status</th><th>Senaste försöket</th><th>Försök</th><th>Senast övat</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>'.$row['first'].'</td><td>'.classroomEscape($row['status']).'</td><td>'.classroomEscape($row['effort']).'</td><td>'.(int)$row['tries'].'</td><td>'.classroomEscape($row['when'] ?? '—').'</td></tr>';
        // Färdig HTML, redan escapad av den som byggde den. Tom sträng ger ingen rad.
        if (!empty($row['detail'])) echo '<tr class="detail"><td colspan="5">'.$row['detail'].'</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="5">Inget att visa.</td></tr>';
    echo '</tbody></table></div>';
}
