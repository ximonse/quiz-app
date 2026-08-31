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
        // Task 11 fix: capture correct answer BEFORE submitAnswer shifts the queue
        const currentItem = engine.currentItem();
        const currentQ = engine.getQuestion(currentItem);
        const correctAnswer = currentQ.answer;
        const result = engine.submitAnswer(answer, currentSpelling);
        setTextInput('');

        if (result.complete) {
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
            // Task 11 fix: use pre-captured correctAnswer (before queue shift)
            setFeedback({ correct: false, correctAnswer: correctAnswer, given: answer });
            setStatus('feedback');
            setTimeout(() => {
                setFeedback(null);
                setStatus('playing');
                // Task 11 fix: TTS for the NEW current item after shift
                const nextItem = engine.currentItem();
                const nextQ = nextItem ? engine.getQuestion(nextItem) : null;
                playTTS(nextItem, nextQ);
            }, 2000);
            return;
        }

        if (result.directionChange) {
            setFeedback({ correct: true, message: 'Nu kör vi omvänt!' });
            setStatus('feedback');
            setTimeout(() => {
                setFeedback(null);
                setStatus('playing');
                // Task 11 fix: TTS for the NEW current item after direction change
                const nextItem = engine.currentItem();
                const nextQ = nextItem ? engine.getQuestion(nextItem) : null;
                playTTS(nextItem, nextQ);
            }, 1500);
            return;
        }

        if (result.phaseChange) {
            setFeedback({ correct: true, message: 'Bra! Nu skrivsvar.' });
            setStatus('feedback');
            setTimeout(() => {
                setFeedback(null);
                setStatus('playing');
                // Task 11 fix: TTS for the NEW current item after phase change
                const nextItem = engine.currentItem();
                const nextQ = nextItem ? engine.getQuestion(nextItem) : null;
                playTTS(nextItem, nextQ);
            }, 1500);
            return;
        }

        // Show confirmation before rendering and reading the next question.
        setFeedback({ correct: true });
        setStatus('feedback');
        setTimeout(() => {
            setFeedback(null);
            setStatus('playing');
            const nextItem = engine.currentItem();
            const nextQ = nextItem ? engine.getQuestion(nextItem) : null;
            playTTS(nextItem, nextQ);
        }, 900);
    }

    function playTTS(item, q) {
        if (!quiz?.settings?.tts_enabled || !item) return;
        if (quiz.type === 'glossary' && engine.currentDirection() === 'forward' && engine.currentPhase() !== 'text') {
            speakGlossary(item.sentence, item.word, quiz.settings.language);
        } else if (q) {
            speakText(q.prompt, quiz.settings.language);
        }
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

            {/* Direction indicator */}
            {progress.direction === 'reverse' && (
                <div className="text-center mb-2">
                    <span className="text-xs px-2 py-0.5 rounded-full bg-purple-100 text-purple-700">Omvänd riktning</span>
                </div>
            )}

            {/* Question card */}
            {status === 'playing' && q && (
                <div className="rounded-xl p-6 mb-4" style={{background: 'var(--card-bg)', border: '1px solid var(--border)'}}>
                    {/* Glossary forward (mc phase only): show sentence with highlighted word */}
                    {quiz.type === 'glossary' && progress.direction === 'forward' && progress.phase !== 'text' && (
                        <div>
                            <p className="text-lg mb-2" style={{color: 'var(--text-secondary)'}}>
                                {q.prompt.replace(q.highlight, `**${q.highlight}**`).split('**').map((part, i) =>
                                    i % 2 === 1
                                        ? <strong key={i} style={{color: 'var(--accent)'}}>{part}</strong>
                                        : part
                                )}
                            </p>
                            <p className="text-sm" style={{color: 'var(--text-secondary)'}}>
                                Vad betyder <strong style={{color: 'var(--accent)'}}>{q.highlight}</strong>?
                            </p>
                        </div>
                    )}

                    {/* Glossary reverse, glossary text phase, or fact quiz: show prompt directly */}
                    {(quiz.type !== 'glossary' || progress.direction === 'reverse' || progress.phase === 'text') && (
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
