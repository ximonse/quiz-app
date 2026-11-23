<?php
// En elev: allt eleven fått och övat på. Sökning när man inte har länken.
// Läsvy; all redigering ligger kvar i classes.php.
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom-practice.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }
header('Cache-Control: no-store');
$teacher=getCurrentTeacherID(); $data=classroomRead();
$id=(string)($_GET['id'] ?? ''); $query=trim((string)($_GET['q'] ?? ''));
$classes=array_filter($data['classes'] ?? [],fn($c)=>$c['teacher_id']===$teacher);
$mine=array_filter($data['students'] ?? [],fn($s)=>$s['teacher_id']===$teacher);
// Borttagna elever får sökas och öppnas: historiken är kvar och utlovad.
$needle=classroomFold($query);
$found=$query===''?[]:array_filter($mine,fn($s)=>strpos(classroomFold($s['name']),$needle)!==false);
uasort($found,fn($a,$b)=>[empty($a['active']),$a['name']]<=>[empty($b['active']),$b['name']]);
$student=null;
if ($id!=='') { try { $student=classroomOwned($data,'students',$id,$teacher); } catch (DomainException $e) { $student=null; } }
$rows=[];
if ($student) {
    foreach (classroomPupilAssignments($data,$student) as $aid=>$a) {
        $target=$data[$a['target_type']][$a['target_id']]['name'] ?? ($a['target_type']==='classes'?'Borttagen klass':'Borttagen grupp');
        $rows[]=classroomPracticeRow($data,$aid,$student['id'],$a['kind'])
            +['first'=>classroomEscape($a['title']).' <span class="muted">'.classroomEscape(classroomKindLabel($a['kind']).' · '.$target).'</span>'];
    }
}
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $student?classroomEscape($student['name']):'Hitta elev' ?></title><link rel="stylesheet" href="../assets/classroom.css"></head><body class="classroom"><main>
<header><h1><?= $student?classroomEscape($student['name']):'Hitta elev' ?></h1><nav><a href="dashboard.php">Mina quiz</a><a href="classes.php">Klasser och uppgifter</a><?php if ($student): ?><a href="class.php?id=<?= classroomEscape(urlencode($student['class_id'])) ?>"><?= classroomEscape($classes[$student['class_id']]['name'] ?? 'Klassen') ?></a><?php endif; ?></nav></header>
<section class="panel"><h2>Sök elev</h2><form method="get"><label for="q">Elevens namn</label><input id="q" name="q" value="<?= classroomEscape($query) ?>" maxlength="100" autocomplete="off"><p><button class="secondary">Sök</button></p></form>
<?php if ($query!==''): ?><?php if (!$found): ?><p>Ingen elev matchar <q><?= classroomEscape($query) ?></q>.</p><?php else: ?>
<?php foreach ($found as $sid=>$s): ?><p><a href="?id=<?= classroomEscape(urlencode($sid)) ?>"><?= classroomEscape($s['name']) ?></a> <span class="muted"><?= classroomEscape($classes[$s['class_id']]['name'] ?? 'Borttagen klass') ?><?= empty($s['active'])?' · borttagen':'' ?></span></p><?php endforeach; ?>
<?php endif; endif; ?></section>
<?php if ($id!=='' && !$student): ?><p class="notice error" role="alert">Eleven hittades inte.</p><?php endif; ?>
<?php if ($student): ?>
<section class="panel"><h2>Övning</h2>
<p class="muted"><?= classroomEscape($classes[$student['class_id']]['name'] ?? 'Borttagen klass') ?><?= empty($student['active'])?' · borttagen, historiken är kvar':'' ?> · inloggning <?= classroomEscape($student['code']) ?>. En rad per utdelning, även uppgifter eleven inte börjat på.</p>
<?php classroomPracticeTable('Uppgift',$rows); ?></section>
<?php endif; ?>
</main></body></html>
