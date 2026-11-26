<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom-admin.php';
require_once __DIR__.'/../includes/classroom-practice.php';
require_once __DIR__.'/../includes/teacher-shell.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }
header('Cache-Control: no-store');
$teacher=getCurrentTeacherID(); $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    requireValidCsrf();
    try {
        $_SESSION['classroom_message']=classroomAdminAction($teacher,$_POST,$_FILES);
        // Behåll lärarens klassfilter över omdirigeringen, så listan ser likadan ut efteråt.
        $query=array_filter(['cards'=>in_array($_POST['action'] ?? '',['students','reset','reset_many'],true)?'1':'','class'=>(string)($_POST['class'] ?? '')]);
        header('Location: classes.php'.($query?'?'.http_build_query($query):'')); exit;
    }
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
function panelStart(string $id, string $title, bool $force=false): void { echo '<details class="panel" id="panel-'.$id.'"'.($force?' data-force-open':'').' open><summary><h2>'.classroomEscape($title).'</h2></summary><div class="panel-body">'; }
function panelEnd(): void { echo '</div></details>'; }
function classOptions(array $classes, string $selected=''): void { foreach ($classes as $id=>$c) echo '<option value="'.classroomEscape($id).'" '.($id===$selected?'selected':'').'>'.classroomEscape($c['name']).'</option>'; }
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Klasser</title><link rel="stylesheet" href="../assets/classroom.css"><link rel="stylesheet" href="../assets/teacher-shell.css"></head><body class="classroom"><main>
<?php teacherShell('klasser','Klasser','Klasser, elever, grupper och det du delat ut.'); ?>
<?php if ($message): ?><p class="notice" role="status"><?= classroomEscape($message) ?> <?php if (isset($_GET['cards'])): ?><a href="cards.php?new=1">Visa och skriv ut nya inloggningskort</a> · PIN visas i 15 minuter.<?php endif; ?></p><?php endif; ?>
<?php if ($error): ?><p class="notice error" role="alert"><?= classroomEscape($error) ?></p><?php endif; ?>
<div class="grid"><?php panelStart('classes','Klasser'); ?><?php if (!$classes): ?><p>Ingen klass ännu. <a href="#panel-new-class">Skapa klass längst ner på sidan</a>.</p><?php endif; ?>
<?php foreach ($classes as $cid=>$c): $count=count(array_filter($students,fn($s)=>$s['class_id']===$cid));
$confirm='Ta bort klassen '.$c['name'].($count?' och dess '.$count.($count===1?' elev':' elever'):'').'?'.($count?' Eleverna förlorar inloggning, grupptillhörigheter och tillgång till uppgifter.':'').' Tidigare övningsresultat sparas för dig.'; ?>
<div class="class-item"><div class="class-item-heading"><span><a href="class.php?id=<?= classroomEscape(urlencode($cid)) ?>"><?= classroomEscape($c['name']) ?></a> · <?= $count ?> <?= $count===1?'elev':'elever' ?></span><details class="class-manage"><summary>Hantera <?= classroomEscape($c['name']) ?></summary>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="class_rename"><input type="hidden" name="class_id" value="<?= classroomEscape($cid) ?>"><label for="rename-<?= classroomEscape($cid) ?>">Nytt klassnamn</label><input id="rename-<?= classroomEscape($cid) ?>" name="name" value="<?= classroomEscape($c['name']) ?>" maxlength="100" required><p><button class="secondary">Byt namn</button></p></form>
<form method="post" data-confirm="<?= classroomEscape($confirm) ?>"><?= csrfField() ?><input type="hidden" name="class_id" value="<?= classroomEscape($cid) ?>"><p><button name="action" value="class_delete" class="secondary">Ta bort klass</button></p></form></details></div></div>
<?php endforeach; ?><?php panelEnd(); ?>
<?php panelStart('pupils-add','Lägg till elever'); ?><?php if (!$classes): ?><p><a href="#panel-new-class">Skapa en klass först</a>.</p><?php else: ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="students"><label for="new-class">Klass</label><select id="new-class" name="class_id"><?php classOptions($classes); ?></select><label for="names">Elevnamn, ett per rad</label><textarea id="names" name="names" rows="4" required placeholder="Alex&#10;Kim"></textarea><p><button>Skapa elever och inloggningskort</button></p></form><?php endif; ?><?php panelEnd(); ?></div>
<?php panelStart('pupils','Elever'); ?><form method="get"><label for="class-filter">Visa klass</label><select id="class-filter" name="class"><option value="">Alla klasser</option><?php classOptions($classes,$classFilter); ?></select><p><button class="secondary">Visa</button></p></form>
<?php $visible=array_filter($students,fn($s)=>$classFilter===''||$s['class_id']===$classFilter); ?>
<div class="table-wrap"><table><thead><tr><th><input type="checkbox" id="pick-all" form="bulk-reset" aria-label="Markera alla elever i listan"></th><th>Elev</th><th>Klass</th><th>Inloggning</th><th>Hantera</th></tr></thead><tbody>
<?php foreach ($visible as $id=>$s): ?>
<tr><td><input type="checkbox" class="pick" name="student_ids[]" value="<?= classroomEscape($id) ?>" form="bulk-reset" aria-label="Välj <?= classroomEscape($s['name']) ?>"></td><td><a href="pupil.php?id=<?= classroomEscape(urlencode($id)) ?>"><?= classroomEscape($s['name']) ?></a></td><td><a href="class.php?id=<?= classroomEscape(urlencode($s['class_id'])) ?>"><?= classroomEscape($classes[$s['class_id']]['name'] ?? '') ?></a></td><td><?= classroomEscape($s['code']) ?></td><td><details><summary>Hantera <?= classroomEscape($s['name']) ?></summary>
<form method="post"><?= csrfField() ?><input type="hidden" name="action" value="move"><input type="hidden" name="student_id" value="<?= classroomEscape($id) ?>"><label for="move-<?= classroomEscape($id) ?>">Flytta till klass</label><select id="move-<?= classroomEscape($id) ?>" name="class_id"><?php classOptions($classes,$s['class_id']); ?></select><p><button class="secondary">Flytta elev</button></p></form>
<form method="post" onsubmit="return confirm('Skapa ny PIN och QR-kod? Den gamla QR-koden och pågående inloggningar slutar fungera.')"><?= csrfField() ?><input type="hidden" name="student_id" value="<?= classroomEscape($id) ?>"><button name="action" value="reset" class="secondary">Ny PIN och QR-kod</button></form>
<form method="post" onsubmit="return confirm('Ta bort eleven? Eleven förlorar inloggning, grupptillhörigheter och tillgång till uppgifter. Tidigare övningsresultat sparas för dig.')"><?= csrfField() ?><input type="hidden" name="student_id" value="<?= classroomEscape($id) ?>"><p><button name="action" value="remove" class="secondary">Ta bort elev</button></p></form></details></td></tr>
<?php endforeach; ?><?php if (!$visible): ?><tr><td colspan="5"><?= $students?'Inga elever i den valda klassen.':'Inga elever ännu.' ?></td></tr><?php endif; ?></tbody></table></div>
<?php if ($visible): ?><form method="post" id="bulk-reset"><?= csrfField() ?><input type="hidden" name="action" value="reset_many"><input type="hidden" name="class" value="<?= classroomEscape($classFilter) ?>"><p><button class="secondary">Ny PIN och QR-kod för valda</button> <span class="muted">Markera eleverna i listan. Filtrera på klass och markera alla för en hel klass.</span></p></form><?php endif; ?><?php panelEnd(); ?>
<?php panelStart('groups','Grupper',isset($_GET['group'])); ?><p class="muted">Välj elever från en eller flera klasser. En elev kan ingå i flera grupper.</p>
<?php $editId=(string)($_GET['group'] ?? ''); $edit=$groups[$editId] ?? ['name'=>'','students'=>[]]; ?>
<?php foreach ($groups as $id=>$g): ?><p><a href="?group=<?= urlencode($id) ?>"><?= classroomEscape($g['name']) ?></a> · <?= count($g['students']) ?> <?= count($g['students'])===1?'elev':'elever' ?></p><?php endforeach; ?>
<details <?= $editId?'open':'' ?>><summary><?= $editId?'Redigera grupp':'Skapa grupp' ?></summary><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="group"><input type="hidden" name="group_id" value="<?= classroomEscape(isset($groups[$editId])?$editId:'') ?>"><label for="group-name">Gruppnamn</label><input id="group-name" name="name" value="<?= classroomEscape($edit['name']) ?>" required maxlength="100"><div class="members">
<?php foreach ($classes as $cid=>$c): ?><fieldset><legend><?= classroomEscape($c['name']) ?></legend><?php foreach ($students as $id=>$s): if ($s['class_id']!==$cid) continue; ?><label><input type="checkbox" name="members[]" value="<?= classroomEscape($id) ?>" <?= in_array($id,$edit['students'],true)?'checked':'' ?>><?= classroomEscape($s['name']) ?></label><?php endforeach; ?></fieldset><?php endforeach; ?></div><p><button><?= $editId?'Spara grupp':'Skapa grupp' ?></button> <?php if ($editId): ?><a href="classes.php">Ny grupp</a><?php endif; ?></p></form></details><?php panelEnd(); ?>
<?php panelStart('assign','Dela ut quiz, flashcards eller PDF'); ?><?php if (!$classes): ?><p><a href="#panel-new-class">Skapa en klass först</a>.</p><?php else: ?><form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="assign">
<label for="target">Till klass eller grupp</label><select id="target" name="target" required><option value="" selected>Välj klass eller grupp</option><optgroup label="Klasser"><?php foreach ($classes as $id=>$c): ?><option value="classes:<?= classroomEscape($id) ?>"><?= classroomEscape($c['name']) ?></option><?php endforeach; ?></optgroup><optgroup label="Grupper"><?php foreach ($groups as $id=>$g): ?><option value="groups:<?= classroomEscape($id) ?>"><?= classroomEscape($g['name']) ?></option><?php endforeach; ?></optgroup></select>
<label for="content">Innehåll</label><select id="content" name="content" required><option value="">Välj innehåll</option><option value="material">Ladda upp PDF</option>
<?php foreach ($quizzes ?? [] as $id=>$quiz): if (($quiz['teacher_id'] ?? '')!==$teacher) continue; $candidate=['kind'=>'quiz','content_id'=>$id,'teacher_id'=>$teacher]; $live=classroomContent($candidate); if (!$live) continue; ?><option value="quiz:<?= classroomEscape($id) ?>">Quiz: <?= classroomEscape($live['title']) ?></option><?php if (!empty($live['settings']['generate_flashcards'])): ?><option value="cards:<?= classroomEscape($id) ?>">Flashcards: <?= classroomEscape($live['title']) ?></option><?php endif; endforeach; ?>
<?php foreach ($decks ?? [] as $id=>$deck): if (($deck['teacher_id'] ?? '')!==$teacher || ($deck['active'] ?? true)===false) continue; ?><option value="deck:<?= classroomEscape($id) ?>">Flashcards: <?= classroomEscape($deck['title']) ?></option><?php endforeach; ?></select>
<div id="pdf-fields" hidden><label for="pdf-title">Materialets titel</label><input id="pdf-title" name="title" maxlength="100"><label for="pdf">PDF, högst 20 MB</label><input id="pdf" type="file" name="pdf" accept="application/pdf,.pdf"><p class="muted">Serverns uppladdningsgräns kan vara lägre än 20 MB.</p></div><p><button>Dela ut</button></p></form><?php endif; ?><?php panelEnd(); ?>
<?php panelStart('overview','Utdelat och resultat',isset($_GET['assignment'])); ?><p class="muted">Quiz visar antal rätt och fel svar under försöket, inklusive repetition. Flashcards räknas när kort vänds. Hämtad PDF betyder att filen har begärts via appen.</p>
<?php if (!$assignments): ?><p>Inget utdelat ännu.</p><?php endif; ?>
<?php foreach (array_reverse($assignments,true) as $aid=>$a): $target=$data[$a['target_type']][$a['target_id']]['name'] ?? ($a['target_type']==='classes'?'Borttagen klass':'Borttagen grupp'); ?>
<details <?= ($_GET['assignment'] ?? '')===$aid?'open':'' ?>><summary><strong><?= classroomEscape($a['title']) ?></strong> · <?= classroomEscape($target) ?> · <?= ['quiz'=>'Quiz','cards'=>'Flashcards','deck'=>'Flashcards','material'=>'PDF'][$a['kind']] ?></summary>
<p><a href="followup.php?assignment=<?= rawurlencode($aid) ?>">Följ upp utdelningen</a></p>
<?php if ($a['kind']==='quiz' && ($quizzes[$a['content_id']]['teacher_id'] ?? '')===$teacher): ?><p><a href="stats.php?id=<?= classroomEscape(urlencode($a['content_id'])) ?>">Statistik för quizet: snitt och svåraste frågorna</a> <span class="muted">Räknar alla som övat på quizet, även utanför den här utdelningen.</span></p><?php endif; ?>
<div class="table-wrap"><table><thead><tr><th>Elev</th><th>Status</th><th>Övning</th></tr></thead><tbody>
<?php $recipients=classroomAssignmentPupils($data,$a,$teacher);
foreach ($recipients as $sid=>$s): $rows=classroomAttempts($data,$aid,$sid); $latest=$rows[0] ?? null; ?>
<tr><td><a href="pupil.php?id=<?= classroomEscape(urlencode($sid)) ?>"><?= classroomEscape($s['name']) ?></a><?= empty($s['active'])?' (borttagen)':'' ?></td><td><?= classroomStatus($rows,$a['kind']) ?></td><td>
<?php if ($latest && $a['kind']==='quiz'): ?>Senaste: <?= $latest['right'] ?> rätt · <?= $latest['wrong'] ?> fel · <?= $latest['completed']?'Klart':'Påbörjat' ?><details><summary><?= count($rows) ?> försök</summary><?php foreach ($rows as $r): ?><p><?= classroomEscape((new DateTimeImmutable($r['updated']))->setTimezone(new DateTimeZone('Europe/Stockholm'))->format('Y-m-d H:i')) ?> · <?= $r['right'] ?> rätt · <?= $r['wrong'] ?> fel · <?= $r['completed']?'Klart':'Påbörjat' ?></p><?php endforeach; ?></details>
<?php elseif ($latest && in_array($a['kind'],['cards','deck'],true)): ?><?= max(array_column($rows,'cards')) ?> kort som mest per övning · <?= count($rows) ?> övningar<?php elseif ($latest): ?>Hämtad<?php else: ?>—<?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div></details><?php endforeach; ?><?php panelEnd(); ?>
<?php panelStart('new-class','Skapa klass'); ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="class"><label for="class-name">Klassnamn</label><input id="class-name" name="name" maxlength="100" required><p><button>Skapa klass</button></p></form><?php panelEnd(); ?>
<script>
// Bekräftelsetexter med elev- och klassnamn ligger i data-confirm, aldrig inline i onsubmit.
document.querySelectorAll('form[data-confirm]').forEach(f=>f.addEventListener('submit',e=>{if(!confirm(f.dataset.confirm))e.preventDefault();}));
const bulk=document.getElementById('bulk-reset'),pickAll=document.getElementById('pick-all');
if(bulk){
  const picks=()=>[...document.querySelectorAll('.pick')],chosen=()=>picks().filter(p=>p.checked);
  if(pickAll){
    pickAll.addEventListener('change',()=>picks().forEach(p=>{p.checked=pickAll.checked;}));
    picks().forEach(p=>p.addEventListener('change',()=>{pickAll.checked=chosen().length===picks().length;}));
  }
  bulk.addEventListener('submit',e=>{
    const n=chosen().length;
    if(!n){e.preventDefault();alert('Markera minst en elev i listan först.');return;}
    if(!confirm('Skapa ny PIN och QR-kod för '+n+(n===1?' elev':' elever')+'? De gamla QR-koderna och pågående inloggningar slutar fungera.'))e.preventDefault();
  });
}
const content=document.getElementById('content'); if(content){const update=()=>{const pdf=content.value==='material';document.getElementById('pdf-fields').hidden=!pdf;document.getElementById('pdf').required=pdf;document.getElementById('pdf-title').required=pdf;};content.addEventListener('change',update);update();}
// Hopfällbara moduler. Lägesminnet ligger lokalt per webbläsare, inte i servern, och synkas aldrig mellan enheter.
// Ligger sist i skriptet, så ett fel här aldrig kan slå ut bekräftelserna ovanför.
(()=>{
  const KEY='classroom-panels';
  let state={};
  try{state=JSON.parse(localStorage.getItem(KEY))||{};}catch(e){state={};}
  document.querySelectorAll('details.panel[id]').forEach(p=>{
    // En panel som sidan öppnar med flit (?group=, ?assignment=) står öppen den här gången utan att minnas det.
    if(!p.hasAttribute('data-force-open')&&typeof state[p.id]==='boolean') p.open=state[p.id];
    const head=p.querySelector(':scope>summary');
    // Bara lärarens egen klickning sparas, aldrig öppningar som sidan gör själv.
    if(head) head.addEventListener('click',()=>{state[p.id]=!p.open;try{localStorage.setItem(KEY,JSON.stringify(state));}catch(e){}});
  });
  const open=id=>{const t=id&&document.getElementById(id);if(t&&t.matches('details.panel'))t.open=true;};
  const fromHash=()=>{try{open(decodeURIComponent(location.hash.slice(1)));}catch(e){}};
  // En länk till en hopfälld panel ska öppna den, även andra gången den klickas.
  document.querySelectorAll('a[href^="#panel-"]').forEach(a=>a.addEventListener('click',()=>open(a.getAttribute('href').slice(1))));
  addEventListener('hashchange',fromHash); fromHash();
})();
</script>
</main></body></html>
