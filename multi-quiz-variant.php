<?php
require_once 'config.php';

$mq_id = $_GET['mq_id'] ?? '';
$variant = $_GET['variant'] ?? '';
$student_id = $_SESSION['student_id'] ?? '';

// Läs data
$multi_quizzes_file = DATA_DIR . 'multi_quizzes.json';
$multi_quizzes = file_exists($multi_quizzes_file) ? json_decode(file_get_contents($multi_quizzes_file), true) : [];

if (!isset($multi_quizzes[$mq_id])) {
    die("Multi-quiz hittades inte");
}

$mq = $multi_quizzes[$mq_id];
$items = $mq['items'];
$settings = $mq['variants'][$variant] ?? null;

if (!$settings) {
    die("Variant hittades inte eller är inte aktiverad");
}

// Generera speldata baserat på variant
$gameData = [];
$mode = ''; // 'flashcard', 'quiz', 'input'

// --- LOGIK FÖR ATT SKAPA FRÅGOR ---

if ($variant === 'flashcard') {
    $mode = 'flashcard';
    foreach ($items as $item) {
        $gameData[] = [
            'id' => uniqid(),
            'front' => $item['concept'],
            'back' => $item['description']
        ];
    }
} elseif ($variant === 'reverse_flashcard') {
    $mode = 'flashcard';
    foreach ($items as $item) {
        $gameData[] = [
            'id' => uniqid(),
            'front' => $item['description'],
            'back' => $item['concept']
        ];
    }
} elseif ($variant === 'quiz') {
    $mode = 'quiz'; // Bara flerval
    $limit = $settings['mc_count'] ?? 10;
    
    // Slumpa poster
    shuffle($items);
    $selectedItems = array_slice($items, 0, $limit);
    
    foreach ($selectedItems as $item) {
        $options = $item['wrong_answers'] ?? [];
        // Se till att vi har array (kan vara null/tomt)
        if (!is_array($options)) $options = [];
        
        // Ta max 3 fel svar
        if (count($options) > 3) {
            shuffle($options);
            $options = array_slice($options, 0, 3);
        }
        
        // Lägg till rätt svar
        $options[] = $item['concept'];
        shuffle($options); // Blanda
        
        $gameData[] = [
            'question' => $item['question'],
            'correct' => $item['concept'],
            'options' => $options
        ];
    }
} elseif ($variant === 'glossary' || $variant === 'reverse_glossary') {
    $mode = 'input'; // Mix av flerval och skrivsvar
    
    $mc_count = $settings['mc_count'] ?? 10;
    $text_count = $settings['text_count'] ?? 5;
    
    shuffle($items);
    
    // Dela upp i flerval och text
    $mc_items = array_slice($items, 0, $mc_count);
    $text_items = array_slice($items, $mc_count, $text_count);
    
    // Skapa flervalsfrågor
    foreach ($mc_items as $item) {
        $q = '';
        $correct = '';
        $wrongs = $item['wrong_answers'] ?? [];
        if (!is_array($wrongs)) $wrongs = [];
        
        if ($variant === 'glossary') {
            // Begrepp + Mening -> Översättning
            $q = $item['concept'];
            if ($item['example_sentence']) $q .= "<br><span class='text-sm italic text-gray-500'>{$item['example_sentence']}</span>";
            $correct = $item['translation'];
        } else {
            // Översättning -> Begrepp
            $q = $item['translation'];
            $correct = $item['concept'];
        }
        
        // Fixa alternativ
        if (count($wrongs) > 3) {
            shuffle($wrongs);
            $wrongs = array_slice($wrongs, 0, 3);
        }
        
        // VIKTIGT: Se till att rätt svar faktiskt läggs till och inte är tomt
        if ($correct === '') $correct = '(Saknar svar)';
        
        $options = array_merge([$correct], $wrongs);
        shuffle($options);
        
        $gameData[] = [
            'type' => 'mc',
            'question' => $q,
            'correct' => $correct,
            'options' => $options
        ];
    }
    
    // Skapa skrivfrågor
    foreach ($text_items as $item) {
        $q = '';
        $correct = '';
        
        if ($variant === 'glossary') {
            $q = $item['concept'];
             if ($item['example_sentence']) $q .= "<br><span class='text-sm italic text-gray-500'>{$item['example_sentence']}</span>";
            $correct = $item['translation'];
        } else {
            $q = $item['translation'];
            $correct = $item['concept'];
        }
        
        $gameData[] = [
            'type' => 'text',
            'question' => $q,
            'correct' => $correct
        ];
    }
    
    shuffle($gameData); // Blanda ordningen på frågorna
}

$title_map = [
    'glossary' => '📚 Glosor',
    'reverse_glossary' => '🔄 Omvända Glosor',
    'flashcard' => '🗂️ Flashcards',
    'reverse_flashcard' => '🔄 Omvända Flashcards',
    'quiz' => '❓ Quiz'
];
$page_title = $title_map[$variant] ?? 'Quiz';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - <?= htmlspecialchars($mq['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        .card-flip {
            perspective: 1000px;
        }
        .card-inner {
            position: relative;
            width: 100%;
            height: 100%;
            text-align: center;
            transition: transform 0.6s;
            transform-style: preserve-3d;
        }
        .card-flip.flipped .card-inner {
            transform: rotateY(180deg);
        }
        .card-front, .card-back {
            position: absolute;
            width: 100%;
            height: 100%;
            -webkit-backface-visibility: hidden;
            backface-visibility: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }
        .card-back {
            transform: rotateY(180deg);
            background-color: #f3f4f6; /* gray-100 istället för grön */
            border: 2px solid #e5e7eb;
        }
        
        .fade-enter {
            opacity: 0;
            transform: translateY(10px);
        }
        .fade-enter-active {
            opacity: 1;
            transform: translateY(0);
            transition: opacity 300ms, transform 300ms;
        }
        
        /* För att förhindra layout-shift i Quiz */
        .quiz-container {
            min-height: 400px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .options-grid {
            min-height: 240px; /* Reservera plats för knappar */
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col font-sans">

    <!-- Header -->
    <div class="bg-white shadow-sm p-4 sticky top-0 z-10 border-b border-gray-200">
        <div class="max-w-3xl mx-auto flex justify-between items-center">
            <a href="multi-quiz-student.php?id=<?= $mq_id ?>" class="text-gray-500 hover:text-gray-900 font-medium">
                ← Tillbaka
            </a>
            <div class="font-bold text-gray-800"><?= htmlspecialchars($mq['title']) ?></div>
            <div class="text-sm font-medium text-purple-600 bg-purple-50 px-3 py-1 rounded-full">
                <span id="progress-text">1 / <?= count($gameData) ?></span>
            </div>
        </div>
        <!-- Progress bar -->
        <div class="max-w-3xl mx-auto mt-4 h-2 bg-gray-200 rounded-full overflow-hidden">
            <div id="progress-bar" class="h-full bg-purple-600 transition-all duration-300" style="width: 0%"></div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex-1 flex items-center justify-center p-4">
        <div class="w-full max-w-2xl" id="game-container">
            <!-- Innehåll renderas av JS -->
        </div>
        
        <!-- Result Screen -->
        <div id="result-screen" class="hidden text-center w-full max-w-lg bg-white p-8 rounded-2xl shadow-xl border border-gray-200">
            <div class="text-6xl mb-6">🎉</div>
            <h2 class="text-3xl font-bold text-gray-800 mb-4">Bra jobbat!</h2>
            <p class="text-xl text-gray-600 mb-8">Du klarade hela övningen!</p>
            
            <div class="bg-gray-50 rounded-xl p-6 mb-8 border border-gray-200">
                <div class="text-sm text-gray-500 mb-1">Ditt resultat</div>
                <div class="text-4xl font-bold text-green-600" id="final-score"></div>
            </div>
            
            <div class="space-y-3">
                <a href="multi-quiz-student.php?id=<?= $mq_id ?>" class="block w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 px-6 rounded-xl transition transform hover:scale-105 shadow">
                    Tillbaka till menyn
                </a>
                <button onclick="location.reload()" class="block w-full bg-white border-2 border-purple-200 text-purple-700 font-bold py-3 px-6 rounded-xl hover:bg-purple-50 transition">
                    Gör igen 🔄
                </button>
            </div>
        </div>
    </div>

    <script>
        // Initiera data
        let gameData = <?= json_encode($gameData) ?>;
        const mode = '<?= $mode ?>';
        const mqId = '<?= $mq_id ?>';
        const variant = '<?= $variant ?>';
        const studentId = '<?= $student_id ?>';
        
        let currentIndex = 0;
        let score = 0;
        let initialCount = gameData.length; // För att räkna progress baserat på startantal

        const container = document.getElementById('game-container');
        const progressBar = document.getElementById('progress-bar');
        const progressText = document.getElementById('progress-text');
        const resultScreen = document.getElementById('result-screen');
        const finalScore = document.getElementById('final-score');

        // Uppdatera progress. I Flashcards kan length öka, så vi visar bara hur många "unika" som är klara?
        // Nej, om kön växer (pga fel svar) så minskar procenten. Det är logiskt.
        function updateProgress() {
            const pct = (currentIndex / gameData.length) * 100;
            progressBar.style.width = pct + '%';
            progressText.textContent = `${currentIndex + 1} / ${gameData.length}`;
        }

        function showResult() {
            container.classList.add('hidden');
            resultScreen.classList.remove('hidden');
            finalScore.textContent = `${score} / ${initialCount} rätt på första försöket`; // Visar poäng baserat på "flyt"
            
            confetti({ particleCount: 150, spread: 70, origin: { y: 0.6 } });
            
            // Spara
            fetch('api/save-multi-progress.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mq_id: mqId, variant: variant, student_id: studentId })
            });

            if (studentId) {
                const localProgress = JSON.parse(localStorage.getItem('multiQuizProgress') || '{}');
                if (!localProgress[mqId]) localProgress[mqId] = {};
                if (!localProgress[mqId][studentId]) localProgress[mqId][studentId] = {};
                localProgress[mqId][studentId][variant] = new Date().toISOString();
                localStorage.setItem('multiQuizProgress', JSON.stringify(localProgress));
            }
        }

        function renderCard() {
            if (currentIndex >= gameData.length) {
                showResult();
                return;
            }
            
            updateProgress();
            const item = gameData[currentIndex];
            container.innerHTML = '';
            
            // --- FLASHCARD MODE ---
            if (mode === 'flashcard') {
                const card = document.createElement('div');
                card.className = 'w-full h-96 bg-white rounded-2xl shadow-xl cursor-pointer card-flip select-none';
                
                // Gemensam stil för text (centrerad, samma storlek)
                const textStyle = "text-3xl font-bold text-gray-800 text-center px-4 leading-tight";
                
                card.innerHTML = `
                    <div class="card-inner">
                        <div class="card-front bg-white rounded-2xl border border-gray-200 p-8 shadow-sm">
                            <span class="absolute top-8 text-xs font-bold text-gray-400 uppercase tracking-widest">Fråga</span>
                            <h2 class="${textStyle}">${item.front}</h2>
                            <p class="absolute bottom-8 text-gray-400 text-sm">(Klicka för att vända)</p>
                        </div>
                        <div class="card-back bg-white rounded-2xl border-2 border-gray-100 p-8">
                            <span class="absolute top-8 text-xs font-bold text-gray-400 uppercase tracking-widest">Svar</span>
                            <h2 class="${textStyle}">${item.back}</h2>
                            
                            <div class="absolute bottom-8 flex gap-4 w-full px-8">
                                <button onclick="nextCard(false)" class="flex-1 bg-red-100 hover:bg-red-200 text-red-700 py-3 rounded-xl font-bold transition">
                                    Behöver öva mer
                                </button>
                                <button onclick="nextCard(true)" class="flex-1 bg-green-500 hover:bg-green-600 text-white py-3 rounded-xl font-bold transition shadow-lg hover:shadow-xl transform active:scale-95">
                                    Jag kunde den!
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                
                card.addEventListener('click', (e) => {
                    if (e.target.closest('button')) return; 
                    card.classList.toggle('flipped');
                });
                
                container.appendChild(card);
            }
            
            // --- QUIZ / INPUT MODE ---
            else if (mode === 'quiz' || mode === 'input') {
                const isMc = mode === 'quiz' || item.type === 'mc';
                
                const wrapper = document.createElement('div');
                wrapper.className = 'bg-white rounded-2xl shadow-lg border border-gray-200 p-8 fade-enter-active quiz-container';
                
                let html = `
                    <div class="mb-6 min-h-[60px] flex items-center justify-center">
                        <h2 class="text-2xl font-bold text-gray-800 text-center leading-snug">${item.question}</h2>
                    </div>
                `;
                
                if (isMc) {
                    html += `<div class="options-grid">`;
                    item.options.forEach(opt => {
                        // Säkra escape av strängar
                        const optSafe = opt.replace(/'/g, "\\'").replace(/"/g, '&quot;');
                        html += `
                            <button onclick="checkAnswer(this, '${optSafe}')" class="w-full text-left px-6 py-4 rounded-xl border-2 border-gray-100 hover:border-purple-200 hover:bg-purple-50 transition text-lg group relative font-medium text-gray-700 bg-white">
                                <span class="group-hover:text-purple-700">${opt}</span>
                            </button>
                        `;
                    });
                    html += `</div>`;
                } else {
                    html += `
                        <div class="space-y-6 py-4">
                            <input type="text" id="text-input" class="w-full p-5 text-xl border-2 border-gray-200 rounded-xl focus:border-purple-500 focus:ring-4 focus:ring-purple-50 outline-none transition text-center font-medium" placeholder="Skriv svaret här..." autocomplete="off">
                            <button onclick="checkTextAnswer()" class="w-full bg-purple-600 text-white font-bold py-4 rounded-xl hover:bg-purple-700 transition shadow-lg text-lg hover:shadow-xl transform active:scale-[0.98]">
                                Svara
                            </button>
                        </div>
                    `;
                }
                
                // Feedback container (alltid renderad men osynlig för att hålla layouten)
                html += `
                    <div class="mt-6 h-[80px] relative">
                         <div id="feedback" class="hidden absolute inset-0 flex items-center justify-center rounded-xl font-bold text-center"></div>
                         <button id="next-btn" onclick="nextQuestion()" class="hidden absolute inset-0 w-full bg-gray-900 text-white font-bold rounded-xl hover:bg-black transition shadow-lg z-10 flex items-center justify-center gap-2">
                            Nästa fråga →
                         </button>
                    </div>
                `;
                
                wrapper.innerHTML = html;
                container.appendChild(wrapper);
                
                if (!isMc) {
                    const input = document.getElementById('text-input');
                    setTimeout(() => input.focus(), 100);
                    input.addEventListener('keypress', (e) => {
                        if (e.key === 'Enter') checkTextAnswer();
                    });
                }
            }
        }
        
        // --- EVENT HANDLERS ---
        
        window.nextCard = function(known) {
            const item = gameData[currentIndex];
            
            if (known) {
                score++; // Poäng bara om man kan den direkt (eller första gången den dyker upp)
            } else {
                // Lägg till kortet sist i listan igen för repetition!
                // Vi klonar itemet och ger nytt ID för säkerhets skull
                const newItem = {...item, id: Date.now()};
                gameData.push(newItem);
            }
            
            currentIndex++;
            renderCard();
        };
        
        window.checkAnswer = function(btn, selected) {
            const item = gameData[currentIndex];
            const feedback = document.getElementById('feedback');
            const nextBtn = document.getElementById('next-btn');
            const buttons = container.querySelectorAll('.options-grid button');
            
            // Inaktivera alla knappar
            buttons.forEach(b => b.disabled = true);
            
            // Normalisera för jämförelse (trimma whitespace och lowercase)
            if (selected.trim().toLowerCase() === item.correct.trim().toLowerCase()) {
                // RÄTT
                btn.classList.remove('border-gray-100', 'bg-white');
                btn.classList.add('bg-green-100', 'border-green-500', 'text-green-800');
                
                feedback.className = 'absolute inset-0 flex items-center justify-center rounded-xl font-bold text-center bg-green-100 text-green-700 border border-green-200 fade-enter-active';
                feedback.innerHTML = '<span class="text-2xl mr-2">✨</span> Rätt svar!';
                feedback.classList.remove('hidden');
                
                if (gameData.length === initialCount) score++; // Poäng om det inte är en "omgörning" (ej implementerat för quiz än, men bra att ha)
                
                setTimeout(nextQuestion, 1200);
            } else {
                // FEL
                btn.classList.remove('border-gray-100', 'bg-white');
                btn.classList.add('bg-red-50', 'border-red-500', 'text-red-800');
                
                // Visa rätta svaret på RÄTT knapp
                buttons.forEach(b => {
                    if (b.innerText.trim().toLowerCase() === item.correct.trim().toLowerCase()) {
                        b.classList.remove('bg-white', 'border-gray-100');
                        b.classList.add('bg-green-100', 'border-green-500', 'text-green-800', 'ring-2', 'ring-green-400');
                    }
                });
                
                // Visa Nästa-knappen direkt vid fel så man hinner se vad som var rätt
                nextBtn.classList.remove('hidden');
                nextBtn.focus();
            }
        };
        
        window.checkTextAnswer = function() {
            const input = document.getElementById('text-input');
            const feedback = document.getElementById('feedback');
            const nextBtn = document.getElementById('next-btn');
            const item = gameData[currentIndex];
            
            if(!input.value.trim()) return;
            
            input.disabled = true;
            document.querySelector('button[onclick="checkTextAnswer()"]').disabled = true;
            
            if (input.value.trim().toLowerCase() === item.correct.toLowerCase()) {
                input.classList.remove('border-gray-200');
                input.classList.add('border-green-500', 'bg-green-50', 'text-green-800');
                
                feedback.className = 'absolute inset-0 flex items-center justify-center rounded-xl font-bold text-center bg-green-100 text-green-700 border border-green-200 fade-enter-active';
                feedback.innerHTML = '<span class="text-2xl mr-2">✨</span> Rätt!';
                feedback.classList.remove('hidden');
                
                score++;
                setTimeout(nextQuestion, 1200);
            } else {
                input.classList.remove('border-gray-200');
                input.classList.add('border-red-500', 'bg-red-50', 'text-red-900');
                
                feedback.className = 'absolute inset-0 flex flex-col items-center justify-center rounded-xl text-center bg-red-50 text-red-800 border border-red-200 fade-enter-active p-2';
                feedback.innerHTML = `<span class="text-xs uppercase font-bold tracking-wider text-red-400 mb-1">Rätt svar var</span><span class="text-lg font-bold">${item.correct}</span>`;
                feedback.classList.remove('hidden');
                
                nextBtn.classList.remove('hidden');
                nextBtn.focus();
            }
        };

        window.nextQuestion = function() {
            currentIndex++;
            renderCard();
        };

        // Start
        renderCard();

    </script>
</body>
</html>
