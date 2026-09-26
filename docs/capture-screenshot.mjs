import { spawn } from 'node:child_process';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

const [url, output, width = '360', height = '800', theme = 'light', mode = '', route = '/today'] = process.argv.slice(2);
if (!url || !output) {
    console.error('Usage: node docs/capture-screenshot.mjs <url> <output> [width] [height] [light|dark] [debug|family] [route]');
    process.exit(1);
}

const edge = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const port = 9333;
const profile = await mkdtemp(path.join(tmpdir(), 'sosuckhoe-edge-'));
const tokenCache = path.join(tmpdir(), 'sosuckhoe-screenshot-token.txt');
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
        } catch {
            // Trình duyệt đang khởi động.
        }
        await sleep(100);
    }
    if (!target) throw new Error('Không kết nối được Chrome DevTools Protocol.');

    const socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.addEventListener('open', resolve, { once: true });
        socket.addEventListener('error', reject, { once: true });
    });

    let sequence = 0;
    const pending = new Map();
    socket.addEventListener('message', ({ data }) => {
        const message = JSON.parse(data);
        if (!message.id || !pending.has(message.id)) return;
        const { resolve, reject } = pending.get(message.id);
        pending.delete(message.id);
        if (message.error) reject(new Error(message.error.message));
        else resolve(message.result);
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

    if (mode === 'family') {
        await send('Runtime.evaluate', {
            expression: `sessionStorage.clear(); localStorage.clear(); location.hash = '/login';`,
        });
        await sleep(750);

        let cachedToken = '';
        try {
            cachedToken = (await readFile(tokenCache, 'utf8')).trim();
        } catch {
            // Phiên chụp đầu tiên sẽ xác thực bằng OTP thật.
        }

        const automate = await send('Runtime.evaluate', {
            expression: `(async () => {
                const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
                const waitFor = async (selector, timeout = 10000) => {
                    const started = Date.now();
                    while (Date.now() - started < timeout) {
                        const element = document.querySelector(selector);
                        if (element && !element.hidden) return element;
                        await sleep(100);
                    }
                    const diagnostics = {
                        selector,
                        hash: location.hash,
                        hasToken: Boolean(sessionStorage.getItem('health_token')),
                        toast: document.querySelector('.toast')?.textContent?.trim() || null,
                        screen: document.querySelector('#app')?.textContent?.trim().slice(0, 500) || null,
                    };
                    throw new Error('Không tìm thấy phần tử: ' + JSON.stringify(diagnostics));
                };
                const rawBase = document.querySelector('meta[name="app-base-url"]')?.content || '';
                const apiBase = rawBase.endsWith('/') ? rawBase.slice(0, -1) : rawBase;
                const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
                let payload = ${JSON.stringify(cachedToken)}
                    ? { data: { token: ${JSON.stringify(cachedToken)} } }
                    : null;
                for (let attempt = 1; !payload?.data?.token && attempt <= 5; attempt += 1) {
                    const requested = await fetch(apiBase + '/api/v1/auth/otp/request', {
                        method: 'POST', headers, body: JSON.stringify({ phone: '0900000001' }),
                    });
                    if (!requested.ok) {
                        throw new Error('Không gửi được OTP: ' + await requested.text());
                    }
                    const verified = await fetch(apiBase + '/api/v1/auth/otp/verify', {
                        method: 'POST', headers,
                        body: JSON.stringify({ phone: '0900000001', code: '123456', device_name: 'Ảnh kiểm thử' }),
                    });
                    payload = await verified.json();
                    if (verified.ok && payload.data?.token) break;
                    await sleep(attempt * 150);
                }
                if (!payload?.data?.token) {
                    throw new Error('Không xác thực được phiên chụp sau 5 lần: ' + JSON.stringify(payload));
                }
                sessionStorage.setItem('health_token', payload.data.token);
                location.hash = '/tenants';
                const tenant = await waitFor('#tenant-list .choice-card');
                tenant.click();
                const patient = await waitFor('#patient-list .choice-card');
                patient.click();
                await waitFor('#today-screen');
                location.hash = ${JSON.stringify(route)};
                await sleep(2500);
                return {
                    hash: location.hash,
                    patient: window.__store?.get('patient')?.full_name,
                    token: payload.data.token,
                };
            })()`,
            awaitPromise: true,
            returnByValue: true,
        });
        if (automate.exceptionDetails) {
            throw new Error(automate.exceptionDetails.exception?.description || automate.exceptionDetails.text);
        }
        const { token, ...summary } = automate.result.value;
        if (!cachedToken && token) {
            await writeFile(tokenCache, token, { encoding: 'utf8', mode: 0o600 });
        }
        console.log(`Đã xác thực gia đình: ${JSON.stringify(summary)}.`);
    }

    await send('Runtime.evaluate', {
        expression: `document.documentElement.dataset.theme = ${JSON.stringify(theme)}; document.activeElement?.blur();`,
    });
    await sleep(250);

    if (mode === 'debug') {
        const inspection = await send('Runtime.evaluate', {
            expression: `JSON.stringify([[180, 412], [30, 380]].map(([x, y]) => ({
                point: [x, y],
                elements: document.elementsFromPoint(x, y).map((element) => {
                    const rect = element.getBoundingClientRect();
                    return {
                        tag: element.tagName,
                        id: element.id,
                        className: element.className,
                        text: element.textContent?.trim().slice(0, 80),
                        rect: [rect.x, rect.y, rect.width, rect.height],
                        position: getComputedStyle(element).position,
                        zIndex: getComputedStyle(element).zIndex,
                    };
                }),
            })))`,
            returnByValue: true,
        });
        console.log(inspection.result.value);
    }

    const screenshot = await send('Page.captureScreenshot', {
        format: 'png',
        fromSurface: true,
        captureBeyondViewport: false,
    });
    await mkdir(path.dirname(path.resolve(output)), { recursive: true });
    await writeFile(output, Buffer.from(screenshot.data, 'base64'));
    socket.close();
    console.log(`Đã chụp ${output} (${width}x${height}, ${theme}).`);
} finally {
    browser.kill();
    await new Promise((resolve) => browser.once('exit', resolve));
    await rm(profile, { recursive: true, force: true });
}
