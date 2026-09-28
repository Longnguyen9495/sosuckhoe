/**
 * Tạo ảnh xem trước khi chia sẻ link (public/og-image.png, 1200×630) — Zalo, Facebook, Messenger.
 * 1) node docs/og-image/og-image.mjs   → ghi docs/og-image/og.html (không đưa lên git)
 * 2) Chụp bằng Edge / Chrome headless:
 *    msedge --headless=new --hide-scrollbars --force-device-scale-factor=1 --window-size=1200,630 \
 *      --virtual-time-budget=8000 --screenshot=public/og-image.png file:///.../docs/og-image/og.html
 * 3) Tăng ?v= của og:image trong resources/views/app.blade.php để Zalo / Facebook tải lại ảnh.
 */
import { createRequire } from 'module';
import { writeFileSync } from 'fs';
const require = createRequire(new URL('../../package.json', import.meta.url));
const L = require('lucide');
const ic = (n, s = 20, sw = 2.2, fill = 'none') => `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="${fill}" stroke="currentColor" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round">${L[n].map(([t, a]) => `<${t} ${Object.entries(a).map(([k, v]) => `${k}="${v}"`).join(' ')}/>`).join('')}</svg>`;

const items = [
  ['06:30', 'Gliclazid 30mg', '1 viên · trước ăn sáng 30 phút', 'med'],
  ['07:00', 'Ăn sáng', 'Yến mạch không đường + 1 quả trứng', 'meal'],
  ['07:30', 'Metformin 850mg', '1 viên · sau ăn', 'med'],
  ['16:30', 'Đi bộ nhanh 20 phút', 'có video hướng dẫn', 'ex'],
];

const html = `<!doctype html><html lang="vi"><head><meta charset="utf-8">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@500;600;700;800&display=block" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0}
html,body{width:1200px;height:630px;overflow:hidden}
body{font-family:"Be Vietnam Pro",sans-serif;color:#fff;background:linear-gradient(135deg,#4B2FC9 0%,#6C4CF1 52%,#9A7BFF 100%);position:relative}
body::before{content:"";position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.15) 1.2px,transparent 1.6px);background-size:24px 24px;-webkit-mask-image:radial-gradient(ellipse at 78% 45%,#000 15%,transparent 65%)}
.blob{position:absolute;border-radius:50%}
.b1{width:520px;height:520px;right:-170px;top:-230px;background:rgba(255,255,255,.08)}
.b2{width:340px;height:340px;left:-140px;bottom:-190px;background:rgba(255,201,64,.2)}
.left{position:absolute;left:64px;top:54px;width:640px}
.logo{display:inline-flex;align-items:center;gap:12px;font-weight:800;font-size:26px}
.logo span{width:48px;height:48px;border-radius:50%;background:#FFC940;color:#3A2A00;display:grid;place-items:center;font-size:24px}
h1{font-size:51px;line-height:1.12;letter-spacing:-.02em;margin:30px 0 18px;font-weight:800;white-space:nowrap}
h1 mark{color:#FFC940;background:linear-gradient(rgba(255,201,64,.28),rgba(255,201,64,.28)) 0 90%/100% .2em no-repeat}
.sub{font-size:21px;line-height:1.45;opacity:.94;font-weight:500;max-width:600px}
.pills{display:flex;gap:10px;margin-top:24px}
.pills b{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.16);border:1.5px solid rgba(255,255,255,.3);padding:9px 15px;border-radius:99px;font-size:17px;font-weight:700;white-space:nowrap}
.pills svg{color:#FFC940}
.foot{position:absolute;left:64px;bottom:40px;display:flex;gap:22px;font-size:18px;font-weight:700;opacity:.95}
.foot span{display:inline-flex;gap:7px;align-items:center}
.foot svg{color:#FFC940}
.stage{position:absolute;right:56px;top:42px;width:460px;height:550px}
.rx{position:absolute;left:14px;top:220px;width:170px;height:214px;background:#FFFDF6;border-radius:16px;box-shadow:0 20px 44px rgba(20,8,80,.35);transform:rotate(-10deg);padding:18px 16px;display:grid;align-content:start;gap:12px;overflow:hidden}
.rx b{font-size:12px;letter-spacing:.16em;color:#6C4CF1}
.rx i{display:block;height:8px;border-radius:4px;background:#E4DFF0}.rx i.s{width:58%}
.rx .beam{position:absolute;left:0;right:0;top:92px;height:48px;background:linear-gradient(180deg,transparent,rgba(108,76,241,.32) 70%,#6C4CF1);border-bottom:3px solid #6C4CF1}
.phone{position:absolute;right:0;top:0;width:330px;background:#fff;color:#1F1B2E;border-radius:36px;padding:22px 18px 14px;box-shadow:0 34px 70px rgba(20,8,80,.42);outline:7px solid rgba(255,255,255,.24);transform:rotate(-2.5deg)}
.top{display:flex;gap:11px;align-items:center;margin-bottom:12px}
.av{width:42px;height:42px;border-radius:50%;background:#FFC940;color:#3A2A00;display:grid;place-items:center;font-weight:800;font-size:18px}
.top b{font-size:17px;display:block}.top small{color:#7A7592;font-size:13px}
.ring{width:84px;height:84px;border-radius:50%;background:conic-gradient(#6C4CF1 75%,#EFEBFF 0);display:grid;place-items:center;margin:0 auto 14px}
.ring div{width:66px;height:66px;border-radius:50%;background:#fff;display:grid;place-items:center;align-content:center;line-height:1.05;text-align:center}
.ring b{font-size:19px}.ring small{font-size:10px;color:#7A7592}
.it{display:flex;gap:10px;align-items:center;border:1.5px solid #E8E4F4;border-radius:15px;padding:10px 11px;margin-bottom:8px}
.it .t{font-weight:800;color:#6C4CF1;width:46px;font-size:14px;flex:none}
.it div{flex:1;min-width:0}.it b{font-size:14.5px;display:block}.it small{font-size:12px;color:#7A7592;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.it.meal{border-style:dashed;background:#FAF9FE}
.it.ex{border-color:#6C4CF1;box-shadow:0 0 0 4px rgba(108,76,241,.14)}
.tick{width:28px;height:28px;border-radius:9px;background:#16804F;color:#fff;display:grid;place-items:center;flex:none}
.it .o{color:#7A7592;flex:none}
.chip{position:absolute;display:flex;align-items:center;gap:11px;background:#fff;color:#1F1B2E;border-radius:18px;padding:10px 16px 10px 10px;box-shadow:0 18px 40px rgba(20,8,80,.32);white-space:nowrap}
.chip>span{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;background:#EFEBFF;color:#6C4CF1}
.chip b{font-size:15px;display:block}.chip small{font-size:12.5px;color:#7A7592}
.c1{left:30px;top:118px}
.c2{right:-22px;bottom:16px}.c2>span{background:#E2F5EA;color:#16804F}
</style></head><body>
<span class="blob b1"></span><span class="blob b2"></span>
<div class="left">
  <div class="logo"><span>S</span>Sổ Sức Khỏe</div>
  <h1>Chụp đơn thuốc —<br><mark>có ngay lịch uống thuốc</mark><br>và chế độ ăn uống</h1>
  <p class="sub">AI đọc đơn thuốc, phiếu xét nghiệm — xếp giờ uống thuốc theo bữa ăn, gợi ý thực đơn và bài tập mỗi ngày.</p>
  <div class="pills">
    <b>${ic('ScanLine', 20)}AI đọc ảnh</b><b>${ic('Salad', 20)}Thực đơn mỗi ngày</b><b>${ic('Share2', 20)}Chia sẻ cho bác sĩ</b>
  </div>
</div>
<div class="foot"><span>${ic('Check', 19, 3)}Miễn phí</span><span>${ic('Check', 19, 3)}Không cần mã OTP</span><span>${ic('Check', 19, 3)}Ảnh được mã hoá</span></div>
<div class="stage">
  <div class="rx"><b>ĐƠN THUỐC</b><i></i><i class="s"></i><i></i><i class="s"></i><i></i><i></i><span class="beam"></span></div>
  <div class="phone">
    <div class="top"><span class="av">D</span><div><b>Chào buổi sáng!</b><small>Hôm nay · 4 việc</small></div></div>
    <div class="ring"><div><b>75%</b><small>đã xong</small></div></div>
    ${items.map(([t, n, s, k]) => `<div class="it ${k}"><span class="t">${t}</span><div><b>${n}</b><small>${s}</small></div>${k === 'med' ? `<span class="tick">${ic('Check', 16, 3)}</span>` : `<span class="o">${ic(k === 'meal' ? 'Utensils' : 'Play', 17, 2.2)}</span>`}</div>`).join('')}
  </div>
  <div class="chip c1"><span>${ic('Sparkles', 20)}</span><div><b>AI đã đọc 3 thuốc</b><small>từ 2 ảnh đơn thuốc</small></div></div>
  <div class="chip c2"><span>${ic('Droplet', 20)}</span><div><b>Đường huyết 6,2</b><small>trong mục tiêu</small></div></div>
</div>
</body></html>`;
writeFileSync(new URL('./og.html', import.meta.url), html);
