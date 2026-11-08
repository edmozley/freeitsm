/**
 * FreeITSM Tasks — Shared right-click context menu
 *
 * One menu used by the dashboard (right-click a board / list card) and the
 * timeline (right-click a Gantt bar). Each page provides:
 *   - targetSelector: CSS selector that identifies a clickable task element
 *   - getTaskId(el):  return the numeric task id from that element
 *   - getTask(id):    return the task object (so submenus know the current
 *                     analyst/team/status/priority for the ✓ marker)
 *   - getLookups():   return { analysts, teams, statuses, priorities }
 *   - onUpdate():     called after a successful save (typically loadTasks())
 *   - apiBase:        base URL for save.php (e.g. '../api/tasks/')
 *   - onCreateSubtask(id) [optional]: dashboard wires this to open the detail
 *                     panel and focus the add-subtask input. Omit on pages
 *                     that don't have a detail panel — the menu hides the
 *                     "Create subtask" item when this is missing.
 *
 * Markup contract: a single .ctx-menu with id="ctxMenu" containing four
 * submenu containers (#ctxAnalyst / #ctxTeam / #ctxStatus / #ctxPriority).
 * The optional "Create subtask" item carries data-action="subtask".
 */
(function() {
    let cfg = null;
    let ctxTaskId = null;
    let wired = false;

    // The companies this analyst can reach. Fetched ONCE on init rather than
    // per right-click: the list does not change while somebody is dragging
    // cards around, and a fetch on every menu open would show an empty submenu
    // for the first frame every time.
    let moveCompanies = [];

    /**
     * Sets the page's menu configuration, loads the companies for "Move to company" and wires the
     * document listeners once.
     *
     * Hides "Create subtask" (and the separator before it) when the page gives no onCreateSubtask.
     */
    function init(config) {
        cfg = Object.assign({
            targetSelector: '.task-card',
            menuId: 'ctxMenu',
            apiBase: '',
            onUpdate: () => {},
            onCreateSubtask: null,
        }, config);

        loadMoveCompanies();

        if (!wired) {
            document.addEventListener('contextmenu', onDocCtx);
            document.addEventListener('click', closeCtx);
            document.addEventListener('scroll', closeCtx, true);
            window.addEventListener('resize', closeCtx);

            const menu = document.getElementById(cfg.menuId);
            if (menu) menu.addEventListener('click', onMenuClick);
            wired = true;
        }

        // Hide the Create-subtask item (and its preceding separator) on pages
        // that don't provide an onCreateSubtask callback.
        const subtaskItem = document.querySelector(`#${cfg.menuId} [data-action="subtask"]`);
        if (subtaskItem) {
            const show = !!cfg.onCreateSubtask;
            subtaskItem.style.display = show ? '' : 'none';
            const sep = subtaskItem.previousElementSibling;
            if (sep && sep.classList.contains('ctx-sep')) sep.style.display = show ? '' : 'none';
        }
    }

    /**
     * Same source as the tickets and changes "Move to company" submenus.
     * accessible=1 means the server decides what this analyst may see, so the
     * menu cannot offer a company the move would then refuse.
     *
     * A failure here leaves the list empty, which hides the menu item. That is
     * the right way round: an item that is there but cannot work is worse than
     * one that is not there.
     */
    async function loadMoveCompanies() {
        try {
            const res = await fetch('../api/system/get_tenants.php?accessible=1');
            const data = await res.json();
            moveCompanies = (data && data.success && data.companies) ? data.companies : [];
        } catch (e) {
            moveCompanies = [];
        }
    }

    /** Closes the menu. */
    function close() { closeCtx(); }
    /** Whether the menu is showing. */
    function isOpen() {
        const menu = cfg && document.getElementById(cfg.menuId);
        return !!menu && menu.style.display === 'block';
    }

    /** Opens the menu when a task element is right-clicked, and closes it on a right-click anywhere else. */
    function onDocCtx(e) {
        if (!cfg) return;
        const target = e.target.closest(cfg.targetSelector);
        if (target) openCtx(e, cfg.getTaskId(target));
        else closeCtx();
    }

    /**
     * Opens the menu for a task at the pointer, kept inside the window, with submenus flipped when
     * they would run off screen.
     */
    function openCtx(e, taskId) {
        e.preventDefault();
        const task = cfg.getTask(taskId);
        if (!task) return;
        ctxTaskId = taskId;
        buildSubmenus(task);

        const menu = document.getElementById(cfg.menuId);
        menu.style.display = 'block';
        const mw = menu.offsetWidth, mh = menu.offsetHeight;
        const x = Math.min(e.clientX, window.innerWidth - mw - 6);
        const y = Math.min(e.clientY, window.innerHeight - mh - 6);
        menu.style.left = Math.max(6, x) + 'px';
        menu.style.top  = Math.max(6, y) + 'px';
        menu.classList.toggle('flip-sub',   e.clientX + mw + 190 > window.innerWidth);
        menu.classList.toggle('flip-sub-v', e.clientY > window.innerHeight * 0.55);
    }

    /** Hides the menu and forgets the task it was opened on. */
    function closeCtx() {
        if (!cfg) return;
        const menu = document.getElementById(cfg.menuId);
        if (menu) menu.style.display = 'none';
        ctxTaskId = null;
    }

    /**
     * Fills the submenus for a task - analyst, team, status, company, priority and due-date shortcuts,
     * each with its current value ticked.
     *
     * Also sets the Complete / Reopen label and hides Log time, Repeat and Move to company where they
     * do not apply.
     */
    function buildSubmenus(task) {
        const lookups = cfg.getLookups();
        /** Translates a key under tasks.*. */
        const T = (k) => window.t('tasks.' + k);

        /** Returns one submenu option for a field and value, ticked when it is the task's current value. */
        const opt = (field, value, label, current, swatch) =>
            `<div class="ctx-sub-item${current ? ' current' : ''}" data-field="${field}" data-value="${escAttr(value)}">
                ${swatch || ''}<span class="ctx-sub-label">${esc(label)}</span>
                ${current ? '<span class="ctx-check">✓</span>' : ''}
            </div>`;
        /** Returns a colour swatch for a status or priority option. */
        const sw = c => `<span class="ctx-swatch" style="background:${escAttr(c || '#888')}"></span>`;

        const elA = document.getElementById('ctxAnalyst');
        if (elA) elA.innerHTML =
            opt('assigned_analyst_id', '', T('detail.unassigned'), !task.assigned_analyst_id) +
            (lookups.analysts || []).map(a =>
                opt('assigned_analyst_id', a.id, a.name, task.assigned_analyst_id == a.id)).join('');

        const elT = document.getElementById('ctxTeam');
        if (elT) elT.innerHTML =
            opt('assigned_team_id', '', T('detail.no_team'), !task.assigned_team_id) +
            (lookups.teams || []).map(tm =>
                opt('assigned_team_id', tm.id, tm.name, task.assigned_team_id == tm.id)).join('');

        const elS = document.getElementById('ctxStatus');
        if (elS) elS.innerHTML =
            (lookups.statuses || []).map(s =>
                opt('status', s.name, s.name, task.status === s.name, sw(s.colour))).join('')
            || `<div class="ctx-sub-empty">${esc(T('context.no_statuses'))}</div>`;

        // ── Move to company ───────────────────────────────────────────────
        // The parent row is hidden unless there is somewhere to move TO. One
        // company, or a failed lookup, means no item at all rather than an
        // item that opens onto nothing.
        const elC = document.getElementById('ctxCompany');
        const elCP = document.getElementById('ctxCompanyParent');
        if (elCP) {
            // A SUBTASK cannot be moved on its own - it belongs to whatever
            // company its parent is in. The service refuses it; hiding the
            // item here means nobody is offered something that will only come
            // back as an error they then have to interpret.
            const isSubtask = !!(task && task.parent_task_id);
            const canMove = moveCompanies.length > 1 && !isSubtask;
            elCP.style.display = canMove ? '' : 'none';
            if (canMove && elC) {
                const currentTid = (task && task.tenant_id != null) ? String(task.tenant_id) : null;
                elC.innerHTML = moveCompanies.map(co =>
                    opt('__company', co.id, co.name, currentTid !== null && String(co.id) === currentTid)
                ).join('');
            }
        }

        const elP = document.getElementById('ctxPriority');
        if (elP) elP.innerHTML =
            (lookups.priorities || []).map(p =>
                opt('priority', p.name, p.name, task.priority === p.name, sw(p.colour))).join('')
            || `<div class="ctx-sub-empty">${esc(T('context.no_priorities'))}</div>`;

        // ── Due date shortcuts ────────────────────────────────────────────
        // ⚠️ Built from LOCAL date parts, never toISOString(): a due date is a
        // bare date, and toISOString() converts to UTC first, so anyone west of
        // Greenwich would get yesterday. Same trap as GH #116, different field.
        const ymd = (d) => d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
        const today = new Date();
        const tomorrow = new Date(today); tomorrow.setDate(today.getDate() + 1);
        const nextMonday = new Date(today);
        // getDay(): 0 = Sunday. Always lands on the NEXT Monday, never today.
        nextMonday.setDate(today.getDate() + ((8 - today.getDay()) % 7 || 7));

        const elD = document.getElementById('ctxDue');
        if (elD) elD.innerHTML =
            opt('due_date', ymd(today), T('context.due_today'), task.due_date === ymd(today)) +
            opt('due_date', ymd(tomorrow), T('context.due_tomorrow'), task.due_date === ymd(tomorrow)) +
            opt('due_date', ymd(nextMonday), T('context.due_next_monday'), task.due_date === ymd(nextMonday)) +
            opt('due_date', '', T('context.due_clear'), !task.due_date);

        // ── Complete / reopen, and whether logging time is offered here ────
        const closed = !!task.status_is_closed;
        const lbl = document.getElementById('ctxCompleteLabel');
        if (lbl) lbl.textContent = T(closed ? 'context.reopen' : 'context.mark_complete');

        // Hidden rather than shown-and-refused when the administrator has
        // switched time off for this kind of task (GH #112).
        const lt = document.getElementById('ctxLogTime');
        if (lt) lt.style.display = (cfg.timeAllowedFor && cfg.timeAllowedFor(task)) ? '' : 'none';

        // Repeats (#94). Hidden on a subtask rather than shown and refused: the
        // series belongs to the piece of work, not a step inside it, and the API
        // rejects it too. The label says which way it will go, so a task that
        // already repeats offers to change it rather than to set it up again.
        // Involve me (GH #276). Only where the service would allow it: not on a task
        // you own (the owner is never also Involved) and not on a subtask (people are
        // involved in the piece of work, not a step inside it) - hidden rather than
        // offered and refused. Which way it goes comes from the task's own list of
        // people (list.php sends collaborators with every task).
        const inv = document.getElementById('ctxInvolve');
        if (inv) {
            const me = cfg.currentAnalystId ? parseInt(cfg.currentAnalystId, 10) : 0;
            const isOwner = me && task && parseInt(task.assigned_analyst_id, 10) === me;
            const show = !!(me && task && !task.parent_task_id && !isOwner);
            inv.style.display = show ? '' : 'none';
            const involved = show && (task.collaborators || []).some(c => parseInt(c.analyst_id, 10) === me);
            inv.dataset.involved = involved ? '1' : '0';
            const il = document.getElementById('ctxInvolveLabel');
            if (il) il.textContent = T(involved ? 'context.stop_involved' : 'context.involve_me');
        }

        const rp = document.getElementById('ctxRepeat');
        if (rp) {
            rp.style.display = task.parent_task_id ? 'none' : '';
            const rl = rp.querySelector('.ctx-item-label');
            if (rl) rl.textContent = T(task.recurrence_id ? 'context.change_repeat' : 'context.set_repeat');
        }
    }

    /**
     * Runs a menu choice - a submenu value is saved (or the task moved company), and the actions
     * (subtask, open, repeat, assign to me, complete / reopen, log time, copy link, delete) are
     * carried out or handed to the page.
     */
    function onMenuClick(e) {
        const subOpt = e.target.closest('.ctx-sub-item');
        if (subOpt) {
            const field = subOpt.dataset.field;
            let value = subOpt.dataset.value;
            // Moving company is NOT a field save. It has its own endpoint
            // because it is not one column - it moves the subtasks too, and it
            // refuses outright in cases an ordinary save has no opinion about.
            if (field === '__company') {
                moveToCompany(parseInt(value, 10));
                return;
            }
            if (field === 'assigned_analyst_id' || field === 'assigned_team_id') {
                value = value === '' ? null : parseInt(value, 10);
            }
            setField(field, value);
            return;
        }
        const id = ctxTaskId;
        const task = id && cfg.getTask ? cfg.getTask(id) : null;

        if (e.target.closest('[data-action="subtask"]')) {
            closeCtx();
            if (id && cfg.onCreateSubtask) cfg.onCreateSubtask(id);
            return;
        }
        if (e.target.closest('[data-action="open"]')) {
            closeCtx();
            if (id && cfg.onOpen) cfg.onOpen(id);
            return;
        }
        // Repeats (#94). Opens the task with its Repeats editor already open,
        // rather than duplicating the editor into the menu: every option lives
        // in one place, and the menu is the shortcut to it.
        if (e.target.closest('[data-action="repeat"]')) {
            closeCtx();
            if (id && cfg.onSetRepeat) cfg.onSetRepeat(id);
            return;
        }
        if (e.target.closest('[data-action="assign-me"]')) {
            // Bare identifier: ANALYST_ID is a const in tasks.js, so the config
            // hands it over rather than this file reaching for window.
            setField('assigned_analyst_id', cfg.currentAnalystId ? parseInt(cfg.currentAnalystId, 10) : null);
            return;
        }
        if (e.target.closest('[data-action="involve-me"]')) {
            const item = document.getElementById('ctxInvolve');
            setInvolved(item && item.dataset.involved === '1' ? 'remove' : 'add');
            return;
        }
        if (e.target.closest('[data-action="complete"]')) {
            // By the is_closed FLAG, never by the status's name — an installation
            // that renamed "Done" would otherwise never be able to finish a task.
            const statuses = (cfg.getLookups() || {}).statuses || [];
            const closed = task && task.status_is_closed;
            const target = closed
                ? (statuses.find(s => !s.is_closed && s.is_default) || statuses.find(s => !s.is_closed))
                : statuses.find(s => s.is_closed);
            if (!target) {
                showToast(window.t('tasks.context.no_status_for_that'), 'error');
                closeCtx();
                return;
            }
            setField('status', target.name);
            return;
        }
        if (e.target.closest('[data-action="logtime"]')) {
            closeCtx();
            if (id && cfg.onLogTime) cfg.onLogTime(id);
            return;
        }
        if (e.target.closest('[data-action="copylink"]')) {
            closeCtx();
            const base = (window.APP_BASE || '/');
            const url = location.origin + base + 'tasks/?task=' + id;
            // ⚠️ copyToClipboard(), never navigator.clipboard directly: that is
            // undefined outside a secure context and throws SYNCHRONOUSLY, so a
            // .catch() fallback never runs. The helper also reports honestly
            // whether the copy happened, so we never claim one we did not make.
            copyToClipboard(url).then(ok => {
                showToast(window.t(ok ? 'tasks.context.link_copied' : 'tasks.context.link_copy_failed'),
                          ok ? 'success' : 'error');
            });
            return;
        }
        if (e.target.closest('[data-action="delete"]')) {
            closeCtx();
            if (id && cfg.onDelete) cfg.onDelete(id, task);
            return;
        }
    }

    /**
     * Move the task - and everything beneath it - to another company.
     *
     * Every rule lives in TasksService::moveTaskToCompany, so this reports
     * what the server says rather than second-guessing it. The message is
     * shown as the server phrased it: it is the only party that knows how
     * many subtasks went along, or which link stood in the way.
     */
    async function moveToCompany(tenantId) {
        const id = ctxTaskId;
        closeCtx();
        if (!id || !tenantId) return;
        try {
            const res = await fetch(cfg.apiBase + 'move_to_company.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ task_id: id, tenant_id: tenantId })
            });
            const data = await res.json();
            if (!data.success) {
                if (window.showToast) window.showToast(data.error || window.t('tasks.context.move_error'), 'error');
                return;
            }
            if (window.showToast) window.showToast(data.message || window.t('tasks.context.moved'), 'success');
            cfg.onUpdate();
        } catch (e) {
            if (window.showToast) window.showToast(window.t('tasks.context.move_error'), 'error');
        }
    }

    /**
     * Puts the current analyst on the menu's task, or takes them off it (GH #276), through the same
     * endpoint the detail panel's Involved list uses - TasksService holds every rule (owner, subtask,
     * company scope), so a refusal is shown as the server words it.
     */
    async function setInvolved(action) {
        const id = ctxTaskId;
        closeCtx();
        const me = cfg.currentAnalystId ? parseInt(cfg.currentAnalystId, 10) : 0;
        if (!id || !me) return;
        try {
            const data = await fetch(cfg.apiBase + 'collaborators.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ task_id: id, analyst_id: me, action })
            }).then(r => r.json());
            if (data.success) {
                if (typeof showToast === 'function') {
                    showToast(window.t(action === 'add' ? 'tasks.context.involved_now' : 'tasks.context.no_longer_involved'), 'success');
                }
                if (cfg.onUpdate) cfg.onUpdate();
            } else if (typeof showToast === 'function') {
                showToast(data.error || window.t('tasks.toast.update_failed'), 'error');
            }
        } catch (e) {
            if (typeof showToast === 'function') showToast(window.t('tasks.toast.update_failed'), 'error');
        }
    }

    /** Saves one field of the menu's task through save.php, then toasts and calls onUpdate. */
    async function setField(field, value) {
        const id = ctxTaskId;
        closeCtx();
        if (!id) return;
        try {
            const data = await fetch(cfg.apiBase + 'save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, [field]: value })
            }).then(r => r.json());
            if (data.success) {
                if (typeof showToast === 'function') {
                    showToast(window.t('tasks.toast.task_updated'), 'success');
                }
                if (cfg.onUpdate) cfg.onUpdate();
            } else if (typeof showToast === 'function') {
                showToast(window.t('tasks.toast.error_prefix', {
                    message: data.error || window.t('tasks.toast.update_failed')
                }), 'error');
            }
        } catch (e) {
            if (typeof showToast === 'function') {
                showToast(window.t('tasks.toast.update_failed'), 'error');
            }
        }
    }

    // ── Local helpers (mirrors of esc/escAttr in tasks.js + tasks-timeline.js) ──
    function esc(text) {
        if (text == null) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    /** Escapes a value for use inside a double-quoted HTML attribute. */
    function escAttr(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    window.TasksCtxMenu = { init, close, isOpen };
})();
