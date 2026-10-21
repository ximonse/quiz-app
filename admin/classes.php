<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom-admin.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }
header('Cache-Control: no-store');
$teacher=getCurrentTeacherID(); $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    requireValidCsrf();
    try { $_SESSION['classroom_message']=classroomAdminAction($teacher,$_POST,$_FILES); header('Location: classes.php'.(in_array($_POST['action'] ?? '',['students','reset'],true)?'?cards=1':'')); exit; }
    catch (DomainException $e) { $error=$e->getMessage(); }
    catch (RuntimeException $e) { $error='Ändringen kunde inte sparas. Försök igen.'; }
}
$message=$_SESSION['classroom_message'] ?? ''; unset($_SESSION['classroom_message']);
$data=classroomRead();
$mine=fn($kind)=>array_filter($data[$kind] ?? [],fn($row)=>$row['teacher_id']===$teacher);
$classes=$mine('classes'); $students=array_filter($mine('students'),fn($s)=>!empty($s['active'])); $groups=$mine('groups'); $assignments=$mine('assignments');
$classFilter=(string)($_GET['class'] ?? '');
$quizzes=is_file(QUIZZES_FILE)?json_decode(file_get_contents(QUIZZES_FILE),true):[];
$decks=is_file(DATA_DIR.'flashcards.json')?json_decode(file_get_contents(DATA_DIR.'flashcards.json'),true):[];
function classOptions(array $classes, string $selected=''): void { foreach ($classes as $id=>$c) echo '<option value="'.classroomEscape($id).'" '.($id===$selected?'selected':'').'>'.classroomEscape($c['name']).'</option>'; }
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Klasser och uppgifter</title><link rel="stylesheet" href="../assets/classroom.css"></head><body class="classroom"><main>
<header><h1>Klasser och uppgifter</h1><nav><a href="dashboard.php">Mina quiz</a><a href="../student/" target="_blank">Elevinloggning</a><a href="cards.php">Inloggningskort</a></nav></header>
<?php if ($message): ?><p class="notice" role="status"><?= classroomEscape($message) ?> <?php if (isset($_GET['cards'])): ?><a href="cards.php?new=1">Visa och skriv ut nya inloggningskort</a> · PIN visas i 15 minuter.<?php endif; ?></p><?php endif; ?>
<?php if ($error): ?><p class="notice error" role="alert"><?= classroomEscape($error) ?></p><?php endif; ?>
<div class="grid"><section class="panel"><h2>Skapa klass</h2><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="class"><label for="class-name">Klassnamn</label><input id="class-name" name="name" maxlength="100" required><p><button>Skapa klass</button></p></form></section>
<section class="panel"><h2>Lägg till elever</h2><?php if (!$classes): ?><p>Skapa en klass först.</p><?php else: ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="students"><label for="new-class">Klass</label><select id="new-class" name="class_id"><?php classOptions($classes); ?></select><label for="names">Elevnamn, ett per rad</label><textarea id="names" name="names" rows="4" required placeholder="Alex&#10;Kim"></textarea><p><button>Skapa elever och inloggningskort</button></p></form><?php endif; ?></section></div>
<section class="panel"><h2>Elever</h2><form method="get"><label for="class-filter">Visa klass</label><select id="class-filter" name="class"><option value="">Alla klasser</option><?php classOptions($classes,$classFilter); ?></select><p><button class="secondary">Visa</button></p></form>
<div class="table-wrap"><table><thead><tr><th>Elev</th><th>Klass</th><th>Inloggning</th><th>Hantera</th></tr></thead><tbody>
<?php foreach ($students as $id=>$s): if ($classFilter!=='' && $s['class_id']!==$classFilter) continue; ?>
<tr><td><?= classroomEscape($s['name']) ?></td><td><?= classroomEscape($classes[$s['class_id']]['name'] ?? '') ?></td><td><?= classroomEscape($s['code']) ?></td><td><details><summary>Hantera <?= classroomEscape($s['name']) ?></summary>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="move"><input type="hidden" name="student_id" value="<?= classroomEscape($id) ?>"><label for="move-<?= classroomEscape($id) ?>">Flytta till klass</label><select id="move-<?= classroomEscape($id) ?>" name="class_id"><?php classOptions($classes,$s['class_id']); ?></select><p><button class="secondary">Flytta elev</button></p></form>
<form method="post" onsubmit="return confirm('Skapa ny PIN och QR-kod? Den gamla QR-koden och pågående inloggningar slutar fungera.')"><?= csrfField() ?><input type="hidden" name="student_id" value="<?= classroomEscape($id) ?>"><button name="action" value="reset" class="secondary">Ny PIN och QR-kod</button></form>
<form method="post" onsubmit="return confirm('Ta bort eleven? Eleven förlorar inloggning, grupptillhörigheter och tillgång till uppgifter. Tidigare övningsresultat sparas för dig.')"><?= csrfField() ?><input type="hidden" name="student_id" value="<?= classroomEscape($id) ?>"><p><button name="action" value="remove" class="secondary">Ta bort elev</button></p></form></details></td></tr>
<?php endforeach; ?><?php if (!$students): ?><tr><td colspan="4">Inga elever ännu.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel"><h2>Grupper</h2><p class="muted">Välj elever från en eller flera klasser. En elev kan ingå i flera grupper.</p>
<?php $editId=(string)($_GET['group'] ?? ''); $edit=$groups[$editId] ?? ['name'=>'','students'=>[]]; ?>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="group"><input type="hidden" name="group_id" value="<?= classroomEscape(isset($groups[$editId])?$editId:'') ?>"><label for="group-name">Gruppnamn</label><input id="group-name" name="name" value="<?= classroomEscape($edit['name']) ?>" required maxlength="100"><div class="members">
<?php foreach ($classes as $cid=>$c): ?><fieldset><legend><?= classroomEscape($c['name']) ?></legend><?php foreach ($students as $id=>$s): if ($s['class_id']!==$cid) continue; ?><label><input type="checkbox" name="members[]" value="<?= classroomEscape($id) ?>" <?= in_array($id,$edit['students'],true)?'checked':'' ?>><?= classroomEscape($s['name']) ?></label><?php endforeach; ?></fieldset><?php endforeach; ?></div><p><button><?= $editId?'Spara grupp':'Skapa grupp' ?></button> <?php if ($editId): ?><a href="classes.php">Ny grupp</a><?php endif; ?></p></form>
<?php foreach ($groups as $id=>$g): ?><p><a href="?group=<?= urlencode($id) ?>"><?= classroomEscape($g['name']) ?></a> · <?= count($g['students']) ?> <?= count($g['students'])===1?'elev':'elever' ?></p><?php endforeach; ?></section>
<section class="panel"><h2>Dela ut quiz, flashcards eller PDF</h2><?php if (!$classes): ?><p>Skapa en klass först.</p><?php else: ?><form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="assign">
<label for="target">Till klass eller grupp</label><select id="target" name="target" required><optgroup label="Klasser"><?php foreach ($classes as $id=>$c): ?><option value="classes:<?= classroomEscape($id) ?>"><?= classroomEscape($c['name']) ?></option><?php endforeach; ?></optgroup><optgroup label="Grupper"><?php foreach ($groups as $id=>$g): ?><option value="groups:<?= classroomEscape($id) ?>"><?= classroomEscape($g['name']) ?></option><?php endforeach; ?></optgroup></select>
<label for="content">Innehåll</label><select id="content" name="content" required><option value="">Välj innehåll</option><option value="material">Ladda upp PDF</option>
<?php foreach ($quizzes ?? [] as $id=>$quiz): if (($quiz['teacher_id'] ?? '')!==$teacher) continue; $candidate=['kind'=>'quiz','content_id'=>$id,'teacher_id'=>$teacher]; $live=classroomContent($candidate); if (!$live) continue; ?><option value="quiz:<?= classroomEscape($id) ?>">Quiz: <?= classroomEscape($live['title']) ?></option><?php if (!empty($live['settings']['generate_flashcards'])): ?><option value="cards:<?= classroomEscape($id) ?>">Flashcards: <?= classroomEscape($live['title']) ?></option><?php endif; endforeach; ?>
<?php foreach ($decks ?? [] as $id=>$deck): if (($deck['teacher_id'] ?? '')!==$teacher || ($deck['active'] ?? true)===false) continue; ?><option value="deck:<?= classroomEscape($id) ?>">Flashcards: <?= classroomEscape($deck['title']) ?></option><?php endforeach; ?></select>
<div id="pdf-fields" hidden><label for="pdf-title">Materialets titel</label><input id="pdf-title" name="title" maxlength="100"><label for="pdf">PDF, högst 20 MB</label><input id="pdf" type="file" name="pdf" accept="application/pdf,.pdf"><p class="muted">Serverns uppladdningsgräns kan vara lägre än 20 MB.</p></div><p><button>Dela ut</button></p></form><?php endif; ?></section>
<section class="panel"><h2>Utdelat och övningsöversikt</h2><p class="muted">Quiz visar antal rätt och fel svar under försöket, inklusive repetition. Flashcards räknas när kort vänds. Hämtad PDF betyder att filen har begärts via appen.</p>
<?php if (!$assignments): ?><p>Inget utdelat ännu.</p><?php endif; ?>
<?php foreach (array_reverse($assignments,true) as $aid=>$a): $target=$data[$a['target_type']][$a['target_id']]['name'] ?? 'Borttagen grupp'; ?>
<details <?= ($_GET['assignment'] ?? '')===$aid?'open':'' ?>><summary><strong><?= classroomEscape($a['title']) ?></strong> · <?= classroomEscape($target) ?> · <?= ['quiz'=>'Quiz','cards'=>'Flashcards','deck'=>'Flashcards','material'=>'PDF'][$a['kind']] ?></summary><div class="table-wrap"><table><thead><tr><th>Elev</th><th>Status</th><th>Övning</th></tr></thead><tbody>
<?php $recipients=classroomRecipients($data,$a); foreach ($data['attempts'] ?? [] as $attempt) if ($attempt['assignment_id']===$aid && isset($data['students'][$attempt['student_id']]) && $data['students'][$attempt['student_id']]['teacher_id']===$teacher) $recipients[$attempt['student_id']]=$data['students'][$attempt['student_id']];
foreach ($recipients as $sid=>$s): $rows=classroomAttempts($data,$aid,$sid); $latest=$rows[0] ?? null; ?>
<tr><td><?= classroomEscape($s['name']) ?><?= empty($s['active'])?' (borttagen)':'' ?></td><td><?= classroomStatus($rows,$a['kind']) ?></td><td>
<?php if ($latest && $a['kind']==='quiz'): ?>Senaste: <?= $latest['right'] ?> rätt · <?= $latest['wrong'] ?> fel · <?= $latest['completed']?'Klart':'Påbörjat' ?><details><summary><?= count($rows) ?> försök</summary><?php foreach ($rows as $r): ?><p><?= classroomEscape((new DateTimeImmutable($r['updated']))->setTimezone(new DateTimeZone('Europe/Stockholm'))->format('Y-m-d H:i')) ?> · <?= $r['right'] ?> rätt · <?= $r['wrong'] ?> fel · <?= $r['completed']?'Klart':'Påbörjat' ?></p><?php endforeach; ?></details>
<?php elseif ($latest && in_array($a['kind'],['cards','deck'],true)): ?><?= max(array_column($rows,'cards')) ?> kort som mest per övning · <?= count($rows) ?> övningar<?php elseif ($latest): ?>Hämtad<?php else: ?>—<?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div></details><?php endforeach; ?></section>
<script>const content=document.getElementById('content'); if(content){const update=()=>{const pdf=content.value==='material';document.getElementById('pdf-fields').hidden=!pdf;document.getElementById('pdf').required=pdf;document.getElementById('pdf-title').required=pdf;};content.addEventListener('change',update);update();}</script>
</main></body></html>
