<?php
// play/flashcards.php — Flashcard player
$quizId = $_GET['id'] ?? '';
$studentName = $_GET['name'] ?? '';
if (!$quizId) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flashcards</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../engine/themes.css">
    <script src="../engine/tts.js"></script>
    <style>
        .card { perspective: 1000px; cursor: pointer; }
        .card-inner { transition: transform 0.5s; transform-style: preserve-3d; position: relative; }
        .card.flipped .card-inner { transform: rotateY(180deg); }
        .card-front, .card-back { backface-visibility: hidden; position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .card-back { transform: rotateY(180deg); }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<div class="max-w-lg mx-auto p-4">
    <div class="flex items-center justify-between mb-6">
        <a href="index.php?id=<?= urlencode($quizId) ?>" class="text-blue-600 hover:underline text-sm">&larr; Tillbaka</a>
        <span id="counter" class="text-sm" style="color: var(--text-secondary)">1/0</span>
    </div>

    <div class="card w-full h-64 mb-6" id="card" onclick="flipCard()">
        <div class="card-inner w-full h-full rounded-xl" style="background: var(--card-bg); border: 1px solid var(--border)">
            <div class="card-front text-center">
                <p id="front-text" class="text-2xl font-bold" style="color: var(--text-primary)"></p>
            </div>
            <div class="card-back text-center">
                <p id="back-text" class="text-xl" style="color: var(--accent)"></p>
            </div>
        </div>
    </div>

    <div class="flex justify-center gap-4">
        <button onclick="prevCard()" class="px-6 py-2 rounded-lg border text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">&larr; Föregående</button>
        <button onclick="nextCard()" class="px-6 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700">Nästa &rarr;</button>
    </div>
</div>

<script>
const QUIZ_ID = <?= json_encode($quizId) ?>;
let items = [];
let currentIndex = 0;
let quizSettings = null;
let quizType = null;

fetch('../api/quiz-data.php?id=' + encodeURIComponent(QUIZ_ID))
    .then(r => r.json())
    .then(data => {
        quizSettings = data.settings;
        quizType = data.type;
        items = shuffle(data.items || []);
        if (items.length > 0) showCard();
        document.getElementById('counter').textContent = `1/${items.length}`;

        // TTS on load
        if (data.settings?.tts_enabled) {
            const item = items[0];
            if (data.type === 'glossary') speakGlossary(item.sentence, item.word, data.settings.language);
            else speakText(item.concept || item.description, data.settings.language);
        }
    });

function shuffle(arr) {
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
}

function showCard() {
    const card = document.getElementById('card');
    card.classList.remove('flipped');
    const item = items[currentIndex];
    // Front: word/concept, Back: translation/description
    document.getElementById('front-text').textContent = item.word || item.concept || '';
    document.getElementById('back-text').textContent = item.translation || item.description || '';
    document.getElementById('counter').textContent = `${currentIndex + 1}/${items.length}`;
}

function flipCard() {
    document.getElementById('card').classList.toggle('flipped');
}

function nextCard() {
    if (currentIndex < items.length - 1) {
        currentIndex++;
        showCard();
    }
}

function prevCard() {
    if (currentIndex > 0) {
        currentIndex--;
        showCard();
    }
}

document.addEventListener('keydown', e => {
    if (e.key === ' ' || e.key === 'Enter') flipCard();
    if (e.key === 'ArrowRight') nextCard();
    if (e.key === 'ArrowLeft') prevCard();
});
</script>
</body>
</html>
