/** Màn "Lịch": tóm tắt, lịch tháng, giai đoạn đo, biểu đồ, danh sách mốc, xuất CSV. */
import { api, apiBlob } from '../core/api.js';
import { todayVN, parseDate, monthLabel, dm, WD, addDays, diffDays } from '../core/format.js';
import { esc, delegate, skeleton, errorBox } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { toast } from '../ui/shell.js';
import { drawChart } from '../ui/chart.js';
import { EVENT_META, blockHead, sumTile } from './common.js';

export async function renderCalendar(ctx) {
    const today = todayVN();
    const pid = ctx.patient.id;
    let month = (ctx.store.get('calMonth') || ctx.store.get('date') || today).slice(0, 7);

    const screen = ctx.render({
        title: 'Lịch theo dõi',
        sub: 'Chạm một ngày để mở lịch chi tiết',
        tab: 'calendar',
        body: `<div class="card lift today-sum" id="cal-sum"></div>

            <section class="blk">
                ${blockHead('calendar', 'Lịch tháng', '<span class="blk-sub">chạm ngày để mở</span>')}
                <div class="card" id="month-card">${skeleton(4)}</div>
            </section>

            <section class="blk">
                ${blockHead('activity', 'Giai đoạn đo', '<span class="blk-sub">theo bác sĩ nội tiết</span>')}
                <div class="phase" id="phases">${skeleton(1)}</div>
            </section>

            <section class="blk">
                ${blockHead('droplet', 'Đường huyết lúc đói', '<span class="blk-sub">mmol/L · 60 ngày</span>')}
                <div class="card"><p class="chart-note"><i class="band-dot"></i>Vùng xanh: mục tiêu 4,4–7,2</p><div class="chart" id="chart-g"></div></div>
            </section>

            <section class="blk">
                ${blockHead('heart', 'Huyết áp buổi sáng', '<span class="blk-sub">mmHg · 60 ngày</span>')}
                <div class="card"><p class="chart-note">Nét đứt: mục tiêu dưới 130/80</p><div class="chart" id="chart-b"></div></div>
            </section>

            <section class="blk">
                ${blockHead('pin', 'Các mốc quan trọng', '<span class="blk-sub" id="ev-count"></span>')}
                <div class="card" id="events">${skeleton(3)}</div>
            </section>

            <section class="blk">
                ${blockHead('file-down', 'Dữ liệu cho bác sĩ')}
                <button class="act-row" data-act="csv"><span class="act-ic">${icon('download', { size: 18 })}</span><span class="grow"><b>Tải bảng chỉ số (.csv)</b><small>Mang theo khi tái khám hoặc gửi cho bác sĩ</small></span>${icon('chevron-right', { size: 18 })}</button>
            </section>`,
    });

    /* ----- Tóm tắt: % hoàn thành tháng · mốc tới · giai đoạn đo (mỗi ô điền khi dữ liệu về) ----- */
    const sum = { done: undefined, next: undefined, phase: undefined };
    function drawSummary() {
        const note = (text) => `<span class="small muted sum-line">${text}</span>`;
        const wait = ['—', note('…')];
        const done = sum.done === undefined ? wait : sum.done === null ? ['—', note('chưa có lịch')] : [`${sum.done}%`, `<span class="sum-bar"><i style="width:${sum.done}%"></i></span>`];
        const next = sum.next === undefined ? wait : sum.next ? [dm(sum.next.event_date.slice(0, 10)), note(esc(sum.next.title))] : ['—', note('không có')];
        // Tên giai đoạn do bác sĩ đặt có thể dài: số lớn là số ngày còn lại, tên để ở dòng phụ.
        const phase = sum.phase === undefined ? wait : sum.phase
            ? [sum.phase.ends_at ? `${diffDays(today, sum.phase.ends_at) + 1} ngày` : 'Đang đo', note(`${sum.phase.ends_at ? 'còn lại · ' : ''}${esc(sum.phase.label)}`)]
            : ['—', note('chưa có')];
        screen.querySelector('#cal-sum').innerHTML = `<div class="sum-grid">
            ${sumTile('check-circle', 'Hoàn thành', ...done)}
            ${sumTile('alarm', 'Mốc tới', ...next)}
            ${sumTile('activity', 'Giai đoạn đo', ...phase)}
        </div>`;
    }
    drawSummary();

    async function drawMonth() {
        const box = screen.querySelector('#month-card');
        box.innerHTML = skeleton(4);
        try {
            const { data } = await api(`/patients/${pid}/calendar?month=${month}&today=${today}`);
            const first = parseDate(`${month}-01`);
            const pad = (first.getDay() + 6) % 7;
            let cells = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'].map((w) => `<div class="wd">${w}</div>`).join('');
            cells += '<div class="cell pad"></div>'.repeat(pad);
            data.days.forEach((d) => {
                const n = parseDate(d.date).getDate();
                const color = d.percent === null ? 'transparent' : d.percent >= 80 ? 'var(--good)' : d.percent >= 40 ? 'var(--warn)' : 'var(--bad)';
                const label = `${n}/${month.slice(5)}${d.total ? `, ${d.total} việc` : ''}${d.percent !== null && d.total ? `, hoàn thành ${d.percent}%` : ''}${d.events.length ? `, ${d.events.map((e) => e.title).join('; ')}` : ''}`;
                cells += `<button class="cell ${d.date === today ? 'today' : ''} ${d.date === ctx.store.get('date') ? 'sel' : ''} ${d.date > today ? 'future' : ''}" data-act="open-day" data-date="${d.date}" aria-label="${esc(label)}">
                    <span>${n}</span><span class="dots">${d.events.slice(0, 3).map((e) => `<i style="background:${(EVENT_META[e.type] || EVENT_META.other).color}"></i>`).join('')}</span>
                    <span class="pbar"><i style="width:${d.percent ?? 0}%;background:${color}"></i></span></button>`;
            });
            // Trung bình % hoàn thành các ngày đã qua có lịch (ngày tương lai chưa tính).
            const past = data.days.filter((d) => d.date <= today && d.total && d.percent !== null);
            sum.done = past.length ? Math.round(past.reduce((s, d) => s + d.percent, 0) / past.length) : null;
            drawSummary();
            const isNow = month === today.slice(0, 7);
            box.innerHTML = `<div class="cal-h">
                    <button class="cal-nav" data-act="month" data-d="-1" aria-label="Tháng trước">${icon('chevron-left', { size: 20 })}</button>
                    <div class="cal-title"><b>${monthLabel(month)}</b>${isNow ? '' : '<button class="link-btn small" data-act="this-month">Về tháng này</button>'}</div>
                    <button class="cal-nav" data-act="month" data-d="1" aria-label="Tháng sau">${icon('chevron-right', { size: 20 })}</button></div>
                <div class="cal">${cells}</div>
                <div class="legend">${Object.entries(EVENT_META).filter(([k]) => k !== 'other').map(([, m]) => `<span><i style="background:${m.color}"></i>${m.label}</span>`).join('')}<span class="muted">Thanh dưới: % hoàn thành</span></div>`;
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    async function drawPhases() {
        const box = screen.querySelector('#phases');
        try {
            const { data } = await api(`/patients/${pid}/monitoring-plans`);
            const glucose = data.filter((p) => p.metric === 'blood_glucose');
            const isCur = (p) => p.starts_at <= today && (!p.ends_at || p.ends_at >= today);
            sum.phase = glucose.find(isCur) || null;
            drawSummary();
            box.innerHTML = glucose.length
                ? glucose.map((p) => {
                    const cur = isCur(p);
                    return `<div class="${cur ? 'cur' : ''}"><b>${esc(p.label)}</b>${cur ? '<span class="chip">đang áp dụng</span>' : ''}<span class="muted small" style="display:block">${dm(p.starts_at)} → ${p.ends_at ? dm(p.ends_at) : '…'}</span><div style="margin-top:4px">${esc(p.description || '')}</div></div>`;
                }).join('')
                : '<div class="empty" style="grid-column:1/-1">Chưa có lịch đo. Bác sĩ hoặc người nhà có thể thêm trong Cài đặt → Ngưỡng.</div>';
        } catch (error) {
            sum.phase = null;
            drawSummary();
            box.innerHTML = errorBox(error.message);
        }
    }

    async function drawCharts() {
        const from = addDays(today, -60);
        try {
            const [g, b] = await Promise.all([
                api(`/patients/${pid}/readings?type=blood_glucose&from=${from}&to=${today}`).then((r) => r.data),
                api(`/patients/${pid}/readings?type=blood_pressure&from=${from}&to=${today}`).then((r) => r.data),
            ]);
            const fasting = g.filter((r) => r.context === 'fasting' || r.context === 'pre_meal');
            drawChart(screen.querySelector('#chart-g'), [{ name: 'Lúc đói', color: 'var(--s1)', pts: fasting.map((r) => ({ x: r.measured_at.slice(0, 10), y: Number(r.values.value) })) }], { band: [4.4, 7.2], pad: 1, unit: 'mmol/L', aria: 'Đường huyết lúc đói theo ngày' });
            const morning = b.filter((r) => r.context !== 'bp_evening');
            drawChart(screen.querySelector('#chart-b'), [
                { name: 'Tâm thu', color: 'var(--s1)', pts: morning.map((r) => ({ x: r.measured_at.slice(0, 10), y: Number(r.values.systolic) })) },
                { name: 'Tâm trương', color: 'var(--s2)', pts: morning.map((r) => ({ x: r.measured_at.slice(0, 10), y: Number(r.values.diastolic) })) },
            ], { refs: [130, 80], pad: 8, unit: 'mmHg', endLabels: true, aria: 'Huyết áp buổi sáng theo ngày' });
        } catch (error) {
            screen.querySelector('#chart-g').innerHTML = errorBox(error.message);
        }
    }

    /** Mốc chưa xong luôn hiện (kể cả đã quá ngày); mốc đã xong gập lại ở cuối. */
    async function drawEvents() {
        const box = screen.querySelector('#events');
        try {
            const { data } = await api(`/patients/${pid}/events`);
            const row = (e) => {
                const day = e.event_date.slice(0, 10);
                const d = parseDate(day);
                const m = EVENT_META[e.type] || EVENT_META.other;
                const done = e.status === 'completed';
                return `<div class="ev-row ${done ? 'past' : ''}"><div class="datebox"><b>${d.getDate()}</b><small>TH${d.getMonth() + 1} · ${WD[d.getDay()]}</small></div>
                    <div class="grow"><div class="ev-type" style="--c:${m.color}">${m.icon}<span>${m.label}</span>${!done && day < today ? '<span class="chip warn">đã qua ngày</span>' : ''}${e.due_date ? `<span class="chip y">hạn ${dm(e.due_date.slice(0, 10))}</span>` : ''}</div>
                    <b>${esc(e.title)}</b>
                    ${e.description ? `<div class="small ink2" style="margin-top:2px">${esc(e.description)}</div>` : ''}
                    <button class="btn sm ${done ? 'ghost' : ''} ev-btn" data-act="event" data-id="${esc(e.id)}" data-status="${done ? 'pending' : 'completed'}">${done ? `${icon('check', { size: 16 })} Đã xong · bỏ đánh dấu` : 'Đánh dấu đã xong'}</button></div></div>`;
            };
            const open = data.filter((e) => e.status !== 'completed').sort((a, b) => a.event_date.localeCompare(b.event_date));
            const closed = data.filter((e) => e.status === 'completed');
            screen.querySelector('#ev-count').textContent = data.length ? `${open.length} chưa xong` : '';
            sum.next = open.find((e) => e.event_date.slice(0, 10) >= today) || null;
            drawSummary();
            box.innerHTML = data.length
                ? `${open.length ? open.map(row).join('') : '<p class="small muted" style="margin:0">Không còn mốc nào chưa xong.</p>'}
                    ${closed.length ? `<details class="fold-lite"><summary>${icon('check-circle', { size: 16 })} Đã xong (${closed.length})</summary>${closed.map(row).join('')}</details>` : ''}`
                : '<div class="empty">Chưa có mốc nào.</div>';
        } catch (error) {
            sum.next = null;
            drawSummary();
            box.innerHTML = errorBox(error.message);
        }
    }

    delegate(screen, {
        'open-day': (el) => { ctx.store.set('date', el.dataset.date); ctx.go(`/today/${el.dataset.date}`); },
        month: (el) => {
            const [y, m] = month.split('-').map(Number);
            const next = new Date(y, m - 1 + Number(el.dataset.d), 1);
            month = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`;
            ctx.store.set('calMonth', `${month}-01`);
            drawMonth();
        },
        'this-month': () => {
            month = today.slice(0, 7);
            ctx.store.set('calMonth', `${month}-01`);
            drawMonth();
        },
        event: async (el) => {
            try {
                await api(`/patients/${pid}/events/${el.dataset.id}`, { method: 'PATCH', body: { status: el.dataset.status } });
                drawEvents(); drawMonth();
            } catch (error) { toast(error.message, 'bad'); }
        },
        csv: async () => {
            try {
                const blob = await apiBlob(`/patients/${pid}/csv-export`);
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `chi-so-${today}.csv`;
                a.click();
                setTimeout(() => URL.revokeObjectURL(a.href), 1000);
            } catch (error) { toast(error.message, 'bad'); }
        },
        retry: () => ctx.refresh(),
    });

    drawMonth();
    drawPhases();
    drawCharts();
    drawEvents();
}
