<?php
// Hantera flashcard-decks via query parameter
require_once '../config.php';

// Definiera flashcards-fil om den inte finns
if (!defined('FLASHCARDS_FILE')) {
    define('FLASHCARDS_FILE', DATA_DIR . 'flashcards.json');
}

// Hämta deck ID från URL
$deck_id = $_GET['deck_id'] ?? '';

if (!$deck_id) {
    http_response_code(404);
    echo "Deck ID saknas";
    exit;
}

// Ladda deck data
$flashcards = readJSON(FLASHCARDS_FILE);

if (!isset($flashcards[$deck_id])) {
    http_response_code(404);
    echo "Deck inte hittat";
    exit;
}

$deck = $flashcards[$deck_id];

// Kolla om decket är inaktiverat
if (isset($deck['active']) && $deck['active'] === false) {
    http_response_code(403);
    echo "Detta deck är för tillfället inaktiverat av läraren.";
    exit;
}

$deck_json = json_encode($deck, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title><?= htmlspecialchars($deck['title']) ?> - Flashcards</title>
    <script src="https://cdn.tailwindcss.com"></script>
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

        /* Tillåt textmarkering i flashcard-innehåll */
        h2, p, .text-3xl, .text-2xl, .text-lg, button[type="button"] {
            -webkit-user-select: text;
            user-select: text;
        }

        /* Flip animation */
        .flip-card {
            perspective: 1000px;
            margin-bottom: 1rem;
        }

        .flip-card-inner {
            position: relative;
            width: 100%;
            min-height: 35vh;
            transition: transform 0.6s;
            transform-style: preserve-3d;
        }

        .flip-card.flipped .flip-card-inner {
            transform: rotateY(180deg);
        }

        .flip-card-front, .flip-card-back {
            position: absolute;
            width: 100%;
            min-height: 35vh;
            backface-visibility: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            border-radius: 1rem;
            background: var(--card-bg);
            overflow-y: auto;
        }

        .flip-card-back {
            transform: rotateY(180deg);
        }

        /* Säkerställ att content i kortet scrollar om det är för långt */
        .flip-card-front > div,
        .flip-card-back > div {
            max-height: 100%;
            overflow-y: auto;
        }

        /* Bildvisning - responsiv */
        .flashcard-image {
            width: 100%;
            max-width: 100%;
            max-height: 25vh;
            object-fit: contain;
            border-radius: 0.5rem;
            margin: 0.5rem auto;
            display: block;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        /* iPad/tablet optimering - ingen scroll */
        @media (min-width: 768px) and (max-height: 1024px) {
            .flip-card-inner,
            .flip-card-front,
            .flip-card-back {
                min-height: 30vh;
            }
            .flashcard-image {
                max-height: 20vh;
            }
        }

        /* Landscape mode */
        @media (orientation: landscape) {
            .flip-card-inner,
            .flip-card-front,
            .flip-card-back {
                min-height: 40vh;
            }
            .flashcard-image {
                max-height: 30vh;
            }
        }

        /* Kompakt layout för iPad */
        @media (min-width: 768px) {
            .compact-header {
                padding: 0.75rem !important;
                margin-bottom: 0.5rem !important;
            }
            .compact-progress {
                padding: 0.5rem 0.75rem !important;
                margin-bottom: 0.5rem !important;
            }
            .compact-container {
                padding-top: 0.5rem !important;
                padding-bottom: 0.5rem !important;
            }
            .compact-buttons {
                margin-top: 0.5rem !important;
            }
            .compact-timer {
                margin-top: 0.25rem !important;
            }
        }
    </style>
</head>
<body>
    <div id="root"></div>

    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

    <script type="text/babel">
        const { useState, useEffect } = React;
        const deckData = <?= $deck_json ?>;

        function FlashcardApp() {
            const [studentName, setStudentName] = useState('');
            const [hasStarted, setHasStarted] = useState(false);
            const [cards, setCards] = useState([]);
            const [currentQueue, setCurrentQueue] = useState([]);
            const [currentCardIndex, setCurrentCardIndex] = useState(0);
            const [isFlipped, setIsFlipped] = useState(false);
            const [showGrading, setShowGrading] = useState(false);
            const [cardGrades, setCardGrades] = useState({});
            const [isComplete, setIsComplete] = useState(false);
            const [timer, setTimer] = useState(0);
            const [timerRunning, setTimerRunning] = useState(false);
            const [theme, setTheme] = useState('light');
            const [isMuted, setIsMuted] = useState(false);
            const [selectedVoice, setSelectedVoice] = useState(null);
            const [availableVoices, setAvailableVoices] = useState([]);
            const [difficultCards, setDifficultCards] = useState(new Set());
            const [showDifficultOnly, setShowDifficultOnly] = useState(false);
            const [cardHeight, setCardHeight] = useState(400);

            const deckLanguage = deckData.language || 'sv';

            // Fisher-Yates shuffle
            function shuffleArray(array) {
                const arr = [...array];
                for (let i = arr.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [arr[i], arr[j]] = [arr[j], arr[i]];
                }
                return arr;
            }

            // Initiera flashcards
            useEffect(() => {
                if (hasStarted && cards.length === 0) {
                    const shuffledCards = shuffleArray(deckData.cards.map((c, i) => ({ ...c, index: i })));
                    setCards(shuffledCards);
                    setCurrentQueue(shuffledCards.map((_, i) => i));

                    // Initiera grades
                    const grades = {};
                    shuffledCards.forEach((_, i) => { grades[i] = []; });
                    setCardGrades(grades);

                    setTimerRunning(true);

                    // Ladda svåra kort från localStorage
                    const storageKey = `flashcard_difficult_${deckData.id}`;
                    const saved = localStorage.getItem(storageKey);
                    if (saved) {
                        try {
                            const savedSet = new Set(JSON.parse(saved));
                            setDifficultCards(savedSet);
                        } catch (e) {
                            console.error('Could not load difficult cards:', e);
                        }
                    }
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

            // TTS spelas endast när användaren klickar på knappen

            // Ladda tillgängliga röster
            useEffect(() => {
                const loadVoices = () => {
                    const voices = window.speechSynthesis.getVoices();
                    setAvailableVoices(voices);

                    // Ladda sparad röst från localStorage
                    const savedVoice = localStorage.getItem('flashcard_voice_preference');
                    if (savedVoice) {
                        const voice = voices.find(v => v.name === savedVoice);
                        if (voice) {
                            setSelectedVoice(voice);
                        }
                    }
                };

                loadVoices();
                if (window.speechSynthesis.onvoiceschanged !== undefined) {
                    window.speechSynthesis.onvoiceschanged = loadVoices;
                }

                // Ladda muted-status från localStorage
                const savedMuted = localStorage.getItem('flashcard_mute');
                if (savedMuted === 'true') {
                    setIsMuted(true);
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

            // Dynamiskt beräkna kortets höjd baserat på innehåll
            useEffect(() => {
                if (!hasStarted || isComplete || currentQueue.length === 0) return;
                const timer = setTimeout(() => {
                    const frontCard = document.querySelector('.flip-card-front > div');
                    const backCard = document.querySelector('.flip-card-back > div');
                    if (frontCard && backCard) {
                        const maxHeight = Math.max(
                            frontCard.offsetHeight + 80,
                            backCard.offsetHeight + 80,
                            400
                        );
                        setCardHeight(maxHeight);
                    }
                }, 100);
                return () => clearTimeout(timer);
            }, [currentCardIndex, isFlipped, hasStarted, isComplete, currentQueue.length]);

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

            // Talsyntes med språkstöd och mongolian fallback
            function speakText(text, lang = deckLanguage) {
                if ('speechSynthesis' in window) {
                    window.speechSynthesis.cancel();
                    const utterance = new SpeechSynthesisUtterance(text);

                    // Språkkoder
                    const langMap = {
                        'sv': 'sv-SE',
                        'en': 'en-US',
                        'mongolian': 'mn-MN',
                        'uk': 'uk-UA'
                    };

                    utterance.lang = langMap[lang] || 'sv-SE';

                    // Använd manuellt vald röst om den finns
                    let voice = selectedVoice;

                    // Annars, försök hitta en röst för rätt språk
                    if (!voice) {
                        const voices = window.speechSynthesis.getVoices();
                        const targetLang = langMap[lang] || 'sv-SE';

                        // För mongolian: försök hitta mongolian-röst, annars fallback till svenska
                        if (lang === 'mongolian') {
                            voice = voices.find(v => v.lang.toLowerCase().includes('mn')) ||
                                   voices.find(v => v.name.toLowerCase().includes('mongol')) ||
                                   voices.find(v => v.lang === 'sv-SE') ||
                                   voices.find(v => v.lang.startsWith('sv'));

                            // Om vi använder svenska fallback, sätt språk till svenska
                            if (voice && !voice.lang.toLowerCase().includes('mn')) {
                                utterance.lang = 'sv-SE';
                            }
                        } else {
                            voice = voices.find(v => v.lang.startsWith(targetLang.substring(0, 2))) ||
                                   voices.find(v => v.lang === targetLang);
                        }
                    }

                    if (voice) {
                        utterance.voice = voice;
                    }

                    utterance.rate = 0.9;
                    window.speechSynthesis.speak(utterance);
                }
            }

            function handleStart() {
                if (studentName.trim()) {
                    setHasStarted(true);
                }
            }

            function handleFlip() {
                setIsFlipped(true);
                setShowGrading(true);
            }

            function handleGrade(grade) {
                const cardIdx = currentQueue[currentCardIndex];

                // Spara grade
                const newGrades = { ...cardGrades };
                newGrades[cardIdx].push(grade);
                setCardGrades(newGrades);

                // Om grade 0 eller 1, markera som svårt kort
                const newDifficult = new Set(difficultCards);
                if (grade <= 1) {
                    newDifficult.add(cardIdx);
                } else if (grade >= 2) {
                    // Om grade 2 eller 3, ta bort från svåra kort
                    newDifficult.delete(cardIdx);
                }
                setDifficultCards(newDifficult);

                // Spara svåra kort till localStorage
                const storageKey = `flashcard_difficult_${deckData.id}`;
                localStorage.setItem(storageKey, JSON.stringify([...newDifficult]));

                // Om grade 0 eller 1, lägg tillbaka kortet i kön
                if (grade <= 1) {
                    setTimeout(() => {
                        const newQueue = [...currentQueue];
                        const [removed] = newQueue.splice(currentCardIndex, 1);
                        newQueue.push(removed);
                        setCurrentQueue(newQueue);
                        setCurrentCardIndex(currentCardIndex % newQueue.length);
                        setIsFlipped(false);
                        setShowGrading(false);
                    }, 1000);
                } else {
                    // Grade 2 eller 3 - ta bort från kön
                    setTimeout(() => {
                        const newQueue = currentQueue.filter((_, i) => i !== currentCardIndex);
                        setCurrentQueue(newQueue);

                        if (newQueue.length === 0) {
                            setIsComplete(true);
                            setTimerRunning(false);
                            saveStats();
                        } else {
                            setCurrentCardIndex(Math.min(currentCardIndex, newQueue.length - 1));
                        }

                        setIsFlipped(false);
                        setShowGrading(false);
                    }, 1000);
                }
            }

            async function saveStats() {
                try {
                    const gradeDistribution = { '0': 0, '1': 0, '2': 0, '3': 0 };
                    const flatGrades = Object.values(cardGrades).flat();
                    flatGrades.forEach(g => {
                        gradeDistribution[g.toString()]++;
                    });

                    const statsData = {
                        deck_id: deckData.id,
                        student_name: studentName,
                        completed: true,
                        time_seconds: timer,
                        card_grades: cardGrades,
                        grade_distribution: gradeDistribution
                    };

                    await fetch('../api/save-flashcard-stats.php', {
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

            function toggleMute() {
                const newMuted = !isMuted;
                setIsMuted(newMuted);
                localStorage.setItem('flashcard_mute', newMuted.toString());
            }

            function handleVoiceChange(voiceName) {
                const voice = availableVoices.find(v => v.name === voiceName);
                setSelectedVoice(voice || null);
                localStorage.setItem('flashcard_voice_preference', voiceName);
            }

            function toggleDifficultFilter() {
                if (!showDifficultOnly && difficultCards.size === 0) {
                    alert('Inga svåra kort markerade än!');
                    return;
                }
                setShowDifficultOnly(!showDifficultOnly);
            }

            // Start screen
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
                                >
                                    {getThemeIcon()}
                                </button>
                            </div>
                            <h1 className="text-3xl font-bold mb-2 text-center" style={{color: 'var(--text-primary)'}}>{deckData.title}</h1>
                            <p className="text-center mb-4" style={{color: 'var(--text-secondary)'}}>
                                🗂️ Flashcards • {deckData.cards.length} kort
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
                                        className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                                    />
                                </div>

                                <button
                                    onClick={handleStart}
                                    disabled={!studentName.trim()}
                                    className="w-full bg-green-500 hover:bg-green-600 disabled:bg-gray-300 text-white font-medium py-3 rounded-lg"
                                >
                                    Starta flashcards
                                </button>

                                {/* Röstinställningar */}
                                <div className="pt-4 mt-4 border-t" style={{borderColor: 'var(--border)'}}>
                                    <p className="text-xs text-center mb-2" style={{color: 'var(--text-secondary)'}}>
                                        Språk: {deckLanguage === 'sv' ? 'Svenska' : deckLanguage === 'en' ? 'Engelska' : deckLanguage === 'mongolian' ? 'Mongoliska (fallback: svenska)' : 'Ukrainska'}
                                    </p>
                                    {availableVoices.length > 0 && (
                                        <div>
                                            <label className="block text-xs font-medium mb-1" style={{color: 'var(--text-secondary)'}}>Välj röst (valfritt)</label>
                                            <select
                                                onChange={(e) => handleVoiceChange(e.target.value)}
                                                value={selectedVoice?.name || ''}
                                                className="w-full px-2 py-1 border border-gray-300 rounded text-xs"
                                            >
                                                <option value="">Standard</option>
                                                {availableVoices.map((voice, i) => (
                                                    <option key={i} value={voice.name}>
                                                        {voice.name} ({voice.lang})
                                                    </option>
                                                ))}
                                            </select>
                                            <button
                                                onClick={() => speakText('Hej, detta är ett test', deckLanguage)}
                                                className="w-full mt-2 px-3 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs"
                                            >
                                                🔊 Testa röst
                                            </button>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                );
            }

            // Complete screen
            if (isComplete) {
                const gradeDistribution = { '0': 0, '1': 0, '2': 0, '3': 0 };
                Object.values(cardGrades).flat().forEach(g => {
                    gradeDistribution[g.toString()]++;
                });

                return (
                    <div className="min-h-screen flex items-center justify-center p-4" style={{background: `linear-gradient(to bottom right, var(--bg-from), var(--bg-to))`}}>
                        <div className="rounded-xl shadow-2xl p-12 max-w-2xl w-full text-center"
                             style={{
                                 backgroundColor: 'var(--card-bg)',
                                 border: '3px solid #10b981'
                             }}>
                            <div style={{fontSize: '80px', marginBottom: '20px'}}>🏆</div>
                            <h2 className="text-4xl font-bold mb-4" style={{color: '#10b981'}}>
                                BRA JOBBAT {studentName}!
                            </h2>
                            <div className="mb-6 p-6 rounded-lg" style={{backgroundColor: 'rgba(16, 185, 129, 0.1)'}}>
                                <p className="text-xl font-medium mb-3" style={{color: 'var(--text-primary)'}}>
                                    Du har genomfört
                                </p>
                                <p className="text-2xl font-bold mb-4" style={{color: 'var(--accent)'}}>
                                    {deckData.title}
                                </p>
                                <div className="grid grid-cols-2 gap-4 text-center">
                                    <div>
                                        <div className="text-3xl font-bold" style={{color: 'var(--accent)'}}>🗂️ {cards.length}</div>
                                        <div className="text-sm" style={{color: 'var(--text-secondary)'}}>Kort</div>
                                    </div>
                                    <div>
                                        <div className="text-3xl font-bold" style={{color: 'var(--accent)'}}>⏱️ {formatTime(timer)}</div>
                                        <div className="text-sm" style={{color: 'var(--text-secondary)'}}>Tid</div>
                                    </div>
                                </div>
                                <div className="mt-4 p-4 bg-white rounded-lg">
                                    <p className="font-bold mb-2">Din bedömning:</p>
                                    <div className="flex justify-around text-sm">
                                        <div>❌ {gradeDistribution['0']}</div>
                                        <div>🤔 {gradeDistribution['1']}</div>
                                        <div>✅ {gradeDistribution['2']}</div>
                                        <div>⭐ {gradeDistribution['3']}</div>
                                    </div>
                                </div>
                            </div>
                            <button
                                onClick={() => window.location.reload()}
                                className="px-8 py-4 rounded-lg font-bold text-lg transition hover:scale-105"
                                style={{backgroundColor: '#10b981', color: 'white'}}
                            >
                                🔄 Börja om
                            </button>
                        </div>
                    </div>
                );
            }

            if (currentQueue.length === 0) {
                return <div className="min-h-screen flex items-center justify-center"><div className="text-xl">Laddar...</div></div>;
            }

            const currentCard = cards[currentQueue[currentCardIndex]];

            return (
                <div className="min-h-screen p-2 md:p-4" style={{background: `linear-gradient(to bottom right, var(--bg-from), var(--bg-to))`}}>
                    <div className="max-w-2xl mx-auto py-2 compact-container">
                        {/* Header */}
                        <div className="rounded-xl shadow-lg p-4 mb-4 compact-header" style={{backgroundColor: 'var(--card-bg)'}}>
                            <div className="flex justify-between items-center">
                                <h1 className="text-xl md:text-2xl font-bold" style={{color: 'var(--text-primary)'}}>{deckData.title}</h1>
                                <div className="flex items-center gap-3">
                                    <button
                                        onClick={toggleMute}
                                        className="px-3 py-2 rounded-lg text-sm font-medium transition"
                                        style={{backgroundColor: 'var(--card-bg)', color: 'var(--text-primary)', border: '2px solid var(--border)'}}
                                    >
                                        {isMuted ? '🔇' : '🔊'}
                                    </button>
                                    <button
                                        onClick={cycleTheme}
                                        className="px-3 py-2 rounded-lg text-sm font-medium transition"
                                        style={{backgroundColor: 'var(--card-bg)', color: 'var(--text-primary)', border: '2px solid var(--border)'}}
                                    >
                                        {getThemeIcon()}
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* Progress */}
                        <div className="rounded-xl shadow-lg p-3 mb-3 compact-progress" style={{backgroundColor: 'var(--card-bg)'}}>
                            <div className="flex justify-between items-center mb-2">
                                <div className="text-sm" style={{color: 'var(--text-secondary)'}}>
                                    Kort kvar: {currentQueue.length} / {cards.length}
                                </div>
                                <button
                                    onClick={toggleDifficultFilter}
                                    className={`px-3 py-1 rounded text-sm ${showDifficultOnly ? 'bg-red-500 text-white' : 'bg-gray-200 text-gray-700'}`}
                                >
                                    📌 Svåra kort ({difficultCards.size})
                                </button>
                            </div>
                            <div className="w-full bg-gray-200 rounded-full h-4">
                                <div
                                    className="bg-gradient-to-r from-green-500 to-blue-500 h-4 rounded-full transition-all"
                                    style={{width: `${((cards.length - currentQueue.length) / cards.length) * 100}%`}}
                                />
                            </div>
                        </div>

                        {/* Flashcard */}
                        <div className={`flip-card ${isFlipped ? 'flipped' : ''}`}>
                            <div className="flip-card-inner" style={{minHeight: `${cardHeight}px`}}>
                                {/* Front */}
                                <div className="flip-card-front" style={{backgroundColor: 'var(--card-bg)', border: '2px solid var(--border)', minHeight: `${cardHeight}px`}}>
                                    <div className="text-center">
                                        {currentCard.image && (currentCard.image_side === 'front' || !currentCard.image_side) && (
                                            <img src={`../data/images/${currentCard.image}`} alt="Flashcard" className="flashcard-image" />
                                        )}
                                        <p className="text-4xl font-bold mb-4" style={{color: 'var(--text-primary)'}}>{currentCard.front}</p>
                                        <button
                                            onClick={() => speakText(currentCard.front, deckLanguage)}
                                            className="bg-blue-100 hover:bg-blue-200 text-blue-600 p-3 rounded-lg"
                                        >
                                            🔊 Läs upp
                                        </button>
                                    </div>
                                </div>

                                {/* Back */}
                                <div className="flip-card-back" style={{backgroundColor: 'var(--card-bg)', border: '2px solid var(--border)', minHeight: `${cardHeight}px`}}>
                                    <div className="text-center">
                                        {currentCard.image && currentCard.image_side === 'back' && (
                                            <img src={`../data/images/${currentCard.image}`} alt="Flashcard" className="flashcard-image" />
                                        )}
                                        <p className="text-4xl font-bold mb-4" style={{color: 'var(--text-primary)'}}>{currentCard.back}</p>
                                        <button
                                            onClick={() => speakText(currentCard.back, deckLanguage)}
                                            className="bg-green-100 hover:bg-green-200 text-green-600 p-3 rounded-lg"
                                        >
                                            🔊 Läs upp
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Action buttons */}
                        <div className="mt-3 compact-buttons">
                            {!isFlipped ? (
                                <button
                                    onClick={handleFlip}
                                    className="w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-3 rounded-lg text-lg"
                                >
                                    🔄 Vänd kort
                                </button>
                            ) : showGrading && (
                                <div className="grid grid-cols-4 gap-2">
                                    <button
                                        onClick={() => handleGrade(0)}
                                        className="bg-red-500 hover:bg-red-600 text-white font-bold py-2 rounded-lg flex flex-col items-center"
                                    >
                                        <span className="text-2xl">❌</span>
                                        <span className="text-xs">Fel</span>
                                    </button>
                                    <button
                                        onClick={() => handleGrade(1)}
                                        className="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 rounded-lg flex flex-col items-center"
                                    >
                                        <span className="text-2xl">🤔</span>
                                        <span className="text-xs">Osäker</span>
                                    </button>
                                    <button
                                        onClick={() => handleGrade(2)}
                                        className="bg-green-500 hover:bg-green-600 text-white font-bold py-2 rounded-lg flex flex-col items-center"
                                    >
                                        <span className="text-2xl">✅</span>
                                        <span className="text-xs">Rätt</span>
                                    </button>
                                    <button
                                        onClick={() => handleGrade(3)}
                                        className="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 rounded-lg flex flex-col items-center"
                                    >
                                        <span className="text-2xl">⭐</span>
                                        <span className="text-xs">Perfekt</span>
                                    </button>
                                </div>
                            )}
                        </div>

                        {/* Timer */}
                        <div className="text-right mt-1 compact-timer">
                            <span className="text-xs text-gray-400">{formatTime(timer)}</span>
                        </div>
                    </div>
                </div>
            );
        }

        ReactDOM.render(<FlashcardApp />, document.getElementById('root'));
    </script>
</body>
</html>
