/**
 * Đăng ký nhanh (tên + SĐT + ngày sinh), đăng nhập SĐT + mật khẩu, xác thực 2 lớp bác sĩ,
 * chọn không gian chăm sóc, chọn người bệnh. OTP tạm tắt trên giao diện (API vẫn giữ).
 */
import { api, setSession, getUserIdFromToken } from '../core/api.js';
import { announceUser } from '../core/offline.js';
import { esc, delegate } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { toast, openSheet, closeSheet } from '../ui/shell.js';
import { FEATURES } from '../core/features.js';

function authShell(ctx, inner, { title = 'Sổ theo dõi điều trị tại nhà', sub = 'Lịch thuốc, chỉ số và tái khám — theo đúng đơn bác sĩ.' } = {}) {
    ctx.root.className = 'app';
    ctx.root.innerHTML = `<div class="auth">
        <header class="hero">
            <div class="topbar"><a class="brand-mark grow" href="#/"><span class="avatar">S</span>Sổ Sức Khỏe</a><a class="icon-btn" href="#/" aria-label="Về trang giới thiệu" title="Trang giới thiệu">✕</a></div>
            <h1>${esc(title)}</h1>
            <p class="sub">${esc(sub)}</p>
        </header>
        <div class="card">${inner}</div>
        <p class="small muted center" style="padding:0 24px">Ứng dụng không thay thế tư vấn, chẩn đoán hay quyết định điều trị của bác sĩ.</p>
    </div>`;
    return ctx.root.querySelector('.card');
}

/** Giống DefaultPassword (PHP): tên bỏ dấu, bỏ khoảng trắng, chữ thường + 4 số cuối SĐT. */
export function defaultPassword(name, phone) {
    const slug = String(name || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').replace(/[^A-Za-z0-9]/g, '').toLowerCase();
    return slug + String(phone || '').replace(/\D/g, '').slice(-4);
}

const cleanPhone = (v) => String(v || '').replace(/[\s.-]/g, '');
const PHONE_OK = /^(\+84|0)\d{9}$/;

async function afterLogin(ctx, data, next = '/tenants') {
    setSession(data.token, data.user_id);
    await announceUser(data.user_id);
    ctx.store.batch({ tenant: data.tenant || null, patient: data.patient || null });
    ctx.go(next);
}

const passwordField = (id = 'password', label = 'Mật khẩu', autocomplete = 'current-password') => `<div class="field">
    <label for="${id}">${label}</label>
    <div class="pw"><input id="${id}" name="${id}" type="password" autocomplete="${autocomplete}" required><button type="button" class="pw-eye" data-act="toggle-pw" data-for="${id}" aria-label="Hiện mật khẩu">${icon('eye', { size: 20 })}</button></div>
</div>`;

function togglePw(root) {
    delegate(root, {
        'toggle-pw': (el) => {
            const input = root.querySelector(`#${el.dataset.for}`);
            input.type = input.type === 'password' ? 'text' : 'password';
            el.setAttribute('aria-label', input.type === 'password' ? 'Hiện mật khẩu' : 'Ẩn mật khẩu');
        },
    });
}

/* ================= Đăng nhập: số điện thoại + mật khẩu ================= */
export function renderLogin(ctx) {
    const remembered = ctx.store.get('loginPhone') || '';
    const card = authShell(ctx, `
        <form id="login-form" novalidate>
            <h3>Đăng nhập</h3>
            <div class="field">
                <label for="phone">Số điện thoại</label>
                <input id="phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="VD 0912345678" value="${esc(remembered)}" required>
            </div>
            ${passwordField()}
            <p class="hint-box">Mật khẩu mặc định là <b>tên viết liền, không dấu</b> + <b>4 số cuối</b> số điện thoại.<br>VD: Nguyễn Văn An, 0912 34<b>5678</b> → <code>nguyenvanan5678</code></p>
            <button class="btn block" type="submit">Đăng nhập</button>
            <p class="center small" style="margin:14px 0 0">Chưa có sổ sức khỏe? <a href="#/start"><b>Tạo mới</b></a></p>
        </form>`, { title: 'Chào mừng trở lại', sub: 'Đăng nhập bằng số điện thoại và mật khẩu.' });

    const form = card.querySelector('#login-form');
    togglePw(card);
    (remembered ? form.password : form.phone).focus();

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const phone = cleanPhone(form.phone.value);
        if (!PHONE_OK.test(phone)) return toast('Số điện thoại chưa đúng (10 số, bắt đầu bằng 0).', 'bad');
        if (!form.password.value) return toast('Nhập mật khẩu.', 'bad');
        const button = form.querySelector('button[type=submit]');
        button.disabled = true;
        try {
            const { data } = await api('/auth/login', { method: 'POST', body: { phone, password: form.password.value, device_name: 'Trình duyệt' } });
            ctx.store.set('loginPhone', null);
            await afterLogin(ctx, data);
        } catch (error) {
            toast(error.message, 'bad');
            button.disabled = false;
        }
    });
}

/* ================= Đăng ký nhanh ================= */
export function renderStart(ctx) {
    const card = authShell(ctx, `
        <form id="start-form" novalidate>
            <h3>Thông tin cá nhân</h3>
            <p class="small ink2" style="margin-top:0">Chỉ 3 thông tin — không cần mã OTP.</p>
            <div class="field">
                <label for="name">Họ và tên</label>
                <input id="name" name="name" autocomplete="name" placeholder="VD: Nguyễn Văn An" maxlength="120" required>
            </div>
            <div class="field">
                <label for="phone">Số điện thoại</label>
                <input id="phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="VD 0912345678" required>
            </div>
            <div class="field">
                <label for="birth_date">Ngày tháng năm sinh</label>
                <input id="birth_date" name="birth_date" type="date" max="${new Date().toISOString().slice(0, 10)}" min="1900-01-01" required>
            </div>
            <div class="pw-preview" id="pw-preview" hidden>Mật khẩu của bạn sẽ là <code id="pw-value"></code><span class="small muted"> — đổi được sau trong mục Thông tin cá nhân.</span></div>
            <label class="check"><input type="checkbox" id="accepted"><span class="small">Tôi đồng ý để Sổ Sức Khỏe lưu và xử lý dữ liệu sức khỏe của tôi (kể cả dùng AI đọc ảnh giấy tờ khám bệnh) theo <a href="#" data-act="terms">điều khoản</a>. Số CCCD và mã BHYT không được lưu.</span></label>
            <button class="btn block" type="submit">Tạo sổ sức khỏe</button>
            <p class="center small" style="margin:14px 0 0">Đã có tài khoản? <a href="#/login"><b>Đăng nhập</b></a></p>
        </form>`, { title: 'Tạo sổ sức khỏe', sub: 'Mất chưa tới 1 phút.' });

    const form = card.querySelector('#start-form');
    form.name.focus();
    const preview = () => {
        const phone = cleanPhone(form.phone.value);
        const ok = form.name.value.trim().length >= 2 && PHONE_OK.test(phone);
        card.querySelector('#pw-preview').hidden = !ok;
        if (ok) card.querySelector('#pw-value').textContent = defaultPassword(form.name.value, phone);
    };
    form.addEventListener('input', preview);

    delegate(card, {
        terms: async (_el, event) => {
            event.preventDefault();
            const sheet = openSheet('<h3>Điều khoản xử lý dữ liệu sức khỏe</h3><div class="consent-text">Đang tải…</div><button class="btn block" style="margin-top:12px" data-act="close">Đã hiểu</button>');
            sheet.querySelector('[data-act=close]').addEventListener('click', () => closeSheet());
            try {
                const v = await api('/consents/version');
                sheet.querySelector('.consent-text').textContent = v.content || 'Chưa có điều khoản.';
            } catch (error) {
                sheet.querySelector('.consent-text').textContent = error.message;
            }
        },
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const name = form.name.value.trim();
        const phone = cleanPhone(form.phone.value);
        const birthDate = form.birth_date.value;
        if (name.length < 2) return toast('Nhập họ và tên.', 'bad');
        if (!PHONE_OK.test(phone)) return toast('Số điện thoại chưa đúng (10 số, bắt đầu bằng 0).', 'bad');
        if (!birthDate) return toast('Chọn ngày tháng năm sinh.', 'bad');
        if (!form.accepted.checked) return toast('Cần đồng ý điều khoản để tạo sổ.', 'bad');

        const button = form.querySelector('button[type=submit]');
        button.disabled = true;
        try {
            const { data } = await api('/auth/register', { method: 'POST', body: { name, phone, birth_date: birthDate, accepted: true, device_name: 'Trình duyệt' } });
            await afterLogin(ctx, data, '/upload');
            showPasswordOnce(data.default_password, phone);
        } catch (error) {
            button.disabled = false;
            if (error.code === 'PHONE_EXISTS') {
                toast(error.payload.message || 'Số điện thoại đã có sổ. Vui lòng đăng nhập.', 'bad');
                ctx.store.set('loginPhone', phone);
                ctx.go('/login');
                return;
            }
            toast(error.message, 'bad');
        }
    });
}

function showPasswordOnce(password, phone) {
    const sheet = openSheet(`<div id="pw-done"><h3>${icon('party', { size: 22 })} Đã tạo sổ sức khỏe</h3>
        <p class="ink2" style="margin-top:0">Lần sau đăng nhập bằng số điện thoại <b>${esc(phone)}</b> và mật khẩu:</p>
        <div class="pw-big"><code>${esc(password)}</code><button class="btn sm ghost" data-act="copy">Chép</button></div>
        <p class="small muted">Hãy ghi lại. Có thể đổi mật khẩu trong mục <b>Thông tin cá nhân</b>.</p>
        <button class="btn block" data-act="ok">Tiếp tục: tải ảnh khám bệnh</button></div>`);
    delegate(sheet.querySelector("#pw-done"), {
        copy: async () => { try { await navigator.clipboard.writeText(password); toast('Đã chép mật khẩu.'); } catch (_e) { toast('Không chép được, hãy ghi tay.', 'bad'); } },
        ok: () => closeSheet(),
    });
}


export function renderTwoFactor(ctx) {
    const card = authShell(ctx, `
        <form id="tfa-form" novalidate>
            <h3>Xác thực hai lớp</h3>
            <p class="small ink2">Tài khoản bác sĩ cần nhập mã 6 số từ ứng dụng xác thực (Google Authenticator, Microsoft Authenticator…) hoặc một mã khôi phục.</p>
            <div class="field"><label for="tfa">Mã xác thực</label><input id="tfa" name="code" inputmode="numeric" autocomplete="one-time-code" required></div>
            <button class="btn block" type="submit">Xác nhận</button>
            <button class="link-btn" type="button" data-act="setup">Chưa thiết lập? Thiết lập xác thực hai lớp</button>
            <div id="setup-box"></div>
        </form>`);
    const form = card.querySelector('#tfa-form');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            const result = await api('/auth/2fa/challenge', { method: 'POST', body: { code: form.code.value.trim(), device_name: 'Trình duyệt' } });
            setSession(result.token, getUserIdFromToken());
            toast('Đã xác thực hai lớp.');
            ctx.go(ctx.patient ? (FEATURES.doctor ? '/doctor' : '/today') : '/patients');
        } catch (error) {
            toast(error.message, 'bad');
        }
    });

    delegate(card, {
        setup: async () => {
            const box = card.querySelector('#setup-box');
            try {
                const data = await api('/auth/2fa/setup', { method: 'POST' });
                box.innerHTML = `<div class="card flat" style="margin-top:12px">
                    <p class="small">Thêm tài khoản vào ứng dụng xác thực bằng khoá sau, rồi nhập mã 6 số để kích hoạt:</p>
                    <p><code style="word-break:break-all">${esc(data.secret || '')}</code></p>
                    <div class="field"><label for="tfa-confirm">Mã 6 số đầu tiên</label><input id="tfa-confirm" inputmode="numeric" maxlength="6"></div>
                    <button class="btn ghost block" type="button" data-act="confirm-setup">Kích hoạt</button>
                </div>`;
            } catch (error) {
                box.innerHTML = `<p class="small muted">${esc(error.message)}</p>`;
            }
        },
        'confirm-setup': async () => {
            try {
                const data = await api('/auth/2fa/confirm', { method: 'POST', body: { code: card.querySelector('#tfa-confirm').value.trim() } });
                const codes = data.recovery_codes || [];
                card.querySelector('#setup-box').innerHTML = `<div class="alert good"><div class="ico">✓</div><div><b>Đã kích hoạt.</b><p>Lưu các mã khôi phục sau ở nơi an toàn (mỗi mã dùng 1 lần): ${codes.map(esc).join(', ')}</p></div></div>`;
            } catch (error) {
                toast(error.message, 'bad');
            }
        },
    });
}

export async function renderTenants(ctx) {
    const card = authShell(ctx, '<h3>Chọn không gian chăm sóc</h3><div id="list"><div class="skeleton tall"></div></div>');
    const list = card.querySelector('#list');
    try {
        const { data } = await api('/tenants');
        if (data.length === 1) {
            ctx.store.set('tenant', data[0]);
            return ctx.go('/patients');
        }
        list.innerHTML = data.length
            ? data.map((t) => `<button class="choice" data-act="pick" data-id="${esc(t.id)}"><span class="avatar">${icon(t.type === 'clinic' ? 'hospital' : 'house', { size: 20 })}</span><span class="grow"><b>${esc(t.name)}</b><br><span class="small muted">${t.type === 'clinic' ? 'Phòng khám' : 'Gia đình'}</span></span></button>`).join('')
            : '<p class="small ink2">Tài khoản chưa có hồ sơ nào. Tạo hồ sơ người bệnh đầu tiên để bắt đầu.</p>';
        list.insertAdjacentHTML('beforeend', `${FEATURES.caregiver || !data.length ? '<button class="btn ghost block" data-act="new">+ Tạo sổ sức khỏe</button>' : ''}<button class="link-btn" data-act="logout">Đăng xuất</button>`);
        delegate(list, {
            pick: (el) => { ctx.store.batch({ tenant: data.find((t) => t.id === el.dataset.id), patient: null }); ctx.go('/patients'); },
            new: () => ctx.go('/onboarding/1'),
            logout: () => ctx.logout(),
        });
    } catch (error) {
        list.innerHTML = `<div class="error-box">${esc(error.message)}</div>`;
    }
}

export async function renderPatients(ctx) {
    const card = authShell(ctx, `<h3>Chọn người bệnh</h3><p class="small muted">${esc(ctx.tenant?.name || '')}</p><div id="list"><div class="skeleton tall"></div></div>`);
    const list = card.querySelector('#list');
    try {
        const { data } = await api('/patients');
        const open = (p) => { ctx.store.set('patient', p); ctx.go(FEATURES.doctor && p.access_role === 'doctor' ? '/doctor' : '/today'); };
        if (data.length === 1) return open(data[0]);
        list.innerHTML = (data.length
            ? data.map((p) => `<button class="choice" data-act="pick" data-id="${esc(p.id)}"><span class="avatar">${esc((p.full_name || '?').replace(/^(Bà|Ông)\s+/, '').charAt(0))}</span><span class="grow"><b>${esc(p.full_name)}</b><br><span class="small muted">${p.birth_year ? `Sinh ${p.birth_year}` : ''}</span></span></button>`).join('')
            : '<p class="small ink2">Không gian này chưa có người bệnh bạn được phép xem.</p>')
            + (FEATURES.caregiver ? '<button class="link-btn" data-act="back">← Đổi không gian</button>' : '<button class="link-btn" data-act="logout">Đăng xuất</button>');
        delegate(list, {
            pick: (el) => open(data.find((p) => p.id === el.dataset.id)),
            back: () => { ctx.store.set('tenant', null); ctx.go('/tenants'); },
            logout: () => ctx.logout(),
        });
    } catch (error) {
        list.innerHTML = `<div class="error-box">${esc(error.message)}</div>`;
    }
}
