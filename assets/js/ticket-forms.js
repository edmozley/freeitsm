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

    function modal(id, width) {
        let m = document.getElementById(id);
        if (!m) {
            m = document.createElement('div');
            m.id = id;
            m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:3500;display:flex;align-items:center;justify-content:center;';
            m.addEventListener('mousedown', e => { if (e.target === m) closeModal(id); });
            document.body.appendChild(m);
        }
        m.innerHTML = '<div role="dialog" style="background:var(--surface,#fff);color:var(--text,#1e293b);border:1px solid var(--border,#cbd5e1);border-radius:8px;'
            + 'width:92%;max-width:' + width + 'px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 10px 25px rgba(0,0,0,.25);"></div>';
        m.style.display = 'flex';
        return m.firstChild;
    }
    function closeModal(id) { const m = document.getElementById(id); if (m) m.remove(); }

    function openPanel() {
        const box = modal('ticketFormsModal', 640);
        drawPanel(box);
    }

    function stateLine(f) {
        if (f.submission_id) {
            return '<span style="color:#16a34a;">' + esc(T('submitted_by', { name: f.submitted_by || '?', when: f.submitted_utc ? fmt(f.submitted_utc) : '' })) + '</span>';
        }
        if (f.has_draft) return '<span style="color:var(--text-muted,#64748b);">' + esc(T('draft_by', { name: f.draft_by || '?' })) + '</span>';
        return '<span style="color:var(--text-muted,#64748b);">' + esc(T('not_submitted')) + '</span>';
    }
    function fmt(utc) { return typeof formatDateTime === 'function' ? formatDateTime(utc) : utc; }

    function drawPanel(box) {
        const rows = state.forms.map(f => {
            const req = f.is_required
                ? '<span style="font-size:11px;padding:1px 6px;border-radius:4px;background:' + (f.requirement_mode === 'block' ? '#fee2e2;color:#991b1b' : '#fef3c7;color:#92400e') + ';">'
                  + esc(f.requirement_mode === 'block' ? T('required_block') : T('required_warn')) + '</span>'
                : '<span style="font-size:11px;color:var(--text-muted,#64748b);">' + esc(T('optional')) + '</span>';
            const gone = !f.form_exists || !f.form_active ? ' <span style="font-size:11px;color:#b45309;">' + esc(T('form_inactive')) + '</span>' : '';
            const fill = f.may_fill && state.canWrite && f.form_exists
                ? '<button type="button" class="btn btn-primary btn-sm" data-fill="' + f.id + '">' + esc(f.submission_id ? T('correct') : T('fill')) + '</button>' : '';
            const ans = f.submission_id && f.may_read ? '<button type="button" class="btn btn-secondary btn-sm" data-answers="' + f.id + '">' + esc(T('answers')) + '</button>' : '';
            const opts = state.canWrite
                ? '<select data-req="' + f.id + '" aria-label="' + esc(T('requirement')) + '" style="font-size:12px;">'
                  + ['block', 'warn', 'optional'].map(v => '<option value="' + v + '"' + ((f.is_required ? f.requirement_mode : 'optional') === v ? ' selected' : '') + '>' + esc(T('req_' + v)) + '</option>').join('')
                  + '</select> <button type="button" class="btn btn-secondary btn-sm" data-remove="' + f.id + '">' + esc(T('remove')) + '</button>' : '';
            return '<div style="border:1px solid var(--border,#e2e8f0);border-radius:6px;padding:10px 12px;margin-bottom:10px;">'
                + '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;"><strong>' + esc(f.title) + '</strong>' + req + '</div>'
                + '<div style="font-size:12px;margin:4px 0 8px;">' + stateLine(f) + gone + '</div>'
                + '<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">' + fill + ans + '<span style="margin-left:auto;">' + opts + '</span></div>'
                + '<div data-answers-box="' + f.id + '"></div></div>';
        }).join('') || '<p style="color:var(--text-muted,#64748b);">' + esc(T('none')) + '</p>';
        const have = new Set(state.forms.map(f => f.form_id));
        const choices = state.available.filter(a => !have.has(a.id));
        const add = state.canWrite && choices.length
            ? '<div style="display:flex;gap:6px;margin-top:6px;"><select id="ticketFormsAdd" aria-label="' + esc(T('add_label')) + '" style="flex:1;min-width:0;"><option value="">' + esc(T('add_label')) + '</option>'
              + choices.map(a => '<option value="' + a.id + '">' + esc(a.title) + '</option>').join('') + '</select>'
              + '<button type="button" class="btn btn-secondary btn-sm" id="ticketFormsAddBtn">' + esc(T('add')) + '</button></div>' : '';
        box.innerHTML = '<div style="padding:14px 18px;border-bottom:1px solid var(--border,#e2e8f0);display:flex;justify-content:space-between;align-items:center;">'
            + '<h3 style="margin:0;font-size:16px;">' + esc(T('title')) + '</h3>'
            + '<button type="button" class="btn btn-secondary btn-sm" id="ticketFormsClose">' + esc(T('close')) + '</button></div>'
            + '<div style="padding:14px 18px;overflow-y:auto;">' + rows + add
            + '<p style="font-size:11px;color:var(--text-muted,#64748b);margin:10px 0 0;">' + esc(T('explain')) + '</p></div>';
        box.querySelector('#ticketFormsClose').onclick = () => closeModal('ticketFormsModal');
        box.querySelectorAll('[data-fill]').forEach(b => { b.onclick = () => openFill(Number(b.dataset.fill)); });
        box.querySelectorAll('[data-answers]').forEach(b => { b.onclick = () => showAnswers(box, Number(b.dataset.answers)); });
        box.querySelectorAll('[data-remove]').forEach(b => { b.onclick = () => removeForm(box, Number(b.dataset.remove)); });
        box.querySelectorAll('[data-req]').forEach(s => { s.onchange = () => setRequirement(box, Number(s.dataset.req), s.value); });
        const addBtn = box.querySelector('#ticketFormsAddBtn');
        if (addBtn) addBtn.onclick = async () => {
            const v = Number(box.querySelector('#ticketFormsAdd').value || 0);
            if (!v) return;
            await act(box, { action: 'add', ticket_id: state.ticketId, form_id: v });
        };
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
            slot.innerHTML = '<dl style="margin:10px 0 0;font-size:13px;">' + list.map(a =>
                '<dt style="font-weight:600;margin-top:6px;">' + esc(a.label) + (a.exception ? ' <span style="color:#dc2626;font-weight:400;">' + esc(T('exception')) + '</span>' : '') + '</dt>'
                + '<dd style="margin:2px 0 0;white-space:pre-wrap;">' + esc(a.value) + '</dd>').join('') + '</dl>';
        } catch (e) { showToast(T('failed'), 'error'); }
    }

    // --- filling, in a window -------------------------------------------

    function openFill(id) {
        const box = modal('ticketFormFill', 820);
        const base = (window.API_BASE || '../api/tickets/').replace(/api\/tickets\/$/, '');
        box.innerHTML = '<div style="padding:10px 14px;border-bottom:1px solid var(--border,#e2e8f0);display:flex;justify-content:flex-end;">'
            + '<button type="button" class="btn btn-secondary btn-sm" id="ticketFormFillClose">' + esc(T('close')) + '</button></div>'
            + '<iframe id="ticketFormFrame" title="' + esc(T('fill')) + '" src="' + base + 'forms/fill.php?ticket_form=' + encodeURIComponent(id) + '&embed=1" style="border:0;width:calc(100% - 32px);margin:8px 16px;height:60vh;"></iframe>';
        box.querySelector('#ticketFormFillClose').onclick = () => { closeModal('ticketFormFill'); refresh(); };
    }

    async function refresh() {
        if (!state.ticketId) return;
        const d = await getJson('?ticket_id=' + encodeURIComponent(state.ticketId)).catch(() => null);
        if (d && d.success) { state.forms = d.forms || []; renderButton(); }
        const panel = document.querySelector('#ticketFormsModal > div');
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
            if (e.data.submitted) { closeModal('ticketFormFill'); showToast(T('submitted'), 'success'); }
        }
    });

    window.loadTicketForms = loadTicketForms;
})();
