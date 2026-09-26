/**
 * Hàng đợi ghi khi mất mạng.
 * - Service worker giữ yêu cầu POST/PUT/PATCH thất bại vì mất mạng, KHÔNG lưu token.
 * - Mỗi mục gắn userId (ứng dụng báo cho service worker lúc đăng nhập) để đăng xuất xoá đúng người.
 * - Khi có mạng, ứng dụng tự gửi lại bằng token hiện tại, chỉ các mục của người đang đăng nhập.
 */
import { API_PREFIX, getToken, getUserIdFromToken, postMessageToSw } from './api.js';

export async function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    const base = (document.querySelector('meta[name="app-base-url"]')?.content || '').replace(/\/$/, '');
    try {
        await navigator.serviceWorker.register(`${base}/sw.js`, { scope: `${base}/` });
        await navigator.serviceWorker.ready;
        const userId = getUserIdFromToken();
        if (userId) await postMessageToSw('set-user', { userId });
    } catch (error) {
        console.warn('Không đăng ký được service worker', error);
    }
}

export async function announceUser(userId) {
    await postMessageToSw('set-user', { userId });
}

/** Gửi lại các thao tác đã xếp hàng; trả về số thao tác gửi thành công. */
export async function syncOfflineQueue() {
    const userId = getUserIdFromToken();
    const token = getToken();
    if (!userId || !token) return 0;

    const reply = await postMessageToSw('get-queue', { userId });
    const records = reply?.records || [];
    let sent = 0;
    for (const record of records) {
        try {
            const response = await fetch(record.url, {
                method: record.method,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${token}`,
                    ...(record.tenantId ? { 'X-Tenant-ID': record.tenantId } : {}),
                },
                body: record.body,
            });
            // Thành công, hoặc lỗi dữ liệu (4xx) không thể gửi lại được nữa → bỏ khỏi hàng đợi.
            if (response.ok || (response.status >= 400 && response.status < 500)) {
                await postMessageToSw('delete-item', { id: record.id });
                if (response.ok) sent += 1;
            }
        } catch (_e) {
            break; // vẫn mất mạng — giữ lại để lần sau
        }
    }
    return sent;
}

export const isApiUrl = (url) => url.startsWith(API_PREFIX);
