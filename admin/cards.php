<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom.php';
require_once __DIR__.'/../ai/common.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
$data=classroomRead(); $teacher=getCurrentTeacherID();
$classes=array_filter($data['classes'] ?? [],fn($c)=>$c['teacher_id']===$teacher);
$students=array_filter($data['students'] ?? [],fn($s)=>$s['teacher_id']===$teacher && !empty($s['active']));
$issued=$_SESSION['classroom_cards'] ?? [];
if (($issued['expires'] ?? 0)<time()) { unset($_SESSION['classroom_cards']); $issued=[]; }
$pins=[]; foreach ($issued['cards'] ?? [] as $card) $pins[$card['id']]=$card['pin'];
$new=isset($_GET['new']); $class=(string)($_GET['class'] ?? '');
// Adressen till elevinloggningen, samma publika bas som AI-anslutningen använder
// (QUIZ_AI_BASE_URL får peka om den). Kortet skrivs ut och läses utanför appen,
// så det ska stå den riktiga adressen, inte den lärarens webbläsare råkar visa.
$loginUrl=aiBaseUrl().'/student/';
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Elevkort</title><link rel="stylesheet" href="../assets/classroom.css"><script src="../assets/vendor/qrcode-1.0.0.min.js"></script></head><body class="classroom"><main>
<header class="no-print"><h1>Inloggningskort</h1><nav><a href="dashboard.php">Mina quiz</a><a href="classes.php">Klasser och uppgifter</a><a href="pupil.php">Hitta elev</a><button onclick="window.print()">Skriv ut</button></nav></header>
<section class="panel no-print"><p>QR-koden leder bara till elevinloggningen. Eleven skriver sedan sina tre ord och sin PIN. Varken namn, ordkod eller PIN finns i QR-koden, så ett upphittat kort avslöjar inget via skanningen.</p><p>Adressen på korten är <?= classroomEscape($loginUrl) ?>.</p><p>Nya PIN-koder visas i 15 minuter efter att elever skapats eller inloggningen återställts. På övriga kort fyller du i elevens PIN för hand.</p>
<form><label for="class">Klass</label><select id="class" name="class"><option value="">Alla klasser</option><?php foreach ($classes as $id=>$c): ?><option value="<?= classroomEscape($id) ?>" <?= $id===$class?'selected':'' ?>><?= classroomEscape($c['name']) ?></option><?php endforeach; ?></select><label><input type="checkbox" name="new" value="1" <?= $new?'checked':'' ?>>Bara nya kort</label><button class="secondary">Visa kort</button></form></section>
<div class="print-grid">
<?php $count=0; foreach ($students as $id=>$s): if (($class!=='' && $s['class_id']!==$class) || ($new && !isset($pins[$id]))) continue; $count++; ?>
<section class="login-card"><h2><?= classroomEscape($s['name']) ?></h2><p><?= classroomEscape($classes[$s['class_id']]['name'] ?? '') ?></p><div class="qr" data-login="<?= classroomEscape($loginUrl) ?>"></div><p class="credentials"><?= classroomEscape($s['code']) ?></p><p class="credentials">PIN: <?= classroomEscape($pins[$id] ?? '____') ?></p><p>Elevinloggning: <?= classroomEscape($loginUrl) ?></p></section>
<?php endforeach; ?></div><?php if (!$count): ?><p>Inga kort att visa. Välj en klass eller skapa nya inloggningskort i klasshanteringen.</p><?php endif; ?>
<script>document.querySelectorAll('[data-login]').forEach(el=>{if(typeof QRCode==='function')new QRCode(el,{text:el.dataset.login,width:160,height:160});else el.textContent='QR-koden kunde inte laddas. Skriv adressen, de tre orden och PIN.';});</script>
</main></body></html>
