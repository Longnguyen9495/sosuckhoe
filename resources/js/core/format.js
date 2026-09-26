/** Định dạng kiểu Việt Nam: ngày dd/mm, số thập phân dấu phẩy, giờ Việt Nam. */
export const WD = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
export const WDL = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];

export function parseDate(ds) {
    const [y, m, d] = ds.split('-').map(Number);
    return new Date(y, m - 1, d);
}

export function toDs(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** Hôm nay theo giờ Việt Nam. */
export function todayVN() {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    return parts;
}

export function nowTimeVN() {
    return new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Ho_Chi_Minh', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date());
}

export function addDays(ds, n) {
    const d = parseDate(ds);
    d.setDate(d.getDate() + n);
    return toDs(d);
}

export function diffDays(a, b) {
    return Math.round((parseDate(b) - parseDate(a)) / 864e5);
}

export const dm = (ds) => { const d = parseDate(ds); return `${d.getDate()}/${d.getMonth() + 1}`; };
export const dmy = (ds) => { const d = parseDate(ds); return `${d.getDate()}/${d.getMonth() + 1}/${d.getFullYear()}`; };
export const longDate = (ds) => { const d = parseDate(ds); return `${WDL[d.getDay()]}, ${d.getDate()}/${d.getMonth() + 1}/${d.getFullYear()}`; };
export const monthLabel = (ym) => { const [y, m] = ym.split('-').map(Number); return `Tháng ${m}/${y}`; };

/** Số kiểu Việt Nam: 6.5 → "6,5". */
export function vn(value, digits = 1) {
    if (value === null || value === undefined || value === '') return '—';
    const n = Number(value);
    if (Number.isNaN(n)) return String(value);
    return (Math.round(n * 10 ** digits) / 10 ** digits).toString().replace('.', ',');
}

/** Đọc số người dùng gõ, chấp nhận dấu phẩy. */
export function parseNum(text) {
    if (text === null || text === undefined) return null;
    const n = parseFloat(String(text).trim().replace(',', '.'));
    return Number.isNaN(n) ? null : n;
}

export const hhmm = (t) => (t ? String(t).slice(0, 5) : '');

export function greeting(time = nowTimeVN()) {
    const h = Number(time.slice(0, 2));
    if (h < 11) return 'Chào buổi sáng';
    if (h < 14) return 'Chào buổi trưa';
    if (h < 18) return 'Chào buổi chiều';
    return 'Chào buổi tối';
}
