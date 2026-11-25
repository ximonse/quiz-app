<?php
// En klass: vad som delats ut till den, och hur klassens elever övat på en vald
// utdelning. Läsvy; all redigering ligger kvar i classes.php.
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom-practice.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }
header('Cache-Control: no-store');
$teacher=getCurrentTeacherID(); $data=classroomRead(); $id=(string)($_GET['id'] ?? '');
// Samma svar för okänd och annans klass, så ingen kan avgöra att den finns.
try { $class=classroomOwned($data,'classes',$id,$teacher); }
catch (DomainException $e) { header('Location: classes.php'); exit; }
$assignments=classroomClassAssignments($data,$id,$teacher);
$selected=(string)($_GET['assignment'] ?? '');
$assignment=$assignments[$selected] ?? null;
// Klassens elever, borttagna sist. Borttagna står kvar med sin historik.
$members=array_filter($data['students'] ?? [],fn($s)=>$s['teacher_id']===$teacher && $s['class_id']===$id);
uasort($members,fn($a,$b)=>[empty($a['active']),$a['name']]<=>[empty($b['active']),$b['name']]);
$rows=[];
if ($assignment) {
    $reached=classroomAssignmentPupils($data,$assignment,$teacher);
    foreach ($members as $sid=>$s) {
        if ($assignment['target_type']==='groups' && !isset($reached[$sid])) continue;
        $rows[]=classroomPracticeRow($data,$selected,$sid,$assignment['kind'])
            +['first'=>'<a href="pupil.php?id='.classroomEscape(urlencode($sid)).'">'.classroomEscape($s['name']).'</a>'.(empty($s['active'])?' <span class="muted">(borttagen)</span>':'')];
    }
}
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= classroomEscape($class['name']) ?></title><link rel="stylesheet" href="../assets/classroom.css"></head><body class="classroom"><main>
<header><h1><?= classroomEscape($class['name']) ?></h1><nav><a href="dashboard.php">Mina quiz</a><a href="classes.php">Klasser och uppgifter</a><a href="pupil.php">Hitta elev</a><a href="cards.php?class=<?= classroomEscape(urlencode($id)) ?>">Inloggningskort</a></nav></header>
<section class="panel"><h2>Utdelat till klassen</h2>
<?php if (!$assignments): ?><p>Inget utdelat till den här klassen ännu.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Uppgift</th><th>Typ</th><th>Utdelad</th></tr></thead><tbody>
<?php foreach ($assignments as $aid=>$a): ?>
<tr<?= $aid===$selected?' class="chosen"':'' ?>><td><a href="?id=<?= classroomEscape(urlencode($id)) ?>&amp;assignment=<?= classroomEscape(urlencode($aid)) ?>"><?= classroomEscape($a['title']) ?></a><?= $a['via_group']!==''?' <span class="muted">via grupp '.classroomEscape($a['via_group']).'</span>':'' ?></td><td><?= classroomEscape(classroomKindLabel($a['kind'])) ?></td><td><?= classroomEscape((new DateTimeImmutable($a['created']))->setTimezone(new DateTimeZone('Europe/Stockholm'))->format('Y-m-d')) ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php if ($assignment): ?>
<section class="panel"><h2><?= classroomEscape($assignment['title']) ?></h2>
<p class="muted"><?= classroomEscape(classroomKindLabel($assignment['kind'])) ?><?= $assignment['via_group']!==''?' · utdelad via grupp '.classroomEscape($assignment['via_group']):'' ?> · <?= count($rows) ?> <?= count($rows)===1?'elev':'elever' ?> i klassen. Siffrorna gäller den här utdelningen.</p>
<?php if ($assignment['kind']==='quiz'):
    $quizzes=is_file(QUIZZES_FILE)?(json_decode(file_get_contents(QUIZZES_FILE),true) ?: []):[];
    if (($quizzes[$assignment['content_id']]['teacher_id'] ?? '')===$teacher): ?>
<p><a href="stats.php?id=<?= classroomEscape(urlencode($assignment['content_id'])) ?>">Statistik för quizet: snitt och svåraste frågorna</a> <span class="muted">Räknar alla som övat på quizet, även utanför den här utdelningen.</span></p>
<?php endif; endif; ?>
<?php classroomPracticeTable('Elev',$rows); ?></section>
<?php elseif ($assignments): ?><p class="muted">Välj en uppgift ovan för att se hur klassens elever övat.</p><?php endif; ?>
<section class="panel"><h2>Elever i klassen</h2><div class="table-wrap"><table><thead><tr><th>Elev</th><th>Inloggning</th></tr></thead><tbody>
<?php foreach ($members as $sid=>$s): ?><tr><td><a href="pupil.php?id=<?= classroomEscape(urlencode($sid)) ?>"><?= classroomEscape($s['name']) ?></a><?= empty($s['active'])?' <span class="muted">(borttagen)</span>':'' ?></td><td><?= classroomEscape($s['code']) ?></td></tr><?php endforeach; ?>
<?php if (!$members): ?><tr><td colspan="2">Inga elever i klassen.</td></tr><?php endif; ?></tbody></table></div></section>
</main></body></html>
