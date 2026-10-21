<?php
// play/flashcards.php — Flashcard player
$quizId = $_GET['id'] ?? '';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom.php';
classroomPlayer($quizId,'cards');
$studentName = classroomStudent()['name'] ?? ($_GET['name'] ?? '');
if (!$quizId) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flashcards</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../engine/themes.css?v=<?= filemtime(__DIR__ . '/../engine/themes.css') ?>">
    <script src="../engine/tts.js?v=<?= filemtime(__DIR__ . '/../engine/tts.js') ?>"></script>
    <style>
        .card { perspective: 1000px; cursor: pointer; }
        .card-inner { transition: transform 0.5s; transform-style: preserve-3d; position: relative; }
        .card.flipped .card-inner { transform: rotateY(180deg); }
        .card-front, .card-back { backface-visibility: hidden; position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .card-back { transform: rotateY(180deg); }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<?php classroomPlayerBar(); ?>
<div class="max-w-lg mx-auto p-4">
    <div class="flex items-center justify-between mb-6">
        <a href="index.php?id=<?= urlencode($quizId) ?>" class="text-sm hover:underline" style="color: var(--accent)">&larr; Start</a>
        <div class="flex items-center gap-2">
            <span id="counter" class="text-sm" style="color: var(--text-secondary)">1/0</span>
            <button id="mute-btn" onclick="toggleMute()" class="text-xs px-1.5 py-0.5 rounded border" style="background: var(--card-bg); color: var(--text-secondary); border-color: var(--border)"></button>
        </div>
    </div>

    <div class="card w-full h-64 mb-6" id="card" onclick="flipCard()">
        <div class="card-inner w-full h-full rounded-xl" style="background: var(--card-bg); border: 1px solid var(--border)">
            <div class="card-front text-center">
                <div>
                    <p id="front-text" class="text-2xl font-bold" style="color: var(--text-primary)"></p>
                    <button onclick="speakFront(event)" class="mt-3 text-xs px-2 py-1 rounded border" style="background: var(--card-bg); color: var(--text-secondary); border-color: var(--border)" title="Lyssna på framsidan">🔊</button>
                </div>
            </div>
            <div class="card-back text-center">
                <div>
                    <p id="back-text" class="text-xl" style="color: var(--accent)"></p>
                    <button onclick="speakBack(event)" class="mt-3 text-xs px-2 py-1 rounded border" style="background: var(--card-bg); color: var(--text-secondary); border-color: var(--border)" title="Lyssna på baksidan">🔊</button>
                </div>
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
        const baseItems = data.items || [];
        if (quizSettings?.flashcard_mix_directions) {
            // En gång från varje håll per kort: dubblera listan och tagga varje kopia
            // med sin riktning och ursprungsindex. Faktaquiz: concept/description,
            // glosor: forward (glosord -> översättning) / reverse (översättning -> glosord).
            const dirs = quizType === 'fact' ? ['concept', 'description'] : ['forward', 'reverse'];
            const doubled = baseItems.flatMap((item, i) => dirs.map(d => ({...item, _dir: d, _origIndex: i})));
            items = shuffleNoAdjacentDuplicates(doubled, c => c._origIndex);
        } else {
            items = shuffle(baseItems);
        }
        if (items.length > 0) showCard();
        document.getElementById('counter').textContent = `1/${items.length}`;
    });

function renderMuteButton() {
    const btn = document.getElementById('mute-btn');
    const muted = ttsIsMuted();
    btn.textContent = muted ? '🔇' : '🔊';
    btn.title = muted ? 'Slå på uppläsning' : 'Stäng av uppläsning';
}

function toggleMute() {
    ttsSetMuted(!ttsIsMuted());
    renderMuteButton();
}

renderMuteButton();

function shuffle(arr) {
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
}

// Som shuffle(), men ser till att de två kopiorna av samma begrepp
// (begrepp→beskrivning / beskrivning→begrepp) aldrig hamnar direkt efter
// varandra, genom att byta plats med nästa icke-krockande kort vid behov.
function shuffleNoAdjacentDuplicates(arr, keyFn) {
    shuffle(arr);
    for (let i = 1; i < arr.length; i++) {
        if (keyFn(arr[i]) !== keyFn(arr[i - 1])) continue;
        let swapWith = -1;
        for (let j = i + 1; j < arr.length; j++) {
            if (keyFn(arr[j]) !== keyFn(arr[i - 1])) { swapWith = j; break; }
        }
        if (swapWith !== -1) {
            [arr[i], arr[swapWith]] = [arr[swapWith], arr[i]];
        }
    }
    return arr;
}

// Glosor: glosord/mening är på quizets språk, översättningen alltid på svenska.
function isGlossReverse(item) { return quizType === 'glossary' && item._dir === 'reverse'; }
function frontText(item) {
    if (quizType === 'fact') {
        const dir = item._dir || quizSettings?.question_direction || 'concept';
        return dir === 'description' ? (item.description || '') : (item.concept || '');
    }
    return isGlossReverse(item) ? (item.translation || '') : (item.word || '');
}
function backText(item) {
    if (quizType === 'fact') {
        const dir = item._dir || quizSettings?.question_direction || 'concept';
        return dir === 'description' ? (item.concept || '') : (item.description || '');
    }
    return isGlossReverse(item) ? (item.word || '') : (item.translation || '');
}
// Faktaquiz: begreppet läses på svenska (förval) eller valt språk
// (settings.concept_language === 'same'); beskrivningen alltid på valt språk.
function factConceptLang(settings) {
    return settings && settings.concept_language === 'same' ? settings.language : 'sv';
}
function factDescriptionLang(settings) {
    return settings ? settings.language : 'sv';
}
function factDir(item) { return item._dir || quizSettings?.question_direction || 'concept'; }
function frontLang(item) {
    if (quizType === 'fact') return factDir(item) === 'description' ? factDescriptionLang(quizSettings) : factConceptLang(quizSettings);
    if (quizType !== 'glossary') return quizSettings?.language;
    return isGlossReverse(item) ? 'sv' : quizSettings?.language;
}
function backLang(item) {
    if (quizType === 'fact') return factDir(item) === 'description' ? factConceptLang(quizSettings) : factDescriptionLang(quizSettings);
    if (quizType !== 'glossary') return quizSettings?.language;
    return isGlossReverse(item) ? quizSettings?.language : 'sv';
}

function showCard() {
    const card = document.getElementById('card');
    card.classList.remove('flipped');
    const item = items[currentIndex];
    document.getElementById('front-text').textContent = frontText(item);
    document.getElementById('back-text').textContent = backText(item);
    document.getElementById('counter').textContent = `${currentIndex + 1}/${items.length}`;
    playCardTTS();
}

function playCardTTS() {
    // Faktaquiz läses bara upp när eleven trycker på en 🔊-knapp (ingen autouppspelning).
    if (!quizSettings?.tts_enabled || quizType === 'fact') return;
    const item = items[currentIndex];
    if (!item) return;
    if (quizType === 'glossary') {
        // Omvänt kort: framsidan är den svenska översättningen — svaret läses när kortet vänds.
        if (!isGlossReverse(item)) speakGlossary(item.sentence, item.word, quizSettings.language);
    }
}

// Uppspelningsknapparna är uttryckliga användarval: de låter även när
// automatisk uppläsning är avstängd (force=true).
function speakFront(e) {
    if (e) e.stopPropagation();
    const item = items[currentIndex];
    if (!item) return;
    speakText(frontText(item), frontLang(item), true);
}

function speakBack(e) {
    if (e) e.stopPropagation();
    const item = items[currentIndex];
    if (!item) return;
    speakText(backText(item), backLang(item), true);
}

function flipCard() {
    if (items[currentIndex]) window.ClassroomTracker?.card(currentIndex, items.length);
    const card = document.getElementById('card');
    card.classList.toggle('flipped');
    // Svaret läses automatiskt när det avslöjas, men bara om det är glosordet på
    // målspråket (omvänt kort). Svensk baksida läses bara via 🔊.
    if (card.classList.contains('flipped') && quizSettings?.tts_enabled && quizType === 'glossary') {
        const item = items[currentIndex];
        if (item && isGlossReverse(item)) speakText(backText(item), backLang(item));
    }
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
