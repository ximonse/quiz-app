<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$error=''; $qr=is_string($_GET['qr'] ?? null) ? $_GET['qr'] : '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    requireValidCsrf();
    if (($_POST['action'] ?? '')==='logout') { unset($_SESSION['pupil']); session_regenerate_id(true); header('Location: ./'); exit; }
    if (classroomLogin((string)($_POST['code'] ?? ''),(string)($_POST['qr'] ?? ''),(string)($_POST['pin'] ?? ''))) { header('Location: ./'); exit; }
    $error='Inloggningen gick inte. Kontrollera kod och PIN. Efter flera fel: vänta 15 minuter eller be läraren om hjälp.';
}
$data=classroomRead(); $student=classroomStudent($data);
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mina uppgifter</title><link rel="stylesheet" href="../assets/classroom.css"></head><body class="classroom"><main<?= $student?' class="pupil-home"':'' ?>>
<?php if (!$student): ?>
<div class="login panel"><h1>Elevinloggning</h1><p>Logga in för att se dina uppgifter.</p>
<?php if ($error): ?><p class="notice error" role="alert"><?= classroomEscape($error) ?></p><?php endif; ?>
<form method="post"><?= csrfField() ?><input type="hidden" name="qr" value="<?= classroomEscape($qr) ?>">
<?php if (!$qr): ?><label for="code">Dina tre ord</label><input id="code" name="code" placeholder="Blå Björk Hagel" autocomplete="username" autocapitalize="none" required autofocus><?php else: ?><p>QR-koden är skannad. Skriv din PIN.</p><?php endif; ?>
<label for="pin">PIN, fyra siffror</label><input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="current-password" required <?= $qr?'autofocus':'' ?>>
<p><button>Logga in</button></p></form><a href="../index.php">Lärarinloggning</a></div>
<?php else: ?>
<header><div><p class="muted">Hej <?= classroomEscape($student['name']) ?></p><h1>Mina uppgifter</h1></div><form method="post"><?= csrfField() ?><button name="action" value="logout" class="secondary">Logga ut / byt elev</button></form></header>
<?php
// Default order works without JavaScript: unfinished first, newest first within each group.
$cards=[];
foreach ($data['assignments'] ?? [] as $id=>$a) {
    if (!classroomAllowed($data,$student,$a) || !($content=classroomContent($a))) continue;
    $state=classroomPupilState(classroomAttempts($data,$id,$student['id']),$a['kind']);
    $cards[]=['id'=>$id,'a'=>$a,'s'=>$state,'type'=>['quiz'=>'Quiz','cards'=>'Flashcards','deck'=>'Flashcards','material'=>'PDF'][$a['kind']],
              'subject'=>trim((string)($content['subject'] ?? '')),'created'=>(string)($a['created'] ?? '')];
}
usort($cards,fn($x,$y)=>[!$x['s']['todo'],$y['created']]<=>[!$y['s']['todo'],$x['created']]);
?>
<?php if ($cards): ?><div class="sort-bar"><label for="sort">Sortera</label><select id="sort"><option value="todo">Ogjorda först</option><option value="new">Nyaste först</option><option value="subject">Ämne</option><option value="type">Typ</option></select></div><?php endif; ?>
<ul class="assignment-list" id="assignments">
<?php foreach ($cards as $c): $a=$c['a']; $s=$c['s']; ?>
<li class="assignment <?= $s['state'] ?>" data-done="<?= $s['todo']?0:1 ?>" data-created="<?= classroomEscape($c['created']) ?>" data-subject="<?= classroomEscape($c['subject']) ?>" data-type="<?= classroomEscape($c['type']) ?>" data-title="<?= classroomEscape($a['title']) ?>">
<div class="assignment-text"><p class="assignment-meta"><?= $c['type'] ?><?= $c['subject']!=='' ? ' · '.classroomEscape($c['subject']) : '' ?></p><h2><?= classroomEscape($a['title']) ?></h2><p class="status"><?= $s['label'] ?></p></div>
<a class="button assignment-action" href="open.php?assignment=<?= urlencode($c['id']) ?>"><?= $s['action'] ?></a></li>
<?php endforeach; ?></ul><?php if (!$cards): ?><section class="panel"><h2>Här kommer dina uppgifter</h2><p>Din lärare har inte delat ut något till dig ännu.</p></section><?php endif; ?>
<script>
(() => {
    const select = document.getElementById('sort'), grid = document.getElementById('assignments');
    if (!select) return;
    const sv = (x, y) => x.localeCompare(y, 'sv');
    const newest = (x, y) => sv(y.dataset.created, x.dataset.created);
    const orders = {
        todo: (x, y) => x.dataset.done - y.dataset.done || newest(x, y),
        new: newest,
        // Cards without a subject go last.
        subject: (x, y) => (!x.dataset.subject - !y.dataset.subject) || sv(x.dataset.subject, y.dataset.subject) || x.dataset.done - y.dataset.done || sv(x.dataset.title, y.dataset.title),
        type: (x, y) => sv(x.dataset.type, y.dataset.type) || x.dataset.done - y.dataset.done || newest(x, y)
    };
    function apply() { grid.append(...[...grid.children].sort(orders[select.value] || orders.todo)); }
    try { const saved = localStorage.getItem('pupil-assignment-sort'); if (orders[saved]) select.value = saved; } catch (e) {}
    select.addEventListener('change', () => { try { localStorage.setItem('pupil-assignment-sort', select.value); } catch (e) {} apply(); });
    apply();
})();
</script>
<?php endif; ?></main></body></html>
