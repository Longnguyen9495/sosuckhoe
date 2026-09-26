/** Đăng ký khách mới — 7 bước (PLAN.md mục 5.3). Lưu nháp sau mỗi bước, quay lại được. */
import { api } from '../core/api.js';
import { esc, delegate } from '../ui/dom.js';
import { toast } from '../ui/shell.js';
import { mountRxEditor, localPreview, previewHtml } from './rx.js';

const STEPS = ['Tài khoản', 'Đồng ý điều khoản', 'Người bệnh', 'Giờ sinh hoạt', 'Đơn thuốc', 'Xem trước lịch', 'Mời người nhà'];

export async function renderOnboarding(ctx, params) {
    let state;
    try {
        state = await api('/onboarding');
    } catch (error) {
        toast(error.message, 'bad');
        return;
    }
    const data = state.data || {};
    const step = Math.min(7, Math.max(1, Number(params.step) || state.current_step || 1));

    ctx.root.className = 'app';
    ctx.root.innerHTML = `<header class="hero"><div class="brand-mark"><span class="avatar">S</span>Sổ Sức Khỏe</div><h1>Tạo hồ sơ người bệnh</h1><p class="sub">Bước ${step}/7 · ${STEPS[step - 1]}</p></header>
        <main class="wrap"><div class="card lift">
            <div class="stepper" aria-hidden="true">${STEPS.map((_, i) => `<i class="${i < step ? 'on' : ''}"></i>`).join('')}</div>
            <form id="step-form" novalidate></form>
        </div></main>`;
    const form = ctx.root.querySelector('#step-form');

    const nav = (next = 'Tiếp tục') => `<div class="row" style="margin-top:16px">${step > 1 ? '<button type="button" class="btn ghost" data-act="back">← Quay lại</button>' : ''}<button class="btn grow" type="submit">${next}</button></div>`;
    const save = async (payload) => api(`/onboarding/step/${step}`, { method: 'POST', body: { data: payload } });
    const goStep = (n) => ctx.go(`/onboarding/${n}`);

    delegate(form, {
        back: async () => { try { await api('/onboarding/back', { method: 'POST' }); } catch (_e) { /* vẫn quay lại */ } goStep(step - 1); },
    });

    let collect = async () => ({});
    let editor = null;

    if (step === 1) {
        form.innerHTML = `<h3>Chào mừng đến với Sổ Sức Khỏe</h3>
            <p class="ink2">Trong khoảng 15 phút, bạn sẽ có lịch uống thuốc, lịch đo và mốc tái khám cho người thân — sắp xếp theo đúng đơn bác sĩ.</p>
            <ul class="small ink2"><li>Chuẩn bị: đơn thuốc gần nhất, giờ ăn và giờ ngủ của người bệnh.</li><li>Có thể dừng giữa chừng — các bước đã điền được lưu lại.</li></ul>${nav('Bắt đầu')}`;
        collect = async () => ({ started: true });
    }

    if (step === 2) {
        form.innerHTML = '<h3>Điều khoản và dữ liệu sức khỏe</h3><div id="consent" class="consent-text">Đang tải…</div>';
        let version = null;
        try {
            version = await api('/consents/version');
            form.querySelector('#consent').textContent = version.content || 'Chưa có điều khoản.';
            form.insertAdjacentHTML('beforeend', `${version.is_draft ? '<p class="small" style="color:var(--warn)">Bản điều khoản đang là bản nháp chờ pháp chế duyệt.</p>' : ''}
                <label class="check"><input type="checkbox" id="agree" ${data.consent?.accepted ? 'checked' : ''}><span>Tôi đã đọc và đồng ý cho Sổ Sức Khỏe xử lý dữ liệu sức khỏe của người bệnh theo điều khoản trên.</span></label>${nav()}`);
        } catch (error) {
            form.querySelector('#consent').textContent = error.message;
        }
        collect = async () => {
            if (!form.querySelector('#agree')?.checked) throw new Error('Cần đồng ý điều khoản để tiếp tục.');
            return { accepted: true, consent_version_id: version?.id };
        };
    }

    if (step === 3) {
        const p = data.patient_profile || {};
        let templates = [];
        try { templates = await api('/templates'); } catch (_e) { /* không bắt buộc */ }
        const chosen = new Set(p.condition_template_ids || []);
        form.innerHTML = `<h3>Người cần chăm sóc</h3>
            <div class="field"><label for="full_name">Họ tên (hoặc cách gọi)</label><input id="full_name" value="${esc(p.full_name || '')}" placeholder="VD: Bà Lan" required></div>
            <div class="grid2"><div class="field"><label for="birth_year">Năm sinh</label><input id="birth_year" inputmode="numeric" maxlength="4" value="${esc(p.birth_year || '')}" required></div>
            <div class="field"><label for="gender">Giới tính</label><select id="gender">${[['female', 'Nữ'], ['male', 'Nam'], ['other', 'Khác']].map(([v, l]) => `<option value="${v}" ${p.gender === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div></div>
            <div class="field"><label for="allergies">Dị ứng thuốc (nếu có)</label><input id="allergies" value="${esc(p.allergies || '')}" placeholder="Không"></div>
            <div class="field"><span class="label">Bệnh nền — chọn để có lịch đo và ngưỡng mặc định (bác sĩ sẽ xác nhận sau)</span>
                ${templates.map((t) => `<label class="check"><input type="checkbox" data-tpl="${esc(t.id)}" ${chosen.has(t.id) ? 'checked' : ''}>${esc(t.name)}</label>`).join('') || '<span class="small muted">Chưa tải được danh sách.</span>'}</div>${nav()}`;
        collect = async () => ({
            full_name: form.querySelector('#full_name').value.trim(),
            birth_year: parseInt(form.querySelector('#birth_year').value, 10) || null,
            gender: form.querySelector('#gender').value,
            allergies: form.querySelector('#allergies').value.trim() || null,
            condition_template_ids: [...form.querySelectorAll('[data-tpl]:checked')].map((x) => x.dataset.tpl),
        });
    }

    if (step === 4) {
        const r = data.routines || { wake_time: '06:00', breakfast_time: '07:00', lunch_time: '12:00', dinner_time: '18:30', sleep_time: '22:00' };
        const field = (k, l) => `<div class="field"><label for="${k}">${l}</label><input type="time" id="${k}" value="${esc(r[k] || '')}" required></div>`;
        form.innerHTML = `<h3>Giờ sinh hoạt của người bệnh</h3><p class="small ink2">Giờ uống thuốc “sau ăn”, “trước ăn” được tính theo các giờ này.</p>
            <div class="grid2">${field('wake_time', 'Thức dậy')}${field('breakfast_time', 'Ăn sáng')}${field('lunch_time', 'Ăn trưa')}${field('dinner_time', 'Ăn tối')}${field('sleep_time', 'Đi ngủ')}</div>${nav()}`;
        collect = async () => Object.fromEntries(['wake_time', 'breakfast_time', 'lunch_time', 'dinner_time', 'sleep_time'].map((k) => [k, form.querySelector(`#${k}`).value]));
    }

    if (step === 5) {
        const rx = (data.prescriptions || [])[0] || {};
        form.innerHTML = `<h3>Đơn thuốc đang dùng</h3>
            <div class="field"><label for="doctor">Bác sĩ kê đơn</label><input id="doctor" value="${esc(rx.doctor_name || '')}"></div>
            <div id="editor"></div>
            <p class="small muted">Có thể bỏ trống và nhập sau ở mục Phác đồ, hoặc chụp đơn để AI đọc.</p>${nav()}`;
        editor = mountRxEditor(form.querySelector('#editor'));
        collect = async () => {
            const items = editor.getItems();
            return { prescriptions: items.length ? [{ doctor_name: form.querySelector('#doctor').value.trim() || null, items }] : [] };
        };
    }

    if (step === 6) {
        const routine = data.routines || {};
        const items = ((data.prescriptions || [])[0]?.items) || [];
        const rows = routine.breakfast_time ? localPreview(items, routine) : [];
        const noTime = items.filter((i) => !i.usage_rule || !(i.usage_rule.doses || []).length);
        form.innerHTML = `<h3>Lịch một ngày dự kiến</h3>
            ${previewHtml(rows, noTime.map((i) => ({ drug_name: i.drug_name, message: i.usage_rule?.type === 'supply' ? 'Vật tư — không nhắc giờ.' : 'Chưa có giờ dùng — sẽ không được nhắc.' })))}
            <p class="small muted">Lượt đo đường huyết / huyết áp sẽ được thêm theo bệnh nền đã chọn. Sửa được sau ở mục Phác đồ và Cài đặt.</p>${nav('Đúng, tiếp tục')}`;
        collect = async () => ({ confirmed: true });
    }

    if (step === 7) {
        form.innerHTML = `<h3>Mời người nhà hoặc bác sĩ</h3>
            <p class="ink2">Sau khi tạo hồ sơ, vào <b>Cài đặt → Thành viên → Mời</b> để mời người nhà cùng theo dõi hoặc bác sĩ xác nhận ngưỡng.</p>${nav('Hoàn tất và mở lịch')}`;
        collect = async () => ({ later: true });
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('button[type=submit]');
        button.disabled = true;
        try {
            const payload = await collect();
            await save(payload);
            if (step < 7) return goStep(step + 1);
            const result = await api('/onboarding/complete', { method: 'POST' });
            const tenants = (await api('/tenants')).data;
            ctx.store.set('tenant', tenants.find((t) => t.id === result.tenant_id));
            const patients = (await api('/patients')).data;
            ctx.store.set('patient', patients.find((p) => p.id === result.patient_id));
            toast('Đã tạo hồ sơ. Đây là lịch hôm nay.');
            ctx.go('/today');
        } catch (error) {
            toast(error.message, 'bad');
            button.disabled = false;
        }
    });
}
