(function () {
    const container = document.getElementById('editor-questions');
    const MAX_FIELDS = 5, MAX_OBJECTS = 12;
    const uid = prefix => prefix + '_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
    function node(tag, text, className) { const el = document.createElement(tag); if (text) el.textContent = text; if (className) el.className = className; return el; }
    function textInput(labelText, value, field, max) {
        const wrap = node('label', labelText); const el = document.createElement('input'); el.type = 'text';
        el.value = value || ''; el.dataset.field = field; el.maxLength = max; wrap.append(el); return wrap;
    }
    function button(text, onClick, className = 'secondary small-button') { const b = node('button', text, className); b.type = 'button'; b.addEventListener('click', onClick); return b; }
    // Image picker shared by fields and objects; keeps an existing file unless replaced or removed.
    function imagePicker(image) {
        const wrap = node('div', null, 'sort-image-picker'); wrap.dataset.file = image?.file || '';
        const preview = node('img'); preview.alt = '';
        const show = src => { preview.hidden = !src; if (src) preview.src = src; else preview.removeAttribute('src'); remove.hidden = !src; alt.hidden = !src; };
        const file = document.createElement('input'); file.type = 'file'; file.accept = 'image/png,image/jpeg,image/webp,image/gif'; file.dataset.field = 'file';
        const label = node('label', 'Bild (valfri)'); label.append(file);
        const alt = textInput('Bildbeskrivning (läses upp om texten saknas)', image?.alt, 'alt', 500);
        const remove = button('Ta bort bild', () => { file.value = ''; wrap.dataset.file = ''; show(null); });
        file.addEventListener('change', () => show(file.files[0] ? URL.createObjectURL(file.files[0]) : (wrap.dataset.file ? '../api/question-image.php?file=' + encodeURIComponent(wrap.dataset.file) : null)));
        wrap.append(preview, label, alt, remove);
        show(image?.file ? '../api/question-image.php?file=' + encodeURIComponent(image.file) : null);
        return wrap;
    }
    function readImage(picker, fallbackAlt) {
        const file = picker.querySelector('[data-field=file]');
        if (!file.files[0] && !picker.dataset.file) return null;
        return {file: file.files[0] ? '' : picker.dataset.file, alt: picker.querySelector('[data-field=alt]').value.trim() || fallbackAlt || 'Bild'};
    }
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
            const pick = (card, group, j) => { const file = card.querySelector('[data-field=file]'); if (file) file.name = `${group}_files[${i}][${j}]`; };
            const fields = [...row.querySelectorAll('.sort-edit-field')].map((card, j) => { pick(card, 'field', j);
                const title = card.querySelector('[data-field=title]').value.trim();
                return {id: card.dataset.id, title, text: card.querySelector('[data-field=text]').value.trim(), image: readImage(card.querySelector('.sort-image-picker'), title)}; });
            const objects = [...row.querySelectorAll('.sort-edit-object')].map((card, j) => { pick(card, 'object', j);
                const text = card.querySelector('[data-field=text]').value.trim();
                return {id: card.dataset.id, text, field: card.querySelector('[data-field=field]').value || null, image: readImage(card.querySelector('.sort-image-picker'), text)}; });
            return {question: row.querySelector('[data-field=question]').value.trim(), flashcard_prompt: row.querySelector('[data-field=flashcard_prompt]').value.trim(),
                image: JSON.parse(row.dataset.infoImage), fields, objects};
        });
    }
    function importJson(replace) {
        const status = document.getElementById('import-status');
        let raw = document.getElementById('import-json').value.trim();
        const fenced = raw.match(/```(?:json)?\s*([\s\S]*?)```/); if (fenced) raw = fenced[1];
        try {
            const data = JSON.parse(raw); const items = Array.isArray(data) ? data : data.items;
            if (!Array.isArray(items) || !items.length || items.some(it => !Array.isArray(it.fields) || !Array.isArray(it.objects))) throw new Error();
            if (replace) container.replaceChildren();
            for (const item of items) addQuestion(item);
            if (!Array.isArray(data) && data.title && !document.getElementById('title').value) document.getElementById('title').value = data.title;
            const s = data.settings || {};
            if (typeof s.tts_enabled === 'boolean') document.getElementById('tts-enabled').checked = s.tts_enabled;
            if (typeof s.generate_flashcards === 'boolean') document.getElementById('generate-flashcards').checked = s.generate_flashcards;
            status.textContent = `${items.length} frågor inlästa. Kontrollera dem och spara för granskning.`;
        } catch { status.textContent = 'Kunde inte läsa JSON. Kontrollera att svaret följer mallen.'; }
    }
    if (INITIAL_SORT_ITEMS.length) INITIAL_SORT_ITEMS.forEach(addQuestion); else addQuestion();
    document.getElementById('add-question').addEventListener('click', () => addQuestion());
    document.getElementById('import-replace').addEventListener('click', () => importJson(true));
    document.getElementById('import-append').addEventListener('click', () => importJson(false));
    document.getElementById('copy-prompt').addEventListener('click', async () => {
        const status = document.getElementById('copy-status');
        try { await navigator.clipboard.writeText(document.getElementById('sort-prompt').textContent); status.textContent = 'Kopierad.'; }
        catch { document.getElementById('sort-prompt').hidden = false; status.textContent = 'Markera och kopiera prompten nedan.'; }
    });
    document.getElementById('sort-editor').addEventListener('submit', event => {
        const items = collect();
        for (const item of items) {
            if (!item.objects.some(o => o.field)) { event.preventDefault(); return alert('Minst ett objekt i varje fråga måste höra till ett fält.'); }
            if (item.fields.some(f => !f.title && !f.image) || item.objects.some(o => !o.text && !o.image)) { event.preventDefault(); return alert('Varje fält behöver en rubrik eller bild, och varje objekt en text eller bild.'); }
        }
        document.getElementById('items-json').value = JSON.stringify(items);
    });
})();
