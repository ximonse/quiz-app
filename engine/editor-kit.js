// Shared helpers for the sort and order editors.
window.EditorKit = (function () {
    const uid = prefix => prefix + '_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
    function node(tag, text, className) { const el = document.createElement(tag); if (text) el.textContent = text; if (className) el.className = className; return el; }
    function textInput(labelText, value, field, max) {
        const wrap = node('label', labelText); const el = document.createElement('input'); el.type = 'text';
        el.value = value || ''; el.dataset.field = field; el.maxLength = max; wrap.append(el); return wrap;
    }
    function button(text, onClick, className = 'secondary small-button') { const b = node('button', text, className); b.type = 'button'; b.addEventListener('click', onClick); return b; }
    // Keeps an existing file unless replaced or removed.
    function imagePicker(image) {
        const wrap = node('div', null, 'sort-image-picker'); wrap.dataset.file = image?.file || '';
        const preview = node('img'); preview.alt = '';
        const file = document.createElement('input'); file.type = 'file'; file.accept = 'image/png,image/jpeg,image/webp,image/gif'; file.dataset.field = 'file';
        const label = node('label', 'Bild (valfri)'); label.append(file);
        const alt = textInput('Bildbeskrivning (läses upp om texten saknas)', image?.alt, 'alt', 500);
        const remove = button('Ta bort bild', () => { file.value = ''; wrap.dataset.file = ''; show(null); });
        const show = src => { preview.hidden = !src; if (src) preview.src = src; else preview.removeAttribute('src'); remove.hidden = !src; alt.hidden = !src; };
        file.addEventListener('change', () => show(file.files[0] ? URL.createObjectURL(file.files[0]) : (wrap.dataset.file ? '../api/question-image.php?file=' + encodeURIComponent(wrap.dataset.file) : null)));
        wrap.append(preview, label, alt, remove);
        show(image?.file ? '../api/question-image.php?file=' + encodeURIComponent(image.file) : null);
        return wrap;
    }
    // A pending upload is sent with an empty file name; the server fills it in.
    function readImage(picker, fallbackAlt) {
        const file = picker.querySelector('[data-field=file]');
        if (!file.files[0] && !picker.dataset.file) return null;
        return {file: file.files[0] ? '' : picker.dataset.file, alt: picker.querySelector('[data-field=alt]').value.trim() || fallbackAlt || 'Bild'};
    }
    function nameUpload(card, group, i, j) { const file = card.querySelector('[data-field=file]'); if (file) file.name = `${group}_files[${i}][${j}]`; }
    function parseImport(raw) {
        const fenced = raw.match(/```(?:json)?\s*([\s\S]*?)```/); const data = JSON.parse((fenced ? fenced[1] : raw).trim());
        return {data, items: Array.isArray(data) ? data : data.items};
    }
    // Wires the "Skapa med AI" panel: copy prompt, replace/append import.
    function importPanel(addItems, isValidItem) {
        const status = document.getElementById('import-status');
        const run = replace => {
            try {
                const {data, items} = parseImport(document.getElementById('import-json').value);
                if (!Array.isArray(items) || !items.length || !items.every(isValidItem)) throw new Error();
                addItems(items, replace, Array.isArray(data) ? {} : data);
                if (!Array.isArray(data) && data.title && !document.getElementById('title').value) document.getElementById('title').value = data.title;
                const tts = document.getElementById('tts-enabled');
                if (typeof data.settings?.tts_enabled === 'boolean' && tts) tts.checked = data.settings.tts_enabled;
                status.textContent = `${items.length} frågor inlästa. Kontrollera dem och spara för granskning.`;
                return data;
            } catch { status.textContent = 'Kunde inte läsa JSON. Kontrollera att svaret följer mallen.'; return null; }
        };
        document.getElementById('import-replace').addEventListener('click', () => run(true));
        document.getElementById('import-append').addEventListener('click', () => run(false));
        document.getElementById('copy-prompt').addEventListener('click', async () => {
            const copy = document.getElementById('copy-status'), prompt = document.getElementById('ai-prompt');
            try { await navigator.clipboard.writeText(prompt.textContent); copy.textContent = 'Kopierad.'; }
            catch { prompt.hidden = false; copy.textContent = 'Markera och kopiera prompten nedan.'; }
        });
        return run;
    }
    return {uid, node, textInput, button, imagePicker, readImage, nameUpload, parseImport, importPanel};
})();
