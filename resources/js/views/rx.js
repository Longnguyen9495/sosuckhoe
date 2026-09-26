/**
 * Nhập đơn thuốc (dùng chung cho màn Nhập đơn và bước 5 của Đăng ký) + màn Chụp đơn (AI).
 * Bộ chọn chỉ mã hoá GIỜ dùng; "lượng mỗi lần" là chữ nguyên văn trên đơn, không tính toán liều.
 */
import { api } from '../core/api.js';
import { todayVN, parseNum } from '../core/format.js';
import { esc, delegate } from '../ui/dom.js';
import { toast, confirmDialog } from '../ui/shell.js';

/** Các thời điểm dùng hay gặp trên đơn → mốc sinh hoạt + lệch giờ (PLAN.md mục 5.4). */
export const SLOTS = [
    { k: 'w+0', anchor: 'wake', offset: 0, label: 'Lúc ngủ dậy (bụng đói)' },
    { k: 'b-60', anchor: 'breakfast', offset: -60, label: 'Trước ăn sáng 1 giờ' },
    { k: 'b-30', anchor: 'breakfast', offset: -30, label: 'Sáng — trước ăn 30 phút' },
    { k: 'b-5', anchor: 'breakfast', offset: -5, label: 'Sáng — ngay trước ăn 5 phút' },
    { k: 'b+0', anchor: 'breakfast', offset: 0, label: 'Sáng — cùng bữa ăn' },
    { k: 'b+30', anchor: 'breakfast', offset: 30, label: 'Sáng — sau ăn' },
    { k: 'l-30', anchor: 'lunch', offset: -30, label: 'Trưa — trước ăn 30 phút' },
    { k: 'l-5', anchor: 'lunch', offset: -5, label: 'Trưa — ngay trước ăn' },
    { k: 'l+0', anchor: 'lunch', offset: 0, label: 'Trưa — cùng bữa ăn' },
    { k: 'l+30', anchor: 'lunch', offset: 30, label: 'Trưa — sau ăn' },
    { k: 'd-30', anchor: 'dinner', offset: -30, label: 'Tối — trước ăn 30 phút' },
    { k: 'd-5', anchor: 'dinner', offset: -5, label: 'Tối — ngay trước ăn 5 phút' },
    { k: 'd+0', anchor: 'dinner', offset: 0, label: 'Tối — cùng bữa ăn' },
    { k: 'd+30', anchor: 'dinner', offset: 30, label: 'Tối — sau ăn' },
    { k: 's-30', anchor: 'sleep', offset: -30, label: 'Trước khi ngủ' },
];

/** Thuốc AI đọc được (usage_rule) → dòng trong trình soạn đơn. */
export function fromUsageRule(med) {
    const item = { ...blankItem(), drug_name: med.drug_name || '', dose_text: med.dose_text || '', type: med.type || 'medication', prescribed_quantity: med.quantity ?? '', quantity_unit: med.unit || 'viên', is_long_term: !med.duration_days };
    (med.usage_rule?.doses || []).forEach((d) => {
        if (d.fixed_time) { item.fixed.push({ time: d.fixed_time, amount: d.amount_text }); return; }
        const slot = SLOTS.find((s) => s.anchor === d.anchor && s.offset === d.offset_min)
            || SLOTS.filter((s) => s.anchor === d.anchor).sort((a, b) => Math.abs(a.offset - d.offset_min) - Math.abs(b.offset - d.offset_min))[0];
        if (slot) item.slots[slot.k] = d.amount_text;
    });
    return item;
}
const TYPES = [['medication', 'Thuốc uống'], ['insulin', 'Insulin / tiêm'], ['topical', 'Bôi / dán'], ['supply', 'Vật tư (kim, que thử…)']];

export const blankItem = () => ({ drug_id: null, drug_name: '', dose_text: '', type: 'medication', slots: {}, fixed: [], prescribed_quantity: '', purchased_quantity: '', quantity_unit: 'viên', units_per_day: '', is_long_term: false });

/** Chuyển dữ liệu biểu mẫu thành item gửi API. */
export function toApiItem(item) {
    const doses = [
        ...Object.entries(item.slots).map(([k, amount]) => { const s = SLOTS.find((x) => x.k === k); return { anchor: s.anchor, offset_min: s.offset, amount_text: amount || '1 lần' }; }),
        ...item.fixed.filter((f) => f.time).map((f) => ({ fixed_time: f.time, amount_text: f.amount || '1 lần' })),
    ];
    const upd = parseNum(item.units_per_day);
    const rule = item.type === 'supply' || doses.length
        ? { type: item.type, days: { type: 'daily' }, doses, ...(upd ? { units_per_day: upd } : {}) }
        : null;
    return {
        drug_id: item.drug_id || null,
        drug_name: item.drug_name.trim(),
        dose_text: item.dose_text.trim() || '(chưa ghi cách dùng)',
        usage_rule: rule,
        prescribed_quantity: parseNum(item.prescribed_quantity),
        purchased_quantity: parseNum(item.purchased_quantity),
        quantity_unit: item.quantity_unit || null,
        is_long_term: !!item.is_long_term,
    };
}

/** Tính giờ xem trước ngay trên máy (dùng khi chưa có hồ sơ, ví dụ bước 6 của Đăng ký). */
export function localPreview(items, routine) {
    const add = (hm, min) => { const [h, m] = hm.split(':').map(Number); const t = h * 60 + m + min; return `${String(Math.floor(((t % 1440) + 1440) % 1440 / 60)).padStart(2, '0')}:${String(((t % 60) + 60) % 60).padStart(2, '0')}`; };
    const map = { breakfast: routine.breakfast_time, lunch: routine.lunch_time, dinner: routine.dinner_time, sleep: routine.sleep_time, wake: routine.wake_time };
    const rows = [];
    items.forEach((it) => (it.usage_rule?.doses || []).forEach((d) => rows.push({ scheduled_time: d.fixed_time || add(map[d.anchor], d.offset_min), title: it.drug_name, amount_text: d.amount_text, type: it.usage_rule.type })));
    return rows.sort((a, b) => a.scheduled_time.localeCompare(b.scheduled_time));
}

export function previewHtml(rows, warnings = []) {
    return `${warnings.map((w) => `<div class="alert mua"><div class="ico">!</div><div><b>${esc(w.drug_name)}</b><p>${esc(w.message)}</p></div></div>`).join('')}
        ${rows.length ? `<div class="day-sched">${rows.map((r) => `<div class="h">${r.scheduled_time}</div><div>${esc(r.title)} · <b>${esc(r.amount_text)}</b></div>`).join('')}</div>` : '<div class="empty">Chưa có giờ dùng nào.</div>'}`;
}

/** Trình soạn danh sách thuốc. Trả về { getItems() }. */
export function mountRxEditor(container, initial = []) {
    const items = initial.length ? initial : [blankItem()];

    function itemHtml(it, i) {
        return `<div class="card flat" data-i="${i}">
            <div class="row"><b class="grow">Thuốc ${i + 1}</b>${items.length > 1 ? `<button type="button" class="btn sm danger" data-act="rm" data-i="${i}">Xoá</button>` : ''}</div>
            <div class="field"><label>Tên thuốc (tìm trong danh mục hoặc gõ tên trên đơn)</label>
                <input data-f="drug_name" list="drug-list-${i}" value="${esc(it.drug_name)}" placeholder="VD: Janumet, Hepazid…" autocomplete="off"><datalist id="drug-list-${i}"></datalist></div>
            <div class="field"><label>Cách dùng ghi trên đơn (chép nguyên văn)</label><input data-f="dose_text" value="${esc(it.dose_text)}" placeholder="VD: Sáng 1 viên, tối 1 viên — sau ăn"></div>
            <div class="field"><span class="label">Loại</span><div class="seg">${TYPES.map(([v, l]) => `<button type="button" data-act="type" data-i="${i}" data-v="${v}" class="${it.type === v ? 'on' : ''}">${l}</button>`).join('')}</div></div>
            ${it.type === 'supply' ? '' : `<div class="field"><span class="label">Giờ dùng — chọn các thời điểm, ghi lượng mỗi lần như trên đơn</span>
                ${SLOTS.map((s) => `<div class="row" style="margin:4px 0"><label class="check grow" style="padding:4px 0"><input type="checkbox" data-act="slot" data-i="${i}" data-k="${s.k}" ${s.k in it.slots ? 'checked' : ''}>${s.label}</label>
                    ${s.k in it.slots ? `<input style="width:110px" class="inline-amount" data-amount="${s.k}" data-i="${i}" value="${esc(it.slots[s.k])}" placeholder="1 viên">` : ''}</div>`).join('')}
                ${it.fixed.map((f, fi) => `<div class="row" style="margin:4px 0"><input type="time" data-fixed-time="${fi}" data-i="${i}" value="${esc(f.time)}"><input style="width:110px" data-fixed-amount="${fi}" data-i="${i}" value="${esc(f.amount)}" placeholder="1 viên"><button type="button" class="btn sm ghost" data-act="rm-fixed" data-i="${i}" data-fi="${fi}">✕</button></div>`).join('')}
                <button type="button" class="link-btn small" data-act="add-fixed" data-i="${i}">+ Giờ cố định (VD: 12:30 mỗi ngày)</button></div>`}
            <div class="grid3">
                <div class="field"><label>SL kê</label><input data-f="prescribed_quantity" inputmode="decimal" value="${esc(it.prescribed_quantity)}"></div>
                <div class="field"><label>SL đã mua</label><input data-f="purchased_quantity" inputmode="decimal" value="${esc(it.purchased_quantity)}" placeholder="= SL kê"></div>
                <div class="field"><label>Đơn vị</label><input data-f="quantity_unit" value="${esc(it.quantity_unit)}"></div>
            </div>
            <div class="grid2">
                <div class="field"><label>Dùng mỗi ngày (đơn vị)</label><input data-f="units_per_day" inputmode="decimal" value="${esc(it.units_per_day)}" placeholder="VD 2"><span class="hint">Chỉ để tính ngày hết thuốc.</span></div>
                <label class="check" style="align-self:center"><input type="checkbox" data-f="is_long_term" ${it.is_long_term ? 'checked' : ''}>Dùng lâu dài</label>
            </div>
        </div>`;
    }

    function draw() {
        container.innerHTML = `${items.map(itemHtml).join('')}<button type="button" class="btn ghost block" data-act="add">+ Thêm thuốc</button>`;
    }
    draw();

    let searchTimer = null;
    container.addEventListener('input', (e) => {
        const card = e.target.closest('[data-i]');
        if (!card) return;
        const it = items[Number(card.dataset.i)];
        const f = e.target.dataset.f;
        if (f === 'is_long_term') it.is_long_term = e.target.checked;
        else if (f) it[f] = e.target.value;
        if (e.target.dataset.amount) it.slots[e.target.dataset.amount] = e.target.value;
        if (e.target.dataset.fixedTime !== undefined) it.fixed[Number(e.target.dataset.fixedTime)].time = e.target.value;
        if (e.target.dataset.fixedAmount !== undefined) it.fixed[Number(e.target.dataset.fixedAmount)].amount = e.target.value;
        if (f === 'drug_name') {
            it.drug_id = null;
            clearTimeout(searchTimer);
            const q = e.target.value.trim();
            if (q.length < 2) return;
            searchTimer = setTimeout(async () => {
                try {
                    const drugs = await api(`/drugs?q=${encodeURIComponent(q)}`);
                    const list = Array.isArray(drugs) ? drugs : drugs.data || [];
                    card.querySelector('datalist').innerHTML = list.map((d) => `<option value="${esc(`${d.brand_name} ${d.strength || ''}`.trim())}">${esc(d.active_ingredient || '')}</option>`).join('');
                    const exact = list.find((d) => `${d.brand_name} ${d.strength || ''}`.trim() === q);
                    if (exact) { it.drug_id = exact.id; if (!it.quantity_unit) it.quantity_unit = exact.unit || ''; }
                } catch (_err) { /* tìm kiếm không bắt buộc */ }
            }, 250);
        }
    });
    container.addEventListener('change', (e) => {
        if (e.target.dataset.f === 'is_long_term') items[Number(e.target.closest('[data-i]').dataset.i)].is_long_term = e.target.checked;
    });

    delegate(container, {
        add: () => { items.push(blankItem()); draw(); },
        rm: (el) => { items.splice(Number(el.dataset.i), 1); draw(); },
        type: (el) => { items[Number(el.dataset.i)].type = el.dataset.v; draw(); },
        slot: (el) => {
            const it = items[Number(el.dataset.i)];
            if (el.checked) it.slots[el.dataset.k] = it.type === 'insulin' ? '' : (it.type === 'topical' ? 'Bôi vùng đau' : '1 viên');
            else delete it.slots[el.dataset.k];
            draw();
        },
        'add-fixed': (el) => { items[Number(el.dataset.i)].fixed.push({ time: '', amount: '1 viên' }); draw(); },
        'rm-fixed': (el) => { items[Number(el.dataset.i)].fixed.splice(Number(el.dataset.fi), 1); draw(); },
    });

    return {
        getItems: () => items.filter((it) => it.drug_name.trim()).map(toApiItem),
        raw: items,
    };
}

/* ================= Màn Nhập đơn mới ================= */
export async function renderRxNew(ctx) {
    const pid = ctx.patient.id;
    const draft = ctx.store.get('rxDraft');
    ctx.store.set('rxDraft', null);
    const screen = ctx.render({
        title: 'Nhập đơn thuốc',
        back: draft?.back || '/plan',
        tab: 'plan',
        body: `<form id="rx-form" novalidate>
            <div class="card lift">
                <div class="field"><label for="rx-doc">Bác sĩ kê đơn</label><input id="rx-doc" value="${esc(draft?.doctor_name || '')}" placeholder="VD: TS Nguyễn Thị Thanh Thủy (Nội tiết)"></div>
                <div class="grid3">
                    <div class="field"><label for="rx-date">Ngày kê</label><input id="rx-date" type="date" value="${draft?.prescribed_at || todayVN()}"></div>
                    <div class="field"><label for="rx-start">Bắt đầu dùng</label><input id="rx-start" type="date" value="${todayVN()}"></div>
                    <div class="field"><label for="rx-end">Kết thúc (nếu có)</label><input id="rx-end" type="date"></div>
                </div>
                <p class="small muted" style="margin:0">Liều và số lượng ghi đúng như trên đơn. Ứng dụng chỉ sắp giờ nhắc.</p>
            </div>
            <div id="editor"></div>
            <div class="sec-title"><h2>Xem trước lịch một ngày</h2><button type="button" class="btn sm ghost" data-act="preview">Xem trước</button></div>
            <div class="card" id="preview"><div class="empty">Chạm “Xem trước” để kiểm tra giờ trước khi lưu.</div></div>
            <button class="btn block" type="submit">Lưu đơn và cập nhật lịch</button>
        </form>`,
    });

    const editor = mountRxEditor(screen.querySelector('#editor'), draft?.items || []);

    delegate(screen, {
        preview: async () => {
            const items = editor.getItems();
            if (!items.length) return toast('Nhập ít nhất một thuốc.', 'bad');
            try {
                const res = await api(`/patients/${pid}/prescriptions/preview`, { method: 'POST', body: { items, date: screen.querySelector('#rx-start').value || todayVN() } });
                screen.querySelector('#preview').innerHTML = previewHtml(res.items, res.warnings);
            } catch (error) { toast(error.message, 'bad'); }
        },
    });

    screen.querySelector('#rx-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const items = editor.getItems();
        if (!items.length) return toast('Nhập ít nhất một thuốc.', 'bad');
        const noTime = items.filter((i) => !i.usage_rule);
        if (noTime.length && !await confirmDialog(`${noTime.map((i) => i.drug_name).join(', ')} chưa có giờ dùng nên sẽ không được nhắc. Vẫn lưu?`, { ok: 'Vẫn lưu' })) return;
        try {
            await api(`/patients/${pid}/prescriptions`, { method: 'POST', body: {
                doctor_name: screen.querySelector('#rx-doc').value.trim() || null,
                prescribed_at: screen.querySelector('#rx-date').value,
                starts_at: screen.querySelector('#rx-start').value,
                ends_at: screen.querySelector('#rx-end').value || null,
                items,
                document_id: draft?.document_id || null,
            } });
            toast('Đã lưu đơn. Lịch đã được cập nhật.');
            ctx.go(draft?.back || '/plan');
        } catch (error) { toast(error.message, 'bad'); }
    });
}

/* ================= Màn Chụp đơn (AI đọc, người dùng duyệt từng dòng) ================= */
export async function renderRxScan(ctx) {
    const pid = ctx.patient.id;
    const screen = ctx.render({
        title: 'Chụp đơn thuốc',
        back: '/plan',
        tab: 'plan',
        wide: true,
        body: `<div class="card lift">
                <p class="small ink2" style="margin-top:0">AI chỉ <b>đề xuất</b>. Bạn phải kiểm tra và xác nhận từng dòng với ảnh gốc trước khi lưu. Che số CCCD / BHYT trước khi chụp.</p>
                <div class="field"><label for="scan-file">Ảnh đơn thuốc</label><input id="scan-file" type="file" accept="image/*" capture="environment"></div>
                <button class="btn block" data-act="read">Đọc đơn</button>
            </div>
            <div class="scan" id="scan"></div>`,
    });

    let draft = null;
    let lines = [];
    let imageUrl = null;

    function drawLines() {
        const allDecided = lines.every((l) => l.state);
        screen.querySelector('#scan').innerHTML = `<div class="card"><h3>Ảnh gốc</h3><img class="scan-img" src="${imageUrl}" alt="Ảnh đơn thuốc vừa chụp"></div>
            <div class="card"><h3>Đề xuất của AI — xác nhận từng dòng</h3>
            ${lines.map((l, i) => `<div class="scan-line ${l.state === 'ok' ? 'ok' : l.state === 'skip' ? 'skip' : ''}">
                <div class="field"><label>Tên thuốc</label><input data-li="${i}" data-f="drug_name" value="${esc(l.drug_name)}"></div>
                <div class="field"><label>Cách dùng (nguyên văn)</label><input data-li="${i}" data-f="dose_text" value="${esc(l.dose_text)}"></div>
                <div class="field"><label>Số lượng</label><input data-li="${i}" data-f="quantity" value="${esc(l.quantity)}"></div>
                <div class="small ${l.matched ? 'muted' : ''}" style="${l.matched ? '' : 'color:var(--warn)'}">${l.matched ? '✓ Có trong danh mục thuốc' : '⚠ Không khớp danh mục — kiểm tra kỹ tên thuốc'}</div>
                <div class="row" style="margin-top:8px"><button class="btn sm" data-act="ok" data-li="${i}">${l.state === 'ok' ? '✓ Đã xác nhận' : 'Xác nhận dòng này'}</button><button class="btn sm ghost" data-act="skip" data-li="${i}">${l.state === 'skip' ? 'Đã bỏ' : 'Bỏ dòng'}</button></div>
            </div>`).join('')}
            <button class="btn block" data-act="done" ${allDecided && lines.some((l) => l.state === 'ok') ? '' : 'disabled'}>Tiếp tục: chọn giờ dùng</button>
            ${allDecided ? '' : '<p class="small muted">Cần xác nhận hoặc bỏ tất cả các dòng.</p>'}</div>`;
    }

    screen.addEventListener('input', (e) => {
        const i = e.target.dataset.li;
        if (i === undefined || !e.target.dataset.f) return;
        lines[Number(i)][e.target.dataset.f] = e.target.value;
        lines[Number(i)].state = null;
    });

    delegate(screen, {
        read: async () => {
            const file = screen.querySelector('#scan-file').files[0];
            if (!file) return toast('Chọn ảnh đơn thuốc.', 'bad');
            imageUrl = URL.createObjectURL(file);
            const base64 = await new Promise((resolve) => { const r = new FileReader(); r.onload = () => resolve(String(r.result).split(',')[1]); r.readAsDataURL(file); });
            try {
                const res = await api(`/patients/${pid}/ai-prescription-drafts`, { method: 'POST', body: { image_base64: base64 } });
                draft = res.draft_id;
                const matched = await api(`/patients/${pid}/ai-prescription-drafts/${draft}/match-drugs`, { method: 'POST' }).then((r) => r.data).catch(() => res.suggestions);
                lines = (matched || []).map((s) => ({ drug_name: s.drug_name || '', dose_text: s.dosage_instructions || s.dose_text || '', quantity: s.quantity || '', matched: !!s.matched_drug_id, drug_id: s.matched_drug_id || null, state: null }));
                if (!lines.length) return toast('AI không đọc được dòng thuốc nào. Hãy nhập tay.', 'bad');
                drawLines();
            } catch (error) { toast(error.message, 'bad'); }
        },
        ok: (el) => { lines[Number(el.dataset.li)].state = 'ok'; drawLines(); },
        skip: (el) => { lines[Number(el.dataset.li)].state = 'skip'; drawLines(); },
        done: async () => {
            try {
                await api(`/patients/${pid}/ai-prescription-drafts/${draft}/confirm`, { method: 'POST', body: { confirmed_lines: lines.map((l, index) => ({ index, confirmed: l.state === 'ok' })) } });
            } catch (error) { return toast(error.message, 'bad'); }
            const items = lines.filter((l) => l.state === 'ok').map((l) => {
                const m = String(l.quantity).match(/([\d.,]+)\s*(.*)/);
                return { ...blankItem(), drug_id: l.drug_id, drug_name: l.drug_name, dose_text: l.dose_text, prescribed_quantity: m ? m[1] : '', quantity_unit: m ? (m[2] || 'viên') : 'viên' };
            });
            ctx.store.set('rxDraft', { items });
            toast('Đã xác nhận. Chọn giờ dùng cho từng thuốc rồi lưu.');
            ctx.go('/rx/new');
        },
    });
}
