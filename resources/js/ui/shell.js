/**
 * Khung ứng dụng (phong cách Snapask): đầu trang tím, thanh tab dưới đáy có nút + nổi,
 * bảng trượt từ dưới lên, hộp xác nhận, phóng ảnh, thông báo ngắn.
 */
import { esc } from './dom.js';
import { icon } from './icons.js';
import { FEATURES } from '../core/features.js';

/** Icon khung ứng dụng (Lucide, xem ui/icons.js). */
export const ICONS = {
    home: icon('house', { size: 24 }),
    cal: icon('calendar', { size: 24 }),
    plus: icon('plus', { size: 28, stroke: 2.4 }),
    pill: icon('pill', { size: 24 }),
    doc: icon('file', { size: 24 }),
    chat: icon('chat', { size: 22 }),
    moon: icon('moon', { size: 20 }),
    gear: icon('settings', { size: 21 }),
    user: icon('user', { size: 21 }),
    back: icon('chevron-left', { size: 22, stroke: 2.4 }),
};

/** Đầu trang tím. `patient` hiện nút chọn bệnh nhân; `back` thay bằng nút quay lại. */
export function hero({ title, sub = '', patient = null, back = null, online = true, badge = false }) {
    const initial = (patient?.full_name || '?').replace(/^(Bà|Ông|Cô|Chú|Anh|Chị)\s+/i, '').trim().charAt(0).toUpperCase() || '?';
    const left = back
        ? `<button class="icon-btn" data-act="nav" data-to="${esc(back)}" aria-label="Quay lại">${ICONS.back}</button><div class="who grow"><b>${esc(title)}</b></div>`
        : FEATURES.caregiver
            ? `<button class="who-btn" data-act="choose-patient" aria-label="Đổi người bệnh">
               <span class="avatar">${esc(initial)}</span>
               <span class="who"><b>${esc(patient?.full_name || 'Sổ Sức Khỏe')} ▾</b><small>${patient?.birth_year ? `${new Date().getFullYear() - patient.birth_year} tuổi · ` : ''}Chạm để đổi người bệnh</small></span>
           </button>`
            : `<button class="who-btn" data-act="nav" data-to="/me" aria-label="Thông tin cá nhân">
               <span class="avatar">${esc(initial)}</span>
               <span class="who"><b>${esc(patient?.full_name || 'Sổ Sức Khỏe')}</b><small>${patient?.birth_year ? `${new Date().getFullYear() - patient.birth_year} tuổi` : 'Sổ theo dõi của bạn'}</small></span>
           </button>`;
    return `<header class="hero">
        <div class="topbar">
            ${left}
            ${FEATURES.doctor ? `<button class="icon-btn" data-act="nav" data-to="/ask" aria-label="Hỏi bác sĩ" title="Hỏi bác sĩ">${ICONS.chat}${badge ? '<span class="dot"></span>' : ''}</button>` : ''}
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
    l.sheet.innerHTML = `<div class="sheet-top"><div class="grab"></div><div class="sheet-bar"><span class="sheet-title"></span><button type="button" class="sheet-x" aria-label="Đóng">${icon('close', { size: 20 })}</button></div></div>${innerHtml}`;
    // Tiêu đề đầu tiên lên thanh trên cùng (dính khi cuộn) cạnh nút đóng — bảng dài vẫn luôn thấy đang làm gì.
    const title = l.sheet.querySelector('.sheet-top ~ * h3, .sheet-top ~ h3');
    if (title) l.sheet.querySelector('.sheet-title').append(title);
    l.sheet.querySelector('.sheet-x').addEventListener('click', () => closeSheet());
    l.sheetOverlay.hidden = false;
    document.body.classList.add('sheet-open');
    onMount?.(l.sheet);
    // Không tự đặt con trỏ vào ô nhập đầu tiên: iPhone sẽ cuộn bảng tới ô đó (che mất tiêu đề) và bật bàn phím.
    // Chỉ focus phần tử có [autofocus]; còn lại focus bảng (cho trình đọc màn hình) và luôn mở từ đầu.
    l.sheet.scrollTop = 0;
    const target = l.sheet.querySelector('[autofocus]');
    if (target) target.focus({ preventScroll: true });
    else { l.sheet.tabIndex = -1; l.sheet.focus({ preventScroll: true }); }
    return l.sheet;
}

export function closeSheet() {
    if (!layers) return;
    layers.sheetOverlay.hidden = true;
    document.body.classList.remove('sheet-open');
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
