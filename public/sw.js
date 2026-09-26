/*
 * Service worker Sổ Sức Khỏe.
 * - Cache vỏ ứng dụng (file tĩnh). KHÔNG cache bất kỳ phản hồi API nào (dữ liệu sức khỏe).
 * - Yêu cầu ghi (POST/PUT/PATCH/DELETE) thất bại vì mất mạng được xếp hàng trong IndexedDB,
 *   bỏ header Authorization (không lưu token), gắn userId do ứng dụng báo qua postMessage.
 * - Ứng dụng tự gửi lại hàng đợi bằng token hiện tại (core/offline.js).
 */
const CACHE = 'sokhoe-shell-v3';
const DB_NAME = 'health-offline';
const DB_VERSION = 3;
const STORE_NAME = 'write-queue';

// Lấy đường dẫn gốc từ scope để chạy được cả ở thư mục con lẫn tên miền riêng.
const BASE_PATH = (typeof self !== 'undefined' && self.registration && self.registration.scope)
    ? new URL(self.registration.scope).pathname.replace(/\/$/, '')
    : '';
const API_PREFIX = BASE_PATH + '/api/';
const SHELL = [BASE_PATH + '/', BASE_PATH + '/manifest.webmanifest', BASE_PATH + '/favicon.ico'];

let currentUserId = null;

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (db.objectStoreNames.contains(STORE_NAME)) db.deleteObjectStore(STORE_NAME);
            db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
        };
        req.onsuccess = (e) => resolve(e.target.result);
        req.onerror = (e) => reject(e);
    });
}

async function withStore(mode, fn) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, mode);
        const result = fn(tx.objectStore(STORE_NAME));
        tx.oncomplete = () => resolve(result && 'result' in result ? result.result : undefined);
        tx.onerror = () => reject(tx.error);
    });
}

const queueRequest = (record) => withStore('readwrite', (store) => store.add(record));
const allRecords = () => withStore('readonly', (store) => store.getAll());
const deleteRecord = (id) => withStore('readwrite', (store) => store.delete(id));

async function clearQueueForUser(userId) {
    const records = await allRecords();
    // Không biết người dùng (dữ liệu cũ) cũng xoá khi đăng xuất để không lưu lại trên máy dùng chung.
    const toDelete = records.filter((r) => r.userId === userId || !r.userId).map((r) => r.id);
    await withStore('readwrite', (store) => { toDelete.forEach((id) => store.delete(id)); });
}

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)));
});
self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys()
        .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
        .then(() => self.clients.claim()));
});

self.addEventListener('message', (event) => {
    const { action } = event.data || {};
    const reply = (data) => event.ports && event.ports[0] && event.ports[0].postMessage(data);

    if (action === 'set-user') {
        currentUserId = event.data.userId || null;
        reply({ ok: true });
    } else if (action === 'clear-queue') {
        const userId = event.data.userId || currentUserId;
        currentUserId = null;
        event.waitUntil(clearQueueForUser(userId).then(() => reply({ ok: true })));
    } else if (action === 'get-queue') {
        event.waitUntil(allRecords().then((records) => reply({
            records: records.filter((r) => r.userId && r.userId === event.data.userId),
        })));
    } else if (action === 'delete-item') {
        event.waitUntil(deleteRecord(event.data.id).then(() => reply({ ok: true })));
    }
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    const isApi = url.pathname.startsWith(API_PREFIX);

    if (isApi && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(event.request.method)) {
        event.respondWith(
            fetch(event.request.clone()).catch(async () => {
                const body = await event.request.clone().text().catch(() => null);
                await queueRequest({
                    url: event.request.url,
                    method: event.request.method,
                    body,
                    tenantId: event.request.headers.get('X-Tenant-ID'),
                    userId: currentUserId,
                    createdAt: Date.now(),
                });
                return new Response(JSON.stringify({ queued: true }), { status: 202, headers: { 'Content-Type': 'application/json' } });
            }),
        );
        return;
    }

    // Không cache API (dữ liệu sức khỏe) và các yêu cầu không phải GET.
    if (event.request.method !== 'GET' || isApi || url.origin !== self.location.origin) return;

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                if (response.ok && (url.pathname.startsWith(BASE_PATH + '/build/') || SHELL.includes(url.pathname))) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(event.request, copy));
                }
                return response;
            })
            .catch(() => caches.match(event.request).then((cached) => cached || caches.match(BASE_PATH + '/'))),
    );
});
