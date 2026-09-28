/** Cài đặt (giờ sinh hoạt, hiển thị, thành viên, dữ liệu) và màn Ngưỡng. */
import { api } from '../core/api.js';
import { hhmm, vn, dmy } from '../core/format.js';
import { esc, delegate, skeleton, errorBox } from '../ui/dom.js';
import { toast, confirmDialog, openSheet, closeSheet } from '../ui/shell.js';
import { FEATURES } from '../core/features.js';

const ROLE = { caregiver: 'Người chăm sóc', patient: 'Người bệnh', doctor: 'Bác sĩ', viewer: 'Người xem', owner: 'Chủ tài khoản', member: 'Thành viên' };

export async function renderSettings(ctx) {
    const pid = ctx.patient.id;
    const tid = ctx.tenant.id;
    const pref = (key, fallback) => { try { return localStorage.getItem(key) || fallback; } catch { return fallback; } };

    const screen = ctx.render({
        title: 'Cài đặt',
        back: '/today',
        tab: 'today',
        body: `<div class="sec-title"><h2>Giờ sinh hoạt</h2><span>lịch thuốc dựa vào các giờ này</span></div>
            <form class="card" id="routine">${skeleton(3)}</form>
            <div class="sec-title"><h2>Hiển thị</h2></div>
            <div class="card">
                <div class="field"><span class="label">Cỡ chữ</span><div class="seg" id="font">${[['normal', 'Thường'], ['large', 'Lớn'], ['xlarge', 'Rất lớn']].map(([v, l]) => `<button type="button" data-v="${v}" class="${pref('sokhoe-font', 'normal') === v ? 'on' : ''}">${l}</button>`).join('')}</div></div>
                <div class="field" style="margin:0"><span class="label">Giao diện</span><div class="seg" id="theme">${[['auto', 'Theo máy'], ['light', 'Sáng'], ['dark', 'Tối']].map(([v, l]) => `<button type="button" data-v="${v}" class="${pref('sokhoe-theme', 'auto') === v ? 'on' : ''}">${l}</button>`).join('')}</div></div>
            </div>
            <div class="sec-title"><h2>Ngưỡng cảnh báo</h2></div>
            <div class="card row"><div class="grow small ink2">Mức đường huyết, huyết áp, mạch dùng để đánh giá “Đạt / Cần chú ý / Cảnh báo”.</div><button class="btn sm ghost" data-act="nav" data-to="/settings/thresholds">Xem ngưỡng</button></div>
            ${FEATURES.caregiver ? `<div class="sec-title"><h2>Thành viên</h2><button class="btn sm" data-act="invite">+ Mời</button></div>
            <div class="card" id="members">${skeleton(2)}</div>` : ''}
            <div class="sec-title"><h2>Dữ liệu & tài khoản</h2></div>
            <div class="card">
                <button class="btn ghost block" data-act="nav" data-to="/calendar">Xuất bảng chỉ số (.csv) — ở màn Lịch</button>
                <button class="btn ghost block" style="margin-top:8px" data-act="logout">Đăng xuất</button>
                <button class="btn danger block" style="margin-top:8px" data-act="delete">Yêu cầu xoá tài khoản</button>
                <p class="small muted" style="margin:10px 0 0">Dữ liệu sức khỏe là dữ liệu cá nhân nhạy cảm. Bạn có quyền xem, xuất và yêu cầu xoá bất cứ lúc nào.</p>
            </div>`,
    });

    async function drawRoutine() {
        const form = screen.querySelector('#routine');
        try {
            const data = await api(`/patients/${pid}/settings`);
            const r = data.routines || {};
            const field = (key, label) => `<div class="field"><label for="${key}">${label}</label><input type="time" id="${key}" name="${key}" value="${hhmm(r[key]) || ''}" required></div>`;
            form.innerHTML = `<div class="grid2">${field('wake_time', 'Thức dậy')}${field('breakfast_time', 'Ăn sáng')}${field('lunch_time', 'Ăn trưa')}${field('dinner_time', 'Ăn tối')}${field('sleep_time', 'Đi ngủ')}</div>
                <p class="small muted">Giờ mới áp dụng từ ngày mai. Lịch hôm nay giữ nguyên.</p>
                <button class="btn block">Lưu giờ sinh hoạt</button>`;
        } catch (error) {
            form.innerHTML = errorBox(error.message);
        }
    }

    async function drawMembers() {
        const box = screen.querySelector('#members');
        try {
            const { data } = await api(`/tenants/${tid}/members`);
            box.innerHTML = data.map((m) => `<div class="row" style="padding:8px 0;border-bottom:1px solid var(--line)"><div class="avatar" style="width:36px;height:36px;font-size:14px">${esc((m.user?.name || '?').charAt(0))}</div>
                <div class="grow"><b>${esc(m.user?.name || '')}</b><div class="small muted">${esc(m.user?.phone || '')}</div></div><span class="chip">${ROLE[m.role] || m.role}</span></div>`).join('') || '<div class="empty">Chưa có thành viên.</div>';
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    screen.querySelector('#routine').addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = Object.fromEntries(['wake_time', 'breakfast_time', 'lunch_time', 'dinner_time', 'sleep_time'].map((k) => [k, event.target[k].value]));
        try {
            const res = await api(`/patients/${pid}/settings/routines`, { method: 'PATCH', body });
            toast(`Đã lưu. Lịch mới áp dụng từ ${dmy(res.effective_from)}.`);
        } catch (error) {
            toast(error.message, 'bad');
        }
    });

    const segHandler = (id, apply) => screen.querySelector(`#${id}`).addEventListener('click', (e) => {
        const b = e.target.closest('button');
        if (!b) return;
        screen.querySelectorAll(`#${id} button`).forEach((x) => x.classList.toggle('on', x === b));
        apply(b.dataset.v);
    });
    segHandler('font', (v) => {
        try { localStorage.setItem('sokhoe-font', v); } catch (_e) { /* bỏ qua */ }
        if (v === 'normal') delete document.documentElement.dataset.font; else document.documentElement.dataset.font = v;
    });
    segHandler('theme', (v) => {
        try { if (v === 'auto') localStorage.removeItem('sokhoe-theme'); else localStorage.setItem('sokhoe-theme', v); } catch (_e) { /* bỏ qua */ }
        if (v === 'auto') delete document.documentElement.dataset.theme; else document.documentElement.dataset.theme = v;
    });

    delegate(screen, {
        invite: () => {
            const sheet = openSheet(`<h3>Mời người cùng theo dõi</h3>
                <form id="inv"><div class="field"><label for="inv-to">Số điện thoại hoặc email người được mời</label><input id="inv-to" required></div>
                <div class="field"><label for="inv-role">Vai trò</label><select id="inv-role"><option value="caregiver">Người chăm sóc (xem, ghi, tích lịch)</option><option value="viewer">Người xem (chỉ xem)</option><option value="doctor">Bác sĩ (xem, xác nhận ngưỡng, nhận xét)</option><option value="patient">Người bệnh</option></select></div>
                <button class="btn block">Tạo lời mời</button></form><div id="inv-out"></div>`);
            sheet.querySelector('#inv').addEventListener('submit', async (e) => {
                e.preventDefault();
                try {
                    const { data } = await api(`/patients/${pid}/invitations`, { method: 'POST', body: { recipient: sheet.querySelector('#inv-to').value.trim(), role: sheet.querySelector('#inv-role').value } });
                    sheet.querySelector('#inv-out').innerHTML = `<div class="alert good"><div class="ico">✓</div><div><b>Đã tạo lời mời (hết hạn sau 7 ngày).</b><p>Gửi mã sau cho người được mời để họ nhập khi đăng nhập:</p><p><code style="word-break:break-all">${esc(data.token)}</code></p></div></div>`;
                } catch (error) { toast(error.message, 'bad'); }
            });
        },
        logout: async () => { if (await confirmDialog('Đăng xuất khỏi Sổ Sức Khỏe trên thiết bị này?')) ctx.logout(); },
        delete: async () => {
            if (!await confirmDialog('Gửi yêu cầu xoá tài khoản và dữ liệu? Yêu cầu sẽ được xử lý theo quy định, không thể hoàn tác.', { ok: 'Gửi yêu cầu', danger: true })) return;
            try { await api('/account/delete', { method: 'POST' }); toast('Đã ghi nhận yêu cầu xoá tài khoản.'); ctx.logout(); } catch (error) { toast(error.message, 'bad'); }
        },
        retry: () => ctx.refresh(),
    });

    drawRoutine();
    if (FEATURES.caregiver) drawMembers();
}

/* ================= Ngưỡng ================= */
const METRIC = { blood_glucose: 'Đường huyết (mmol/L)', blood_pressure: 'Huyết áp (mmHg)', heart_rate: 'Mạch (lần/phút)' };
const CONTEXT = { pre_meal: 'Trước ăn / lúc đói / trước ngủ', post_meal_2h: '2 giờ sau ăn', general: 'Mọi lúc', resting: 'Khi nghỉ' };

function describe(t) {
    const r = t.ranges || {};
    if (t.metric === 'blood_pressure') {
        return [`Đạt: dưới ${r.target_below?.systolic}/${r.target_below?.diastolic}`, `Cảnh báo: từ ${r.red_at_or_above?.systolic}/${r.red_at_or_above?.diastolic} trở lên, hoặc dưới ${r.red_below?.systolic}/${r.red_below?.diastolic}`];
    }
    const lines = [];
    if (r.target) lines.push(`Đạt: ${vn(r.target[0])} – ${vn(r.target[1])}`);
    if (r.target_below) lines.push(`Đạt: dưới ${vn(r.target_below)}`);
    if (r.red) lines.push(`Cảnh báo: ${[r.red.below !== undefined ? `dưới ${vn(r.red.below)}` : '', r.red.above !== undefined ? `trên ${vn(r.red.above)}` : ''].filter(Boolean).join(' hoặc ')}${r.red.critical_above ? ` (rất nguy hiểm trên ${vn(r.red.critical_above)})` : ''}`);
    return lines;
}

export async function renderThresholds(ctx) {
    const pid = ctx.patient.id;
    const screen = ctx.render({
        title: 'Ngưỡng cảnh báo',
        back: '/settings',
        tab: 'today',
        body: `<div class="card lift small ink2">Ngưỡng lấy từ mẫu bệnh là <b>mặc định</b>, cần bác sĩ xác nhận cho từng người bệnh. Người nhà sửa ngưỡng thì vẫn ở trạng thái “chưa được bác sĩ xác nhận”.</div>
            <div id="list">${skeleton(4)}</div>`,
    });

    let thresholds = [];
    async function draw() {
        const box = screen.querySelector('#list');
        try {
            thresholds = await api(`/patients/${pid}/thresholds`);
            box.innerHTML = thresholds.map((t) => `<div class="card">
                <div class="row" style="align-items:flex-start"><div class="grow"><h3>${METRIC[t.metric] || t.metric}</h3><div class="small muted">${CONTEXT[t.context] || t.context}</div></div>
                ${t.confirmed_at ? `<span class="chip good">Bác sĩ đã xác nhận</span>` : '<span class="chip warn">Mặc định — chưa được bác sĩ xác nhận</span>'}</div>
                <ul class="small" style="margin:8px 0 0;padding-left:18px">${describe(t).map((l) => `<li>${esc(l)}</li>`).join('')}</ul>
                <div class="row wrap-row" style="margin-top:10px">
                    ${t.metric !== 'blood_pressure' ? `<button class="btn sm ghost" data-act="edit" data-id="${esc(t.id)}">Sửa</button>` : ''}
                    ${ctx.isDoctor && !t.confirmed_at ? `<button class="btn sm" data-act="confirm" data-id="${esc(t.id)}">Xác nhận ngưỡng</button>` : ''}
                </div></div>`).join('') || '<div class="empty">Chưa có ngưỡng. Gán bệnh nền để có ngưỡng mặc định.</div>';
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    delegate(screen, {
        confirm: async (el) => {
            try { await api(`/patients/${pid}/thresholds/${el.dataset.id}/confirm`, { method: 'POST' }); toast('Đã xác nhận ngưỡng.'); draw(); } catch (error) { toast(error.message, 'bad'); }
        },
        edit: (el) => {
            const t = thresholds.find((x) => x.id === el.dataset.id);
            const r = t.ranges;
            const sheet = openSheet(`<h3>Sửa ngưỡng: ${METRIC[t.metric]} · ${CONTEXT[t.context] || t.context}</h3>
                <form id="th">${r.target ? `<div class="grid2"><div class="field"><label for="t0">Đạt từ</label><input id="t0" inputmode="decimal" value="${vn(r.target[0])}"></div><div class="field"><label for="t1">đến</label><input id="t1" inputmode="decimal" value="${vn(r.target[1])}"></div></div>` : ''}
                ${r.target_below !== undefined ? `<div class="field"><label for="tb">Đạt khi dưới</label><input id="tb" inputmode="decimal" value="${vn(r.target_below)}"></div>` : ''}
                <div class="grid2"><div class="field"><label for="rb">Cảnh báo khi dưới</label><input id="rb" inputmode="decimal" value="${vn(r.red?.below)}"></div><div class="field"><label for="ra">Cảnh báo khi trên</label><input id="ra" inputmode="decimal" value="${vn(r.red?.above)}"></div></div>
                <p class="small muted">Chỉ sửa theo chỉ định của bác sĩ điều trị.</p><button class="btn block">Lưu</button></form>`);
            sheet.querySelector('#th').addEventListener('submit', async (e) => {
                e.preventDefault();
                const num = (id) => { const v = sheet.querySelector(`#${id}`)?.value; return v === undefined ? undefined : parseFloat(String(v).replace(',', '.')); };
                const ranges = JSON.parse(JSON.stringify(r));
                if (r.target) ranges.target = [num('t0'), num('t1')];
                if (r.target_below !== undefined) ranges.target_below = num('tb');
                ranges.red = { ...(r.red || {}), below: num('rb'), above: num('ra') };
                try { await api(`/patients/${pid}/thresholds/${t.id}`, { method: 'PATCH', body: { ranges } }); closeSheet(); toast('Đã lưu ngưỡng.'); draw(); } catch (error) { toast(error.message, 'bad'); }
            });
        },
        retry: () => ctx.refresh(),
    });

    draw();
}
