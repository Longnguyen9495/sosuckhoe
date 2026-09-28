/** Hằng số và hàm hiển thị dùng chung cho các màn hình. */
import { esc } from '../ui/dom.js';
import { vn } from '../core/format.js';
import { icon } from '../ui/icons.js';

export const EVENT_META = {
    appointment: { icon: icon('stethoscope', { size: 18 }), cls: 'kham', color: 'var(--brand)', label: 'Khám' },
    test: { icon: icon('flask', { size: 18 }), cls: 'xn', color: 'var(--info)', label: 'Xét nghiệm' },
    purchase: { icon: icon('cart', { size: 18 }), cls: 'mua', color: 'var(--warn)', label: 'Mua thuốc' },
    vaccination: { icon: icon('syringe', { size: 18 }), cls: 'good', color: 'var(--good)', label: 'Tiêm phòng' },
    other: { icon: icon('pin', { size: 18 }), cls: 'kham', color: 'var(--muted)', label: 'Khác' },
};

export const TYPE_TAG = {
    medication: '<span class="tag tmed">THUỐC</span>',
    insulin: '<span class="tag ins">INSULIN</span>',
    topical: '<span class="tag top">BÔI</span>',
    measurement: '',
    supply: '<span class="tag tmed">VẬT TƯ</span>',
};

export const POINTS = {
    fasting: 'Lúc đói', post_breakfast: '2h sau sáng', pre_lunch: 'Trước trưa', post_lunch: '2h sau trưa',
    pre_dinner: 'Trước tối', post_dinner: '2h sau tối', bedtime: 'Trước ngủ', bp_morning: 'HA sáng', bp_evening: 'HA tối',
};
export const GLUCOSE_POINTS = ['fasting', 'post_breakfast', 'pre_lunch', 'post_lunch', 'pre_dinner', 'post_dinner', 'bedtime'];

/** Dấu hiệu bất thường người nhà tự đánh dấu mỗi ngày (khớp bài hướng dẫn "Khi nào cần đi khám ngay"). */
export const SYMPTOMS = [
    { k: 'hypo', t: 'Run tay, vã mồ hôi, đói cồn cào, hoa mắt', a: 'Nghi hạ đường huyết: đo ngay; dưới 3,9 thì ăn 15 g đường nhanh, đo lại sau 15 phút.' },
    { k: 'conf', t: 'Lơ mơ, lú lẫn, co giật, không tỉnh', a: 'Gọi 115 ngay. Không cho ăn uống bằng miệng khi không tỉnh.' },
    { k: 'high', t: 'Khát nhiều, tiểu nhiều, nôn, thở nhanh', a: 'Nghi tăng đường huyết nặng: đo đường huyết, gọi hotline hoặc đi khám ngay.' },
    { k: 'liver', t: 'Vàng da, vàng mắt, nước tiểu sẫm', a: 'Dấu hiệu về gan: đi khám trong ngày, mang theo đơn thuốc.' },
    { k: 'gi', t: 'Phân đen, nôn ra máu, đau thượng vị', a: 'Ngừng thuốc giảm đau, đi khám ngay.' },
    { k: 'uti', t: 'Sốt, tiểu buốt, đau hông lưng', a: 'Nhiễm trùng tiết niệu tiến triển: đi khám.' },
    { k: 'bp', t: 'Đau đầu dữ dội, đau ngực, khó thở, yếu liệt', a: 'Gọi 115 ngay.' },
    { k: 'skin', t: 'Mẩn ngứa, mày đay, phù môi mắt sau uống thuốc', a: 'Dị ứng thuốc: ngừng thuốc mới, đi khám ngay (khó thở: gọi 115).' },
];

export const CONTACTS = [
    ['Cấp cứu', '115'],
    ['Tổng đài BV ĐH Y Hà Nội (giờ hành chính)', '19006422'],
    ['Hotline Khoa KCB theo yêu cầu (6h–22h)', '0866914460'],
    ['Sau 22h', '0348060800'],
    ['Đặt lịch khám', '02435747350'],
];

const LEVEL = {
    good: ['good', 'Đạt'],
    attention: ['warn', 'Cần chú ý'],
    red: ['bad', 'Cảnh báo'],
    unknown: ['info', 'Chưa có ngưỡng'],
    incomplete: ['info', 'Thiếu số'],
};

export function levelChip(level, label) {
    const [cls, text] = LEVEL[level] || LEVEL.unknown;
    return `<span class="eval chip ${cls}">${esc(label || text)}</span>`;
}

export function readingText(reading) {
    if (!reading) return '—';
    const v = reading.values || {};
    if (reading.type === 'blood_pressure') return `${v.systolic ?? '?'}/${v.diastolic ?? '?'}${v.heart_rate ? ` · mạch ${v.heart_rate}` : ''}`;
    return vn(v.value);
}

export function readingLevel(reading) {
    const e = reading?.evaluation;
    if (!e) return null;
    return typeof e === 'string' ? e : e.level;
}

export function initials(name) {
    return (name || '?').replace(/^(Bà|Ông|Cô|Chú|Anh|Chị|BS\.?|TS|PGS\.TS|BSCKII)\s+/i, '').trim().charAt(0).toUpperCase() || '?';
}

/** Văn bản nhiều dòng → danh sách. */
export function linesToList(text, ordered = false) {
    const items = String(text || '').split('\n').map((l) => l.trim()).filter(Boolean);
    const tag = ordered ? 'ol' : 'ul';
    return `<${tag}>${items.map((l) => `<li>${esc(l)}</li>`).join('')}</${tag}>`;
}

/* ---------- Bài tập có video (màn Hôm nay + Phác đồ) ---------- */

/**
 * Thẻ bài tập. Ảnh video chỉ là nút; bấm mới tải YouTube (xem playVideo).
 * `done` (true/false) hiện nút "Đã tập" để đánh dấu trong ngày; null thì không hiện.
 */
export function exerciseCard(e, { done = null, compact = false } = {}) {
    return `<article class="ex-card ${compact ? 'compact' : ''} ${done ? 'done' : ''}">
        <button type="button" class="ex-video" data-act="play-video" data-yt="${esc(e.youtube_id)}" aria-label="Xem video: ${esc(e.title)}">
            <img src="https://i.ytimg.com/vi/${esc(e.youtube_id)}/hqdefault.jpg" alt="" loading="lazy"><span class="play" aria-hidden="true">${icon('play', { size: 26, fill: 'currentColor' })}</span>
        </button>
        <div class="ex-body">
            <h3>${esc(e.title)}</h3>
            <div class="ex-meta"><span class="chip info">${esc(e.intensity)}</span><span class="chip">${esc(e.frequency)}</span></div>
            ${compact ? '' : `${e.why ? `<p class="ex-why">${esc(e.why)}</p>` : `<p>${esc(e.summary)}</p>`}
            <p class="ex-caution">${icon('warn', { size: 14 })} ${esc(e.caution)}</p>
            <p class="small muted">Video: ${esc(e.source)}</p>`}
            ${done === null ? '' : `<button type="button" class="btn sm ${done ? '' : 'ghost'} block ex-done" data-act="ex-done" data-id="${esc(e.id)}" aria-pressed="${done}">${done ? `${icon('check-circle', { size: 16 })} Đã tập hôm nay` : `${icon('check', { size: 16 })} Đánh dấu đã tập`}</button>`}
        </div></article>`;
}

/**
 * Thay ảnh bằng khung video YouTube (iframe không nằm được trong <button>).
 * YouTube bắt buộc trang nhúng gửi Referer, thiếu là báo "Lỗi 153". Máy chủ đặt Referrer-Policy: same-origin
 * cho cả site, nên riêng iframe này gửi tên miền (không gửi đường dẫn trang) qua referrerpolicy.
 * Nút phóng to của YouTube nhỏ, chỉ hiện khi chạm vào video → thêm nút "Phóng to" riêng dưới khung.
 * iPhone không cho phóng to khung iframe (chỉ thẻ <video>) → mở video trên YouTube.
 */
export function playVideo(el) {
    const id = encodeURIComponent(el.dataset.yt);
    const origin = encodeURIComponent(location.origin);
    const watch = `https://www.youtube.com/watch?v=${id}`;
    const tpl = document.createElement('template');
    tpl.innerHTML = `<div class="ex-video"><iframe src="https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&playsinline=1&fs=1&origin=${origin}" title="Video bài tập" referrerpolicy="strict-origin-when-cross-origin" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe></div>
        <div class="ex-video-bar">
            <button type="button" class="btn sm ghost ex-fs">${icon('maximize', { size: 16 })} Phóng to</button>
            <a class="ex-yt-link" href="${watch}" target="_blank" rel="noopener">Không xem được? Mở trên YouTube</a>
        </div>`;
    const box = tpl.content.querySelector('.ex-video');
    tpl.content.querySelector('.ex-fs').addEventListener('click', (event) => {
        event.stopPropagation();
        const enter = box.requestFullscreen || box.webkitRequestFullscreen;
        if (!enter) { window.open(watch, '_blank', 'noopener'); return; }
        Promise.resolve(enter.call(box))
            .then(() => screen.orientation?.lock?.('landscape').catch(() => {}))
            .catch(() => window.open(watch, '_blank', 'noopener'));
    });
    el.replaceWith(tpl.content);
}

/* ---------- Thực đơn ---------- */
export const MEALS = [['breakfast', 'sunrise', 'Bữa sáng'], ['lunch', 'sun', 'Bữa trưa'], ['dinner', 'moon', 'Bữa tối'], ['snacks', 'apple', 'Bữa phụ']];
export const WEEKDAYS = ['Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy', 'Chủ nhật'];
export const WEEKDAY_SHORT = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
/** Chỉ số ngày trong thực đơn tuần (0 = Thứ Hai) của một ngày 'YYYY-MM-DD'. */
export const menuIndex = (isoDate) => (new Date(`${isoDate}T00:00:00`).getDay() + 6) % 7;

/** Thực đơn 7 ngày của kế hoạch (Thứ Hai → Chủ nhật), hoặc null nếu kế hoạch cũ chỉ có thực đơn mẫu. */
export function weeklyMenu(content) {
    const week = content?.diet?.weekly_menu || [];
    return week.length === 7 ? week : null;
}

/** Các dòng bữa ăn của một ngày; `times` = giờ ăn theo giờ sinh hoạt (tuỳ chọn). */
/** Bữa chính sắp tới theo giờ sinh hoạt (sau `now` 'HH:MM'), hoặc null khi đã qua bữa tối. */
export function nextMeal(times, now) {
    const found = MEALS.slice(0, 3).find(([k]) => times[k] && String(times[k]).slice(0, 5) > now);
    return found ? { key: found[0], label: found[2].replace('Bữa ', '').replace(/^./, (x) => x.toUpperCase()), time: String(times[found[0]]).slice(0, 5) } : null;
}

/** Các bữa của một ngày; `times` = giờ ăn theo giờ sinh hoạt, `nextKey` = bữa sắp tới (làm nổi bật). */
export function menuRows(menu, times = {}, nextKey = null) {
    return MEALS.filter(([k]) => menu?.[k]).map(([k, ic, label]) => `<div class="meal-row m-${k} ${k === nextKey ? 'next' : ''}">
        <span class="meal-ic">${icon(ic, { size: 20 })}</span>
        <div class="meal-body"><div class="meal-h"><b>${label}</b>${times[k] ? `<small>${esc(String(times[k]).slice(0, 5))}</small>` : ''}${k === nextKey ? '<span class="chip">Sắp tới</span>' : ''}</div>
        <p>${esc(menu[k])}</p></div></div>`).join('');
}

