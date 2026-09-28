/** Màn "Phác đồ": tóm tắt thuốc, lịch dùng trong ngày, thuốc + tồn kho, chế độ ăn, bài tập, bệnh nền, hướng dẫn. */
import { api } from '../core/api.js';
import { todayVN, dm, parseNum, nowTimeVN } from '../core/format.js';
import { esc, delegate, skeleton, errorBox, draftTitle } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { openSheet, closeSheet, toast, confirmDialog } from '../ui/shell.js';
import { TYPE_TAG, linesToList, exerciseCard, playVideo, weeklyMenu, menuRows, WEEKDAYS, WEEKDAY_SHORT, blockHead, sumTile } from './common.js';
import { generateCarePlan } from './upload.js';

const ICON = { insulin: icon('syringe'), topical: icon('droplets'), supply: icon('package'), medication: icon('pill') };
/** Icon từng loại việc trong lịch dùng một ngày. */
const ITEM_IC = { measurement: 'droplet', insulin: 'syringe', medication: 'pill', topical: 'droplets', meal: 'utensils', activity: 'footprints' };
const GUIDE_IC = { guide_hypoglycemia: 'warn', guide_insulin: 'syringe', guide_diet: 'salad' };

export async function renderPlan(ctx) {
    const pid = ctx.patient.id;
    const today = todayVN();
    const date = ctx.store.get('date') || today;
    const screen = ctx.render({
        title: 'Phác đồ điều trị',
        sub: 'Theo đúng đơn bác sĩ — ứng dụng không đổi liều',
        tab: 'plan',
        body: `<div class="card lift today-sum" id="plan-sum">
                <div class="sum-grid" id="plan-tiles"></div>
                <p class="rule-note">${icon('shield', { size: 16 })}<span>Ứng dụng chỉ sắp giờ dùng theo đơn, <b>không thay đổi liều</b>. Mọi điều chỉnh (nhất là liều insulin, ngừng thuốc) phải hỏi bác sĩ điều trị.</span></p>
                <div class="plan-acts"><button class="btn sm" data-act="nav" data-to="/rx/new">${icon('plus', { size: 16 })} Nhập đơn mới</button><button class="btn sm ghost" data-act="nav" data-to="/upload">${icon('camera', { size: 16 })} Tải ảnh đơn (AI đọc)</button></div>
            </div>
            <div id="pending"></div>

            <section class="blk">
                ${blockHead('clock', date === today ? 'Lịch dùng hôm nay' : `Lịch dùng ngày ${dm(date)}`, '<span class="blk-sub" id="frame-count"></span>')}
                <div class="card" id="frame">${skeleton(4)}</div>
            </section>

            <section class="blk">
                ${blockHead('pill', 'Thuốc theo đơn', '<span class="blk-sub" id="rx-count"></span>')}
                <div id="rx">${skeleton(4)}</div>
            </section>

            <section class="blk">
                ${blockHead('salad', 'Chế độ ăn uống &amp; sinh hoạt', '<button class="btn sm ghost" data-act="careplan" id="cp-btn">Lập lại</button>')}
                <div id="careplan">${skeleton(3)}</div>
            </section>

            <section class="blk">
                ${blockHead('dumbbell', 'Bài tập gợi ý', '<span class="blk-sub">có video hướng dẫn</span>')}
                <div id="exercises">${skeleton(2)}</div>
            </section>

            <section class="blk">
                ${blockHead('stethoscope', 'Bệnh nền', '<span class="blk-sub" id="cond-count"></span>')}
                <div id="conditions">${skeleton(3)}</div>
            </section>

            <section class="blk">
                ${blockHead('info', 'Hướng dẫn', '<span class="blk-sub">chờ bác sĩ duyệt</span>')}
                <div id="articles">${skeleton(3)}</div>
            </section>`,
    });

    let prescriptions = [];
    let carePlan = null;

    /* ----- Tóm tắt: số thuốc · liều tới · sắp hết (mỗi ô điền khi dữ liệu về) ----- */
    const sum = { meds: undefined, next: undefined, low: undefined };
    function drawSummary() {
        const note = (text) => `<span class="small muted sum-line">${text}</span>`;
        const wait = ['—', note('…')];
        const meds = sum.meds === undefined ? wait : [String(sum.meds.count), note(`thuốc, vật tư · ${sum.meds.rx} đơn`)];
        const next = sum.next === undefined ? wait : sum.next ? [esc(sum.next.time), note(esc(sum.next.title))] : ['—', note(date === today ? 'đã dùng đủ hôm nay' : 'xem màn Hôm nay')];
        const low = sum.low === undefined ? wait : [String(sum.low.length), sum.low.length ? `<span class="chip warn">${esc(sum.low[0])}${sum.low.length > 1 ? ` +${sum.low.length - 1}` : ''}</span>` : note('đủ thuốc ≥ 7 ngày')];
        screen.querySelector('#plan-tiles').innerHTML = `
            ${sumTile('pill', 'Đang dùng', ...meds)}
            ${sumTile('alarm', 'Liều tới', ...next)}
            ${sumTile('package', 'Sắp hết', ...low)}`;
    }
    drawSummary();

    /** Lịch dùng một ngày dạng dòng thời gian: mỗi mốc giờ một dòng, việc đã xong có dấu tích, mốc sắp tới nổi bật. */
    async function drawFrame() {
        const box = screen.querySelector('#frame');
        try {
            const { data } = await api(`/patients/${pid}/day/${date}`);
            const groups = {};
            data.items.forEach((i) => { (groups[i.time] ||= []).push(i); });
            const times = Object.keys(groups).sort();
            const now = nowTimeVN();
            const doses = data.items.filter((i) => ['medication', 'insulin', 'topical'].includes(i.type));
            sum.next = date === today ? (doses.find((i) => !i.done && i.time >= now) || doses.find((i) => !i.done) || null) : null;
            drawSummary();
            const countable = data.items.filter((i) => i.countable);
            screen.querySelector('#frame-count').textContent = countable.length ? `xong ${countable.filter((i) => i.done).length}/${countable.length}` : '';
            const nextTime = date === today ? times.find((t) => t >= now && groups[t].some((i) => !i.done && i.type !== 'meal')) : null;
            const text = (i) => (i.type === 'measurement'
                ? esc(`Đo ${i.title.replace(/^Đường huyết /, 'đường huyết ').toLowerCase()}`)
                : `${esc(i.title)}${i.amount_text ? ` <span class="muted">· ${esc(i.amount_text)}</span>` : ''}`);
            box.innerHTML = times.length
                ? `<div class="tl">${times.map((t) => {
                    const list = groups[t];
                    const allDone = list.filter((i) => i.countable).every((i) => i.done) && list.some((i) => i.countable);
                    return `<div class="tl-row ${t === nextTime ? 'next' : ''} ${allDone ? 'done' : ''}"><span class="tl-time">${t}${t === nextTime ? '<small>sắp tới</small>' : ''}</span>
                        <div class="tl-items">${list.map((i) => `<div class="tl-it t-${esc(i.type)} ${i.done ? 'ok' : ''}">${icon(ITEM_IC[i.type] || 'pin', { size: 15 })}<span>${text(i)}</span>${i.done ? icon('check', { size: 15 }) : ''}</div>`).join('')}</div></div>`;
                }).join('')}</div>`
                : '<div class="empty">Ngày này chưa có lịch. Nhập đơn thuốc để có lịch.</div>';
        } catch (error) {
            sum.next = null;
            drawSummary();
            box.innerHTML = errorBox(error.message);
        }
    }

    function medCard(item) {
        const times = item.times.length
            ? `<div class="dose-times">${item.times.map((t) => `<span class="dose">${icon('clock', { size: 13 })}<b>${t.time}</b>${esc(t.amount_text)}</span>`).join('')}</div>`
            : `<div class="small ${item.type === 'supply' ? 'muted' : ''}" style="margin-top:4px${item.type === 'supply' ? '' : ';color:var(--warn)'}">${item.type === 'supply' ? 'Vật tư — không nhắc giờ' : 'Chưa có giờ dùng'}</div>`;
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
        return `<div class="med t-${esc(item.type)}"><div class="pill-ico">${ICON[item.type] || ICON.medication}</div><div class="grow">
            <div class="med-h"><b>${esc(item.drug_name)}</b>${TYPE_TAG[item.type] || ''}</div>
            ${item.active_ingredient ? `<div class="small muted">${esc(item.active_ingredient)}</div>` : ''}
            ${times}
            ${item.dose_text ? `<div class="small ink2" style="margin-top:4px">${esc(item.dose_text)}</div>` : ''}
            ${item.purpose ? `<div class="small ink2">${esc(item.purpose)}${qty ? ` · ${qty}` : ''}</div>` : (qty ? `<div class="small ink2">${qty}</div>` : '')}
            ${item.warning ? `<div class="warn-line">${icon('warn', { size: 14 })} ${esc(item.warning)}</div>` : ''}
            ${stock}
            ${item.supply_days ? `<button class="link-btn small" data-act="bought" data-id="${esc(item.id)}">${icon('plus', { size: 14 })} Đã mua thêm</button>` : ''}
        </div></div>`;
    }

    async function drawRx() {
        const box = screen.querySelector('#rx');
        try {
            prescriptions = (await api(`/patients/${pid}/prescriptions?date=${todayVN()}`)).data;
            const items = prescriptions.flatMap((p) => p.items);
            screen.querySelector('#rx-count').textContent = prescriptions.length ? `${prescriptions.length} đơn · ${items.length} món` : '';
            sum.meds = { count: items.length, rx: prescriptions.length };
            sum.low = items.filter((i) => i.supply_days && i.days_left <= 7).map((i) => i.drug_name);
            drawSummary();
            box.innerHTML = prescriptions.length ? prescriptions.map((p) => `<div class="card rx-card">
                <div class="rx-h"><span class="rx-ic">${icon('stethoscope', { size: 18 })}</span><div class="grow"><h3>${esc(p.doctor_name || 'Đơn thuốc')}</h3>
                    <div class="small muted">Kê ${p.prescribed_at ? dm(p.prescribed_at) : '—'} · dùng từ ${p.starts_at ? dm(p.starts_at) : '—'}${p.ends_at ? ` đến ${dm(p.ends_at)}` : ''} · ${p.items.length} món</div></div></div>
                ${p.items.map(medCard).join('')}
                <div class="rx-foot"><button class="link-btn small" style="color:var(--bad)" data-act="close-rx" data-id="${esc(p.id)}">Kết thúc đơn này</button></div>
            </div>`).join('') : '<div class="empty">Chưa có đơn thuốc. Chạm “Nhập đơn mới” ở trên để thêm.</div>';
        } catch (error) {
            sum.meds = { count: 0, rx: 0 };
            sum.low = [];
            drawSummary();
            box.innerHTML = errorBox(error.message);
        }
    }

    async function drawConditions() {
        const box = screen.querySelector('#conditions');
        try {
            const { data } = await api(`/patients/${pid}/overview`);
            screen.querySelector('#cond-count').textContent = data.conditions.length ? `${data.conditions.length} vấn đề` : '';
            box.innerHTML = data.conditions.length ? `<div class="card issues">${data.conditions.map((c) => issueRow(c.title, c.detail, c.priority === 'high', c.priority === 'high' ? 'Ưu tiên cao' : 'Theo dõi')).join('')}</div>`
                : '<div class="empty">Chưa ghi nhận bệnh nền.</div>';
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    /** Bài hướng dẫn dài: gập lại, bấm mới mở. Bài hạ đường huyết mở sẵn (cần biết khi khẩn cấp). */
    async function drawArticles() {
        const box = screen.querySelector('#articles');
        try {
            const { data } = await api('/articles?type=guide_');
            const wanted = ['guide_hypoglycemia', 'guide_insulin', 'guide_diet'];
            const list = wanted.map((t) => data.find((a) => a.type === t)).filter(Boolean);
            box.innerHTML = list.length ? list.map((a) => {
                const t = draftTitle(a.title);
                return `<details class="card article fold" ${a.type === 'guide_hypoglycemia' ? 'open' : ''}><summary><span class="fold-ic">${icon(GUIDE_IC[a.type] || 'info', { size: 16 })}</span><span class="grow">${t.html}</span></summary><div class="fold-body">${linesToList(a.content, a.type !== 'guide_diet')}</div></details>`;
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
    /** Một dòng vấn đề sức khoẻ (bệnh nền, điều AI lưu ý): chấm màu theo mức ưu tiên. */
    const issueRow = (title, detail, high, label, basedOn = '') => `<div class="issue ${high ? 'high' : ''}"><div class="issue-h"><b>${esc(title)}</b><span class="chip ${high ? 'bad' : 'info'}">${label}</span></div>
        ${detail ? `<p class="small ink2">${esc(detail)}</p>` : ''}${basedOn ? `<p class="small muted">Căn cứ: ${esc(basedOn)}</p>` : ''}</div>`;
    /** Mục thu gọn: kế hoạch AI khá dài, chỉ mở phần người dùng cần. */
    const fold = (ic, title, inner, show) => (show ? `<details class="card article fold"><summary><span class="fold-ic">${icon(ic, { size: 16 })}</span><span class="grow">${esc(title)}</span></summary><div class="fold-body">${inner}</div></details>` : '');

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
                    <button class="btn" data-act="careplan">${icon('sparkles', { size: 18 })} Lập kế hoạch (khoảng 1 phút)</button></div>`;
                return;
            }
            const c = data.content || {};
            const diet = c.diet || {};
            const week = weeklyMenu(c);
            const issues = c.key_issues || [];
            box.innerHTML = `
                ${c.summary || issues.length ? `<div class="card cp-summary">
                    ${c.summary ? `<p class="cp-lead">${icon('sparkles', { size: 16 })}<span>${esc(c.summary)}</span></p>` : ''}
                    ${issues.length ? `<div class="issues">${issues.map((i) => issueRow(i.title, i.detail, i.priority === 'high', i.priority === 'high' ? 'Ưu tiên' : 'Theo dõi', i.based_on)).join('')}</div>` : ''}
                    <div class="small muted" style="margin-top:10px">Lập ${esc((data.created_at || '').slice(0, 10).split('-').reverse().join('/'))} · dựa trên ${data.sources?.medications ?? 0} thuốc, ${data.sources?.lab_results ?? 0} chỉ số xét nghiệm</div></div>` : ''}
                <div class="card menu-card" id="menu-card"></div>
                ${c.warning_signs?.length ? `<div class="alert bad"><div class="ico">${icon('warn', { size: 18 })}</div><div><b>Đi khám ngay nếu có</b>${list(c.warning_signs)}</div></div>` : ''}
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
            box.innerHTML = rows ? `<h3>Thực đơn gợi ý một ngày</h3><div class="meals-list">${rows}</div><p class="small muted" style="margin:8px 0 0">Bấm “Lập lại” để AI lên thực đơn 7 ngày khác nhau.</p>` : '';
            box.hidden = !rows;
            return;
        }
        box.innerHTML = `<h3>Thực đơn 7 ngày</h3>
            <div class="seg menu-days" role="tablist">${WEEKDAYS.map((_, i) => `<button type="button" role="tab" data-act="menu-day" data-i="${i}" class="${i === dayIndex ? 'on' : ''}" aria-selected="${i === dayIndex}">${WEEKDAY_SHORT[i]}</button>`).join('')}</div>
            <p class="small muted" style="margin:10px 0 6px">${WEEKDAYS[dayIndex]}${dayIndex === (new Date().getDay() + 6) % 7 ? ' · hôm nay' : ''}</p><div class="meals-list">${menuRows(week[dayIndex])}</div>`;
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
            ${data.exercise_safety ? `<p class="small muted ex-safety">${icon('warn', { size: 14 })} ${esc(data.exercise_safety)}</p>` : ''}`;
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
    drawFrame();
    drawRx();
    drawCarePlan();
    drawConditions();
    drawArticles();
}
