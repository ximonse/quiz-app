// Render DOM nodes instead of interpolating teacher/AI text into HTML.
(async function () {
    const root = document.getElementById('order-player');
    const $ = id => document.getElementById(id); const preview = root.dataset.preview === '1';
    let quiz, engine, presentation, board, submitted = false;
    const wrongCounts = {}; // item index -> accumulated wrong tries, for the spaced-repetition signal
    const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
    const imageNode = image => {
        const img = document.createElement('img'); img.alt = image.alt; img.draggable = false;
        img.src = '../api/question-image.php?file=' + encodeURIComponent(image.file);
        img.addEventListener('error', () => { img.alt = 'Bilden kunde inte laddas. ' + image.alt; });
        return img;
    };
    // The header mute switch silences all reading, including hold-to-listen.
    function say(text) { if (text && quiz.settings.tts_enabled && !ttsIsMuted()) speakText(text, quiz.settings.language); }
    function renderMute() {
        const muted = ttsIsMuted(); $('mute').textContent = muted ? '🔇' : '🔊';
        $('mute').setAttribute('aria-label', muted ? 'Slå på uppläsning' : 'Stäng av uppläsning'); $('mute').setAttribute('aria-pressed', String(muted));
    }
    function updateProgress() {
        const p = engine.progress();
        $('progress').textContent = quiz.settings.quiz_mode === 'test' ? `${p.answered} av ${p.total} frågor besvarade` : `${p.mastered} av ${p.total} frågor klara · ${p.required} rätt per fråga`;
    }
    function objectNode(object) {
        const el = document.createElement('button'); el.type = 'button'; el.className = 'sort-object' + (object.image ? ' has-image' : '');
        el.dataset.id = object.id; el.dataset.flip = ''; el.setAttribute('aria-pressed', 'false');
        if (object.image) el.append(imageNode(object.image));
        if (object.text) { const span = document.createElement('span'); span.textContent = object.text; el.append(span); }
        el.setAttribute('aria-label', object.text || object.image.alt);
        return el;
    }
    function shuffle(list) { for (let i = list.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [list[i], list[j]] = [list[j], list[i]]; } return list; }
    function render() {
        presentation = engine.current(); submitted = false;
        updateProgress();
        if (!presentation) return finish();
        const {item, reveal} = presentation;
        $('question-card').hidden = false; $('question').textContent = item.question;
        $('info-image').replaceChildren(); if (item.image) $('info-image').append(imageNode(item.image));
        $('instructions').textContent = reveal ? 'Dra objekten nedanför till linjen och lägg dem i rätt ordning. Tryck på Klar när du är färdig.' : 'Försök igen. Tryck på Klar när du är färdig.';
        const vertical = item.orientation === 'vertical';
        $('track').classList.toggle('vertical', vertical); $('line').dataset.axis = vertical ? 'y' : 'x';
        for (const span of $('track').querySelectorAll('[data-label]')) span.textContent = item.labels?.[span.dataset.label] || '';
        $('line').replaceChildren();
        let objects = shuffle([...item.objects]);
        while (objects.length > 1 && objects.every((o, i) => o.id === item.objects[i].id)) objects = shuffle(objects);
        $('tray').replaceChildren(...objects.map(objectNode));
        $('feedback').textContent = ''; $('check').hidden = false; $('next').hidden = true;
        board.lock(false); say(item.question);
    }
    async function check() {
        if (submitted) return; submitted = true; board.lock(true); $('check').hidden = true;
        const placed = [...$('line').querySelectorAll('.sort-object')].map(el => el.dataset.id);
        const result = engine.submit(placed); const complete = engine.progress().complete;
        window.ClassroomTracker?.answer(result.correct, complete);
        if (!result.correct) wrongCounts[result.itemIndex] = (wrongCounts[result.itemIndex] || 0) + 1;
        if (result.itemFinal) { window.ClassroomTracker?.question(result.itemIndex, wrongCounts[result.itemIndex] || 0); delete wrongCounts[result.itemIndex]; }
        const nodes = [...$('board').querySelectorAll('.sort-object')];
        for (const el of nodes) if (result.statuses[el.dataset.id] === 'correct' && placed.includes(el.dataset.id)) el.classList.add('correct');
        const wrong = nodes.filter(el => result.statuses[el.dataset.id] === 'wrong');
        wrong.forEach(el => el.classList.add('blink-wrong'));
        if (wrong.length) await wait(1300);
        wrong.forEach(el => el.classList.remove('blink-wrong'));
        if (result.reveal && wrong.length) {
            // Glide everything into the correct order on the line.
            board.flip(() => { for (const object of presentation.item.objects) $('line').append($('board').querySelector(`.sort-object[data-id="${CSS.escape(object.id)}"]`)); });
            nodes.forEach(el => el.classList.add('correct')); await wait(300);
        }
        $('feedback').textContent = result.correct ? 'Rätt ordning!' : `${result.wrong} objekt på fel plats` + (result.reveal ? ' · så här är rätt ordning' : ' · objekten ligger kvar där du lade dem');
        updateProgress(); $('next').hidden = false; $('next').textContent = complete ? 'Visa resultat' : 'Nästa'; $('next').focus();
    }
    async function finish() {
        const results = engine.results(); $('question-card').hidden = true;
        $('quiz-title').textContent = 'Klart!'; $('progress').textContent = `${root.dataset.name}: ${results.score} rätt av ${results.total} besvarade frågor.`;
        if (preview) { $('preview-label').textContent = 'Förhandsvisning · inget elevresultat sparades'; return; }
        try {
            const response = await fetch('../api/save-result.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({quiz_id: root.dataset.id, student_name: root.dataset.name, student_id: window.classroomContext?.student_id || null, browser_id: getQuizBrowserId(), ...results})});
            const saved = await response.json();
            if (saved.reason === 'training_only') { $('preview-label').textContent = 'Träning · inget testresultat sparades'; return; }
            if (!response.ok || !saved.saved) throw new Error('Resultatet kunde inte sparas.');
        } catch (e) { $('player-error').textContent = e.message; }
    }
    $('check').addEventListener('click', check); $('next').addEventListener('click', render);
    $('mute').addEventListener('click', () => { ttsSetMuted(!ttsIsMuted()); renderMute(); });
    try {
        const response = await fetch('../api/quiz-data.php?id=' + encodeURIComponent(root.dataset.id) + (preview ? '&preview=1' : ''));
        quiz = await response.json();
        if (!response.ok) throw new Error(quiz.error || 'Kunde inte ladda quizet.');
        if (quiz.status === 'not_yet') throw new Error('Quizet öppnar ' + quiz.opens + '.');
        if (quiz.status === 'training_only') quiz.settings.quiz_mode = 'training';
        if (quiz.type !== 'order') throw new Error('Det här är inte ett ordningsquiz.');
        $('quiz-title').textContent = quiz.title; if (preview) $('preview-label').textContent = 'Förhandsvisning · bara du kan se det här';
        $('mute').hidden = !quiz.settings.tts_enabled; renderMute();
        attachLongPress($('question'), () => say(presentation?.item.question));
        for (const span of $('track').querySelectorAll('[data-label]')) attachLongPress(span, () => say(span.textContent));
        board = DragBoard({root: $('board'), containerSelector: '[data-zone]', onSpeak: el => say(el.getAttribute('aria-label'))});
        engine = OrderEngine(quiz.items, quiz.settings); render();
    } catch (e) { $('quiz-title').textContent = 'Quiz'; $('player-error').textContent = e.message; }
})();
