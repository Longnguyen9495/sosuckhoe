/** Màn "Lịch": lịch tháng, giai đoạn đo, biểu đồ, danh sách mốc, xuất CSV. */
import { api, apiBlob } from '../core/api.js';
import { todayVN, parseDate, monthLabel, dm, WD, addDays } from '../core/format.js';
import { esc, delegate, skeleton, errorBox } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { toast } from '../ui/shell.js';
import { drawChart } from '../ui/chart.js';
import { EVENT_META } from './common.js';

export async function renderCalendar(ctx) {
    const today = todayVN();
    const pid = ctx.patient.id;
    let month = (ctx.store.get('calMonth') || ctx.store.get('date') || today).slice(0, 7);

    const screen = ctx.render({
        title: 'Lịch theo dõi',
        sub: 'Chạm một ngày để mở lịch chi tiết',
        tab: 'calendar',
        body: `<div class="card lift" id="month-card">${skeleton(4)}</div>
            <div class="sec-title"><h2>Giai đoạn đo</h2><span>theo bác sĩ nội tiết</span></div>
            <div class="phase" id="phases">${skeleton(1)}</div>
            <div class="sec-title"><h2>Đường huyết lúc đói</h2><span>vùng xanh = mục tiêu 4,4–7,2</span></div>
            <div class="card"><div class="chart" id="chart-g"></div></div>
            <div class="sec-title"><h2>Huyết áp buổi sáng</h2><span>mục tiêu dưới 130/80</span></div>
            <div class="card"><div class="chart" id="chart-b"></div></div>
            <div class="sec-title"><h2>Các mốc quan trọng</h2><span id="ev-count"></span></div>
            <div class="card" id="events"></div>
            <div class="sec-title"><h2>Dữ liệu cho bác sĩ</h2></div>
            <div class="card row wrap-row"><button class="btn ghost sm" data-act="csv">${icon('download', { size: 16 })} Bảng chỉ số (.csv)</button><span class="small muted">Mang theo khi tái khám hoặc gửi cho bác sĩ.</span></div>`,
    });

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
                cells += `<button class="cell ${d.date === today ? 'today' : ''} ${d.date === ctx.store.get('date') ? 'sel' : ''}" data-act="open-day" data-date="${d.date}" aria-label="${esc(label)}">
                    <span>${n}</span><span class="dots">${d.events.slice(0, 3).map((e) => `<i style="background:${(EVENT_META[e.type] || EVENT_META.other).color}"></i>`).join('')}</span>
                    <span class="pbar"><i style="width:${d.percent ?? 0}%;background:${color}"></i></span></button>`;
            });
            box.innerHTML = `<div class="cal-h"><button class="btn ghost sm" data-act="month" data-d="-1" aria-label="Tháng trước">‹</button><b>${monthLabel(month)}</b><button class="btn ghost sm" data-act="month" data-d="1" aria-label="Tháng sau">›</button></div>
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
            box.innerHTML = glucose.length
                ? glucose.map((p) => {
                    const cur = p.starts_at <= today && (!p.ends_at || p.ends_at >= today);
                    return `<div class="${cur ? 'cur' : ''}"><b>${esc(p.label)}</b><span class="muted small">${dm(p.starts_at)} → ${p.ends_at ? dm(p.ends_at) : '…'}</span><div style="margin-top:4px">${esc(p.description || '')}</div></div>`;
                }).join('')
                : '<div class="empty" style="grid-column:1/-1">Chưa có lịch đo. Bác sĩ hoặc người nhà có thể thêm trong Cài đặt → Ngưỡng.</div>';
        } catch (error) {
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

    async function drawEvents() {
        const box = screen.querySelector('#events');
        try {
            const { data } = await api(`/patients/${pid}/events`);
            screen.querySelector('#ev-count').textContent = `${data.length} mốc`;
            box.innerHTML = data.length ? data.map((e) => {
                const d = parseDate(e.event_date.slice(0, 10));
                const m = EVENT_META[e.type] || EVENT_META.other;
                const done = e.status === 'completed';
                return `<div class="ev-row ${done ? 'past' : ''}"><div class="datebox"><b>${d.getDate()}</b><small>TH${d.getMonth() + 1} · ${WD[d.getDay()]}</small></div>
                    <div class="grow"><b>${m.icon} ${esc(e.title)}</b>${e.due_date ? ` <span class="chip y">hạn ${dm(e.due_date.slice(0, 10))}</span>` : ''}${done ? ' <span class="chip good">Đã xong</span>' : ''}
                    <div class="small ink2" style="margin-top:2px">${esc(e.description || '')}</div>
                    <button class="link-btn small" data-act="event" data-id="${esc(e.id)}" data-status="${done ? 'pending' : 'completed'}">${done ? 'Bỏ đánh dấu' : 'Đánh dấu đã xong'}</button></div></div>`;
            }).join('') : '<div class="empty">Chưa có mốc nào.</div>';
        } catch (error) {
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
