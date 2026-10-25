// Lugn repetitionsspelare: en fråga i taget, inga nedräkningar, inga röda
// felmeddelanden, ingen poäng som visas för eleven. Rätt svar krävs för att
// gå vidare (annars skulle "klarad" bli otydligt), men antalet fel-försök
// skickas med som svårighetssignal till FSRS-schemaläggningen.
(function () {
    const root = document.getElementById('review-root');
    if (!root) return;
    const $ = id => document.getElementById(id);
    const queue = Array.isArray(window.reviewQueue) ? [...window.reviewQueue] : [];
    let index = 0, wrongAttempts = 0, locked = false;

    function shuffledOptions(q) {
        const options = [...q.options];
        for (let i = options.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [options[i], options[j]] = [options[j], options[i]]; }
        return options;
    }

    function render() {
        const q = queue[index];
        if (!q) { finish(); return; }
        wrongAttempts = 0; locked = false;
        $('review-progress').textContent = `Fråga ${index + 1} av ${queue.length}`;
        $('review-question').textContent = q.prompt;
        $('review-feedback').textContent = '';
        const optionsRoot = $('review-options');
        optionsRoot.replaceChildren();
        for (const option of shuffledOptions(q)) {
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'button secondary'; button.textContent = option;
            button.addEventListener('click', () => choose(option, button));
            optionsRoot.append(button);
        }
    }

    async function choose(option, button) {
        if (locked) return;
        const q = queue[index];
        if (option === q.answer) {
            locked = true;
            button.textContent = button.textContent + ' ✓';
            $('review-feedback').textContent = 'Bra!';
            await save(q, wrongAttempts);
            setTimeout(() => { index++; render(); }, 500);
        } else {
            wrongAttempts++;
            button.disabled = true;
            $('review-feedback').textContent = 'Testa en gång till.';
        }
    }

    async function save(q, wrong) {
        try {
            await fetch('../api/repetition-review.php', {
                method: 'POST', credentials: 'same-origin', keepalive: true,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.reviewCsrf },
                body: JSON.stringify({ quiz_id: q.quiz_id, question_index: q.question_index, wrong_attempts: wrong })
            });
        } catch { /* Tyst — sparas inte, men stör inte elevens flöde. */ }
    }

    function finish() {
        $('review-progress').textContent = '';
        $('review-question').textContent = '';
        $('review-options').replaceChildren();
        $('review-feedback').textContent = '';
        $('review-done').hidden = false;
    }

    render();
})();
