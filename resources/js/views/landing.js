/**
 * Trang giới thiệu (trang chủ) — phong cách Snapask: nền tím, điểm nhấn vàng, thẻ bo tròn lớn.
 * Nút "Thông tin cá nhân": đã đăng nhập → hồ sơ của tôi; chưa → đăng ký nhanh.
 */
import { getToken } from '../core/api.js';
import { delegate } from '../ui/dom.js';
import { icon } from '../ui/icons.js';

const STEPS = [
    ['1', 'Nhập tên, số điện thoại, ngày sinh', 'Không cần mã OTP. Hệ thống tạo sổ sức khỏe và mật khẩu cho bạn ngay.'],
    ['2', 'Chụp mọi giấy tờ khám bệnh', 'Đơn thuốc, phiếu xét nghiệm, siêu âm, giấy ra viện, vỏ hộp thuốc — chụp bao nhiêu cũng được.'],
    ['3', 'Nhận lịch uống thuốc & chế độ ăn', 'AI đọc ảnh, xếp giờ uống thuốc theo bữa ăn và gợi ý thực đơn bám theo kết quả xét nghiệm.'],
];

const FEATURES = [
    ['pill', 'Lịch uống thuốc tự động', 'Trước ăn, sau ăn, trước khi ngủ — xếp đúng theo đơn và giờ sinh hoạt của bạn, có nhắc giờ.'],
    ['flask', 'Đọc kết quả xét nghiệm', 'Chỉ số cao / thấp được đánh dấu rõ, lưu theo ngày để so sánh các lần khám.'],
    ['salad', 'Chế độ ăn theo chỉ số', 'Nên ăn, nên tránh, thực đơn mẫu một ngày — tính đến tương tác giữa thuốc và thức ăn.'],
    ['calendar', 'Nhắc tái khám', 'Ngày hẹn trên phiếu được đưa vào lịch, kèm việc cần chuẩn bị và câu nên hỏi bác sĩ.'],
];

const PREVIEW = [
    ['06:30', 'Gliclazid 30mg', '1 viên · trước ăn sáng 30 phút', 'tmed'],
    ['07:00', 'Ăn sáng', 'Yến mạch không đường + 1 quả trứng', 'meal'],
    ['07:30', 'Metformin 850mg', '1 viên · sau ăn', 'tmed'],
    ['18:30', 'Ăn tối', 'Cá kho nhạt, rau luộc, ½ bát cơm', 'meal'],
];

export function renderLanding(ctx) {
    const loggedIn = !!getToken();
    document.title = 'Sổ Sức Khỏe — chụp đơn thuốc, có ngay lịch uống thuốc và chế độ ăn';
    ctx.root.className = 'lp';
    ctx.root.innerHTML = `<div class="lp-page">
    <header class="lp-nav">
        <div class="lp-in">
            <a class="lp-logo" href="#/" aria-label="Sổ Sức Khỏe — trang chủ"><span class="avatar">S</span><span>Sổ Sức Khỏe</span></a>
            <nav class="lp-links" aria-label="Giới thiệu">
                <a href="#how" data-act="scroll" data-to="how">Cách dùng</a>
                <a href="#features" data-act="scroll" data-to="features">Tính năng</a>
                <a href="#privacy" data-act="scroll" data-to="privacy">Bảo mật</a>
            </nav>
            <button class="btn lp-me" data-act="me">${loggedIn ? 'Sổ của tôi' : 'Thông tin cá nhân'}</button>
        </div>
    </header>

    <main>
        <section class="lp-hero">
            <div class="lp-in lp-hero-grid">
                <div>
                    <span class="lp-kicker">Sổ theo dõi điều trị tại nhà</span>
                    <h1>Chụp đơn thuốc —<br><mark>có ngay lịch uống thuốc</mark><br>và chế độ ăn uống</h1>
                    <p class="lp-lead">Tải lên ảnh đơn thuốc, phiếu xét nghiệm, giấy khám. Sổ Sức Khỏe đọc giúp bạn, xếp giờ uống thuốc theo bữa ăn và gợi ý thực đơn bám theo kết quả xét nghiệm.</p>
                    <div class="lp-cta">
                        <button class="btn lp-primary" data-act="me">${loggedIn ? 'Mở sổ sức khỏe' : 'Tạo sổ miễn phí'} →</button>
                        ${loggedIn ? '<button class="btn lp-ghost" data-act="upload">Tải ảnh khám bệnh</button>' : '<button class="btn lp-ghost" data-act="login">Tôi đã có tài khoản</button>'}
                    </div>
                    <ul class="lp-trust">
                        <li>✓ Không cần mã OTP</li>
                        <li>✓ Không lưu số CCCD, mã BHYT</li>
                        <li>✓ Ảnh được mã hoá</li>
                    </ul>
                </div>
                <div class="lp-phone" aria-hidden="true">
                    <div class="lp-phone-top"><span class="avatar">D</span><div><b>Chào buổi sáng!</b><small>Hôm nay · 4 việc</small></div></div>
                    <div class="lp-ring"><div><b>75%</b><small>đã xong</small></div></div>
                    ${PREVIEW.map(([t, title, sub, kind]) => `<div class="lp-item ${kind}"><span class="time">${t}</span><div><b>${title}</b><small>${sub}</small></div>${kind === 'meal' ? `<span class="ico">${icon('utensils', { size: 14 })}</span>` : '<span class="tick">✓</span>'}</div>`).join('')}
                </div>
            </div>
        </section>

        <section class="lp-sec" id="how">
            <div class="lp-in">
                <h2>3 bước là xong</h2>
                <p class="lp-sub">Dành cho người bệnh và người nhà — không cần rành công nghệ.</p>
                <div class="lp-steps">${STEPS.map(([n, t, d]) => `<div class="lp-card"><span class="lp-num">${n}</span><h3>${t}</h3><p>${d}</p></div>`).join('')}</div>
            </div>
        </section>

        <section class="lp-sec alt" id="features">
            <div class="lp-in">
                <h2>Mọi thứ về điều trị, gọn trong một sổ</h2>
                <div class="lp-features">${FEATURES.map(([i, t, d]) => `<div class="lp-card"><span class="lp-ico">${icon(i, { size: 26 })}</span><h3>${t}</h3><p>${d}</p></div>`).join('')}</div>
            </div>
        </section>

        <section class="lp-sec" id="privacy">
            <div class="lp-in lp-privacy">
                <div class="lp-lock">${icon('shield', { size: 34 })}</div>
                <div>
                    <h2>Dữ liệu sức khỏe của bạn được giữ kín</h2>
                    <ul>
                        <li><b>Không lưu số CCCD / CMND và mã thẻ BHYT.</b> Các số này bị lọc khỏi dữ liệu trước khi lưu; ảnh chỉ chụp thẻ CCCD / BHYT sẽ bị từ chối.</li>
                        <li><b>Ảnh gốc được mã hoá AES-256</b>, lưu ngoài vùng truy cập công khai, xoá thông tin vị trí (GPS) trong ảnh.</li>
                        <li><b>Chỉ bạn và người bạn mời</b> mới xem được. Mỗi lần xem hồ sơ đều được ghi nhật ký.</li>
                        <li><b>Xoá bất cứ lúc nào</b> — xoá tài khoản là xoá cả ảnh và dữ liệu.</li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="lp-band">
            <div class="lp-in">
                <h2>Bắt đầu sổ sức khỏe trong 1 phút</h2>
                <button class="btn lp-primary" data-act="me">${loggedIn ? 'Mở sổ sức khỏe' : 'Nhập thông tin cá nhân'} →</button>
            </div>
        </section>
    </main>

    <footer class="lp-foot"><div class="lp-in">
        <p><b>Sổ Sức Khỏe</b> hỗ trợ ghi nhớ và sắp xếp việc điều trị. Ứng dụng <b>không thay thế</b> tư vấn, chẩn đoán hay chỉ định của bác sĩ. Không tự ý đổi liều hoặc ngừng thuốc.</p>
    </div></footer></div>`;

    delegate(ctx.root.querySelector(".lp-page"), {
        me: () => ctx.go(loggedIn ? '/me' : '/start'),
        login: () => ctx.go('/login'),
        upload: () => ctx.go('/upload'),
        scroll: (el, event) => { event.preventDefault(); document.getElementById(el.dataset.to)?.scrollIntoView({ behavior: 'smooth' }); },
    });
}
