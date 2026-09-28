/** Màn "Hôm nay": đường huyết, thực đơn + bài tập của ngày, dấu hiệu bất thường, ghi chú; bảng ghi chỉ số nhanh (nút +). */
import { api } from '../core/api.js';
import { todayVN, addDays, dm, longDate, parseDate, WD, vn, parseNum, nowTimeVN, diffDays, greeting } from '../core/format.js';
import { esc, delegate, skeleton, errorBox } from '../ui/dom.js';
import { openSheet, closeSheet, toast, confirmDialog } from '../ui/shell.js';
import { icon } from '../ui/icons.js';
import { EVENT_META, POINTS, GLUCOSE_POINTS, SYMPTOMS, levelChip, readingText, readingLevel, exerciseCard, playVideo, weeklyMenu, menuRows, menuIndex, nextMeal, WEEKDAYS, blockHead, sumTile } from './common.js';
import { generateCarePlan } from './upload.js';

export async function renderToday(ctx, params = {}) {
    const today = todayVN();
    const date = params.date || ctx.store.get('date') || today;
    ctx.store.set('date', date);
    const isToday = date === today;

    const screen = ctx.render({
        title: isToday ? `${greeting()}!` : `Ngày ${dm(date)}`,
        sub: longDate(date),
        tab: 'today',
        body: `<section id="today-screen" class="today">
            <div class="card lift today-sum" id="overview-card">${skeleton(2)}</div>
            <div class="strip" id="strip"></div>
            <div id="alerts-card"></div>

            <section class="blk">
                ${blockHead('droplet', 'Đường huyết', '<span class="blk-sub">mmol/L</span>')}
                <div class="card" id="glucose-card">
                    <form class="glu-form" id="glu-form" novalidate>
                        <label class="sr-only" for="glu-time">Giờ đo</label>
                        <input id="glu-time" class="glu-time" type="time" required value="${isToday ? nowTimeVN() : '07:00'}">
                        <label class="sr-only" for="glu-value">Đường huyết (mmol/L)</label>
                        <input id="glu-value" class="glu-input" inputmode="decimal" autocomplete="off" placeholder="6,5">
                        <button class="btn" type="submit">Lưu</button>
                    </form>
                    <div class="small muted glu-point" id="glu-point" aria-live="polite"></div>
                    <div id="readings-card"></div>
                    <div id="glu-note" aria-live="polite"></div>
                </div>
            </section>

            <section class="blk">
                ${blockHead('utensils', 'Thực đơn hôm nay', `<button class="link-btn small blk-link" data-act="week-menu" id="week-menu-btn" hidden>Cả tuần ${icon('chevron-right', { size: 16 })}</button>`)}
                <div class="card menu-card" id="menu-today">${skeleton(3)}</div>
            </section>

            <section class="blk">
                ${blockHead('dumbbell', 'Bài tập hôm nay', '<span class="blk-sub" id="ex-count"></span>')}
                <div id="ex-today">${skeleton(2)}</div>
            </section>

            <section class="blk">
                ${blockHead('heart', 'Dấu hiệu bất thường', '<span class="blk-sub">có thì chạm chọn</span>')}
                <div class="card" id="symptom-card"></div>
            </section>

            <section class="blk">
                ${blockHead('note', 'Ghi chú')}
                <div class="card"><div class="field" style="margin:0"><label class="sr-only" for="note">Ghi chú trong ngày</label><textarea id="note" placeholder="Ví dụ: ăn ít bữa trưa, đau vai giảm, tên thuốc huyết áp đang uống…"></textarea></div></div>
            </section>
        </section>`,
    });

    const pid = ctx.patient.id;
    let day;
    let calendar = { days: [] };
    let carePlan = null;

    async function load() {
        const month = date.slice(0, 7);
        [day, calendar, carePlan] = await Promise.all([
            api(`/patients/${pid}/day/${date}`).then((r) => r.data),
            api(`/patients/${pid}/calendar?month=${month}&today=${today}`).then((r) => r.data).catch(() => ({ days: [] })),
            api(`/patients/${pid}/care-plan`).then((r) => r.data).catch(() => null),
        ]);
    }

    try {
        await load();
    } catch (error) {
        screen.querySelector('#overview-card').innerHTML = errorBox(error.message);
        delegate(screen, { retry: () => ctx.refresh() });
        return;
    }

    const cal = Object.fromEntries((calendar.days || []).map((d) => [d.date, d]));
    const exercises = () => day.daily?.exercises || [];
    const doneSet = () => new Set(day.day_log.exercises_done || []);

    /* ----- Tóm tắt: đường huyết gần nhất · bài tập · bữa tới ----- */
    function drawOverview() {
        const glu = [...day.readings].filter((r) => r.type === 'blood_glucose').pop();
        const list = exercises();
        const done = list.filter((e) => doneSet().has(e.id)).length;
        const next = isToday ? nextMeal(day.routine || {}, nowTimeVN()) : null;
        const tile = sumTile;
        screen.querySelector('#overview-card').innerHTML = `<div class="sum-grid">
            ${tile('droplet', 'Đường huyết', glu ? vn(glu.values.value) : '—', glu ? levelChip(readingLevel(glu)) : '<span class="small muted">chưa đo</span>')}
            ${tile('dumbbell', 'Bài tập', list.length ? `${done}/${list.length}` : '—', list.length ? `<span class="sum-bar"><i style="width:${Math.round((done * 100) / list.length)}%"></i></span>` : '')}
            ${tile('utensils', 'Bữa tới', next ? next.label : '—', next ? `<span class="small muted">${esc(next.time)}</span>` : '')}
        </div>`;
    }

    /* ----- Dải ngày (chấm vàng = có mốc: tái khám, xét nghiệm…) ----- */
    function drawStrip() {
        let html = '';
        for (let i = -3; i <= 10; i++) {
            const d = addDays(date, i);
            const dt = parseDate(d);
            html += `<button class="day ${d === date ? 'sel' : ''} ${d === today ? 'today' : ''}" data-act="day" data-date="${d}" aria-label="${longDate(d)}">
                ${cal[d]?.events?.length ? '<span class="ev"></span>' : ''}<small>${WD[dt.getDay()]}</small><b>${dt.getDate()}</b></button>`;
        }
        const strip = screen.querySelector('#strip');
        strip.innerHTML = html;
        strip.querySelector('.sel')?.scrollIntoView({ inline: 'center', block: 'nearest' });
    }

    /* ----- Cảnh báo + mốc ----- */
    function drawAlerts() {
        const warn = icon('warn', { size: 18 });
        let html = '';
        day.readings.filter((r) => readingLevel(r) === 'red').forEach((r) => {
            const low = r.type === 'blood_glucose' && Number(r.values.value) < 3.9;
            html += `<div class="alert bad"><div class="ico">${warn}</div><div><b>${r.type === 'blood_pressure' ? 'Huyết áp' : 'Đường huyết'} ${esc(readingText(r))} — ngoài ngưỡng an toàn</b>
                <p>${low ? 'Nghi hạ đường huyết: ăn ngay 15 g đường nhanh (3–4 viên đường hoặc 150 ml nước cam), đo lại sau 15 phút. Lơ mơ, không tỉnh: gọi 115.' : 'Ghi lại và báo bác sĩ điều trị. Có triệu chứng nặng: gọi 115.'}</p></div></div>`;
        });
        day.day_log.symptoms.map((k) => SYMPTOMS.find((s) => s.k === k)).filter(Boolean).forEach((s) => {
            html += `<div class="alert bad"><div class="ico">${warn}</div><div><b>${esc(s.t)}</b><p>${esc(s.a)}</p></div></div>`;
        });
        day.events.forEach((e) => {
            const m = EVENT_META[e.type] || EVENT_META.other;
            const done = e.status === 'completed';
            html += `<div class="alert ${m.cls}"><div class="ico">${m.icon}</div><div class="grow"><b>${esc(e.title)}${e.due_date ? ` <span class="chip y">hạn ${dm(e.due_date)}</span>` : ''}</b><p>${esc(e.description || '')}</p>
                <button class="btn sm ${done ? 'ghost' : ''}" style="margin-top:8px" data-act="event" data-id="${esc(e.id)}" data-status="${done ? 'pending' : 'completed'}">${done ? `${icon('check', { size: 16 })} Đã xong · bỏ đánh dấu` : 'Đánh dấu đã xong'}</button></div></div>`;
        });
        const upcoming = (calendar.days || []).filter((d) => d.date > date && diffDays(date, d.date) <= 3).flatMap((d) => d.events.filter((e) => e.type === 'appointment').map((e) => ({ ...e, date: d.date })));
        upcoming.forEach((e) => {
            html += `<div class="alert kham"><div class="ico">${icon('alarm', { size: 18 })}</div><div><b>Sắp tới (${dm(e.date)}): ${esc(e.title)}</b><p>Chuẩn bị giấy tờ, đơn cũ và sổ theo dõi.</p></div></div>`;
        });
        screen.querySelector('#alerts-card').innerHTML = html;
    }

    function drawReadings() {
        const box = screen.querySelector('#readings-card');
        box.innerHTML = day.readings.length
            ? `<table class="tbl"><tbody>${day.readings.map((r) => `<tr><td class="v">${esc((r.measured_at || '').slice(11, 16))}</td><td>${esc(POINTS[r.context] || (r.type === 'blood_pressure' ? 'Huyết áp' : 'Đường huyết'))}</td><td class="v">${esc(readingText(r))}</td><td>${levelChip(readingLevel(r))}</td></tr>`).join('')}</tbody></table>`
            : '<p class="small muted" style="margin:10px 0 0">Chưa đo lần nào trong ngày.</p>';
        drawGluPoint();
    }

    /** Thời điểm đo được suy ra từ giờ người bệnh nhập — không phải chọn tay. */
    function drawGluPoint() {
        const time = screen.querySelector('#glu-time').value;
        screen.querySelector('#glu-point').textContent = time ? `Tính là: ${POINTS[guessGlucosePoint(day.items, time)]} · lúc ${time}` : 'Nhập giờ đo.';
    }

    function drawSymptoms() {
        const on = new Set(day.day_log.symptoms);
        screen.querySelector('#symptom-card').innerHTML = `<div class="sym">${SYMPTOMS.map((s) => `<label class="${on.has(s.k) ? 'on' : ''}"><input type="checkbox" data-sym="${s.k}" ${on.has(s.k) ? 'checked' : ''}>${esc(s.t)}</label>`).join('')}</div>`;
    }

    /* ----- Thực đơn của ngày (lên mới mỗi sáng) + mẹo + nên ăn / tránh ----- */
    function drawMenu() {
        const box = screen.querySelector('#menu-today');
        const next = isToday ? nextMeal(day.routine || {}, nowTimeVN()) : null;
        const rows = menuRows(day.menu, day.routine || {}, next?.key);
        screen.querySelector('#week-menu-btn').hidden = !weeklyMenu(carePlan?.content);
        const guide = carePlan ? dietGuide(carePlan.content?.diet || {}) : '';
        if (!carePlan || (!rows && !guide)) {
            box.innerHTML = `<div class="cp-empty"><span class="cp-ico">${icon('salad', { size: 30 })}</span><b>Chưa có thực đơn</b>
                <p class="small ink2">AI lên thực đơn mỗi ngày một khác và chọn bài tập có video, dựa trên thuốc đang dùng và kết quả xét nghiệm.</p>
                <button class="btn" data-act="make-plan">${icon('sparkles', { size: 18 })} Lên thực đơn & bài tập</button></div>`;
            return;
        }
        box.innerHTML = `${day.daily?.tip ? `<div class="menu-hint">${icon('sparkles', { size: 16 })}<span>${esc(day.daily.tip)}</span></div>` : ''}
            ${rows ? `<div class="meals-list">${rows}</div>` : ''}
            ${guide}`;
    }

    /**
     * Không ăn đúng được thực đơn (ăn cỗ, ăn ngoài, nhà nấu món khác…): đủ 3 nhóm nên ăn / hạn chế / tránh
     * để tự chọn món thay thế. Lưu ý thuốc – thức ăn chỉ để ở màn Phác đồ (dài, không cần xem mỗi ngày).
     */
    function dietGuide(diet) {
        const groups = [
            ['check', 'Nên ăn', diet.eat_more, 'good'],
            ['warn', 'Hạn chế', diet.limit, 'warn'],
            ['ban', 'Tránh', diet.avoid, 'bad'],
        ].filter(([, , list]) => list?.length);
        if (!groups.length) return '';
        return `<div class="diet-guide">
            <p class="diet-guide-h">${icon('info', { size: 16 })}<span><b>Không ăn được như thực đơn?</b> Tự chọn món theo các nhóm dưới đây.</span></p>
            ${groups.map(([ic, label, list, tone]) => `<div class="diet-grp ${tone}"><div class="diet-grp-h">${icon(ic, { size: 15 })}<b>${label}</b></div>
                <ul>${list.map((x) => `<li>${esc(x)}</li>`).join('')}</ul></div>`).join('')}
        </div>`;
    }

    /* ----- Bài tập của ngày (luân phiên mỗi ngày): video + đánh dấu đã tập ----- */
    function drawExercises() {
        const box = screen.querySelector('#ex-today');
        const list = exercises();
        const done = doneSet();
        screen.querySelector('#ex-count').textContent = list.length ? `đã tập ${list.filter((e) => done.has(e.id)).length}/${list.length}` : '';
        if (!carePlan) { box.innerHTML = ''; return; }
        if (!list.length) { box.innerHTML = '<div class="empty">Chưa có bài tập. Bấm “Lập lại” ở màn Phác đồ để AI chọn bài.</div>'; return; }
        const tips = day.daily?.insulin_tips;
        box.innerHTML = `<div class="ex-strip">${list.map((e) => exerciseCard(e, { done: done.has(e.id), compact: true })).join('')}</div>
            ${tips ? `<button class="ex-tip" data-act="insulin-tips">${icon('syringe', { size: 18 })}<span><b>${esc(tips.title)}</b><small>Xem trước khi tập — phòng hạ đường huyết</small></span>${icon('chevron-right', { size: 18 })}</button>` : ''}
            <p class="small muted ex-safety">${icon('warn', { size: 14 })} ${esc(carePlan.exercise_safety || '')}</p>`;
    }

    /* ----- Nhận xét đường huyết trong ngày: số liệu hiện ngay, AI viết lời sau (chậm) khi có số đo mới ----- */
    let noteSeq = 0;
    async function loadNote() {
        const seq = ++noteSeq;
        const box = screen.querySelector('#glu-note');
        let data;
        try {
            data = (await api(`/patients/${pid}/glucose-note/${date}`)).data;
        } catch (_e) { if (seq === noteSeq) box.innerHTML = ''; return; }
        if (seq !== noteSeq) return;
        drawNote(data, data.needs_ai);
        if (!data.needs_ai) return;
        try {
            const res = await api(`/patients/${pid}/glucose-note/${date}`, { method: 'POST' });
            // Lần tải mới hơn (vừa lưu thêm số đo) đã thay chỗ; mất mạng thì yêu cầu được xếp hàng → giữ nhận xét quy tắc.
            if (seq === noteSeq) drawNote(res.queued ? data : res.data, false);
        } catch (_e) {
            if (seq === noteSeq) drawNote(data, false);
        }
    }

    function drawNote(data, busy) {
        const box = screen.querySelector('#glu-note');
        const s = data.stats;
        const chips = [
            s.today.count ? `<span class="chip ${s.today.in_target === s.today.count ? 'good' : 'warn'}">${s.today.in_target}/${s.today.count} lần đạt</span>` : '',
            s.last_7_days.fasting_avg !== null ? `<span class="chip info">Lúc đói TB 7 ngày: ${vn(s.last_7_days.fasting_avg)}</span>` : '',
            s.hba1c ? `<span class="chip info">HbA1c ${vn(s.hba1c.value)}% ≈ ${vn(s.hba1c.estimated_avg_glucose)}</span>` : '',
        ].join('');
        if (!s.today.count) {
            box.innerHTML = chips ? `<div class="gnote-stats" style="margin-top:10px">${chips}</div>` : '';
            return;
        }
        const note = data.note || data.fallback;
        const ai = data.note?.source === 'ai';
        const TONE_IC = { good: 'check-circle', warn: 'warn', bad: 'warn', info: 'info' };
        box.innerHTML = `<div class="gnote">
            <div class="gnote-h"><span class="gnote-ic">${icon('sparkles', { size: 16 })}</span><b>Nhận xét hôm nay</b>
                ${busy ? '<span class="gnote-busy"><span class="spin"></span>AI đang nhận xét…</span>' : ai ? '<span class="chip">AI · tham khảo</span>' : ''}</div>
            ${chips ? `<div class="gnote-stats">${chips}</div>` : ''}
            ${note.summary ? `<p class="gnote-sum">${esc(note.summary)}</p>` : ''}
            ${note.points?.length ? `<ul class="gnote-pts">${note.points.map((p) => `<li class="${esc(p.tone)}">${icon(TONE_IC[p.tone] || 'info', { size: 15 })}<span>${esc(p.text)}</span></li>`).join('')}</ul>` : ''}
            ${note.ask_doctor ? `<p class="gnote-ask">${icon('stethoscope', { size: 15 })}<span><b>Nên hỏi bác sĩ:</b> ${esc(note.ask_doctor)}</span></p>` : ''}
            <p class="gnote-disc">${esc(data.disclaimer)}</p>
        </div>`;
    }

    function drawAll() {
        drawOverview(); drawStrip(); drawAlerts(); drawReadings(); drawMenu(); drawExercises(); drawSymptoms();
        screen.querySelector('#note').value = day.day_log.note || '';
        loadNote();
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
        event: async (el) => {
            try {
                await api(`/patients/${pid}/events/${el.dataset.id}`, { method: 'PATCH', body: { status: el.dataset.status } });
                await reload();
            } catch (error) { toast(error.message, 'bad'); }
        },
        'play-video': (el) => playVideo(el),
        'make-plan': async (el) => { el.disabled = true; if (!await generateCarePlan(ctx, { after: null })) el.disabled = false; },
        'ex-done': async (el) => {
            const set = doneSet();
            if (set.has(el.dataset.id)) set.delete(el.dataset.id); else set.add(el.dataset.id);
            day.day_log.exercises_done = [...set];
            drawExercises(); drawOverview();
            if (set.has(el.dataset.id)) toast('Tốt lắm! Đã ghi nhận bài tập hôm nay.');
            try { await saveDayMeta({ exercises_done: day.day_log.exercises_done }); } catch (error) { toast(error.message, 'bad'); }
        },
        'insulin-tips': () => {
            const sheet = openSheet(`<div id="ins-tips"><h3>${esc(day.daily.insulin_tips.title)}</h3>${exerciseCard(day.daily.insulin_tips)}</div>`);
            delegate(sheet.querySelector('#ins-tips'), { 'play-video': (el) => playVideo(el) });
        },
        'week-menu': () => {
            const week = weeklyMenu(carePlan?.content);
            if (!week) return;
            const cur = menuIndex(date);
            openSheet(`<h3>Thực đơn tham khảo cả tuần</h3><p class="small muted" style="margin-top:-6px">Mỗi sáng app lên thực đơn mới cho hôm đó; bảng này để xem trước.</p>
                ${week.map((m, i) => `<div class="menu-day ${i === cur ? 'cur' : ''}"><h4>${WEEKDAYS[i]}${i === cur ? ' <span class="chip">hôm nay</span>' : ''}</h4>${menuRows(m)}</div>`).join('')}
                <p class="small muted">${esc(carePlan.disclaimer || '')}</p>`);
            document.querySelector('.sheet .menu-day.cur')?.scrollIntoView({ block: 'start' });
        },
        retry: () => ctx.refresh(),
    });

    screen.querySelector('#glu-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const input = screen.querySelector('#glu-value');
        const value = parseNum(input.value);
        if (value === null || value <= 0 || value > 60) { input.focus(); return toast('Nhập số đường huyết, ví dụ 6,5.', 'bad'); }
        const timeInput = screen.querySelector('#glu-time');
        const time = timeInput.value;
        if (!time) { timeInput.focus(); return toast('Nhập giờ đo, ví dụ 07:30.', 'bad'); }
        if (isToday && time > nowTimeVN()) { timeInput.focus(); return toast('Giờ đo chưa tới — kiểm tra lại giờ.', 'bad'); }
        const point = guessGlucosePoint(day.items, time);
        const button = event.target.querySelector('button');
        button.disabled = true;
        try {
            const res = await api(`/patients/${pid}/readings`, { method: 'POST', body: { type: 'blood_glucose', context: point, measured_at: `${date} ${time}:00`, values: { value } } });
            if (res.alert) toast(res.alert.replace('[NHÁP — CẦN DUYỆT] ', ''), 'bad');
            else toast(`Đã lưu ${vn(value)} mmol/L.`);
            input.value = '';
            if (isToday) timeInput.value = nowTimeVN();
            await reload();
        } catch (error) {
            toast(error.message, 'bad');
        } finally {
            button.disabled = false;
        }
    });

    screen.querySelector('#glu-time').addEventListener('input', drawGluPoint);

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
/**
 * Đoán thời điểm đo đường huyết để người bệnh chỉ cần gõ số: ưu tiên lần đo trong lịch chưa làm
 * gần giờ hiện tại nhất (trong vòng 90 phút), không có thì theo giờ trong ngày.
 */
export function guessGlucosePoint(items, time) {
    const mins = (t) => { const [h, m] = String(t).split(':').map(Number); return h * 60 + (m || 0); };
    const now = mins(time);
    const planned = (items || [])
        .filter((i) => i.type === 'measurement' && i.metric === 'blood_glucose' && !i.done && GLUCOSE_POINTS.includes(i.point))
        .map((i) => ({ point: i.point, gap: Math.abs(mins(i.time) - now) }))
        .filter((i) => i.gap <= 90)
        .sort((a, b) => a.gap - b.gap);
    if (planned.length) return planned[0].point;
    const h = now / 60;
    if (h < 9) return 'fasting';
    if (h < 11) return 'post_breakfast';
    if (h < 12) return 'pre_lunch';
    if (h < 17) return 'post_lunch';
    if (h < 19) return 'pre_dinner';
    if (h < 21.5) return 'post_dinner';
    return 'bedtime';
}

/** Nút +: ghi đường huyết chỉ bằng một con số; huyết áp để trong mục mở thêm. */
export async function openQuickReading(ctx) {
    if (!ctx.patient) return;
    const today = todayVN();
    const date = ctx.store.get('date') || today;
    const pid = ctx.patient.id;
    let items = [];
    try {
        items = (await api(`/patients/${pid}/day/${date}`)).data.items;
    } catch (_e) { /* vẫn cho nhập */ }
    const isToday = date === today;
    const pointText = (t) => (t ? `Tính là: ${POINTS[guessGlucosePoint(items, t)]} · lúc ${t}${isToday ? '' : ` · ngày ${date.split('-').reverse().join('/')}`}` : 'Nhập giờ đo.');
    const startTime = isToday ? nowTimeVN() : '07:00';

    const sheet = openSheet(`<h3>Ghi đường huyết</h3>
        <form id="qr" novalidate>
            <div class="glu-form">
                <div class="field"><label for="qr-time">Giờ đo</label><input id="qr-time" class="glu-time" type="time" required value="${startTime}"></div>
                <div class="field grow"><label for="qr-g">Đường huyết (mmol/L)</label><input id="qr-g" class="glu-input" inputmode="decimal" autocomplete="off" placeholder="VD 6,5"></div>
            </div>
            <div class="small muted glu-point" id="qr-point" aria-live="polite">${pointText(startTime)}</div>
            <details class="qr-more"><summary>Ghi thêm huyết áp</summary>
                <div class="grid3">
                    <div class="field"><label for="qr-sys">Tâm thu</label><input id="qr-sys" inputmode="numeric" placeholder="125"></div>
                    <div class="field"><label for="qr-dia">Tâm trương</label><input id="qr-dia" inputmode="numeric" placeholder="78"></div>
                    <div class="field"><label for="qr-hr">Mạch</label><input id="qr-hr" inputmode="numeric" placeholder="82"></div>
                </div>
            </details>
            <button class="btn block" type="submit">Lưu</button>
        </form>`);
    sheet.querySelector('#qr-g').focus();
    sheet.querySelector('#qr-time').addEventListener('input', (event) => { sheet.querySelector('#qr-point').textContent = pointText(event.target.value); });

    sheet.querySelector('#qr').addEventListener('submit', async (event) => {
        event.preventDefault();
        const timeInput = sheet.querySelector('#qr-time');
        const time = timeInput.value;
        if (!time) { timeInput.focus(); return toast('Nhập giờ đo, ví dụ 07:30.', 'bad'); }
        if (isToday && time > nowTimeVN()) { timeInput.focus(); return toast('Giờ đo chưa tới — kiểm tra lại giờ.', 'bad'); }
        const measuredAt = `${date} ${time}:00`;
        const g = parseNum(sheet.querySelector('#qr-g').value);
        const sys = parseInt(sheet.querySelector('#qr-sys').value, 10);
        const dia = parseInt(sheet.querySelector('#qr-dia').value, 10);
        const hr = parseInt(sheet.querySelector('#qr-hr').value, 10);
        if (g === null && !(sys && dia)) return toast('Nhập số đường huyết, ví dụ 6,5.', 'bad');
        const alerts = [];
        try {
            if (g !== null) {
                const res = await api(`/patients/${pid}/readings`, { method: 'POST', body: { type: 'blood_glucose', context: guessGlucosePoint(items, time), measured_at: measuredAt, values: { value: g } } });
                if (res.alert) alerts.push(res.alert);
            }
            if (sys && dia) {
                const bpPoint = Number(time.slice(0, 2)) < 14 ? 'bp_morning' : 'bp_evening';
                const res = await api(`/patients/${pid}/readings`, { method: 'POST', body: { type: 'blood_pressure', context: bpPoint, measured_at: measuredAt, values: { systolic: sys, diastolic: dia, ...(hr ? { heart_rate: hr } : {}) } } });
                if (res.alert) alerts.push(res.alert);
            }
            closeSheet();
            if (alerts.length) toast(alerts.map((a) => a.replace('[NHÁP — CẦN DUYỆT] ', '')).join(' '), 'bad');
            else toast('Đã lưu chỉ số.');
            ctx.refresh();
        } catch (error) {
            toast(error.message, 'bad');
        }
    });
}
