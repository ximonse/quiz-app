// Hold-to-listen for all quiz players: holding still on text for a second reads
// it aloud, instead of showing 🔊 buttons. The only visible speaker is the mute
// switch in the header, and it silences this too.
// Elements opt in with data-speak="text" (and data-speak-lang="sv"), or through
// attachLongPress() when the text must be computed when the hold fires.
// A hold never counts as a tap: the click that follows it is swallowed, so
// holding on an answer option or a flashcard only reads it.
const HOLD_SPEAK_MS = 1000, HOLD_MOVE_PX = 8;
let holdSpokeAt = 0;
function holdSpeakSwallowsClick() { return Date.now() - holdSpokeAt < 5000; }
function attachLongPress(el, speak) {
    let timer = null, start = null;
    const cancel = () => { clearTimeout(timer); timer = null; };
    el.addEventListener('pointerdown', e => { start = [e.clientX, e.clientY]; cancel(); timer = setTimeout(() => { timer = null; holdSpokeAt = Date.now(); speak(); }, HOLD_SPEAK_MS); });
    el.addEventListener('pointermove', e => { if (timer && Math.hypot(e.clientX - start[0], e.clientY - start[1]) > HOLD_MOVE_PX) cancel(); });
    for (const type of ['pointerup', 'pointercancel', 'pointerleave']) el.addEventListener(type, cancel);
    el.addEventListener('contextmenu', e => e.preventDefault());
}
(function () {
    let timer = null, start = null;
    const cancel = () => { clearTimeout(timer); timer = null; };
    const speakable = e => e.target.closest && e.target.closest('[data-speak]');
    document.addEventListener('pointerdown', e => {
        holdSpokeAt = 0; cancel();
        const el = speakable(e); if (!el) return;
        start = [e.clientX, e.clientY];
        timer = setTimeout(() => {
            timer = null; holdSpokeAt = Date.now();
            if (!ttsIsMuted()) speakText(el.dataset.speak, el.dataset.speakLang || 'sv');
        }, HOLD_SPEAK_MS);
    }, true);
    document.addEventListener('pointermove', e => { if (timer && Math.hypot(e.clientX - start[0], e.clientY - start[1]) > HOLD_MOVE_PX) cancel(); }, true);
    for (const type of ['pointerup', 'pointercancel']) document.addEventListener(type, cancel, true);
    document.addEventListener('keydown', () => { holdSpokeAt = 0; }, true);
    document.addEventListener('click', e => { if (holdSpokeAt && holdSpeakSwallowsClick()) { holdSpokeAt = 0; e.preventDefault(); e.stopPropagation(); } }, true);
    document.addEventListener('contextmenu', e => { if (speakable(e)) e.preventDefault(); });
    // iOS only allows speech that starts from a user gesture; unlock once on the first touch.
    document.addEventListener('pointerdown', () => {
        if (!('speechSynthesis' in window)) return;
        const silent = new SpeechSynthesisUtterance(' '); silent.volume = 0; window.speechSynthesis.speak(silent);
    }, {once: true});
    const style = document.createElement('style');
    style.textContent = '[data-speak]{-webkit-touch-callout:none;-webkit-user-select:none;user-select:none}';
    document.head.append(style);
})();
