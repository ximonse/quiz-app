<?php
$id=$_GET['id']??'';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom.php';
classroomPlayer($id,'quiz');
$name=classroomStudent()['name'] ?? ($_GET['name']??'');
if (!$id || !$name) { header('Location: index.php?id='.urlencode($id)); exit; }
$v=fn($file)=>filemtime(__DIR__.'/../engine/'.$file);
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sorteringsquiz</title>
<link rel="stylesheet" href="../engine/image-choice.css?v=<?= $v('image-choice.css') ?>"><link rel="stylesheet" href="../engine/sort.css?v=<?= $v('sort.css') ?>">
<script src="../engine/browser-id.js?v=<?= $v('browser-id.js') ?>"></script><script src="../engine/tts.js?v=<?= $v('tts.js') ?>"></script><script src="../engine/drag-board.js?v=<?= $v('drag-board.js') ?>"></script><script src="../engine/sort-engine.js?v=<?= $v('sort-engine.js') ?>"></script><script src="../engine/sort-player.js?v=<?= $v('sort-player.js') ?>" defer></script></head>
<body><?php classroomPlayerBar(); ?><main id="sort-player" class="sort-main" data-id="<?= htmlspecialchars($id,ENT_QUOTES) ?>" data-name="<?= htmlspecialchars($name,ENT_QUOTES) ?>" data-preview="<?= isset($_GET['preview'])?'1':'0' ?>">
<header class="sort-header"><div><a href="index.php?id=<?= urlencode($id) ?>">← Start</a><div class="sort-header-top"><h1 id="quiz-title">Laddar…</h1><p id="progress"></p></div><p class="eyebrow" id="preview-label"></p></div>
<button type="button" id="mute" class="mute-button" hidden></button></header>
<section class="question-card" id="question-card" hidden><h2 id="question" class="speakable"></h2><div class="info-image" id="info-image"></div><p id="instructions"></p>
<div id="board" class="sort-board"><div id="fields" class="sort-fields"></div><div id="tray" class="sort-tray" data-field="" tabindex="0" aria-label="Objekt som inte ligger i något fält"></div></div>
<p id="feedback" class="feedback" role="status" aria-live="polite"></p>
<div class="actions"><button id="check">Klar</button><button id="next" hidden>Nästa</button></div></section><p id="player-error" class="error" role="alert"></p></main></body></html>
