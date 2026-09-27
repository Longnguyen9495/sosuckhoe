import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

const url = process.argv[2] || 'http://sokhoe.local';
const output = process.argv[3] || 'docs/screenshots/test-family.png';
const width = process.argv[4] || '360';
const height = process.argv[5] || '800';
const theme = process.argv[6] || 'light';
const route = process.argv[7] || '/today';

const edge = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const port = 9335;
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
    await send('Emulation.setDeviceMetricsOverride', {
        width: Number(width),
        height: Number(height),
        deviceScaleFactor: 1,
        mobile: Number(width) <= 480,
    });
    await send('Emulation.setEmulatedMedia', {
        features: [{ name: 'prefers-color-scheme', value: theme }],
    });
    await send('Page.navigate', { url });
    await sleep(3000);

    // Authenticate via API inside the page
    const authResult = await send('Runtime.evaluate', {
        expression: `(async () => {
            const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
            const rawBase = document.querySelector('meta[name="app-base-url"]')?.content || '';
            const apiBase = rawBase.endsWith('/') ? rawBase.slice(0, -1) : rawBase;
            const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
            await fetch(apiBase + '/api/v1/auth/otp/request', {
                method: 'POST', headers,
                body: JSON.stringify({ phone: '0900000001' })
            });
            await sleep(300);
            const res = await fetch(apiBase + '/api/v1/auth/otp/verify', {
                method: 'POST', headers,
                body: JSON.stringify({ phone: '0900000001', code: '123456', device_name: 'test' })
            });
            const payload = await res.json();
            if (!payload.data?.token) throw new Error('Auth failed: ' + JSON.stringify(payload));
            sessionStorage.setItem('health_token', payload.data.token);
            sessionStorage.setItem('health_user_id', payload.data.user_id);
            // get tenants
            const tRes = await fetch(apiBase + '/api/v1/tenants', { headers: { ...headers, 'Authorization': 'Bearer ' + payload.data.token } });
            const tData = await tRes.json();
            if (!tData.data?.[0]) throw new Error('No tenant');
            sessionStorage.setItem('health_tenant', JSON.stringify(tData.data[0]));
            // get patients
            const pRes = await fetch(apiBase + '/api/v1/patients', { headers: { ...headers, 'Authorization': 'Bearer ' + payload.data.token, 'X-Tenant-ID': tData.data[0].id } });
            const pData = await pRes.json();
            if (!pData.data?.[0]) throw new Error('No patient');
            sessionStorage.setItem('health_patient', JSON.stringify(pData.data[0]));
            location.hash = ${JSON.stringify(route)};
            await sleep(3500);
            return { hash: location.hash, patient: pData.data[0].full_name, title: document.title };
        })()`,
        awaitPromise: true,
        returnByValue: true,
    });
    await send('Page.navigate', { url: url + '/#' + route });
    await sleep(3500);
    if (authResult.exceptionDetails) {
        throw new Error(authResult.exceptionDetails.exception?.description || authResult.exceptionDetails.text);
    }
    console.log('Auth result:', authResult.result.value);

    await send('Runtime.evaluate', {
        expression: `document.documentElement.dataset.theme = ${JSON.stringify(theme)}; document.activeElement?.blur();`,
    });
    await sleep(500);

    const screenshot = await send('Page.captureScreenshot', {
        format: 'png',
        fromSurface: true,
        captureBeyondViewport: false,
    });
    await import('node:fs/promises').then(({ mkdir, writeFile }) => mkdir(path.dirname(path.resolve(output)), { recursive: true }).then(() => writeFile(output, Buffer.from(screenshot.data, 'base64'))));
    socket.close();
    console.log(`Đã chụp ${output} (${width}x${height}, ${theme}).`);
} finally {
    browser.kill();
    await new Promise((resolve) => browser.once('exit', resolve));
    await rm(profile, { recursive: true, force: true });
}
