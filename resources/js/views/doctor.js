/** Cổng bác sĩ (PLAN.md mục 6.2): danh sách theo cờ đỏ, hồ sơ, trả lời câu hỏi, nhận xét, báo cáo PDF. */
import { api, apiBlob } from '../core/api.js';
import { todayVN, addDays, dm, vn } from '../core/format.js';
import { esc, delegate, skeleton, errorBox } from '../ui/dom.js';
import { toast } from '../ui/shell.js';
import { drawChart } from '../ui/chart.js';
import { readingText, levelChip, readingLevel } from './common.js';

export async function renderDoctor(ctx) {
    const today = todayVN();
    const screen = ctx.render({
        title: 'Cổng bác sĩ',
        sub: ctx.tenant?.name || '',
        tab: false,
        wide: true,
        body: `<div class="split lift">
            <section class="card"><h3>Bệnh nhân được giao</h3><p class="small muted" style="margin:0 0 10px">Sắp theo cờ đỏ 7 ngày, rồi tuân thủ thấp.</p><div id="pt-list">${skeleton(3)}</div></section>
            <section id="detail"><div class="card"><div class="empty">Chọn một bệnh nhân để xem hồ sơ.</div></div></section>
        </div>`,
    });

    let patients = [];
    let current = ctx.patient?.access_role === 'doctor' ? ctx.patient.id : null;

    async function drawList() {
        const box = screen.querySelector('#pt-list');
        try {
            patients = (await api('/clinic/patients')).data;
            box.innerHTML = patients.length ? patients.map((p) => `<button class="pt-row ${p.id === current ? 'sel' : ''}" data-act="open" data-id="${esc(p.id)}">
                <span><b>${esc(p.full_name)}</b>${p.birth_year ? ` <span class="small muted">· ${p.birth_year}</span>` : ''}<br>
                <span class="small muted">Tuân thủ 7 ngày: ${p.adherence_percent === null ? '—' : `${p.adherence_percent}%`}${p.latest_reading ? ` · gần nhất ${esc(readingText(p.latest_reading))}` : ''}${p.next_appointment ? ` · tái khám ${dm(String(p.next_appointment.date).slice(0, 10))}` : ''}</span></span>
                ${p.red_flag_count_7d ? `<span class="chip bad">${p.red_flag_count_7d} cờ đỏ</span>` : '<span class="chip good">Ổn</span>'}</button>`).join('')
                : '<div class="empty">Chưa có bệnh nhân nào mời bạn theo dõi.</div>';
            if (!current && patients[0]) current = patients[0].id;
            if (current) drawDetail(current);
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    async function drawDetail(pid) {
        const box = screen.querySelector('#detail');
        box.innerHTML = `<div class="card">${skeleton(5)}</div>`;
        try {
            const [overview, readings, questions, notes] = await Promise.all([
                api(`/patients/${pid}/overview`).then((r) => r.data),
                api(`/patients/${pid}/readings?from=${addDays(today, -90)}&to=${today}`).then((r) => r.data),
                api(`/patients/${pid}/questions`).then((r) => r.data),
                api(`/patients/${pid}/notes`).then((r) => r.data),
            ]);
            const p = overview.patient;
            const pending = questions.filter((q) => !q.answer);
            box.innerHTML = `<div class="card">
                    <div class="row" style="align-items:flex-start"><div class="grow"><h3>${esc(p.full_name)}</h3><div class="small muted">${p.birth_year ? `Sinh ${p.birth_year} · ` : ''}Tuân thủ 7 ngày: ${overview.adherence_7d === null ? '—' : `${overview.adherence_7d}%`}</div></div>
                    <button class="btn sm" data-act="pdf" data-id="${esc(pid)}">⬇ Báo cáo PDF</button></div>
                    <div class="chips" style="margin-top:10px">${overview.conditions.map((c) => `<span class="chip ${c.priority === 'high' ? 'bad' : 'info'}">${esc(c.title.split(/[(—]/)[0].trim())}</span>`).join('')}</div>
                    ${overview.alerts.length ? `<div style="margin-top:12px">${overview.alerts.map((a) => `<div class="alert bad"><div class="ico">!</div><div><p style="margin:0">${esc(a.content.replace('[NHÁP — CẦN DUYỆT] ', ''))}</p><span class="small muted">${esc((a.created_at || '').slice(0, 16).replace('T', ' '))}</span></div></div>`).join('')}</div>` : ''}
                </div>
                <div class="card"><div class="row"><h3 class="grow">Đường huyết (90 ngày)</h3><select id="range" aria-label="Khoảng thời gian"><option value="30">30 ngày</option><option value="90" selected>90 ngày</option></select></div><div class="chart" id="d-chart-g"></div></div>
                <div class="card"><h3>Huyết áp</h3><div class="chart" id="d-chart-b"></div></div>
                <div class="card"><h3>Chỉ số gần đây</h3>${readings.length ? `<table class="tbl"><tbody>${readings.slice(-12).reverse().map((r) => `<tr><td class="v">${esc((r.measured_at || '').slice(0, 16).replace('T', ' '))}</td><td>${esc(r.context || '')}</td><td class="v">${esc(readingText(r))}</td><td>${levelChip(readingLevel(r))}</td></tr>`).join('')}</tbody></table>` : '<div class="empty">Chưa có chỉ số.</div>'}</div>
                <div class="card"><h3>Câu hỏi của gia đình (${pending.length} chưa trả lời)</h3>
                    ${pending.length ? pending.map((q) => `<div class="chat" style="margin-bottom:12px"><div class="bubble q">${esc(q.question)}</div>
                        <form class="row" data-answer="${esc(q.id)}"><input class="grow" style="border:1px solid var(--line);border-radius:12px;padding:10px;min-height:44px;background:var(--card-2)" placeholder="Trả lời…" required><button class="btn sm">Gửi</button></form></div>`).join('') : '<div class="empty">Không có câu hỏi chờ.</div>'}
                </div>
                <div class="card"><h3>Nhận xét</h3>
                    <form id="note-form"><div class="field"><label for="note-text" class="sr-only">Nhận xét</label><textarea id="note-text" placeholder="Nhận xét cho gia đình (hiện ở mục Hồ sơ của gia đình)" required></textarea></div><button class="btn sm">Lưu nhận xét</button></form>
                    ${notes.map((n) => `<div class="card flat" style="margin-top:8px"><div class="small muted">${esc((n.created_at || '').slice(0, 10))}</div><p style="margin:4px 0 0">${esc(n.content)}</p></div>`).join('')}
                </div>
                <div class="card row"><div class="grow small ink2">Xác nhận hoặc sửa ngưỡng cảnh báo cho bệnh nhân này.</div><button class="btn sm ghost" data-act="thresholds" data-id="${esc(pid)}">Ngưỡng</button></div>`;

            const drawCharts = (days) => {
                const from = addDays(today, -days);
                const inRange = readings.filter((r) => r.measured_at.slice(0, 10) >= from);
                drawChart(box.querySelector('#d-chart-g'), [{ name: 'Đường huyết', color: 'var(--s1)', pts: inRange.filter((r) => r.type === 'blood_glucose').map((r) => ({ x: r.measured_at.slice(0, 10), y: Number(r.values.value) })) }], { band: [4.4, 7.2], unit: 'mmol/L', aria: 'Đường huyết' });
                const bp = inRange.filter((r) => r.type === 'blood_pressure');
                drawChart(box.querySelector('#d-chart-b'), [
                    { name: 'Tâm thu', color: 'var(--s1)', pts: bp.map((r) => ({ x: r.measured_at.slice(0, 10), y: Number(r.values.systolic) })) },
                    { name: 'Tâm trương', color: 'var(--s2)', pts: bp.map((r) => ({ x: r.measured_at.slice(0, 10), y: Number(r.values.diastolic) })) },
                ], { refs: [130, 80], pad: 8, unit: 'mmHg', endLabels: true, aria: 'Huyết áp' });
            };
            drawCharts(90);
            box.querySelector('#range').addEventListener('change', (e) => drawCharts(Number(e.target.value)));

            box.querySelectorAll('[data-answer]').forEach((form) => form.addEventListener('submit', async (e) => {
                e.preventDefault();
                try {
                    await api(`/patients/${pid}/questions/${form.dataset.answer}/answer`, { method: 'POST', body: { answer: form.querySelector('input').value.trim() } });
                    toast('Đã gửi câu trả lời. Gia đình sẽ thấy trong mục Hỏi bác sĩ.');
                    drawDetail(pid);
                } catch (error) { toast(error.message, 'bad'); }
            }));
            box.querySelector('#note-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                try {
                    await api(`/patients/${pid}/notes`, { method: 'POST', body: { content: box.querySelector('#note-text').value.trim(), type: 'doctor' } });
                    toast('Đã lưu nhận xét.');
                    drawDetail(pid);
                } catch (error) { toast(error.message, 'bad'); }
            });
        } catch (error) {
            box.innerHTML = `<div class="card">${errorBox(error.message)}</div>`;
        }
    }

    delegate(screen, {
        open: (el) => {
            current = el.dataset.id;
            const p = patients.find((x) => x.id === current);
            ctx.store.set('patient', { id: p.id, full_name: p.full_name, birth_year: p.birth_year, access_role: 'doctor' });
            screen.querySelectorAll('.pt-row').forEach((b) => b.classList.toggle('sel', b.dataset.id === current));
            drawDetail(current);
            if (window.innerWidth < 1024) screen.querySelector('#detail').scrollIntoView({ behavior: 'smooth' });
        },
        pdf: async (el) => {
            try {
                const blob = await apiBlob(`/patients/${el.dataset.id}/report/pdf?from=${addDays(today, -30)}&to=${today}`);
                window.open(URL.createObjectURL(blob), '_blank');
            } catch (error) { toast(error.message, 'bad'); }
        },
        thresholds: (el) => {
            const p = patients.find((x) => x.id === el.dataset.id);
            ctx.store.set('patient', { id: p.id, full_name: p.full_name, birth_year: p.birth_year, access_role: 'doctor' });
            ctx.go('/settings/thresholds');
        },
        retry: () => ctx.refresh(),
    });

    drawList();
}
