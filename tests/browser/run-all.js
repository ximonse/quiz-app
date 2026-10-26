// Runs every browser check one after another; exits non-zero if any fails.
const {spawnSync} = require('child_process');
const path = require('path');

const runs = [
    ['sort-player.js'], ['order-player.js'], ['sort-editor.js'],
    ['hold-speak-drag.js', 'mouse'], ['hold-speak-drag.js', 'touch'], ['hold-speak.js'],
    ['pupil-robots.js'], ['result-save.js']
];
let failed = 0;
for (const [file, ...args] of runs) {
    const label = [file, ...args].join(' ');
    const result = spawnSync(process.execPath, [path.join(__dirname, file), ...args], {encoding: 'utf-8', timeout: 300000});
    const lines = (result.stdout + result.stderr).trim().split('\n');
    const ok = result.status === 0 && lines.some(l => l.startsWith('PASS'));
    if (!ok) failed++;
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${label}: ${ok ? lines.find(l => l.startsWith('PASS')) : lines.slice(-5).join('\n      ')}`);
}
console.log(failed ? `${failed} of ${runs.length} browser runs failed` : `PASS: all ${runs.length} browser runs`);
process.exitCode = failed ? 1 : 0;
