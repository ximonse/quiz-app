<?php
$id=$_GET['id']??'';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom.php';
classroomPlayer($id,'quiz');
$name=classroomStudent()['name'] ?? ($_GET['name']??'');
if (!$id || !$name) { header('Location: index.php?id='.urlencode($id)); exit; }
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bildvalsquiz</title>
<link rel="stylesheet" href="../engine/image-choice.css?v=<?= filemtime(__DIR__ . '/../engine/image-choice.css') ?>"><script src="../engine/browser-id.js?v=<?= filemtime(__DIR__ . '/../engine/browser-id.js') ?>"></script><script src="../engine/tts.js"></script><script src="../engine/image-choice-engine.js?v=<?= filemtime(__DIR__ . '/../engine/image-choice-engine.js') ?>"></script><script src="../engine/image-choice-player.js?v=<?= filemtime(__DIR__ . '/../engine/image-choice-player.js') ?>" defer></script></head>
<body><?php classroomPlayerBar(); ?><main id="image-player" data-id="<?= htmlspecialchars($id,ENT_QUOTES) ?>" data-name="<?= htmlspecialchars($name,ENT_QUOTES) ?>" data-preview="<?= isset($_GET['preview'])?'1':'0' ?>">
<a href="index.php?id=<?= urlencode($id) ?>">← Start</a><header><p class="eyebrow" id="preview-label"></p><h1 id="quiz-title">Laddar…</h1><p id="progress"></p></header>
<section class="question-card" id="question-card" hidden><div class="question-top"><h2 id="question"></h2><button id="listen" class="listen-button" aria-label="Läs upp frågan">🔊 Lyssna</button></div>
<div class="info-image" id="info-image"></div><p id="instructions"></p><div id="choices" class="choice-grid"></div><p id="feedback" class="feedback" role="status" aria-live="polite"></p>
<div class="actions"><button id="check">Kontrollera</button><button id="next" hidden>Nästa fråga</button></div></section><p id="player-error" class="error" role="alert"></p></main></body></html>
