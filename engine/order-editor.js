(function () {
    const {uid, node, textInput, button, imagePicker, readImage, nameUpload, importPanel} = window.EditorKit;
    const container = document.getElementById('editor-questions');
    const MAX_OBJECTS = 8;
    function renumber(list) { [...list.children].forEach((card, i) => { card.querySelector('.order-number').textContent = i + 1; }); }
    function addObject(row, object = {id: uid('o'), text: '', image: null}) {
        const list = row.querySelector('.order-edit-objects'); if (list.children.length >= MAX_OBJECTS) return alert('Högst åtta objekt per fråga.');
        const card = node('div', null, 'sort-edit-object order-edit-object'); card.dataset.id = object.id;
        const move = delta => { const target = delta < 0 ? card.previousElementSibling : card.nextElementSibling?.nextElementSibling; if (delta < 0 && !target) return; list.insertBefore(card, target || null); renumber(list); };
        const tools = node('div', null, 'order-edit-tools'); tools.append(node('span', '', 'order-number'), button('↑', () => move(-1)), button('↓', () => move(1)),
            button('Ta bort', () => { if (list.children.length <= 2) return alert('Varje fråga behöver minst två objekt.'); card.remove(); renumber(list); }));
        tools.querySelectorAll('button')[0].setAttribute('aria-label', 'Flytta upp'); tools.querySelectorAll('button')[1].setAttribute('aria-label', 'Flytta ner');
        card.append(tools, textInput('Text', object.text, 'text', 200), imagePicker(object.image));
        list.append(card); renumber(list);
    }
    function addQuestion(item = null) {
        item = item || {question: '', image: null, orientation: 'horizontal', labels: {start: '', middle: '', end: ''}, objects: [{id: uid('o'), text: ''}, {id: uid('o'), text: ''}]};
        const row = node('section', null, 'question-card question-row'); row.dataset.infoImage = JSON.stringify(item.image || null);
        const top = node('div', null, 'question-top'); top.append(node('h2', 'Fråga'),
            button('Ta bort fråga', () => { if (container.children.length <= 1) return alert('Quizet behöver minst en fråga.'); row.remove(); }));
        const question = node('textarea'); question.dataset.field = 'question'; question.required = true; question.rows = 2; question.value = item.question || '';
        const qLabel = node('label', 'Huvudfråga (kan läsas upp)'); qLabel.append(question);
        const orientation = document.createElement('select'); orientation.dataset.field = 'orientation';
        orientation.append(new Option('Vågrät linje (vänster → höger)', 'horizontal'), new Option('Lodrät linje (uppifrån och ner)', 'vertical'));
        orientation.value = item.orientation === 'vertical' ? 'vertical' : 'horizontal';
        const oLabel = node('label', 'Linje'); oLabel.append(orientation);
        const labels = node('div', null, 'order-edit-labels'); const l = item.labels || {};
        labels.append(textInput('Text vid början', l.start, 'start', 100), textInput('Text i mitten', l.middle, 'middle', 100), textInput('Text vid slutet', l.end, 'end', 100));
        row.append(top, qLabel, oLabel, labels, node('h3', 'Objekt i rätt ordning (eleven får dem blandade)'), node('div', null, 'order-edit-objects'), button('+ Lägg till objekt', () => addObject(row)));
        container.append(row);
        for (const object of item.objects || []) addObject(row, {id: object.id || uid('o'), text: object.text || '', image: object.image || null});
    }
    function collect() {
        return [...container.children].map((row, i) => ({
            question: row.querySelector('[data-field=question]').value.trim(), image: JSON.parse(row.dataset.infoImage),
            orientation: row.querySelector('[data-field=orientation]').value,
            labels: Object.fromEntries(['start', 'middle', 'end'].map(k => [k, row.querySelector(`.order-edit-labels [data-field=${k}]`).value.trim()])),
            objects: [...row.querySelectorAll('.order-edit-object')].map((card, j) => { nameUpload(card, 'object', i, j);
                const text = card.querySelector('[data-field=text]').value.trim();
                return {id: card.dataset.id, text, image: readImage(card.querySelector('.sort-image-picker'), text)}; })
        }));
    }
    if (INITIAL_ITEMS.length) INITIAL_ITEMS.forEach(addQuestion); else addQuestion();
    document.getElementById('add-question').addEventListener('click', () => addQuestion());
    importPanel((items, replace) => { if (replace) container.replaceChildren(); items.forEach(addQuestion); }, item => Array.isArray(item.objects));
    document.getElementById('quiz-editor').addEventListener('submit', event => {
        const items = collect();
        if (items.some(item => item.objects.some(o => !o.text && !o.image))) { event.preventDefault(); return alert('Varje objekt behöver en text eller bild.'); }
        document.getElementById('items-json').value = JSON.stringify(items);
    });
})();
