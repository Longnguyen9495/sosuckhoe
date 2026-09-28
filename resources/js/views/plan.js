/** Màn "Phác đồ": khung giờ một ngày, bệnh nền, thuốc + tồn kho, hướng dẫn. */
import { api } from '../core/api.js';
import { todayVN, dm, parseNum } from '../core/format.js';
import { esc, delegate, skeleton, errorBox, draftTitle } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { openSheet, closeSheet, toast, confirmDialog } from '../ui/shell.js';
import { TYPE_TAG, linesToList, exerciseCard, playVideo, weeklyMenu, menuRows, WEEKDAYS, WEEKDAY_SHORT } from './common.js';
import { generateCarePlan } from './upload.js';

const ICON = { insulin: icon('syringe'), topical: icon('droplets'), supply: icon('package'), medication: icon('pill') };

export async function renderPlan(ctx) {
    const pid = ctx.patient.id;
    const date = ctx.store.get('date') || todayVN();
    const screen = ctx.render({
        title: 'Phác đồ điều trị',
        sub: 'Theo đúng đơn bác sĩ — ứng dụng không đổi liều',
        tab: 'plan',
        body: `<div class="card lift"><div class="row" style="align-items:flex-start"><div class="pill-ico" style="background:var(--accent);color:var(--accent-ink)">!</div>
                <div class="grow small ink2"><b style="color:var(--ink)">Nguyên tắc:</b> ứng dụng chỉ sắp xếp giờ dùng theo đơn, <b>không thay đổi liều</b>. Mọi điều chỉnh (đặc biệt liều insulin, ngừng thuốc) phải hỏi bác sĩ điều trị.</div></div>
                <div class="row wrap-row" style="margin-top:12px"><button class="btn sm" data-act="nav" data-to="/rx/new">+ Nhập đơn mới</button><button class="btn sm ghost" data-act="nav" data-to="/upload">${icon('camera', { size: 16 })} Tải ảnh đơn (AI đọc)</button></div></div>
            <div id="pending"></div>
            <div class="sec-title"><h2>Chế độ ăn uống &amp; sinh hoạt</h2><button class="btn sm ghost" data-act="careplan" id="cp-btn">Lập lại</button></div>
            <div id="careplan">${skeleton(3)}</div>
            <div class="sec-title"><h2>Bài tập gợi ý</h2><span>có video hướng dẫn</span></div>
            <div id="exercises">${skeleton(2)}</div>
            <div class="sec-title"><h2>Khung giờ một ngày</h2><span id="frame-date"></span></div>
            <div class="card" id="frame">${skeleton(4)}</div>
            <div class="sec-title"><h2>Thuốc theo đơn</h2><span id="rx-count"></span></div>
            <div id="rx">${skeleton(4)}</div>
            <div class="sec-title"><h2>Bệnh nền</h2><span id="cond-count"></span></div>
            <div id="conditions">${skeleton(3)}</div>
            <div class="sec-title"><h2>Hướng dẫn</h2><span>nội dung chờ bác sĩ duyệt</span></div>
            <div id="articles">${skeleton(3)}</div>`,
    });

    let prescriptions = [];
    let carePlan = null;

    async function drawFrame() {
        const box = screen.querySelector('#frame');
        try {
            const { data } = await api(`/patients/${pid}/day/${date}`);
            screen.querySelector('#frame-date').textContent = `ngày ${dm(date)}`;
            const groups = {};
            data.items.filter((i) => i.type !== 'meal').forEach((i) => {
                (groups[i.time] ||= []).push(i.type === 'measurement' ? `Đo ${i.title.replace(/^Đường huyết /, 'đường huyết ').toLowerCase()}` : `${i.title}${i.amount_text ? ` (${i.amount_text})` : ''}`);
            });
            data.items.filter((i) => i.type === 'meal').forEach((i) => { (groups[i.time] ||= []).push(`<b>${esc(i.title)}</b>`); });
            const times = Object.keys(groups).sort();
            box.innerHTML = times.length
                ? `<div class="day-sched">${times.map((t) => `<div class="h">${t}</div><div>${groups[t].map((x) => (x.startsWith('<b>') ? x : esc(x))).join(' · ')}</div>`).join('')}</div>`
                : '<div class="empty">Ngày này chưa có lịch. Nhập đơn thuốc để có lịch.</div>';
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    function medCard(item) {
        const times = item.times.length ? item.times.map((t) => `${t.time} · ${esc(t.amount_text)}`).join(' &nbsp;|&nbsp; ') : (item.type === 'supply' ? 'Vật tư — không nhắc giờ' : '<span style="color:var(--warn)">Chưa có giờ dùng</span>');
        let stock = '';
        if (item.supply_days) {
            const pct = Math.max(0, Math.min(100, Math.round((item.days_left * 100) / item.supply_days)));
            const tone = item.days_left <= 0 ? 'bad' : item.days_left <= 7 ? 'warn' : 'good';
            stock = `<div class="stock"><div class="sb"><i style="width:${pct}%;background:var(--${tone})"></i></div><span class="chip ${tone}">${item.days_left <= 0 ? 'Đã hết' : `Còn ${item.days_left} ngày`}</span></div>
                <div class="small muted">Đủ ${item.supply_days} ngày · dự kiến hết ${dm(item.runs_out_on)}${item.is_long_term ? ' · <b>dùng lâu dài — nhớ mua / xin kê tiếp</b>' : ''}</div>`;
        } else if (item.course_ends_at) {
            stock = `<div class="small muted" style="margin-top:6px">Dùng trong đợt điều trị đến ${dm(item.course_ends_at)}</div>`;
        }
        const qty = item.prescribed_quantity !== null
            ? `SL kê ${item.prescribed_quantity}${item.purchased_quantity !== null && item.purchased_quantity !== item.prescribed_quantity ? ` · đã mua <b>${item.purchased_quantity}</b>` : ''} ${esc(item.quantity_unit || '')}`
            : '';
        return `<div class="med"><div class="pill-ico">${ICON[item.type] || ICON.medication}</div><div class="grow">
            <b>${esc(item.drug_name)}</b> ${item.active_ingredient ? `<span class="small muted">${esc(item.active_ingredient)}</span>` : ''}
            <div class="how">${TYPE_TAG[item.type] || ''}${times}</div>
            <div class="small ink2">${esc(item.dose_text)}</div>
            ${item.purpose ? `<div class="small ink2">${esc(item.purpose)}${qty ? ` · ${qty}` : ''}</div>` : (qty ? `<div class="small ink2">${qty}</div>` : '')}
            ${item.warning ? `<div class="warn-line">${icon('warn', { size: 14 })} ${esc(item.warning)}</div>` : ''}
            ${stock}
            ${item.supply_days ? `<button class="link-btn small" data-act="bought" data-id="${esc(item.id)}">+ Đã mua thêm</button>` : ''}
        </div></div>`;
    }

    async function drawRx() {
        const box = screen.querySelector('#rx');
        try {
            prescriptions = (await api(`/patients/${pid}/prescriptions?date=${todayVN()}`)).data;
            const count = prescriptions.reduce((n, p) => n + p.items.length, 0);
            screen.querySelector('#rx-count').textContent = `${prescriptions.length} đơn · ${count} thuốc / vật tư`;
            box.innerHTML = prescriptions.length ? prescriptions.map((p) => `<div class="card">
                <div class="row" style="align-items:flex-start"><div class="grow"><h3>${esc(p.doctor_name || 'Đơn thuốc')}</h3>
                    <div class="small muted">Kê ${p.prescribed_at ? dm(p.prescribed_at) : '—'} · dùng từ ${p.starts_at ? dm(p.starts_at) : '—'}${p.ends_at ? ` đến ${dm(p.ends_at)}` : ''}</div></div>
                    <button class="btn sm ghost" data-act="close-rx" data-id="${esc(p.id)}">Kết thúc đơn</button></div>
                ${p.items.map(medCard).join('')}
            </div>`).join('') : '<div class="empty">Chưa có đơn thuốc. Chạm "Nhập đơn mới" để thêm.</div>';
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    async function drawConditions() {
        const box = screen.querySelector('#conditions');
        try {
            const { data } = await api(`/patients/${pid}/overview`);
            screen.querySelector('#cond-count').textContent = `${data.conditions.length} vấn đề`;
            box.innerHTML = data.conditions.length ? data.conditions.map((c) => `<div class="card cond ${c.priority === 'high' ? 'high' : ''}">
                <div class="row" style="align-items:flex-start"><h3 class="grow">${esc(c.title)}</h3><span class="chip ${c.priority === 'high' ? 'bad' : 'info'}">${c.priority === 'high' ? 'Ưu tiên cao' : 'Theo dõi'}</span></div>
                ${c.detail ? `<p class="small ink2" style="margin:6px 0 0">${esc(c.detail)}</p>` : ''}</div>`).join('')
                : '<div class="empty">Chưa ghi nhận bệnh nền.</div>';
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    async function drawArticles() {
        const box = screen.querySelector('#articles');
        try {
            const { data } = await api('/articles?type=guide_');
            const wanted = ['guide_hypoglycemia', 'guide_insulin', 'guide_diet'];
            const list = wanted.map((t) => data.find((a) => a.type === t)).filter(Boolean);
            box.innerHTML = list.length ? list.map((a) => {
                const t = draftTitle(a.title);
                return `<div class="card article"><h3>${t.html}</h3>${linesToList(a.content, a.type !== 'guide_diet')}</div>`;
            }).join('') : '<div class="empty">Chưa có bài hướng dẫn.</div>';
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    async function drawPending() {
        try {
            const { data } = await api(`/patients/${pid}/pending-medications`);
            const n = data.reduce((s, d) => s + d.medications.length, 0);
            screen.querySelector('#pending').innerHTML = n ? `<div class="alert mua"><div class="ico">${icon('pill', { size: 18 })}</div><div class="grow"><b>${n} thuốc AI đọc được đang chờ bạn xác nhận</b>
                <p>Kiểm tra lại rồi đưa vào lịch uống thuốc.</p><button class="btn sm" style="margin-top:8px" data-act="nav" data-to="/review">Kiểm tra ngay</button></div></div>` : '';
        } catch (_e) { /* không bắt buộc */ }
    }

    const list = (items) => (items || []).length ? `<ul>${items.map((x) => `<li>${esc(x)}</li>`).join('')}</ul>` : '';
    /** Mục thu gọn: kế hoạch AI khá dài, chỉ mở phần người dùng cần. */
    const fold = (ic, title, inner, show) => (show ? `<details class="card article fold"><summary>${icon(ic, { size: 18 })}<span class="grow">${esc(title)}</span></summary><div class="fold-body">${inner}</div></details>` : '');

    async function drawCarePlan() {
        const box = screen.querySelector('#careplan');
        try {
            const { data } = await api(`/patients/${pid}/care-plan`);
            screen.querySelector('#cp-btn').textContent = data ? 'Lập lại' : 'Lập kế hoạch';
            drawExercises(data);
            carePlan = data;
            if (!data) {
                box.innerHTML = `<div class="card cp-empty"><div class="cp-ico">${icon('salad', { size: 30 })}</div><b>Chưa có chế độ ăn uống</b>
                    <p class="small ink2">AI sẽ lập chế độ ăn, sinh hoạt và việc cần theo dõi dựa trên thuốc đang dùng và kết quả xét nghiệm trong hồ sơ.</p>
                    <button class="btn" data-act="careplan">Lập kế hoạch (khoảng 1 phút)</button></div>`;
                return;
            }
            const c = data.content || {};
            const diet = c.diet || {};
            const week = weeklyMenu(c);
            box.innerHTML = `
                ${c.summary ? `<div class="card cp-summary"><p style="margin:0">${esc(c.summary)}</p>
                    <div class="small muted" style="margin-top:8px">Lập ${esc((data.created_at || '').slice(0, 10).split('-').reverse().join('/'))} · dựa trên ${data.sources?.medications ?? 0} thuốc, ${data.sources?.lab_results ?? 0} chỉ số xét nghiệm</div></div>` : ''}
                ${(c.key_issues || []).map((i) => `<div class="card cond ${i.priority === 'high' ? 'high' : ''}"><div class="row" style="align-items:flex-start"><h3 class="grow">${esc(i.title)}</h3><span class="chip ${i.priority === 'high' ? 'bad' : 'info'}">${i.priority === 'high' ? 'Ưu tiên' : 'Theo dõi'}</span></div>
                    ${i.detail ? `<p class="small ink2" style="margin:6px 0 0">${esc(i.detail)}</p>` : ''}${i.based_on ? `<p class="small muted" style="margin:4px 0 0">Căn cứ: ${esc(i.based_on)}</p>` : ''}</div>`).join('')}
                <div class="card" id="menu-card"></div>
                ${c.warning_signs?.length ? `<div class="alert bad"><div class="ico">!</div><div><b>Đi khám ngay nếu có</b>${list(c.warning_signs)}</div></div>` : ''}
                ${fold('salad', 'Nên ăn · hạn chế · tránh', `
                    <div class="cp-cols">
                        ${diet.eat_more?.length ? `<div class="cp-col good"><h4>✓ Nên ăn</h4>${list(diet.eat_more)}</div>` : ''}
                        ${diet.limit?.length ? `<div class="cp-col warn"><h4>↓ Hạn chế</h4>${list(diet.limit)}</div>` : ''}
                        ${diet.avoid?.length ? `<div class="cp-col bad"><h4>✕ Tránh</h4>${list(diet.avoid)}</div>` : ''}
                    </div>
                    ${diet.principles?.length ? `<h4>Nguyên tắc chung</h4>${list(diet.principles)}` : ''}`, diet.eat_more?.length || diet.limit?.length || diet.principles?.length)}
                ${fold('pill', 'Thuốc & thức ăn, cách dùng thuốc', `${list(diet.drug_food_notes)}${list(c.medication_notes)}`, diet.drug_food_notes?.length || c.medication_notes?.length)}
                ${fold('trend', 'Cần tự theo dõi', (c.monitoring || []).map((m) => `<div class="mon"><b>${esc(m.what)}</b><div class="small ink2">${esc(m.how_often || '')}</div>${m.target ? `<div class="small muted">Mục tiêu: ${esc(m.target)}</div>` : ''}</div>`).join(''), c.monitoring?.length)}
                ${fold('footprints', 'Sinh hoạt', list(c.lifestyle), c.lifestyle?.length)}
                ${fold('calendar', 'Tái khám & câu nên hỏi bác sĩ', `${c.follow_up?.length ? `<h4>Lần tái khám tới</h4>${list(c.follow_up)}` : ''}${c.questions_for_doctor?.length ? `<h4>Nên hỏi bác sĩ</h4>${list(c.questions_for_doctor)}` : ''}`, c.follow_up?.length || c.questions_for_doctor?.length)}
                <p class="small muted cp-note">${icon('info', { size: 14 })} ${esc(data.disclaimer)}</p>`;
            drawMenu(week ? (new Date().getDay() + 6) % 7 : null);
        } catch (error) {
            box.innerHTML = errorBox(error.message);
            screen.querySelector('#exercises').innerHTML = '';
        }
    }

    /** Thực đơn 7 ngày có nút chọn thứ; kế hoạch cũ chỉ có thực đơn mẫu một ngày. */
    function drawMenu(dayIndex) {
        const box = screen.querySelector('#menu-card');
        const c = carePlan?.content || {};
        const week = weeklyMenu(c);
        if (!week) {
            const rows = menuRows(c.diet?.sample_day);
            box.innerHTML = rows ? `<h3>Thực đơn gợi ý một ngày</h3>${rows}<p class="small muted" style="margin:8px 0 0">Bấm “Lập lại” để AI lên thực đơn 7 ngày khác nhau.</p>` : '';
            box.hidden = !rows;
            return;
        }
        box.innerHTML = `<h3>Thực đơn 7 ngày</h3>
            <div class="seg menu-days" role="tablist">${WEEKDAYS.map((_, i) => `<button type="button" role="tab" data-act="menu-day" data-i="${i}" class="${i === dayIndex ? 'on' : ''}" aria-selected="${i === dayIndex}">${WEEKDAY_SHORT[i]}</button>`).join('')}</div>
            <p class="small muted" style="margin:10px 0 4px">${WEEKDAYS[dayIndex]}${dayIndex === (new Date().getDay() + 6) % 7 ? ' · hôm nay' : ''}</p>${menuRows(week[dayIndex])}`;
    }

    /** Bài tập chọn từ thư viện có video; video chỉ tải khi người bệnh bấm xem. */
    function drawExercises(data) {
        const box = screen.querySelector('#exercises');
        const items = data?.exercises || [];
        if (!items.length) {
            box.innerHTML = `<div class="empty">${data ? 'Chưa có bài tập phù hợp. Bấm “Lập lại” ở mục chế độ ăn để AI chọn bài.' : 'Lập kế hoạch chăm sóc ở trên để có bài tập phù hợp với bệnh và thuốc đang dùng.'}</div>`;
            return;
        }
        box.innerHTML = `<div class="ex-list">${items.map((e) => exerciseCard(e)).join('')}</div>
            <div class="alert mua" style="margin-top:12px"><div class="ico">!</div><div><p style="margin:0">${esc(data.exercise_safety)}</p></div></div>`;
    }

    delegate(screen, {
        'play-video': (el) => playVideo(el),
        'menu-day': (el) => drawMenu(Number(el.dataset.i)),
        careplan: async (el) => {
            el.disabled = true;
            if (await generateCarePlan(ctx)) return;
            el.disabled = false;
        },
        bought: (el) => {
            const item = prescriptions.flatMap((p) => p.items).find((i) => i.id === el.dataset.id);
            const sheet = openSheet(`<h3>Đã mua thêm ${esc(item.drug_name)}</h3>
                <form id="buy"><div class="field"><label for="qty">Số lượng vừa mua (${esc(item.quantity_unit || 'đơn vị')})</label><input id="qty" inputmode="decimal" required></div>
                <p class="small muted">Hiện ghi nhận: ${item.purchased_quantity ?? item.prescribed_quantity ?? 0} ${esc(item.quantity_unit || '')}. Ngày hết sẽ được tính lại.</p>
                <button class="btn block">Lưu</button></form>`);
            sheet.querySelector('#buy').addEventListener('submit', async (e) => {
                e.preventDefault();
                const qty = parseNum(sheet.querySelector('#qty').value);
                if (!qty) return toast('Nhập số lượng.', 'bad');
                try {
                    await api(`/patients/${pid}/prescription-items/${item.id}`, { method: 'PATCH', body: { add_quantity: qty } });
                    closeSheet(); toast('Đã cập nhật số lượng.'); drawRx();
                } catch (error) { toast(error.message, 'bad'); }
            });
        },
        'close-rx': async (el) => {
            if (!await confirmDialog('Kết thúc đơn này từ hôm nay? Từ ngày mai lịch sẽ không còn thuốc của đơn. Chỉ làm khi bác sĩ đã kê đơn mới hoặc dặn ngừng.', { ok: 'Kết thúc đơn', danger: true })) return;
            try {
                await api(`/patients/${pid}/prescriptions/${el.dataset.id}/close`, { method: 'POST', body: { continued_item_ids: [] } });
                toast('Đã kết thúc đơn.'); drawRx(); drawFrame();
            } catch (error) { toast(error.message, 'bad'); }
        },
        retry: () => ctx.refresh(),
    });

    drawPending();
    drawCarePlan();
    drawFrame();
    drawRx();
    drawConditions();
    drawArticles();
}
