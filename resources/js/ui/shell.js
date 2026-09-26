/**
 * Khung ứng dụng (phong cách Snapask): đầu trang tím, thanh tab dưới đáy có nút + nổi,
 * bảng trượt từ dưới lên, hộp xác nhận, phóng ảnh, thông báo ngắn.
 */
import { esc } from './dom.js';

export const ICONS = {
    home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>',
    cal: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18"/></svg>',
    plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
    pill: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="8" width="18" height="8" rx="4" transform="rotate(-35 12 12)"/><path d="M9.2 8.1l5.6 7.8"/></svg>',
    doc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg>',
    chat: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>',
    moon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>',
    gear: '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>',
    user: '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
    back: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>',
};

/** Đầu trang tím. `patient` hiện nút chọn bệnh nhân; `back` thay bằng nút quay lại. */
export function hero({ title, sub = '', patient = null, back = null, online = true, badge = false }) {
    const initial = (patient?.full_name || '?').replace(/^(Bà|Ông|Cô|Chú|Anh|Chị)\s+/i, '').trim().charAt(0).toUpperCase() || '?';
    const left = back
        ? `<button class="icon-btn" data-act="nav" data-to="${esc(back)}" aria-label="Quay lại">${ICONS.back}</button><div class="who grow"><b>${esc(title)}</b></div>`
        : `<button class="who-btn" data-act="choose-patient" aria-label="Đổi người bệnh">
               <span class="avatar">${esc(initial)}</span>
               <span class="who"><b>${esc(patient?.full_name || 'Sổ Sức Khỏe')} ▾</b><small>${patient?.birth_year ? `${new Date().getFullYear() - patient.birth_year} tuổi · ` : ''}Chạm để đổi người bệnh</small></span>
           </button>`;
    return `<header class="hero">
        <div class="topbar">
            ${left}
            <button class="icon-btn" data-act="nav" data-to="/ask" aria-label="Hỏi bác sĩ" title="Hỏi bác sĩ">${ICONS.chat}${badge ? '<span class="dot"></span>' : ''}</button>
            <button class="icon-btn" data-act="nav" data-to="/me" aria-label="Thông tin cá nhân" title="Thông tin cá nhân">${ICONS.user}</button>
            <button class="icon-btn" data-act="nav" data-to="/settings" aria-label="Cài đặt" title="Cài đặt">${ICONS.gear}</button>
        </div>
        ${back ? '' : `<h1>${esc(title)}</h1>`}
        ${sub ? `<p class="sub">${esc(sub)}</p>` : ''}
        <span class="pill ${online ? '' : 'off'}"><i></i>${online ? 'Đã đồng bộ' : 'Ngoại tuyến — sẽ gửi khi có mạng'}</span>
    </header>`;
}

export function tabbar(active) {
    const tab = (key, to, icon, label) => `<a class="tab ${active === key ? 'on' : ''}" href="#${to}" aria-current="${active === key ? 'page' : 'false'}">${icon}${label}</a>`;
    return `<nav class="nav" aria-label="Điều hướng chính"><div class="nav-in">
        ${tab('today', '/today', ICONS.home, 'Hôm nay')}
        ${tab('calendar', '/calendar', ICONS.cal, 'Lịch')}
        <button class="fab" data-act="fab" aria-label="Tải ảnh hoặc ghi chỉ số">${ICONS.plus}</button>
        ${tab('plan', '/plan', ICONS.pill, 'Phác đồ')}
        ${tab('records', '/records', ICONS.doc, 'Hồ sơ')}
    </div></nav>`;
}

/* ---------- Lớp phủ dùng chung (tạo một lần) ---------- */
let layers = null;
function ensureLayers() {
    if (layers) return layers;
    const wrap = document.createElement('div');
    wrap.innerHTML = `
        <div class="sheet-overlay" hidden><div class="sheet" role="dialog" aria-modal="true"></div></div>
        <div class="confirm-overlay" hidden><div class="confirm-panel" role="alertdialog" aria-modal="true">
            <p class="confirm-message"></p>
            <div class="confirm-actions"><button type="button" class="btn ghost" data-v="0">Huỷ</button><button type="button" class="btn" data-v="1">Đồng ý</button></div>
        </div></div>
        <div class="lightbox" hidden><button type="button">Đóng ✕</button><img alt=""></div>
        <div class="toast" role="status" hidden></div>`;
    document.body.append(...wrap.children);
    layers = {
        sheetOverlay: document.querySelector('.sheet-overlay'),
        sheet: document.querySelector('.sheet'),
        confirm: document.querySelector('.confirm-overlay'),
        lightbox: document.querySelector('.lightbox'),
        toast: document.querySelector('.toast'),
    };
    layers.sheetOverlay.addEventListener('click', (e) => { if (e.target === layers.sheetOverlay) closeSheet(); });
    layers.lightbox.addEventListener('click', (e) => { if (e.target.tagName !== 'IMG') closeLightbox(); });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        closeSheet();
        closeLightbox();
    });
    return layers;
}

export function openSheet(innerHtml, onMount) {
    const l = ensureLayers();
    l.sheet.innerHTML = `<div class="grab"></div>${innerHtml}`;
    l.sheetOverlay.hidden = false;
    onMount?.(l.sheet);
    l.sheet.querySelector('input, select, textarea, button')?.focus();
    return l.sheet;
}

export function closeSheet() {
    if (!layers) return;
    layers.sheetOverlay.hidden = true;
    layers.sheet.innerHTML = '';
}

let toastTimer = null;
export function toast(message, tone = '') {
    const l = ensureLayers();
    l.toast.textContent = message;
    l.toast.className = `toast ${tone}`;
    l.toast.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { l.toast.hidden = true; }, tone === 'bad' ? 5000 : 2600);
}

export function confirmDialog(message, { ok = 'Đồng ý', cancel = 'Huỷ', danger = false } = {}) {
    const l = ensureLayers();
    l.confirm.querySelector('.confirm-message').textContent = message;
    const [cancelBtn, okBtn] = l.confirm.querySelectorAll('button');
    cancelBtn.textContent = cancel;
    okBtn.textContent = ok;
    okBtn.className = danger ? 'btn danger' : 'btn';
    l.confirm.hidden = false;
    okBtn.focus();
    return new Promise((resolve) => {
        const done = (value) => { l.confirm.hidden = true; cancelBtn.onclick = null; okBtn.onclick = null; resolve(value); };
        cancelBtn.onclick = () => done(false);
        okBtn.onclick = () => done(true);
    });
}

export function openLightbox(src, alt = '') {
    const l = ensureLayers();
    const img = l.lightbox.querySelector('img');
    img.src = src;
    img.alt = alt;
    l.lightbox.hidden = false;
}

export function closeLightbox() {
    if (layers) layers.lightbox.hidden = true;
}
