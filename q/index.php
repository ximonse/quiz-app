<?php
// Denna fil hanterar dynamisk routing för quiz-sidor
// URL: /quiz-app/q/abc123.html -> laddar quiz med ID abc123

require_once '../config.php';

// Hämta quiz ID från URL
$request_uri = $_SERVER['REQUEST_URI'];
preg_match('/\/q\/([a-f0-9]+)\.html/', $request_uri, $matches);

if (!isset($matches[1])) {
    http_response_code(404);
    echo "Quiz inte hittad";
    exit;
}

$quiz_id = $matches[1];

// Ladda quiz data
$quizzes = readJSON(QUIZZES_FILE);

if (!isset($quizzes[$quiz_id])) {
    http_response_code(404);
    echo "Quiz inte hittad";
    exit;
}

$quiz = $quizzes[$quiz_id];

// Kolla om quizet är inaktiverat
if (isset($quiz['active']) && $quiz['active'] === false) {
    http_response_code(403);
    echo "Detta quiz är för tillfället inaktiverat av läraren.";
    exit;
}
$quiz_json = json_encode($quiz, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title><?= htmlspecialchars($quiz['title']) ?> - Quiz</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
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
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
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

        body.psychedelic-mode * {
            position: relative;
            z-index: 1;
        }

        @keyframes softBlink {
            0%, 100% { background-color: rgb(220, 252, 231); }
            50% { background-color: rgb(134, 239, 172); }
        }
        .soft-blink {
            animation: softBlink 0.5s ease-in-out 3;
        }
        body {
            -webkit-tap-highlight-color: transparent;
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            user-select: none;
            transition: background 0.3s ease;
        }
        input, button {
            -webkit-user-select: text;
            user-select: text;
        }
        /* Tillåt textmarkering i quiz-innehåll */
        h2, p, .text-3xl, .text-2xl, .text-lg, button[type="button"] {
            -webkit-user-select: text;
            user-select: text;
        }
        /* Confetti animation */
        @keyframes confetti-fall {
            0% { transform: translateY(-100vh) rotate(0deg); opacity: 1; }
            100% { transform: translateY(100vh) rotate(720deg); opacity: 0; }
        }
        .confetti {
            position: fixed;
            width: 10px;
            height: 10px;
            z-index: 9999;
            pointer-events: none;
        }
        /* Bildvisning A7-format */
        .question-image {
            max-width: 300px;
            width: 100%;
            aspect-ratio: 74 / 105;
            object-fit: contain;
            border-radius: 0.5rem;
            margin: 1rem auto;
            display: block;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div id="root"></div>

    <script type="text/babel">
        const { useState, useEffect } = React;
        const quizData = <?= $quiz_json ?>;

        function QuizApp() {
            const [studentName, setStudentName] = useState('');
            const [hasStarted, setHasStarted] = useState(false);
            const [phase, setPhase] = useState(1); // 1 = Flerval, 2 = Fritext
            const [questions, setQuestions] = useState([]);
            const [currentQueue, setCurrentQueue] = useState([]);
            const [currentQuestionIndex, setCurrentQuestionIndex] = useState(0);
            const [progress, setProgress] = useState({});
            const [selectedAnswer, setSelectedAnswer] = useState(null);
            const [showFeedback, setShowFeedback] = useState(false);
            const [isCorrect, setIsCorrect] = useState(false);
            const [textAnswer, setTextAnswer] = useState('');
            const [isComplete, setIsComplete] = useState(false);
            const [timer, setTimer] = useState(0);
            const [timerRunning, setTimerRunning] = useState(false);
            const [errorCount, setErrorCount] = useState(0);
            const [questionErrors, setQuestionErrors] = useState({});
            const [theme, setTheme] = useState('light'); // 'light', 'night', 'night-magenta', 'psychedelic'
            const [milestonesReached, setMilestonesReached] = useState([false, false, false, false]); // För progress 1,2,3,4
            const [spellingMode, setSpellingMode] = useState('student_choice'); // easy, puritan, student_choice
            const teacherSpellingMode = quizData.spelling_mode || 'student_choice';
            const [misspellings, setMisspellings] = useState([]); // För glosquiz statistik
            const [isMuted, setIsMuted] = useState(false); // Mute autouppläsning
            const [selectedVoice, setSelectedVoice] = useState(null); // Manuellt vald röst
            const [availableVoices, setAvailableVoices] = useState([]); // Tillgängliga röster
            const isGlossary = quizData.type === 'glossary';
            const quizLanguage = quizData.language || 'sv';
            const [direction, setDirection] = useState('forward'); // forward | reverse

            // Inställningar per riktning
            const reverseEnabled = isGlossary && (
                quizData.reverse_enabled === true ||
                quizData.reverse_enabled === 1 ||
                quizData.reverse_enabled === '1'
            );
            const forwardSettings = {
                answerMode: quizData.answer_mode || 'hybrid',
                requiredPhase1: quizData.required_correct_phase1 || 2,
                requiredPhase2: quizData.required_correct_phase2 || 2
            };
            const reverseSettings = {
                answerMode: quizData.reverse_answer_mode || 'hybrid',
                requiredPhase1: quizData.reverse_required_correct_phase1 || 2,
                requiredPhase2: quizData.reverse_required_correct_phase2 || 2
            };
            const currentSettings = direction === 'reverse' ? reverseSettings : forwardSettings;
            const answerMode = currentSettings.answerMode;
            const requiredPhase1 = currentSettings.requiredPhase1;
            const requiredPhase2 = currentSettings.requiredPhase2;
            const isReverseDirection = direction === 'reverse';

            // Fisher-Yates shuffle
            function shuffleArray(array) {
                const arr = [...array];
                for (let i = arr.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [arr[i], arr[j]] = [arr[j], arr[i]];
                }
                return arr;
            }


            // Pepp-popup baserat på progress-nivå
            function showPeppMessage(level) {
                const messages = {
                    1: '💪 Bra jobbat!',
                    2: '🔥 Starkt jobbat!',
                    3: '⭐ Fortsätt så!',
                    4: '🎉 Fantastiskt!'
                };
                const message = messages[level] || '🎉 Snyggt!';

                // Ta bort eventuella tidigare popups för att undvika hackning
                const existingPopups = document.querySelectorAll('.pepp-popup');
                existingPopups.forEach(p => p.remove());

                const popup = document.createElement('div');
                popup.className = 'pepp-popup';
                popup.textContent = message;
                popup.style.position = 'fixed';
                popup.style.top = '50%';
                popup.style.left = '50%';
                popup.style.transform = 'translate(-50%, -50%)';
                popup.style.fontSize = '56px';
                popup.style.fontWeight = 'bold';
                popup.style.color = '#ffd700';
                popup.style.textShadow = '4px 4px 8px rgba(0,0,0,0.6)';
                popup.style.zIndex = '10000';
                popup.style.pointerEvents = 'none';
                popup.style.willChange = 'transform, opacity'; // Hjälper med prestanda

                const animation = popup.animate([
                    { opacity: 0, transform: 'translate(-50%, -50%) scale(0.3)' },
                    { opacity: 1, transform: 'translate(-50%, -50%) scale(1.15)', offset: 0.3 },
                    { opacity: 1, transform: 'translate(-50%, -50%) scale(1.15)', offset: 0.7 },
                    { opacity: 0, transform: 'translate(-50%, -50%) scale(1.4)' }
                ], {
                    duration: 2500,
                    easing: 'cubic-bezier(0.34, 1.56, 0.64, 1)'
                });

                document.body.appendChild(popup);

                animation.onfinish = () => {
                    popup.remove();
                };
            }

            // Kolla om alla frågor har nått en viss progress-nivå
            function checkMilestone(progressObj, level) {
                const allReached = Object.values(progressObj).every(p => p >= level);
                if (allReached && !milestonesReached[level - 1]) {
                    const newMilestones = [...milestonesReached];
                    newMilestones[level - 1] = true;
                    setMilestonesReached(newMilestones);

                    if (level === 4) {
                        // Sista milstolpen = konfetti bonanza!
                        createBigConfetti();
                    }
                    showPeppMessage(level);
                }
            }

            // Stor confetti-effekt vid slutet
            function createBigConfetti() {
                const colors = ['#ff0000', '#00ff00', '#0000ff', '#ffff00', '#ff00ff', '#00ffff', '#ffa500', '#ff1493', '#ffd700', '#7fff00'];
                const confettiCount = 100;

                for (let i = 0; i < confettiCount; i++) {
                    setTimeout(() => {
                        const confetti = document.createElement('div');
                        confetti.className = 'confetti';
                        confetti.style.left = Math.random() * 100 + '%';
                        confetti.style.width = (8 + Math.random() * 8) + 'px';
                        confetti.style.height = (8 + Math.random() * 8) + 'px';
                        confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                        confetti.style.animation = `confetti-fall ${2 + Math.random() * 3}s linear`;
                        confetti.style.borderRadius = Math.random() > 0.5 ? '50%' : '0';
                        document.body.appendChild(confetti);

                        setTimeout(() => confetti.remove(), 5000);
                    }, i * 20);
                }
            }


            // Levenshtein distance
            function levenshteinDistance(a, b) {
                const matrix = [];
                for (let i = 0; i <= b.length; i++) {
                    matrix[i] = [i];
                }
                for (let j = 0; j <= a.length; j++) {
                    matrix[0][j] = j;
                }
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

            // Stavfelstolerans med Easy/Puritan modes
            function isAnswerCorrect(userAnswer, correctAnswer, mode = spellingMode) {
                const user = userAnswer.toLowerCase().trim().replace(/\s+/g, ' ');
                const correct = correctAnswer.toLowerCase().trim().replace(/\s+/g, ' ');

                // Exakt match (fungerar i alla lägen)
                if (user === correct) return { correct: true, type: 'exact' };

                // Puritan mode: endast exakt match accepteras
                if (mode === 'puritan') {
                    return { correct: false, type: 'wrong' };
                }

                // Easy mode: generösare tolerans
                if (mode === 'easy' || mode === 'student_choice') {
                    // Suffix-tolerans (schimpanser vs schimpansernas)
                    if (user.startsWith(correct) || correct.startsWith(user)) {
                        const diff = Math.abs(user.length - correct.length);
                        if (diff <= 3) return { correct: true, type: 'suffix' };
                    }

                    // Levenshtein distance 20% (1-2 bokstäver fel)
                    const maxDistance = Math.ceil(correct.length * 0.2);
                    const distance = levenshteinDistance(user, correct);
                    if (distance <= maxDistance && distance > 0) {
                        return { correct: true, type: 'typo' };
                    }
                    if (distance > maxDistance) {
                        return { correct: false, type: 'wrong' };
                    }
                }

                return { correct: false, type: 'wrong' };
            }

            // Initiera quiz
            useEffect(() => {
                if (hasStarted && questions.length === 0) {
                    const allGlossaryWords = quizData.questions
                        .map(q => (q.word || '').trim())
                        .filter(Boolean);

                    const qs = quizData.questions.map((q, i) => ({
                        ...q,
                        index: i,
                        shuffledOptions: shuffleArray(q.options || []),
                        reverseOptions: (() => {
                            if (!isGlossary || !q.word) {
                                return [];
                            }
                            const normalizedWord = q.word.toLowerCase().trim();
                            const explicitReverseWrong = Array.isArray(q.reverse_wrong_options)
                                ? q.reverse_wrong_options
                                    .map(w => (w || '').trim())
                                    .filter(Boolean)
                                    .filter(w => w.toLowerCase().trim() !== normalizedWord)
                                : [];
                            const fallbackWrong = allGlossaryWords.filter(w => w.toLowerCase().trim() !== normalizedWord);
                            const mergedWrong = Array.from(new Set([...explicitReverseWrong, ...fallbackWrong]));
                            const pickedWrong = shuffleArray(mergedWrong).slice(0, 3);
                            const candidate = [q.word, ...pickedWrong];
                            return shuffleArray(Array.from(new Set(candidate)));
                        })()
                    }));
                    setQuestions(qs);
                    setCurrentQueue(qs.map((_, i) => i));

                    // Initiera progress
                    const prog = {};
                    qs.forEach((_, i) => { prog[i] = 0; });
                    setProgress(prog);

                    // Initiera error tracking
                    const errors = {};
                    qs.forEach((_, i) => { errors[i] = 0; });
                    setQuestionErrors(errors);

                    setTimerRunning(true);
                }
            }, [hasStarted]);

            // Timer
            useEffect(() => {
                let interval;
                if (timerRunning) {
                    interval = setInterval(() => {
                        setTimer(t => t + 1);
                    }, 1000);
                }
                return () => clearInterval(interval);
            }, [timerRunning]);

            // Autouppläsning för glosquiz vid ny fråga
            useEffect(() => {
                if (hasStarted && isGlossary && !isReverseDirection && currentQueue.length > 0 && !isComplete && !isMuted) {
                    const currentQ = questions[currentQueue[currentQuestionIndex]];
                    if (currentQ && currentQ.word) {
                        setTimeout(() => {
                            speakGlossary(currentQ.question, currentQ.word);
                        }, 500);
                    }
                }
            }, [currentQuestionIndex, hasStarted, currentQueue.length, isReverseDirection, isMuted, isComplete, questions]);

            // Autofokusera textinput i fas 2 när fråga ändras
            useEffect(() => {
                if (hasStarted && (phase === 2 || answerMode === 'text_only') && !showFeedback && currentQueue.length > 0) {
                    const input = document.querySelector('input[type="text"][placeholder="Skriv ditt svar här..."]');
                    if (input) {
                        setTimeout(() => input.focus(), 100);
                    }
                }
            }, [currentQuestionIndex, showFeedback, phase, answerMode]);

            // Ladda tillgängliga röster
            useEffect(() => {
                const loadVoices = () => {
                    const voices = window.speechSynthesis.getVoices();
                    setAvailableVoices(voices);

                    // För iPad/iPhone med spanska: sätt Paulina som standard
                    if (quizLanguage === 'es' && /iPad|iPhone|iPod/.test(navigator.userAgent)) {
                        const paulina = voices.find(v => v.name.includes('Paulina'));
                        if (paulina) {
                            setSelectedVoice(paulina);
                        }
                    }
                };

                loadVoices();
                if (window.speechSynthesis.onvoiceschanged !== undefined) {
                    window.speechSynthesis.onvoiceschanged = loadVoices;
                }
            }, []);

            // Theme toggle
            useEffect(() => {
                document.body.classList.remove('night-mode', 'night-magenta-mode', 'psychedelic-mode');
                if (theme === 'night') {
                    document.body.classList.add('night-mode');
                } else if (theme === 'night-magenta') {
                    document.body.classList.add('night-magenta-mode');
                } else if (theme === 'psychedelic') {
                    document.body.classList.add('psychedelic-mode');
                }
            }, [theme]);

            function cycleTheme() {
                setTheme(t => {
                    if (t === 'light') return 'night';
                    if (t === 'night') return 'night-magenta';
                    if (t === 'night-magenta') return 'psychedelic';
                    return 'light';
                });
            }

            function getThemeIcon() {
                if (theme === 'light') return '☀️';
                if (theme === 'night') return '🌙';
                if (theme === 'night-magenta') return '💜';
                return '🌈';
            }

            // Talsyntes med språkstöd
            function speakText(text, lang = quizLanguage) {
                if ('speechSynthesis' in window) {
                    window.speechSynthesis.cancel();
                    const utterance = new SpeechSynthesisUtterance(text);

                    // Språkkoder
                    const langMap = {
                        'sv': 'sv-SE',
                        'en': 'en-US',
                        'es': 'es-ES',
                        'fr': 'fr-FR',
                        'de': 'de-DE',
                        'uk': 'uk'
                    };

                    utterance.lang = langMap[lang] || 'sv-SE';

                    // Använd manuellt vald röst om den finns
                    let voice = selectedVoice;

                    // Annars, försök hitta en röst för rätt språk
                    if (!voice) {
                        const voices = window.speechSynthesis.getVoices();
                        const targetLang = langMap[lang] || 'sv-SE';
                        voice = voices.find(v => v.lang.startsWith(targetLang.substring(0, 2))) ||
                                      voices.find(v => v.lang === targetLang);

                        // För tyska, försök även med varianter om ingen röst hittades
                        if (!voice && lang === 'de') {
                            voice = voices.find(v => v.lang.includes('de') || v.lang.includes('DE') || v.name.toLowerCase().includes('german') || v.name.toLowerCase().includes('deutsch'));
                        }

                        // För franska, försök även med varianter om ingen röst hittades
                        if (!voice && lang === 'fr') {
                            voice = voices.find(v => v.lang.includes('fr') || v.lang.includes('FR') || v.name.toLowerCase().includes('french') || v.name.toLowerCase().includes('français'));
                        }
                    }

                    if (voice) {
                        utterance.voice = voice;
                    }

                    utterance.rate = 0.9;
                    window.speechSynthesis.speak(utterance);
                }
            }

            // För glosquiz: läs mening + ord separat
            function speakGlossary(sentence, word) {
                if ('speechSynthesis' in window) {
                    window.speechSynthesis.cancel();

                    const langMap = {
                        'sv': 'sv-SE',
                        'en': 'en-US',
                        'es': 'es-ES',
                        'fr': 'fr-FR',
                        'de': 'de-DE',
                        'uk': 'uk'
                    };

                    const doSpeak = () => {
                        // Läs meningen
                        const utterance1 = new SpeechSynthesisUtterance(sentence);
                        const targetLang = langMap[quizLanguage] || 'sv-SE';
                        utterance1.lang = targetLang;

                        // Försök hitta en röst för rätt språk
                        const voices = window.speechSynthesis.getVoices();

                        // Använd manuellt vald röst om den finns
                        let voice = selectedVoice;

                        // Annars, sök efter röst på flera sätt
                        if (!voice) {
                            voice = voices.find(v => v.lang === targetLang) ||
                                   voices.find(v => v.lang.startsWith(targetLang.substring(0, 2))) ||
                                   voices.find(v => v.lang.toLowerCase().includes(targetLang.substring(0, 2).toLowerCase()));

                            // För spanska, försök även med varianter (ES, MX, AR etc)
                            if (!voice && quizLanguage === 'es') {
                                voice = voices.find(v => v.lang.includes('es') || v.lang.includes('ES') || v.name.toLowerCase().includes('spanish') || v.name.toLowerCase().includes('español'));
                            }

                            // För tyska, försök även med varianter (DE-DE, DE-AT, DE-CH etc)
                            if (!voice && quizLanguage === 'de') {
                                voice = voices.find(v => v.lang.includes('de') || v.lang.includes('DE') || v.name.toLowerCase().includes('german') || v.name.toLowerCase().includes('deutsch'));
                            }

                            // För franska, försök även med varianter (FR-FR, FR-CA, FR-BE etc)
                            if (!voice && quizLanguage === 'fr') {
                                voice = voices.find(v => v.lang.includes('fr') || v.lang.includes('FR') || v.name.toLowerCase().includes('french') || v.name.toLowerCase().includes('français'));
                            }

                            // För ukrainska, försök även med varianter
                            if (!voice && quizLanguage === 'uk') {
                                voice = voices.find(v => v.lang.includes('uk') || v.lang.includes('UA') || v.name.toLowerCase().includes('ukrain'));
                            }
                        }

                        if (voice) {
                            utterance1.voice = voice;
                        }
                        utterance1.rate = 0.9;

                        // Läs ordet separat efter en paus
                        utterance1.onend = () => {
                            setTimeout(() => {
                                const utterance2 = new SpeechSynthesisUtterance(word);
                                utterance2.lang = targetLang;
                                if (voice) {
                                    utterance2.voice = voice;
                                }
                                utterance2.rate = 0.85;
                                window.speechSynthesis.speak(utterance2);
                            }, 300);
                        };

                        window.speechSynthesis.speak(utterance1);
                    };

                    // Vänta på att röster laddas (viktigt för iPad/Safari)
                    const voices = window.speechSynthesis.getVoices();
                    if (voices.length === 0) {
                        window.speechSynthesis.addEventListener('voiceschanged', doSpeak, { once: true });
                    } else {
                        doSpeak();
                    }
                }
            }

            // Bakåtkompatibilitet
            function speakQuestion(text) {
                speakText(text);
            }

            function getCorrectAnswerForQuestion(q) {
                if (isGlossary && isReverseDirection) {
                    return q.word || '';
                }
                return q.answer || '';
            }

            function getOptionsForQuestion(q) {
                if (isGlossary && isReverseDirection) {
                    return q.reverseOptions || [];
                }
                return q.shuffledOptions || [];
            }

            function startDirectionRound(nextDirection) {
                const nextMode = nextDirection === 'reverse'
                    ? reverseSettings.answerMode
                    : forwardSettings.answerMode;
                const freshQueue = questions.map((_, i) => i);
                const freshProgress = {};
                questions.forEach((_, i) => {
                    freshProgress[i] = 0;
                });

                setDirection(nextDirection);
                setPhase(nextMode === 'text_only' ? 2 : 1);
                setCurrentQueue(freshQueue);
                setCurrentQuestionIndex(0);
                setProgress(freshProgress);
                setMilestonesReached([false, false, false, false]);
                setShowFeedback(false);
                setSelectedAnswer(null);
                setTextAnswer('');
            }

            function finishDirectionOrQuiz() {
                if (isGlossary && reverseEnabled && !isReverseDirection) {
                    startDirectionRound('reverse');
                    return;
                }

                setIsComplete(true);
                setTimerRunning(false);
                saveStats();
            }

            function handleStart() {
                if (studentName.trim()) {
                    // För text_only mode: starta direkt i fas 2
                    if (answerMode === 'text_only') {
                        setPhase(2);
                    }
                    // För glosquiz: kolla localStorage för senaste session
                    else if (isGlossary && answerMode === 'hybrid') {
                        const storageKey = `glossary_${quizData.id}`;
                        const lastSessionData = localStorage.getItem(storageKey);

                        if (lastSessionData) {
                            try {
                                const session = JSON.parse(lastSessionData);
                                const lastTime = new Date(session.timestamp).getTime();
                                const now = new Date().getTime();
                                const hoursDiff = (now - lastTime) / (1000 * 60 * 60);

                                // Dag 2-läge aktiveras bara om:
                                // 1. <25h sen senast
                                // 2. Senaste sessionen var fullständigt klarad
                                // 3. Det var i puritan mode
                                if (hoursDiff < 25 && session.completed && session.mode === 'puritan') {
                                    setPhase(2);
                                }
                            } catch (e) {
                                // Gammal format (bara timestamp) - ignorera
                                console.log('Old localStorage format, resetting');
                            }
                        }
                    }

                    setHasStarted(true);
                }
            }

            function handleMultipleChoice(optionIndex) {
                if (showFeedback) return;

                const currentQ = questions[currentQueue[currentQuestionIndex]];
                const currentOptions = getOptionsForQuestion(currentQ);
                const correctAnswer = getCorrectAnswerForQuestion(currentQ);
                const selectedOption = currentOptions[optionIndex];
                const correct = selectedOption === correctAnswer;

                setSelectedAnswer(optionIndex);
                setIsCorrect(correct);
                setShowFeedback(true);

                if (correct) {
                    // Öka progress
                    const qIdx = currentQueue[currentQuestionIndex];
                    const newProg = { ...progress, [qIdx]: progress[qIdx] + 1 };
                    setProgress(newProg);

                    // Kolla milstolpar (1, 2, 3, 4 rader gröna)
                    for (let level = 1; level <= 4; level++) {
                        checkMilestone(newProg, level);
                    }

                    // För multiple_choice mode: kräv requiredPhase2 rätt innan klar
                    // För hybrid mode: kräv requiredPhase1 rätt innan gå till fas 2
                    const requiredCorrect = answerMode === 'multiple_choice' ? requiredPhase2 : requiredPhase1;

                    if (newProg[qIdx] >= requiredCorrect) {
                        setTimeout(() => {
                            const newQueue = currentQueue.filter((_, i) => i !== currentQuestionIndex);
                            setCurrentQueue(newQueue);

                            if (newQueue.length === 0) {
                                // Fas 1 klar!
                                if (answerMode === 'multiple_choice') {
                                    finishDirectionOrQuiz();
                                } else {
                                    // hybrid mode: gå till fas 2
                                    setPhase(2);
                                    setCurrentQueue(questions.map((_, i) => i));
                                    setCurrentQuestionIndex(0);
                                }
                            } else {
                                setCurrentQuestionIndex(Math.min(currentQuestionIndex, newQueue.length - 1));
                            }

                            setShowFeedback(false);
                            setSelectedAnswer(null);
                        }, 1500);
                    } else {
                        // Gå till nästa fråga
                        setTimeout(() => {
                            setCurrentQuestionIndex((currentQuestionIndex + 1) % currentQueue.length);
                            setShowFeedback(false);
                            setSelectedAnswer(null);
                        }, 1500);
                    }
                } else {
                    // Fel svar - räkna fel och flytta frågan till slutet
                    setErrorCount(c => c + 1);
                    const qIdx = currentQueue[currentQuestionIndex];
                    setQuestionErrors(prev => ({ ...prev, [qIdx]: prev[qIdx] + 1 }));

                    setTimeout(() => {
                        const newQueue = [...currentQueue];
                        const [removed] = newQueue.splice(currentQuestionIndex, 1);
                        newQueue.push(removed);
                        setCurrentQueue(newQueue);
                        setCurrentQuestionIndex(currentQuestionIndex % newQueue.length);
                        setShowFeedback(false);
                        setSelectedAnswer(null);
                    }, 2500);
                }
            }

            function handleFreeText(e) {
                e.preventDefault();
                if (showFeedback || !textAnswer.trim()) return;

                const currentQ = questions[currentQueue[currentQuestionIndex]];
                const correctAnswer = getCorrectAnswerForQuestion(currentQ);
                const result = isAnswerCorrect(textAnswer, correctAnswer, spellingMode);

                setIsCorrect(result.correct);
                setShowFeedback(true);

                // För glosquiz: spara felstavningar
                if (isGlossary && !isReverseDirection && !result.correct && result.type === 'wrong') {
                    setMisspellings(prev => [...prev, {
                        correct: correctAnswer,
                        misspelled: textAnswer.trim()
                    }]);
                }

                if (result.correct) {
                    const qIdx = currentQueue[currentQuestionIndex];
                    const newProg = { ...progress, [qIdx]: progress[qIdx] + 1 };
                    setProgress(newProg);

                    // Kolla milstolpar (1, 2, 3, 4 rader gröna)
                    for (let level = 1; level <= 4; level++) {
                        checkMilestone(newProg, level);
                    }

                    // För glosquiz fas 2 (efter 25h - dag 2): 1 färre rätt krävs (minimum 1)
                    const requiredCorrect = (isGlossary && !isReverseDirection && phase === 2 && currentQueue.length === questions.length)
                        ? Math.max(1, requiredPhase2 - 1)
                        : requiredPhase2;

                    // Om nått required antal rätt, ta bort från kön
                    if (newProg[qIdx] >= requiredCorrect) {
                        setTimeout(() => {
                            const newQueue = currentQueue.filter((_, i) => i !== currentQuestionIndex);
                            setCurrentQueue(newQueue);

                            if (newQueue.length === 0) {
                                finishDirectionOrQuiz();
                            } else {
                                setCurrentQuestionIndex(Math.min(currentQuestionIndex, newQueue.length - 1));
                            }

                            setShowFeedback(false);
                            setTextAnswer('');
                        }, 1500);
                    } else {
                        setTimeout(() => {
                            setCurrentQuestionIndex((currentQuestionIndex + 1) % currentQueue.length);
                            setShowFeedback(false);
                            setTextAnswer('');
                        }, 1500);
                    }
                } else {
                    setErrorCount(c => c + 1);
                    const qIdx = currentQueue[currentQuestionIndex];
                    setQuestionErrors(prev => ({ ...prev, [qIdx]: prev[qIdx] + 1 }));

                    setTimeout(() => {
                        const newQueue = [...currentQueue];
                        const [removed] = newQueue.splice(currentQuestionIndex, 1);
                        newQueue.push(removed);
                        setCurrentQueue(newQueue);
                        setCurrentQuestionIndex(currentQuestionIndex % newQueue.length);
                        setShowFeedback(false);
                        setTextAnswer('');
                    }, 2500);
                }
            }

            async function saveStats() {
                try {
                    const statsData = {
                        quiz_id: quizData.id,
                        student_name: studentName,
                        completed: true,
                        time_seconds: timer,
                        total_errors: errorCount,
                        question_errors: questionErrors
                    };

                    // För glosquiz: lägg till felstavningar
                    if (isGlossary) {
                        statsData.misspellings = misspellings;
                        statsData.is_glossary = true;

                        // Uppdatera localStorage med senaste fullständiga session
                        const storageKey = `glossary_${quizData.id}`;
                        const sessionData = {
                            timestamp: new Date().toISOString(),
                            completed: true,
                            mode: spellingMode
                        };
                        localStorage.setItem(storageKey, JSON.stringify(sessionData));
                    }

                    await fetch('../api/save-stats.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(statsData)
                    });
                } catch (err) {
                    console.error('Failed to save stats:', err);
                }
            }

            function formatTime(seconds) {
                const m = Math.floor(seconds / 60);
                const s = seconds % 60;
                return `${m}:${s.toString().padStart(2, '0')}`;
            }

            if (!hasStarted) {
                return (
                    <div className="min-h-screen flex items-center justify-center p-4" style={{background: `linear-gradient(to bottom right, var(--bg-from), var(--bg-to))`}}>
                        <div className="rounded-xl shadow-lg p-8 max-w-md w-full" style={{backgroundColor: 'var(--card-bg)'}}>
                            <div className="flex justify-end mb-4">
                                <button
                                    onClick={cycleTheme}
                                    className="px-3 py-2 rounded-lg text-sm font-medium transition"
                                    style={{
                                        backgroundColor: 'var(--card-bg)',
                                        color: 'var(--text-primary)',
                                        border: '2px solid var(--border)'
                                    }}
                                    title="Växla tema: Ljust → Natt → Natt Magenta → Psykedeliskt"
                                >
                                    {getThemeIcon()}
                                </button>
                            </div>
                            <h1 className="text-3xl font-bold mb-2 text-center" style={{color: 'var(--text-primary)'}}>{quizData.title}</h1>
                            <p className="text-center mb-4" style={{color: 'var(--text-secondary)'}}>
                                {isGlossary
                                    ? (reverseEnabled ? '📚 Glosquiz (framåt + omvänt)' : '📚 Glosquiz')
                                    : '📝 Fakta-quiz'} • {questions.length || quizData.questions.length} frågor
                            </p>
                            <div className="space-y-4">
                                <div>
                                    <label className="block text-gray-700 font-medium mb-2">Ditt namn</label>
                                    <input
                                        type="text"
                                        value={studentName}
                                        onChange={(e) => setStudentName(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleStart()}
                                        placeholder="Ange ditt namn"
                                        className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    />
                                </div>

                                {/* Stavningsläge för glosquiz */}
                                {isGlossary && teacherSpellingMode === 'student_choice' && (
                                    <div>
                                        <label className="block text-gray-700 font-medium mb-2">Stavningsläge</label>
                                        <div className="space-y-2">
                                            <button
                                                onClick={() => setSpellingMode('easy')}
                                                className="w-full px-4 py-3 rounded-lg transition text-left border-2"
                                                style={{
                                                    backgroundColor: spellingMode === 'easy'
                                                        ? (theme === 'light' ? '#bbf7d0' : '#14532d')
                                                        : (theme === 'light' ? '#dcfce7' : '#166534'),
                                                    borderColor: spellingMode === 'easy'
                                                        ? (theme === 'light' ? '#22c55e' : '#4ade80')
                                                        : (theme === 'light' ? '#86efac' : '#22c55e'),
                                                    color: theme === 'light' ? '#166534' : '#dcfce7',
                                                    borderWidth: spellingMode === 'easy' ? '3px' : '2px'
                                                }}
                                            >
                                                <div className="font-bold">😊 Easy Mode</div>
                                                <div className="text-sm opacity-80">1-2 bokstäver fel är okej, generös med ändelser</div>
                                            </button>
                                            <button
                                                onClick={() => setSpellingMode('puritan')}
                                                className="w-full px-4 py-3 rounded-lg transition text-left border-2"
                                                style={{
                                                    backgroundColor: spellingMode === 'puritan'
                                                        ? (theme === 'light' ? '#fecaca' : '#7f1d1d')
                                                        : (theme === 'light' ? '#fee2e2' : '#991b1b'),
                                                    borderColor: spellingMode === 'puritan'
                                                        ? (theme === 'light' ? '#ef4444' : '#f87171')
                                                        : (theme === 'light' ? '#fca5a5' : '#ef4444'),
                                                    color: theme === 'light' ? '#991b1b' : '#fee2e2',
                                                    borderWidth: spellingMode === 'puritan' ? '3px' : '2px'
                                                }}
                                            >
                                                <div className="font-bold">😤 Puritan Mode</div>
                                                <div className="text-sm opacity-80">Stavningen måste vara helt korrekt</div>
                                            </button>
                                        </div>
                                    </div>
                                )}

                                <button
                                    onClick={handleStart}
                                    disabled={!studentName.trim()}
                                    className="w-full bg-blue-500 hover:bg-blue-600 disabled:bg-gray-300 text-white font-medium py-3 rounded-lg transition"
                                >
                                    Starta quiz
                                </button>

                                {/* Diskreta röstinställningar */}
                                {isGlossary && (
                                    <div className="pt-4 mt-4 border-t" style={{borderColor: 'var(--border)'}}>
                                        <p className="text-xs text-center mb-2" style={{color: 'var(--text-secondary)'}}>
                                            Språk: {quizLanguage === 'sv' ? 'Svenska' : quizLanguage === 'en' ? 'Engelska' : quizLanguage === 'es' ? 'Spanska' : quizLanguage === 'fr' ? 'Franska' : quizLanguage === 'de' ? 'Tyska' : 'Ukrainska'}
                                        </p>
                                        {quizLanguage === 'uk' && (
                                            <div className="mt-2">
                                                <button
                                                    onClick={() => {
                                                        const info = document.getElementById('uk-voice-help');
                                                        info.classList.toggle('hidden');
                                                    }}
                                                    className="text-xs text-gray-400 hover:text-gray-600 underline"
                                                >
                                                    Озвучування не працює? Натисніть тут для допомоги
                                                </button>
                                                <div id="uk-voice-help" className="hidden mt-2 p-2 bg-gray-50 border border-gray-200 rounded text-left text-xs">
                                                    <p className="font-bold mb-1">📱 iPad/iPhone:</p>
                                                    <p className="mb-2">Налаштування → Спеціальні можливості → Мовлений вміст → Голоси → Українська</p>

                                                    <p className="font-bold mb-1">🤖 Android:</p>
                                                    <p className="mb-2">Налаштування → Система → Мова та введення → Текст у мовлення → Завантажити українські голоси</p>

                                                    <p className="font-bold mb-1">💻 Windows:</p>
                                                    <p className="mb-2">Налаштування → Час і мова → Мовлення → Додати голоси → Українська</p>

                                                    <p className="font-bold mb-1">🍎 Mac:</p>
                                                    <p>Системні налаштування → Спеціальні можливості → Мовлений вміст → Системний голос → Налаштувати → Українська</p>
                                                </div>
                                            </div>
                                        )}
                                        {quizLanguage === 'es' && (
                                            <div className="mt-2">
                                                <div className="mb-2">
                                                    <select
                                                        onChange={(e) => {
                                                            const voice = availableVoices.find(v => v.name === e.target.value);
                                                            setSelectedVoice(voice || null);
                                                        }}
                                                        value={selectedVoice?.name || ''}
                                                        className="w-full px-2 py-1 border border-gray-300 rounded text-xs"
                                                    >
                                                        <option value="">Välj röst (valfritt)</option>
                                                        {availableVoices
                                                            .filter(v => v.lang.toLowerCase().includes('es') || v.name.toLowerCase().includes('spanish') || v.name.toLowerCase().includes('español'))
                                                            .map((voice, i) => (
                                                                <option key={i} value={voice.name}>
                                                                    {voice.name}
                                                                </option>
                                                            ))
                                                        }
                                                    </select>
                                                </div>
                                                <button
                                                    onClick={() => {
                                                        const testText = "Hola, esta es una prueba de voz";
                                                        speakText(testText, 'es');
                                                    }}
                                                    className="w-full mb-1 px-3 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs"
                                                >
                                                    🔊 Testa röst
                                                </button>
                                                <button
                                                    onClick={() => {
                                                        const info = document.getElementById('es-voice-help');
                                                        info.classList.toggle('hidden');
                                                    }}
                                                    className="text-xs text-gray-400 hover:text-gray-600 underline"
                                                >
                                                    Ingen spansk röst? Klicka här
                                                </button>
                                                <div id="es-voice-help" className="hidden mt-2 p-2 bg-gray-50 border border-gray-200 rounded text-left text-xs">
                                                    <p className="font-bold mb-1">📱 iPad/iPhone:</p>
                                                    <p className="mb-2">Inställningar → Tillgänglighet → Talat innehåll → Röster → Spanska</p>

                                                    <p className="font-bold mb-1">🤖 Android:</p>
                                                    <p className="mb-2">Inställningar → System → Språk och inmatning → Text till tal → Ladda ner spanska röster</p>

                                                    <p className="font-bold mb-1">💻 Windows:</p>
                                                    <p className="mb-2">Inställningar → Tid och språk → Tal → Lägg till röster → Spanska</p>

                                                    <p className="font-bold mb-1">🍎 Mac:</p>
                                                    <p>Systeminställningar → Tillgänglighet → Talat innehåll → Systemröst → Anpassa → Spanska</p>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                );
            }

            if (isComplete) {
                return (
                    <div className="min-h-screen flex items-center justify-center p-4" style={{background: `linear-gradient(to bottom right, var(--bg-from), var(--bg-to))`}}>
                        <div className="rounded-xl shadow-2xl p-12 max-w-2xl w-full text-center relative overflow-hidden"
                             style={{
                                 backgroundColor: 'var(--card-bg)',
                                 border: '3px solid #ffd700',
                                 boxShadow: '0 0 50px rgba(255, 215, 0, 0.3)'
                             }}>
                            {/* Dekorativa stjärnor i hörnen */}
                            <div style={{position: 'absolute', top: '15px', left: '15px', fontSize: '24px'}}>⭐</div>
                            <div style={{position: 'absolute', top: '15px', right: '15px', fontSize: '24px'}}>⭐</div>
                            <div style={{position: 'absolute', bottom: '15px', left: '15px', fontSize: '24px'}}>⭐</div>
                            <div style={{position: 'absolute', bottom: '15px', right: '15px', fontSize: '24px'}}>⭐</div>

                            {/* Trofé med guldcirkel */}
                            <div style={{
                                display: 'inline-block',
                                backgroundColor: 'rgba(255, 215, 0, 0.2)',
                                borderRadius: '50%',
                                padding: '30px',
                                marginBottom: '20px'
                            }}>
                                <div className="text-8xl">🏆</div>
                            </div>

                            <h2 className="text-4xl font-bold mb-4" style={{
                                color: '#ffd700',
                                textShadow: '2px 2px 4px rgba(0,0,0,0.3)'
                            }}>
                                LYSANDE {studentName}!
                            </h2>

                            <div className="mb-6 p-6 rounded-lg" style={{
                                backgroundColor: 'rgba(255, 215, 0, 0.1)',
                                border: '2px solid rgba(255, 215, 0, 0.3)'
                            }}>
                                <p className="text-xl font-medium mb-3" style={{color: 'var(--text-primary)'}}>
                                    Du har klarat
                                </p>
                                <p className="text-2xl font-bold mb-4" style={{color: 'var(--accent)'}}>
                                    {quizData.title}
                                </p>
                                <div className="grid grid-cols-3 gap-4 text-center">
                                    <div>
                                        <div className="text-3xl font-bold" style={{color: 'var(--accent)'}}>📝 {questions.length}</div>
                                        <div className="text-sm" style={{color: 'var(--text-secondary)'}}>Frågor</div>
                                    </div>
                                    <div>
                                        <div className="text-3xl font-bold" style={{color: 'var(--accent)'}}>⏱️ {formatTime(timer)}</div>
                                        <div className="text-sm" style={{color: 'var(--text-secondary)'}}>Tid</div>
                                    </div>
                                    <div>
                                        <div className="text-3xl font-bold" style={{color: 'var(--accent)'}}>📅 {new Date().toLocaleDateString('sv-SE')}</div>
                                        <div className="text-sm" style={{color: 'var(--text-secondary)'}}>Datum</div>
                                    </div>
                                </div>
                            </div>

                            <p className="text-sm mb-6" style={{
                                color: 'var(--text-secondary)',
                                fontStyle: 'italic'
                            }}>
                                💡 Tips: Ta en screenshot för att spara ditt resultat!
                            </p>

                            <button
                                onClick={() => window.location.reload()}
                                className="px-8 py-4 rounded-lg font-bold text-lg transition hover:scale-105"
                                style={{
                                    backgroundColor: '#ffd700',
                                    color: '#1f2937',
                                    boxShadow: '0 4px 15px rgba(255, 215, 0, 0.4)'
                                }}
                            >
                                🔄 Börja om
                            </button>
                        </div>
                    </div>
                );
            }

            if (currentQueue.length === 0) {
                return <div className="min-h-screen bg-gradient-to-br from-blue-50 to-purple-50 flex items-center justify-center"><div className="text-xl">Laddar...</div></div>;
            }

            const currentQ = questions[currentQueue[currentQuestionIndex]];
            const currentCorrectAnswer = getCorrectAnswerForQuestion(currentQ);
            const currentOptions = getOptionsForQuestion(currentQ);
            const directionLabel = isGlossary
                ? (isReverseDirection ? '🔄 Översättning → Glosord' : '📚 Glosord → Översättning')
                : '';

            return (
                <div className="min-h-screen p-4" style={{background: `linear-gradient(to bottom right, var(--bg-from), var(--bg-to))`}}>
                    <div className="max-w-2xl mx-auto py-8">
                        {/* Header */}
                        <div className="rounded-xl shadow-lg p-6 mb-6" style={{backgroundColor: 'var(--card-bg)'}}>
                            <div className="flex justify-between items-center mb-2">
                                <h1 className="text-2xl font-bold text-gray-800" style={{color: 'var(--text-primary)'}}>{quizData.title}</h1>
                                <div className="flex items-center gap-3">
                                    {isGlossary && !isReverseDirection && (
                                        <button
                                            onClick={() => setIsMuted(!isMuted)}
                                            className="px-3 py-2 rounded-lg text-sm font-medium transition"
                                            style={{
                                                backgroundColor: 'var(--card-bg)',
                                                color: 'var(--text-primary)',
                                                border: '2px solid var(--border)'
                                            }}
                                            title={isMuted ? 'Slå på autouppläsning' : 'Stäng av autouppläsning'}
                                        >
                                            {isMuted ? '🔇' : '🔊'}
                                        </button>
                                    )}
                                    <button
                                        onClick={cycleTheme}
                                        className="px-3 py-2 rounded-lg text-sm font-medium transition"
                                        style={{
                                            backgroundColor: 'var(--card-bg)',
                                            color: 'var(--text-primary)',
                                            border: '2px solid var(--border)'
                                        }}
                                        title="Växla tema: Ljust → Natt → Natt Magenta → Psykedeliskt"
                                    >
                                        {getThemeIcon()}
                                    </button>
                                    <span className="text-lg font-medium text-gray-600" style={{color: 'var(--text-secondary)'}}>
                                        {answerMode === 'multiple_choice' ? '📝 Flerval' :
                                         answerMode === 'text_only' ? '✍️ Skriv svaret' :
                                         phase === 1 ? '📝 Fas 1: Flerval' : '✍️ Fas 2: Skriv svaret'}
                                    </span>
                                </div>
                            </div>
                            <div className="text-sm" style={{color: 'var(--text-secondary)'}}>Hej {studentName}!</div>
                            {directionLabel && (
                                <div className="text-xs mt-1" style={{color: 'var(--text-secondary)'}}>{directionLabel}</div>
                            )}
                        </div>

                        {/* Progress bars */}
                        <div className="rounded-xl shadow-lg p-6 mb-6" style={{backgroundColor: 'var(--card-bg)'}}>
                            <div className="grid grid-cols-5 gap-2">
                                {questions.map((q, i) => (
                                    <div key={i} className="flex flex-col gap-1">
                                        {[0, 1, 2, 3].map(segment => (
                                            <div
                                                key={segment}
                                                className={`h-2 rounded ${
                                                    progress[i] > segment ? 'bg-green-500' : 'bg-gray-200'
                                                }`}
                                            />
                                        ))}
                                    </div>
                                ))}
                            </div>
                            <div className="text-xs text-center mt-2" style={{color: 'var(--text-secondary)'}}>
                                {answerMode === 'multiple_choice' || answerMode === 'text_only' ?
                                    `Kvar: ${currentQueue.length} frågor` :
                                    `Kvar i fas ${phase}: ${currentQueue.length} frågor`}
                            </div>
                        </div>

                        {/* Question card */}
                        <div className="rounded-xl shadow-lg p-8" style={{backgroundColor: 'var(--card-bg)'}}>
                            {currentQ.image && (
                                <div className="mb-6">
                                    <img src={`../data/images/${currentQ.image}`} alt="Frågebild" className="question-image" />
                                </div>
                            )}
                            {isGlossary ? (
                                isReverseDirection ? (
                                    <div className="mb-6">
                                        <p className="text-sm mb-2" style={{color: 'var(--text-secondary)'}}>
                                            Översättning:
                                        </p>
                                        <p className="text-3xl font-bold mt-2" style={{color: 'var(--text-primary)'}}>
                                            {currentQ.answer}
                                        </p>
                                    </div>
                                ) : (
                                    <div className="mb-6">
                                        <div className="flex justify-between items-start mb-4">
                                            <div className="flex-1">
                                                <p className="text-lg mb-2" style={{color: 'var(--text-secondary)'}}>
                                                    {currentQ.question}
                                                </p>
                                                <p className="text-3xl font-bold mt-4" style={{color: 'var(--text-primary)'}}>
                                                    {currentQ.word}
                                                </p>
                                            </div>
                                            <button
                                                onClick={() => speakGlossary(currentQ.question, currentQ.word)}
                                                className="ml-4 bg-blue-100 hover:bg-blue-200 text-blue-600 p-3 rounded-lg transition"
                                                title="Lyssna på mening + ord"
                                            >
                                                🔊
                                            </button>
                                        </div>
                                    </div>
                                )
                            ) : (
                                <div className="flex justify-between items-start mb-4">
                                    <h2 className="text-2xl font-bold flex-1" style={{color: 'var(--text-primary)'}}>{currentQ.question}</h2>
                                    <button
                                        onClick={() => speakQuestion(currentQ.question)}
                                        className="ml-4 bg-blue-100 hover:bg-blue-200 text-blue-600 p-3 rounded-lg transition"
                                        title="Lyssna på frågan"
                                    >
                                        🔊
                                    </button>
                                </div>
                            )}

                            {(phase === 1 && answerMode !== 'text_only') ? (
                                <div className="space-y-3 mt-6">
                                    {currentOptions.map((option, i) => {
                                        let className = "w-full text-left p-4 border-2 rounded-lg transition ";
                                        let buttonStyle = {};

                                        if (showFeedback) {
                                            if (i === selectedAnswer) {
                                                className += isCorrect
                                                    ? "border-green-500 bg-green-100 soft-blink"
                                                    : "border-red-500 bg-red-100";
                                                buttonStyle.color = '#166534'; // Grön text för rätt svar
                                            } else if (option === currentCorrectAnswer && !isCorrect) {
                                                className += "border-green-500 bg-green-100 font-bold";
                                                buttonStyle.color = '#166534';
                                            } else {
                                                className += "border-gray-200 opacity-50";
                                                buttonStyle.color = 'var(--text-primary)';
                                            }
                                        } else {
                                            className += "hover:border-blue-400 hover:bg-blue-50";
                                            buttonStyle = {
                                                backgroundColor: 'var(--card-bg)',
                                                color: theme === 'night-magenta' ? 'var(--accent)' : theme === 'night' ? '#d1d5db' : 'var(--text-primary)',
                                                borderColor: 'var(--border)'
                                            };
                                        }

                                        return (
                                            <button
                                                key={i}
                                                onClick={() => handleMultipleChoice(i)}
                                                disabled={showFeedback}
                                                className={className}
                                                style={buttonStyle}
                                            >
                                                {option}
                                            </button>
                                        );
                                    })}
                                </div>
                            ) : (
                                <form onSubmit={handleFreeText} className="mt-6 space-y-4">
                                    <input
                                        type="text"
                                        value={textAnswer}
                                        onChange={(e) => setTextAnswer(e.target.value)}
                                        disabled={showFeedback}
                                        placeholder="Skriv ditt svar här..."
                                        className={`w-full px-4 py-3 border-2 rounded-lg focus:ring-2 focus:ring-blue-500 transition ${
                                            showFeedback
                                                ? isCorrect
                                                    ? 'border-green-500 bg-green-100'
                                                    : 'border-red-500 bg-red-100'
                                                : 'border-gray-300'
                                        }`}
                                        autoFocus
                                    />
                                    {showFeedback && !isCorrect && (
                                        <div className="text-red-600 font-medium">
                                            Rätt svar: {currentCorrectAnswer}
                                        </div>
                                    )}
                                    {!showFeedback && (
                                        <button
                                            type="submit"
                                            className="w-full bg-blue-500 hover:bg-blue-600 text-white font-medium py-3 rounded-lg"
                                        >
                                            Svara
                                        </button>
                                    )}
                                </form>
                            )}
                        </div>

                        {/* Timer */}
                        <div className="text-right mt-4">
                            <span className="text-sm text-gray-400">{formatTime(timer)}</span>
                        </div>
                    </div>
                </div>
            );
        }

        ReactDOM.render(<QuizApp />, document.getElementById('root'));
    </script>
</body>
</html>
