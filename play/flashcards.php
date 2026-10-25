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
    <script src="../engine/hold-speak.js?v=<?= filemtime(__DIR__ . '/../engine/hold-speak.js') ?>"></script>
    <style>
        :root { --bar-width: 32rem; }
        .card { perspective: 1000px; cursor: pointer; }
        .card-inner { transition: transform 0.5s; transform-style: preserve-3d; position: relative; }
        .card.flipped .card-inner { transform: rotateY(180deg); }
        .card-front, .card-back { backface-visibility: hidden; position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .card-back { transform: rotateY(180deg); }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<?php classroomPlayerBar('index.php?id='.urlencode($quizId)); ?>
<div class="max-w-lg mx-auto p-4">
    <div class="flex items-center justify-end mb-6">
        <div class="flex items-center gap-2">
            <span id="counter" class="text-sm" style="color: var(--text-secondary)">1/0</span>
            <button id="mute-btn" onclick="toggleMute()" class="text-xs px-1.5 py-0.5 rounded border" style="background: var(--card-bg); color: var(--text-secondary); border-color: var(--border)"></button>
        </div>
    </div>

    <div class="card w-full h-64 mb-6" id="card" onclick="flipCard()">
        <div class="card-inner w-full h-full rounded-xl" style="background: var(--card-bg); border: 1px solid var(--border)">
            <div class="card-front text-center">
                <div>
                    <p id="front-hint" class="text-sm mb-2 hidden" style="color: var(--text-secondary)"></p>
                    <img id="front-image" class="hidden mx-auto mb-3 max-h-28 rounded" alt="">
                    <p id="front-text" class="text-2xl font-bold" style="color: var(--text-primary)"></p>
                </div>
            </div>
            <div class="card-back text-center">
                <div>
                    <img id="back-image" class="hidden mx-auto mb-3 max-h-28 rounded" alt="">
                    <p id="back-text" class="text-xl" style="color: var(--accent)"></p>
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
        // Sort quizzes: one card per object that belongs to a field (distractors are skipped).
        const baseItems = quizType === 'sort' ? (data.items || []).flatMap(item => item.objects.filter(o => o.field).map(o => ({
            hint: item.flashcard_prompt || item.question, object: o, field: item.fields.find(f => f.id === o.field)}))) : (data.items || []);
        if (quizSettings?.flashcard_mix_directions && quizType !== 'sort') {
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
function sortLabel(entry) { return entry.title || entry.text || entry.image?.alt || ''; }
function frontText(item) {
    if (quizType === 'sort') return item.hint + ' ' + sortLabel(item.object) + '?';
    if (quizType === 'fact') {
        const dir = item._dir || quizSettings?.question_direction || 'concept';
        return dir === 'description' ? (item.description || '') : (item.concept || '');
    }
    return isGlossReverse(item) ? (item.translation || '') : (item.word || '');
}
function backText(item) {
    if (quizType === 'sort') return sortLabel(item.field);
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
    const sort = quizType === 'sort';
    document.getElementById('front-hint').classList.toggle('hidden', !sort);
    document.getElementById('front-hint').textContent = sort ? item.hint : '';
    document.getElementById('front-text').textContent = sort ? sortLabel(item.object) : frontText(item);
    document.getElementById('back-text').textContent = backText(item);
    setCardImage('front-image', sort ? item.object.image : null);
    setCardImage('back-image', sort ? item.field.image : null);
    document.getElementById('counter').textContent = `${currentIndex + 1}/${items.length}`;
    updateCardSpeech();
    playCardTTS();
}

function setCardImage(id, image) {
    const img = document.getElementById(id);
    img.classList.toggle('hidden', !image);
    if (image) { img.src = '../api/question-image.php?file=' + encodeURIComponent(image.file); img.alt = image.alt; }
    else { img.removeAttribute('src'); img.alt = ''; }
}

function playCardTTS() {
    // Faktaquiz läses bara upp när eleven håller på kortet (ingen autouppspelning).
    if (!quizSettings?.tts_enabled || quizType === 'fact') return;
    const item = items[currentIndex];
    if (!item) return;
    if (quizType === 'glossary') {
        // Omvänt kort: framsidan är den svenska översättningen — svaret läses när kortet vänds.
        if (!isGlossReverse(item)) speakGlossary(item.sentence, item.word, quizSettings.language);
    }
}

// Håll på kortet i en sekund för att höra sidan som syns (engine/hold-speak.js).
function updateCardSpeech() {
    const card = document.getElementById('card');
    const item = items[currentIndex];
    if (!item || !quizSettings?.tts_enabled) { delete card.dataset.speak; return; }
    const back = card.classList.contains('flipped');
    card.dataset.speak = back ? backText(item) : frontText(item);
    card.dataset.speakLang = (back ? backLang(item) : frontLang(item)) || 'sv';
}

function flipCard() {
    if (items[currentIndex]) window.ClassroomTracker?.card(currentIndex, items.length);
    const card = document.getElementById('card');
    card.classList.toggle('flipped');
    updateCardSpeech();
    // Svaret läses automatiskt när det avslöjas, men bara om det är glosordet på
    // målspråket (omvänt kort). Svensk baksida läses bara när eleven håller på kortet.
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
