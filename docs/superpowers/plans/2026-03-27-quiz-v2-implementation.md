# Quiz-app v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the monolithic multiquiz system with two clean quiz types (glossary + fact) sharing a common engine, with simplified CSV formats, time locks, compact admin UI, and student diplomas.

**Architecture:** PHP backend serves quiz data via JSON API. Shared JS engine handles queue management, answer validation, phase transitions, and TTS. One quiz player renders both types based on quiz.type. Admin CRUD in separate focused PHP files.

**Tech Stack:** PHP 8+ (backend, no framework), vanilla JS with React via CDN (frontend), JSON file storage, Tailwind CSS via CDN, Web Speech API for TTS.

**Spec:** `docs/superpowers/specs/2026-03-26-quiz-v2-design.md`

**Reusable code from current codebase:**
- `config.php` (lines 29-103): readJSON/writeJSON, auth helpers, CSRF — use as-is
- `q/index.php` (lines 358-415): levenshteinDistance + isAnswerCorrect — extract to engine/validator.js
- `q/index.php` (lines 546-680): TTS logic — extract to engine/tts.js
- `q/index.php` (lines 56-134): Theme CSS — extract to engine/themes.css

---

## Task 1: Directory structure + shared CSS/JS extraction

**Files:**
- Create: `admin/` directory
- Create: `play/` directory
- Create: `engine/validator.js`
- Create: `engine/tts.js`
- Create: `engine/themes.css`
- Source: `q/index.php` (read-only, extract from)

- [ ] **Step 1: Create directory structure**

```bash
mkdir -p admin play engine
```

- [ ] **Step 2: Extract validator.js from q/index.php**

Create `engine/validator.js` — extract levenshteinDistance (lines 358-387) and isAnswerCorrect (lines 389-415) from `q/index.php`. Wrap as module:

```javascript
// engine/validator.js — Answer validation for all quiz types

function levenshteinDistance(a, b) {
    const matrix = [];
    for (let i = 0; i <= b.length; i++) matrix[i] = [i];
    for (let j = 0; j <= a.length; j++) matrix[0][j] = j;
    for (let i = 1; i <= b.length; i++) {
        for (let j = 1; j <= a.length; j++) {
            if (b.charAt(i - 1) === a.charAt(j - 1)) {
                matrix[i][j] = matrix[i - 1][j - 1];
            } else {
                matrix[i][j] = Math.min(
                    matrix[i - 1][j - 1] + 1,
                    matrix[i][j - 1] + 1,
                    matrix[i - 1][j] + 1
                );
            }
        }
    }
    return matrix[b.length][a.length];
}

function isAnswerCorrect(userAnswer, correctAnswer, spellingMode) {
    const user = userAnswer.toLowerCase().trim().replace(/\s+/g, ' ');
    const correct = correctAnswer.toLowerCase().trim().replace(/\s+/g, ' ');

    if (user === correct) return { correct: true, type: 'exact' };

    if (spellingMode === 'puritan') return { correct: false, type: 'wrong' };

    // Easy / student_choice: suffix tolerance
    if (user.startsWith(correct) || correct.startsWith(user)) {
        const diff = Math.abs(user.length - correct.length);
        if (diff <= 3) return { correct: true, type: 'suffix' };
    }

    // Levenshtein tolerance: 20% of word length
    const maxDistance = Math.ceil(correct.length * 0.2);
    const distance = levenshteinDistance(user, correct);
    if (distance <= maxDistance && distance > 0) return { correct: true, type: 'typo' };

    return { correct: false, type: 'wrong' };
}

function isMultipleChoiceCorrect(selected, correct) {
    return selected.trim().toLowerCase() === correct.trim().toLowerCase();
}
```

- [ ] **Step 3: Extract tts.js from q/index.php**

Create `engine/tts.js` — extract speakText (lines 546-591) and speakGlossary (lines 594-675). Parameterize instead of relying on globals:

```javascript
// engine/tts.js — Text-to-speech for all quiz types

const LANG_MAP = {
    'sv': 'sv-SE', 'en': 'en-US', 'es': 'es-ES',
    'fr': 'fr-FR', 'de': 'de-DE', 'uk': 'uk'
};

let selectedVoice = null;

function setVoice(voice) {
    selectedVoice = voice;
}

function speakText(text, lang) {
    if (!('speechSynthesis' in window)) return;
    window.speechSynthesis.cancel();

    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = LANG_MAP[lang] || 'sv-SE';

    let voice = selectedVoice;
    if (!voice) {
        const voices = window.speechSynthesis.getVoices();
        const targetLang = LANG_MAP[lang] || 'sv-SE';
        voice = voices.find(v => v.lang.startsWith(targetLang.substring(0, 2)))
             || voices.find(v => v.lang === targetLang);

        if (!voice && (lang === 'de' || lang === 'fr')) {
            const langKey = lang === 'de' ? 'german|deutsch' : 'french|français';
            voice = voices.find(v =>
                v.lang.includes(lang) || v.lang.includes(lang.toUpperCase())
                || new RegExp(langKey, 'i').test(v.name)
            );
        }
    }

    if (voice) utterance.voice = voice;
    utterance.rate = 0.9;
    window.speechSynthesis.speak(utterance);
}

function speakGlossary(sentence, word, lang) {
    if (!('speechSynthesis' in window)) return;
    window.speechSynthesis.cancel();

    const u1 = new SpeechSynthesisUtterance(sentence);
    u1.lang = LANG_MAP[lang] || 'sv-SE';
    u1.rate = 0.9;

    const voices = window.speechSynthesis.getVoices();
    const targetLang = LANG_MAP[lang] || 'sv-SE';
    const voice = selectedVoice
        || voices.find(v => v.lang.startsWith(targetLang.substring(0, 2)))
        || voices.find(v => v.lang === targetLang);

    if (voice) u1.voice = voice;

    u1.onend = () => {
        setTimeout(() => {
            const u2 = new SpeechSynthesisUtterance(word);
            u2.lang = LANG_MAP[lang] || 'sv-SE';
            u2.rate = 0.85;
            if (voice) u2.voice = voice;
            window.speechSynthesis.speak(u2);
        }, 300);
    };

    window.speechSynthesis.speak(u1);
}

function stopSpeech() {
    if ('speechSynthesis' in window) window.speechSynthesis.cancel();
}
```

- [ ] **Step 4: Extract themes.css from q/index.php**

Create `engine/themes.css` — extract theme CSS variables (lines 56-134):

```css
/* engine/themes.css — Shared theme definitions */

:root {
    --bg-from: #f0f9ff;
    --bg-to: #faf5ff;
    --card-bg: #ffffff;
    --text-primary: #1f2937;
    --text-secondary: #6b7280;
    --border: #e5e7eb;
    --accent: #3b82f6;
}

body.night-mode {
    --bg-from: #000000;
    --bg-to: #1a1a1a;
    --card-bg: #1a1a1a;
    --text-primary: #ffffff;
    --text-secondary: #999999;
    --border: #444444;
    --accent: #ffffff;
}
body.night-mode .rounded-xl {
    box-shadow: 0 4px 6px rgba(255, 255, 255, 0.1);
}

body.night-magenta-mode {
    --bg-from: #0a0a0f;
    --bg-to: #1a0a1f;
    --card-bg: #150a1a;
    --text-primary: #ffffff;
    --text-secondary: #c084fc;
    --border: #4a1f5a;
    --accent: #ff00ff;
}

body.psychedelic-mode {
    --bg-from: #ff00ff;
    --bg-to: #00ffff;
    --card-bg: rgba(255, 255, 0, 0.15);
    --text-primary: #ffffff;
    --text-secondary: #ffff00;
    --border: #ff00ff;
    --accent: #00ff00;
    animation: psychedelic-pulse 8s ease-in-out infinite;
}
@keyframes psychedelic-pulse {
    0% { filter: hue-rotate(0deg) saturate(1.5); }
    25% { filter: hue-rotate(90deg) saturate(2); }
    50% { filter: hue-rotate(180deg) saturate(1.5); }
    75% { filter: hue-rotate(270deg) saturate(2); }
    100% { filter: hue-rotate(360deg) saturate(1.5); }
}
body.psychedelic-mode::before {
    content: '';
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background:
        radial-gradient(circle at 20% 50%, rgba(255, 0, 255, 0.3) 0%, transparent 50%),
        radial-gradient(circle at 80% 50%, rgba(0, 255, 255, 0.3) 0%, transparent 50%),
        radial-gradient(circle at 50% 50%, rgba(255, 255, 0, 0.2) 0%, transparent 50%);
    animation: psychedelic-move 15s ease-in-out infinite;
    pointer-events: none;
    z-index: 0;
}
@keyframes psychedelic-move {
    0%, 100% { transform: translate(0, 0) scale(1); }
    33% { transform: translate(10%, 10%) scale(1.1); }
    66% { transform: translate(-10%, 10%) scale(0.9); }
}
body.psychedelic-mode * { position: relative; z-index: 1; }
```

- [ ] **Step 5: Commit extraction**

```bash
git add engine/validator.js engine/tts.js engine/themes.css
git commit -m "feat: extract shared engine (validator, TTS, themes) from monolithic files"
```

---

## Task 2: Quiz engine — queue management + phase logic

**Files:**
- Create: `engine/quiz-engine.js`

This is the core brain. Handles shuffling, queue management, phase transitions (MC → text in hybrid), direction switching (forward → reverse), and training vs test mode.

- [ ] **Step 1: Create engine/quiz-engine.js**

```javascript
// engine/quiz-engine.js — Core quiz state machine

function QuizEngine(config) {
    // config = { items, settings, quizType }
    // quizType = 'glossary' | 'fact'

    const items = [...config.items];
    const settings = config.settings;
    const quizType = config.quizType;
    const isTest = settings.quiz_mode === 'test';

    // State
    let direction = 'forward';       // 'forward' | 'reverse'
    let phase = 'mc';                // 'mc' | 'text'  (for hybrid)
    let queue = [];
    let totalQuestions = 0;
    let correctCount = 0;
    let totalErrors = 0;
    let errors = [];                 // { item_index, given, correct }
    let sessionComplete = false;

    function getAnswerMode() {
        if (direction === 'reverse') return settings.reverse_answer_mode || 'multiple_choice';
        return settings.answer_mode || 'multiple_choice';
    }

    function getMcCount() {
        if (direction === 'reverse') return settings.reverse_mc_count || items.length;
        return settings.mc_count || items.length;
    }

    function getTextCount() {
        if (direction === 'reverse') return settings.reverse_text_count || 0;
        return settings.text_count || 0;
    }

    function buildQueue() {
        const shuffled = shuffle([...items]);
        const mode = getAnswerMode();

        if (mode === 'multiple_choice') {
            queue = shuffled.map((item, i) => ({ ...item, _index: items.indexOf(item), _phase: 'mc' }));
            phase = 'mc';
        } else if (mode === 'text_only') {
            queue = shuffled.map((item, i) => ({ ...item, _index: items.indexOf(item), _phase: 'text' }));
            phase = 'text';
        } else {
            // hybrid: mc_count items as MC, then text_count items as text
            const mcCount = Math.min(getMcCount(), shuffled.length);
            const textCount = Math.min(getTextCount(), shuffled.length);
            const mcItems = shuffled.slice(0, mcCount).map(item => ({ ...item, _index: items.indexOf(item), _phase: 'mc' }));
            const textItems = shuffled.slice(0, textCount).map(item => ({ ...item, _index: items.indexOf(item), _phase: 'text' }));
            queue = mcItems;
            // Store text items for phase 2
            queue._textQueue = textItems;
            phase = 'mc';
        }

        totalQuestions = isTest ? queue.length : queue.length + (queue._textQueue ? queue._textQueue.length : 0);
    }

    function shuffle(arr) {
        for (let i = arr.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [arr[i], arr[j]] = [arr[j], arr[i]];
        }
        return arr;
    }

    function currentItem() {
        return queue.length > 0 ? queue[0] : null;
    }

    function currentPhase() {
        return phase;
    }

    function currentDirection() {
        return direction;
    }

    function getQuestion(item) {
        if (!item) return null;
        if (quizType === 'glossary') {
            if (direction === 'forward') {
                return { prompt: item.sentence, highlight: item.word, answer: item.translation, options: buildOptions(item, 'forward') };
            } else {
                return { prompt: item.translation, highlight: null, answer: item.word, options: buildOptions(item, 'reverse') };
            }
        } else {
            // fact
            if (direction === 'forward') {
                return { prompt: item.concept, highlight: null, answer: item.description, options: buildOptions(item, 'forward') };
            } else {
                return { prompt: item.description, highlight: null, answer: item.concept, options: buildOptions(item, 'reverse') };
            }
        }
    }

    function buildOptions(item, dir) {
        if (phase === 'text') return null; // No options for text input

        let correct, wrongs;
        if (quizType === 'glossary') {
            if (dir === 'forward') {
                correct = item.translation;
                wrongs = item.wrong_options || [];
            } else {
                correct = item.word;
                wrongs = item.reverse_wrong_options || [];
                // Fallback: use other words from items
                if (wrongs.length === 0) {
                    wrongs = items.filter(i => i.word !== item.word).map(i => i.word);
                    wrongs = shuffle(wrongs).slice(0, 3);
                }
            }
        } else {
            // fact
            if (dir === 'forward') {
                correct = item.description;
                wrongs = item.wrong_options || [];
            } else {
                correct = item.concept;
                // Fallback: use other concepts from items
                wrongs = items.filter(i => i.concept !== item.concept).map(i => i.concept);
                wrongs = shuffle(wrongs).slice(0, 3);
            }
        }

        return shuffle([correct, ...wrongs.slice(0, 3)]);
    }

    function submitAnswer(answer, spellingMode) {
        const item = queue[0];
        if (!item) return null;

        const q = getQuestion(item);
        let result;

        if (phase === 'mc' || item._phase === 'mc') {
            result = { correct: isMultipleChoiceCorrect(answer, q.answer), type: 'mc' };
        } else {
            result = isAnswerCorrect(answer, q.answer, spellingMode);
        }

        if (result.correct) {
            correctCount++;
            queue.shift(); // Remove from queue
        } else {
            totalErrors++;
            errors.push({ item_index: item._index, given: answer, correct: q.answer });
            if (isTest) {
                queue.shift(); // Test mode: remove even on wrong
            } else {
                queue.push(queue.shift()); // Training: move to end
            }
        }

        // Check phase transition (hybrid: MC done → text)
        if (queue.length === 0 && getAnswerMode() === 'hybrid' && phase === 'mc' && queue._textQueue && queue._textQueue.length > 0) {
            queue = queue._textQueue;
            delete queue._textQueue;
            phase = 'text';
            return { ...result, phaseChange: true, newPhase: 'text' };
        }

        // Check direction transition (forward done → reverse)
        if (queue.length === 0 && direction === 'forward' && settings.reverse_enabled && !isTest) {
            direction = 'reverse';
            buildQueue();
            return { ...result, directionChange: true, newDirection: 'reverse' };
        }

        // Check completion
        if (queue.length === 0) {
            sessionComplete = true;
            return { ...result, complete: true };
        }

        return result;
    }

    function getProgress() {
        const answered = correctCount + totalErrors;
        return { correctCount, totalErrors, answered, totalQuestions, remaining: queue.length, direction, phase, flawless: totalErrors === 0 };
    }

    function getResults() {
        return { score: correctCount, total: correctCount + totalErrors, errors, flawless: totalErrors === 0 && sessionComplete };
    }

    function isComplete() {
        return sessionComplete;
    }

    // Initialize
    buildQueue();

    return { currentItem, currentPhase, currentDirection, getQuestion, submitAnswer, getProgress, getResults, isComplete };
}
```

- [ ] **Step 2: Commit**

```bash
git add engine/quiz-engine.js
git commit -m "feat: add quiz engine with queue, phases, directions, and training/test modes"
```

---

## Task 3: API — quiz data endpoint + save results + time lock

**Files:**
- Create: `api/quiz-data.php`
- Create: `api/save-result.php`
- Create: `api/clear-results.php`
- Existing: `config.php` (read-only)

- [ ] **Step 1: Create api/quiz-data.php**

Returns quiz data for students. Checks time lock. Returns `{ quiz, status }` where status is `open` | `not_yet` | `training_only`.

```php
<?php
// api/quiz-data.php — Serve quiz data to student player
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$quizId = $_GET['id'] ?? '';
if (!$quizId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing quiz id']);
    exit;
}

$quizzes = readJSON(DATA_DIR . '/quizzes.json');
if (!isset($quizzes[$quizId])) {
    http_response_code(404);
    echo json_encode(['error' => 'Quiz not found']);
    exit;
}

$quiz = $quizzes[$quizId];
$now = date('Y-m-d H:i');
$status = 'open';

if (!empty($quiz['settings']['time_lock'])) {
    $lock = $quiz['settings']['time_lock'];
    if (!empty($lock['opens']) && $now < $lock['opens']) {
        $status = 'not_yet';
        echo json_encode([
            'status' => $status,
            'opens' => $lock['opens'],
            'title' => $quiz['title']
        ]);
        exit;
    }
    if (!empty($lock['closes']) && $now > $lock['closes']) {
        $status = 'training_only';
    }
}

// Strip teacher-only fields before sending
$studentQuiz = [
    'id' => $quiz['id'],
    'title' => $quiz['title'],
    'type' => $quiz['type'],
    'settings' => $quiz['settings'],
    'items' => $quiz['items'],
    'status' => $status
];

echo json_encode($studentQuiz);
```

- [ ] **Step 2: Create api/save-result.php**

Saves student results. Respects time lock (training_only → don't save).

```php
<?php
// api/save-result.php — Save student quiz results
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['quiz_id']) || empty($input['student_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$quizzes = readJSON(DATA_DIR . '/quizzes.json');
$quizId = $input['quiz_id'];

if (!isset($quizzes[$quizId])) {
    http_response_code(404);
    echo json_encode(['error' => 'Quiz not found']);
    exit;
}

// Check time lock — don't save after closing
$quiz = $quizzes[$quizId];
if (!empty($quiz['settings']['time_lock']['closes'])) {
    if (date('Y-m-d H:i') > $quiz['settings']['time_lock']['closes']) {
        echo json_encode(['saved' => false, 'reason' => 'training_only']);
        exit;
    }
}

$result = [
    'student_name' => substr(trim($input['student_name']), 0, 50),
    'timestamp' => date('Y-m-d H:i:s'),
    'score' => intval($input['score']),
    'total' => intval($input['total']),
    'errors' => $input['errors'] ?? []
];

$quizzes[$quizId]['results'][] = $result;
writeJSON(DATA_DIR . '/quizzes.json', $quizzes);

echo json_encode(['saved' => true]);
```

- [ ] **Step 3: Create api/clear-results.php**

Teacher-only endpoint to clear results for a quiz (for reuse).

```php
<?php
// api/clear-results.php — Clear all results for a quiz (teacher only)
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
requireTeacher();
requireValidCsrf(true);

$input = json_decode(file_get_contents('php://input'), true);
$quizId = $input['quiz_id'] ?? '';

if (!$quizId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing quiz_id']);
    exit;
}

$quizzes = readJSON(DATA_DIR . '/quizzes.json');
if (!isset($quizzes[$quizId])) {
    http_response_code(404);
    echo json_encode(['error' => 'Quiz not found']);
    exit;
}

$quizzes[$quizId]['results'] = [];
writeJSON(DATA_DIR . '/quizzes.json', $quizzes);

echo json_encode(['success' => true]);
```

- [ ] **Step 4: Commit**

```bash
git add api/quiz-data.php api/save-result.php api/clear-results.php
git commit -m "feat: add quiz API endpoints (data, save results, clear results)"
```

---

## Task 4: Admin — dashboard (compact quiz list)

**Files:**
- Create: `admin/dashboard.php`

- [ ] **Step 1: Create admin/dashboard.php**

Compact quiz list with horizontal action buttons. Each quiz = one row. Shows title, type badge, item count, time lock status, and action buttons (copy link, edit, stats, delete).

```php
<?php
// admin/dashboard.php — Compact quiz list for teachers
session_start();
require_once __DIR__ . '/../config.php';
requireTeacher();

$quizzes = readJSON(DATA_DIR . '/quizzes.json');
$teacherId = getCurrentTeacherID();

// Filter to current teacher's quizzes
$myQuizzes = array_filter($quizzes, fn($q) => ($q['teacher_id'] ?? '') === $teacherId);
$myQuizzes = array_values($myQuizzes);

// Sort by created date, newest first
usort($myQuizzes, fn($a, $b) => strcmp($b['created'] ?? '', $a['created'] ?? ''));

$csrfToken = getCsrfToken();

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    requireValidCsrf();
    $deleteId = $_POST['quiz_id'] ?? '';
    if (isset($quizzes[$deleteId]) && ($quizzes[$deleteId]['teacher_id'] ?? '') === $teacherId) {
        unset($quizzes[$deleteId]);
        writeJSON(DATA_DIR . '/quizzes.json', $quizzes);
        header('Location: dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mina Quiz</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../engine/themes.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<div class="max-w-4xl mx-auto p-4">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Mina Quiz</h1>
        <a href="create.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">+ Skapa quiz</a>
    </div>

    <?php if (empty($myQuizzes)): ?>
        <p class="text-center py-12" style="color: var(--text-secondary)">Inga quiz ännu. Skapa ditt första!</p>
    <?php else: ?>
    <div class="space-y-1">
        <?php foreach ($myQuizzes as $quiz):
            $type = $quiz['type'] ?? 'glossary';
            $typeBadge = $type === 'glossary' ? 'glosquiz' : 'faktaquiz';
            $badgeColor = $type === 'glossary' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700';
            $itemCount = count($quiz['items'] ?? []);
            $resultCount = count($quiz['results'] ?? []);
            $itemWord = $type === 'glossary' ? 'glosor' : 'frågor';

            // Time lock status
            $lockText = 'Alltid öppen';
            if (!empty($quiz['settings']['time_lock'])) {
                $lock = $quiz['settings']['time_lock'];
                $opens = !empty($lock['opens']) ? date('j/n', strtotime($lock['opens'])) : '';
                $closes = !empty($lock['closes']) ? date('j/n', strtotime($lock['closes'])) : '';
                if ($opens && $closes) $lockText = "Öppen $opens–$closes";
                elseif ($opens) $lockText = "Öppnar $opens";
                elseif ($closes) $lockText = "Stänger $closes";
            }

            $playUrl = '../play/index.php?id=' . urlencode($quiz['id']);
        ?>
        <div class="flex items-center justify-between px-4 py-2 rounded-lg" style="background: var(--card-bg); border: 1px solid var(--border)">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                    <span class="font-medium truncate" style="color: var(--text-primary)"><?= htmlspecialchars($quiz['title']) ?></span>
                    <span class="text-xs px-2 py-0.5 rounded-full <?= $badgeColor ?>"><?= $typeBadge ?></span>
                </div>
                <div class="text-xs" style="color: var(--text-secondary)">
                    <?= $itemCount ?> <?= $itemWord ?> · <?= $lockText ?> · <?= $resultCount ?> resultat
                </div>
            </div>
            <div class="flex items-center gap-1 ml-4">
                <button onclick="copyLink('<?= htmlspecialchars($playUrl) ?>')" class="p-1.5 rounded hover:bg-gray-100 text-gray-500" title="Kopiera länk">📋</button>
                <a href="edit.php?id=<?= urlencode($quiz['id']) ?>" class="p-1.5 rounded hover:bg-gray-100 text-gray-500" title="Redigera">✏️</a>
                <a href="stats.php?id=<?= urlencode($quiz['id']) ?>" class="p-1.5 rounded hover:bg-gray-100 text-gray-500" title="Statistik">📊</a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Radera detta quiz?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="quiz_id" value="<?= htmlspecialchars($quiz['id']) ?>">
                    <button type="submit" class="p-1.5 rounded hover:bg-red-50 text-gray-500" title="Radera">🗑️</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function copyLink(path) {
    const url = window.location.origin + path;
    navigator.clipboard.writeText(url).then(() => {
        alert('Länk kopierad!');
    });
}
</script>
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add admin/dashboard.php
git commit -m "feat: add compact admin dashboard with horizontal action buttons"
```

---

## Task 5: Admin — create quiz (with CSV import + settings)

**Files:**
- Create: `admin/create.php`

- [ ] **Step 1: Create admin/create.php**

Form to create a new quiz. Step 1: choose type. Step 2: paste CSV or AI-generate. Step 3: settings. Saves to quizzes.json.

```php
<?php
// admin/create.php — Create new quiz (glossary or fact)
session_start();
require_once __DIR__ . '/../config.php';
requireTeacher();

$csrfToken = getCsrfToken();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();

    $type = $_POST['type'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $csvData = trim($_POST['csv_data'] ?? '');

    if (!$title || !$csvData || !in_array($type, ['glossary', 'fact'])) {
        $error = 'Fyll i alla fält.';
    } else {
        $items = parseCSV($csvData, $type);
        if (empty($items)) {
            $error = 'Kunde inte tolka CSV-data. Kontrollera formatet.';
        } else {
            $quizId = 'q_' . bin2hex(random_bytes(5));
            $answerMode = $_POST['answer_mode'] ?? 'multiple_choice';
            $reverseEnabled = isset($_POST['reverse_enabled']);
            $reverseAnswerMode = $_POST['reverse_answer_mode'] ?? 'multiple_choice';

            $quiz = [
                'id' => $quizId,
                'title' => $title,
                'type' => $type,
                'created' => date('Y-m-d H:i:s'),
                'teacher_id' => getCurrentTeacherID(),
                'settings' => [
                    'answer_mode' => $answerMode,
                    'mc_count' => intval($_POST['mc_count'] ?? count($items)),
                    'text_count' => intval($_POST['text_count'] ?? 0),
                    'reverse_enabled' => $reverseEnabled,
                    'reverse_answer_mode' => $reverseAnswerMode,
                    'reverse_mc_count' => intval($_POST['reverse_mc_count'] ?? count($items)),
                    'reverse_text_count' => intval($_POST['reverse_text_count'] ?? 0),
                    'quiz_mode' => $_POST['quiz_mode'] ?? 'training',
                    'tts_enabled' => isset($_POST['tts_enabled']),
                    'language' => $_POST['language'] ?? 'sv',
                    'spelling_mode' => $_POST['spelling_mode'] ?? 'student_choice',
                    'time_lock' => null,
                    'generate_flashcards' => isset($_POST['generate_flashcards']),
                ],
                'items' => $items,
                'results' => []
            ];

            // Time lock
            $opens = trim($_POST['time_lock_opens'] ?? '');
            $closes = trim($_POST['time_lock_closes'] ?? '');
            if ($opens || $closes) {
                $quiz['settings']['time_lock'] = [
                    'opens' => $opens ?: null,
                    'closes' => $closes ?: null
                ];
            }

            $quizzes = readJSON(DATA_DIR . '/quizzes.json');
            $quizzes[$quizId] = $quiz;
            writeJSON(DATA_DIR . '/quizzes.json', $quizzes);

            header('Location: dashboard.php');
            exit;
        }
    }
}

function parseCSV($csvText, $type) {
    $lines = array_filter(array_map('trim', explode("\n", $csvText)));
    $items = [];

    // Skip header if present
    $first = strtolower($lines[0] ?? '');
    if (str_contains($first, 'mening') || str_contains($first, 'begrepp')) {
        array_shift($lines);
    }

    foreach ($lines as $line) {
        $cols = array_map('trim', explode(';', $line));

        if ($type === 'glossary') {
            // mening;ord;översättning;fel1;fel2;fel3;omvänt_fel1;omvänt_fel2;omvänt_fel3
            if (count($cols) < 6) continue;
            $item = [
                'sentence' => $cols[0],
                'word' => $cols[1],
                'translation' => $cols[2],
                'wrong_options' => array_filter([$cols[3] ?? '', $cols[4] ?? '', $cols[5] ?? '']),
                'reverse_wrong_options' => array_filter([$cols[6] ?? '', $cols[7] ?? '', $cols[8] ?? ''])
            ];
            $items[] = $item;
        } else {
            // begrepp;beskrivning;fel1;fel2;fel3
            if (count($cols) < 5) continue;
            $item = [
                'concept' => $cols[0],
                'description' => $cols[1],
                'wrong_options' => array_filter([$cols[2] ?? '', $cols[3] ?? '', $cols[4] ?? ''])
            ];
            $items[] = $item;
        }
    }

    return $items;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skapa Quiz</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../engine/themes.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<div class="max-w-2xl mx-auto p-4">
    <div class="flex items-center gap-4 mb-6">
        <a href="dashboard.php" class="text-blue-600 hover:underline text-sm">&larr; Tillbaka</a>
        <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Skapa Quiz</h1>
    </div>

    <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-6" style="background: var(--card-bg); border: 1px solid var(--border)" class="rounded-xl p-6">
        <?= csrfField() ?>

        <!-- Typ -->
        <div>
            <label class="block text-sm font-medium mb-2" style="color: var(--text-primary)">Typ</label>
            <div class="flex gap-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="type" value="glossary" checked onchange="toggleTypeFields()">
                    <span style="color: var(--text-primary)">Glosquiz</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="type" value="fact" onchange="toggleTypeFields()">
                    <span style="color: var(--text-primary)">Faktaquiz</span>
                </label>
            </div>
        </div>

        <!-- Titel -->
        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary)">Titel</label>
            <input type="text" name="title" required class="w-full px-3 py-2 border rounded-lg" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" placeholder="T.ex. Spanska vecka 12">
        </div>

        <!-- CSV Data -->
        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary)">CSV-data (semikolon-separerad)</label>
            <div id="csv-hint-glossary" class="text-xs mb-1" style="color: var(--text-secondary)">Format: mening;ord;översättning;fel1;fel2;fel3;omvänt_fel1;omvänt_fel2;omvänt_fel3</div>
            <div id="csv-hint-fact" class="text-xs mb-1 hidden" style="color: var(--text-secondary)">Format: begrepp;beskrivning;fel1;fel2;fel3</div>
            <textarea name="csv_data" required rows="8" class="w-full px-3 py-2 border rounded-lg font-mono text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" placeholder="Klistra in CSV här..."></textarea>
        </div>

        <!-- Inställningar -->
        <fieldset class="border rounded-lg p-4" style="border-color: var(--border)">
            <legend class="text-sm font-medium px-2" style="color: var(--text-primary)">Inställningar</legend>
            <div class="grid grid-cols-2 gap-4">

                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Svarsläge</label>
                    <select name="answer_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" onchange="toggleHybridFields()">
                        <option value="multiple_choice">Flerval</option>
                        <option value="text_only">Skrivsvar</option>
                        <option value="hybrid">Hybrid (flerval + skrivsvar)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Quizläge</label>
                    <select name="quiz_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        <option value="training">Träning (repetition)</option>
                        <option value="test">Test (en genomgång)</option>
                    </select>
                </div>

                <div id="mc-count-field" class="hidden">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal flerval</label>
                    <input type="number" name="mc_count" min="1" value="10" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>

                <div id="text-count-field" class="hidden">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal skrivsvar</label>
                    <input type="number" name="text_count" min="1" value="5" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>

                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Språk</label>
                    <select name="language" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        <option value="sv">Svenska</option>
                        <option value="en">Engelska</option>
                        <option value="es">Spanska</option>
                        <option value="fr">Franska</option>
                        <option value="de">Tyska</option>
                        <option value="uk">Ukrainska</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Stavningsläge</label>
                    <select name="spelling_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        <option value="student_choice">Eleven väljer</option>
                        <option value="easy">Generös</option>
                        <option value="puritan">Exakt</option>
                    </select>
                </div>

                <!-- Checkboxes -->
                <div class="col-span-2 flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary)">
                        <input type="checkbox" name="reverse_enabled" onchange="toggleReverseFields()"> Omvänd riktning
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer" id="tts-label" style="color: var(--text-primary)">
                        <input type="checkbox" name="tts_enabled" id="tts-checkbox" checked> Uppläsning (TTS)
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary)">
                        <input type="checkbox" name="generate_flashcards" checked> Generera flashcards
                    </label>
                </div>
            </div>
        </fieldset>

        <!-- Omvänd riktning (dold tills aktiverad) -->
        <fieldset id="reverse-fields" class="border rounded-lg p-4 hidden" style="border-color: var(--border)">
            <legend class="text-sm font-medium px-2" style="color: var(--text-primary)">Omvänd riktning</legend>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Omvänt svarsläge</label>
                    <select name="reverse_answer_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" onchange="toggleReverseHybridFields()">
                        <option value="multiple_choice">Flerval</option>
                        <option value="text_only">Skrivsvar</option>
                        <option value="hybrid">Hybrid</option>
                    </select>
                </div>
                <div></div>
                <div id="reverse-mc-count-field" class="hidden">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal flerval (omvänd)</label>
                    <input type="number" name="reverse_mc_count" min="1" value="10" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
                <div id="reverse-text-count-field" class="hidden">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal skrivsvar (omvänd)</label>
                    <input type="number" name="reverse_text_count" min="1" value="5" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
            </div>
        </fieldset>

        <!-- Tidsspärr -->
        <fieldset class="border rounded-lg p-4" style="border-color: var(--border)">
            <legend class="text-sm font-medium px-2" style="color: var(--text-primary)">Tidsspärr (valfritt)</legend>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Öppnar</label>
                    <input type="datetime-local" name="time_lock_opens" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Stänger</label>
                    <input type="datetime-local" name="time_lock_closes" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
            </div>
        </fieldset>

        <button type="submit" class="w-full py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">Skapa Quiz</button>
    </form>
</div>

<script>
function toggleTypeFields() {
    const isGlossary = document.querySelector('input[name="type"][value="glossary"]').checked;
    document.getElementById('csv-hint-glossary').classList.toggle('hidden', !isGlossary);
    document.getElementById('csv-hint-fact').classList.toggle('hidden', isGlossary);
    // TTS default: on for glossary, off for fact
    document.getElementById('tts-checkbox').checked = isGlossary;
}
function toggleHybridFields() {
    const mode = document.querySelector('select[name="answer_mode"]').value;
    document.getElementById('mc-count-field').classList.toggle('hidden', mode !== 'hybrid');
    document.getElementById('text-count-field').classList.toggle('hidden', mode !== 'hybrid');
}
function toggleReverseFields() {
    const enabled = document.querySelector('input[name="reverse_enabled"]').checked;
    document.getElementById('reverse-fields').classList.toggle('hidden', !enabled);
}
function toggleReverseHybridFields() {
    const mode = document.querySelector('select[name="reverse_answer_mode"]').value;
    document.getElementById('reverse-mc-count-field').classList.toggle('hidden', mode !== 'hybrid');
    document.getElementById('reverse-text-count-field').classList.toggle('hidden', mode !== 'hybrid');
}
</script>
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add admin/create.php
git commit -m "feat: add quiz creation form with CSV import and all settings"
```

---

## Task 6: Admin — edit quiz

**Files:**
- Create: `admin/edit.php`

- [ ] **Step 1: Create admin/edit.php**

Load existing quiz, pre-fill form, save changes. Same form structure as create.php but pre-populated. Allows editing title, settings, and CSV data (re-parsed on save).

```php
<?php
// admin/edit.php — Edit existing quiz
session_start();
require_once __DIR__ . '/../config.php';
requireTeacher();

$quizId = $_GET['id'] ?? '';
$quizzes = readJSON(DATA_DIR . '/quizzes.json');

if (!isset($quizzes[$quizId]) || ($quizzes[$quizId]['teacher_id'] ?? '') !== getCurrentTeacherID()) {
    header('Location: dashboard.php');
    exit;
}

$quiz = $quizzes[$quizId];
$csrfToken = getCsrfToken();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();

    $title = trim($_POST['title'] ?? '');
    if (!$title) {
        $error = 'Titel krävs.';
    } else {
        // Update settings
        $quiz['title'] = $title;
        $quiz['settings']['answer_mode'] = $_POST['answer_mode'] ?? 'multiple_choice';
        $quiz['settings']['mc_count'] = intval($_POST['mc_count'] ?? count($quiz['items']));
        $quiz['settings']['text_count'] = intval($_POST['text_count'] ?? 0);
        $quiz['settings']['reverse_enabled'] = isset($_POST['reverse_enabled']);
        $quiz['settings']['reverse_answer_mode'] = $_POST['reverse_answer_mode'] ?? 'multiple_choice';
        $quiz['settings']['reverse_mc_count'] = intval($_POST['reverse_mc_count'] ?? count($quiz['items']));
        $quiz['settings']['reverse_text_count'] = intval($_POST['reverse_text_count'] ?? 0);
        $quiz['settings']['quiz_mode'] = $_POST['quiz_mode'] ?? 'training';
        $quiz['settings']['tts_enabled'] = isset($_POST['tts_enabled']);
        $quiz['settings']['language'] = $_POST['language'] ?? 'sv';
        $quiz['settings']['spelling_mode'] = $_POST['spelling_mode'] ?? 'student_choice';
        $quiz['settings']['generate_flashcards'] = isset($_POST['generate_flashcards']);

        // Time lock
        $opens = trim($_POST['time_lock_opens'] ?? '');
        $closes = trim($_POST['time_lock_closes'] ?? '');
        $quiz['settings']['time_lock'] = ($opens || $closes) ? ['opens' => $opens ?: null, 'closes' => $closes ?: null] : null;

        // Re-parse CSV if provided
        $csvData = trim($_POST['csv_data'] ?? '');
        if ($csvData) {
            $items = parseCSV($csvData, $quiz['type']);
            if (!empty($items)) {
                $quiz['items'] = $items;
            } else {
                $error = 'Kunde inte tolka CSV-data.';
            }
        }

        if (!$error) {
            $quizzes[$quizId] = $quiz;
            writeJSON(DATA_DIR . '/quizzes.json', $quizzes);
            header('Location: dashboard.php');
            exit;
        }
    }
}

// Same parseCSV function as create.php
function parseCSV($csvText, $type) {
    $lines = array_filter(array_map('trim', explode("\n", $csvText)));
    $items = [];
    $first = strtolower($lines[0] ?? '');
    if (str_contains($first, 'mening') || str_contains($first, 'begrepp')) array_shift($lines);

    foreach ($lines as $line) {
        $cols = array_map('trim', explode(';', $line));
        if ($type === 'glossary') {
            if (count($cols) < 6) continue;
            $items[] = [
                'sentence' => $cols[0], 'word' => $cols[1], 'translation' => $cols[2],
                'wrong_options' => array_filter([$cols[3] ?? '', $cols[4] ?? '', $cols[5] ?? '']),
                'reverse_wrong_options' => array_filter([$cols[6] ?? '', $cols[7] ?? '', $cols[8] ?? ''])
            ];
        } else {
            if (count($cols) < 5) continue;
            $items[] = [
                'concept' => $cols[0], 'description' => $cols[1],
                'wrong_options' => array_filter([$cols[2] ?? '', $cols[3] ?? '', $cols[4] ?? ''])
            ];
        }
    }
    return $items;
}

// Convert items back to CSV for textarea display
function itemsToCSV($items, $type) {
    $lines = [];
    foreach ($items as $item) {
        if ($type === 'glossary') {
            $parts = [$item['sentence'], $item['word'], $item['translation']];
            $parts = array_merge($parts, $item['wrong_options'] ?? []);
            $parts = array_merge($parts, $item['reverse_wrong_options'] ?? []);
            $lines[] = implode(';', $parts);
        } else {
            $parts = [$item['concept'], $item['description']];
            $parts = array_merge($parts, $item['wrong_options'] ?? []);
            $lines[] = implode(';', $parts);
        }
    }
    return implode("\n", $lines);
}

$s = $quiz['settings'];
$csvText = itemsToCSV($quiz['items'], $quiz['type']);
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redigera: <?= htmlspecialchars($quiz['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../engine/themes.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<div class="max-w-2xl mx-auto p-4">
    <div class="flex items-center gap-4 mb-6">
        <a href="dashboard.php" class="text-blue-600 hover:underline text-sm">&larr; Tillbaka</a>
        <h1 class="text-2xl font-bold" style="color: var(--text-primary)">Redigera: <?= htmlspecialchars($quiz['title']) ?></h1>
    </div>

    <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-6 rounded-xl p-6" style="background: var(--card-bg); border: 1px solid var(--border)">
        <?= csrfField() ?>

        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary)">Titel</label>
            <input type="text" name="title" value="<?= htmlspecialchars($quiz['title']) ?>" required class="w-full px-3 py-2 border rounded-lg" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
        </div>

        <div>
            <label class="block text-sm font-medium mb-1" style="color: var(--text-primary)">
                CSV-data <span class="text-xs font-normal" style="color: var(--text-secondary)">(lämna tomt för att behålla befintliga frågor)</span>
            </label>
            <textarea name="csv_data" rows="8" class="w-full px-3 py-2 border rounded-lg font-mono text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)"><?= htmlspecialchars($csvText) ?></textarea>
        </div>

        <fieldset class="border rounded-lg p-4" style="border-color: var(--border)">
            <legend class="text-sm font-medium px-2" style="color: var(--text-primary)">Inställningar</legend>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Svarsläge</label>
                    <select name="answer_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" onchange="toggleHybridFields()">
                        <option value="multiple_choice" <?= $s['answer_mode'] === 'multiple_choice' ? 'selected' : '' ?>>Flerval</option>
                        <option value="text_only" <?= $s['answer_mode'] === 'text_only' ? 'selected' : '' ?>>Skrivsvar</option>
                        <option value="hybrid" <?= $s['answer_mode'] === 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Quizläge</label>
                    <select name="quiz_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        <option value="training" <?= ($s['quiz_mode'] ?? 'training') === 'training' ? 'selected' : '' ?>>Träning</option>
                        <option value="test" <?= ($s['quiz_mode'] ?? '') === 'test' ? 'selected' : '' ?>>Test</option>
                    </select>
                </div>
                <div id="mc-count-field" class="<?= $s['answer_mode'] !== 'hybrid' ? 'hidden' : '' ?>">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal flerval</label>
                    <input type="number" name="mc_count" min="1" value="<?= $s['mc_count'] ?? 10 ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
                <div id="text-count-field" class="<?= $s['answer_mode'] !== 'hybrid' ? 'hidden' : '' ?>">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal skrivsvar</label>
                    <input type="number" name="text_count" min="1" value="<?= $s['text_count'] ?? 5 ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Språk</label>
                    <select name="language" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        <?php foreach (['sv'=>'Svenska','en'=>'Engelska','es'=>'Spanska','fr'=>'Franska','de'=>'Tyska','uk'=>'Ukrainska'] as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($s['language'] ?? 'sv') === $code ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Stavningsläge</label>
                    <select name="spelling_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                        <option value="student_choice" <?= ($s['spelling_mode'] ?? 'student_choice') === 'student_choice' ? 'selected' : '' ?>>Eleven väljer</option>
                        <option value="easy" <?= ($s['spelling_mode'] ?? '') === 'easy' ? 'selected' : '' ?>>Generös</option>
                        <option value="puritan" <?= ($s['spelling_mode'] ?? '') === 'puritan' ? 'selected' : '' ?>>Exakt</option>
                    </select>
                </div>
                <div class="col-span-2 flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary)">
                        <input type="checkbox" name="reverse_enabled" <?= !empty($s['reverse_enabled']) ? 'checked' : '' ?> onchange="toggleReverseFields()"> Omvänd riktning
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary)">
                        <input type="checkbox" name="tts_enabled" <?= !empty($s['tts_enabled']) ? 'checked' : '' ?>> Uppläsning (TTS)
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary)">
                        <input type="checkbox" name="generate_flashcards" <?= ($s['generate_flashcards'] ?? true) ? 'checked' : '' ?>> Flashcards
                    </label>
                </div>
            </div>
        </fieldset>

        <fieldset id="reverse-fields" class="border rounded-lg p-4 <?= empty($s['reverse_enabled']) ? 'hidden' : '' ?>" style="border-color: var(--border)">
            <legend class="text-sm font-medium px-2" style="color: var(--text-primary)">Omvänd riktning</legend>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Omvänt svarsläge</label>
                    <select name="reverse_answer_mode" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)" onchange="toggleReverseHybridFields()">
                        <option value="multiple_choice" <?= ($s['reverse_answer_mode'] ?? 'multiple_choice') === 'multiple_choice' ? 'selected' : '' ?>>Flerval</option>
                        <option value="text_only" <?= ($s['reverse_answer_mode'] ?? '') === 'text_only' ? 'selected' : '' ?>>Skrivsvar</option>
                        <option value="hybrid" <?= ($s['reverse_answer_mode'] ?? '') === 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
                    </select>
                </div>
                <div></div>
                <div id="reverse-mc-count-field" class="<?= ($s['reverse_answer_mode'] ?? '') !== 'hybrid' ? 'hidden' : '' ?>">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal flerval (omvänd)</label>
                    <input type="number" name="reverse_mc_count" min="1" value="<?= $s['reverse_mc_count'] ?? 10 ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
                <div id="reverse-text-count-field" class="<?= ($s['reverse_answer_mode'] ?? '') !== 'hybrid' ? 'hidden' : '' ?>">
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Antal skrivsvar (omvänd)</label>
                    <input type="number" name="reverse_text_count" min="1" value="<?= $s['reverse_text_count'] ?? 5 ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
            </div>
        </fieldset>

        <fieldset class="border rounded-lg p-4" style="border-color: var(--border)">
            <legend class="text-sm font-medium px-2" style="color: var(--text-primary)">Tidsspärr</legend>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Öppnar</label>
                    <input type="datetime-local" name="time_lock_opens" value="<?= htmlspecialchars($s['time_lock']['opens'] ?? '') ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
                <div>
                    <label class="block text-xs mb-1" style="color: var(--text-secondary)">Stänger</label>
                    <input type="datetime-local" name="time_lock_closes" value="<?= htmlspecialchars($s['time_lock']['closes'] ?? '') ?>" class="w-full px-2 py-1 border rounded text-sm" style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
                </div>
            </div>
        </fieldset>

        <button type="submit" class="w-full py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">Spara ändringar</button>
    </form>
</div>

<script>
function toggleHybridFields() {
    const mode = document.querySelector('select[name="answer_mode"]').value;
    document.getElementById('mc-count-field').classList.toggle('hidden', mode !== 'hybrid');
    document.getElementById('text-count-field').classList.toggle('hidden', mode !== 'hybrid');
}
function toggleReverseFields() {
    const enabled = document.querySelector('input[name="reverse_enabled"]').checked;
    document.getElementById('reverse-fields').classList.toggle('hidden', !enabled);
}
function toggleReverseHybridFields() {
    const mode = document.querySelector('select[name="reverse_answer_mode"]').value;
    document.getElementById('reverse-mc-count-field').classList.toggle('hidden', mode !== 'hybrid');
    document.getElementById('reverse-text-count-field').classList.toggle('hidden', mode !== 'hybrid');
}
</script>
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add admin/edit.php
git commit -m "feat: add quiz edit form with pre-populated settings"
```

---

## Task 7: Admin — statistics view

**Files:**
- Create: `admin/stats.php`

- [ ] **Step 1: Create admin/stats.php**

Shows stats for a quiz: attempt count, per-question difficulty, recent results list, and clear results button.

```php
<?php
// admin/stats.php — Quiz statistics + clear results
session_start();
require_once __DIR__ . '/../config.php';
requireTeacher();

$quizId = $_GET['id'] ?? '';
$quizzes = readJSON(DATA_DIR . '/quizzes.json');

if (!isset($quizzes[$quizId]) || ($quizzes[$quizId]['teacher_id'] ?? '') !== getCurrentTeacherID()) {
    header('Location: dashboard.php');
    exit;
}

$quiz = $quizzes[$quizId];
$results = $quiz['results'] ?? [];
$items = $quiz['items'] ?? [];
$csrfToken = getCsrfToken();

// Calculate per-question error rates
$questionErrors = [];
foreach ($results as $result) {
    foreach ($result['errors'] ?? [] as $err) {
        $idx = $err['item_index'] ?? -1;
        if ($idx >= 0) {
            $questionErrors[$idx] = ($questionErrors[$idx] ?? 0) + 1;
        }
    }
}
arsort($questionErrors);

// Average score
$avgScore = 0;
if (count($results) > 0) {
    $totalScore = array_sum(array_column($results, 'score'));
    $totalPossible = array_sum(array_column($results, 'total'));
    $avgScore = $totalPossible > 0 ? round(($totalScore / $totalPossible) * 100) : 0;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistik: <?= htmlspecialchars($quiz['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../engine/themes.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<div class="max-w-3xl mx-auto p-4">
    <div class="flex items-center gap-4 mb-6">
        <a href="dashboard.php" class="text-blue-600 hover:underline text-sm">&larr; Tillbaka</a>
        <h1 class="text-2xl font-bold" style="color: var(--text-primary)">📊 <?= htmlspecialchars($quiz['title']) ?></h1>
    </div>

    <!-- Overview cards -->
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="rounded-lg p-4 text-center" style="background: var(--card-bg); border: 1px solid var(--border)">
            <div class="text-2xl font-bold" style="color: var(--accent)"><?= count($results) ?></div>
            <div class="text-xs" style="color: var(--text-secondary)">Genomförda</div>
        </div>
        <div class="rounded-lg p-4 text-center" style="background: var(--card-bg); border: 1px solid var(--border)">
            <div class="text-2xl font-bold" style="color: var(--accent)"><?= $avgScore ?>%</div>
            <div class="text-xs" style="color: var(--text-secondary)">Snitträtt</div>
        </div>
        <div class="rounded-lg p-4 text-center" style="background: var(--card-bg); border: 1px solid var(--border)">
            <div class="text-2xl font-bold" style="color: var(--accent)"><?= count($items) ?></div>
            <div class="text-xs" style="color: var(--text-secondary)">Frågor</div>
        </div>
    </div>

    <!-- Hardest questions -->
    <?php if (!empty($questionErrors)): ?>
    <div class="rounded-lg p-4 mb-6" style="background: var(--card-bg); border: 1px solid var(--border)">
        <h2 class="font-medium mb-3" style="color: var(--text-primary)">Svåraste frågorna</h2>
        <?php foreach (array_slice($questionErrors, 0, 5, true) as $idx => $errorCount):
            $item = $items[$idx] ?? null;
            if (!$item) continue;
            $label = $quiz['type'] === 'glossary' ? ($item['word'] ?? '?') : ($item['concept'] ?? '?');
            $pct = count($results) > 0 ? round(($errorCount / count($results)) * 100) : 0;
        ?>
        <div class="flex items-center justify-between py-1">
            <span class="text-sm" style="color: var(--text-primary)"><?= htmlspecialchars($label) ?></span>
            <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700"><?= $errorCount ?> fel (<?= $pct ?>%)</span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Recent results -->
    <div class="rounded-lg p-4 mb-6" style="background: var(--card-bg); border: 1px solid var(--border)">
        <h2 class="font-medium mb-3" style="color: var(--text-primary)">Senaste resultat</h2>
        <?php if (empty($results)): ?>
            <p class="text-sm" style="color: var(--text-secondary)">Inga resultat ännu.</p>
        <?php else: ?>
            <div class="space-y-1">
            <?php foreach (array_reverse(array_slice($results, -20)) as $r): ?>
                <div class="flex items-center justify-between py-1">
                    <span class="text-sm" style="color: var(--text-primary)"><?= htmlspecialchars($r['student_name']) ?></span>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium" style="color: var(--accent)"><?= $r['score'] ?>/<?= $r['total'] ?></span>
                        <span class="text-xs" style="color: var(--text-secondary)"><?= date('j/n H:i', strtotime($r['timestamp'])) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Clear results -->
    <?php if (!empty($results)): ?>
    <form method="POST" action="../api/clear-results.php" onsubmit="return clearResults(event)" class="text-center">
        <button type="button" onclick="clearResults()" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm">Rensa alla resultat (<?= count($results) ?> st)</button>
    </form>
    <script>
    async function clearResults() {
        if (!confirm('Radera alla ' + <?= count($results) ?> + ' resultat? Detta går inte att ångra.')) return;
        const res = await fetch('../api/clear-results.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?= $csrfToken ?>' },
            body: JSON.stringify({ quiz_id: '<?= htmlspecialchars($quizId) ?>' })
        });
        if (res.ok) location.reload();
    }
    </script>
    <?php endif; ?>
</div>
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add admin/stats.php
git commit -m "feat: add quiz statistics view with difficulty chart and clear results"
```

---

## Task 8: Student — quiz player (the main play view)

**Files:**
- Create: `play/quiz.php`

This is the core student experience. Uses React (CDN) + the shared engine. Renders both glossary and fact quiz types.

- [ ] **Step 1: Create play/quiz.php**

Single-file React app that:
1. Fetches quiz via API
2. Initializes QuizEngine
3. Renders questions (MC or text input based on phase)
4. Shows running score counter
5. Shows correct answer on wrong
6. Handles phase transitions (MC → text) and direction switch (forward → reverse)
7. Shows diploma at end (Flawless or normal)
8. Saves result via API

```php
<?php
// play/quiz.php — Student quiz player (glossary + fact)
// No auth required — students access via link
$quizId = $_GET['id'] ?? '';
$studentName = $_GET['name'] ?? '';
if (!$quizId || !$studentName) {
    header('Location: index.php?id=' . urlencode($quizId));
    exit;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
    <link rel="stylesheet" href="../engine/themes.css">
    <script src="../engine/validator.js"></script>
    <script src="../engine/tts.js"></script>
    <script src="../engine/quiz-engine.js"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<div id="root"></div>

<script type="text/babel">
const QUIZ_ID = <?= json_encode($quizId) ?>;
const STUDENT_NAME = <?= json_encode($studentName) ?>;

function App() {
    const [quiz, setQuiz] = React.useState(null);
    const [engine, setEngine] = React.useState(null);
    const [status, setStatus] = React.useState('loading'); // loading | not_yet | playing | feedback | diploma
    const [theme, setTheme] = React.useState('light');
    const [spellingMode, setSpellingMode] = React.useState('easy');
    const [spellingChoice, setSpellingChoice] = React.useState(null); // for student_choice
    const [feedback, setFeedback] = React.useState(null);
    const [textInput, setTextInput] = React.useState('');
    const [opensAt, setOpensAt] = React.useState('');

    // Load quiz
    React.useEffect(() => {
        fetch('../api/quiz-data.php?id=' + encodeURIComponent(QUIZ_ID))
            .then(r => r.json())
            .then(data => {
                if (data.status === 'not_yet') {
                    setOpensAt(data.opens);
                    setStatus('not_yet');
                    return;
                }
                setQuiz(data);
                const sm = data.settings.spelling_mode || 'easy';
                if (sm !== 'student_choice') setSpellingMode(sm);
                const eng = QuizEngine({
                    items: data.items,
                    settings: data.settings,
                    quizType: data.type
                });
                setEngine(eng);
                setStatus('playing');

                // TTS
                if (data.settings.tts_enabled) {
                    const item = eng.currentItem();
                    const q = eng.getQuestion(item);
                    if (data.type === 'glossary' && eng.currentDirection() === 'forward') {
                        speakGlossary(item.sentence, item.word, data.settings.language);
                    } else {
                        speakText(q.prompt, data.settings.language);
                    }
                }
            })
            .catch(() => setStatus('error'));
    }, []);

    // Theme
    React.useEffect(() => {
        document.body.className = '';
        if (theme === 'night') document.body.classList.add('night-mode');
        else if (theme === 'night-magenta') document.body.classList.add('night-magenta-mode');
        else if (theme === 'psychedelic') document.body.classList.add('psychedelic-mode');
        document.body.classList.add('min-h-screen', 'bg-gradient-to-br', 'from-[var(--bg-from)]', 'to-[var(--bg-to)]');
    }, [theme]);

    function handleAnswer(answer) {
        const currentSpelling = spellingChoice || spellingMode;
        const result = engine.submitAnswer(answer, currentSpelling);
        setTextInput('');

        const item = engine.currentItem();
        const q = item ? engine.getQuestion(item) : null;

        if (result.complete) {
            // Save result
            const results = engine.getResults();
            fetch('../api/save-result.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    quiz_id: QUIZ_ID,
                    student_name: STUDENT_NAME,
                    score: results.score,
                    total: results.total,
                    errors: results.errors
                })
            });
            setStatus('diploma');
            return;
        }

        if (!result.correct) {
            const prevItem = engine.currentItem(); // After shift, this is next item
            setFeedback({ correct: false, correctAnswer: result.type === 'mc'
                ? engine.getQuestion(engine.currentItem())?.answer
                : result.correctAnswer || answer, given: answer });
            setStatus('feedback');
            setTimeout(() => {
                setFeedback(null);
                setStatus('playing');
                playTTS(item, q);
            }, 2000);
            return;
        }

        if (result.directionChange) {
            setFeedback({ correct: true, message: 'Nu kör vi omvänt!' });
            setStatus('feedback');
            setTimeout(() => {
                setFeedback(null);
                setStatus('playing');
                playTTS(item, q);
            }, 1500);
            return;
        }

        if (result.phaseChange) {
            setFeedback({ correct: true, message: 'Bra! Nu skrivsvar.' });
            setStatus('feedback');
            setTimeout(() => {
                setFeedback(null);
                setStatus('playing');
                playTTS(item, q);
            }, 1500);
            return;
        }

        // Correct, next question
        playTTS(item, q);
    }

    function playTTS(item, q) {
        if (!quiz?.settings?.tts_enabled || !item) return;
        if (quiz.type === 'glossary' && engine.currentDirection() === 'forward') {
            speakGlossary(item.sentence, item.word, quiz.settings.language);
        } else if (q) {
            speakText(q.prompt, quiz.settings.language);
        }
    }

    if (status === 'loading') return <div className="flex items-center justify-center min-h-screen"><p style={{color: 'var(--text-secondary)'}}>Laddar...</p></div>;
    if (status === 'error') return <div className="flex items-center justify-center min-h-screen"><p className="text-red-500">Kunde inte ladda quizet.</p></div>;
    if (status === 'not_yet') return <div className="flex items-center justify-center min-h-screen"><div className="text-center p-8 rounded-xl" style={{background: 'var(--card-bg)', border: '1px solid var(--border)'}}><p className="text-lg" style={{color: 'var(--text-primary)'}}>Quizet öppnar</p><p className="text-2xl font-bold mt-2" style={{color: 'var(--accent)'}}>{opensAt}</p></div></div>;

    if (status === 'diploma') {
        const results = engine.getResults();
        return (
            <div className="flex items-center justify-center min-h-screen p-4">
                <div className="text-center p-8 rounded-xl max-w-md w-full" style={{background: 'var(--card-bg)', border: '2px solid var(--accent)'}}>
                    <div className="text-6xl mb-4">{results.flawless ? '💎' : '🎉'}</div>
                    <h1 className="text-3xl font-bold mb-2" style={{color: 'var(--text-primary)'}}>
                        {results.flawless ? 'FLAWLESS!' : 'Bra jobbat!'}
                    </h1>
                    <p className="text-xl mb-4" style={{color: 'var(--accent)'}}>
                        {results.score}/{results.total} rätt
                    </p>
                    {results.flawless && <p className="text-sm mb-4" style={{color: 'var(--text-secondary)'}}>Inte ett enda fel. Imponerande, {STUDENT_NAME}!</p>}
                    {!results.flawless && results.errors.length > 0 && (
                        <div className="text-left mt-4 p-4 rounded-lg" style={{background: 'var(--bg-from)'}}>
                            <p className="text-sm font-medium mb-2" style={{color: 'var(--text-primary)'}}>Att öva på:</p>
                            {results.errors.map((e, i) => (
                                <div key={i} className="text-sm py-1 flex justify-between" style={{color: 'var(--text-secondary)'}}>
                                    <span className="line-through text-red-400">{e.given}</span>
                                    <span style={{color: 'var(--accent)'}}>{e.correct}</span>
                                </div>
                            ))}
                        </div>
                    )}
                    <p className="text-xs mt-4" style={{color: 'var(--text-secondary)'}}>{STUDENT_NAME} · {quiz.title}</p>
                </div>
            </div>
        );
    }

    const item = engine.currentItem();
    const q = engine.getQuestion(item);
    const progress = engine.getProgress();

    return (
        <div className="max-w-lg mx-auto p-4">
            {/* Header */}
            <div className="flex items-center justify-between mb-4">
                <h2 className="text-sm font-medium truncate" style={{color: 'var(--text-primary)'}}>{quiz.title}</h2>
                <div className="flex items-center gap-2">
                    <span className="text-sm font-bold" style={{color: 'var(--accent)'}}>{progress.correctCount}/{progress.correctCount + progress.totalErrors}</span>
                    <select value={theme} onChange={e => setTheme(e.target.value)} className="text-xs px-1 py-0.5 rounded border" style={{background: 'var(--card-bg)', color: 'var(--text-secondary)', borderColor: 'var(--border)'}}>
                        <option value="light">Light</option>
                        <option value="night">Night</option>
                        <option value="night-magenta">Magenta</option>
                        <option value="psychedelic">Psychedelic</option>
                    </select>
                </div>
            </div>

            {/* Progress bar */}
            <div className="w-full h-1.5 rounded-full mb-6" style={{background: 'var(--border)'}}>
                <div className="h-full rounded-full bg-green-500 transition-all" style={{width: `${progress.answered > 0 ? (progress.correctCount / progress.totalQuestions) * 100 : 0}%`}}></div>
            </div>

            {/* Spelling mode choice (if student_choice) */}
            {quiz.settings.spelling_mode === 'student_choice' && progress.phase === 'text' && !spellingChoice && (
                <div className="mb-4 p-3 rounded-lg text-center" style={{background: 'var(--card-bg)', border: '1px solid var(--border)'}}>
                    <p className="text-sm mb-2" style={{color: 'var(--text-primary)'}}>Stavningsläge:</p>
                    <div className="flex gap-2 justify-center">
                        <button onClick={() => setSpellingChoice('easy')} className="px-4 py-1 rounded-lg bg-green-100 text-green-700 text-sm hover:bg-green-200">Generös</button>
                        <button onClick={() => setSpellingChoice('puritan')} className="px-4 py-1 rounded-lg bg-red-100 text-red-700 text-sm hover:bg-red-200">Exakt</button>
                    </div>
                </div>
            )}

            {/* Direction indicator */}
            {progress.direction === 'reverse' && (
                <div className="text-center mb-2"><span className="text-xs px-2 py-0.5 rounded-full bg-purple-100 text-purple-700">Omvänd riktning</span></div>
            )}

            {/* Question card */}
            {status === 'playing' && q && (
                <div className="rounded-xl p-6 mb-4" style={{background: 'var(--card-bg)', border: '1px solid var(--border)'}}>
                    {/* Glossary: show sentence with highlighted word */}
                    {quiz.type === 'glossary' && progress.direction === 'forward' && (
                        <div>
                            <p className="text-lg mb-2" style={{color: 'var(--text-secondary)'}}>{q.prompt.replace(q.highlight, `**${q.highlight}**`).split('**').map((part, i) =>
                                i % 2 === 1 ? <strong key={i} style={{color: 'var(--accent)'}}>{part}</strong> : part
                            )}</p>
                            <p className="text-sm" style={{color: 'var(--text-secondary)'}}>Vad betyder <strong style={{color: 'var(--accent)'}}>{q.highlight}</strong>?</p>
                        </div>
                    )}

                    {/* Glossary reverse or fact: show prompt */}
                    {(quiz.type !== 'glossary' || progress.direction === 'reverse') && (
                        <p className="text-lg" style={{color: 'var(--text-primary)'}}>{q.prompt}</p>
                    )}

                    {/* Multiple choice options */}
                    {q.options && (
                        <div className="mt-4 space-y-2">
                            {q.options.map((opt, i) => (
                                <button key={i} onClick={() => handleAnswer(opt)} className="w-full text-left px-4 py-3 rounded-lg border text-sm transition-colors hover:border-blue-400" style={{background: 'var(--card-bg)', color: 'var(--text-primary)', borderColor: 'var(--border)'}}>
                                    {opt}
                                </button>
                            ))}
                        </div>
                    )}

                    {/* Text input */}
                    {!q.options && (
                        <form onSubmit={e => { e.preventDefault(); if (textInput.trim()) handleAnswer(textInput.trim()); }} className="mt-4">
                            <input type="text" value={textInput} onChange={e => setTextInput(e.target.value)} autoFocus placeholder="Skriv ditt svar..." className="w-full px-4 py-3 rounded-lg border text-sm" style={{background: 'var(--card-bg)', color: 'var(--text-primary)', borderColor: 'var(--border)'}} />
                            <button type="submit" className="w-full mt-2 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">Svara</button>
                        </form>
                    )}
                </div>
            )}

            {/* Feedback overlay */}
            {status === 'feedback' && feedback && (
                <div className={`rounded-xl p-6 mb-4 text-center ${feedback.correct ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'}`} style={{border: '1px solid'}}>
                    {feedback.correct ? (
                        <div>
                            <div className="text-3xl mb-2">✅</div>
                            {feedback.message && <p className="text-green-700 font-medium">{feedback.message}</p>}
                        </div>
                    ) : (
                        <div>
                            <div className="text-3xl mb-2">❌</div>
                            <p className="text-red-700 text-sm">Rätt svar: <strong>{feedback.correctAnswer}</strong></p>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App />);
</script>
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add play/quiz.php
git commit -m "feat: add student quiz player with MC, text input, TTS, themes, and diplomas"
```

---

## Task 9: Student — entry page (name input + dashboard)

**Files:**
- Create: `play/index.php`

- [ ] **Step 1: Create play/index.php**

Name input → dashboard showing available activities (flashcards, quiz, test).

```php
<?php
// play/index.php — Student entry: name input + activity dashboard
$quizId = $_GET['id'] ?? '';
if (!$quizId) {
    echo '<p>Ingen quiz angiven.</p>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../engine/themes.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)] flex items-center justify-center">
<div id="app" class="max-w-md w-full mx-auto p-4">
    <!-- Name input -->
    <div id="name-screen" class="rounded-xl p-8 text-center" style="background: var(--card-bg); border: 1px solid var(--border)">
        <h1 class="text-2xl font-bold mb-6" style="color: var(--text-primary)" id="quiz-title">Quiz</h1>
        <form onsubmit="enterQuiz(event)">
            <input type="text" id="name-input" placeholder="Ditt namn" required autofocus
                class="w-full px-4 py-3 rounded-lg border text-center text-lg mb-4"
                style="background: var(--card-bg); color: var(--text-primary); border-color: var(--border)">
            <button type="submit" class="w-full py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium text-lg">Starta</button>
        </form>
    </div>

    <!-- Dashboard (hidden until name entered) -->
    <div id="dashboard" class="hidden space-y-4">
        <h1 class="text-xl font-bold text-center mb-2" style="color: var(--text-primary)" id="dash-title"></h1>
        <p class="text-center text-sm mb-4" style="color: var(--text-secondary)">Välj aktivitet, <span id="dash-name"></span></p>

        <!-- Flashcards section -->
        <div id="flashcard-section" class="hidden">
            <h3 class="text-xs uppercase tracking-wide mb-2 px-1" style="color: var(--text-secondary)">Träna</h3>
            <a id="flashcard-link" href="#" class="block rounded-lg px-4 py-3 hover:shadow-md transition-shadow" style="background: var(--card-bg); border: 1px solid var(--border)">
                <span class="font-medium" style="color: var(--text-primary)">📇 Flashcards</span>
                <span class="text-xs ml-2" style="color: var(--text-secondary)">Bläddra genom alla</span>
            </a>
        </div>

        <!-- Quiz section -->
        <div>
            <h3 class="text-xs uppercase tracking-wide mb-2 px-1" style="color: var(--text-secondary)">Öva</h3>
            <a id="quiz-link" href="#" class="block rounded-lg px-4 py-3 hover:shadow-md transition-shadow" style="background: var(--card-bg); border: 1px solid var(--border)">
                <span class="font-medium" style="color: var(--text-primary)">📝 Quiz</span>
                <span class="text-xs ml-2" style="color: var(--text-secondary)">Träningsläge med repetition</span>
            </a>
        </div>

        <!-- Test section (if quiz_mode allows) -->
        <div id="test-section" class="hidden">
            <h3 class="text-xs uppercase tracking-wide mb-2 px-1" style="color: var(--text-secondary)">Testa</h3>
            <a id="test-link" href="#" class="block rounded-lg px-4 py-3 hover:shadow-md transition-shadow" style="background: var(--card-bg); border: 1px solid var(--border)">
                <span class="font-medium" style="color: var(--text-primary)">🎯 Test</span>
                <span class="text-xs ml-2" style="color: var(--text-secondary)">En genomgång, inga omtag</span>
            </a>
        </div>
    </div>
</div>

<script>
const QUIZ_ID = <?= json_encode($quizId) ?>;
let quizData = null;

// Load quiz title
fetch('../api/quiz-data.php?id=' + encodeURIComponent(QUIZ_ID))
    .then(r => r.json())
    .then(data => {
        quizData = data;
        document.getElementById('quiz-title').textContent = data.title || 'Quiz';
    });

function enterQuiz(e) {
    e.preventDefault();
    const name = document.getElementById('name-input').value.trim();
    if (!name) return;

    document.getElementById('name-screen').classList.add('hidden');
    document.getElementById('dashboard').classList.remove('hidden');
    document.getElementById('dash-title').textContent = quizData?.title || 'Quiz';
    document.getElementById('dash-name').textContent = name;

    const base = 'quiz.php?id=' + encodeURIComponent(QUIZ_ID) + '&name=' + encodeURIComponent(name);
    document.getElementById('quiz-link').href = base;

    if (quizData?.settings?.generate_flashcards) {
        document.getElementById('flashcard-section').classList.remove('hidden');
        document.getElementById('flashcard-link').href = 'flashcards.php?id=' + encodeURIComponent(QUIZ_ID) + '&name=' + encodeURIComponent(name);
    }

    if (quizData?.settings?.quiz_mode === 'test' || quizData?.settings?.quiz_mode === 'training') {
        document.getElementById('test-section').classList.remove('hidden');
        document.getElementById('test-link').href = base + '&mode=test';
    }
}
</script>
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add play/index.php
git commit -m "feat: add student entry page with name input and activity dashboard"
```

---

## Task 10: Student — flashcard player

**Files:**
- Create: `play/flashcards.php`

- [ ] **Step 1: Create play/flashcards.php**

Simple flip-card UI. No scoring. Front = concept/word, back = translation/description.

```php
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

fetch('../api/quiz-data.php?id=' + encodeURIComponent(QUIZ_ID))
    .then(r => r.json())
    .then(data => {
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
```

- [ ] **Step 2: Commit**

```bash
git add play/flashcards.php
git commit -m "feat: add flashcard player with flip animation and keyboard navigation"
```

---

## Task 11: Integration — verify quiz-engine feedback loop in play/quiz.php

The quiz player (Task 8) has a feedback bug: when the answer is wrong, it shows `feedback.correctAnswer` but that value comes from the engine's internal state which has already shifted the queue. The correct answer needs to be captured BEFORE calling `submitAnswer()`.

**Files:**
- Modify: `play/quiz.php`

- [ ] **Step 1: Fix the feedback flow in play/quiz.php**

In the `handleAnswer` function, capture the correct answer BEFORE calling `engine.submitAnswer()`:

Find this in `play/quiz.php`'s `handleAnswer` function:
```javascript
    function handleAnswer(answer) {
        const currentSpelling = spellingChoice || spellingMode;
        const result = engine.submitAnswer(answer, currentSpelling);
```

Replace with:
```javascript
    function handleAnswer(answer) {
        const currentSpelling = spellingChoice || spellingMode;
        const currentItem = engine.currentItem();
        const currentQ = engine.getQuestion(currentItem);
        const correctAnswer = currentQ.answer;
        const result = engine.submitAnswer(answer, currentSpelling);
```

Then update the wrong-answer feedback block. Find:
```javascript
            setFeedback({ correct: false, correctAnswer: result.type === 'mc'
                ? engine.getQuestion(engine.currentItem())?.answer
                : result.correctAnswer || answer, given: answer });
```

Replace with:
```javascript
            setFeedback({ correct: false, correctAnswer: correctAnswer, given: answer });
```

- [ ] **Step 2: Also fix TTS to use the new current item after answer**

After the `submitAnswer` call, the engine has moved to the next item. The TTS calls should use the NEW current item. Find:
```javascript
        // Correct, next question
        playTTS(item, q);
```

Replace with:
```javascript
        // Correct, next question
        const nextItem = engine.currentItem();
        const nextQ = nextItem ? engine.getQuestion(nextItem) : null;
        playTTS(nextItem, nextQ);
```

Apply the same fix to the directionChange and phaseChange blocks — update the `playTTS` calls to use `engine.currentItem()` and `engine.getQuestion()` after the state change.

- [ ] **Step 3: Commit**

```bash
git add play/quiz.php
git commit -m "fix: capture correct answer before queue shift, fix TTS after answer"
```

---

## Task 12: Smoke test — create a test quiz and verify full flow

**Files:** None (manual testing)

- [ ] **Step 1: Create test data**

Create a file `data/quizzes.json` with a sample glossary quiz for manual testing:

```json
{
    "q_test1": {
        "id": "q_test1",
        "title": "Test Glosquiz",
        "type": "glossary",
        "created": "2026-03-27 10:00:00",
        "teacher_id": "teacher_test",
        "settings": {
            "answer_mode": "hybrid",
            "mc_count": 2,
            "text_count": 2,
            "reverse_enabled": true,
            "reverse_answer_mode": "multiple_choice",
            "reverse_mc_count": 3,
            "reverse_text_count": 0,
            "quiz_mode": "training",
            "tts_enabled": true,
            "language": "es",
            "spelling_mode": "student_choice",
            "time_lock": null,
            "generate_flashcards": true
        },
        "items": [
            {"sentence": "Hola, me llamo Roberto", "word": "llamo", "translation": "heter", "wrong_options": ["bor", "läser", "springer"], "reverse_wrong_options": ["vive", "toma", "mira"]},
            {"sentence": "Yo tengo un gato", "word": "tengo", "translation": "har", "wrong_options": ["är", "vill", "kan"], "reverse_wrong_options": ["soy", "quiero", "puedo"]},
            {"sentence": "Ella come una manzana", "word": "come", "translation": "äter", "wrong_options": ["dricker", "köper", "ser"], "reverse_wrong_options": ["bebe", "compra", "ve"]}
        ],
        "results": []
    }
}
```

- [ ] **Step 2: Open in browser and test**

1. Open `play/index.php?id=q_test1` — verify name input works
2. Click Quiz — verify MC phase shows 2 questions
3. Answer correctly — verify phase transition to text input
4. Complete quiz — verify reverse direction starts automatically
5. Complete reverse — verify diploma screen (Flawless if no errors)
6. Open flashcards — verify flip animation works
7. Open admin dashboard — verify quiz appears in list

- [ ] **Step 3: Commit test data**

```bash
git add data/quizzes.json
git commit -m "test: add sample quiz data for smoke testing"
```

---

## Summary

| Task | What | Files |
|------|------|-------|
| 1 | Extract shared engine (validator, TTS, themes) | `engine/*` |
| 2 | Quiz engine (queue, phases, directions) | `engine/quiz-engine.js` |
| 3 | API endpoints (data, save, clear) | `api/*` |
| 4 | Admin dashboard (compact list) | `admin/dashboard.php` |
| 5 | Admin create quiz form | `admin/create.php` |
| 6 | Admin edit quiz form | `admin/edit.php` |
| 7 | Admin statistics view | `admin/stats.php` |
| 8 | Student quiz player | `play/quiz.php` |
| 9 | Student entry + dashboard | `play/index.php` |
| 10 | Flashcard player | `play/flashcards.php` |
| 11 | Fix feedback/TTS bug | `play/quiz.php` |
| 12 | Smoke test with sample data | `data/quizzes.json` |

Tasks 1-3 are foundation (engine + API). Tasks 4-7 are admin. Tasks 8-10 are student-facing. Task 11 is a known integration fix. Task 12 is verification.
