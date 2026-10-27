// Render DOM nodes instead of interpolating teacher/AI text into HTML.
(async function () {
    const root = document.getElementById('sort-player');
    const $ = id => document.getElementById(id); const preview = root.dataset.preview === '1';
    let quiz, engine, presentation, board, submitted = false;
    const wrongCounts = {}; // item index -> accumulated wrong objects, for the spaced-repetition signal
    const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
    const imageNode = image => {
        const img = document.createElement('img'); img.alt = image.alt; img.draggable = false;
        img.src = '../api/question-image.php?file=' + encodeURIComponent(image.file);
        img.addEventListener('error', () => { img.alt = 'Bilden kunde inte laddas. ' + image.alt; });
        return img;
    };
    const textOf = entry => [entry.title, entry.text].filter(Boolean).join('. ') || entry.image?.alt || '';
    // The header mute switch silences all reading, including hold-to-listen.
    function say(text) { if (quiz.settings.tts_enabled && !ttsIsMuted()) speakText(text, quiz.settings.language); }
    function renderMute() {
        const muted = ttsIsMuted(); $('mute').textContent = muted ? '🔇' : '🔊';
        $('mute').setAttribute('aria-label', muted ? 'Slå på uppläsning' : 'Stäng av uppläsning'); $('mute').setAttribute('aria-pressed', String(muted));
    }
    function updateProgress() {
        const p = engine.progress();
        $('progress').textContent = quiz.settings.quiz_mode === 'test' ? `${p.answered} av ${p.total} frågor besvarade` : `${p.mastered} av ${p.total} frågor klara · ${p.required} rätt per objekt`;
    }
    function objectNode(object) {
        const el = document.createElement('button'); el.type = 'button'; el.className = 'sort-object' + (object.image ? ' has-image' : '');
        el.dataset.id = object.id; el.dataset.flip = ''; el.setAttribute('aria-pressed', 'false');
        if (object.image) el.append(imageNode(object.image));
        if (object.text) { const span = document.createElement('span'); span.textContent = object.text; el.append(span); }
        el.setAttribute('aria-label', object.text || object.image.alt);
        return el;
    }
    function fieldNode(field) {
        const wrap = document.createElement('div'); wrap.className = 'sort-field'; wrap.dataset.dropTarget = '';
        const head = document.createElement('div'); head.className = 'sort-field-head speakable';
        if (field.image) head.append(imageNode(field.image));
        if (field.title) { const h = document.createElement('h3'); h.textContent = field.title; head.append(h); }
        if (field.text) { const p = document.createElement('p'); p.textContent = field.text; head.append(p); }
        attachLongPress(head, () => say(textOf(field)));
        const zone = document.createElement('div'); zone.className = 'sort-zone'; zone.dataset.field = field.id; zone.tabIndex = 0;
        zone.setAttribute('aria-label', 'Fält: ' + textOf(field));
        wrap.append(head, zone); return wrap;
    }
    function shuffle(list) { for (let i = list.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [list[i], list[j]] = [list[j], list[i]]; } return list; }
    function render() {
        presentation = engine.current(); submitted = false;
        updateProgress();
        if (!presentation) return finish();
        const {item, objectIds, reveal} = presentation;
        $('question-card').hidden = false; $('question').textContent = item.question;
        $('info-image').replaceChildren(); if (item.image) $('info-image').append(imageNode(item.image));
        $('instructions').textContent = reveal ? 'Dra objekten nedanför till fältet där de hör hemma. Allt behöver inte flyttas. Tryck på Klar när du är färdig.'
            : 'Öva igen på de här objekten. Tryck på Klar när du är färdig.';
        $('fields').replaceChildren(...item.fields.map(fieldNode));
        $('fields').dataset.count = String(item.fields.length);
        $('tray').replaceChildren(...shuffle(item.objects.filter(o => objectIds.includes(o.id))).map(objectNode));
        $('feedback').textContent = ''; $('check').hidden = false; $('next').hidden = true;
        board.lock(false); say(item.question);
    }
    async function check() {
        if (submitted) return; submitted = true; board.lock(true); $('check').hidden = true;
        const placements = {};
        for (const el of $('board').querySelectorAll('.sort-object')) placements[el.dataset.id] = el.closest('[data-field]').dataset.field || null;
        const result = engine.submit(placements); const complete = engine.progress().complete;
        const ids = Object.keys(result.statuses);
        ids.forEach((id, i) => window.ClassroomTracker?.answer(result.statuses[id] === 'correct', complete && i === ids.length - 1));
        wrongCounts[result.itemIndex] = (wrongCounts[result.itemIndex] || 0) + result.wrong;
        if (result.itemFinal) { window.ClassroomTracker?.question(result.itemIndex, wrongCounts[result.itemIndex]); delete wrongCounts[result.itemIndex]; }
        const nodes = [...$('board').querySelectorAll('.sort-object')];
        for (const el of nodes) if (result.statuses[el.dataset.id] === 'correct') el.classList.add('correct');
        const wrong = nodes.filter(el => result.statuses[el.dataset.id] === 'wrong');
        wrong.forEach(el => el.classList.add('blink-wrong'));
        if (wrong.length) await wait(1300);
        wrong.forEach(el => el.classList.remove('blink-wrong'));
        if (result.reveal) for (const el of wrong) {
            const field = presentation.item.objects.find(o => o.id === el.dataset.id).field;
            const target = field ? $('fields').querySelector(`[data-field="${CSS.escape(field)}"]`) : $('tray');
            board.place(el, target, target.children.length); el.classList.add('correct', 'revealed'); await wait(280);
        }
        $('feedback').textContent = `${result.right} rätt · ${result.wrong} fel` + (result.wrong && !result.reveal ? ' · objekten ligger kvar där du lade dem' : '');
        updateProgress(); $('next').hidden = false; $('next').textContent = complete ? 'Visa resultat' : 'Nästa'; $('next').focus();
    }
    async function finish() {
        const results = engine.results(); $('question-card').hidden = true;
        $('quiz-title').textContent = 'Klart!'; $('progress').textContent = `${root.dataset.name}: ${results.score} rätt av ${results.total} placeringar.`;
        if (preview) { $('preview-label').textContent = 'Förhandsvisning · inget elevresultat sparades'; return; }
        try {
            const saved = await QuizSave.send('../api/save-result.php', {quiz_id: root.dataset.id, student_name: root.dataset.name, student_id: window.classroomContext?.student_id || null, browser_id: getQuizBrowserId(), ...results});
            if (saved.reason === 'training_only') { $('preview-label').textContent = 'Träning · inget testresultat sparades'; return; }
            if (!saved.saved) throw new Error('Resultatet kunde inte sparas.');
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
        if (quiz.type !== 'sort') throw new Error('Det här är inte ett sorteringsquiz.');
        $('quiz-title').textContent = quiz.title; if (preview) $('preview-label').textContent = 'Förhandsvisning · bara du kan se det här';
        $('mute').hidden = !quiz.settings.tts_enabled; renderMute();
        attachLongPress($('question'), () => say(presentation?.item.question || ''));
        board = DragBoard({root: $('board'), containerSelector: '[data-field]', onSpeak: el => say(el.getAttribute('aria-label'))});
        engine = SortEngine(quiz.items, quiz.settings); render();
    } catch (e) { $('quiz-title').textContent = 'Quiz'; $('player-error').textContent = e.message; }
})();
