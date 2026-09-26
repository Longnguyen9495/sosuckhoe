/** Màn "Hôm nay" + bảng ghi chỉ số nhanh (nút +). */
import { api } from '../core/api.js';
import { todayVN, addDays, dm, longDate, parseDate, WD, vn, parseNum, nowTimeVN, diffDays, greeting } from '../core/format.js';
import { esc, delegate, skeleton, errorBox } from '../ui/dom.js';
import { openSheet, closeSheet, toast, confirmDialog } from '../ui/shell.js';
import { EVENT_META, TYPE_TAG, POINTS, GLUCOSE_POINTS, SYMPTOMS, levelChip, readingText, readingLevel } from './common.js';

const SESSIONS = [['Sáng', '00:00', '10:59'], ['Trưa', '11:00', '14:59'], ['Chiều', '15:00', '17:59'], ['Tối', '18:00', '23:59']];

export async function renderToday(ctx, params = {}) {
    const today = todayVN();
    const date = params.date || ctx.store.get('date') || today;
    ctx.store.set('date', date);
    const isToday = date === today;

    const screen = ctx.render({
        title: isToday ? `${greeting()}!` : `Lịch ngày ${dm(date)}`,
        sub: longDate(date),
        tab: 'today',
        body: `<section id="today-screen">
            <div class="card lift" id="overview-card">${skeleton(2)}</div>
            <div class="strip" id="strip"></div>
            <div id="alerts-card"></div>
            <div class="sec-title"><h2>Lịch trong ngày</h2><span id="phase-label"></span></div>
            <div id="schedule-card">${skeleton(5)}</div>
            <div class="sec-title"><h2>Chỉ số đã đo</h2><span>theo ngưỡng của người bệnh</span></div>
            <div class="card" id="readings-card"></div>
            <div class="sec-title"><h2>Uống nước</h2><span>≥ 2 lít · 8 cốc 250 ml</span></div>
            <div class="card" id="water-card"></div>
            <div class="sec-title"><h2>Dấu hiệu bất thường hôm nay</h2><span>có thì đánh dấu</span></div>
            <div class="card" id="symptom-card"></div>
            <div class="sec-title"><h2>Ghi chú</h2></div>
            <div class="card"><div class="field" style="margin:0"><label class="sr-only" for="note">Ghi chú trong ngày</label><textarea id="note" placeholder="Ví dụ: ăn ít bữa trưa, đau vai giảm, tên thuốc huyết áp đang uống…"></textarea></div></div>
        </section>`,
    });

    const pid = ctx.patient.id;
    let day;
    let calendar = { days: [] };

    async function load() {
        const month = date.slice(0, 7);
        [day, calendar] = await Promise.all([
            api(`/patients/${pid}/day/${date}`).then((r) => r.data),
            api(`/patients/${pid}/calendar?month=${month}&today=${today}`).then((r) => r.data).catch(() => ({ days: [] })),
        ]);
    }

    try {
        await load();
    } catch (error) {
        screen.querySelector('#schedule-card').innerHTML = errorBox(error.message);
        delegate(screen, { retry: () => ctx.refresh() });
        return;
    }

    const cal = Object.fromEntries((calendar.days || []).map((d) => [d.date, d]));
    const byKey = () => Object.fromEntries(day.items.map((i) => [i.key, i]));

    /* ----- Tổng quan ----- */
    function drawOverview() {
        const s = day.summary;
        const fasting = day.items.find((i) => i.point === 'fasting')?.reading;
        const bp = day.items.find((i) => i.point === 'bp_morning')?.reading || day.readings.find((r) => r.type === 'blood_pressure');
        screen.querySelector('#overview-card').innerHTML = `<div class="summary">
            <div class="ring" style="--p:${s.percent ?? 0}" role="img" aria-label="Hoàn thành ${s.percent ?? 0}%"><div><b>${s.percent === null ? '—' : `${s.percent}%`}</b><small>${s.total ? `${s.done}/${s.total}` : 'chưa có việc'}</small></div></div>
            <div class="stats">
                <div class="stat"><small>ĐH lúc đói</small><b>${fasting ? vn(fasting.values.value) : '—'}</b> ${fasting ? levelChip(readingLevel(fasting)) : ''}</div>
                <div class="stat"><small>Huyết áp sáng</small><b>${bp ? `${bp.values.systolic}/${bp.values.diastolic}` : '—'}</b></div>
                <div class="stat"><small>Nước uống</small><b>${day.day_log.water_cups}/8</b> <span class="small muted">cốc</span></div>
            </div>
        </div>`;
        screen.querySelector('#phase-label').textContent = day.monitoring_phase ? day.monitoring_phase.label : 'Chạm ô vuông để đánh dấu';
    }

    /* ----- Dải ngày ----- */
    function drawStrip() {
        let html = '';
        for (let i = -3; i <= 10; i++) {
            const d = addDays(date, i);
            const info = cal[d];
            const dt = parseDate(d);
            html += `<button class="day ${d === date ? 'sel' : ''} ${d === today ? 'today' : ''}" data-act="day" data-date="${d}" aria-label="${longDate(d)}">
                ${info?.events?.length ? '<span class="ev"></span>' : ''}<small>${WD[dt.getDay()]}</small><b>${dt.getDate()}</b>
                <div class="bar"><i style="width:${info?.percent ?? 0}%"></i></div></button>`;
        }
        const strip = screen.querySelector('#strip');
        strip.innerHTML = html;
        strip.querySelector('.sel')?.scrollIntoView({ inline: 'center', block: 'nearest' });
    }

    /* ----- Cảnh báo + mốc ----- */
    function drawAlerts() {
        let html = '';
        day.readings.filter((r) => readingLevel(r) === 'red').forEach((r) => {
            const low = r.type === 'blood_glucose' && Number(r.values.value) < 3.9;
            html += `<div class="alert bad"><div class="ico">!</div><div><b>${r.type === 'blood_pressure' ? 'Huyết áp' : 'Đường huyết'} ${esc(readingText(r))} — ngoài ngưỡng an toàn</b>
                <p>${low ? 'Nghi hạ đường huyết: ăn ngay 15 g đường nhanh (3–4 viên đường hoặc 150 ml nước cam), đo lại sau 15 phút. Lơ mơ, không tỉnh: gọi 115.' : 'Ghi lại và báo bác sĩ điều trị. Có triệu chứng nặng: gọi 115.'}</p></div></div>`;
        });
        day.day_log.symptoms.map((k) => SYMPTOMS.find((s) => s.k === k)).filter(Boolean).forEach((s) => {
            html += `<div class="alert bad"><div class="ico">!</div><div><b>${esc(s.t)}</b><p>${esc(s.a)}</p></div></div>`;
        });
        day.events.forEach((e) => {
            const m = EVENT_META[e.type] || EVENT_META.other;
            const done = e.status === 'completed';
            html += `<div class="alert ${m.cls}"><div class="ico">${m.icon}</div><div class="grow"><b>${esc(e.title)}${e.due_date ? ` <span class="chip y">hạn ${dm(e.due_date)}</span>` : ''}</b><p>${esc(e.description || '')}</p>
                <button class="btn sm ${done ? 'ghost' : ''}" style="margin-top:8px" data-act="event" data-id="${esc(e.id)}" data-status="${done ? 'pending' : 'completed'}">${done ? '✓ Đã xong · bỏ đánh dấu' : 'Đánh dấu đã xong'}</button></div></div>`;
        });
        const upcoming = (calendar.days || []).filter((d) => d.date > date && diffDays(date, d.date) <= 3).flatMap((d) => d.events.filter((e) => e.type === 'appointment').map((e) => ({ ...e, date: d.date })));
        upcoming.forEach((e) => {
            html += `<div class="alert kham"><div class="ico">⏰</div><div><b>Sắp tới (${dm(e.date)}): ${esc(e.title)}</b><p>Chuẩn bị giấy tờ, đơn cũ và sổ theo dõi.</p></div></div>`;
        });
        if (!day.items.some((i) => i.countable)) {
            const next = (calendar.days || []).find((d) => d.date > date && d.total > 0);
            html += `<div class="alert good"><div class="ico">ℹ️</div><div><b>Ngày này chưa có lịch dùng thuốc hay đo</b><p>${next ? `Lịch bắt đầu từ ${longDate(next.date)}.` : 'Tải ảnh đơn thuốc để AI đọc và lập lịch uống thuốc.'}</p>
                ${next ? `<button class="btn sm" style="margin-top:8px" data-act="day" data-date="${next.date}">Xem ngày ${dm(next.date)}</button>` : '<button class="btn sm" style="margin-top:8px" data-act="nav" data-to="/upload">📷 Tải ảnh đơn thuốc</button>'}</div></div>`;
        }
        screen.querySelector('#alerts-card').innerHTML = html;
    }

    /* ----- Dòng thời gian ----- */
    function itemHtml(it) {
        if (it.type === 'meal') {
            return `<div class="item meal"><div class="time">${it.time}</div><div class="body"><div class="t">🍽️ ${esc(it.title)} <span class="small muted">— giờ cố định</span></div>${it.diet_note ? `<div class="s">Gợi ý: ${esc(it.diet_note)}</div>` : ''}</div></div>`;
        }
        if (it.type === 'measurement') {
            const r = it.reading;
            const isBp = it.metric === 'blood_pressure';
            const tag = isBp ? '<span class="tag bp">HUYẾT ÁP</span>' : '<span class="tag glu">ĐƯỜNG HUYẾT</span>';
            const target = isBp ? 'Mục tiêu dưới 130/80 · mạch 60–100' : (it.threshold_context === 'post_meal_2h' ? 'Mục tiêu dưới 10 mmol/L' : 'Mục tiêu 4,4 – 7,2 mmol/L');
            const inputs = isBp
                ? `<input inputmode="numeric" placeholder="Tâm thu" aria-label="Huyết áp tâm thu" data-f="systolic" value="${esc(r?.values?.systolic ?? '')}">
                   <span>/</span><input inputmode="numeric" placeholder="Tâm trương" aria-label="Huyết áp tâm trương" data-f="diastolic" value="${esc(r?.values?.diastolic ?? '')}">
                   <input inputmode="numeric" placeholder="Mạch" aria-label="Mạch" data-f="heart_rate" value="${esc(r?.values?.heart_rate ?? '')}">`
                : `<input inputmode="decimal" placeholder="mmol/L" aria-label="${esc(it.title)}" data-f="value" value="${r ? esc(vn(r.values.value)) : ''}">`;
            return `<div class="item ${it.done ? 'done' : ''}" data-key="${esc(it.key)}"><div class="time">${it.time}</div><div class="body">
                <div class="t">${tag}${esc(it.title)}</div><div class="s">${target}</div>
                <div class="inline-in">${inputs}<button class="btn sm ghost" data-act="save-measure" data-key="${esc(it.key)}">${r ? 'Sửa' : 'Lưu'}</button>${r ? levelChip(readingLevel(r)) : ''}</div>
            </div><div class="tick static" aria-hidden="true">${it.done ? '✓' : ''}</div></div>`;
        }
        return `<div class="item ${it.done ? 'done' : ''}"><div class="time">${it.time}</div><div class="body">
            <div class="t">${TYPE_TAG[it.type] || ''}${esc(it.title)}${it.amount_text ? ` · <b>${esc(it.amount_text)}</b>` : ''}</div>
            <div class="s">${esc(it.dose_text || '')}</div></div>
            <button class="tick" data-act="tick" data-key="${esc(it.key)}" aria-pressed="${it.done}" aria-label="${it.done ? 'Bỏ đánh dấu' : 'Đánh dấu đã làm'}: ${esc(it.title)} lúc ${it.time}">✓</button></div>`;
    }

    function drawSchedule() {
        const box = screen.querySelector('#schedule-card');
        if (!day.items.length) {
            box.innerHTML = '<div class="empty">Chưa có lịch cho ngày này.</div>';
            return;
        }
        box.innerHTML = SESSIONS.map(([name, from, to]) => {
            const items = day.items.filter((i) => i.time >= from && i.time <= to);
            return items.length ? `<div class="session-h">${name}</div>${items.map(itemHtml).join('')}` : '';
        }).join('');
    }

    function drawReadings() {
        const box = screen.querySelector('#readings-card');
        box.innerHTML = day.readings.length
            ? `<table class="tbl"><tbody>${day.readings.map((r) => `<tr><td class="v">${esc((r.measured_at || '').slice(11, 16))}</td><td>${esc(POINTS[r.context] || (r.type === 'blood_pressure' ? 'Huyết áp' : 'Đường huyết'))}</td><td class="v">${esc(readingText(r))}</td><td>${levelChip(readingLevel(r))}</td></tr>`).join('')}</tbody></table>`
            : '<div class="empty">Chưa đo lần nào trong ngày. Nhập ngay trên dòng thời gian hoặc nút +.</div>';
    }

    function drawWater() {
        const cups = day.day_log.water_cups;
        screen.querySelector('#water-card').innerHTML = `<div class="water">${Array.from({ length: 8 }, (_, i) => `<button class="cup ${i < cups ? 'on' : ''}" data-act="water" data-n="${i + 1}" aria-label="${i + 1} cốc"></button>`).join('')}</div>
            <p class="small muted" style="margin:8px 0 0">Bác sĩ dặn uống nhiều nước. Người có bệnh tim, thận nên hỏi bác sĩ lượng phù hợp.</p>`;
    }

    function drawSymptoms() {
        const on = new Set(day.day_log.symptoms);
        screen.querySelector('#symptom-card').innerHTML = `<div class="sym">${SYMPTOMS.map((s) => `<label class="${on.has(s.k) ? 'on' : ''}"><input type="checkbox" data-sym="${s.k}" ${on.has(s.k) ? 'checked' : ''}>${esc(s.t)}</label>`).join('')}</div>`;
    }

    function drawAll() {
        drawOverview(); drawStrip(); drawAlerts(); drawSchedule(); drawReadings(); drawWater(); drawSymptoms();
        screen.querySelector('#note').value = day.day_log.note || '';
    }
    drawAll();

    async function reload() {
        day = (await api(`/patients/${pid}/day/${date}`)).data;
        drawAll();
    }

    async function saveDayMeta(meta) {
        await api(`/patients/${pid}/logs`, { method: 'POST', body: { log_date: date, meta } });
    }

    delegate(screen, {
        day: (el) => ctx.go(`/today/${el.dataset.date}`),
        tick: async (el) => {
            const it = byKey()[el.dataset.key];
            it.done = !it.done;
            day.summary.done += it.done ? 1 : -1;
            day.summary.percent = day.summary.total ? Math.round((day.summary.done * 100) / day.summary.total) : null;
            drawSchedule(); drawOverview();
            try {
                await api(`/patients/${pid}/logs`, { method: 'POST', body: { log_date: date, schedule_item_id: it.schedule_item_id, completed: it.done } });
            } catch (error) {
                toast(error.message, 'bad');
                await reload();
            }
        },
        'save-measure': async (el) => {
            const it = byKey()[el.dataset.key];
            const row = el.closest('.item');
            const read = (f) => row.querySelector(`[data-f="${f}"]`)?.value;
            const isBp = it.metric === 'blood_pressure';
            const values = isBp
                ? { systolic: parseInt(read('systolic'), 10), diastolic: parseInt(read('diastolic'), 10), ...(read('heart_rate') ? { heart_rate: parseInt(read('heart_rate'), 10) } : {}) }
                : { value: parseNum(read('value')) };
            if (isBp ? !(values.systolic && values.diastolic) : values.value === null) return toast('Nhập số đo trước khi lưu.', 'bad');
            const time = date === today ? nowTimeVN() : it.time;
            try {
                const res = await api(`/patients/${pid}/readings`, { method: 'POST', body: { type: it.metric, context: it.point, measured_at: `${date} ${time}:00`, values } });
                if (res.alert) toast(res.alert.replace('[NHÁP — CẦN DUYỆT] ', ''), 'bad');
                else toast('Đã lưu chỉ số.');
                await reload();
            } catch (error) {
                toast(error.message, 'bad');
            }
        },
        water: async (el) => {
            const n = Number(el.dataset.n);
            day.day_log.water_cups = day.day_log.water_cups === n ? n - 1 : n;
            drawWater(); drawOverview();
            try { await saveDayMeta({ water_cups: day.day_log.water_cups }); } catch (error) { toast(error.message, 'bad'); }
        },
        event: async (el) => {
            try {
                await api(`/patients/${pid}/events/${el.dataset.id}`, { method: 'PATCH', body: { status: el.dataset.status } });
                await reload();
            } catch (error) { toast(error.message, 'bad'); }
        },
        retry: () => ctx.refresh(),
    });

    screen.addEventListener('change', async (event) => {
        const box = event.target.closest('[data-sym]');
        if (box) {
            const set = new Set(day.day_log.symptoms);
            if (box.checked) set.add(box.dataset.sym); else set.delete(box.dataset.sym);
            day.day_log.symptoms = [...set];
            drawSymptoms(); drawAlerts();
            if (box.checked && (box.dataset.sym === 'conf' || box.dataset.sym === 'bp')) {
                await confirmDialog('Dấu hiệu nguy hiểm: gọi cấp cứu 115 ngay.', { ok: 'Đã hiểu' });
            }
            try { await saveDayMeta({ symptoms: day.day_log.symptoms }); } catch (error) { toast(error.message, 'bad'); }
        }
        if (event.target.id === 'note') {
            try { await saveDayMeta({ note: event.target.value }); toast('Đã lưu ghi chú.'); } catch (error) { toast(error.message, 'bad'); }
        }
    });
}

/* ================= Bảng ghi chỉ số nhanh (nút + giữa thanh tab) ================= */
export async function openQuickReading(ctx) {
    if (!ctx.patient) return;
    const date = ctx.store.get('date') || todayVN();
    const pid = ctx.patient.id;
    let planned = [];
    try {
        planned = (await api(`/patients/${pid}/day/${date}`)).data.items.filter((i) => i.type === 'measurement' && !i.done).map((i) => i.point);
    } catch (_e) { /* vẫn cho nhập */ }

    const firstGlucose = planned.find((p) => GLUCOSE_POINTS.includes(p)) || 'fasting';
    const sheet = openSheet(`<h3>Ghi chỉ số</h3>
        <form id="qr" novalidate>
            <div class="grid2">
                <div class="field"><label for="qr-date">Ngày</label><input type="date" id="qr-date" value="${date}"></div>
                <div class="field"><label for="qr-time">Giờ đo</label><input type="time" id="qr-time" value="${date === todayVN() ? nowTimeVN() : '07:00'}"></div>
            </div>
            <div class="field"><span class="label">Đường huyết — thời điểm</span>
                <div class="seg" id="qr-point">${GLUCOSE_POINTS.map((p) => `<button type="button" data-p="${p}" class="${p === firstGlucose ? 'on' : ''}">${POINTS[p]}${planned.includes(p) ? ' ★' : ''}</button>`).join('')}</div>
            </div>
            <div class="field"><label for="qr-g">Đường huyết (mmol/L)</label><input id="qr-g" inputmode="decimal" placeholder="VD 6,5"></div>
            <div class="field"><span class="label">Huyết áp — buổi</span><div class="seg" id="qr-bp-point"><button type="button" data-p="bp_morning" class="on">Sáng${planned.includes('bp_morning') ? ' ★' : ''}</button><button type="button" data-p="bp_evening">Tối${planned.includes('bp_evening') ? ' ★' : ''}</button></div></div>
            <div class="grid3">
                <div class="field"><label for="qr-sys">Tâm thu</label><input id="qr-sys" inputmode="numeric" placeholder="125"></div>
                <div class="field"><label for="qr-dia">Tâm trương</label><input id="qr-dia" inputmode="numeric" placeholder="78"></div>
                <div class="field"><label for="qr-hr">Mạch</label><input id="qr-hr" inputmode="numeric" placeholder="82"></div>
            </div>
            <p class="small muted">★ = cần đo theo lịch hôm đó. Bỏ trống ô nào thì không lưu ô đó.</p>
            <button class="btn block" type="submit">Lưu chỉ số</button>
        </form>`);

    const pick = (groupId) => sheet.querySelector(`#${groupId} .on`)?.dataset.p;
    sheet.querySelectorAll('.seg').forEach((seg) => seg.addEventListener('click', (e) => {
        const b = e.target.closest('button');
        if (!b) return;
        seg.querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b));
    }));

    sheet.querySelector('#qr').addEventListener('submit', async (event) => {
        event.preventDefault();
        const d = sheet.querySelector('#qr-date').value || date;
        const measuredAt = `${d} ${sheet.querySelector('#qr-time').value || '07:00'}:00`;
        const g = parseNum(sheet.querySelector('#qr-g').value);
        const sys = parseInt(sheet.querySelector('#qr-sys').value, 10);
        const dia = parseInt(sheet.querySelector('#qr-dia').value, 10);
        const hr = parseInt(sheet.querySelector('#qr-hr').value, 10);
        if (g === null && !(sys && dia)) return toast('Nhập ít nhất một chỉ số.', 'bad');
        const alerts = [];
        try {
            if (g !== null) {
                const res = await api(`/patients/${pid}/readings`, { method: 'POST', body: { type: 'blood_glucose', context: pick('qr-point'), measured_at: measuredAt, values: { value: g } } });
                if (res.alert) alerts.push(res.alert);
            }
            if (sys && dia) {
                const res = await api(`/patients/${pid}/readings`, { method: 'POST', body: { type: 'blood_pressure', context: pick('qr-bp-point'), measured_at: measuredAt, values: { systolic: sys, diastolic: dia, ...(hr ? { heart_rate: hr } : {}) } } });
                if (res.alert) alerts.push(res.alert);
            }
            closeSheet();
            if (alerts.length) toast(alerts.map((a) => a.replace('[NHÁP — CẦN DUYỆT] ', '')).join(' '), 'bad');
            else toast('Đã lưu chỉ số.');
            ctx.store.set('date', d);
            ctx.refresh();
        } catch (error) {
            toast(error.message, 'bad');
        }
    });
}
