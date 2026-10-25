<?php
// play/quiz.php — Student quiz player (glossary + fact)
// No auth required — students access via link
$quizId = $_GET['id'] ?? '';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/classroom.php';
classroomPlayer($quizId,'quiz');
$studentName = classroomStudent()['name'] ?? ($_GET['name'] ?? '');
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
    <link rel="stylesheet" href="../engine/themes.css?v=<?= filemtime(__DIR__ . '/../engine/themes.css') ?>">
    <script src="../engine/browser-id.js?v=<?= filemtime(__DIR__ . '/../engine/browser-id.js') ?>"></script>
    <style>
        :root { --bar-width: 32rem; }
        @keyframes answerPulseOnce {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(34,197,94,0.55); }
            40% { transform: scale(1.035); box-shadow: 0 0 14px 4px rgba(34,197,94,0.45); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(34,197,94,0); }
        }
        .answer-correct-pulse {
            background: #22c55e !important;
            color: #fff !important;
            border-color: #16a34a !important;
            animation: answerPulseOnce 0.55s ease-out;
        }
        .answer-wrong {
            background: #ef4444 !important;
            color: #fff !important;
            border-color: #dc2626 !important;
        }
        @keyframes answerGlowTwice {
            0% { background: var(--card-bg); color: var(--text-primary); border-color: var(--border); transform: scale(1); box-shadow: 0 0 0 0 rgba(34,197,94,0.6); }
            8% { background: #22c55e; color: #fff; border-color: #16a34a; }
            22% { transform: scale(1.045); box-shadow: 0 0 16px 5px rgba(34,197,94,0.55); }
            38% { transform: scale(1); box-shadow: 0 0 0 0 rgba(34,197,94,0.25); }
            58% { transform: scale(1.045); box-shadow: 0 0 16px 5px rgba(34,197,94,0.55); }
            75%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(34,197,94,0); background: #22c55e; color: #fff; border-color: #16a34a; }
        }
        .answer-correct-glow {
            animation: answerGlowTwice 1.1s ease-in-out 0.35s 1 both;
        }
    </style>
    <script src="../engine/validator.js?v=<?= filemtime(__DIR__ . '/../engine/validator.js') ?>"></script>
    <script src="../engine/tts.js?v=<?= filemtime(__DIR__ . '/../engine/tts.js') ?>"></script>
    <script src="../engine/hold-speak.js?v=<?= filemtime(__DIR__ . '/../engine/hold-speak.js') ?>"></script>
    <script src="../engine/quiz-engine.js?v=<?= filemtime(__DIR__ . '/../engine/quiz-engine.js') ?>"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-[var(--bg-from)] to-[var(--bg-to)]">
<?php classroomPlayerBar('index.php?id='.urlencode($quizId)); ?>
<div id="root"></div>

<script type="text/babel">
const PREVIEW = <?= isset($_GET['preview'])?'true':'false' ?>;
const QUIZ_ID = <?= json_encode($quizId) ?>;
const STUDENT_NAME = <?= json_encode($studentName, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

// Faktaquiz: begreppet läses på svenska (förval) eller valt språk
// (settings.concept_language === 'same'); beskrivningen alltid på valt språk.
function factConceptLang(settings) {
    return settings && settings.concept_language === 'same' ? settings.language : 'sv';
}
function factDescriptionLang(settings) {
    return settings ? settings.language : 'sv';
}

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
    const [muted, setMuted] = React.useState(() => ttsIsMuted());
    const wrongCountsRef = React.useRef({}); // item index -> accumulated wrong tries, for the spaced-repetition signal

    function factLang() { return quiz?.settings?.language; }
    // Faktaquiz: språket beror på om texten är begrepp eller beskrivning.
    // Framåt: prompt = begrepp, alternativ = beskrivningar. Omvänt tvärtom.
    function factPromptLang(direction) {
        return direction === 'reverse' ? factDescriptionLang(quiz?.settings) : factConceptLang(quiz?.settings);
    }
    function factOptionLang(direction) {
        return direction === 'reverse' ? factConceptLang(quiz?.settings) : factDescriptionLang(quiz?.settings);
    }

    // Faktaquiz läses bara upp när eleven håller på texten (ingen autouppspelning).

    function toggleMute() {
        const next = !muted;
        ttsSetMuted(next);
        setMuted(next);
    }

    // Load quiz
    React.useEffect(() => {
        fetch('../api/quiz-data.php?id=' + encodeURIComponent(QUIZ_ID) + (PREVIEW?'&preview=1':''))
            .then(r => r.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                const own = {image_choice:'image-quiz.php', sort:'sort-quiz.php', order:'order-quiz.php'}[data.type];
                if (own) { window.location.replace(own+window.location.search); return; }
                if (data.status === 'not_yet') {
                    setOpensAt(data.opens);
                    setStatus('not_yet');
                    return;
                }
                setQuiz(data);
                const sm = data.settings.spelling_mode || 'easy';
                if (sm !== 'student_choice') setSpellingMode(sm);
                const requestedMode = new URLSearchParams(window.location.search).get('mode');
                const effectiveSettings = { ...data.settings, quiz_mode: requestedMode === 'test' ? 'test' : data.settings.quiz_mode };
                const eng = QuizEngine({
                    items: data.items,
                    settings: effectiveSettings,
                    quizType: data.type
                });
                setEngine(eng);
                setStatus('playing');

                // TTS on first question
                if (data.settings.tts_enabled) {
                    const item = eng.currentItem();
                    const q = eng.getQuestion(item);
                    if (data.type === 'glossary' && eng.currentDirection() === 'forward' && eng.currentPhase() !== 'text') {
                        speakGlossary(item.sentence, item.word, data.settings.language);
                    }
                    // Omvänd riktning: prompten är svenska; svaret (glosordet) läses först när det avslöjats.
                    // Faktaquiz läses upp av effekten nedan, utifrån frågan som
                    // faktiskt visas (alternativens ordning slumpas vid varje getQuestion).
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

    function showFeedback(payload, delay) {
        setFeedback(payload);
        setStatus('feedback');
        // Omvänt glosquiz: svaret (glosordet, målspråket) läses upp först när det avslöjats.
        if (quiz?.settings?.tts_enabled && quiz.type === 'glossary' && payload.direction === 'reverse') {
            speakText(payload.correctAnswer, quiz.settings.language);
        }
        setTimeout(() => {
            setFeedback(null);
            setStatus('playing');
            const nextItem = engine.currentItem();
            const nextQ = nextItem ? engine.getQuestion(nextItem) : null;
            playTTS(nextItem, nextQ);
        }, delay);
    }

    function handleAnswer(answer) {
        const currentSpelling = spellingChoice || spellingMode;
        // Reuse THIS render's already-computed q/progress instead of calling
        // engine.getQuestion()/getProgress() again — buildOptions() reshuffles
        // the option order on every call, so a fresh call here would capture
        // a different button order than what's actually on screen, making the
        // feedback view glow a different-looking arrangement than what was clicked.
        const base = {
            given: answer,
            correctAnswer: q.answer,
            options: q.options,
            prompt: q.prompt,
            highlight: q.highlight,
            direction: progress.direction,
            phase: progress.phase
        };
        const result = engine.submitAnswer(answer, currentSpelling);
        window.ClassroomTracker?.answer(result.correct, result.complete);
        if (!result.correct) wrongCountsRef.current[result.itemIndex] = (wrongCountsRef.current[result.itemIndex] || 0) + 1;
        if (result.itemFinal) {
            window.ClassroomTracker?.question(result.itemIndex, wrongCountsRef.current[result.itemIndex] || 0);
            delete wrongCountsRef.current[result.itemIndex];
        }
        setTextInput('');

        if (result.complete) {
            const results = engine.getResults();
            if (!PREVIEW) fetch('../api/save-result.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    quiz_id: QUIZ_ID,
                    student_name: STUDENT_NAME,
                    student_id: window.classroomContext?.student_id || null,
                    browser_id: getQuizBrowserId(),
                    score: results.score,
                    total: results.total,
                    errors: results.errors
                })
            });
            setStatus('diploma');
            return;
        }

        if (!result.correct) {
            showFeedback({ ...base, correct: false }, 2000);
            return;
        }

        if (result.directionChange) {
            showFeedback({ ...base, correct: true, message: 'Nu kör vi omvänt!' }, 1500);
            return;
        }

        if (result.phaseChange) {
            showFeedback({ ...base, correct: true, message: 'Bra! Nu skrivsvar.' }, 1500);
            return;
        }

        // Show confirmation before rendering and reading the next question.
        showFeedback({ ...base, correct: true }, 900);
    }

    function playTTS(item, q) {
        if (!quiz?.settings?.tts_enabled || !item) return;
        if (quiz.type === 'glossary' && engine.currentDirection() === 'forward' && engine.currentPhase() !== 'text') {
            speakGlossary(item.sentence, item.word, quiz.settings.language);
        }
        // Faktaquiz läses upp av effekten ovan när frågan visats.
    }

    if (status === 'loading') return <div className="flex items-center justify-center min-h-screen"><p style={{color: 'var(--text-secondary)'}}>Laddar...</p></div>;
    if (status === 'error') return <div className="flex items-center justify-center min-h-screen"><p className="text-red-500">Kunde inte ladda quizet.</p></div>;
    if (status === 'not_yet') return (
        <div className="flex items-center justify-center min-h-screen">
            <div className="text-center p-8 rounded-xl" style={{background: 'var(--card-bg)', border: '1px solid var(--border)'}}>
                <p className="text-lg" style={{color: 'var(--text-primary)'}}>Quizet öppnar</p>
                <p className="text-2xl font-bold mt-2" style={{color: 'var(--accent)'}}>{opensAt}</p>
            </div>
        </div>
    );

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
                    {results.flawless && (
                        <p className="text-sm mb-4" style={{color: 'var(--text-secondary)'}}>
                            Inte ett enda fel. Imponerande, {STUDENT_NAME}!
                        </p>
                    )}
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
    const isFact = quiz.type === 'fact';
    // Språk per sida: glosor har glosord/mening på quizets språk och översättning på svenska.
    // Framåt: alternativen är svenska. Omvänt: prompten är svensk, alternativen glosord.
    const isGlossary = quiz.type === 'glossary';
    const optionLang = isGlossary ? (progress.direction === 'forward' ? 'sv' : factLang()) : factOptionLang(progress.direction);
    // Håll på frågan eller ett alternativ i en sekund för uppläsning (engine/hold-speak.js).
    const speakProps = (text, lang) => quiz.settings.tts_enabled ? {'data-speak': text, 'data-speak-lang': lang} : {};
    const promptLang = (direction, phase) => isGlossary ? (direction === 'forward' && phase !== 'text' ? factLang() : 'sv') : factPromptLang(direction);

    function renderPrompt(promptText, highlightText, direction, phase) {
        if (quiz.type === 'glossary' && direction === 'forward' && phase !== 'text') {
            return (
                <div {...speakProps(promptText, promptLang(direction, phase))}>
                    <p className="text-xl mb-2 text-center" style={{color: 'var(--text-secondary)'}}>
                        {promptText.replace(highlightText, `**${highlightText}**`).split('**').map((part, i) =>
                            i % 2 === 1
                                ? <strong key={i} style={{color: 'var(--accent)'}}>{part}</strong>
                                : part
                        )}
                    </p>
                    <p className="text-sm text-center" style={{color: 'var(--text-secondary)'}}>
                        Vad betyder <strong style={{color: 'var(--accent)'}}>{highlightText}</strong>?
                    </p>
                </div>
            );
        }
        return <p className="text-2xl font-bold text-center leading-snug" style={{color: 'var(--text-primary)'}} {...speakProps(promptText, promptLang(direction, phase))}>{promptText}</p>;
    }

    return (
        <div className="max-w-lg mx-auto p-4">
            {/* Header */}
            <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-2 min-w-0">
                    <h2 className="text-sm font-medium truncate" style={{color: 'var(--text-primary)'}}>{quiz.title}</h2>
                </div>
                <div className="flex items-center gap-2">
                    <span className="text-sm font-bold" style={{color: 'var(--accent)'}}>{progress.correctCount}/{progress.correctCount + progress.totalErrors}</span>
                    <button onClick={toggleMute} title={muted ? 'Slå på uppläsning' : 'Stäng av uppläsning'} className="text-xs px-1.5 py-0.5 rounded border" style={{background: 'var(--card-bg)', color: 'var(--text-secondary)', borderColor: 'var(--border)'}}>
                        {muted ? '🔇' : '🔊'}
                    </button>
                    <select value={theme} onChange={e => setTheme(e.target.value)} className="text-xs px-1 py-0.5 rounded border" style={{background: 'var(--card-bg)', color: 'var(--text-secondary)', borderColor: 'var(--border)'}}>
                        <option value="light">Light</option>
                        <option value="night">Night</option>
                        <option value="night-magenta">Magenta</option>
                        <option value="psychedelic">Psychedelic</option>
                    </select>
                </div>
            </div>

            {/* Mastery progress */}
            <div className="rounded-lg p-3 mb-6" style={{background: 'var(--card-bg)', border: '1px solid var(--border)'}}>
                <div className="flex justify-between text-xs mb-2" style={{color: 'var(--text-secondary)'}}>
                    <span>Rätt per fråga</span><span>{progress.masteredCount}/{progress.phaseTotal} klara · {progress.requiredCorrect} rätt krävs</span>
                </div>
                <div className="grid grid-cols-5 gap-2">
                    {progress.phaseItemIndices.map((index, position) => (
                        <div key={index} className="text-center" title={`Fråga ${position + 1}: ${progress.masteryCounts[index] || 0}/${progress.requiredCorrect}`}>
                            <div className="flex gap-0.5">{Array.from({length: progress.requiredCorrect}, (_, segment) => <span key={segment} className={`h-2 flex-1 rounded ${(progress.masteryCounts[index] || 0) > segment ? 'bg-green-500' : 'bg-gray-200'}`}></span>)}</div>
                            <span className="text-[10px]" style={{color: 'var(--text-secondary)'}}>{progress.masteryCounts[index] || 0}/{progress.requiredCorrect}</span>
                        </div>
                    ))}
                </div>
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

            {/* Direction indicator — visas bara när quizet faktiskt bytt riktning
                (andra omgången), inte när "omvänt" bara råkar vara quizets
                enda/primära riktning (t.ex. faktaquiz med beskrivning som fråga) */}
            {progress.secondPass && (
                <div className="text-center mb-2">
                    <span className="text-xs px-2 py-0.5 rounded-full bg-purple-100 text-purple-700">Omvänd riktning</span>
                </div>
            )}

            {/* Question card */}
            {status === 'playing' && q && (
                <div className="rounded-xl p-6 mb-4" style={{background: 'var(--card-bg)', border: '1px solid var(--border)'}}>
                    {item.image && typeof item.image === 'object' && /^ai_[a-f0-9]{32}\.(png|jpg|webp|gif)$/.test(item.image.file) && (
                        <img src={'../api/question-image.php?file=' + encodeURIComponent(item.image.file)} alt={item.image.alt || 'Frågebild'} className="w-full max-h-64 object-contain rounded-lg mb-4" onError={e => { e.currentTarget.hidden = true; }} />
                    )}
                    {renderPrompt(q.prompt, q.highlight, progress.direction, progress.phase)}

                    {/* Multiple choice options */}
                    {q.options && (
                        <div className="mt-4 space-y-2">
                            {q.options.map((opt, i) => (
                                <button key={i} onClick={() => handleAnswer(opt)} {...speakProps(opt, optionLang)} className="w-full text-left px-4 py-3 rounded-lg border text-sm transition-colors hover:border-blue-400" style={{background: 'var(--card-bg)', color: 'var(--text-primary)', borderColor: 'var(--border)'}}>
                                    {opt}
                                </button>
                            ))}
                        </div>
                    )}

                    {/* Text input */}
                    {!q.options && (
                        <form onSubmit={e => { e.preventDefault(); if (textInput.trim()) handleAnswer(textInput.trim()); }} className="mt-4">
                            <input
                                type="text"
                                value={textInput}
                                onChange={e => setTextInput(e.target.value)}
                                autoFocus
                                placeholder="Skriv ditt svar..."
                                className="w-full px-4 py-3 rounded-lg border text-sm"
                                style={{background: 'var(--card-bg)', color: 'var(--text-primary)', borderColor: 'var(--border)'}}
                            />
                            <button type="submit" className="w-full mt-2 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">Svara</button>
                        </form>
                    )}
                </div>
            )}

            {/* Feedback: re-render the same question, buttons colored to show what happened */}
            {status === 'feedback' && feedback && (
                <div className="rounded-xl p-6 mb-4" style={{background: 'var(--card-bg)', border: '1px solid var(--border)'}}>
                    {renderPrompt(feedback.prompt, feedback.highlight, feedback.direction, feedback.phase)}

                    {feedback.options ? (
                        <div className="mt-4 space-y-2">
                            {feedback.options.map((opt, i) => {
                                const isGiven = opt === feedback.given;
                                const isCorrectOpt = opt === feedback.correctAnswer;
                                let cls = 'w-full text-left px-4 py-3 rounded-lg border text-sm';
                                let style = {background: 'var(--card-bg)', color: 'var(--text-primary)', borderColor: 'var(--border)'};
                                if (isGiven && feedback.correct) {
                                    cls += ' answer-correct-pulse';
                                } else if (isGiven && !feedback.correct) {
                                    cls += ' answer-wrong';
                                } else if (isCorrectOpt && !feedback.correct) {
                                    cls += ' answer-correct-glow';
                                }
                                return <button key={i} disabled className={cls} style={style}>{opt}</button>;
                            })}
                        </div>
                    ) : (
                        <div className="mt-4 text-center">
                            <div className="text-3xl mb-2">{feedback.correct ? '✅' : '❌'}</div>
                            {!feedback.correct && <p className="text-sm" style={{color: '#ef4444'}}>Rätt svar: <strong>{feedback.correctAnswer}</strong></p>}
                        </div>
                    )}

                    {feedback.message && <p className="mt-3 text-center text-sm font-medium" style={{color: 'var(--accent)'}}>{feedback.message}</p>}
                </div>
            )}
        </div>
    );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App />);
</script>
</body>
</html>
