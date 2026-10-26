// A result keeps one request ID until the server confirms it. Pending work is
// kept in this page only; closing an offline page does not persist pupil data.
(() => {
    const pending = new Set();
    let savedNotice = null;
    function send(url, payload) {
        const requestId = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
        const body = JSON.stringify({...payload, request_id: requestId});
        const notice = document.createElement('p');
        notice.setAttribute('role', 'status');
        notice.style.cssText = 'margin:12px 16px;color:var(--text-primary,#111c2b);font:16px/1.4 system-ui';
        const text = document.createTextNode('Sparar resultatet… ');
        const retry = document.createElement('button');
        retry.type = 'button'; retry.textContent = 'Försök spara igen'; retry.hidden = true;
        notice.append(text, retry); document.body.prepend(notice);
        let sending = false;
        return new Promise(resolve => {
            async function attempt() {
                if (sending) return;
                sending = true; retry.hidden = true;
                text.textContent = 'Sparar resultatet… ';
                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), 15000);
                try {
                    const response = await fetch(url, {
                        method: 'POST', credentials: 'same-origin', keepalive: true, signal: controller.signal,
                        headers: {'Content-Type':'application/json', ...(window.reviewCsrf ? {'X-CSRF-Token':window.reviewCsrf} : {})}, body
                    });
                    const result = await response.json();
                    if (!response.ok || !(result.saved === true || result.success === true || result.reason === 'training_only')) throw new Error('Save rejected');
                    text.textContent = result.reason === 'training_only' ? 'Träningsomgång – inget testresultat sparas.' : 'Resultatet är sparat.';
                    if (savedNotice) savedNotice.remove();
                    savedNotice = notice;
                    pending.delete(attempt); retry.remove(); resolve(result);
                } catch {
                    text.textContent = 'Resultatet är inte sparat. Kontrollera anslutningen. Om du har loggats ut behöver du logga in igen. ';
                    retry.hidden = false;
                    notice.scrollIntoView({block:'nearest'});
                } finally { clearTimeout(timeout); sending = false; }
            }
            retry.addEventListener('click', attempt); pending.add(attempt); attempt();
        });
    }
    window.QuizSave = {send};
    window.addEventListener('online', () => { for (const retry of pending) retry(); });
})();
