/**
 * Watching a ticket (GitHub #277) - the ticket's Watch button and panel, and the
 * Watch / Stop watching item in the inbox's right-click menu.
 *
 * A watcher is told about the ticket as if it were theirs, through their own
 * notification settings; which events is Tickets -> Settings -> General. The rules
 * live server-side (includes/services/ticket_watchers.php): anyone who can see a
 * ticket may watch it; adding or removing a colleague needs write access.
 *
 * Talks to api/tickets/watchers.php. Uses inbox.js's globals: API_BASE, analysts,
 * escapeHtml, showToast, t, and the right-click menu's ctxTargetTicketId /
 * ctxActsOnSelection / selectedTicketIds().
 */
(function () {
    const API = () => (window.API_BASE || '../api/tickets/') + 'watchers.php';
    const T = (k, p) => (typeof window.t === 'function' ? window.t('tickets.watch.' + k, p) : k);
    const esc = (s) => (typeof escapeHtml === 'function' ? escapeHtml(s) : String(s ?? ''));
    const EYE = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';

    let state = { ticketId: null, watchers: [], watching: false, canAdd: false, ready: true };

    async function call(method, body, query) {
        const res = await fetch(API() + (query || ''), method === 'GET' ? {} : {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        });
        const data = await res.json().catch(() => ({ success: false }));
        if (!data.success) throw new Error(data.error || T('failed'));
        return data;
    }

    /** Loads the open ticket's watchers and draws its Watch button. Called by inbox.js when a ticket opens. */
    async function loadTicketWatchers(ticketId) {
        state = { ticketId, watchers: [], watching: false, canAdd: false, ready: true };
        try {
            const d = await call('GET', null, '?ticket_id=' + encodeURIComponent(ticketId));
            if (state.ticketId !== ticketId) return;   // another ticket opened meanwhile
            state = { ticketId, watchers: d.watchers || [], watching: !!d.watching, canAdd: !!d.can_add, ready: d.ready !== false };
        } catch (e) { /* the button still draws; the panel says what went wrong */ }
        renderButton();
    }

    function renderButton() {
        const toolbar = document.querySelector('.action-toolbar');
        if (!toolbar || !state.ticketId || !state.ready) return;
        let btn = document.getElementById('btnTicketWatch');
        if (!btn) {
            btn = document.createElement('button');
            btn.id = 'btnTicketWatch';
            btn.type = 'button';
            btn.className = 'action-btn';
            btn.addEventListener('click', togglePanel);
            const chk = document.getElementById('btnSopChecklists');
            toolbar.insertBefore(btn, chk ? chk.nextSibling : toolbar.firstChild);
        }
        const n = state.watchers.length;
        btn.title = T('button_title');
        btn.setAttribute('aria-pressed', state.watching ? 'true' : 'false');
        btn.innerHTML = '<span class="action-btn-icon">' + EYE + '</span><span>'
            + esc(state.watching ? T('watching') : T('watch')) + (n ? ' · ' + n : '') + '</span>';
        btn.style.color = state.watching ? 'var(--primary, #2563eb)' : '';
    }

    function closePanel() {
        const p = document.getElementById('ticketWatchPanel');
        if (p) p.remove();
        document.removeEventListener('mousedown', outside, true);
    }
    function outside(e) {
        const p = document.getElementById('ticketWatchPanel'), b = document.getElementById('btnTicketWatch');
        if (p && !p.contains(e.target) && !(b && b.contains(e.target))) closePanel();
    }

    function togglePanel() {
        if (document.getElementById('ticketWatchPanel')) { closePanel(); return; }
        const btn = document.getElementById('btnTicketWatch');
        const panel = document.createElement('div');
        panel.id = 'ticketWatchPanel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', T('title'));
        panel.style.cssText = 'position:fixed;z-index:3400;width:300px;max-width:calc(100vw - 24px);background:var(--surface,#fff);color:var(--text,#1e293b);'
            + 'border:1px solid var(--border,#cbd5e1);border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,.18);padding:12px;font-size:13px;';
        document.body.appendChild(panel);
        drawPanel(panel);
        const r = btn.getBoundingClientRect();
        panel.style.top = Math.min(r.bottom + 6, window.innerHeight - panel.offsetHeight - 8) + 'px';
        panel.style.left = Math.max(8, Math.min(r.left, window.innerWidth - panel.offsetWidth - 8)) + 'px';
        setTimeout(() => document.addEventListener('mousedown', outside, true), 0);
    }

    function drawPanel(panel) {
        const me = Number(window.CURRENT_ANALYST_ID || 0);
        const rows = state.watchers.map(w => {
            const mine = w.analyst_id === me;
            const by = w.added_by && w.added_by !== w.analyst_id && w.added_by_name ? ' <span style="color:var(--text-muted,#64748b);font-size:11px;">' + esc(T('added_by', { name: w.added_by_name })) + '</span>' : '';
            const rm = (mine || state.canAdd)
                ? '<button type="button" class="btn-link-small" data-unwatch="' + w.analyst_id + '" style="margin-left:auto;">' + esc(mine ? T('stop') : T('remove')) + '</button>' : '';
            return '<div style="display:flex;align-items:center;gap:6px;padding:4px 0;"><span>' + esc(w.name) + (mine ? ' (' + esc(T('you')) + ')' : '') + '</span>' + by + rm + '</div>';
        }).join('') || '<div style="color:var(--text-muted,#64748b);padding:4px 0;">' + esc(T('none')) + '</div>';
        const watchedIds = new Set(state.watchers.map(w => w.analyst_id));
        const options = (typeof analysts !== 'undefined' ? analysts : [])
            .filter(a => a.is_active !== false && !watchedIds.has(Number(a.id)))
            .map(a => '<option value="' + Number(a.id) + '">' + esc(a.full_name || a.name || '') + '</option>').join('');
        const add = state.canAdd && options
            ? '<div style="display:flex;gap:6px;margin-top:10px;"><select id="ticketWatchAdd" aria-label="' + esc(T('add_label')) + '" style="flex:1;min-width:0;"><option value="">' + esc(T('add_label')) + '</option>' + options + '</select>'
              + '<button type="button" class="btn btn-secondary btn-sm" id="ticketWatchAddBtn">' + esc(T('add')) + '</button></div>' : '';
        panel.innerHTML = '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;"><strong>' + esc(T('title')) + '</strong>'
            + '<button type="button" class="btn btn-primary btn-sm" id="ticketWatchMe">' + esc(state.watching ? T('stop') : T('watch')) + '</button></div>'
            + rows + add
            + '<p style="margin:10px 0 0;color:var(--text-muted,#64748b);font-size:11px;">' + esc(T('explain')) + '</p>';
        panel.querySelector('#ticketWatchMe').onclick = () => change(state.watching ? 'unwatch' : 'watch', me, panel);
        panel.querySelectorAll('[data-unwatch]').forEach(b => { b.onclick = () => change('unwatch', Number(b.dataset.unwatch), panel); });
        const addBtn = panel.querySelector('#ticketWatchAddBtn');
        if (addBtn) addBtn.onclick = () => {
            const v = Number(panel.querySelector('#ticketWatchAdd').value || 0);
            if (v) change('watch', v, panel);
        };
    }

    async function change(action, analystId, panel) {
        try {
            const d = await call('POST', { action, ticket_id: state.ticketId, analyst_id: analystId });
            state.watchers = d.watchers || [];
            state.watching = !!d.watching;
            renderButton();
            if (panel && document.body.contains(panel)) drawPanel(panel);
        } catch (e) {
            if (typeof showToast === 'function') showToast(e.message, 'error');
        }
    }

    // --- the right-click menu ---------------------------------------------

    /** Sets the menu item's label for the right-clicked ticket(s): Watch, or Stop watching when I already do. */
    async function populateContextWatchItem() {
        const item = document.getElementById('ctxWatchItem');
        if (!item) return;
        const label = item.querySelector('span');
        item.dataset.mode = 'watch';
        label.textContent = T('watch');
        if (ctxActsOnSelection) return;   // a selection: "Watch" each of them
        const id = ctxTargetTicketId;
        // The ticket open in the reading pane is already known.
        if (state.ticketId === id) {
            item.dataset.mode = state.watching ? 'unwatch' : 'watch';
            label.textContent = state.watching ? T('stop') : T('watch');
            return;
        }
        try {
            const d = await call('GET', null, '?ticket_id=' + encodeURIComponent(id));
            if (ctxTargetTicketId !== id) return;
            item.dataset.mode = d.watching ? 'unwatch' : 'watch';
            label.textContent = d.watching ? T('stop') : T('watch');
        } catch (e) { /* stays "Watch" */ }
    }

    /** The right-click item: watch or stop watching the ticket, or watch every selected one. */
    async function watchFromContext() {
        const item = document.getElementById('ctxWatchItem');
        const mode = item ? item.dataset.mode : 'watch';
        if (typeof closeTicketContextMenu === 'function') closeTicketContextMenu();
        try {
            if (ctxActsOnSelection) {
                const d = await call('POST', { action: 'watch_many', ticket_ids: selectedTicketIds() });
                let msg = T('many_done', { n: d.added });
                if (d.already) msg += ' ' + T('many_already', { n: d.already });
                if (d.skipped) msg += ' ' + T('many_skipped', { n: d.skipped });
                showToast(msg, 'success');
            } else {
                const id = ctxTargetTicketId;
                const d = await call('POST', { action: mode, ticket_id: id });
                showToast(mode === 'watch' ? T('now_watching') : T('stopped'), 'success');
                if (state.ticketId === id) { state.watchers = d.watchers || []; state.watching = !!d.watching; renderButton(); }
            }
            if (state.ticketId) loadTicketWatchers(state.ticketId);
        } catch (e) {
            showToast(e.message, 'error');
        }
    }

    window.loadTicketWatchers = loadTicketWatchers;
    window.populateContextWatchItem = populateContextWatchItem;
    window.watchFromContext = watchFromContext;
})();
