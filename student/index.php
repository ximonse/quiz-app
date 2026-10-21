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
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mina uppgifter</title><link rel="stylesheet" href="../assets/classroom.css"></head><body class="classroom"><main>
<?php if (!$student): ?>
<div class="login panel"><h1>Elevinloggning</h1><p>Logga in för att se dina uppgifter.</p>
<?php if ($error): ?><p class="notice error" role="alert"><?= classroomEscape($error) ?></p><?php endif; ?>
<form method="post"><?= csrfField() ?><input type="hidden" name="qr" value="<?= classroomEscape($qr) ?>">
<?php if (!$qr): ?><label for="code">Dina tre ord</label><input id="code" name="code" placeholder="Blå Björk Hagel" autocomplete="username" autocapitalize="none" required autofocus><?php else: ?><p>QR-koden är skannad. Skriv din PIN.</p><?php endif; ?>
<label for="pin">PIN, fyra siffror</label><input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="current-password" required <?= $qr?'autofocus':'' ?>>
<p><button>Logga in</button></p></form><a href="../index.php">Lärarinloggning</a></div>
<?php else: ?>
<header><div><p class="muted">Hej <?= classroomEscape($student['name']) ?></p><h1>Mina uppgifter</h1></div><form method="post"><?= csrfField() ?><button name="action" value="logout" class="secondary">Logga ut / byt elev</button></form></header>
<div class="grid">
<?php $count=0; foreach ($data['assignments'] ?? [] as $id=>$a): if (!classroomAllowed($data,$student,$a) || !classroomContent($a)) continue; $count++; $rows=classroomAttempts($data,$id,$student['id']); ?>
<section class="panel"><p class="muted"><?= ['quiz'=>'Quiz','cards'=>'Flashcards','deck'=>'Flashcards','material'=>'PDF'][$a['kind']] ?></p><h2><?= classroomEscape($a['title']) ?></h2><p><?= classroomStatus($rows,$a['kind']) ?></p>
<a class="button" href="open.php?assignment=<?= urlencode($id) ?>"><?= $a['kind']==='material'?'Hämta PDF':'Öppna' ?></a></section>
<?php endforeach; ?></div><?php if (!$count): ?><section class="panel"><h2>Här kommer dina uppgifter</h2><p>Din lärare har inte delat ut något till dig ännu.</p></section><?php endif; ?>
<?php endif; ?></main></body></html>
