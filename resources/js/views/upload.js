/**
 * Tải ảnh khám bệnh (nhiều ảnh một lúc) → AI đọc từng ảnh → xác nhận thuốc → lập lịch + chế độ ăn.
 * Ảnh được vẽ lại qua canvas trước khi gửi: đúng chiều, nhỏ gọn, bỏ hết metadata (vị trí GPS…).
 */
import { api, apiUpload } from '../core/api.js';
import { todayVN, dm, addDays } from '../core/format.js';
import { esc, delegate, skeleton, errorBox } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { toast, confirmDialog } from '../ui/shell.js';
import { fromUsageRule, savePrescription } from './rx.js';

const TYPE_LABEL = { don: 'Đơn thuốc', xn: 'Xét nghiệm', cdha: 'Chẩn đoán hình ảnh', kham: 'Phiếu khám', hd: 'Hướng dẫn', thuoc: 'Vỏ hộp thuốc', khac: 'Giấy tờ khác' };
const MAX_SIDE = 2200;

/** Vẽ lại ảnh: xoay đúng chiều theo EXIF, thu nhỏ, xuất JPEG — metadata bị loại bỏ. */
async function prepareImage(file) {
    if (!file.type.startsWith('image/') || file.type === 'image/gif') return file;
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        const g = canvas.getContext('2d');
        g.fillStyle = '#fff';
        g.fillRect(0, 0, canvas.width, canvas.height);
        g.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close?.();
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.88));
        return blob ? new File([blob], 'anh.jpg', { type: 'image/jpeg' }) : file;
    } catch (_e) {
        return file; // Trình duyệt cũ / HEIC: gửi nguyên, máy chủ vẫn bỏ metadata.
    }
}

function resultText(res) {
    if (res.status === 'rejected_identity') return ['bad', (res.message || 'Ảnh CCCD / BHYT — không lưu')];
    if (res.status === 'duplicate') return ['info', '↺ ' + (res.message || 'Ảnh đã có trong hồ sơ — không lưu lại')];
    if (res.status === 'stored_retake') return ['info', '↺ Bản chụp lại của phiếu đã có — không nhập lại dữ liệu'];
    const d = res.data;
    if (!d) return ['bad', 'Không lưu được'];
    if (d.ai_status === 'failed') return ['warn', 'Đã lưu ảnh, AI chưa đọc được'];
    if (d.ai_status === 'skipped') return ['info', 'Đã lưu (PDF — chưa đọc tự động)'];
    const parts = [TYPE_LABEL[d.type] || 'Giấy tờ'];
    if (d.medications_count) parts.push(`${d.medications_count} thuốc`);
    if (d.findings?.length && !d.medications_count) parts.push(`${d.findings.length} ý chính`);
    if (d.masked_count) parts.push(`đã ẩn ${d.masked_count} số định danh`);
    return ['good', '✓ ' + parts.join(' · ')];
}

export async function renderUpload(ctx) {
    const pid = ctx.patient.id;
    const screen = ctx.render({
        title: 'Tải ảnh khám bệnh',
        sub: 'AI đọc đơn thuốc, xét nghiệm, giấy khám giúp bạn',
        tab: 'records',
        body: `<div class="card lift">
                <div class="drop" id="drop">
                    <div class="drop-ico">${icon('camera', { size: 36 })}</div>
                    <b>Chụp hoặc chọn ảnh</b>
                    <p class="small ink2">Đơn thuốc, phiếu xét nghiệm, siêu âm, giấy ra viện, vỏ hộp thuốc… Chọn được nhiều ảnh một lần.</p>
                    <div class="row wrap-row" style="justify-content:center">
                        <label class="btn"><input type="file" accept="image/*" capture="environment" data-pick hidden>${icon('camera', { size: 18 })} Chụp ảnh</label>
                        <label class="btn ghost"><input type="file" accept="image/*,application/pdf" multiple data-pick hidden>${icon('image', { size: 18 })} Chọn từ máy</label>
                    </div>
                </div>
                <ul class="tips small ink2">
                    <li>Chụp thẳng, đủ sáng, thấy rõ cả trang. Mỗi trang một ảnh.</li>
                    <li><b>Số CCCD, mã thẻ BHYT tự động bị loại bỏ</b>, không lưu vào hồ sơ. Ảnh chỉ chụp thẻ CCCD / BHYT sẽ bị từ chối.</li>
                    <li>Ảnh gốc được mã hoá, chỉ bạn và người bạn mời xem được.</li>
                </ul>
            </div>
            <div id="queue"></div>
            <div id="done"></div>`,
    });

    const queue = [];
    let running = false;

    function draw() {
        screen.querySelector('#queue').innerHTML = queue.length ? `<div class="sec-title"><h2>Ảnh đã chọn</h2><span>${queue.filter((q) => q.state === 'done').length}/${queue.length} xong</span></div>
            <div class="card">${queue.map((q) => `<div class="up-row">
                <div class="up-thumb">${q.preview ? `<img src="${q.preview}" alt="">` : icon('file', { size: 22 })}</div>
                <div class="grow"><b class="up-name">${esc(q.name)}</b>
                    <div class="small ${q.tone ? '' : 'muted'}">${q.state === 'wait' ? 'Đang chờ…' : q.state === 'up' ? '<span class="spin"></span> Đang tải lên & AI đang đọc… (10–30 giây)' : `<span class="chip ${q.tone}">${esc(q.text)}</span>`}</div>
                </div></div>`).join('')}</div>` : '';

        const finished = queue.length && queue.every((q) => q.state === 'done');
        // Ảnh trùng / bản chụp lại không tính thuốc mới.
        const fresh = (q) => q.res?.data && !['duplicate', 'stored_retake'].includes(q.res.status);
        const meds = queue.reduce((n, q) => n + (fresh(q) ? q.res.data.medications_count || 0 : 0), 0);
        const stored = queue.filter(fresh).length;
        screen.querySelector('#done').innerHTML = finished ? `<div class="card done-card">
                <h3>Đã xử lý ${queue.length} ảnh</h3>
                <p class="small ink2" style="margin-top:0">${stored ? `Đã lưu ${stored} giấy tờ vào hồ sơ.` : 'Chưa lưu được giấy tờ nào.'} ${meds ? `AI tìm thấy <b>${meds} thuốc</b> — hãy kiểm tra lại trước khi lập lịch.` : ''}</p>
                ${meds ? '<button class="btn block" data-act="review">Kiểm tra thuốc & lập lịch uống →</button>' : ''}
                ${stored && !meds ? '<button class="btn block" data-act="plan">Lập chế độ ăn uống & sinh hoạt →</button>' : ''}
                <div class="row" style="margin-top:8px"><button class="btn ghost grow" data-act="more">+ Tải thêm ảnh</button><button class="btn ghost grow" data-act="records">Xem hồ sơ</button></div>
            </div>` : '';
    }

    async function run() {
        if (running) return;
        running = true;
        for (const q of queue) {
            if (q.state !== 'wait') continue;
            q.state = 'up';
            draw();
            try {
                const prepared = await prepareImage(q.file);
                const fd = new FormData();
                fd.append('file', prepared, prepared.name || q.name);
                q.res = await apiUpload(`/patients/${pid}/documents`, fd);
            } catch (error) {
                q.res = { status: 'error', data: null, message: error.message };
            }
            [q.tone, q.text] = q.res.status === 'error' ? ['bad', q.res.message] : resultText(q.res);
            q.state = 'done';
            draw();
        }
        running = false;
        if (queue.some((q) => q.state === 'wait')) run();
    }

    function add(files) {
        [...files].slice(0, 30).forEach((file) => {
            if (file.size > 15 * 1024 * 1024 && !file.type.startsWith('image/')) { toast(`${file.name}: quá 15 MB.`, 'bad'); return; }
            queue.push({ file, name: file.name, state: 'wait', preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null });
        });
        draw();
        run();
    }

    screen.addEventListener('change', (e) => { if (e.target.matches('[data-pick]')) { add(e.target.files); e.target.value = ''; } });
    const drop = screen.querySelector('#drop');
    drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('over'));
    drop.addEventListener('drop', (e) => { e.preventDefault(); drop.classList.remove('over'); add(e.dataTransfer.files); });

    delegate(screen, {
        review: () => ctx.go('/review'),
        records: () => ctx.go('/records'),
        more: () => screen.querySelector('[data-pick][multiple]').click(),
        plan: () => generateCarePlan(ctx),
    });
}

/** Gọi AI lập kế hoạch chăm sóc rồi mở màn Phác đồ. */
/** Lập kế hoạch chăm sóc; xong thì sang `after` (mặc định màn Phác đồ), `after: null` thì vẽ lại màn hiện tại. */
export async function generateCarePlan(ctx, { silentFail = false, after = '/plan' } = {}) {
    const overlay = document.createElement('div');
    overlay.className = 'busy';
    overlay.innerHTML = '<div class="busy-panel"><span class="spin big"></span><b>AI đang lên thực đơn, bài tập & chế độ sinh hoạt</b><p class="small">Dựa trên thuốc đang dùng và kết quả xét nghiệm · khoảng 1 phút</p></div>';
    document.body.append(overlay);
    try {
        await api(`/patients/${ctx.patient.id}/care-plan`, { method: 'POST' });
        toast('Đã lập thực đơn, bài tập và kế hoạch chăm sóc.');
        if (after) ctx.go(after); else ctx.refresh();
        return true;
    } catch (error) {
        if (!silentFail) toast(error.message, 'bad');
        return false;
    } finally {
        overlay.remove();
    }
}

/* ================= Xác nhận thuốc AI đọc được → lập lịch ================= */
const ANCHOR = { wake: 'Lúc ngủ dậy', breakfast: 'Bữa sáng', lunch: 'Bữa trưa', dinner: 'Bữa tối', sleep: 'Trước khi ngủ' };

export function describeDoses(rule) {
    if (!rule) return '<span style="color:var(--warn)">Chưa rõ giờ dùng — chạm “Sửa” để chọn giờ</span>';
    if (rule.type === 'supply') return 'Vật tư — không nhắc giờ';
    return rule.doses.map((d) => {
        if (d.fixed_time) return `${d.fixed_time} · ${esc(d.amount_text)}`;
        let when = ANCHOR[d.anchor] || d.anchor;
        if (['breakfast', 'lunch', 'dinner'].includes(d.anchor)) {
            when += d.offset_min < -10 ? ` — trước ăn ${-d.offset_min} phút` : d.offset_min < 0 ? ' — ngay trước ăn' : d.offset_min > 0 ? ' — sau ăn' : '';
        }
        return `${when} · <b>${esc(d.amount_text)}</b>`;
    }).join('<br>');
}

export async function renderReview(ctx) {
    const pid = ctx.patient.id;
    const screen = ctx.render({
        title: 'Kiểm tra thuốc',
        back: '/upload',
        tab: 'plan',
        body: `<div class="card lift"><div class="row" style="align-items:flex-start"><div class="pill-ico" style="background:var(--accent);color:var(--accent-ink)">!</div>
            <div class="grow small ink2"><b style="color:var(--ink)">Đối chiếu với ảnh gốc trước khi lưu.</b> AI có thể đọc nhầm chữ viết tay. Bỏ chọn thuốc không dùng nữa, chạm “Sửa” nếu giờ hoặc lượng chưa đúng. Ứng dụng <b>không thay đổi liều</b>.</div></div></div>
            <div id="docs">${skeleton(4)}</div>`,
    });

    let docs = [];
    let active = [];

    async function load() {
        try {
            [docs, active] = await Promise.all([
                api(`/patients/${pid}/pending-medications`).then((r) => r.data),
                api(`/patients/${pid}/prescriptions?date=${todayVN()}`).then((r) => r.data).catch(() => []),
            ]);
            // Thuốc đang dùng hoặc đã có ở phiếu khác (cùng đơn chụp nhiều lần) bỏ chọn sẵn.
            docs.forEach((d) => d.medications.forEach((m) => { m.on = !m.duplicate; }));
            draw();
        } catch (error) {
            screen.querySelector('#docs').innerHTML = errorBox(error.message);
        }
    }

    function draw() {
        const box = screen.querySelector('#docs');
        if (!docs.length) {
            box.innerHTML = `<div class="empty">Không còn thuốc nào chờ xác nhận.</div>
                <div class="row" style="margin-top:12px"><button class="btn grow" data-act="nav" data-to="/plan">Xem phác đồ</button><button class="btn ghost grow" data-act="nav" data-to="/upload">Tải thêm ảnh</button></div>`;
            return;
        }
        const total = docs.reduce((n, d) => n + d.medications.filter((m) => m.on).length, 0);
        box.innerHTML = `${docs.map((d, di) => `<div class="card">
                <div class="row" style="align-items:flex-start"><div class="grow"><h3>${esc(d.title)}</h3>
                    <div class="small muted">${d.document_date ? dm(d.document_date) : ''}${d.doctor_name ? ` · ${esc(d.doctor_name)}` : ''} · ${TYPE_LABEL[d.type] || ''}</div>
                    ${d.duplicate_of_id ? '<span class="chip info" style="margin-top:4px">↺ Bản chụp lại của phiếu đã có</span>' : ''}</div>
                    <button class="btn sm ghost" data-act="edit" data-di="${di}">Sửa</button></div>
                ${d.medications.map((m, mi) => `<label class="med rv ${m.on ? '' : 'off'}">
                    <input type="checkbox" data-di="${di}" data-mi="${mi}" ${m.on ? 'checked' : ''}>
                    <div class="grow"><b>${esc(m.drug_name)}</b>${m.quantity ? ` <span class="small muted">· ${esc(String(m.quantity).replace('.', ','))} ${esc(m.unit || '')}</span>` : ''}
                        ${m.duplicate ? `<div><span class="chip warn">↺ ${esc(m.duplicate.label)}</span></div>` : ''}
                        <div class="small ink2">${esc(m.dose_text || '')}</div>
                        <div class="how small">${describeDoses(m.usage_rule)}</div>
                        ${m.duration_days ? `<div class="small muted">Dùng ${m.duration_days} ngày</div>` : ''}
                    </div></label>`).join('')}
                <button class="link-btn small" data-act="dismiss" data-di="${di}">Bỏ qua phiếu này (đơn cũ / không dùng)</button>
            </div>`).join('')}
            ${active.length ? `<label class="check card flat"><input type="checkbox" id="replace"><span><b>Đây là đơn mới thay cho đơn đang dùng</b><br><span class="small ink2">Kết thúc ${active.length} đơn hiện tại (${esc(active.map((p) => p.doctor_name || 'Đơn thuốc').join(', '))}) từ hôm nay. Không chọn nếu dùng song song.</span></span></label>` : ''}
            <button class="btn block" data-act="save" ${total ? '' : 'disabled'}>Lưu ${total} thuốc & tạo lịch uống</button>
            <p class="small muted center">Sau khi lưu, AI sẽ lập chế độ ăn uống theo thuốc và kết quả xét nghiệm.</p>`;
    }

    screen.addEventListener('change', (e) => {
        // Thay đơn cũ: thuốc "đang dùng" sẽ hết hiệu lực cùng đơn cũ, nên chọn lại để đơn mới có thuốc đó.
        if (e.target.id === 'replace') {
            docs.forEach((d) => d.medications.forEach((m) => { if (m.duplicate?.reason === 'active') m.on = e.target.checked; }));
            draw();
            screen.querySelector('#replace').checked = e.target.checked;
            return;
        }
        if (e.target.dataset.mi === undefined) return;
        docs[Number(e.target.dataset.di)].medications[Number(e.target.dataset.mi)].on = e.target.checked;
        draw();
    });

    delegate(screen, {
        edit: (el) => {
            const d = docs[Number(el.dataset.di)];
            ctx.store.set('rxDraft', { document_id: d.document_id, doctor_name: d.doctor_name, prescribed_at: d.document_date, back: '/review', items: d.medications.filter((m) => m.on).map(fromUsageRule) });
            ctx.go('/rx/new');
        },
        dismiss: async (el) => {
            const d = docs[Number(el.dataset.di)];
            if (!await confirmDialog(`Bỏ qua các thuốc của “${d.title}”? Ảnh vẫn nằm trong hồ sơ.`, { ok: 'Bỏ qua' })) return;
            try {
                await api(`/patients/${pid}/documents/${d.document_id}/dismiss-medications`, { method: 'POST' });
                docs.splice(Number(el.dataset.di), 1);
                draw();
            } catch (error) { toast(error.message, 'bad'); }
        },
        save: async (el) => {
            el.disabled = true;
            const today = todayVN();
            try {
                if (screen.querySelector('#replace')?.checked) {
                    for (const p of active) await api(`/patients/${pid}/prescriptions/${p.id}/close`, { method: 'POST', body: { continued_item_ids: [] } });
                }
                let saved = 0;
                for (const d of docs) {
                    const meds = d.medications.filter((m) => m.on);
                    if (!meds.length) continue;
                    const durations = meds.map((m) => m.duration_days).filter(Boolean);
                    const res = await savePrescription(pid, {
                        document_id: d.document_id,
                        doctor_name: d.doctor_name || d.title || null,
                        prescribed_at: d.document_date && d.document_date <= today ? d.document_date : today,
                        starts_at: today,
                        ends_at: durations.length === meds.length ? addDays(today, Math.max(...durations) - 1) : null,
                        items: meds.map((m) => ({
                            drug_name: m.drug_name,
                            dose_text: m.dose_text || '(chưa ghi cách dùng)',
                            usage_rule: m.usage_rule,
                            prescribed_quantity: m.quantity ?? null,
                            quantity_unit: m.unit || null,
                            is_long_term: !m.duration_days,
                        })),
                    });
                    if (res) saved += meds.length;
                }
                toast(`Đã tạo lịch cho ${saved} thuốc.`);
                if (!await generateCarePlan(ctx, { silentFail: true })) {
                    toast('Đã lưu lịch thuốc. Chế độ ăn uống có thể lập lại ở mục Phác đồ.');
                    ctx.go('/plan');
                }
            } catch (error) {
                toast(error.message, 'bad');
                el.disabled = false;
            }
        },
    });

    load();
}
