/** Tiện ích DOM nhỏ: thoát HTML, gắn sự kiện theo data-attribute. */
export const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

/** Gắn một lần cho cả vùng: click vào phần tử có data-act="x" gọi handlers.x(el, event). */
export function delegate(root, handlers) {
    root.addEventListener('click', (event) => {
        const el = event.target.closest('[data-act]');
        if (!el || !root.contains(el)) return;
        const fn = handlers[el.dataset.act];
        if (fn) fn(el, event);
    });
}

export const skeleton = (rows = 3) => Array.from({ length: rows }, (_, i) => `<div class="skeleton${i === 0 ? ' tall' : ''}"></div>`).join('');

export const emptyBox = (text) => `<div class="empty">${esc(text)}</div>`;

export function errorBox(message, retryAct = 'retry') {
    return `<div class="error-box" role="alert"><span>${esc(message)}</span><button class="btn sm danger" data-act="${retryAct}">Thử lại</button></div>`;
}

/** Nhãn nháp cho nội dung y khoa chưa được duyệt; bỏ tiền tố trong tiêu đề. */
export function draftTitle(title) {
    const clean = String(title || '').replace('[NHÁP — CẦN DUYỆT]', '').trim();
    const isDraft = String(title || '').includes('[NHÁP');
    return { clean, isDraft, html: `${esc(clean)}${isDraft ? ' <span class="nhap">NHÁP</span>' : ''}` };
}
