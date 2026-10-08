/**
 * Projects - the Overview's briefing and the Reports tab: the AI project
 * manager (3.2.0). Rules: includes/services/project_reports.php; the AI:
 * includes/projects/ai.php.
 *
 * The AI only PROPOSES. A briefing is marked as the AI's; a report is a draft
 * until someone who may approve it does, and an approved report is final.
 * Report text is a small Markdown subset (## heading, - bullet, **bold**),
 * drawn by md() below, which escapes everything first.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = (k, p) => P.T('reports.' + k, p);
    const esc = P.esc;
    const KINDS = ['highlight', 'exception', 'checkpoint'];
    let ctx = null;
    let state = null;          // the GET from reports.php, fetched once per project
    let loading = false;
    let busy = false;          // a provider call in flight
    let wired = false;
    let editing = null;        // the report in the modal, or {kind} for a new one

    const call = body => P.api('reports.php', Object.assign({ project_id: ctx.projectId }, body));
    const when = s => (s && window.fmtDateTime ? window.fmtDateTime(s) : (s || ''));

    /** Escape, then the three marks the AI is told to use. Nothing else becomes markup. */
    function md(text) {
        const inline = s => esc(s).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        let html = '';
        let list = false;
        let para = [];
        const flush = () => { if (para.length) { html += '<p>' + para.map(inline).join('<br>') + '</p>'; para = []; } };
        const close = () => { if (list) { html += '</ul>'; list = false; } };
        String(text || '').split(/\r?\n/).forEach(raw => {
            const line = raw.trim();
            if (!line) { flush(); close(); return; }
            const h = line.match(/^#{1,4}\s+(.*)$/);
            const b = line.match(/^[-*]\s+(.*)$/);
            if (h) { flush(); close(); html += '<h4>' + inline(h[1]) + '</h4>'; }
            else if (b) { flush(); if (!list) { html += '<ul>'; list = true; } html += '<li>' + inline(b[1]) + '</li>'; }
            else { close(); para.push(line); }
        });
        flush(); close();
        return html;
    }

    function aiError(r) {
        if (!r || !r.ai_error) return false;
        P.toast(r.ai_error === 'not_configured' ? T('not_configured') : T('unreachable', { detail: r.detail || '' }), 'error');
        return true;
    }

    async function fetchState() {
        if (loading) return;
        loading = true;
        try { state = await P.api('reports.php?project_id=' + ctx.projectId); }
        catch (e) { state = null; }
        loading = false;
        draw();
    }

    // ---- The Overview's briefing ------------------------------------------------
    function drawBriefing() {
        const box = document.getElementById('pvBriefing');
        if (!box) return;
        if (!state || !state.ready || (!state.ai_ready && !state.briefing && !state.can_set_up)) { box.hidden = true; return; }
        box.hidden = false;
        const b = state.briefing;
        let html = '<div class="prj-panel prj-brief"><div class="prj-panel-head"><h3>' + P.icon('sparkles', 16) + ' ' + esc(T('briefing')) + '</h3>';
        if (state.ai_ready) html += '<button type="button" class="btn btn-secondary sm" data-brief' + (busy ? ' disabled' : '') + '>' + esc(b ? T('refresh') : T('brief_me')) + '</button>';
        else if (state.can_set_up) html += '<a class="btn btn-secondary sm" href="' + esc(window.PRJ_BASE + 'projects/settings/?tab=ai') + '">' + esc(T('setup')) + '</a>';
        html += '</div>';
        if (busy === 'briefing') html += '<p class="prj-muted prj-brief-wait"><span class="prj-spin"></span>' + esc(T('thinking')) + '</p>';
        else if (b) html += '<div class="prj-md">' + md(b.body) + '</div><p class="prj-brief-foot">' + esc(T('briefing_when', { when: when(b.created_at), name: b.created_by || '-' })) + '</p>';
        else html += '<p class="prj-muted">' + esc(state.ai_ready ? T('briefing_none') : T('briefing_off')) + '</p>';
        box.innerHTML = html + '</div>';
    }

    async function brief() {
        if (busy) return;
        busy = 'briefing'; drawBriefing();
        try {
            const r = await call({ action: 'briefing', refresh: true });
            if (!aiError(r)) state.briefing = r.briefing;
        } catch (e) { P.toast(e.message, 'error'); }
        busy = false; drawBriefing();
    }

    // ---- The Reports tab --------------------------------------------------------
    function badges(r) {
        let s = '<span class="prj-rep-kind k-' + esc(r.kind) + '">' + esc(T('kind_' + r.kind)) + '</span>'
            + '<span class="prj-rep-status s-' + esc(r.status) + '">' + esc(T('status_' + r.status)) + '</span>';
        if (r.ai_drafted) s += '<span class="prj-rep-ai">' + P.icon('sparkles', 12) + esc(r.ai_edited ? T('ai_edited') : T('ai_badge')) + '</span>';
        return s;
    }

    function drawReports() {
        const box = document.getElementById('pvReports');
        if (!box) return;
        if (!state) { box.innerHTML = ''; return; }
        if (!state.ready) { box.innerHTML = '<div class="prj-plan-empty">' + esc(T('not_ready')) + '</div>'; return; }
        let html = '<p class="prj-muted" style="margin-top:0">' + esc(T('intro')) + '</p>';
        if (state.can_write) {
            html += '<div class="prj-panel prj-rep-new"><div class="prj-rep-kinds">' + KINDS.map((k, i) =>
                '<label class="prj-rep-pick"><input type="radio" name="prjRepKind" value="' + k + '"' + (i === 0 ? ' checked' : '') + '>'
                + '<span><strong>' + esc(T('kind_' + k)) + '</strong><small>' + esc(T('kind_' + k + '_d')) + '</small></span></label>').join('') + '</div>'
                + '<div class="prj-rep-go"><label>' + esc(T('period')) + ' <select id="prjRepDays">' + [7, 14, 28].map(n => '<option value="' + n + '"' + (n === 14 ? ' selected' : '') + '>' + esc(T('days', { n: n })) + '</option>').join('') + '</select></label>'
                + (state.ai_ready ? '<button type="button" class="btn btn-primary prj-btn" data-rep-draft' + (busy ? ' disabled' : '') + '>' + P.icon('sparkles', 14) + ' ' + esc(T('draft')) + '</button>' : '')
                + '<button type="button" class="btn btn-secondary" data-rep-write>' + esc(T('write')) + '</button></div>'
                + (busy === 'draft' ? '<p class="prj-muted prj-brief-wait"><span class="prj-spin"></span>' + esc(T('thinking')) + '</p>' : '')
                + (!state.ai_ready ? '<p class="prj-muted" style="margin:10px 0 0">' + esc(T('briefing_off')) + '</p>' : '')
                + '</div>';
        }
        if (!state.reports.length) html += '<div class="prj-plan-empty">' + esc(T('none')) + '</div>';
        else html += '<div class="prj-rep-list">' + state.reports.map(r => '<button type="button" class="prj-rep-row" data-rep-open="' + r.id + '">'
            + '<span class="prj-rep-badges">' + badges(r) + '</span><span class="prj-rep-title">' + esc(r.title) + '</span>'
            + '<span class="prj-rep-meta">' + esc(r.status === 'approved' ? T('approved_by', { name: r.approved_by || '-', when: when(r.approved_at) }) : T('by', { name: r.updated_by || r.created_by || '-', when: when(r.updated_at) })) + '</span>'
            + '</button>').join('') + '</div>';
        box.innerHTML = html;
    }

    function draw() { drawBriefing(); drawReports(); }

    async function draftWithAi() {
        if (busy) return;
        const kind = (document.querySelector('input[name="prjRepKind"]:checked') || {}).value || 'highlight';
        const days = Number(document.getElementById('prjRepDays').value) || 14;
        busy = 'draft'; drawReports();
        try {
            const r = await call({ action: 'draft', kind: kind, days: days });
            if (!aiError(r)) { state.reports = r.reports; busy = false; drawReports(); openReport(r.id); return; }
        } catch (e) { P.toast(e.message, 'error'); }
        busy = false; drawReports();
    }

    // ---- The report dialog ------------------------------------------------------
    function setMode(mode) {
        const m = document.getElementById('prjReportModal');
        m.querySelectorAll('[data-rep-mode]').forEach(b => b.classList.toggle('active', b.dataset.repMode === mode));
        document.getElementById('rpEditWrap').hidden = mode !== 'edit';
        const pv = document.getElementById('rpPreview');
        pv.hidden = mode !== 'preview';
        if (mode === 'preview') pv.innerHTML = md(document.getElementById('rpBody').value);
    }

    function openReport(id) {
        const r = id ? state.reports.find(x => x.id === Number(id)) : null;
        if (id && !r) return;
        editing = r || { id: 0, kind: (document.querySelector('input[name="prjRepKind"]:checked') || {}).value || 'highlight', status: 'draft', title: '', body: '' };
        const final = editing.status === 'approved';
        const mayEdit = !final && state.can_write;
        if (!r) editing.title = T('kind_' + editing.kind) + ' - ' + new Date().toLocaleDateString();
        document.getElementById('rpHead').innerHTML = r ? badges(r) : esc(T('new_title', { kind: T('kind_' + editing.kind).toLowerCase() }));
        document.getElementById('rpTitle').value = editing.title;
        document.getElementById('rpTitle').readOnly = !mayEdit;
        document.getElementById('rpBody').value = editing.body;
        document.getElementById('rpTabs').hidden = !mayEdit;
        document.getElementById('rpAiNote').hidden = !(r && r.ai_drafted && !final);
        document.getElementById('rpMeta').textContent = r ? [T('by', { name: r.created_by || '-', when: when(r.created_at) }),
            r.approved_by ? T('approved_by', { name: r.approved_by, when: when(r.approved_at) }) : ''].filter(Boolean).join(' · ') : '';
        document.getElementById('rpSave').hidden = !mayEdit;
        document.getElementById('rpApprove').hidden = final || !state.can_approve;
        document.getElementById('rpCopy').hidden = !r;
        document.getElementById('rpDelete').hidden = !r || (final ? !state.can_approve : !state.can_write);
        document.getElementById('rpError').hidden = true;
        setMode(mayEdit && !(r && r.body) ? 'edit' : 'preview');
        P.openModal('prjReportModal');
    }

    function fail(e) { const el = document.getElementById('rpError'); el.textContent = e.message; el.hidden = false; }

    async function save(quiet) {
        const body = { action: 'save', title: document.getElementById('rpTitle').value, body: document.getElementById('rpBody').value };
        if (editing.id) body.id = editing.id; else body.kind = editing.kind;
        const r = await call(body);
        state.reports = r.reports;
        editing = state.reports.find(x => x.id === Number(r.id)) || editing;
        if (!quiet) { P.closeModal('prjReportModal'); P.toast(T('saved')); drawReports(); }
        return r.id;
    }

    async function approve() {
        const ok = await window.showConfirm({ title: T('approve_title'), message: T('approve_body'), okLabel: T('approve') });
        if (!ok) return;
        try {
            // Approve what is on the screen: an unsaved edit is saved first.
            if (!document.getElementById('rpSave').hidden) await save(true);
            const r = await call({ action: 'approve', id: editing.id });
            state.reports = r.reports;
            P.closeModal('prjReportModal'); P.toast(T('approved_toast')); drawReports();
            if (ctx.refresh) ctx.refresh();   // the history line
        } catch (e) { fail(e); }
    }

    async function remove() {
        const ok = await window.showConfirm({ title: T('delete_title'), message: T('delete_body'), okLabel: P.TC('delete'), okClass: 'danger' });
        if (!ok) return;
        try { const r = await call({ action: 'delete', id: editing.id }); state.reports = r.reports; P.closeModal('prjReportModal'); drawReports(); }
        catch (e) { fail(e); }
    }

    async function copy() {
        const text = document.getElementById('rpTitle').value + '\n\n' + document.getElementById('rpBody').value;
        const ok = window.copyToClipboard ? await window.copyToClipboard(text) : false;
        P.toast(ok ? T('copied') : P.TC('failed'), ok ? undefined : 'error');
    }

    function wire() {
        if (wired) return;
        wired = true;
        document.addEventListener('click', e => {
            if (e.target.closest('#pvBriefing [data-brief]')) { brief(); return; }
            if (e.target.closest('#pvReports [data-rep-draft]')) { draftWithAi(); return; }
            if (e.target.closest('#pvReports [data-rep-write]')) { openReport(0); return; }
            const o = e.target.closest('#pvReports [data-rep-open]');
            if (o) { openReport(o.dataset.repOpen); return; }
            const m = e.target.closest('#prjReportModal [data-rep-mode]');
            if (m) setMode(m.dataset.repMode);
        });
        document.getElementById('rpSave').addEventListener('click', async () => { try { await save(false); } catch (e) { fail(e); } });
        document.getElementById('rpApprove').addEventListener('click', approve);
        document.getElementById('rpDelete').addEventListener('click', remove);
        document.getElementById('rpCopy').addEventListener('click', copy);
    }

    window.PrjReports = {
        md: md,
        /** Called by projects-view.js after every redraw: the Overview's slot is new each time. */
        render(c) {
            const first = !ctx || ctx.projectId !== c.projectId;
            ctx = c;
            wire();
            if (first) fetchState(); else draw();
        },
    };
})();
