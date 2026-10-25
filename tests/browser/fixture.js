// Disposable copy of the app with synthetic data (seed.php) behind PHP's built-in server.
// Never reads local accounts, quizzes or config.local.php.
const fs = require('fs'), path = require('path'), os = require('os'), net = require('net');
const {execFileSync, spawn} = require('child_process');

const root = path.resolve(__dirname, '../..');
const shots = path.join(__dirname, 'screenshots');

function shot(name) {
    fs.mkdirSync(shots, {recursive: true});
    return path.join(shots, name);
}

function freePort() {
    return new Promise(resolve => {
        const server = net.createServer().listen(0, '127.0.0.1', () => { const {port} = server.address(); server.close(() => resolve(port)); });
    });
}

function startFixture() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'quiz-browser-'));
    for (const d of ['admin', 'ai', 'api', 'assets', 'engine', 'includes', 'play', 'q', 'student']) fs.cpSync(path.join(root, d), path.join(dir, d), {recursive: true});
    for (const f of ['config.php', 'index.php']) fs.copyFileSync(path.join(root, f), path.join(dir, f));
    fs.mkdirSync(path.join(dir, 'data'), {recursive: true}); fs.mkdirSync(path.join(dir, 'sessions'));
    fs.copyFileSync(path.join(__dirname, 'seed.php'), path.join(dir, 'seed.php'));
    execFileSync('php', [path.join(dir, 'seed.php')]);
    let server = null;
    const fx = {
        dir, root, base: null,
        async ready() {
            const port = await freePort();
            fx.base = `http://127.0.0.1:${port}`;
            server = spawn('php', ['-d', 'session.save_path=' + path.join(dir, 'sessions'), '-S', `127.0.0.1:${port}`, '-t', dir], {cwd: dir, stdio: 'ignore'});
            for (let i = 0; i < 100; i++) {
                try { if ((await fetch(fx.base + '/student/')).ok) return fx; } catch {}
                await new Promise(r => setTimeout(r, 100));
            }
            throw new Error('PHP server did not start');
        },
        stop() {
            if (server) server.kill();
            setTimeout(() => { try { fs.rmSync(dir, {recursive: true, force: true}); } catch {} }, 300);
        }
    };
    return fx;
}

module.exports = {startFixture, shot, root};
