/**
 * Biểu đồ đường SVG: 1–2 chuỗi cùng một trục, vùng mục tiêu, đường tham chiếu,
 * nhãn ở cuối đường, tooltip khi di chuột / chạm. Màu lấy từ token --s1 / --s2.
 */
import { dm, vn, diffDays } from '../core/format.js';

export function drawChart(el, series, opt = {}) {
    const all = series.flatMap((s) => s.pts);
    if (!all.length) {
        el.innerHTML = `<div class="empty">${opt.empty || 'Chưa có số liệu. Nhập chỉ số ở màn Hôm nay hoặc nút +.'}</div>`;
        return;
    }
    const W = 640, H = 220, P = { l: 36, r: opt.endLabels ? 64 : 14, t: 12, b: 26 };
    const days = [...new Set(all.map((p) => p.x))].sort();
    const x0 = days[0], span = Math.max(1, diffDays(x0, days[days.length - 1]));
    const ys = all.map((p) => p.y).concat(opt.band || [], opt.refs || []);
    const pad = opt.pad ?? 1;
    const yMin = Math.floor(Math.min(...ys) - pad), yMax = Math.ceil(Math.max(...ys) + pad);
    const X = (d) => P.l + (days.length === 1 ? (W - P.l - P.r) / 2 : (diffDays(x0, d) / span) * (W - P.l - P.r));
    const Y = (v) => P.t + (1 - (v - yMin) / (yMax - yMin || 1)) * (H - P.t - P.b);

    const grid = [];
    for (let i = 0; i <= 4; i++) {
        const v = yMin + ((yMax - yMin) * i) / 4;
        grid.push(`<line x1="${P.l}" x2="${W - P.r}" y1="${Y(v)}" y2="${Y(v)}"/><text x="${P.l - 6}" y="${Y(v) + 4}" text-anchor="end">${vn(v)}</text>`);
    }
    const nX = Math.min(days.length, 6), xl = [];
    for (let i = 0; i < nX; i++) {
        const d = days[Math.round((i * (days.length - 1)) / Math.max(1, nX - 1))];
        xl.push(`<text x="${X(d)}" y="${H - 6}" text-anchor="middle">${dm(d)}</text>`);
    }
    const band = opt.band ? `<rect x="${P.l}" width="${W - P.l - P.r}" y="${Y(opt.band[1])}" height="${Y(opt.band[0]) - Y(opt.band[1])}" style="fill:var(--band)" rx="4"/>` : '';
    const refs = (opt.refs || []).map((v) => `<line x1="${P.l}" x2="${W - P.r}" y1="${Y(v)}" y2="${Y(v)}" style="stroke:var(--muted)" stroke-dasharray="4 4" stroke-width="1"/>`).join('');
    let lines = '', ends = '';
    series.forEach((s) => {
        const pts = [...s.pts].sort((a, b) => a.x.localeCompare(b.x));
        if (pts.length > 1) lines += `<polyline fill="none" style="stroke:${s.color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="${pts.map((p) => `${X(p.x)},${Y(p.y)}`).join(' ')}"/>`;
        lines += pts.map((p) => `<circle cx="${X(p.x)}" cy="${Y(p.y)}" r="4" style="fill:${s.color};stroke:var(--card)" stroke-width="2"/>`).join('');
        if (opt.endLabels && pts.length) {
            const last = pts[pts.length - 1];
            ends += `<text x="${X(last.x) + 8}" y="${Y(last.y) + 4}" style="fill:var(--ink-2);font-weight:600">${s.name}</text>`;
        }
    });
    const legend = series.length > 1 ? `<div class="legend" style="margin:0 0 6px">${series.map((s) => `<span><i style="background:${s.color}"></i>${s.name}</span>`).join('')}</div>` : '';
    el.innerHTML = `${legend}<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${opt.aria || 'Biểu đồ'}"><g class="grid">${grid.join('')}</g>${band}${refs}${xl.join('')}${lines}${ends}<line class="xh" y1="${P.t}" y2="${H - P.b}" style="stroke:var(--muted)" stroke-width="1" opacity="0"/><rect x="${P.l}" y="${P.t}" width="${W - P.l - P.r}" height="${H - P.t - P.b}" fill="transparent"/></svg><div class="tip"></div>`;

    const svg = el.querySelector('svg'), tip = el.querySelector('.tip'), xh = el.querySelector('.xh');
    const move = (event) => {
        const r = svg.getBoundingClientRect();
        const px = ((event.clientX - r.left) / r.width) * W;
        let best = days[0];
        for (const d of days) if (Math.abs(X(d) - px) < Math.abs(X(best) - px)) best = d;
        const vals = series.map((s) => { const p = s.pts.find((q) => q.x === best); return p ? `${s.name}: <b>${vn(p.y)}</b>` : null; }).filter(Boolean);
        xh.setAttribute('x1', X(best)); xh.setAttribute('x2', X(best)); xh.setAttribute('opacity', 0.5);
        tip.innerHTML = `${dm(best)} · ${vals.join(' · ')}${opt.unit ? ` ${opt.unit}` : ''}`;
        const topY = Math.min(...series.map((s) => { const p = s.pts.find((q) => q.x === best); return p ? Y(p.y) : H; }));
        tip.style.left = `${(X(best) / W) * r.width}px`;
        tip.style.top = `${(topY / H) * r.height}px`;
        tip.classList.add('on');
    };
    svg.addEventListener('pointermove', move);
    svg.addEventListener('pointerdown', move);
    svg.addEventListener('pointerleave', () => { tip.classList.remove('on'); xh.setAttribute('opacity', 0); });
}
