/** Người bệnh tạo / xem / thu hồi link chia sẻ hồ sơ chỉ xem (màn Thông tin cá nhân). */
import { api } from '../core/api.js';
import { esc, delegate, skeleton, errorBox } from '../ui/dom.js';
import { openSheet, closeSheet, toast, confirmDialog } from '../ui/shell.js';
import { icon } from '../ui/icons.js';

const LABELS = ['Dược sĩ', 'Bác sĩ', 'Người thân'];
const EXPIRY = [[1, '24 giờ'], [7, '7 ngày'], [30, '30 ngày']];
const STATUS = { active: ['good', 'Đang mở'], expired: ['', 'Đã hết hạn'], revoked: ['bad', 'Đã thu hồi'] };

const two = (n) => String(n).padStart(2, '0');
const when = (iso) => { const d = new Date(iso); return `${two(d.getHours())}:${two(d.getMinutes())} ${two(d.getDate())}/${two(d.getMonth() + 1)}`; };

/** Khối "Chia sẻ hồ sơ" gắn vào một phần tử có sẵn. */
export function mountShareSection(box, ctx) {
    const pid = ctx.patient?.id;
    if (!pid) { box.innerHTML = ''; return; }
    let links = [];

    async function draw() {
        box.innerHTML = `<div class="blk-h"><span class="blk-ic">${icon('share', { size: 18 })}</span><h2>Chia sẻ hồ sơ</h2>
            <button class="btn sm" data-act="share-new">${icon('plus', { size: 16 })} Tạo link</button></div>
            <p class="small ink2" style="margin:0 2px 10px">Gửi link cho dược sĩ, bác sĩ hoặc người thân để họ xem thuốc đang dùng, bệnh nền, xét nghiệm và đường huyết — không cần tài khoản. Link có hạn và thu hồi được bất cứ lúc nào.</p>
            <div id="share-list">${skeleton(2)}</div>`;
        try {
            links = (await api(`/patients/${pid}/share-links`)).data;
            drawList();
        } catch (error) {
            box.querySelector('#share-list').innerHTML = errorBox(error.message);
        }
    }

    function drawList() {
        const active = links.filter((l) => l.status === 'active');
        const old = links.filter((l) => l.status !== 'active').slice(0, 5);
        const row = (l) => {
            const [tone, text] = STATUS[l.status];
            const lockout = l.last_lockout_at && Date.now() - new Date(l.last_lockout_at).getTime() < 7 * 864e5;
            return `<div class="share-row ${l.status}">
                <div class="row" style="align-items:flex-start"><div class="grow"><b>${esc(l.label)}</b> <span class="chip ${tone}">${text}</span>
                    <div class="small muted">${l.status === 'active' ? `Hết hạn ${when(l.expires_at)}` : `Tạo ${when(l.created_at)}`} · ${l.has_pin ? `${icon('lock', { size: 12 })} có PIN` : 'không PIN'}${l.include_documents ? ' · kèm ảnh phiếu' : ''}</div>
                    <div class="small ink2">${icon('eye', { size: 13 })} ${l.view_count ? `Đã xem ${l.view_count} lần · lần cuối ${when(l.last_viewed_at)}` : 'Chưa ai mở'}</div></div>
                    ${l.status === 'active' ? `<button class="btn sm danger" data-act="share-revoke" data-id="${esc(l.id)}">Thu hồi</button>` : ''}</div>
                ${lockout ? `<div class="warn-line">${icon('warn', { size: 14 })} Có người nhập sai PIN nhiều lần (${when(l.last_lockout_at)}). Nếu không phải người bạn gửi, hãy thu hồi link này.</div>` : ''}
                ${l.views.length ? `<details class="fold-lite"><summary>${icon('history', { size: 14 })} Lượt xem gần đây</summary><ul class="share-views">${l.views.map((v) => `<li>${when(v.at)} · ${esc(v.device || '')}${v.ip ? ` · ${esc(v.ip)}` : ''}</li>`).join('')}</ul></details>` : ''}
            </div>`;
        };
        box.querySelector('#share-list').innerHTML = `<div class="card">${active.length ? active.map(row).join('') : '<p class="small muted" style="margin:0">Chưa có link nào đang mở.</p>'}</div>
            ${old.length ? `<details class="fold-lite"><summary>Link cũ (${old.length})</summary><div class="card">${old.map(row).join('')}</div></details>` : ''}`;
    }

    async function openCreate() {
        let pin = '';
        try { pin = (await api(`/patients/${pid}/share-links/pin`)).pin; } catch (_e) { /* để trống, người bệnh tự đặt */ }
        const sheet = openSheet(`<h3>Tạo link chia sẻ</h3>
            <form id="share-form" novalidate>
                <div class="field"><span class="label">Gửi cho ai?</span>
                    <div class="seg" id="sh-label">${LABELS.map((l, i) => `<button type="button" data-v="${esc(l)}" class="${i === 0 ? 'on' : ''}">${l}</button>`).join('')}<button type="button" data-v="">Khác…</button></div>
                    <input id="sh-label-other" placeholder="VD: Nhà thuốc Long Châu" maxlength="60" hidden></div>
                <div class="field"><span class="label">Link dùng được trong</span>
                    <div class="seg" id="sh-exp">${EXPIRY.map(([d, l]) => `<button type="button" data-v="${d}" class="${d === 7 ? 'on' : ''}">${l}</button>`).join('')}</div></div>
                <label class="check"><input type="checkbox" id="sh-pin-on" checked><span><b>Đặt mã PIN</b><br><span class="small ink2">Người nhận phải nhập PIN mới xem được. Nên bật khi gửi qua Zalo / tin nhắn.</span></span></label>
                <div class="field" id="sh-pin-box"><label for="sh-pin">Mã PIN (4–6 số)</label>
                    <input id="sh-pin" class="pin-input" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="${esc(pin)}" autocomplete="off">
                    <span class="hint">App gợi ý sẵn một số ngẫu nhiên, bạn có thể đổi. Không dùng năm sinh, 4 số cuối điện thoại, 1234, 0000…</span></div>
                <label class="check"><input type="checkbox" id="sh-name"><span><b>Hiện họ tên đầy đủ</b><br><span class="small ink2">Mặc định chỉ hiện chữ cái đầu của tên và tuổi.</span></span></label>
                <label class="check"><input type="checkbox" id="sh-docs"><span><b>Cho xem ảnh phiếu khám</b><br><span class="small ink2">Ảnh gốc đơn thuốc, xét nghiệm. Số CCCD / BHYT đã được ẩn khi AI đọc, nhưng ảnh gốc có thể còn thấy.</span></span></label>
                <label class="check consent"><input type="checkbox" id="sh-consent"><span>Tôi đồng ý chia sẻ dữ liệu sức khỏe của mình cho <b>người có link này</b> đến khi link hết hạn hoặc tôi thu hồi.</span></label>
                <button class="btn block" type="submit">${icon('link', { size: 18 })} Tạo link</button>
            </form>`);

        const seg = (id) => sheet.querySelector(`#${id} .on`)?.dataset.v;
        sheet.querySelectorAll('.seg').forEach((s) => s.addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (!b) return;
            s.querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b));
            if (s.id === 'sh-label') { const other = sheet.querySelector('#sh-label-other'); other.hidden = b.dataset.v !== ''; if (!other.hidden) other.focus(); }
        }));
        sheet.querySelector('#sh-pin-on').addEventListener('change', (e) => { sheet.querySelector('#sh-pin-box').hidden = !e.target.checked; });

        sheet.querySelector('#share-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const label = seg('sh-label') || sheet.querySelector('#sh-label-other').value.trim();
            const withPin = sheet.querySelector('#sh-pin-on').checked;
            const pinValue = sheet.querySelector('#sh-pin').value.replace(/\D/g, '');
            if (!label) return toast('Ghi người nhận, VD: Nhà thuốc gần nhà.', 'bad');
            if (withPin && !/^\d{4,6}$/.test(pinValue)) return toast('PIN gồm 4–6 chữ số.', 'bad');
            if (!sheet.querySelector('#sh-consent').checked) return toast('Đánh dấu đồng ý chia sẻ trước khi tạo link.', 'bad');
            if (!withPin && Number(seg('sh-exp')) > 1 && !await confirmDialog('Link không có PIN: ai có link đều xem được. Nên chọn hạn 24 giờ hoặc bật PIN. Vẫn tạo?', { ok: 'Vẫn tạo' })) return;
            const button = e.target.querySelector('button[type="submit"]');
            button.disabled = true;
            try {
                const { data } = await api(`/patients/${pid}/share-links`, { method: 'POST', body: {
                    label, expires_in_days: Number(seg('sh-exp')), pin: withPin ? pinValue : null,
                    show_full_name: sheet.querySelector('#sh-name').checked, include_documents: sheet.querySelector('#sh-docs').checked, consent: true,
                } });
                showCreated(data);
                draw();
            } catch (error) {
                toast(error.errors?.pin?.[0] || error.errors?.label?.[0] || error.message, 'bad');
                button.disabled = false;
            }
        });
    }

    /** Link + QR + PIN chỉ hiện một lần này. */
    function showCreated(d) {
        const sheet = openSheet(`<div id="sh-created"><h3>${icon('check-circle', { size: 22 })} Đã tạo link cho ${esc(d.label)}</h3>
            <div class="share-url"><input id="sh-url" value="${esc(d.url)}" readonly aria-label="Link chia sẻ"><button class="btn sm" data-act="copy">${icon('copy', { size: 16 })} Chép</button></div>
            ${navigator.share ? `<button class="btn block" data-act="send" style="margin-top:8px">${icon('share', { size: 18 })} Gửi qua Zalo, tin nhắn…</button>` : ''}
            ${d.pin ? `<div class="share-pin"><small>Mã PIN</small><b class="pin-big">${esc(d.pin.split('').join(' '))}</b>
                <p class="small">Gửi PIN <b>riêng</b> với link — VD link qua Zalo, PIN đọc qua điện thoại. PIN chỉ hiện lần này, quên thì tạo link mới.</p></div>` : '<div class="warn-line">Link không có PIN — ai có link đều xem được đến khi hết hạn.</div>'}
            <details class="fold-lite" open><summary>${icon('qr', { size: 16 })} Mã QR (cho quét tại quầy)</summary><div class="share-qr">${d.qr_svg}</div></details>
            <p class="small muted">Hết hạn ${when(d.expires_at)}. Thu hồi bất cứ lúc nào ở mục Chia sẻ hồ sơ.</p>
            <button class="btn ghost block" data-act="done">Xong</button></div>`);
        // Gắn vào phần tử con tạo mới mỗi lần mở (khung bảng trượt dùng chung giữa các lần mở).
        delegate(sheet.querySelector('#sh-created'), {
            copy: async () => {
                const input = sheet.querySelector('#sh-url');
                try { await navigator.clipboard.writeText(input.value); } catch (_e) { input.select(); document.execCommand('copy'); }
                toast('Đã chép link.');
            },
            send: async () => { try { await navigator.share({ title: 'Hồ sơ sức khỏe', text: `Hồ sơ sức khỏe chia sẻ cho ${d.label}`, url: d.url }); } catch (_e) { /* người dùng huỷ */ } },
            done: () => closeSheet(),
        });
    }

    delegate(box, {
        'share-new': () => openCreate(),
        'share-revoke': async (el) => {
            if (!await confirmDialog('Thu hồi link này? Người đang giữ link sẽ không xem được nữa.', { ok: 'Thu hồi', danger: true })) return;
            try { await api(`/patients/${pid}/share-links/${el.dataset.id}`, { method: 'DELETE' }); toast('Đã thu hồi link.'); draw(); } catch (error) { toast(error.message, 'bad'); }
        },
    });

    draw();
}
