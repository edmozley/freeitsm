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
 */
(function() {
    let cfg = null;
    let taskId = null;
    let wired = false;

    const ICON_LAUNCH = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"></path></svg>';
    const ICON_CLOSE  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';

    function init(config) {
        cfg = Object.assign({
            apiBase: '',
            onUpdate: () => {},
            fullEditUrl: null,
            getLookups: () => ({}),
        }, config);
        ensureMarkup();
        if (!wired) {
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape' && taskId !== null) close();
            });
            wired = true;
        }
    }

    function ensureMarkup() {
        if (document.getElementById('tqPanel')) return;
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

    async function open(id) {
        taskId = id;
        document.getElementById('tqOpenFull').href = cfg.fullEditUrl ? cfg.fullEditUrl(id) : '#';
        document.getElementById('tqBody').innerHTML =
            `<div style="text-align:center;color:#999;padding:40px 0;">${esc(window.t('tasks.calendar.loading'))}</div>`;
        document.getElementById('tqPanel').classList.add('open');
        document.getElementById('tqOverlay').classList.add('open');
        try {
            const d = await fetch(cfg.apiBase + 'get.php?id=' + id).then(r => r.json());
            if (d.success) render(d.task);
        } catch (e) { console.error(e); }
    }

    function close() {
        document.getElementById('tqPanel').classList.remove('open');
        document.getElementById('tqOverlay').classList.remove('open');
        const wasOpen = taskId !== null;
        taskId = null;
        // Refresh the caller's view so inline edits (status colour, dates, etc.) surface
        if (wasOpen && cfg && cfg.onUpdate) cfg.onUpdate();
    }

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

    function render(task) {
        const lookups = cfg.getLookups();
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
            : `<span style="color:#999;font-size:13px;">—</span>`;

        const descBlock = task.description
            ? `<div class="cal-detail-desc-preview">${task.description}</div>
               <div style="font-size:12px;color:#999;margin-top:6px;">${esc(T('description_preview_hint'))}</div>`
            : `<div style="color:#999;font-size:13px;">${esc(T('no_description'))}</div>`;

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
            </div>
        `;
    }

    function esc(text) {
        if (text == null) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    window.TasksQuickPanel = { init, open, close, saveField };
})();
