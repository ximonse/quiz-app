(function () {
    const {uid, node, textInput, button, imagePicker, readImage, nameUpload, importPanel} = window.EditorKit;
    const container = document.getElementById('editor-questions');
    const MAX_FIELDS = 5, MAX_OBJECTS = 12;
    function refreshFieldOptions(row) {
        const fields = [...row.querySelectorAll('.sort-edit-field')].map((f, i) => [f.dataset.id, f.querySelector('[data-field=title]').value.trim() || `Fält ${i + 1}`]);
        for (const select of row.querySelectorAll('select[data-field=field]')) {
            const keep = select.value || select.dataset.initial || '';
            select.replaceChildren(new Option('Inget fält (ska ligga kvar utanför)', ''), ...fields.map(([id, title]) => new Option(title, id)));
            select.value = fields.some(([id]) => id === keep) ? keep : ''; delete select.dataset.initial;
        }
    }
    function addField(row, field = {id: uid('f'), title: '', text: '', image: null}) {
        const list = row.querySelector('.sort-edit-fields'); if (list.children.length >= MAX_FIELDS) return alert('Högst fem fält per fråga.');
        const card = node('div', null, 'sort-edit-field'); card.dataset.id = field.id;
        card.append(textInput('Rubrik', field.title, 'title', 100), textInput('Infotext (valfri)', field.text, 'text', 500), imagePicker(field.image),
            button('Ta bort fält', () => { if (list.children.length <= 1) return alert('Varje fråga behöver minst ett fält.'); card.remove(); refreshFieldOptions(row); }));
        card.querySelector('[data-field=title]').addEventListener('input', () => refreshFieldOptions(row));
        list.append(card); refreshFieldOptions(row);
    }
    function addObject(row, object = {id: uid('o'), text: '', image: null, field: null}) {
        const list = row.querySelector('.sort-edit-objects'); if (list.children.length >= MAX_OBJECTS) return alert('Högst tolv objekt per fråga.');
        const card = node('div', null, 'sort-edit-object'); card.dataset.id = object.id;
        const select = document.createElement('select'); select.dataset.field = 'field'; select.dataset.initial = object.field || '';
        const belongs = node('label', 'Hör till'); belongs.append(select);
        card.append(textInput('Text', object.text, 'text', 200), belongs, imagePicker(object.image),
            button('Ta bort', () => { if (list.children.length <= 2) return alert('Varje fråga behöver minst två objekt.'); card.remove(); }));
        list.append(card); refreshFieldOptions(row);
    }
    function addQuestion(item = null) {
        item = item || {question: '', flashcard_prompt: '', image: null, fields: [{id: uid('f'), title: ''}, {id: uid('f'), title: ''}], objects: [{id: uid('o'), text: ''}, {id: uid('o'), text: ''}]};
        const row = node('section', null, 'question-card question-row'); row.dataset.infoImage = JSON.stringify(item.image || null);
        const top = node('div', null, 'question-top'); top.append(node('h2', 'Fråga'),
            button('Ta bort fråga', () => { if (container.children.length <= 1) return alert('Quizet behöver minst en fråga.'); row.remove(); }));
        const question = node('textarea'); question.dataset.field = 'question'; question.required = true; question.rows = 2; question.value = item.question || '';
        const qLabel = node('label', 'Huvudfråga (kan läsas upp)'); qLabel.append(question);
        const hint = textInput('Ledtråd på flashcards, t.ex. ”Vilket rike tillhör”', item.flashcard_prompt, 'flashcard_prompt', 300);
        row.append(top, qLabel, hint, node('h3', 'Fält'), node('div', null, 'sort-edit-fields'));
        row.append(button('+ Lägg till fält', () => addField(row)), node('h3', 'Objekt'), node('div', null, 'sort-edit-objects'), button('+ Lägg till objekt', () => addObject(row)));
        container.append(row);
        for (const field of item.fields || []) addField(row, {id: field.id || uid('f'), title: field.title || '', text: field.text || '', image: field.image || null});
        for (const object of item.objects || []) addObject(row, {id: object.id || uid('o'), text: object.text || '', image: object.image || null, field: object.field ?? null});
    }
    function collect() {
        return [...container.children].map((row, i) => {
            const fields = [...row.querySelectorAll('.sort-edit-field')].map((card, j) => { nameUpload(card, 'field', i, j);
                const title = card.querySelector('[data-field=title]').value.trim();
                return {id: card.dataset.id, title, text: card.querySelector('[data-field=text]').value.trim(), image: readImage(card.querySelector('.sort-image-picker'), title)}; });
            const objects = [...row.querySelectorAll('.sort-edit-object')].map((card, j) => { nameUpload(card, 'object', i, j);
                const text = card.querySelector('[data-field=text]').value.trim();
                return {id: card.dataset.id, text, field: card.querySelector('[data-field=field]').value || null, image: readImage(card.querySelector('.sort-image-picker'), text)}; });
            return {question: row.querySelector('[data-field=question]').value.trim(), flashcard_prompt: row.querySelector('[data-field=flashcard_prompt]').value.trim(),
                image: JSON.parse(row.dataset.infoImage), fields, objects};
        });
    }
    if (INITIAL_ITEMS.length) INITIAL_ITEMS.forEach(addQuestion); else addQuestion();
    document.getElementById('add-question').addEventListener('click', () => addQuestion());
    importPanel((items, replace, data) => {
        if (replace) container.replaceChildren();
        items.forEach(addQuestion);
        if (typeof data.settings?.generate_flashcards === 'boolean') document.getElementById('generate-flashcards').checked = data.settings.generate_flashcards;
    }, item => Array.isArray(item.fields) && Array.isArray(item.objects));
    document.getElementById('quiz-editor').addEventListener('submit', event => {
        const items = collect();
        for (const item of items) {
            if (!item.objects.some(o => o.field)) { event.preventDefault(); return alert('Minst ett objekt i varje fråga måste höra till ett fält.'); }
            if (item.fields.some(f => !f.title && !f.image) || item.objects.some(o => !o.text && !o.image)) { event.preventDefault(); return alert('Varje fält behöver en rubrik eller bild, och varje objekt en text eller bild.'); }
        }
        document.getElementById('items-json').value = JSON.stringify(items);
    });
})();
