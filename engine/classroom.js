// One cumulative record per player visit. Retries are idempotent; pupil pages never fetch scores.
(() => {
    const context = window.classroomContext;
    if (!context?.assignment_id) return;
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    const attempt = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    let seq = 0, right = 0, wrong = 0, completed = false, active = false, pending = null, sending = false;
    const cards = new Set();
    // Separate from right/wrong above (which count every raw attempt, retries
    // included, for the existing flawless/done/shaky grading). This is one
    // entry per fully-finished question, with how many wrong tries it took —
    // the signal the spaced-repetition scheduler uses. Never resets: resent
    // in full on every flush, so the backend tracks how many entries it has
    // already applied (see answers_applied in classroomRecord()).
    const answers = [];
    const notice = document.createElement('p');
    notice.setAttribute('role', 'status'); notice.style.cssText = 'margin:8px 16px;color:#b91c1c;font:16px system-ui';
    const retry = document.createElement('button'); retry.textContent = 'Försök spara igen'; retry.hidden = true;
    retry.onclick = () => flush(); notice.append(retry);
    document.addEventListener('DOMContentLoaded', () => document.body.append(notice));
    function message(text) { notice.firstChild?.nodeType === 3 ? notice.firstChild.textContent = text : notice.prepend(document.createTextNode(text)); retry.hidden = !text; }
    function snapshot() { return {assignment_id: context.assignment_id, student_id: context.student_id, attempt_id: attempt, seq: ++seq, right, wrong, cards: cards.size, completed, answers: [...answers]}; }
    async function flush() {
        if (sending || !pending) return;
        sending = true;
        const value = pending;
        try {
            const response = await fetch('../api/classroom-activity.php', {method:'POST', credentials:'same-origin', keepalive:true, headers:{'Content-Type':'application/json','X-CSRF-Token':context.csrf}, body:JSON.stringify(value)});
            if (!response.ok) throw new Error('Sparningen misslyckades.');
            if (pending === value) pending = null;
            message('');
        } catch { message('Din övning kunde inte sparas. Kontrollera anslutningen eller logga in igen. '); }
        finally { sending = false; }
        if (pending && pending !== value) flush();
    }
    function save() { active = true; pending = snapshot(); flush(); }
    window.ClassroomTracker = {
        answer(correct, complete = false) { correct ? right++ : wrong++; completed ||= complete; save(); },
        // Call once a question is truly done for this session (no more retries
        // coming), with how many wrong tries it took. Independent of answer()
        // above — does not affect right/wrong or the pupil's grading.
        question(key, wrongAttempts = 0) { answers.push({q: String(key), w: Math.max(0, wrongAttempts | 0)}); save(); },
        card(id, total) { cards.add(String(id)); completed ||= total > 0 && cards.size >= total; save(); },
        complete() { if (active) { completed = true; save(); } }
    };
    window.addEventListener('online', flush);
    window.addEventListener('pagehide', () => { if (active) { pending = snapshot(); sending = false; flush(); } });
})();
