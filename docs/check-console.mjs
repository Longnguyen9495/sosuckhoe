import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

const url = process.argv[2] || 'http://sokhoe.local';
const edge = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const port = 9334;
const profile = await mkdtemp(path.join(tmpdir(), 'sosuckhoe-check-'));
const browser = spawn(edge, [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--disable-default-apps',
    '--disable-extensions',
    `--remote-debugging-port=${port}`,
    `--user-data-dir=${profile}`,
    'about:blank',
], { stdio: 'ignore' });

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

try {
    let target;
    for (let attempt = 0; attempt < 50; attempt += 1) {
        try {
            const targets = await fetch(`http://127.0.0.1:${port}/json`).then((response) => response.json());
            target = targets.find((item) => item.type === 'page');
            if (target) break;
        } catch {}
        await sleep(100);
    }
    if (!target) throw new Error('Không kết nối được CDP.');

    const socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.addEventListener('open', resolve, { once: true });
        socket.addEventListener('error', reject, { once: true });
    });

    let sequence = 0;
    const pending = new Map();
    socket.addEventListener('message', ({ data }) => {
        const message = JSON.parse(data);
        if (message.method === 'Runtime.consoleAPICalled') {
            const args = message.params.args.map((a) => a.value ?? a.description ?? JSON.stringify(a)).join(' ');
            console.log(`[console.${message.params.type}] ${args}`);
        }
        if (message.id && pending.has(message.id)) {
            const { resolve, reject } = pending.get(message.id);
            pending.delete(message.id);
            if (message.error) reject(new Error(message.error.message));
            else resolve(message.result);
        }
    });

    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence;
        pending.set(id, { resolve, reject });
        socket.send(JSON.stringify({ id, method, params }));
    });

    await send('Page.enable');
    await send('Runtime.enable');
    await send('Page.navigate', { url });
    await sleep(4000);

    // Also evaluate to trigger any hash-router navigation if needed
    await send('Runtime.evaluate', {
        expression: `sessionStorage.clear(); localStorage.clear(); location.hash = '/login';`,
    });
    await sleep(2000);

    const diagnostics = await send('Runtime.evaluate', {
        expression: `JSON.stringify({ hash: location.hash, html: document.body.innerHTML.slice(0, 2000), title: document.title })`,
        returnByValue: true,
    });
    console.log('Diagnostics:', diagnostics.result.value);

    socket.close();
} finally {
    browser.kill();
    await new Promise((resolve) => browser.once('exit', resolve));
    await rm(profile, { recursive: true, force: true });
}
