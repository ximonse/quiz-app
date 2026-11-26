<?php
// admin/followup.php — Uppföljning: en klass eller grupp i taget, en rad per
// elev. Svarar på frågorna man ställer sig mellan lektionerna: vem är efter,
// vem har repetition att göra, och vad sitter lösast just nu.
// Läsvy; all redigering ligger kvar i classes.php.
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom-practice.php';
require_once __DIR__.'/../includes/repetition-overview.php';
require_once __DIR__.'/../includes/teacher-shell.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }
header('Cache-Control: no-store');
$teacher=getCurrentTeacherID(); $data=classroomRead();
$classes=array_filter($data['classes'] ?? [],fn($c)=>$c['teacher_id']===$teacher);
$groups=array_filter($data['groups'] ?? [],fn($g)=>$g['teacher_id']===$teacher);

$assignmentId=is_string($_GET['assignment'] ?? null) ? $_GET['assignment'] : '';
$assignment=$data['assignments'][$assignmentId] ?? null;
if ($assignmentId!=='' && (!$assignment || $assignment['teacher_id']!==$teacher)) {
    $assignment=null;
    http_response_code(404);
}

// Valet står kvar i adressen, så en uppdatering eller en bakåtknapp visar samma grupp.
$selected=is_string($_GET['target'] ?? null) ? $_GET['target'] : '';
if ($assignment) $selected=$assignment['target_type'].':'.$assignment['target_id'];
if ($selected==='' && $classes) $selected='classes:'.array_key_first($classes);
[$kind,$targetId]=array_pad(explode(':',$selected,2),2,'');
$members=[]; $targetName='';
if ($kind==='classes' && isset($classes[$targetId])) {
    $targetName=$classes[$targetId]['name'];
    $members=array_filter($data['students'] ?? [],fn($s)=>$s['teacher_id']===$teacher && $s['class_id']===$targetId && !empty($s['active']));
} elseif ($kind==='groups' && isset($groups[$targetId])) {
    $targetName=$groups[$targetId]['name'];
    $members=array_filter($data['students'] ?? [],fn($s)=>$s['teacher_id']===$teacher && !empty($s['active']) && in_array($s['id'],$groups[$targetId]['students'],true));
}
uasort($members,fn($a,$b)=>strcmp($a['name'],$b['name']));

$practiceRows=[];
if ($assignment) {
    $recipients=classroomAssignmentPupils($data,$assignment,$teacher);
    uasort($recipients,fn($a,$b)=>strnatcmp(classroomFold($a['name']),classroomFold($b['name'])));
    foreach ($recipients as $sid=>$student) {
        $practiceRows[]=classroomPracticeRow($data,$assignmentId,$sid,$assignment['kind']) + [
            'first'=>'<a href="pupil.php?id='.rawurlencode($sid).'#assignment-'.rawurlencode($assignmentId).'">'.classroomEscape($student['name']).'</a>',
        ];
    }
} elseif ($assignmentId==='') {
    // Utdelningar som når de här eleverna, så "klart av" räknar rätt antal.
    $assignments=[];
    foreach ($data['assignments'] ?? [] as $aid=>$a) {
        if ($a['teacher_id']!==$teacher) continue;
        if ($targetName!=='' && $a['target_type']===$kind && $a['target_id']===$targetId) {
            $assignments[$aid]=$a;
            continue;
        }
        foreach ($members as $sid=>$s) if (classroomAllowed($data,$s,$a)) { $assignments[$aid]=$a; break; }
    }
    $repetition=repetitionSummaryFor(array_keys($members),readJSON(QUIZZES_FILE),$teacher);
    $rows=[];
    foreach ($members as $sid=>$s) {
        $done=0; $reaches=0; $latest=null;
        foreach ($assignments as $aid=>$a) {
            if (!classroomAllowed($data,$s,$a)) continue;
            $reaches++;
            $attempts=classroomAttempts($data,$aid,$sid);
            if (classroomStatus($attempts,$a['kind'])==='Klart') $done++;
            foreach ($attempts as $attempt) if ($latest===null || strcmp($attempt['updated'],$latest)>0) $latest=$attempt['updated'];
        }
        $rows[$sid]=['name'=>$s['name'],'done'=>$done,'reaches'=>$reaches,'latest'=>$latest]+$repetition[$sid];
    }
}
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Uppföljning</title><link rel="stylesheet" href="../assets/classroom.css"><link rel="stylesheet" href="../assets/teacher-shell.css"></head><body class="classroom teacher-workflow"><main class="teacher-page">
<?php teacherShell('uppfoljning','Uppföljning'); ?>
<?php if ($assignment): ?>
<section class="workflow-panel">
    <div class="workflow-heading">
        <div>
            <p class="workflow-muted"><?= $assignment['target_type']==='groups'?'Grupp':'Klass' ?> · <?= classroomEscape($targetName!=='' ? $targetName : 'Borttagen mottagare') ?></p>
            <h2><?= classroomEscape($assignment['title']) ?></h2>
        </div>
        <a class="workflow-link" href="followup.php?target=<?= rawurlencode($selected) ?>">Till översikten</a>
    </div>
    <p class="workflow-muted"><?= classroomEscape(classroomKindLabel($assignment['kind'])) ?> · Utdelad <?= classroomEscape(substr($assignment['created'],0,10)) ?> · <?= count($practiceRows) ?> <?= count($practiceRows)===1?'elev':'elever' ?></p>
    <?php classroomPracticeTable('Elev',$practiceRows); ?>
</section>
<?php elseif ($assignmentId!==''): ?>
<section class="workflow-panel"><h2>Utdelningen är inte tillgänglig</h2><p><a href="followup.php">Till uppföljningen</a></p></section>
<?php elseif (!$classes && !$groups): ?>
<section class="workflow-panel"><h2>Ingen klass ännu</h2><p>Skapa en klass i <a href="classes.php">Klasser</a>, lägg till elever och dela ut något. Då fylls den här vyn.</p></section>
<?php else: ?>
<section class="workflow-panel">
<div class="workflow-heading"><h2><?= $targetName!==''?classroomEscape($targetName):'Välj klass eller grupp' ?></h2></div>
<form method="get" class="workflow-toolbar"><label for="target">Visa klass eller grupp</label><select id="target" name="target">
<optgroup label="Klasser"><?php foreach ($classes as $cid=>$c): ?><option value="classes:<?= classroomEscape($cid) ?>" <?= $selected==='classes:'.$cid?'selected':'' ?>><?= classroomEscape($c['name']) ?></option><?php endforeach; ?></optgroup>
<optgroup label="Grupper"><?php foreach ($groups as $gid=>$g): ?><option value="groups:<?= classroomEscape($gid) ?>" <?= $selected==='groups:'.$gid?'selected':'' ?>><?= classroomEscape($g['name']) ?></option><?php endforeach; ?></optgroup>
</select><button class="workflow-primary">Visa</button></form>
<p class="muted">Klart räknas över de <?= count($assignments) ?> <?= count($assignments)===1?'utdelning':'utdelningar' ?> som når gruppen. Repetitionen samlar kort från dina fakta- och glosquiz som är publicerade och utdelade, när eleven övat på dem inloggad.</p>
<div class="table-wrap"><table><thead><tr><th>Elev</th><th>Klart</th><th>Senast övat</th><th>Repetition att göra</th><th>Svagast just nu</th></tr></thead><tbody>
<?php foreach ($rows as $sid=>$row): ?>
<tr>
<td><a href="pupil.php?id=<?= classroomEscape(urlencode($sid)) ?>"><?= classroomEscape($row['name']) ?></a></td>
<td<?= ($row['reaches'] && $row['done']<$row['reaches'])?' class="behind"':'' ?>><?= $row['reaches']?((int)$row['done'].' av '.(int)$row['reaches']):'—' ?></td>
<td><?= $row['latest']?classroomEscape((new DateTimeImmutable($row['latest']))->setTimezone(new DateTimeZone('Europe/Stockholm'))->format('Y-m-d')):'aldrig' ?></td>
<td<?= $row['due']?' class="behind"':'' ?>><?php if ($row['due']): ?><?= (int)$row['due'] ?> kort<?php elseif ($row['total']): ?>Inget i dag<?php else: ?>—<?php endif; ?><?= $row['total']?' <span class="muted">av '.(int)$row['total'].'</span>':'' ?></td>
<td><?= $row['weakest']!==null?classroomEscape($row['weakest']):'—' ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5">Inga aktiva elever i <?= $targetName!==''?classroomEscape($targetName):'den valda gruppen' ?>.</td></tr><?php endif; ?>
</tbody></table></div>
<p class="workflow-muted">Öppna en elev för att se vilka frågor som blev fel.</p>
</section>
<section class="workflow-panel">
    <div class="workflow-heading"><h2>Utdelningar</h2></div>
    <?php if (!$assignments): ?><p>Inga utdelningar till eleverna i det här urvalet ännu.</p><?php else: ?>
    <ul class="workflow-assignments">
    <?php foreach (array_reverse($assignments,true) as $aid=>$a):
        $recipient=$data[$a['target_type']][$a['target_id']]['name'] ?? 'Borttagen mottagare'; ?>
        <li><a href="followup.php?assignment=<?= rawurlencode($aid) ?>"><?= classroomEscape($a['title']) ?></a>
            <span class="workflow-muted"><?= classroomEscape($recipient) ?> · <?= classroomEscape(classroomKindLabel($a['kind'])) ?> · <?= classroomEscape(substr($a['created'],0,10)) ?></span></li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<?php endif; ?>
</main></body></html>
