/**
 * Trang xem hồ sơ qua link chia sẻ (#/s/{mã}) — người xem KHÔNG đăng nhập (dược sĩ, bác sĩ, người thân).
 * Gọi API bằng fetch riêng, không qua core/api.js: không gửi token của người đang dùng máy,
 * và lỗi 401 (sai PIN) không được làm đăng xuất tài khoản trên máy này.
 */
import { dm, dmy, vn } from '../core/format.js';
import { esc } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { openLightbox } from '../ui/shell.js';
import { drawChart } from '../ui/chart.js';
import { POINTS, levelChip } from './common.js';

const BASE = '/api/v1';
const FLAG = { H: ['bad', '↑ Cao'], L: ['info', '↓ Thấp'], W: ['warn', 'Lưu ý'], N: ['good', 'Bình thường'] };
const GENDER = { male: 'Nam', female: 'Nữ', nam: 'Nam', nu: 'Nữ', 'nữ': 'Nữ', other: 'Khác' };
const TYPE = { insulin: 'Insulin', topical: 'Thuốc bôi', supply: 'Vật tư', medication: 'Thuốc' };
const DOC_TYPE = { don: 'Đơn thuốc', xn: 'Xét nghiệm', cdha: 'Chẩn đoán hình ảnh', kham: 'Kết quả khám', hd: 'Hướng dẫn', thuoc: 'Vỏ thuốc', khac: 'Khác' };

async function call(path, init = {}) {
    const res = await fetch(`${BASE}${path}`, { credentials: 'omit', ...init, headers: { Accept: 'application/json', ...(init.body ? { 'Content-Type': 'application/json' } : {}), ...(init.headers || {}) } });
    const body = await res.json().catch(() => ({}));
    return { status: res.status, body };
}

/** Trang không cho công cụ tìm kiếm lập chỉ mục, không gửi địa chỉ trang (có mã link) sang trang khác. */
function privacyMeta() {
    for (const [name, content] of [['robots', 'noindex, nofollow'], ['referrer', 'no-referrer']]) {
        let m = document.querySelector(`meta[name="${name}"]`);
        if (!m) { m = document.createElement('meta'); m.name = name; document.head.append(m); }
        m.content = content;
    }
}

export async function renderShared(ctx, { token }) {
    privacyMeta();
    const root = ctx.root;
    root.className = 'app shared';
    document.title = 'Hồ sơ được chia sẻ · Sổ Sức Khỏe';
    root.innerHTML = `<header class="hero sh-hero"><div class="sh-brand">${icon('shield', { size: 18 })} Sổ Sức Khỏe · hồ sơ được chia sẻ</div><h1 id="sh-title">Đang mở…</h1><p class="sub" id="sh-sub"></p></header>
        <main class="wrap" id="sh"><div class="card lift"><div class="skeleton tall"></div></div></main>`;
    const main = root.querySelector('#sh');
    const setHead = (title, sub = '') => { root.querySelector('#sh-title').textContent = title; root.querySelector('#sh-sub').textContent = sub; };

    const gone = (message) => {
        setHead('Không mở được hồ sơ');
        main.innerHTML = `<div class="card lift sh-gone"><span class="cp-ico">${icon('lock', { size: 30 })}</span><p>${esc(message || 'Link không còn hiệu lực.')}</p><p class="small muted">Nhờ người bệnh gửi lại link mới.</p></div>`;
    };

    const meta = await call(`/shared/${encodeURIComponent(token)}`).catch(() => ({ status: 0, body: {} }));
    if (meta.status !== 200) return gone(meta.status === 0 ? 'Mất kết nối mạng. Thử lại sau.' : meta.body.message);
    const info = meta.body.data;

    async function open(pin = null) {
        const res = await call(`/shared/${encodeURIComponent(token)}/open`, { method: 'POST', body: JSON.stringify(pin ? { pin } : {}) });
        if (res.status === 200) return showSummary(res.body.data, res.body.session);
        if (res.status === 404) return gone(res.body.message);
        return pinForm(res.body.message, res.status === 423);
    }

    function pinForm(error = '', locked = false) {
        setHead('Nhập mã PIN', `Người bệnh chia sẻ cho: ${info.label}`);
        main.innerHTML = `<form class="card lift sh-pin" id="pin-form" novalidate>
            <span class="cp-ico">${icon('key', { size: 28 })}</span>
            <label for="pin"><b>Mã PIN (4–6 số)</b></label>
            <input id="pin" class="pin-input" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6" placeholder="••••" ${locked ? 'disabled' : ''}>
            ${error ? `<p class="sh-err" role="alert">${esc(error)}</p>` : '<p class="small muted">Người bệnh gửi PIN cho bạn riêng (tin nhắn khác hoặc gọi điện).</p>'}
            <button class="btn block" ${locked ? 'disabled' : ''}>Mở hồ sơ</button>
            <p class="small muted">Link hết hạn ${dmyTime(info.expires_at)}.</p></form>`;
        const form = main.querySelector('#pin-form');
        form.querySelector('#pin').focus();
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const pin = form.querySelector('#pin').value.replace(/\D/g, '');
            if (pin.length < 4) return pinForm('Nhập đủ 4–6 chữ số.');
            form.querySelector('button').disabled = true;
            await open(pin);
        });
    }

    if (info.locked_minutes) return pinForm(`Nhập sai PIN nhiều lần. Thử lại sau ${info.locked_minutes} phút.`, true);
    if (info.requires_pin) return pinForm();
    return open();

    /* ---------- Nội dung hồ sơ ---------- */
    function showSummary(d, session) {
        const p = d.patient;
        setHead(`${p.name}${p.age ? `, ${p.age} tuổi` : ''}`, [GENDER[String(p.gender || '').toLowerCase()], `Chia sẻ cho: ${d.label}`, `hết hạn ${dmyTime(d.expires_at)}`].filter(Boolean).join(' · '));
        const glucose = d.readings.filter((r) => r.type === 'blood_glucose');
        const bp = d.readings.filter((r) => r.type === 'blood_pressure');
        const groups = {};
        d.lab_results.forEach((r) => { (groups[r.group] ||= []).push(r); });
        // Người xem (dược sĩ, bác sĩ) cần thấy ngay chỉ số bất thường; bảng đầy đủ gập lại.
        const abnormal = d.lab_results.filter((r) => ['H', 'L', 'W'].includes(r.flag));
        // Dạng danh sách 2 cột (tên | kết quả) thay cho bảng 4 cột — vừa màn hình điện thoại.
        const labRow = (r) => { const f = FLAG[r.flag] || FLAG.N; return `<div class="lab-row"><div class="lab-name">${esc(r.name)}<small>${dm(r.date)}</small></div><div class="lab-val"><b>${esc(r.value)}</b> <span class="muted">${esc(r.unit || '')}</span><span class="chip ${f[0]}">${f[1]}</span>${r.reference ? `<small>Tham chiếu: ${esc(r.reference)}</small>` : ''}</div></div>`; };
        const sec = (ic, title, body, sub = '') => `<section class="blk"><div class="blk-h"><span class="blk-ic">${icon(ic, { size: 18 })}</span><h2>${title}</h2>${sub ? `<span class="blk-sub">${sub}</span>` : ''}</div>${body}</section>`;

        main.innerHTML = `
            <div class="card lift ${p.allergies ? 'sh-allergy' : ''}"><div class="row" style="align-items:flex-start">${icon('warn', { size: 20 })}<div class="grow"><b>Dị ứng</b><p style="margin:2px 0 0">${p.allergies ? esc(p.allergies) : '<span class="muted">Chưa ghi nhận trong sổ (không có nghĩa là không dị ứng — hỏi lại người bệnh).</span>'}</p></div></div></div>

            ${sec('pill', 'Thuốc đang dùng', d.medications.length ? `<div class="card">${d.medications.map((m) => `<div class="sh-med">
                <div class="row" style="align-items:flex-start"><b class="grow">${esc(m.drug)}</b><span class="chip ${m.type === 'insulin' ? 'bad' : 'info'}">${TYPE[m.type] || 'Thuốc'}</span></div>
                <p class="small ink2">${esc(m.how_to_use || '')}</p>
                ${m.times.length ? `<div class="chips">${m.times.map((t) => `<span class="chip">${icon('clock', { size: 13 })} ${esc(t.time)} · ${esc(t.amount || '')}</span>`).join('')}</div>` : ''}
                <p class="small muted">${[m.prescriber ? `Kê bởi: ${esc(m.prescriber)}` : '', m.prescribed_at ? `ngày ${dmy(m.prescribed_at)}` : '', m.long_term ? 'dùng lâu dài' : ''].filter(Boolean).join(' · ')}</p></div>`).join('')}</div>`
                : '<div class="empty">Chưa có đơn thuốc đang dùng trong sổ.</div>', `${d.medications.length} thuốc`)}

            ${sec('stethoscope', 'Bệnh nền / chẩn đoán', d.conditions.length ? `<div class="card"><ul class="sh-list">${d.conditions.map((c) => `<li>${esc(c)}</li>`).join('')}</ul></div>` : '<div class="empty">Chưa ghi nhận.</div>')}

            ${sec('flask', 'Xét nghiệm gần nhất', d.lab_results.length ? `
                ${abnormal.length ? `<div class="card sh-abn"><h3>${icon('warn', { size: 16 })} Chỉ số ngoài giới hạn (${abnormal.length})</h3><div class="lab-list">${abnormal.map(labRow).join('')}</div></div>` : '<div class="card"><p class="small ink2" style="margin:0">Không có chỉ số nào ngoài giới hạn tham chiếu.</p></div>'}
                <details class="fold-lite"><summary>Xem tất cả ${d.lab_results.length} chỉ số theo nhóm</summary>
                ${Object.entries(groups).map(([g, rows]) => `<div class="card flat"><h3>${esc(g)}</h3><div class="lab-list">${rows.map(labRow).join('')}</div></div>`).join('')}
                </details>` : '<div class="empty">Chưa có kết quả xét nghiệm trong 12 tháng.</div>', 'trong 12 tháng')}

            ${sec('droplet', 'Đường huyết 30 ngày', glucose.length ? `<div class="card">${stats(glucose)}<div class="chart" id="sh-chart"></div>
                <details class="fold-lite"><summary>Xem ${glucose.length} lần đo</summary><table class="tbl"><tbody>${[...glucose].reverse().map((r) => `<tr><td class="v">${esc(dm(r.at.slice(0, 10)))} ${esc(r.at.slice(11, 16))}</td><td>${esc(POINTS[r.context] || '')}</td><td class="v">${vn(r.values.value)}</td><td>${r.evaluation ? levelChip(r.evaluation) : ''}</td></tr>`).join('')}</tbody></table></details></div>`
                : '<div class="empty">Chưa tự đo đường huyết trong 30 ngày.</div>', 'mmol/L')}

            ${bp.length ? sec('heart', 'Huyết áp 30 ngày', `<div class="card"><table class="tbl"><tbody>${[...bp].reverse().slice(0, 20).map((r) => `<tr><td class="v">${esc(dm(r.at.slice(0, 10)))} ${esc(r.at.slice(11, 16))}</td><td class="v">${esc(r.values.systolic)}/${esc(r.values.diastolic)}${r.values.heart_rate ? ` · mạch ${esc(r.values.heart_rate)}` : ''}</td><td>${r.evaluation ? levelChip(r.evaluation) : ''}</td></tr>`).join('')}</tbody></table></div>`, 'mmHg') : ''}

            ${d.documents.length ? sec('file', 'Ảnh phiếu khám', `<div class="sh-docs">${d.documents.map((doc) => `<button class="sh-doc" data-doc="${esc(doc.id)}" aria-label="Xem ảnh ${esc(doc.title)}"><span class="sh-thumb" data-thumb="${esc(doc.id)}">${icon('image', { size: 22 })}</span><b>${esc(doc.title)}</b><small>${esc(DOC_TYPE[doc.type] || '')}${doc.date ? ` · ${dm(doc.date)}` : ''}</small></button>`).join('')}</div>`, `${d.documents.length} phiếu`) : ''}

            <div class="sh-foot">
                <button class="btn ghost block" id="sh-print">${icon('download', { size: 18 })} In / lưu PDF</button>
                <p class="small muted">${icon('info', { size: 14 })} Dữ liệu do người bệnh tự ghi và AI đọc từ ảnh phiếu khám — đối chiếu với giấy tờ gốc trước khi dùng cho quyết định điều trị. Không có số điện thoại, CCCD, BHYT. Cập nhật lúc ${dmyTime(d.generated_at)}.</p>
            </div>`;

        const chartEl = main.querySelector('#sh-chart');
        if (chartEl) drawChart(chartEl, [{ name: 'Đường huyết', color: 'var(--s1)', pts: glucose.map((r) => ({ x: r.at.slice(0, 10), y: Number(r.values.value) })) }], { band: [4.4, 10], pad: 1, unit: 'mmol/L', aria: 'Đường huyết 30 ngày' });
        main.querySelector('#sh-print').addEventListener('click', () => window.print());

        // Ảnh phiếu: tải bằng phiên xem (header), hiện bản thu nhỏ + phóng to.
        const urls = {};
        const load = async (id) => {
            if (urls[id]) return urls[id];
            const res = await fetch(`${BASE}/shared-documents/${encodeURIComponent(id)}`, { credentials: 'omit', headers: { 'X-Share-Session': session } });
            if (!res.ok) throw new Error(res.status === 404 ? 'Phiên xem đã hết hạn. Tải lại trang để xem tiếp.' : 'Không mở được ảnh.');
            urls[id] = URL.createObjectURL(await res.blob());
            return urls[id];
        };
        main.querySelectorAll('[data-thumb]').forEach(async (el) => {
            try { el.innerHTML = `<img src="${await load(el.dataset.thumb)}" alt="">`; } catch (_e) { /* để icon */ }
        });
        main.querySelectorAll('[data-doc]').forEach((btn) => btn.addEventListener('click', async () => {
            try { openLightbox(await load(btn.dataset.doc), 'Ảnh phiếu khám'); } catch (error) { alert(error.message); }
        }));
    }
}

function stats(glucose) {
    const v = glucose.map((r) => Number(r.values.value)).filter((n) => !Number.isNaN(n));
    const avg = v.reduce((a, b) => a + b, 0) / v.length;
    const low = v.filter((n) => n < 3.9).length;
    const cell = (label, value) => `<div class="sum-tile"><small>${label}</small><b>${value}</b></div>`;
    return `<div class="sum-grid" style="margin-bottom:10px">${cell('Trung bình', vn(Math.round(avg * 10) / 10))}${cell('Thấp nhất – cao nhất', `${vn(Math.min(...v))}–${vn(Math.max(...v))}`)}${cell('Dưới 3,9', `${low} lần`)}</div>`;
}

function dmyTime(iso) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const two = (n) => String(n).padStart(2, '0');
    return `${two(d.getHours())}:${two(d.getMinutes())} ${two(d.getDate())}/${two(d.getMonth() + 1)}/${d.getFullYear()}`;
}
