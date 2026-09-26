/**
 * Client API — base URL từ thẻ meta, token trong sessionStorage, tự gắn X-Tenant-ID.
 * Không bao giờ hiện nguyên văn lỗi máy chủ cho người dùng (REVIEW R4):
 * lỗi kiểm tra dữ liệu (422) hiện câu đầu tiên, còn lại hiện câu thân thiện.
 */
const BASE_URL = (document.querySelector('meta[name="app-base-url"]')?.content || '').replace(/\/$/, '');
export const API_PREFIX = `${BASE_URL}/api/v1`;
export const APP_ENV = document.querySelector('meta[name="app-env"]')?.content || 'production';

const TOKEN_KEY = 'health_token';
const USER_KEY = 'health_user_id';

export function getToken() {
    return sessionStorage.getItem(TOKEN_KEY);
}

export function setSession(token, userId) {
    sessionStorage.setItem(TOKEN_KEY, token);
    if (userId) sessionStorage.setItem(USER_KEY, userId);
}

export function clearSession() {
    sessionStorage.removeItem(TOKEN_KEY);
    sessionStorage.removeItem(USER_KEY);
    sessionStorage.removeItem('health_tenant');
    sessionStorage.removeItem('health_patient');
}

/**
 * Mã người dùng của phiên hiện tại. Token Sanctum ("id|chuỗi") không phải JWT nên
 * không giải mã được — mã người dùng được lưu từ phản hồi đăng nhập.
 */
export function getUserIdFromToken() {
    return sessionStorage.getItem(USER_KEY);
}

export class ApiError extends Error {
    constructor(message, status, payload = {}) {
        super(message);
        this.status = status;
        this.payload = payload;
        this.code = payload.code || payload.error || null;
        this.errors = payload.errors || null;
    }
}

function headers(extra = {}, json = true) {
    const h = { Accept: 'application/json', ...extra };
    if (json) h['Content-Type'] = 'application/json';
    const token = getToken();
    if (token) h.Authorization = `Bearer ${token}`;
    const tenantId = window.__store?.get('tenant')?.id;
    if (tenantId && !h['X-Tenant-ID']) h['X-Tenant-ID'] = tenantId;
    return h;
}

function friendly(status, payload) {
    if (status === 422 && payload.errors) return Object.values(payload.errors).flat()[0];
    if (status === 422 && payload.message && !/exception|sql/i.test(payload.message)) return payload.message;
    if (status === 400 && payload.message) return payload.message;
    if (status === 403) return 'Bạn không có quyền thực hiện thao tác này.';
    if (status === 404) return 'Không tìm thấy dữ liệu.';
    if (status === 429) return 'Thao tác quá nhanh. Vui lòng thử lại sau ít phút.';
    return 'Không tải được dữ liệu. Vui lòng thử lại.';
}

export async function api(path, options = {}) {
    const { body, headers: extraHeaders, ...rest } = options;
    const init = { ...rest, headers: headers(extraHeaders) };
    if (body !== undefined) init.body = typeof body === 'string' ? body : JSON.stringify(body);

    let response;
    try {
        response = await fetch(`${API_PREFIX}${path}`, init);
    } catch (networkError) {
        window.dispatchEvent(new CustomEvent('api:offline'));
        throw new ApiError('Mất kết nối mạng. Thao tác sẽ được gửi lại khi có mạng.', 0);
    }

    const payload = await response.json().catch(() => ({}));

    if (response.status === 202 && payload.queued) {
        window.dispatchEvent(new CustomEvent('api:offline'));
        return payload;
    }
    if (response.status === 401) {
        window.dispatchEvent(new CustomEvent('api:unauthorized'));
        throw new ApiError('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.', 401, payload);
    }
    if (response.status === 403 && /TWO_FACTOR/.test(payload.code || '')) {
        window.dispatchEvent(new CustomEvent('api:requires2fa', { detail: payload.code }));
        throw new ApiError(payload.message || 'Cần xác thực hai lớp.', 403, payload);
    }
    if (!response.ok) {
        if (APP_ENV === 'local') console.warn('[API]', response.status, path, payload);
        throw new ApiError(friendly(response.status, payload), response.status, payload);
    }

    window.dispatchEvent(new CustomEvent('api:online'));
    return payload;
}

/** Tải file (ảnh phiếu, PDF, CSV) có kèm token — thẻ <img> không gửi được Authorization. */
export async function apiBlob(url) {
    const full = url.startsWith('http') ? url : `${API_PREFIX}${url}`;
    const response = await fetch(full, { headers: headers({}, false) });
    if (!response.ok) throw new ApiError(friendly(response.status, {}), response.status);
    return response.blob();
}

/** Gửi form có file (tải ảnh phiếu). */
export async function apiUpload(path, formData) {
    const response = await fetch(`${API_PREFIX}${path}`, { method: 'POST', headers: headers({}, false), body: formData });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new ApiError(friendly(response.status, payload), response.status, payload);
    return payload;
}

export async function postMessageToSw(action, payload = {}) {
    if (!('serviceWorker' in navigator)) return null;
    const registration = await navigator.serviceWorker.getRegistration();
    const worker = registration?.active;
    if (!worker) return null;

    return new Promise((resolve) => {
        const channel = new MessageChannel();
        const timer = setTimeout(() => resolve(null), 2000);
        channel.port1.onmessage = (event) => { clearTimeout(timer); resolve(event.data); };
        worker.postMessage({ action, ...payload }, [channel.port2]);
    });
}
