// engine/tts.js — Text-to-speech for all quiz types

const LANG_MAP = {
    'sv': 'sv-SE', 'en': 'en-US', 'es': 'es-ES',
    'fr': 'fr-FR', 'de': 'de-DE', 'uk': 'uk'
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

function speakText(text, lang) {
    if (!('speechSynthesis' in window) || ttsIsMuted()) return;
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
    window.speechSynthesis.speak(utterance);
}

function speakGlossary(sentence, word, lang) {
    if (!('speechSynthesis' in window) || ttsIsMuted()) return;
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

    window.speechSynthesis.speak(u1);
}

function stopSpeech() {
    speechGeneration += 1;
    if ('speechSynthesis' in window) window.speechSynthesis.cancel();
}
