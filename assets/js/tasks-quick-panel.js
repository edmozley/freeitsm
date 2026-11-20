/**
 * FreeITSM Tasks — Shared right-side quick-look detail panel
 *
 * Used by the calendar and the timeline (any view where the full dashboard
 * editor would be overkill but the user still wants to peek-and-edit
 * without losing their place). Shows: title (editable), status / priority /
 * assignee / team / start / due dates (editable), tags (read-only chips),
 * description (read-only HTML preview). A launch-icon link in the header
 * takes the user to the dashboard's full editor for richer changes
 * (subtasks, comments, rich-text description).
 *
 * The page provides:
 *   - apiBase:        base URL for get.php / save.php (e.g. '../api/tasks/')
 *   - getLookups():   return { analysts, teams, statuses, priorities }
 *   - onUpdate():     called after the panel closes — typically loadTasks()
 *                     so the calendar/timeline re-renders with any edits
 *   - fullEditUrl(id): URL the header launch icon links to
 *
 * The panel markup is injected by this module on first init() (so pages
 * only need to load the script, not include duplicate HTML).
 *
 * FULL MODE (GH #219): an analyst who chose "The full task" in Preferences
 * (tasks_open_panel = full, published by the page as window.TASKS_OPEN_PANEL)
 * gets the real task editor in this panel instead - tasks/embedded.php?host=tasks
 * in a frame, the same editor a ticket's Work tab shows - so the Table, Calendar
 * and Timeline keep their place and still show everything the board does. The
 * views need no change: they call open() as before.
 *
 * TRAP: closing in full mode ASKS THE FRAME FIRST (freeitsm:task-embed:leave,
 * answered by assets/js/task-embed-bridge.js), so an unsaved edit or a save still
 * in flight is never thrown away by Escape or a click on the overlay.
 */
(function() {
    let cfg = null;
    let taskId = null;
    let wired = false;
    let full = false;          // this open is the full editor in a frame (GH #219)
    let changed = false;       // the full editor saved something
    let leaveSeq = 0;          // ids for "may I close you?" requests
    const leaveWaiters = {};   // requestId -> resolve

    const ICON_LAUNCH = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"></path></svg>';
    const ICON_CLOSE  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';

    /**
     * Sets the page's panel configuration, injects the panel markup if needed, and wires Escape to
     * close it once.
     */
    function init(config) {
        cfg = Object.assign({
            apiBase: '',
            onUpdate: () => {},
            fullEditUrl: null,
            embedUrl: id => '../embedded.php?host=tasks&task=' + encodeURIComponent(id),
            getLookups: () => ({}),
        }, config);
        ensureMarkup();
        if (!wired) {
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape' && taskId !== null) close();
            });
            // The full editor's messages - only from its own frame, on this origin.
            window.addEventListener('message', e => {
                const frame = document.getElementById('tqFrame');
                const m = e.data;
                if (!frame || e.source !== frame.contentWindow || e.origin !== location.origin || !m) return;
                if (m.type === 'freeitsm:task-embed:leave-result' && leaveWaiters[m.requestId]) {
                    leaveWaiters[m.requestId](!!m.canLeave);
                    delete leaveWaiters[m.requestId];
                } else if (m.type === 'freeitsm:task-embed') {
                    if (m.event === 'changed') changed = true;
                    if (m.event === 'closed') finishClose();   // its own Close has already asked
                }
            });
            wired = true;
        }
    }

    /** Adds the quick-look panel and its overlay to the page, unless they are already there. */
    function ensureMarkup() {
        if (document.getElementById('tqPanel')) return;
        /** Translates a key under tasks.detail.*. */
        const T = k => window.t('tasks.detail.' + k);
        document.body.insertAdjacentHTML('beforeend',
            `<div class="detail-overlay" id="tqOverlay" onclick="TasksQuickPanel.close()"></div>
             <div class="detail-panel" id="tqPanel">
                 <div class="detail-panel-header">
                     <h3>${esc(T('heading'))}</h3>
                     <div class="detail-panel-actions">
                         <a class="btn-icon" id="tqOpenFull" href="#" title="${esc(T('open_full'))}">${ICON_LAUNCH}</a>
                         <button class="btn-icon" onclick="TasksQuickPanel.close()" title="${esc(T('close'))}">${ICON_CLOSE}</button>
                     </div>
                 </div>
                 <div class="detail-panel-body" id="tqBody"></div>
             </div>`);
    }

    /** Opens the quick-look panel on a task, showing a loading line until get.php returns it - or, in full mode, the task's own editor. */
    async function open(id, opts = {}) {
        if (taskId !== null && full && !await mayLeave()) return;   // another task while one is open
        taskId = id;
        document.getElementById('tqOpenFull').href = cfg.fullEditUrl ? cfg.fullEditUrl(id) : '#';
        full = opts.full === true || window.TASKS_OPEN_PANEL === 'full';
        document.getElementById('tqPanel').classList.toggle('tq-full', full);
        if (full) {
            changed = false;
            document.getElementById('tqBody').innerHTML =
                `<iframe class="tq-frame" id="tqFrame" title="${esc(window.t('tasks.detail.heading'))}" src="${esc(cfg.embedUrl(id))}"></iframe>`;
            document.getElementById('tqPanel').classList.add('open');
            document.getElementById('tqOverlay').classList.add('open');
            return;
        }
        document.getElementById('tqBody').innerHTML =
            `<div style="text-align:center;color:#999;padding:40px 0;">${esc(window.t('tasks.calendar.loading'))}</div>`;
        document.getElementById('tqPanel').classList.add('open');
        document.getElementById('tqOverlay').classList.add('open');
        try {
            const d = await fetch(cfg.apiBase + 'get.php?id=' + id).then(r => r.json());
            if (d.success) render(d.task);
        } catch (e) { console.error(e); }
    }

    /**
     * Closes the panel and, if a task was open, calls onUpdate so the calendar or timeline shows any
     * edits.
     */
    async function close() {
        if (taskId !== null && full && !await mayLeave()) return;
        finishClose();
    }

    /**
     * Full mode: ask the editor in the frame whether it may be closed - it flushes a
     * pending save, waits for writes in flight, and asks to Discard anything unsaved.
     * No answer within 15 seconds (a frame that never loaded, an error page) counts
     * as yes, so a broken frame can never trap the panel open.
     */
    function mayLeave() {
        const frame = document.getElementById('tqFrame');
        if (!frame || !frame.contentWindow) return Promise.resolve(true);
        const requestId = 'tq' + (++leaveSeq);
        return new Promise(resolve => {
            leaveWaiters[requestId] = resolve;
            frame.contentWindow.postMessage({type: 'freeitsm:task-embed:leave', requestId}, location.origin);
            setTimeout(() => { if (leaveWaiters[requestId]) { delete leaveWaiters[requestId]; resolve(true); } }, 15000);
        });
    }

    /** Hides the panel, empties a full-mode frame, and refreshes the caller's view. */
    function finishClose() {
        document.getElementById('tqPanel').classList.remove('open');
        document.getElementById('tqOverlay').classList.remove('open');
        const wasOpen = taskId !== null;
        taskId = null;
        if (full) { document.getElementById('tqBody').innerHTML = ''; full = false; }
        // Refresh the caller's view so inline edits (status colour, dates, etc.) surface
        if (wasOpen && cfg && cfg.onUpdate) cfg.onUpdate();
    }

    /**
     * Saves one field of the open task through save.php straight away; a failure is only logged to the
     * console.
     */
    async function saveField(field, value) {
        if (taskId === null) return;
        try {
            await fetch(cfg.apiBase + 'save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: taskId, [field]: value })
            });
        } catch (e) { console.error(e); }
    }

    /**
     * Fills the panel with the task's editable title, status, priority, assignee, team and dates, plus
     * its tags and a description preview.
     */
    function render(task) {
        const lookups = cfg.getLookups();
        /** Translates a key under tasks.detail.*. */
        const T = k => window.t('tasks.detail.' + k);

        const statusOpts = (lookups.statuses || []).filter(s => s.is_active).map(s =>
            `<option value="${esc(s.name)}"${s.name === task.status ? ' selected' : ''}>${esc(s.name)}</option>`).join('');
        const priorityOpts = (lookups.priorities || []).map(p =>
            `<option value="${esc(p.name)}"${p.name === task.priority ? ' selected' : ''}>${esc(p.name)}</option>`).join('');
        const analystOpts = `<option value="">${esc(T('unassigned'))}</option>` +
            (lookups.analysts || []).map(a =>
                `<option value="${a.id}"${a.id == task.assigned_analyst_id ? ' selected' : ''}>${esc(a.name)}</option>`).join('');
        const teamOpts = `<option value="">${esc(T('no_team'))}</option>` +
            (lookups.teams || []).map(tm =>
                `<option value="${tm.id}"${tm.id == task.assigned_team_id ? ' selected' : ''}>${esc(tm.name)}</option>`).join('');

        const tagChips = (task.tags && task.tags.length)
            ? task.tags.map(tg => {
                const c = tg.colour || '#6b7280';
                return `<span class="tag-chip" style="background:${esc(c)}1f;color:${esc(c)};border-color:${esc(c)}55">${esc(tg.name)}</span>`;
            }).join('')
            : `<span style="color:#999;font-size:var(--font-size-md, 13px);">—</span>`;

        const descBlock = task.description
            // TRAP: rich text - cleaned, never inserted raw (an onerror handler runs on insertion).
            // Fails closed to plain text if safe-html.js is missing.
            ? `<div class="cal-detail-desc-preview">${typeof safeHtmlFragment === 'function' ? safeHtmlFragment(task.description) : esc(task.description)}</div>`
            : `<div style="color:#999;font-size:var(--font-size-md, 13px);">${esc(T('no_description'))}</div>`;
        // Not a hint to go elsewhere: a button that swaps this panel for the full
        // task editor, in place (GH #219). With read-only access it opens it to read.
        const readOnly = document.body.classList.contains('module-read-only');
        const fullBtn = `<button type="button" class="btn btn-secondary tq-open-full-btn" onclick="TasksQuickPanel.openFull()"
                title="${esc(T('description_preview_hint'))}">${esc(window.t(readOnly ? 'common.open' : 'common.edit'))}</button>`;

        document.getElementById('tqBody').innerHTML = `
            <div class="detail-field">
                <input class="detail-title-input" value="${esc(task.title)}" onchange="TasksQuickPanel.saveField('title', this.value)">
            </div>
            <div class="detail-row">
                <div class="detail-field">
                    <label>${esc(T('status'))}</label>
                    <select class="detail-select" onchange="TasksQuickPanel.saveField('status', this.value)">${statusOpts}</select>
                </div>
                <div class="detail-field">
                    <label>${esc(T('priority'))}</label>
                    <select class="detail-select" onchange="TasksQuickPanel.saveField('priority', this.value)">${priorityOpts}</select>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-field">
                    <label>${esc(T('assignee'))}</label>
                    <select class="detail-select" onchange="TasksQuickPanel.saveField('assigned_analyst_id', this.value || null)">${analystOpts}</select>
                </div>
                <div class="detail-field">
                    <label>${esc(T('team'))}</label>
                    <select class="detail-select" onchange="TasksQuickPanel.saveField('assigned_team_id', this.value || null)">${teamOpts}</select>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-field">
                    <label>${esc(T('start_date'))}</label>
                    <input class="detail-input" type="date" value="${esc(task.start_date || '')}" onchange="TasksQuickPanel.saveField('start_date', this.value || null)">
                </div>
                <div class="detail-field">
                    <label>${esc(T('due_date'))}</label>
                    <input class="detail-input" type="date" value="${esc(task.due_date || '')}" onchange="TasksQuickPanel.saveField('due_date', this.value || null)">
                </div>
            </div>
            <div class="detail-field">
                <label>${esc(T('tags'))}</label>
                <div>${tagChips}</div>
            </div>
            <div class="detail-field">
                <label>${esc(T('description'))}</label>
                ${descBlock}
                ${fullBtn}
            </div>
        `;
    }

    /** HTML-escapes a value through a text node; null and undefined become an empty string. */
    function esc(text) {
        if (text == null) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** The quick look's Edit button: the same task, now in the full editor, in this panel (GH #219). */
    function openFull() {
        if (taskId === null || full) return;
        const id = taskId;
        taskId = null;   // nothing to ask: the quick look saves each field as it changes
        open(id, {full: true});
    }

    window.TasksQuickPanel = { init, open, close, saveField, openFull };
})();
