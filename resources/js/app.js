/**
 * Sổ Sức Khỏe — điểm vào giao diện (SPA, hash router, JS thuần).
 * Mọi dữ liệu lấy từ API /api/v1; không có dữ liệu bệnh nhân nào viết cứng.
 */
import { api, getToken, setSession, clearSession, getUserIdFromToken, postMessageToSw } from './core/api.js';
import { createStore } from './core/store.js';
import { createRouter } from './core/router.js';
import { todayVN } from './core/format.js';
import { openSheet, closeSheet, toast, hero, tabbar } from './ui/shell.js';
import { esc, delegate } from './ui/dom.js';
import { icon } from './ui/icons.js';
import { syncOfflineQueue, registerServiceWorker } from './core/offline.js';
import { FEATURES } from './core/features.js';

import { renderLogin, renderStart, renderTwoFactor, renderTenants, renderPatients } from './views/auth.js';
import { renderLanding } from './views/landing.js';
import { renderShared } from './views/shared.js';
import { renderUpload, renderReview } from './views/upload.js';
import { renderMe } from './views/me.js';
import { renderToday, openQuickReading, meterCamInput } from './views/today.js';
import { renderCalendar } from './views/calendar.js';
import { renderPlan } from './views/plan.js';
import { renderRecords } from './views/records.js';
import { renderAsk } from './views/ask.js';
import { renderSettings, renderThresholds } from './views/settings.js';
import { renderOnboarding } from './views/onboarding.js';
import { renderRxNew, renderRxScan } from './views/rx.js';
import { renderDoctor } from './views/doctor.js';

/* ---------------- Trạng thái ---------------- */
const saved = (key) => { try { return JSON.parse(sessionStorage.getItem(key) || 'null'); } catch { return null; } };
const store = createStore({
    tenant: saved('health_tenant'),
    patient: saved('health_patient'),
    date: todayVN(),
    online: navigator.onLine,
});
window.__store = store;
store.subscribe('tenant', (t) => sessionStorage.setItem('health_tenant', JSON.stringify(t)));
store.subscribe('patient', (p) => sessionStorage.setItem('health_patient', JSON.stringify(p)));

const root = document.getElementById('app');

/* ---------------- Ngữ cảnh truyền cho từng màn ---------------- */
const ctx = {
    store,
    api,
    root,
    get patient() { return store.get('patient'); },
    get tenant() { return store.get('tenant'); },
    get isDoctor() { return store.get('patient')?.access_role === 'doctor'; },
    go: (path) => router.navigate(path),
    /** Vẽ một màn: `body` nằm trong .wrap, kèm đầu trang và thanh tab. */
    render({ title, sub = '', body = '', tab = null, back = null, wide = false }) {
        root.className = wide ? 'app wide' : 'app';
        document.title = `${title} · Sổ Sức Khỏe`;
        root.innerHTML = `${hero({ title, sub, patient: store.get('patient'), back, online: store.get('online') })}
            <main class="wrap" id="screen">${body}</main>
            ${tab !== false ? tabbar(tab) : ''}`;
        return root.querySelector('#screen');
    },
    refresh: () => router.resolve(),
    choosePatient,
    quickReading: () => openQuickReading(ctx),
    logout,
};

/* ---------------- Điều hướng + chặn quyền ---------------- */
const needsAuth = (fn, { patient = true } = {}) => async (params) => {
    if (!getToken()) return router.navigate('/login');
    if (!store.get('tenant')) return router.navigate('/tenants');
    if (patient && !store.get('patient')) return router.navigate('/patients');
    window.scrollTo(0, 0);
    try {
        await fn(ctx, params);
    } catch (error) {
        console.error(error);
        toast(error.message || 'Không tải được dữ liệu.', 'bad');
    }
};

const router = createRouter({
    '/': () => renderLanding(ctx),
    '/start': () => (getToken() ? router.navigate('/me') : renderStart(ctx)),
    '/login': () => (getToken() ? router.navigate('/today') : renderLogin(ctx)),
    // Link chia sẻ hồ sơ: mở được khi chưa đăng nhập (dược sĩ, bác sĩ, người thân).
    '/s/:token': (params) => renderShared(ctx, params),
    '/me': needsAuth(renderMe, { patient: false }),
    '/upload': needsAuth(renderUpload),
    '/review': needsAuth(renderReview),
    '/2fa': () => renderTwoFactor(ctx),
    '/tenants': () => (getToken() ? renderTenants(ctx) : router.navigate('/login')),
    '/patients': () => (getToken() && store.get('tenant') ? renderPatients(ctx) : router.navigate('/tenants')),
    '/onboarding': () => (getToken() ? renderOnboarding(ctx, {}) : router.navigate('/login')),
    '/onboarding/:step': (params) => (getToken() ? renderOnboarding(ctx, params) : router.navigate('/login')),
    '/today': needsAuth(renderToday),
    '/today/:date': needsAuth(renderToday),
    '/calendar': needsAuth(renderCalendar),
    '/plan': needsAuth(renderPlan),
    '/records': needsAuth(renderRecords),
    '/ask': FEATURES.doctor ? needsAuth(renderAsk) : () => router.navigate('/today'),
    '/settings': needsAuth(renderSettings),
    '/settings/thresholds': needsAuth(renderThresholds),
    '/rx/new': needsAuth(renderRxNew),
    '/rx/scan': needsAuth(renderRxScan),
    '/doctor': FEATURES.doctor ? needsAuth(renderDoctor, { patient: false }) : () => router.navigate('/today'),
    '*': () => {
        if (!getToken()) return router.navigate('/');
        if (!store.get('tenant')) return router.navigate('/tenants');
        if (!store.get('patient')) return router.navigate('/patients');
        return router.navigate(FEATURES.doctor && store.get('patient').access_role === 'doctor' ? '/doctor' : '/today');
    },
});
ctx.router = router;

/* ---------------- Đổi người bệnh ---------------- */
async function choosePatient() {
    const sheet = openSheet('<h3>Người bệnh</h3><div id="pt-list"><div class="skeleton tall"></div></div>');
    try {
        const { data } = await api('/patients');
        const current = store.get('patient')?.id;
        sheet.querySelector('#pt-list').innerHTML = `${data.map((p) => `
            <button class="choice" data-act="pick" data-id="${esc(p.id)}">
                <span class="avatar">${esc((p.full_name || '?').replace(/^(Bà|Ông)\s+/, '').charAt(0))}</span>
                <span class="grow"><b>${esc(p.full_name)}</b><br><span class="small muted">${p.birth_year ? `Sinh ${p.birth_year} · ` : ''}${roleLabel(p.access_role)}</span></span>
                ${p.id === current ? '<span class="chip">Đang xem</span>' : ''}
            </button>`).join('')}
            <button class="btn ghost block" data-act="new">+ Tạo hồ sơ người bệnh mới</button>
            <button class="btn ghost block" style="margin-top:8px" data-act="tenants">Đổi không gian chăm sóc</button>`;
        delegate(sheet, {
            pick: (el) => {
                const p = data.find((x) => x.id === el.dataset.id);
                store.set('patient', p);
                closeSheet();
                router.navigate(p.access_role === 'doctor' ? '/doctor' : '/today');
                router.resolve();
            },
            new: () => { closeSheet(); router.navigate('/onboarding/1'); },
            tenants: () => { closeSheet(); store.set('patient', null); router.navigate('/tenants'); },
        });
    } catch (error) {
        sheet.querySelector('#pt-list').innerHTML = `<div class="error-box">${esc(error.message)}</div>`;
    }
}

export function roleLabel(role) {
    return { caregiver: 'Người chăm sóc', patient: 'Người bệnh', doctor: 'Bác sĩ', viewer: 'Người xem' }[role] || 'Thành viên';
}

/* ---------------- Đăng xuất: xoá phiên + hàng đợi ngoại tuyến của người dùng ---------------- */
async function logout(callApi = true) {
    const userId = getUserIdFromToken();
    if (callApi && getToken()) api('/auth/logout', { method: 'POST' }).catch(() => {});
    try { await postMessageToSw('clear-queue', { userId }); } catch (_e) { /* không có service worker */ }
    clearSession();
    store.batch({ tenant: null, patient: null });
    router.navigate('/');
}

/* ---------------- Nút + giữa thanh tab: tải ảnh hoặc ghi chỉ số ---------------- */
function openFabMenu() {
    const sheet = openSheet(`<div id="fab-menu"><h3>Bạn muốn làm gì?</h3>
        <label class="choice">${meterCamInput('data-fab-meter')}<span class="avatar">${icon('camera', { size: 20 })}</span><span class="grow"><b>Chụp máy đo</b><br><span class="small muted">Máy đường huyết, máy huyết áp — app tự đọc số</span></span></label>
        <button class="choice" data-act="reading"><span class="avatar">${icon('droplet', { size: 20 })}</span><span class="grow"><b>Gõ số đo</b><br><span class="small muted">Đường huyết VD 6,5 — hoặc huyết áp</span></span></button>
        <button class="choice" data-act="upload"><span class="avatar">${icon('file', { size: 20 })}</span><span class="grow"><b>Tải ảnh khám bệnh</b><br><span class="small muted">Đơn thuốc, xét nghiệm, giấy khám — AI đọc giúp</span></span></button></div>`);
    sheet.querySelector('[data-fab-meter]').addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (!file) return;
        closeSheet();
        openQuickReading(ctx, { file });
    });
    delegate(sheet.querySelector('#fab-menu'), {
        upload: () => { closeSheet(); router.navigate('/upload'); },
        reading: () => { closeSheet(); openQuickReading(ctx); },
    });
}
ctx.setSession = setSession;

/* ---------------- Sự kiện toàn cục ---------------- */
delegate(document.body, {
    nav: (el) => router.navigate(el.dataset.to),
    'choose-patient': () => { if (FEATURES.caregiver) choosePatient(); },
    'quick-reading': () => openQuickReading(ctx),
    fab: () => openFabMenu(),
});
// Đổi màn thì đóng bảng trượt đang mở (không để bảng nằm đè lên màn mới).
window.addEventListener('hashchange', () => closeSheet());
window.addEventListener('api:unauthorized', () => logout(false));
window.addEventListener('api:requires2fa', () => router.navigate('/2fa'));
const setOnline = (value) => {
    if (store.get('online') === value) return;
    store.set('online', value);
    document.querySelectorAll('.hero .pill').forEach((pill) => {
        pill.classList.toggle('off', !value);
        pill.innerHTML = `<i></i>${value ? 'Đã đồng bộ' : 'Ngoại tuyến — sẽ gửi khi có mạng'}`;
    });
};
window.addEventListener('api:offline', () => setOnline(false));
window.addEventListener('api:online', () => setOnline(true));
window.addEventListener('offline', () => setOnline(false));
window.addEventListener('online', async () => {
    setOnline(true);
    const sent = await syncOfflineQueue();
    if (sent > 0) { toast(`Đã gửi ${sent} thao tác lưu khi mất mạng.`); router.resolve(); }
});

/* ---------------- Khởi động ---------------- */
try {
    const font = localStorage.getItem('sokhoe-font');
    if (font) document.documentElement.dataset.font = font;
    const theme = localStorage.getItem('sokhoe-theme');
    if (theme) document.documentElement.dataset.theme = theme;
} catch (_e) { /* trình duyệt chặn localStorage */ }

registerServiceWorker();
router.resolve();
if (getToken() && navigator.onLine) syncOfflineQueue().then((sent) => { if (sent > 0) router.resolve(); });
