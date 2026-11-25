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

// Valet står kvar i adressen, så en uppdatering eller en bakåtknapp visar samma grupp.
$selected=(string)($_GET['target'] ?? '');
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

// Utdelningar som når de här eleverna, så "klart av" räknar rätt antal.
$assignments=[];
foreach ($data['assignments'] ?? [] as $aid=>$a) {
    if ($a['teacher_id']!==$teacher) continue;
    foreach ($members as $sid=>$s) if (classroomAllowed($data,$s,$a)) { $assignments[$aid]=$a; break; }
}
$quizzes=is_file(QUIZZES_FILE)?(json_decode(file_get_contents(QUIZZES_FILE),true) ?: []):[];
$repetition=repetitionSummaryFor(array_keys($members),$quizzes,$teacher);

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
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Uppföljning</title><link rel="stylesheet" href="../assets/classroom.css"><link rel="stylesheet" href="../assets/teacher-shell.css"></head><body class="classroom"><main class="teacher-page">
<?php teacherShell('uppfoljning','Uppföljning','Hur det går för de inloggade eleverna.'); ?>
<?php if (!$classes && !$groups): ?>
<section class="panel"><h2>Ingen klass ännu</h2><p>Skapa en klass i <a href="classes.php">Klasser</a>, lägg till elever och dela ut något. Då fylls den här vyn.</p></section>
<?php else: ?>
<section class="panel"><h2><?= $targetName!==''?classroomEscape($targetName):'Välj klass eller grupp' ?></h2>
<form method="get"><label for="target">Klass eller grupp</label><select id="target" name="target">
<optgroup label="Klasser"><?php foreach ($classes as $cid=>$c): ?><option value="classes:<?= classroomEscape($cid) ?>" <?= $selected==='classes:'.$cid?'selected':'' ?>><?= classroomEscape($c['name']) ?></option><?php endforeach; ?></optgroup>
<optgroup label="Grupper"><?php foreach ($groups as $gid=>$g): ?><option value="groups:<?= classroomEscape($gid) ?>" <?= $selected==='groups:'.$gid?'selected':'' ?>><?= classroomEscape($g['name']) ?></option><?php endforeach; ?></optgroup>
</select><p><button class="secondary">Visa</button></p></form>
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
<p class="muted">Öppna en elev för att se vilka frågor som blev fel. Oinloggade spelare räknas inte här.</p>
</section>
<?php endif; ?>
</main></body></html>
