/** Hằng số và hàm hiển thị dùng chung cho các màn hình. */
import { esc } from '../ui/dom.js';
import { vn } from '../core/format.js';

export const EVENT_META = {
    appointment: { icon: '🩺', cls: 'kham', color: 'var(--brand)', label: 'Khám' },
    test: { icon: '🧪', cls: 'xn', color: 'var(--info)', label: 'Xét nghiệm' },
    purchase: { icon: '🛒', cls: 'mua', color: 'var(--warn)', label: 'Mua thuốc' },
    vaccination: { icon: '💉', cls: 'good', color: 'var(--good)', label: 'Tiêm phòng' },
    other: { icon: '📌', cls: 'kham', color: 'var(--muted)', label: 'Khác' },
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
