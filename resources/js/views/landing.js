/**
 * Trang giới thiệu (trang chủ) — nền tím, điểm nhấn vàng, thẻ bo tròn lớn; chuyển động làm hoàn toàn bằng CSS (landing.css).
 * Phần "Tham quan Sổ" là tab thuần CSS (radio + :checked): chạm tab dưới điện thoại mẫu để xem từng màn.
 * Nút "Thông tin cá nhân": đã đăng nhập → hồ sơ của tôi; chưa → đăng ký nhanh.
 */
import { getToken } from '../core/api.js';
import { delegate } from '../ui/dom.js';
import { icon } from '../ui/icons.js';

const PREVIEW = [
    ['06:30', 'Gliclazid 30mg', '1 viên · trước ăn sáng 30 phút', 'med'],
    ['07:00', 'Ăn sáng', 'Yến mạch không đường + 1 quả trứng', 'meal'],
    ['07:30', 'Metformin 850mg', '1 viên · sau ăn', 'med'],
    ['18:30', 'Ăn tối', 'Cá kho nhạt, rau luộc, ½ bát cơm', 'meal'],
];

const DOCS = ['Đơn thuốc', 'Phiếu xét nghiệm', 'Siêu âm', 'Giấy ra viện', 'Vỏ hộp thuốc', 'Kết quả khám', 'Giấy hẹn tái khám', 'Chụp X-quang'];

const STEPS = [
    ['form', 'Nhập tên, số điện thoại, ngày sinh', 'Không cần mã OTP. Sổ sức khỏe và mật khẩu được tạo cho bạn ngay.'],
    ['shot', 'Chụp mọi giấy tờ khám bệnh', 'Đơn thuốc, phiếu xét nghiệm, siêu âm, giấy ra viện, vỏ hộp thuốc — chọn nhiều ảnh một lúc.'],
    ['plan', 'Xem lại và nhận lịch mỗi ngày', 'AI đọc ảnh, bạn xác nhận thuốc là xong: lịch uống thuốc, thực đơn và bài tập cho từng ngày.'],
];

/* Màn mẫu cho phần tham quan. `--i` = thứ tự xuất hiện của từng dòng. */
const a = (i) => `class="a" style="--i:${i}"`;
const SCREENS = {
    upload: `<div class="ts-top"><b>Tải ảnh khám bệnh</b><small>3 ảnh · AI đang đọc…</small></div>
        <div class="ts-scan" aria-hidden="true"><div class="ts-paper"><i></i><i></i><i class="s"></i><i></i><i class="s"></i><i></i></div><span class="ts-beam"></span></div>
        <small class="ts-label">Đã tìm thấy</small>
        <div ${a(0)}><span class="ts-row">${icon('pill', { size: 15 })}<b>Metformin 850mg</b><em class="ok">2 lần / ngày</em></span></div>
        <div ${a(1)}><span class="ts-row">${icon('pill', { size: 15 })}<b>Gliclazid 30mg</b><em class="ok">sáng</em></span></div>
        <div ${a(2)}><span class="ts-row">${icon('flask', { size: 15 })}<b>HbA1c 7,8%</b><em class="hi">↑ Cao</em></span></div>
        <div ${a(3)}><span class="ts-row">${icon('calendar', { size: 15 })}<b>Tái khám 12/10</b><em>đã vào lịch</em></span></div>`,
    today: `<div class="ts-top"><b>Chào buổi sáng!</b><small>Thứ Hai, 28/9</small></div>
        <div class="ts-tiles">
            <div ${a(0)}><small>Đường huyết</small><b>6,2</b><em class="ok">trong mục tiêu</em></div>
            <div ${a(1)}><small>Bài tập</small><b>1/2</b><em>còn 1 bài</em></div>
            <div ${a(2)}><small>Bữa tới</small><b>11:30</b><em>bữa trưa</em></div>
        </div>
        <div ${a(3)}><div class="ts-card ts-meal"><span class="ts-ic">${icon('salad', { size: 16 })}</span><div><b>Bữa trưa · 11:30</b><small>Cơm gạo lứt ½ bát, cá hấp gừng, rau muống luộc</small></div></div></div>
        <div ${a(4)}><div class="ts-card ts-ex"><span class="ts-play">${icon('play', { size: 14, fill: 'currentColor' })}</span><div><b>Đi bộ nhanh 20 phút</b><small>Có video hướng dẫn</small><span class="ts-bar"><i></i></span></div></div></div>`,
    plan: `<div class="ts-top"><b>Phác đồ điều trị</b><small>Theo đúng đơn bác sĩ</small></div>
        <div ${a(0)}><div class="ts-slot"><span>06:30</span><div><b>Gliclazid 30mg</b><small>1 viên · trước ăn 30 phút</small></div></div></div>
        <div ${a(1)}><div class="ts-slot"><span>07:30</span><div><b>Metformin 850mg</b><small>1 viên · sau ăn sáng</small></div></div></div>
        <div ${a(2)}><div class="ts-slot"><span>19:00</span><div><b>Metformin 850mg</b><small>1 viên · sau ăn tối</small></div></div></div>
        <div ${a(3)}><div class="ts-slot night"><span>21:30</span><div><b>Insulin Glargine</b><small>10 đơn vị · trước khi ngủ</small></div></div></div>
        <div ${a(4)}><div class="ts-stock"><small>Metformin còn <b>12 ngày</b></small><span class="ts-bar warn"><i></i></span></div></div>`,
    calendar: `<div class="ts-top"><b>Lịch theo dõi</b><small>Tháng 9</small></div>
        <div class="ts-month" aria-hidden="true">${Array.from({ length: 28 }, (_, d) => `<i class="${[2, 5, 9, 13, 16, 20, 23].includes(d) ? 'dot' : ''}${d === 27 ? ' now' : ''}">${d + 1}</i>`).join('')}</div>
        <small class="ts-label">Đường huyết lúc đói</small>
        <div class="ts-chart" aria-hidden="true"><svg viewBox="0 0 200 70" preserveAspectRatio="none"><rect class="band" x="0" y="18" width="200" height="26"/><path pathLength="1" d="M0 12 L25 20 L50 16 L75 30 L100 26 L125 36 L150 31 L175 38 L200 34"/></svg></div>
        <div ${a(3)}><span class="ts-row">${icon('file-down', { size: 15 })}<b>Xuất CSV đi tái khám</b></span></div>`,
    records: `<div class="ts-top"><b>Hồ sơ & kết quả</b><small>Ảnh phiếu khám được mã hoá</small></div>
        <div class="ts-docs" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
        <div ${a(0)}><div class="ts-lab"><span>HbA1c</span><b>7,8 %</b><em class="hi">↑ Cao</em></div></div>
        <div ${a(1)}><div class="ts-lab"><span>Glucose đói</span><b>6,2</b><em class="ok">Bình thường</em></div></div>
        <div ${a(2)}><div class="ts-lab"><span>Cholesterol</span><b>5,9</b><em class="hi">↑ Cao</em></div></div>
        <div ${a(3)}><div class="ts-lab"><span>Creatinin</span><b>82</b><em class="ok">Bình thường</em></div></div>`,
};

/* Thứ tự tham quan (theo luồng dùng) khác thứ tự tab trong app (theo thanh dưới). */
const TOUR = [
    ['upload', 'Chụp ảnh — AI đọc giúp bạn', 'Bấm nút + tím ở giữa thanh dưới, chọn một hoặc nhiều ảnh. AI tách ra thuốc, chỉ số xét nghiệm, chẩn đoán và ngày hẹn tái khám.',
        ['Bạn xem lại danh sách thuốc trước khi lưu — không có gì tự thêm vào lịch', 'Chụp lại cùng một phiếu được nhận ra, không lưu trùng', 'Ảnh được xoá vị trí GPS và mã hoá trước khi lưu'], 'Nút + ở giữa thanh dưới'],
    ['today', 'Hôm nay — mở app là biết việc cần làm', 'Tóm tắt đường huyết, bữa ăn sắp tới và bài tập của ngày ngay trên màn đầu tiên.',
        ['Thực đơn đổi mỗi ngày, bám theo bệnh và kết quả xét nghiệm của bạn', 'Bài tập nhẹ có video hướng dẫn, đánh dấu khi tập xong', 'Ghi nhanh đường huyết, dấu hiệu bất thường, ghi chú'], 'Tab Hôm nay'],
    ['plan', 'Phác đồ — thuốc xếp theo giờ sinh hoạt', 'Trước ăn, sau ăn, trước khi ngủ: mỗi thuốc được xếp vào đúng khung giờ theo đơn và giờ ăn, ngủ của bạn.',
        ['Giờ sinh hoạt chỉnh được trong Cài đặt, lịch tự xếp lại', 'Biết thuốc nào sắp hết để mua thêm kịp', 'Ứng dụng không bao giờ tự đổi liều'], 'Tab Phác đồ'],
    ['calendar', 'Lịch — nhìn lại cả tháng', 'Mỗi ngày có ghi chép được đánh dấu. Biểu đồ đường huyết và huyết áp có sẵn vùng mục tiêu để dễ so.',
        ['Chạm một ngày để mở lại chi tiết ngày đó', 'Đường huyết lúc đói, huyết áp buổi sáng theo thời gian', 'Xuất file CSV mang theo khi đi tái khám'], 'Tab Lịch'],
    ['records', 'Hồ sơ — mọi phiếu khám ở một chỗ', 'Ảnh phiếu khám, kết quả xét nghiệm và bệnh nền được gom lại, sắp theo ngày.',
        ['Chỉ số cao / thấp được đánh dấu rõ, so được giữa các lần khám', 'Lọc theo loại: đơn thuốc, xét nghiệm, chẩn đoán hình ảnh…', 'Chạm để phóng to ảnh phiếu gốc'], 'Tab Hồ sơ'],
];

/* Thanh tab trong điện thoại mẫu — đúng thứ tự thanh dưới của app. */
const PHONE_TABS = [['today', 'house', 'Hôm nay'], ['calendar', 'calendar', 'Lịch'], ['upload', 'plus', ''], ['plan', 'pill', 'Phác đồ'], ['records', 'file', 'Hồ sơ']];

const EXTRAS = [
    ['share', 'Gửi hồ sơ cho bác sĩ, dược sĩ', 'Tạo link chỉ xem có hạn 24 giờ, 7 ngày hoặc 30 ngày, thêm mã PIN nếu muốn, kèm mã QR. Thu hồi bất cứ lúc nào.', 'x-share', 'Thông tin cá nhân → Chia sẻ hồ sơ'],
    ['type', 'Chữ to cho người lớn tuổi', 'Ba cỡ chữ: Thường, Lớn, Rất lớn.', 'x-type', 'Cài đặt → Cỡ chữ'],
    ['wifi-off', 'Vẫn ghi được khi mất mạng', 'Chỉ số ghi lúc không có sóng được giữ lại và tự gửi khi có mạng.', 'x-off', 'Tự động'],
    ['moon', 'Giao diện sáng hoặc tối', 'Theo máy, hoặc chọn hẳn Sáng / Tối cho dễ nhìn ban đêm.', 'x-moon', 'Cài đặt → Giao diện'],
];

export function renderLanding(ctx) {
    const loggedIn = !!getToken();
    document.title = 'Sổ Sức Khỏe — chụp đơn thuốc, có ngay lịch uống thuốc và chế độ ăn';
    ctx.root.className = 'lp';
    const primary = loggedIn ? 'Mở sổ sức khỏe' : 'Tạo sổ miễn phí';
    const docs = DOCS.map((d) => `<span>${icon('file', { size: 16 })}${d}</span>`).join('');

    ctx.root.innerHTML = `<div class="lp-page">
    <header class="lp-nav">
        <div class="lp-in">
            <a class="lp-logo" href="#/" aria-label="Sổ Sức Khỏe — trang chủ"><span class="avatar">S</span><span>Sổ Sức Khỏe</span></a>
            <nav class="lp-links" aria-label="Giới thiệu">
                <a href="#how" data-act="scroll" data-to="how">Cách dùng</a>
                <a href="#tour" data-act="scroll" data-to="tour">Tham quan</a>
                <a href="#privacy" data-act="scroll" data-to="privacy">Bảo mật</a>
            </nav>
            <button class="btn lp-me" data-act="me">${loggedIn ? 'Sổ của tôi' : 'Thông tin cá nhân'}</button>
        </div>
    </header>

    <main>
        <section class="lp-hero">
            <span class="lp-blob b1" aria-hidden="true"></span><span class="lp-blob b2" aria-hidden="true"></span>
            <div class="lp-in lp-hero-grid">
                <div class="lp-hero-copy">
                    <span class="lp-kicker"><i></i>Sổ theo dõi điều trị tại nhà</span>
                    <h1><span class="ln" style="--i:0">Chụp đơn thuốc —</span> <span class="ln" style="--i:1"><mark>có ngay lịch uống thuốc</mark></span> <span class="ln" style="--i:2">và chế độ ăn uống</span></h1>
                    <p class="lp-lead">Tải lên ảnh đơn thuốc, phiếu xét nghiệm, giấy khám. Sổ Sức Khỏe đọc giúp bạn, xếp giờ uống thuốc theo bữa ăn và gợi ý thực đơn bám theo kết quả xét nghiệm.</p>
                    <div class="lp-cta">
                        <button class="btn lp-primary" data-act="me">${primary} <span class="arr">→</span></button>
                        ${loggedIn ? '<button class="btn lp-ghost" data-act="upload">Tải ảnh khám bệnh</button>' : '<button class="btn lp-ghost" data-act="login">Tôi đã có tài khoản</button>'}
                    </div>
                    <ul class="lp-trust">
                        <li>${icon('check', { size: 15, stroke: 3 })}Không cần mã OTP</li>
                        <li>${icon('check', { size: 15, stroke: 3 })}Không lưu số CCCD, mã BHYT</li>
                        <li>${icon('check', { size: 15, stroke: 3 })}Ảnh được mã hoá</li>
                    </ul>
                </div>

                <div class="lp-stage" aria-hidden="true">
                    <div class="lp-rx">
                        <b>ĐƠN THUỐC</b>
                        <i></i><i class="s"></i><i></i><i class="s"></i><i></i>
                        <span class="lp-rx-beam"></span>
                    </div>
                    <div class="lp-phone">
                        <div class="lp-phone-top"><span class="avatar">D</span><div><b>Chào buổi sáng!</b><small>Hôm nay · 4 việc</small></div></div>
                        <div class="lp-ring"><div><b></b><small>đã xong</small></div></div>
                        ${PREVIEW.map(([t, title, sub, kind], i) => `<div class="lp-item ${kind}" style="--i:${i}"><span class="time">${t}</span><div><b>${title}</b><small>${sub}</small></div>${kind === 'meal' ? `<span class="ico">${icon('utensils', { size: 14 })}</span>` : `<span class="tick">${icon('check', { size: 14, stroke: 3 })}</span>`}</div>`).join('')}
                    </div>
                    <div class="lp-chip c1"><span>${icon('sparkles', { size: 16 })}</span><div><b>AI đã đọc 3 thuốc</b><small>từ 2 ảnh đơn thuốc</small></div></div>
                    <div class="lp-chip c2"><span>${icon('droplet', { size: 16 })}</span><div><b>Đường huyết 6,2</b><small>trong mục tiêu</small></div></div>
                </div>
            </div>
        </section>

        <div class="lp-marquee" aria-label="Các loại giấy tờ đọc được">
            <div class="lp-marquee-track"><div>${docs}</div><div aria-hidden="true">${docs}</div></div>
        </div>

        <section class="lp-sec" id="how">
            <div class="lp-in">
                <p class="lp-eyebrow rv">Cách dùng</p>
                <h2 class="rv">3 bước là xong</h2>
                <p class="lp-sub rv">Dành cho người bệnh và người nhà — không cần rành công nghệ.</p>
                <ol class="lp-steps">${STEPS.map(([kind, t, d], i) => `<li class="lp-step rv" style="--i:${i}">
                    <div class="lp-art art-${kind}" aria-hidden="true">${stepArt(kind)}</div>
                    <span class="lp-num">${i + 1}</span><h3>${t}</h3><p>${d}</p></li>`).join('')}</ol>
            </div>
        </section>

        <section class="lp-sec alt" id="tour">
            <div class="lp-in">
                <p class="lp-eyebrow rv">Tham quan Sổ</p>
                <h2 class="rv">Mỗi tab làm một việc, không phải học</h2>
                <p class="lp-sub rv">Chạm vào từng mục — hoặc các tab dưới điện thoại mẫu — để xem màn hình thật trông thế nào.</p>
                <div class="lp-tour">
                    ${TOUR.map(([id], i) => `<input type="radio" name="lp-tour" id="tour-${id}" class="lp-tour-r"${i === 0 ? ' checked' : ''}>`).join('')}
                    <div class="lp-tour-grid">
                        <ol class="lp-tour-list" aria-label="Các màn chính">
                            ${TOUR.map(([id, title, desc, points, where], i) => `<li><label for="tour-${id}" class="lp-tour-item ti-${id}">
                                <span class="n">${i + 1}</span>
                                <span class="t"><b>${title}</b><span class="more"><span>
                                    <span class="d">${desc}</span>
                                    ${points.map((p) => `<span class="pt">${icon('check', { size: 14, stroke: 3 })}${p}</span>`).join('')}
                                    <em>${icon('pin', { size: 13 })}Tìm ở: ${where}</em>
                                </span></span></span></label></li>`).join('')}
                        </ol>
                        <div class="lp-tour-phone">
                            <div class="lp-mini">
                                <div class="lp-mini-screen">${TOUR.map(([id]) => `<div class="ts ts-${id}" aria-hidden="true">${SCREENS[id]}</div>`).join('')}</div>
                                <div class="lp-mini-tabs">${PHONE_TABS.map(([id, ic, label]) => `<label for="tour-${id}" class="${label ? 'mt' : 'mfab'} mt-${id}" aria-label="${label || 'Tải ảnh'}">${icon(ic, { size: label ? 17 : 20, stroke: label ? 2 : 2.6 })}${label ? `<span>${label}</span>` : ''}</label>`).join('')}</div>
                                <span class="lp-tap" aria-hidden="true"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="lp-sec" id="more">
            <div class="lp-in">
                <p class="lp-eyebrow rv">Còn nữa</p>
                <h2 class="rv">Những điều nhỏ giúp dùng lâu dài</h2>
                <div class="lp-extras">${EXTRAS.map(([ic, t, d, art, where], i) => `<article class="lp-extra rv ${art}" style="--i:${i}">
                    <div class="lp-extra-art" aria-hidden="true">${extraArt(art, ic)}</div>
                    <h3>${t}</h3><p>${d}</p><small>${where}</small></article>`).join('')}</div>
            </div>
        </section>

        <section class="lp-sec" id="privacy">
            <div class="lp-in lp-privacy rv">
                <div class="lp-lock" aria-hidden="true"><span></span><span></span>${icon('shield', { size: 34 })}</div>
                <div>
                    <h2>Dữ liệu sức khỏe của bạn được giữ kín</h2>
                    <ul>
                        <li><b>Không lưu số CCCD / CMND và mã thẻ BHYT.</b> Các số này bị lọc khỏi dữ liệu trước khi lưu; ảnh chỉ chụp thẻ CCCD / BHYT sẽ bị từ chối.</li>
                        <li><b>Ảnh gốc được mã hoá AES-256</b>, lưu ngoài vùng truy cập công khai, xoá thông tin vị trí (GPS) trong ảnh.</li>
                        <li><b>Chỉ bạn và người bạn chia sẻ</b> mới xem được. Mỗi lần xem qua link đều được ghi nhật ký.</li>
                        <li><b>Xoá bất cứ lúc nào</b> — xoá tài khoản là xoá cả ảnh và dữ liệu.</li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="lp-band rv">
            <div class="lp-in">
                <h2>Bắt đầu sổ sức khỏe trong 1 phút</h2>
                <p>Chỉ cần tên, số điện thoại và ngày sinh.</p>
                <button class="btn lp-primary" data-act="me">${loggedIn ? 'Mở sổ sức khỏe' : 'Nhập thông tin cá nhân'} <span class="arr">→</span></button>
            </div>
        </section>
    </main>

    <footer class="lp-foot"><div class="lp-in">
        <p><b>Sổ Sức Khỏe</b> hỗ trợ ghi nhớ và sắp xếp việc điều trị. Ứng dụng <b>không thay thế</b> tư vấn, chẩn đoán hay chỉ định của bác sĩ. Không tự ý đổi liều hoặc ngừng thuốc.</p>
    </div></footer></div>`;

    delegate(ctx.root.querySelector('.lp-page'), {
        me: () => ctx.go(loggedIn ? '/me' : '/start'),
        login: () => ctx.go('/login'),
        upload: () => ctx.go('/upload'),
        scroll: (el, event) => { event.preventDefault(); document.getElementById(el.dataset.to)?.scrollIntoView({ behavior: 'smooth' }); },
    });
}

/** Hình minh hoạ nhỏ trên thẻ từng bước. */
function stepArt(kind) {
    if (kind === 'form') return `<span class="f"><small>Họ tên</small><i>Nguyễn Văn Dũng</i></span><span class="f"><small>Số điện thoại</small><i>0912 345 678</i></span><span class="f"><small>Ngày sinh</small><i>12/05/1958</i></span>`;
    if (kind === 'shot') return `<span class="p p1"></span><span class="p p2"></span><span class="p p3"><i></i><i></i><i></i></span><span class="flash"></span><span class="cam">${icon('camera', { size: 18 })}</span>`;
    return ['Gliclazid · 06:30', 'Ăn sáng · 07:00', 'Đi bộ · 16:30'].map((t, i) => `<span class="r" style="--i:${i}">${icon('check', { size: 13, stroke: 3 })}${t}</span>`).join('');
}

/** Hình minh hoạ trên thẻ "Còn nữa". */
function extraArt(art, ic) {
    if (art === 'x-share') return `<span class="qr">${Array.from({ length: 25 }, (_, i) => `<i class="${[0, 1, 2, 5, 7, 10, 11, 12, 14, 18, 20, 22, 24, 3, 16].includes(i) ? 'on' : ''}" style="--i:${i}"></i>`).join('')}</span><span class="link">${icon('link', { size: 14 })}sosuckhoe…/s/7f3a<em>7 ngày</em></span>`;
    if (art === 'x-type') return '<span class="aa">Aa</span>';
    if (art === 'x-off') return `<span class="off">${icon('wifi-off', { size: 22 })}</span><span class="q"><i></i><i></i><i></i></span>`;
    return `<span class="moon">${icon(ic, { size: 22 })}</span>`;
}
