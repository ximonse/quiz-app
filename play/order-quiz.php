<?php
$id=$_GET['id']??'';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom.php';
classroomPlayer($id,'quiz');
$name=classroomStudent()['name'] ?? ($_GET['name']??'');
if (!$id || !$name) { header('Location: index.php?id='.urlencode($id)); exit; }
$v=fn($file)=>filemtime(__DIR__.'/../engine/'.$file);
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ordningsquiz</title>
<link rel="stylesheet" href="../engine/image-choice.css?v=<?= $v('image-choice.css') ?>"><link rel="stylesheet" href="../engine/player.css?v=<?= $v('player.css') ?>"><link rel="stylesheet" href="../engine/sort.css?v=<?= $v('sort.css') ?>">
<script src="../engine/browser-id.js?v=<?= $v('browser-id.js') ?>"></script><script src="../engine/tts.js?v=<?= $v('tts.js') ?>"></script><script src="../engine/hold-speak.js?v=<?= $v('hold-speak.js') ?>"></script><script src="../engine/drag-board.js?v=<?= $v('drag-board.js') ?>"></script><script src="../engine/order-engine.js?v=<?= $v('order-engine.js') ?>"></script><script src="../engine/order-player.js?v=<?= $v('order-player.js') ?>" defer></script><script src="../engine/result-save.js?v=1"></script></head>
<body><?php classroomPlayerBar('index.php?id='.urlencode($id)); ?><main id="order-player" class="player-main sort-main" data-id="<?= htmlspecialchars($id,ENT_QUOTES) ?>" data-name="<?= htmlspecialchars($name,ENT_QUOTES) ?>" data-preview="<?= isset($_GET['preview'])?'1':'0' ?>">
<header class="player-header"><div class="player-title"><h1 id="quiz-title">Laddar…</h1><p id="progress"></p></div><button type="button" id="mute" class="mute-button" hidden></button></header><p class="eyebrow" id="preview-label"></p>
<section class="question-card" id="question-card" hidden><h2 id="question" class="speakable"></h2><div class="info-image" id="info-image"></div><p id="instructions"></p>
<div id="board" class="sort-board"><div id="track" class="order-track"><div class="order-labels"><span data-label="start" class="speakable"></span><span data-label="middle" class="speakable"></span><span data-label="end" class="speakable"></span></div>
<div id="line" class="order-line" data-zone="line" tabindex="0" aria-label="Linjen"></div></div>
<div id="tray" class="sort-tray" data-zone="tray" tabindex="0" aria-label="Objekt som inte ligger på linjen"></div></div>
<p id="feedback" class="feedback" role="status" aria-live="polite"></p>
<div class="actions"><button id="check">Klar</button><button id="next" hidden>Nästa</button></div></section><p id="player-error" class="error" role="alert"></p></main></body></html>
