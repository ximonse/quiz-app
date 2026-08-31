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
    let masteryCounts = {};
    let phaseItemIndices = [];
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

    function getRequiredCorrect() {
        if (isTest) return 1;
        const configured = direction === 'reverse'
            ? settings.reverse_required_correct
            : settings.required_correct;
        return Math.max(1, Number(configured) || 1);
    }

    function resetPhaseMastery() {
        phaseItemIndices = queue.map(item => item._index);
        masteryCounts = Object.fromEntries(phaseItemIndices.map(index => [index, 0]));
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
        resetPhaseMastery();
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
            if (direction === 'forward' && phase === 'text') {
                // Skrivsvar ska alltid vara på språket eleven övar (samma som uttal/TTS),
                // så skriv-frågor kräver alltid källordet — oavsett riktning.
                return { prompt: item.translation, highlight: null, answer: item.word, options: buildOptions(item, 'forward') };
            } else if (direction === 'forward') {
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
            const requiredCorrect = getRequiredCorrect();
            const masteryCount = Math.min((masteryCounts[item._index] || 0) + 1, requiredCorrect);
            masteryCounts[item._index] = masteryCount;
            result.masteryCount = masteryCount;
            result.requiredCorrect = requiredCorrect;
            if (masteryCount >= requiredCorrect) {
                correctCount++;
                queue.shift(); // Mastered: remove from queue
            } else {
                queue.push(queue.shift()); // Keep rotating until mastered
            }
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
            resetPhaseMastery();
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
        const requiredCorrect = getRequiredCorrect();
        const masteredCount = phaseItemIndices.filter(index => (masteryCounts[index] || 0) >= requiredCorrect).length;
        return { correctCount, totalErrors, answered, totalQuestions, remaining: queue.length, direction, phase, flawless: totalErrors === 0, masteryCounts: { ...masteryCounts }, phaseItemIndices, requiredCorrect, masteredCount, phaseTotal: phaseItemIndices.length };
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
