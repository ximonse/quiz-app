<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
$data=classroomRead(); $teacher=getCurrentTeacherID();
$classes=array_filter($data['classes'] ?? [],fn($c)=>$c['teacher_id']===$teacher);
$students=array_filter($data['students'] ?? [],fn($s)=>$s['teacher_id']===$teacher && !empty($s['active']));
$issued=$_SESSION['classroom_cards'] ?? [];
if (($issued['expires'] ?? 0)<time()) { unset($_SESSION['classroom_cards']); $issued=[]; }
$pins=[]; foreach ($issued['cards'] ?? [] as $card) $pins[$card['id']]=$card['pin'];
$new=isset($_GET['new']); $class=(string)($_GET['class'] ?? '');
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Elevkort</title><link rel="stylesheet" href="../assets/classroom.css"><script src="../assets/vendor/qrcode-1.0.0.min.js"></script></head><body class="classroom"><main>
<header class="no-print"><h1>Inloggningskort</h1><nav><a href="classes.php">Klasser och uppgifter</a><button onclick="window.print()">Skriv ut</button></nav></header>
<section class="panel no-print"><p>Skanna QR-koden och skriv PIN, eller skriv de tre orden och PIN. PIN finns aldrig i QR-koden.</p><p>Nya PIN-koder visas i 15 minuter efter att elever skapats eller inloggningen återställts. På övriga kort fyller du i elevens PIN för hand.</p>
<form><label for="class">Klass</label><select id="class" name="class"><option value="">Alla klasser</option><?php foreach ($classes as $id=>$c): ?><option value="<?= classroomEscape($id) ?>" <?= $id===$class?'selected':'' ?>><?= classroomEscape($c['name']) ?></option><?php endforeach; ?></select><label><input type="checkbox" name="new" value="1" <?= $new?'checked':'' ?>>Bara nya kort</label><button class="secondary">Visa kort</button></form></section>
<div class="print-grid">
<?php $count=0; foreach ($students as $id=>$s): if (($class!=='' && $s['class_id']!==$class) || ($new && !isset($pins[$id]))) continue; $count++; ?>
<section class="login-card"><h2><?= classroomEscape($s['name']) ?></h2><p><?= classroomEscape($classes[$s['class_id']]['name'] ?? '') ?></p><div class="qr" data-login="../student/?qr=<?= classroomEscape($s['qr']) ?>"></div><p class="credentials"><?= classroomEscape($s['code']) ?></p><p class="credentials">PIN: <?= classroomEscape($pins[$id] ?? '____') ?></p><p>Elevinloggning: <span class="login-address"></span></p></section>
<?php endforeach; ?></div><?php if (!$count): ?><p>Inga kort att visa. Välj en klass eller skapa nya inloggningskort i klasshanteringen.</p><?php endif; ?>
<script>document.querySelectorAll('[data-login]').forEach(el=>{const url=new URL(el.dataset.login,location.href);if(typeof QRCode==='function')new QRCode(el,{text:url.href,width:160,height:160});else el.textContent='QR-koden kunde inte laddas. Använd de tre orden och PIN.';});document.querySelectorAll('.login-address').forEach(el=>el.textContent=new URL('../student/',location.href).href);</script>
</main></body></html>
