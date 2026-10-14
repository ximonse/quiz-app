const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { test } = require('node:test');

const source = fs.readFileSync(require('node:path').join(__dirname, '../engine/browser-id.js'), 'utf8');
const uuid = '12345678-1234-4234-8234-123456789abc';

function loadBrowser({ stored = null, crypto = { randomUUID: () => uuid }, blocked = false } = {}) {
    let value = stored;
    const localStorage = {
        getItem: () => { if (blocked) throw new Error('blocked'); return value; },
        setItem: (_, next) => { if (blocked) throw new Error('blocked'); value = next; }
    };
    const window = { localStorage, crypto };
    vm.runInNewContext(source, { window, Uint8Array });
    return { window, stored: () => value };
}

test('keeps one browser ID across visits and replaces invalid saved values', () => {
    const browser = loadBrowser({ stored: 'invalid' });
    assert.equal(browser.window.getQuizBrowserId(), uuid);
    assert.equal(browser.stored(), uuid);
    assert.equal(browser.window.getQuizBrowserId(), uuid);
});

test('generates a valid UUID when randomUUID is unavailable', () => {
    const browser = loadBrowser({ crypto: { getRandomValues: bytes => bytes.fill(0) } });
    assert.match(browser.window.getQuizBrowserId(), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}$/);
});

test('continues without an ID when browser storage is unavailable', () => {
    const browser = loadBrowser({ blocked: true });
    assert.equal(browser.window.getQuizBrowserId(), null);
});
