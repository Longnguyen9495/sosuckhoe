/** Màn "Hồ sơ": tóm tắt + chẩn đoán, ảnh phiếu khám + phân tích, xét nghiệm, nhận xét bác sĩ. */
import { api, apiBlob } from '../core/api.js';
import { dm } from '../core/format.js';
import { esc, delegate, skeleton, errorBox } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { toast, openLightbox, confirmDialog } from '../ui/shell.js';
import { blockHead, sumTile } from './common.js';

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
        body: `<div class="card lift today-sum">
                <div class="sum-grid" id="rec-tiles"></div>
                <div id="profile">${skeleton(1)}</div>
            </div>

            <section class="blk">
                ${blockHead('file', 'Phiếu khám', `<button class="btn sm" data-act="upload">${icon('camera', { size: 16 })} Tải ảnh</button>`)}
                <div class="filters" id="filters"></div>
                <div id="docs" style="margin-top:8px">${skeleton(4)}</div>
            </section>

            <section class="blk">
                ${blockHead('flask', 'Kết quả xét nghiệm', '<span class="blk-sub" id="lab-date"></span>')}
                <div id="labs">${skeleton(4)}</div>
            </section>

            <section class="blk">
                ${blockHead('stethoscope', 'Nhận xét của bác sĩ')}
                <div id="notes">${skeleton(2)}</div>
            </section>

            <p class="small muted rec-privacy">${icon('lock', { size: 14 })}<span>Số CCCD, mã thẻ BHYT trên phiếu được tự động loại bỏ, không lưu vào hồ sơ. Ảnh gốc được mã hoá và chỉ người được mời mới xem được.</span></p>`,
    });

    let documents = [];
    const thumbCache = new Map();

    /* ----- Tóm tắt: số phiếu · xét nghiệm (bao nhiêu chỉ số ngoài ngưỡng) · chẩn đoán ----- */
    const sum = { docs: undefined, labs: undefined, conds: undefined };
    function drawSummary() {
        const note = (text) => `<span class="small muted sum-line">${text}</span>`;
        const wait = ['—', note('…')];
        const docs = sum.docs === undefined ? wait : [String(sum.docs.total), note(sum.docs.pending ? `${sum.docs.pending} chờ xác nhận thuốc` : sum.docs.total ? 'đã lưu' : 'chưa có phiếu')];
        const labs = sum.labs === undefined ? wait : [String(sum.labs.total), sum.labs.abnormal ? `<span class="chip bad">${sum.labs.abnormal} ngoài ngưỡng</span>` : note(sum.labs.total ? 'trong ngưỡng' : 'chưa có')];
        const conds = sum.conds === undefined ? wait : [String(sum.conds.total), sum.conds.high ? `<span class="chip bad">${sum.conds.high} ưu tiên cao</span>` : note(sum.conds.total ? 'đang theo dõi' : 'chưa ghi nhận')];
        screen.querySelector('#rec-tiles').innerHTML = `
            ${sumTile('file', 'Phiếu khám', ...docs)}
            ${sumTile('flask', 'Xét nghiệm', ...labs)}
            ${sumTile('stethoscope', 'Chẩn đoán', ...conds)}`;
    }
    drawSummary();

    async function drawProfile() {
        const box = screen.querySelector('#profile');
        try {
            const { data } = await api(`/patients/${pid}/overview`);
            const p = data.patient;
            sum.conds = { total: data.conditions.length, high: data.conditions.filter((c) => c.priority === 'high').length };
            drawSummary();
            box.innerHTML = `<div class="rec-who"><b>${esc(p.full_name)}</b>${p.birth_year ? `<span class="muted small">sinh ${p.birth_year} · ${new Date().getFullYear() - p.birth_year} tuổi</span>` : ''}</div>
                ${p.allergies ? `<p class="rec-allergy">${icon('warn', { size: 16 })}<span><b>Dị ứng:</b> ${esc(p.allergies)}</span></p>` : ''}
                ${data.conditions.length ? `<div class="chips" style="margin-top:10px">${data.conditions.map((c, i) => `<span class="chip ${c.priority === 'high' ? 'bad' : 'info'}" ${i >= COND_SHOWN ? 'data-more hidden' : ''}>${esc(c.title.split(/[(—]/)[0].trim())}</span>`).join('')}
                ${data.conditions.length > COND_SHOWN ? `<button class="link-btn small" data-act="more-cond">+ Xem thêm ${data.conditions.length - COND_SHOWN} chẩn đoán</button>` : ''}</div>` : ''}`;
        } catch (error) {
            sum.conds = { total: 0, high: 0 };
            drawSummary();
            box.innerHTML = errorBox(error.message);
        }
    }

    function drawFilters() {
        const counts = documents.reduce((m, d) => ({ ...m, [d.type]: (m[d.type] || 0) + 1 }), {});
        const box = screen.querySelector('#filters');
        box.hidden = documents.length === 0;
        box.innerHTML = Object.entries(CATS)
            .filter(([k]) => k === 'all' || counts[k])
            .map(([k, v]) => `<button class="fbtn ${cat === k ? 'on' : ''}" data-act="cat" data-cat="${k}">${v}${k !== 'all' ? ` · ${counts[k]}` : ''}</button>`).join('');
    }

    function drawDocs() {
        const list = documents.filter((d) => cat === 'all' || d.type === cat);
        const box = screen.querySelector('#docs');
        box.innerHTML = list.length ? list.map((d) => {
            const dup = d.duplicate_of_id ? documents.find((x) => x.id === d.duplicate_of_id) : null;
            return `<div class="card rec">
                <button class="thumb" data-act="zoom" data-id="${esc(d.id)}" aria-label="Xem ảnh ${esc(d.title || '')}" data-thumb="${esc(d.id)}">Đang tải…</button>
                <div class="grow"><h4>${esc(d.title || d.department || 'Phiếu khám')}</h4>
                <div class="meta"><span class="chip">${CATS[d.type] || d.type}</span>${d.document_date ? ` ${dm(d.document_date)}` : ''}${d.doctor_name ? ` · ${esc(d.doctor_name)}` : ''}</div>
                ${dup ? `<div class="small muted"><i>Bản chụp trùng của “${esc(dup.title)}”</i></div>` : (d.findings.length ? `<ul>${d.findings.map((f) => `<li>${esc(f)}</li>`).join('')}</ul>` : (d.diagnoses?.length || d.medications_count ? '' : '<div class="small muted">Chưa có phân tích.</div>'))}
                ${(d.diagnoses || []).length ? `<div class="small ink2" style="margin-top:4px"><b>Chẩn đoán:</b> ${d.diagnoses.map((x) => esc(x.name)).join('; ')}</div>` : ''}
                <div class="chips" style="margin-top:8px">
                    ${d.ai_status === 'done' ? `<span class="chip info">${icon('sparkles', { size: 13 })} AI đã đọc</span>` : d.ai_status === 'failed' ? '<span class="chip warn">AI chưa đọc được</span>' : ''}
                    ${d.medications_count ? `<span class="chip ${d.imported ? 'good' : 'y'}">${d.medications_count} thuốc${d.imported ? ' · đã vào lịch' : ' · chờ xác nhận'}</span>` : ''}
                    ${d.masked_count ? `<span class="chip good">${icon('lock', { size: 13 })} Đã ẩn ${d.masked_count} số định danh</span>` : ''}
                </div>
                <div class="rec-acts">${d.medications_count && !d.imported ? `<button class="btn sm" data-act="nav" data-to="/review">Xác nhận thuốc ${icon('chevron-right', { size: 16 })}</button>` : ''}<span class="grow"></span><button class="link-btn small" style="color:var(--bad)" data-act="del" data-id="${esc(d.id)}">Xoá</button></div>
                </div></div>`;
        }).join('') : `<div class="empty">${documents.length ? 'Không có phiếu thuộc nhóm này.' : 'Chưa có phiếu nào. Chạm “Tải ảnh” để AI đọc đơn thuốc, phiếu xét nghiệm.'}</div>`;
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
            sum.docs = { total: documents.length, pending: documents.filter((d) => d.medications_count && !d.imported).length };
            drawSummary();
            drawFilters();
            drawDocs();
        } catch (error) {
            sum.docs = { total: 0, pending: 0 };
            drawSummary();
            screen.querySelector('#docs').innerHTML = errorBox(error.message);
        }
    }

    /** Xét nghiệm theo nhóm; mỗi chỉ số một dòng: tên + tham chiếu bên trái, kết quả + mức bên phải (không cần cuộn ngang). */
    async function drawLabs() {
        const box = screen.querySelector('#labs');
        try {
            const { data } = await api(`/patients/${pid}/lab-results`);
            sum.labs = { total: data.length, abnormal: data.filter((r) => r.flag === 'H' || r.flag === 'L').length };
            drawSummary();
            if (!data.length) { box.innerHTML = '<div class="empty">Chưa có kết quả xét nghiệm. Tải ảnh phiếu xét nghiệm để AI đọc.</div>'; return; }
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
            box.innerHTML = Object.entries(groups).map(([group, rows]) => {
                const off = rows.filter((r) => r.flag === 'H' || r.flag === 'L').length;
                return `<div class="card lab-card"><div class="lab-h"><h3>${esc(group)}</h3>${off ? `<span class="chip bad">${off} ngoài ngưỡng</span>` : `<span class="small muted">${rows.length} chỉ số</span>`}</div>
                <div class="lab-list">${rows.map((r) => {
                    const f = FLAG[r.flag] || FLAG.N;
                    const conflict = values[key(r)].size > 1;
                    return `<div class="lab-row ${r.flag === 'H' || r.flag === 'L' ? 'off' : ''}"><div class="lab-name">${esc(r.name)}${r.reference_range ? `<small>Tham chiếu: ${esc(r.reference_range)}</small>` : ''}${conflict ? `<span class="chip warn" title="Cùng ngày có kết quả khác — đối chiếu với phiếu gốc">${icon('warn', { size: 13 })} Có 2 kết quả khác nhau</span>` : ''}</div>
                        <div class="lab-val"><b>${esc(r.value)}</b> <span class="muted">${esc(r.unit || '')}</span><span class="chip ${f[0]}">${f[1]}</span></div></div>`;
                }).join('')}</div></div>`;
            }).join('');
        } catch (error) {
            sum.labs = { total: 0, abnormal: 0 };
            drawSummary();
            box.innerHTML = errorBox(error.message);
        }
    }

    async function drawNotes() {
        const box = screen.querySelector('#notes');
        try {
            const { data } = await api(`/patients/${pid}/notes`);
            box.innerHTML = data.length
                ? data.map((n) => `<div class="card note-card"><div class="small muted">${icon('stethoscope', { size: 14 })} ${esc(n.author_name || 'Bác sĩ')} · ${esc((n.created_at || '').slice(0, 10).split('-').reverse().join('/'))}</div><p style="margin:6px 0 0">${esc(n.content)}</p></div>`).join('')
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
