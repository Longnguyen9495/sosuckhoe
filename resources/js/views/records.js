/** Màn "Hồ sơ": chẩn đoán, ảnh phiếu khám + phân tích, xét nghiệm, nhận xét bác sĩ. */
import { api, apiBlob } from '../core/api.js';
import { dm } from '../core/format.js';
import { esc, delegate, skeleton, errorBox } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { toast, openLightbox, confirmDialog } from '../ui/shell.js';

const CATS = { all: 'Tất cả', don: 'Đơn thuốc', xn: 'Xét nghiệm', cdha: 'Chẩn đoán hình ảnh', kham: 'Kết quả khám', hd: 'Hướng dẫn', thuoc: 'Vỏ hộp thuốc', khac: 'Khác' };
const FLAG = { H: ['bad', '↑ Cao'], L: ['info', '↓ Thấp'], W: ['warn', 'Lưu ý'], N: ['good', 'Bình thường'] };
/** Số chẩn đoán hiện sẵn; phần còn lại gập lại để màn Hồ sơ không bị đẩy dài trên điện thoại. */
const COND_SHOWN = 6;
/** Tên chỉ số không kèm nhóm, chữ thường, bỏ dấu — khớp DuplicateMatcher::labName() phía máy chủ. */
const labName = (metric) => String(metric).split(' — ').pop().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').toLowerCase().replace(/[^a-z0-9.]+/g, ' ').trim();

export async function renderRecords(ctx) {
    const pid = ctx.patient.id;
    let cat = ctx.store.get('recCat') || 'all';
    const screen = ctx.render({
        title: 'Hồ sơ & kết quả',
        sub: 'Ảnh phiếu khám được mã hoá khi lưu',
        tab: 'records',
        body: `<div class="card lift" id="profile">${skeleton(2)}</div>
            <div class="sec-title"><h2>Phiếu khám</h2><button class="btn sm" data-act="upload">${icon('camera', { size: 16 })} Tải ảnh</button></div>
            <div class="filters" id="filters"></div>
            <div id="docs" style="margin-top:8px">${skeleton(4)}</div>
            <div class="sec-title"><h2>Kết quả xét nghiệm</h2><span id="lab-date"></span></div>
            <div id="labs">${skeleton(4)}</div>
            <div class="sec-title"><h2>Nhận xét của bác sĩ</h2></div>
            <div id="notes">${skeleton(2)}</div>
            <p class="small muted" style="margin:14px 4px">Số CCCD, mã thẻ BHYT trên phiếu được tự động loại bỏ, không lưu vào hồ sơ. Ảnh gốc được mã hoá và chỉ người được mời mới xem được.</p>`,
    });

    let documents = [];
    const thumbCache = new Map();

    async function drawProfile() {
        const box = screen.querySelector('#profile');
        try {
            const { data } = await api(`/patients/${pid}/overview`);
            const p = data.patient;
            box.innerHTML = `<b>${esc(p.full_name)}</b>${p.birth_year ? ` · sinh ${p.birth_year}` : ''}
                ${p.allergies ? `<div class="small ink2">Dị ứng: ${esc(p.allergies)}</div>` : ''}
                <div class="chips" style="margin-top:10px">${data.conditions.map((c, i) => `<span class="chip ${c.priority === 'high' ? 'bad' : 'info'}" ${i >= COND_SHOWN ? 'data-more hidden' : ''}>${esc(c.title.split(/[(—]/)[0].trim())}</span>`).join('')}
                ${data.conditions.length > COND_SHOWN ? `<button class="link-btn small" data-act="more-cond">+ Xem thêm ${data.conditions.length - COND_SHOWN} chẩn đoán</button>` : ''}</div>`;
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    function drawFilters() {
        const counts = documents.reduce((m, d) => ({ ...m, [d.type]: (m[d.type] || 0) + 1 }), {});
        screen.querySelector('#filters').innerHTML = Object.entries(CATS)
            .filter(([k]) => k === 'all' || counts[k])
            .map(([k, v]) => `<button class="fbtn ${cat === k ? 'on' : ''}" data-act="cat" data-cat="${k}">${v}${k !== 'all' ? ` · ${counts[k]}` : ''}</button>`).join('');
    }

    function drawDocs() {
        const list = documents.filter((d) => cat === 'all' || d.type === cat);
        const box = screen.querySelector('#docs');
        box.innerHTML = list.length ? list.map((d) => {
            const dup = d.duplicate_of_id ? documents.find((x) => x.id === d.duplicate_of_id) : null;
            return `<div class="card flat rec">
                <button class="thumb" data-act="zoom" data-id="${esc(d.id)}" aria-label="Xem ảnh ${esc(d.title || '')}" data-thumb="${esc(d.id)}">Đang tải…</button>
                <div class="grow"><h4>${esc(d.title || d.department || 'Phiếu khám')}</h4>
                <div class="meta">${d.document_date ? dm(d.document_date) : ''}${d.doctor_name ? ` · ${esc(d.doctor_name)}` : ''} · <span class="chip" style="padding:0 7px;font-size:.68rem">${CATS[d.type] || d.type}</span></div>
                ${dup ? `<div class="small muted"><i>Bản chụp trùng của “${esc(dup.title)}”</i></div>` : (d.findings.length ? `<ul>${d.findings.map((f) => `<li>${esc(f)}</li>`).join('')}</ul>` : (d.diagnoses?.length || d.medications_count ? '' : '<div class="small muted">Chưa có phân tích.</div>'))}
                ${(d.diagnoses || []).length ? `<div class="small ink2">Chẩn đoán: ${d.diagnoses.map((x) => esc(x.name)).join('; ')}</div>` : ''}
                <div class="chips" style="margin-top:6px">
                    ${d.ai_status === 'done' ? '<span class="chip info">AI đã đọc</span>' : d.ai_status === 'failed' ? '<span class="chip warn">AI chưa đọc được</span>' : ''}
                    ${d.medications_count ? `<span class="chip ${d.imported ? 'good' : 'y'}">${d.medications_count} thuốc${d.imported ? ' · đã vào lịch' : ' · chờ xác nhận'}</span>` : ''}
                    ${d.masked_count ? `<span class="chip good">${icon('lock', { size: 13 })} Đã ẩn ${d.masked_count} số định danh</span>` : ''}
                </div>
                <div class="row" style="margin-top:4px">${d.medications_count && !d.imported ? '<button class="link-btn small" data-act="nav" data-to="/review">Xác nhận thuốc →</button>' : ''}<span class="grow"></span><button class="link-btn small" style="color:var(--bad)" data-act="del" data-id="${esc(d.id)}">Xoá</button></div>
                </div></div>`;
        }).join('') : '<div class="empty">Chưa có phiếu nào.</div>';
        observeThumbs();
    }

    let observer = null;
    function observeThumbs() {
        observer?.disconnect();
        observer = new IntersectionObserver((entries) => {
            entries.filter((e) => e.isIntersecting).forEach(async (e) => {
                observer.unobserve(e.target);
                const id = e.target.dataset.thumb;
                try {
                    const url = await thumbUrl(id);
                    e.target.innerHTML = `<img src="${url}" alt="">`;
                } catch (_err) {
                    e.target.textContent = 'Không có ảnh';
                }
            });
        }, { rootMargin: '200px' });
        screen.querySelectorAll('[data-thumb]').forEach((el) => observer.observe(el));
    }

    async function thumbUrl(id) {
        if (thumbCache.has(id)) return thumbCache.get(id);
        const doc = documents.find((d) => d.id === id);
        const blob = await apiBlob(doc.file_url);
        const url = URL.createObjectURL(blob);
        thumbCache.set(id, url);
        return url;
    }

    async function loadDocs() {
        try {
            documents = (await api(`/patients/${pid}/documents`)).data;
            drawFilters();
            drawDocs();
        } catch (error) {
            screen.querySelector('#docs').innerHTML = errorBox(error.message);
        }
    }

    async function drawLabs() {
        const box = screen.querySelector('#labs');
        try {
            const { data } = await api(`/patients/${pid}/lab-results`);
            if (!data.length) { box.innerHTML = '<div class="empty">Chưa có kết quả xét nghiệm.</div>'; return; }
            const groups = {};
            data.forEach((r) => {
                const [group, name] = r.metric.includes(' — ') ? r.metric.split(' — ') : ['Kết quả', r.metric];
                (groups[group] ||= []).push({ ...r, name });
            });
            // Cùng chỉ số, cùng ngày mà có nhiều giá trị khác nhau: nhắc đối chiếu lại với phiếu gốc (AI có thể đọc nhầm).
            const key = (r) => `${labName(r.metric)}|${(r.measured_at || '').slice(0, 10)}`;
            const values = {};
            data.forEach((r) => { (values[key(r)] ||= new Set()).add(String(r.value).replace(/\s+/g, '').replace(',', '.').toLowerCase()); });
            const dates = [...new Set(data.map((r) => (r.measured_at || '').slice(0, 10)))].filter(Boolean);
            screen.querySelector('#lab-date').textContent = dates.length ? `ngày ${dates.map(dm).join(', ')}` : '';
            box.innerHTML = Object.entries(groups).map(([group, rows]) => `<div class="card flat scroll-x"><h3>${esc(group)}</h3>
                <table class="tbl"><thead><tr><th>Chỉ số</th><th>Kết quả</th><th>Tham chiếu</th><th></th></tr></thead><tbody>
                ${rows.map((r) => { const f = FLAG[r.flag] || FLAG.N; const conflict = values[key(r)].size > 1; return `<tr><td>${esc(r.name)}${conflict ? '<div><span class="chip warn" title="Cùng ngày có kết quả khác — đối chiếu với phiếu gốc">' + icon('warn', { size: 13 }) + ' Có 2 kết quả khác nhau</span></div>' : ''}</td><td class="v">${esc(r.value)} <span class="muted" style="font-weight:400">${esc(r.unit || '')}</span></td><td class="small muted">${esc(r.reference_range || '')}</td><td><span class="chip ${f[0]}">${f[1]}</span></td></tr>`; }).join('')}
                </tbody></table></div>`).join('');
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    async function drawNotes() {
        const box = screen.querySelector('#notes');
        try {
            const { data } = await api(`/patients/${pid}/notes`);
            box.innerHTML = data.length
                ? data.map((n) => `<div class="card flat"><div class="small muted">${esc(n.author_name || 'Bác sĩ')} · ${esc((n.created_at || '').slice(0, 10))}</div><p style="margin:4px 0 0">${esc(n.content)}</p></div>`).join('')
                : '<div class="empty">Chưa có nhận xét. Khi bác sĩ ghi nhận xét trong cổng bác sĩ, nội dung sẽ hiện ở đây.</div>';
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    delegate(screen, {
        'more-cond': (el) => { screen.querySelectorAll('#profile [data-more]').forEach((c) => { c.hidden = false; }); el.remove(); },
        cat: (el) => { cat = el.dataset.cat; ctx.store.set('recCat', cat); drawFilters(); drawDocs(); },
        zoom: async (el) => {
            try { openLightbox(await thumbUrl(el.dataset.id), 'Ảnh phiếu khám'); } catch (error) { toast(error.message, 'bad'); }
        },
        upload: () => ctx.go('/upload'),
        del: async (el) => {
            const d = documents.find((x) => x.id === el.dataset.id);
            if (!await confirmDialog(`Xoá “${d.title || 'phiếu này'}” cùng ảnh gốc và kết quả xét nghiệm đọc từ phiếu? Không khôi phục được.`, { ok: 'Xoá', danger: true })) return;
            try {
                await api(`/patients/${pid}/documents/${d.id}`, { method: 'DELETE' });
                toast('Đã xoá phiếu và ảnh.');
                thumbCache.delete(d.id);
                loadDocs();
                drawLabs();
            } catch (error) { toast(error.message, 'bad'); }
        },
        retry: () => ctx.refresh(),
    });

    drawProfile();
    loadDocs();
    drawLabs();
    drawNotes();
}
