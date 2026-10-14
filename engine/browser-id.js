(function (global) {
    const storageKey = 'quiz-browser-id-v1';
    const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

    function getQuizBrowserId() {
        try {
            const saved = global.localStorage.getItem(storageKey);
            if (saved && uuidPattern.test(saved)) return saved.toLowerCase();
            if (!global.crypto) return null;
            let id;
            if (typeof global.crypto.randomUUID === 'function') {
                id = global.crypto.randomUUID();
            } else if (typeof global.crypto.getRandomValues === 'function') {
                const bytes = global.crypto.getRandomValues(new Uint8Array(16));
                bytes[6] = (bytes[6] & 0x0f) | 0x40;
                bytes[8] = (bytes[8] & 0x3f) | 0x80;
                const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
                id = [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
            } else {
                return null;
            }
            global.localStorage.setItem(storageKey, id);
            return id;
        } catch (error) {
            // Quiz results still work when storage is disabled.
            return null;
        }
    }

    global.getQuizBrowserId = getQuizBrowserId;
})(window);
