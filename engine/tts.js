// engine/tts.js — Text-to-speech for all quiz types

const LANG_MAP = {
    'sv': 'sv-SE', 'en': 'en-US', 'es': 'es-ES',
    'fr': 'fr-FR', 'de': 'de-DE', 'fi': 'fi-FI', 'uk': 'uk'
};

let selectedVoice = null;
let speechGeneration = 0;

const TTS_MUTE_KEY = 'quizapp-tts-muted';

function setVoice(voice) {
    selectedVoice = voice;
}

function ttsIsMuted() {
    try { return localStorage.getItem(TTS_MUTE_KEY) === '1'; } catch (e) { return false; }
}

function ttsSetMuted(muted) {
    try { localStorage.setItem(TTS_MUTE_KEY, muted ? '1' : '0'); } catch (e) {}
    if (muted) stopSpeech();
}

// Voices load asynchronously in some browsers — a speak() call made before
// they're ready can silently produce no audio. Wait once, then proceed.
function whenVoicesReady(callback) {
    if (!('speechSynthesis' in window)) return;
    if (window.speechSynthesis.getVoices().length > 0) { callback(); return; }
    let done = false;
    const fire = () => { if (done) return; done = true; callback(); };
    window.speechSynthesis.addEventListener('voiceschanged', fire, { once: true });
    setTimeout(fire, 300);
}

// force=true: användaren tryckte själv på en uppspelningsknapp, då ska
// uppläsningen ske även om automatisk uppläsning är avstängd (mute).
function speakText(text, lang, force) {
    if (!('speechSynthesis' in window) || !text) return;
    if (!force && ttsIsMuted()) return;
    whenVoicesReady(() => speakTextNow(text, lang, force));
}

function speakTextNow(text, lang, force) {
    if (!force && ttsIsMuted()) return;
    speechGeneration += 1;
    window.speechSynthesis.cancel();

    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = LANG_MAP[lang] || 'sv-SE';

    let voice = selectedVoice;
    if (!voice) {
        const voices = window.speechSynthesis.getVoices();
        const targetLang = LANG_MAP[lang] || 'sv-SE';
        voice = voices.find(v => v.lang.startsWith(targetLang.substring(0, 2)))
             || voices.find(v => v.lang === targetLang);

        if (!voice && (lang === 'de' || lang === 'fr')) {
            const langKey = lang === 'de' ? 'german|deutsch' : 'french|français';
            voice = voices.find(v =>
                v.lang.includes(lang) || v.lang.includes(lang.toUpperCase())
                || new RegExp(langKey, 'i').test(v.name)
            );
        }
    }

    if (voice) utterance.voice = voice;
    utterance.rate = 0.9;
    speakAfterCancel(utterance);
}

// Chrome kan tappa en utterance som skickas direkt efter cancel(), och en
// pausad kö (efter tidigare cancel) måste återupptas.
function speakAfterCancel(utterance) {
    const synth = window.speechSynthesis;
    setTimeout(() => {
        if (synth.paused) synth.resume();
        synth.speak(utterance);
    }, 60);
}

function findVoice(lang) {
    if (selectedVoice) return selectedVoice;
    const voices = window.speechSynthesis.getVoices();
    const target = LANG_MAP[lang] || 'sv-SE';
    return voices.find(v => v.lang === target)
        || voices.find(v => v.lang.startsWith(target.substring(0, 2)))
        || null;
}

const OPTION_LABELS = {
    sv: ['ett', 'två', 'tre', 'fyra', 'fem', 'sex'],
    en: ['one', 'two', 'three', 'four', 'five', 'six'],
    es: ['uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis'],
    fr: ['un', 'deux', 'trois', 'quatre', 'cinq', 'six'],
    de: ['eins', 'zwei', 'drei', 'vier', 'fünf', 'sechs']
};

// Läser upp en lista av delar i tur och ordning, med paus emellan.
// parts: [{ text, lang }]. Avbryts av nästa speak*/stopSpeech-anrop.
function speakSequence(parts, force) {
    if (!('speechSynthesis' in window)) return;
    if (!force && ttsIsMuted()) return;
    const queue = parts.filter(p => p && p.text);
    if (queue.length === 0) return;
    whenVoicesReady(() => {
        speechGeneration += 1;
        const generation = speechGeneration;
        window.speechSynthesis.cancel();
        let i = 0;
        const next = () => {
            if (generation !== speechGeneration || i >= queue.length) return;
            const part = queue[i++];
            const u = new SpeechSynthesisUtterance(part.text);
            u.lang = LANG_MAP[part.lang] || 'sv-SE';
            u.rate = 0.9;
            const voice = findVoice(part.lang);
            if (voice) u.voice = voice;
            const advance = () => setTimeout(next, 350);
            u.onend = advance;
            u.onerror = advance;
            if (i === 1) speakAfterCancel(u); else window.speechSynthesis.speak(u);
        };
        next();
    });
}

// Fråga följt av "alternativ ett", första alternativet, "alternativ två", ...
// Alternativen läses på quizets språk, "alternativ N" på svenska.
function speakQuestionWithOptions(prompt, options, lang, force) {
    const labels = OPTION_LABELS.sv;
    const parts = [{ text: prompt, lang }];
    (options || []).forEach((opt, i) => {
        parts.push({ text: 'Alternativ ' + (labels[i] || (i + 1)), lang: 'sv' });
        parts.push({ text: opt, lang });
    });
    speakSequence(parts, force);
}

function speakOptions(options, lang, force) {
    const labels = OPTION_LABELS.sv;
    const parts = [];
    (options || []).forEach((opt, i) => {
        parts.push({ text: 'Alternativ ' + (labels[i] || (i + 1)), lang: 'sv' });
        parts.push({ text: opt, lang });
    });
    speakSequence(parts, force);
}

function speakGlossary(sentence, word, lang) {
    if (!sentence) { speakText(word, lang); return; }
    if (!('speechSynthesis' in window) || ttsIsMuted()) return;
    whenVoicesReady(() => speakGlossaryNow(sentence, word, lang));
}

function speakGlossaryNow(sentence, word, lang) {
    if (ttsIsMuted()) return;
    speechGeneration += 1;
    const generation = speechGeneration;
    window.speechSynthesis.cancel();

    const u1 = new SpeechSynthesisUtterance(sentence);
    u1.lang = LANG_MAP[lang] || 'sv-SE';
    u1.rate = 0.9;

    const voices = window.speechSynthesis.getVoices();
    const targetLang = LANG_MAP[lang] || 'sv-SE';
    const voice = selectedVoice
        || voices.find(v => v.lang.startsWith(targetLang.substring(0, 2)))
        || voices.find(v => v.lang === targetLang);

    if (voice) u1.voice = voice;

    u1.onend = () => {
        if (generation !== speechGeneration) return;
        setTimeout(() => {
            if (generation !== speechGeneration) return;
            const u2 = new SpeechSynthesisUtterance(word);
            u2.lang = LANG_MAP[lang] || 'sv-SE';
            u2.rate = 0.85;
            if (voice) u2.voice = voice;
            window.speechSynthesis.speak(u2);
        }, 300);
    };

    speakAfterCancel(u1);
}

function stopSpeech() {
    speechGeneration += 1;
    if ('speechSynthesis' in window) window.speechSynthesis.cancel();
}
