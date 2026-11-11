/**
 * Forms on a ticket (GitHub #272) - the ticket's Forms button and panel.
 *
 * The ticket twin of a task's Forms section: add a form to the ticket, fill it in
 * on the ticket (the real fill page, forms/fill.php?ticket_form=N&embed=1, in a
 * window), see who submitted it, and - for those allowed - read the answers. A form
 * can be required before the ticket closes: block (refused) or warn (recorded).
 * The rules are server-side (TicketForms in includes/services/task_forms.php).
 *
 * Talks to api/tickets/forms.php. Uses inbox.js's globals: API_BASE, escapeHtml,
 * showToast, showConfirm, t.
 */
(function () {
    const API = () => (window.API_BASE || '../api/tickets/') + 'forms.php';
    const T = (k, p) => (typeof window.t === 'function' ? window.t('tickets.forms.' + k, p) : k);
    const esc = (s) => (typeof escapeHtml === 'function' ? escapeHtml(s) : String(s ?? ''));
    const ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg>';

    let state = { ticketId: null, enabled: false, forms: [], available: [], canWrite: false };

    async function getJson(q) {
        const r = await fetch(API() + q);
        return r.json();
    }
    async function post(body) {
        const r = await fetch(API(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        return r.json();
    }

    /** Loads the open ticket's forms and draws its Forms button. Called by inbox.js when a ticket opens. */
    async function loadTicketForms(ticketId) {
        state = { ticketId, enabled: false, forms: [], available: [], canWrite: false };
        try {
            const d = await getJson('?ticket_id=' + encodeURIComponent(ticketId));
            if (state.ticketId !== ticketId) return;   // another ticket opened meanwhile
            if (d.success) state = { ticketId, enabled: !!d.enabled, forms: d.forms || [], available: d.available || [], canWrite: !!d.can_write };
        } catch (e) { /* no button */ }
        renderButton();
    }

    function renderButton() {
        const toolbar = document.querySelector('.action-toolbar');
        let btn = document.getElementById('btnTicketForms');
        if (!toolbar || !state.enabled) { if (btn) btn.remove(); return; }
        if (!btn) {
            btn = document.createElement('button');
            btn.id = 'btnTicketForms';
            btn.type = 'button';
            btn.className = 'action-btn';
            btn.addEventListener('click', openPanel);
            const after = document.getElementById('btnTicketWatch') || document.getElementById('btnSopChecklists');
            toolbar.insertBefore(btn, after ? after.nextSibling : toolbar.firstChild);
        }
        const n = state.forms.length, done = state.forms.filter(f => f.submission_id).length;
        btn.title = T('button_title');
        btn.innerHTML = '<span class="action-btn-icon">' + ICON + '</span><span>' + esc(T('button')) + (n ? ' · ' + done + '/' + n : '') + '</span>';
    }

    // --- the panel -------------------------------------------------------

    // The panel's own look, injected once. Everything else is the app's shared modal
    // (.modal / .modal-content / -header / -body / -footer in inbox.css) and .btn / .form-input.
    function injectStyle() {
        if (document.getElementById('ticketFormsStyle')) return;
        const st = document.createElement('style');
        st.id = 'ticketFormsStyle';
        st.textContent = `
.tf-card{border:1px solid var(--border,#e2e8f0);border-radius:8px;padding:14px 16px;margin-bottom:12px;}
.tf-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;}
.tf-title{font-weight:600;font-size:15px;color:var(--text,#1e293b);line-height:1.3;}
.tf-badge{flex-shrink:0;font-size:11px;font-weight:600;padding:3px 9px;border-radius:999px;white-space:nowrap;}
.tf-badge.block{background:rgba(220,38,38,.14);color:#dc2626;}
.tf-badge.warn{background:rgba(217,119,6,.16);color:#b45309;}
[data-theme="dark"] .tf-badge.block{color:#f87171;}
[data-theme="dark"] .tf-badge.warn{color:#fbbf24;}
.tf-badge.optional{background:rgba(100,116,139,.15);color:var(--text-muted,#64748b);}
.tf-state{font-size:13px;color:var(--text-muted,#64748b);margin:4px 0 14px;}
.tf-state.done{color:#16a34a;}
.tf-gone{color:#b45309;margin-left:6px;}
.tf-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.tf-row .btn,.tf-add .btn{padding:7px 16px;font-size:13px;line-height:1.2;}
.tf-right{margin-left:auto;display:flex;align-items:center;gap:6px;}
.tf-row select.form-input{width:auto;padding:6px 8px;font-size:13px;}
.tf-remove{background:none;border:0;padding:7px 6px;font-size:13px;color:var(--text-muted,#64748b);cursor:pointer;border-radius:4px;}
.tf-remove:hover{color:#dc2626;background:rgba(220,38,38,.08);}
.tf-add{display:flex;gap:8px;margin-top:4px;}
.tf-add .btn:disabled{opacity:.5;cursor:default;}
.tf-add select.form-input{flex:1;min-width:0;padding:7px 10px;font-size:13px;}
.tf-empty{color:var(--text-muted,#64748b);margin:0 0 14px;}
.tf-answers{margin:12px 0 0;padding-top:10px;border-top:1px solid var(--border,#e2e8f0);font-size:13px;}
.tf-answers dt{font-weight:600;margin-top:8px;color:var(--text,#1e293b);}
.tf-answers dt:first-child{margin-top:0;}
.tf-answers dd{margin:2px 0 0;white-space:pre-wrap;color:var(--text,#1e293b);}
.tf-exception{color:#dc2626;font-weight:400;}
#ticketFormsModal .modal-footer{justify-content:space-between;align-items:center;}
.tf-explain{font-size:12px;line-height:1.45;color:var(--text-muted,#64748b);margin:0;flex:1;}
@media (max-width:560px){.tf-right{margin-left:0;width:100%;}.tf-row select.form-input{flex:1;}}
`;
        document.head.appendChild(st);
    }

    /** One of the app's own modals, built on demand; returns its .modal-content. */
    function modal(id, width) {
        injectStyle();
        let m = document.getElementById(id);
        if (!m) {
            m = document.createElement('div');
            m.id = id;
            m.className = 'modal';
            m.addEventListener('mousedown', e => { if (e.target === m) closeModal(id); });
            document.body.appendChild(m);
        }
        m.innerHTML = '<div class="modal-content" role="dialog" aria-modal="true" style="max-width:' + width + 'px;"></div>';
        requestAnimationFrame(() => m.classList.add('active'));
        return m.firstChild;
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        if (!m) return;
        m.classList.remove('active');
        setTimeout(() => m.remove(), 280);   // let the shared fade finish
        if (id === 'ticketFormFill') refresh();
    }
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (document.getElementById('ticketFormFill')) closeModal('ticketFormFill');
        else if (document.getElementById('ticketFormsModal')) closeModal('ticketFormsModal');
    });

    function openPanel() {
        const box = modal('ticketFormsModal', 620);
        drawPanel(box);
    }

    function stateLine(f) {
        if (f.submission_id) return '<div class="tf-state done">' + esc(T('submitted_by', { name: f.submitted_by || '?', when: f.submitted_utc ? fmt(f.submitted_utc) : '' }));
        if (f.has_draft) return '<div class="tf-state">' + esc(T('draft_by', { name: f.draft_by || '?' }));
        return '<div class="tf-state">' + esc(T('not_submitted'));
    }
    function fmt(utc) { return typeof formatDateTime === 'function' ? formatDateTime(utc) : utc; }

    function drawPanel(box) {
        const rows = state.forms.map(f => {
            const mode = f.is_required ? f.requirement_mode : 'optional';
            const badge = '<span class="tf-badge ' + mode + '">'
                + esc(mode === 'block' ? T('required_block') : mode === 'warn' ? T('required_warn') : T('optional')) + '</span>';
            const gone = !f.form_exists || !f.form_active ? '<span class="tf-gone">' + esc(T('form_inactive')) + '</span>' : '';
            const fill = f.may_fill && state.canWrite && f.form_exists
                ? '<button type="button" class="btn ' + (f.submission_id ? 'btn-secondary' : 'btn-primary') + '" data-fill="' + f.id + '">' + esc(f.submission_id ? T('correct') : T('fill')) + '</button>' : '';
            const ans = f.submission_id && f.may_read ? '<button type="button" class="btn btn-secondary" data-answers="' + f.id + '">' + esc(T('answers')) + '</button>' : '';
            const right = state.canWrite
                ? '<span class="tf-right"><select class="form-input" data-req="' + f.id + '" aria-label="' + esc(T('requirement')) + '">'
                  + ['block', 'warn', 'optional'].map(v => '<option value="' + v + '"' + (mode === v ? ' selected' : '') + '>' + esc(T('req_' + v)) + '</option>').join('')
                  + '</select><button type="button" class="tf-remove" data-remove="' + f.id + '">' + esc(T('remove')) + '</button></span>' : '';
            return '<div class="tf-card">'
                + '<div class="tf-head"><span class="tf-title">' + esc(f.title) + '</span>' + badge + '</div>'
                + stateLine(f) + gone + '</div>'
                + '<div class="tf-row">' + fill + ans + right + '</div>'
                + '<div data-answers-box="' + f.id + '"></div></div>';
        }).join('') || '<p class="tf-empty">' + esc(T('none')) + '</p>';
        const have = new Set(state.forms.map(f => f.form_id));
        const choices = state.available.filter(a => !have.has(a.id));
        const add = state.canWrite && choices.length
            ? '<div class="tf-add"><select class="form-input" id="ticketFormsAdd" aria-label="' + esc(T('add_label')) + '"><option value="">' + esc(T('add_label')) + '</option>'
              + choices.map(a => '<option value="' + a.id + '">' + esc(a.title) + '</option>').join('') + '</select>'
              + '<button type="button" class="btn btn-primary" id="ticketFormsAddBtn" disabled>' + esc(T('add')) + '</button></div>' : '';
        box.innerHTML = '<div class="modal-header">' + esc(T('title')) + '</div>'
            + '<div class="modal-body">' + rows + add + '</div>'
            + '<div class="modal-footer"><p class="tf-explain">' + esc(T('explain')) + '</p>'
            + '<button type="button" class="btn btn-secondary" id="ticketFormsClose">' + esc(T('close')) + '</button></div>';
        box.querySelector('#ticketFormsClose').onclick = () => closeModal('ticketFormsModal');
        box.querySelectorAll('[data-fill]').forEach(b => { b.onclick = () => openFill(Number(b.dataset.fill)); });
        box.querySelectorAll('[data-answers]').forEach(b => { b.onclick = () => showAnswers(box, Number(b.dataset.answers)); });
        box.querySelectorAll('[data-remove]').forEach(b => { b.onclick = () => removeForm(box, Number(b.dataset.remove)); });
        box.querySelectorAll('[data-req]').forEach(s => { s.onchange = () => setRequirement(box, Number(s.dataset.req), s.value); });
        const addSel = box.querySelector('#ticketFormsAdd'), addBtn = box.querySelector('#ticketFormsAddBtn');
        if (addSel && addBtn) {
            addSel.onchange = () => { addBtn.disabled = !addSel.value; };
            addBtn.onclick = async () => {
                const v = Number(addSel.value || 0);
                if (!v) return;
                await act(box, { action: 'add', ticket_id: state.ticketId, form_id: v });
            };
        }
    }
    async function act(box, body) {
        try {
            const d = await post(body);
            if (!d.success) { showToast(d.error || T('failed'), 'error'); return; }
            state.forms = d.forms || [];
            renderButton();
            if (box && document.body.contains(box)) drawPanel(box);
        } catch (e) { showToast(T('failed'), 'error'); }
    }
    function setRequirement(box, id, v) {
        act(box, { action: 'update', task_form_id: id, required: v !== 'optional', mode: v === 'optional' ? 'block' : v });
    }
    async function removeForm(box, id) {
        const f = state.forms.find(x => x.id === id);
        const ok = typeof showConfirm === 'function'
            ? await showConfirm({ title: T('remove'), message: T('remove_confirm', { name: f ? f.title : '' }), okLabel: T('remove'), okClass: 'danger' })
            : true;
        if (ok) act(box, { action: 'remove', task_form_id: id });
    }
    async function showAnswers(box, id) {
        const slot = box.querySelector('[data-answers-box="' + id + '"]');
        if (!slot) return;
        if (slot.innerHTML) { slot.innerHTML = ''; return; }
        try {
            const d = await getJson('?ticket_id=' + encodeURIComponent(state.ticketId) + '&answers=1');
            const f = (d.forms || []).find(x => x.id === id);
            const list = (f && f.answers) || [];
            slot.innerHTML = '<dl class="tf-answers">' + list.map(a =>
                '<dt>' + esc(a.label) + (a.exception ? ' <span class="tf-exception">' + esc(T('exception')) + '</span>' : '') + '</dt>'
                + '<dd>' + esc(a.value) + '</dd>').join('') + '</dl>';
        } catch (e) { showToast(T('failed'), 'error'); }
    }

    // --- filling, in a window -------------------------------------------

    function openFill(id) {
        const f = state.forms.find(x => x.id === id);
        const box = modal('ticketFormFill', 820);
        const base = (window.API_BASE || '../api/tickets/').replace(/api\/tickets\/$/, '');
        box.innerHTML = '<div class="modal-header">' + esc(f ? f.title : T('fill')) + '</div>'
            + '<div class="modal-body" style="padding:8px 16px;">'
            + '<iframe id="ticketFormFrame" title="' + esc(f ? f.title : T('fill')) + '" src="' + base + 'forms/fill.php?ticket_form=' + encodeURIComponent(id) + '&embed=1" style="border:0;width:100%;height:60vh;display:block;"></iframe></div>'
            + '<div class="modal-footer"><button type="button" class="btn btn-secondary" id="ticketFormFillClose">' + esc(T('close')) + '</button></div>';
        box.querySelector('#ticketFormFillClose').onclick = () => closeModal('ticketFormFill');
    }
    async function refresh() {
        if (!state.ticketId) return;
        const d = await getJson('?ticket_id=' + encodeURIComponent(state.ticketId)).catch(() => null);
        if (d && d.success) { state.forms = d.forms || []; renderButton(); }
        const panel = document.querySelector('#ticketFormsModal.active > .modal-content');
        if (panel) drawPanel(panel);
    }

    // The fill page says when it saved or submitted, and how tall it is (forms/fill.php).
    window.addEventListener('message', (e) => {
        if (e.origin !== location.origin || !e.data || typeof e.data !== 'object') return;
        const frame = document.getElementById('ticketFormFrame');
        if (!frame || e.source !== frame.contentWindow) return;
        if (e.data.type === 'tf-height' && e.data.height) frame.style.height = Math.min(Math.max(e.data.height + 24, 240), window.innerHeight * 0.8) + 'px';
        if (e.data.type === 'tf-saved') {
            refresh();
            if (e.data.submitted) { closeModal('ticketFormFill'); showToast(T('submitted'), 'success'); return; }
        }
    });

    window.loadTicketForms = loadTicketForms;
})();
