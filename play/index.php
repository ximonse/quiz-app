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
        const savedName = sessionStorage.getItem('quiz-student-' + QUIZ_ID);
        if (savedName) {
            document.getElementById('name-input').value = savedName;
            enterQuiz();
        }
    });

function enterQuiz(e) {
    if (e) e.preventDefault();
    const name = document.getElementById('name-input').value.trim();
    if (!name) return;
    sessionStorage.setItem('quiz-student-' + QUIZ_ID, name);

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
