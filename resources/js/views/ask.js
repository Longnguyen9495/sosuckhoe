/** Màn "Hỏi bác sĩ": câu hỏi soạn sẵn theo chuyên khoa (dạng chat), dấu hiệu nguy hiểm, số liên hệ. */
import { api } from '../core/api.js';
import { dm } from '../core/format.js';
import { esc, delegate, skeleton, errorBox, draftTitle } from '../ui/dom.js';
import { toast } from '../ui/shell.js';
import { CONTACTS, initials, linesToList } from './common.js';

const COLORS = ['#6C4CF1', '#C92A5E', '#16804F', '#2A62C9', '#9A6200'];

export async function renderAsk(ctx) {
    const pid = ctx.patient.id;
    const screen = ctx.render({
        title: 'Hỏi bác sĩ',
        back: '/today',
        tab: 'today',
        body: `<div class="card lift"><div class="bubble a" style="max-width:100%">👋 Mang điện thoại theo khi tái khám, mở mục này và hỏi lần lượt. Đánh dấu ô bên cạnh khi đã hỏi. Mang kèm đơn thuốc cũ và kết quả xét nghiệm.</div></div>
            <div id="groups">${skeleton(4)}</div>
            <div class="sec-title"><h2>Thêm câu hỏi</h2></div>
            <form class="card" id="add-q">
                <div class="field"><label for="q-text">Câu hỏi</label><textarea id="q-text" placeholder="VD: Nếu quên tiêm insulin buổi tối thì làm thế nào?" required></textarea></div>
                <div class="grid2"><div class="field"><label for="q-spec">Chuyên khoa</label><input id="q-spec" list="spec-list" placeholder="VD: Nội tiết"><datalist id="spec-list"></datalist></div>
                <div class="field"><label for="q-due">Hỏi trước ngày</label><input id="q-due" type="date"></div></div>
                <button class="btn block">Thêm câu hỏi</button>
            </form>
            <div class="sec-title"><h2>Khi nào cần đi khám ngay</h2></div>
            <div id="danger">${skeleton(3)}</div>
            <div class="sec-title"><h2>Số liên hệ</h2></div>
            ${CONTACTS.map(([label, number]) => `<a class="contact" href="tel:${number}"><div class="pill-ico">📞</div><div class="grow"><b>${number}</b><div class="small muted">${esc(label)}</div></div></a>`).join('')}`,
    });

    let questions = [];

    async function drawGroups() {
        const box = screen.querySelector('#groups');
        try {
            questions = (await api(`/patients/${pid}/questions`)).data;
            const groups = {};
            questions.forEach((q) => { (groups[q.specialty] ||= []).push(q); });
            screen.querySelector('#spec-list').innerHTML = Object.keys(groups).map((s) => `<option value="${esc(s)}">`).join('');
            box.innerHTML = Object.keys(groups).length ? Object.entries(groups).map(([spec, list], gi) => {
                const doctor = list.find((q) => q.doctor_name)?.doctor_name;
                const due = list.find((q) => q.due_date)?.due_date;
                const asked = list.filter((q) => q.asked).length;
                return `<div class="card">
                    <div class="doc-head"><div class="av" style="background:${COLORS[gi % COLORS.length]}">${esc(initials(spec))}</div>
                        <div class="grow"><b>${esc(spec)}</b><div class="small muted">${esc(doctor || '')}${due ? ` · hỏi trước ${dm(due)}` : ''}</div></div>
                        <span class="chip">${asked}/${list.length} đã hỏi</span></div>
                    <div class="chat">${list.map((q) => `
                        <label class="q-row"><div class="bubble q ${q.asked ? 'asked' : ''}">${esc(q.question)}</div><input type="checkbox" data-q="${esc(q.id)}" ${q.asked ? 'checked' : ''} aria-label="Đã hỏi"></label>
                        ${q.answer ? `<div class="bubble a"><b>Bác sĩ trả lời:</b> ${esc(q.answer)}</div>` : ''}`).join('')}
                    </div></div>`;
            }).join('') : '<div class="empty">Chưa có câu hỏi nào.</div>';
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    async function drawDanger() {
        const box = screen.querySelector('#danger');
        try {
            const { data } = await api('/articles?type=guide_danger');
            const a = data[0];
            box.innerHTML = a ? `<div class="card article" style="border-left:5px solid var(--bad)"><h3>${draftTitle(a.title).html}</h3>${linesToList(a.content)}</div>` : '';
        } catch (error) {
            box.innerHTML = errorBox(error.message);
        }
    }

    screen.addEventListener('change', async (event) => {
        const box = event.target.closest('[data-q]');
        if (!box) return;
        try {
            await api(`/patients/${pid}/questions/${box.dataset.q}/asked`, { method: 'PATCH', body: { asked: box.checked } });
            drawGroups();
        } catch (error) {
            toast(error.message, 'bad');
            box.checked = !box.checked;
        }
    });

    screen.querySelector('#add-q').addEventListener('submit', async (event) => {
        event.preventDefault();
        const question = screen.querySelector('#q-text').value.trim();
        if (!question) return;
        try {
            await api(`/patients/${pid}/questions`, { method: 'POST', body: { question, specialty: screen.querySelector('#q-spec').value.trim() || null, due_date: screen.querySelector('#q-due').value || null } });
            event.target.reset();
            toast('Đã thêm câu hỏi.');
            drawGroups();
        } catch (error) {
            toast(error.message, 'bad');
        }
    });

    delegate(screen, { retry: () => ctx.refresh() });
    drawGroups();
    drawDanger();
}
