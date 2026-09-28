/** Màn "Thông tin cá nhân": thông tin tài khoản, đổi mật khẩu, lối tắt, đăng xuất. */
import { api } from '../core/api.js';
import { dmy } from '../core/format.js';
import { esc, delegate, skeleton, errorBox } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { toast } from '../ui/shell.js';
import { mountShareSection } from './share.js';

export async function renderMe(ctx) {
    const screen = ctx.render({
        title: 'Thông tin cá nhân',
        back: '/today',
        tab: false,
        body: `<div class="card lift" id="me-card">${skeleton(2)}</div>
            <div id="pw-warn"></div>
            <div class="quick">
                <button class="quick-btn" data-act="nav" data-to="/upload"><span>${icon('camera', { size: 24 })}</span>Tải ảnh khám bệnh</button>
                <button class="quick-btn" data-act="nav" data-to="/plan"><span>${icon('salad', { size: 24 })}</span>Phác đồ & chế độ ăn</button>
                <button class="quick-btn" data-act="nav" data-to="/records"><span>${icon('folder', { size: 24 })}</span>Hồ sơ & kết quả</button>
                <button class="quick-btn" data-act="nav" data-to="/today"><span>${icon('calendar', { size: 24 })}</span>Lịch hôm nay</button>
            </div>
            <section class="blk" id="share-box"></section>
            <div class="sec-title"><h2>Đổi mật khẩu</h2></div>
            <form class="card" id="pw-form" novalidate>
                <div class="field"><label for="cur">Mật khẩu hiện tại</label><input id="cur" type="password" autocomplete="current-password" required></div>
                <div class="field"><label for="pw1">Mật khẩu mới (ít nhất 8 ký tự)</label><input id="pw1" type="password" autocomplete="new-password" minlength="8" required></div>
                <div class="field"><label for="pw2">Nhập lại mật khẩu mới</label><input id="pw2" type="password" autocomplete="new-password" required></div>
                <button class="btn block">Đổi mật khẩu</button>
                <p class="small muted" style="margin:10px 0 0">Đổi mật khẩu sẽ đăng xuất các thiết bị khác.</p>
            </form>
            <div class="card">
                <button class="btn ghost block" data-act="nav" data-to="/settings">Cài đặt giờ sinh hoạt, cỡ chữ, thành viên</button>
                <button class="btn ghost block" style="margin-top:8px" data-act="home">Trang giới thiệu</button>
                <button class="btn danger block" style="margin-top:8px" data-act="logout">Đăng xuất</button>
            </div>`,
    });

    async function draw() {
        try {
            const { data } = await api('/auth/me');
            screen.querySelector('#me-card').innerHTML = `<div class="row"><span class="avatar big">${esc((data.name || '?').trim().split(/\s+/).pop().charAt(0).toUpperCase())}</span>
                <div class="grow"><h3 style="margin:0">${esc(data.name)}</h3>
                <div class="small ink2">${icon('phone', { size: 14 })} ${esc(data.phone || '')}${data.birth_date ? ` · ${icon('cake', { size: 14 })} ${dmy(data.birth_date)}` : ''}</div></div></div>`;
            screen.querySelector('#pw-warn').innerHTML = data.uses_default_password
                ? `<div class="alert mua"><div class="ico">${icon('key', { size: 18 })}</div><div><b>Bạn đang dùng mật khẩu mặc định</b><p>Mật khẩu mặc định dễ đoán (tên + 4 số cuối SĐT). Nên đổi sang mật khẩu riêng ở bên dưới.</p></div></div>`
                : '';
        } catch (error) {
            screen.querySelector('#me-card').innerHTML = errorBox(error.message);
        }
    }

    screen.querySelector('#pw-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const f = event.target;
        const [cur, pw1, pw2] = ['#cur', '#pw1', '#pw2'].map((s) => f.querySelector(s).value);
        if (pw1.length < 8) return toast('Mật khẩu mới cần ít nhất 8 ký tự.', 'bad');
        if (pw1 !== pw2) return toast('Nhập lại mật khẩu mới chưa khớp.', 'bad');
        const button = f.querySelector('button');
        button.disabled = true;
        try {
            await api('/auth/password', { method: 'POST', body: { current_password: cur, password: pw1, password_confirmation: pw2 } });
            toast('Đã đổi mật khẩu.');
            f.reset();
            draw();
        } catch (error) {
            toast(error.message, 'bad');
        } finally {
            button.disabled = false;
        }
    });

    mountShareSection(screen.querySelector('#share-box'), ctx);

    delegate(screen, {
        logout: () => ctx.logout(),
        home: () => ctx.go('/'),
        retry: () => ctx.refresh(),
    });

    draw();
}
