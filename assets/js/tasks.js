/**
 * FreeITSM Tasks Module — Board, List, Detail Panel, Drag & Drop
 *
 * @wiki Task-Board-and-Subtasks-Developer-Guide
 */

// ── State ──────────────────────────────────────────────────────────

// The board/list choice and the my/all choice are this analyst's saved
// preference (GH #131), handed over by tasks/index.php rather than fetched, so
// the page is already painted the right way round before this file runs. The
// fallbacks are the historical defaults, for a page served before the
// preference existed.
let currentFilter = window.TASK_FILTER === 'all' ? 'all' : 'my';
let currentFilterTeamId = null;
let currentFilterAnalystId = null;
let currentView = window.TASK_VIEW === 'list' ? 'list' : 'board';
let tasks = [];
let analysts = [];
let teams = [];
let selectedTaskId = null;
let taskDetailGeneration = 0;
let sortField = 'board_position';
let sortDir = 'asc';
let tinyEditor = null;
let descSaveTimer = null;
let searchQuery = '';
let searchTerms = [];

// Which extras appear on board cards — overridden by Settings → Card
let cardFields = {
    priority: 1, assignee: 1, team: 0, start_date: 0,
    due_date: 1, description: 0, subtasks: 1, links: 1
};

// Where scheduled work and time entries appear — overridden by Settings → Time.
// Defaults to everywhere, matching the server (GH #112).
let timeScope = 'both';

// Tags — full list, display settings, the active sidebar filter, and the
// working set while a task is open in the detail panel
let tagList = [];
let tagSettings = {
    allow_create: 0, surface_card: 1, surface_filter: 1,
    surface_search: 1, surface_calendar: 0
};
let currentTagFilter = '';
// Projects (3.2.0): the sidebar Project filter, and the live projects the task
// window offers (only fetched for analysts who can open Projects).
let currentProjectFilter = '';
let projectChoices = null;
let contractorChoices = null;      // 3.3.0: suppliers and their contacts, once per page (null = not loaded, false = may not choose)
let currentContractorFilter = '';
let detailTags = [];
// What the open task is linked to, so replacing a link can ask first (Ed).
let detailLinks = { ticket_id: null, ticket_label: '', change_id: null, change_label: '' };
const TAG_PALETTE = ['#dc2626', '#ea580c', '#d97706', '#16a34a',
                     '#0891b2', '#2563eb', '#7c3aed', '#db2777'];

const ANALYST_ID = document.body.dataset.analystId;

// Locale for date formatting — matches the page's i18n locale

// ── Init ───────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', async () => {
    await loadCardSettings();
    await loadLookups();          // statuses drive the board columns
    if (window.TASK_HOST === 'ticket') {
        await loadDropdowns();
        await openDetailPanel(window.TASK_EMBED_CONTEXT.taskId);
        return;
    }
    buildBoardColumns();
    applyTagSettings();
    // Open a task straight away if linked from the calendar/timeline (?task=N)
    loadDropdowns().then(() => {
        const taskParam = new URLSearchParams(location.search).get('task');
        if (taskParam) openDetailPanel(parseInt(taskParam, 10));
        // ?new=1: New in the Calendar, Table or Timeline action row lands here (#185).
        else if (new URLSearchParams(location.search).get('new') === '1') openNewTask();
    });
    loadTasks();
    TasksCtxMenu.init({
        targetSelector: '.task-card',
        getTaskId: el => parseInt(el.dataset.id, 10),
        getTask:   id => tasks.find(t => t.id === id),
        getLookups: () => ({
            analysts, teams,
            statuses:   statusList,
            priorities: priorityList,
        }),
        // ANALYST_ID is a `const` in this file and therefore NOT on window, so it
        // is handed over rather than reached for.
        currentAnalystId: ANALYST_ID,
        timeAllowedFor,
        onOpen:    id => openDetailPanel(id),
        onLogTime: id => openTaskAndFocusTime(id),
        onDelete:  (id, task) => deleteTaskById(id, task),
        onUpdate: () => loadTasks(),
        apiBase: API_BASE,
        // Open the task and drop the cursor straight into the Add-subtask box
        onCreateSubtask: id => openDetailPanel(id).then(() => {
            const input = document.getElementById('newSubtaskInput');
            if (input) { input.scrollIntoView({ block: 'center' }); input.focus(); }
        }),
        // Open the task with its Repeats editor already showing (#94), so the
        // menu is a shortcut to the one editor rather than a second copy of it.
        onSetRepeat: id => openDetailPanel(id).then(() => {
            const ed = document.getElementById('recurEditor');
            if (ed && ed.style.display === 'none') toggleRecurrenceEditor();
            const sec = document.getElementById('recurSection');
            if (sec) sec.scrollIntoView({ block: 'center' });
        }),
    });
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (TasksCtxMenu.isOpen()) TasksCtxMenu.close();
        else closeDetailPanel();
    });
});

/**
 * Loads the Tasks settings and takes the card fields, tag settings and time scope from them, keeping
 * the built-in defaults for anything missing.
 */
async function loadCardSettings() {
    try {
        const data = await fetch(API_BASE + 'get_settings.php').then(r => r.json());
        if (data.success && data.settings.card_fields) {
            cardFields = data.settings.card_fields;
        }
        if (data.success && data.settings.tag_settings) {
            tagSettings = data.settings.tag_settings;
        }
        if (data.success && data.settings.time_scope) {
            timeScope = data.settings.time_scope;
        }
    } catch (e) { console.error('Failed to load card settings:', e); }
}

// Where the time features are offered: 'both', 'tasks', 'subtasks' or 'off'
// (Settings → Time). A top-level task and a subtask are the same record told
// apart by parent_task_id, so this is the only thing that distinguishes them.
//
// ⚠️ This is the DISPLAY half only. The server enforces the same rule on write,
// so a stale tab cannot log time the setting has since disallowed.
function timeAllowedFor(task) {
    if (timeScope === 'off')  return false;
    if (timeScope === 'both') return true;
    const isSubtask = !!task.parent_task_id;
    return timeScope === 'subtasks' ? isSubtask : !isSubtask;
}

// Minutes as people say them: 90 -> "1h 30m". Matches the ticket time panel.
function formatMinutes(mins) {
    mins = Math.max(0, parseInt(mins, 10) || 0);
    if (mins < 60) return mins + 'm';
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return m ? `${h}h ${m}m` : `${h}h`;
}

// ── Data Loading ───────────────────────────────────────────────────

/** Fetches the analysts and teams and fills the sidebar's analyst and team filter dropdowns with them. */
async function loadDropdowns() {
    try {
        const [aRes, tRes] = await Promise.all([
            fetch(API_BASE + 'list.php?analysts=1').then(r => r.json()),
            fetch(API_BASE + 'list.php?teams=1').then(r => r.json())
        ]);
        if (aRes.success) {
            analysts = aRes.analysts;
            const sel = document.getElementById('analystFilter');
            if (sel) sel.innerHTML = '<option value="">' + esc(window.t('tasks.filter.all_analysts')) + '</option>' +
                analysts.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
        }
        if (tRes.success) {
            teams = tRes.teams;
            const sel = document.getElementById('teamFilter');
            if (sel) sel.innerHTML = '<option value="">' + esc(window.t('tasks.filter.all_teams')) + '</option>' +
                teams.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
        }
    } catch (e) { console.error('Failed to load dropdowns:', e); }
}

/**
 * Fetches the tasks for the current filter (my, all, a team or an analyst), builds each one's search
 * text, refreshes the Project and Contractor filters and redraws the board or list.
 */
async function loadTasks() {
    if (window.TASK_HOST === 'ticket') {
        window.TaskEmbedBridge?.changed();
        return;
    }
    let url = API_BASE + 'list.php?filter=' + currentFilter;
    if (currentFilter === 'team' && currentFilterTeamId) url += '&team_id=' + currentFilterTeamId;
    if (currentFilter === 'analyst' && currentFilterAnalystId) url += '&analyst_id=' + currentFilterAnalystId;

    try {
        const data = await fetch(url).then(r => r.json());
        if (data.success) {
            tasks = data.tasks;
            tasks.forEach(t => t._search = buildSearchText(t));
            refreshProjectFilter();
            refreshContractorFilter();
            if (currentView === 'board') renderBoard();
            else renderList();
        }
    } catch (e) { console.error('Failed to load tasks:', e); }
}

// ── Filters ────────────────────────────────────────────────────────

/**
 * Switches to one of the filter buttons (my, all, ...), clears the team and analyst dropdowns and
 * reloads the tasks.
 *
 * Only the "my" and "all" choices are saved as the analyst's preference.
 */
function setFilter(filter) {
    currentFilter = filter;
    currentFilterTeamId = null;
    currentFilterAnalystId = null;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    const btn = document.querySelector(`.filter-btn[data-filter="${filter}"]`);
    if (btn) btn.classList.add('active');
    document.getElementById('teamFilter').value = '';
    document.getElementById('analystFilter').value = '';
    loadTasks();
    // Only the two coarse choices are remembered (GH #131). 'team' and
    // 'analyst' reach currentFilter through the two dropdowns below and are
    // deliberately left out: a stored analyst id can outlive the analyst, and
    // coming back tomorrow to somebody else's tasks — or to an empty board —
    // reads as data loss rather than as a filter.
    if (filter === 'my' || filter === 'all') saveTaskPreference('tasks_filter', filter);
}

/**
 * Shows one team's tasks, or falls back to "my" when the team dropdown is cleared while it was the
 * active filter.
 */
function setTeamFilter(teamId) {
    if (!teamId) { setFilter(currentFilter === 'team' ? 'my' : currentFilter); return; }
    currentFilter = 'team';
    currentFilterTeamId = teamId;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('analystFilter').value = '';
    loadTasks();
}

/**
 * Shows one analyst's tasks, or falls back to "my" when the analyst dropdown is cleared while it was
 * the active filter.
 */
function setAnalystFilter(analystId) {
    if (!analystId) { setFilter(currentFilter === 'analyst' ? 'my' : currentFilter); return; }
    currentFilter = 'analyst';
    currentFilterAnalystId = analystId;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('teamFilter').value = '';
    loadTasks();
}

// ── Search ─────────────────────────────────────────────────────────

// Lowercased haystack of a task's title + plain-text description,
// pre-computed once per load so as-you-type filtering stays cheap
function buildSearchText(t) {
    let text = t.title || '';
    if (t.description) {
        const doc = new DOMParser().parseFromString(t.description, 'text/html');
        text += ' ' + (doc.body.textContent || '');
    }
    if (tagSettings.surface_search && t.tags) {
        text += ' ' + t.tags.map(tg => tg.name).join(' ');
    }
    return text.toLowerCase();
}

// ── Hide done, and the subtasks under each card (GH #184) ──────────

let hideDone = !!window.TASK_HIDE_DONE;
let boardSubtasks = ['all', 'open', 'none'].includes(window.TASK_BOARD_SUBTASKS) ? window.TASK_BOARD_SUBTASKS : 'all';
/** card (task) id => 'show' | 'hide': this analyst's own choice for one card, over boardSubtasks. */
let subtaskCards = Object.assign({}, window.TASK_SUBTASK_CARDS || {});

/** True unless Hide done is on and the task is done. */
function taskMatchesDone(t) {
    return !hideDone || !taskIsDone(t);
}

/** Hide done on or off: redraws (the status board also drops its done columns) and remembers it. */
function setHideDone(on) {
    hideDone = !!on;
    saveTaskPreference('tasks_hide_done', hideDone ? 'on' : '');
    if (currentView === 'board') { buildBoardColumns(); renderBoard(); }
    else renderList();
}

/** All, open only or no subtasks under the cards; remembered. Each card's own choice still wins. */
function setBoardSubtasks(mode) {
    boardSubtasks = ['all', 'open', 'none'].includes(mode) ? mode : 'all';
    document.querySelectorAll('#boardSubtasksToggle .view-btn')
        .forEach(b => b.classList.toggle('active', b.dataset.subtasks === boardSubtasks));
    saveTaskPreference('tasks_board_subtasks', boardSubtasks);
    renderBoard();
}

/** The subtasks a card lists under it by the board-wide choice alone. */
function defaultCardSubtasks(items) {
    if (boardSubtasks === 'none') return [];
    if (boardSubtasks === 'open') return items.filter(s => !taskIsDone(s));
    return items;
}

/**
 * The subtasks a card lists under it: its own choice if it has one ('show' =
 * every subtask, 'hide' = none), otherwise the board-wide one.
 */
function cardSubtasks(t) {
    const items = t.subtask_items || [];
    const own = subtaskCards[t.id];
    if (own === 'show') return items;
    if (own === 'hide') return [];
    return defaultCardSubtasks(items);
}

/**
 * Opens or closes one card's subtask list (its "1/4" line), and remembers that
 * card. A choice that matches what the board would show anyway is forgotten, so
 * the remembered list only holds the cards that are different.
 */
function toggleCardSubtasks(event, taskId) {
    event.stopPropagation();
    const t = tasks.find(x => String(x.id) === String(taskId));
    if (!t) return;
    const showing = cardSubtasks(t).length > 0;
    const want = showing ? 'hide' : 'show';
    const byDefault = defaultCardSubtasks(t.subtask_items || []).length > 0 ? 'show' : 'hide';
    if (want === byDefault) delete subtaskCards[t.id]; else subtaskCards[t.id] = want;
    // Bounded: the 300 most recent choices are plenty, and a stale id costs nothing.
    const keys = Object.keys(subtaskCards);
    if (keys.length > 300) keys.slice(0, keys.length - 300).forEach(k => delete subtaskCards[k]);
    saveTaskPreference('tasks_subtask_cards', JSON.stringify(subtaskCards));
    renderBoard();
}

/** True when every search term appears in the task's pre-built search text (always true with no search). */
function taskMatchesSearch(t) {
    if (searchTerms.length === 0) return true;
    const hay = t._search || '';
    return searchTerms.every(term => hay.includes(term));
}

// Filters the board/list as you type — no server round-trip
function setSearch(value) {
    searchQuery = value;
    searchTerms = value.toLowerCase().trim().split(/\s+/).filter(Boolean);
    document.getElementById('searchClear').style.display = value ? 'flex' : 'none';
    if (currentView === 'board') renderBoard();
    else renderList();
}

/** Empties the search box and redraws without a search. */
function clearSearch() {
    document.getElementById('taskSearch').value = '';
    setSearch('');
}

// ── Tag filter ─────────────────────────────────────────────────────

/** True when the task carries the tag chosen in the sidebar Tag filter (always true with no tag chosen). */
function taskMatchesTag(t) {
    if (!currentTagFilter) return true;
    return (t.tags || []).some(tg => String(tg.id) === String(currentTagFilter));
}

/** Sets the sidebar Tag filter and redraws the board or list. */
function setTagFilter(tagId) {
    currentTagFilter = tagId;
    if (currentView === 'board') renderBoard();
    else renderList();
}

// ── Project filter (3.2.0) ───────────────────────────────────────────

/**
 * True when the task matches the sidebar Project filter - any project, "none" (no project), or one
 * project id.
 */
function taskMatchesProject(t) {
    if (!currentProjectFilter) return true;
    if (currentProjectFilter === 'none') return !t.project_id;
    return String(t.project_id || '') === String(currentProjectFilter);
}

/** Sets the sidebar Project filter and redraws the board or list. */
function setProjectFilter(v) {
    currentProjectFilter = v;
    if (currentView === 'board') renderBoard();
    else renderList();
}

/**
 * The Project filter lists the projects the loaded tasks belong to - nothing to
 * fetch, and never a project with no tasks here to show. Hidden when no task on
 * the board belongs to a project, so an install that does not use Projects
 * sees no change at all.
 */
function refreshProjectFilter() {
    const section = document.getElementById('projectFilterSection');
    const sel = document.getElementById('projectFilter');
    if (!section || !sel) return;
    const seen = new Map();
    tasks.forEach(t => { if (t.project_id && !seen.has(t.project_id)) seen.set(t.project_id, t.project_name); });
    section.style.display = seen.size ? '' : 'none';
    if (!seen.size && currentProjectFilter) currentProjectFilter = '';
    const keep = currentProjectFilter;
    sel.innerHTML = '<option value="">' + esc(window.t('tasks.filter.all_projects')) + '</option>'
        + '<option value="none">' + esc(window.t('tasks.filter.no_project')) + '</option>'
        + Array.from(seen.entries()).sort((a, b) => String(a[1]).localeCompare(String(b[1])))
            .map(([id, name]) => `<option value="${id}">${esc(name)}</option>`).join('');
    sel.value = keep;
}

// ── Contractor filter (3.3.0) ─────────────────────────────────────
// The same shape as the Project filter: built from the loaded tasks, hidden when none has a contractor.

/**
 * True when the task matches the sidebar Contractor filter - all, "any" (has a contractor), "none", or
 * one supplier id.
 */
function taskMatchesContractor(t) {
    if (!currentContractorFilter) return true;
    if (currentContractorFilter === 'none') return !t.supplier_id;
    if (currentContractorFilter === 'any') return !!t.supplier_id;
    return String(t.supplier_id || '') === String(currentContractorFilter);
}

/** Sets the sidebar Contractor filter and redraws the board or list. */
function setContractorFilter(v) {
    currentContractorFilter = v;
    if (currentView === 'board') renderBoard();
    else renderList();
}

/**
 * Rebuilds the Contractor filter from the suppliers on the loaded tasks, keeping the current choice,
 * and hides the section when no task has a contractor.
 */
function refreshContractorFilter() {
    const section = document.getElementById('contractorFilterSection');
    const sel = document.getElementById('contractorFilter');
    if (!section || !sel) return;
    const seen = new Map();
    tasks.forEach(t => { if (t.supplier_id && !seen.has(t.supplier_id)) seen.set(t.supplier_id, t.supplier_name); });
    section.style.display = seen.size ? '' : 'none';
    if (!seen.size && currentContractorFilter) currentContractorFilter = '';
    const keep = currentContractorFilter;
    sel.innerHTML = '<option value="">' + esc(window.t('tasks.filter.all_contractors')) + '</option>'
        + '<option value="any">' + esc(window.t('tasks.filter.any_contractor')) + '</option>'
        + '<option value="none">' + esc(window.t('tasks.filter.no_contractor')) + '</option>'
        + Array.from(seen.entries()).sort((a, b) => String(a[1]).localeCompare(String(b[1])))
            .map(([id, name]) => `<option value="${id}">${esc(name)}</option>`).join('');
    sel.value = keep;
}

// Populate the sidebar tag filter and show/hide it per the surface setting
function applyTagSettings() {
    const section = document.getElementById('tagFilterSection');
    if (!section) return;
    section.style.display = tagSettings.surface_filter ? '' : 'none';
    const sel = document.getElementById('tagFilter');
    if (sel) {
        const keep = sel.value;
        sel.innerHTML = '<option value="">' + esc(window.t('tasks.filter.all_tags')) + '</option>' +
            tagList.map(tg => `<option value="${tg.id}">${esc(tg.name)}</option>`).join('');
        sel.value = keep;
    }
}

// ── View Toggle ────────────────────────────────────────────────────

// Remember a board preference for this analyst (GH #131).
//
// Fire-and-forget, and silent on failure, matching the two settings on the
// tasks calendar: the board has already redrawn the way you asked, so a failed
// save costs you the choice NEXT time and nothing now. Interrupting somebody
// with a toast because a view preference did not persist would be worse than
// quietly showing them the default tomorrow. (The detail-panel switch one
// screen away DOES toast, deliberately — moving a task into the large window
// is a considered choice you would want to know had not stuck.)
function saveTaskPreference(key, value) {
    fetch(APP_BASE + 'api/system/set_user_preference.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: key, value: value })
    }).catch(() => {});
}

/**
 * Switches between the board and the list, shows the board-grouping toggle only on the board, redraws
 * and saves the choice as the analyst's preference.
 */
function switchView(view) {
    currentView = view;
    // Only the Board/List buttons: '.view-btn' alone also caught the Status/Analyst
    // and subtask switches below, and left them with nothing highlighted.
    document.querySelectorAll('.view-btn[data-view]').forEach(b => b.classList.toggle('active', b.dataset.view === view));
    document.getElementById('boardView').style.display = view === 'board' ? 'flex' : 'none';
    document.getElementById('listView').style.display = view === 'list' ? 'block' : 'none';
    const groupToggle = document.getElementById('boardGroupSection');
    if (groupToggle) groupToggle.style.display = view === 'board' ? '' : 'none';
    const subtasksToggle = document.getElementById('boardSubtasksSection');
    if (subtasksToggle) subtasksToggle.style.display = view === 'board' ? '' : 'none';
    const sortToggle = document.getElementById('boardSortSection');
    if (sortToggle) sortToggle.style.display = view === 'board' ? '' : 'none';
    if (view === 'board') renderBoard();
    else renderList();
    saveTaskPreference('tasks_view', view);
}

// ── Board Rendering ────────────────────────────────────────────────

// Fallback if the statuses API is unreachable
const DEFAULT_STATUSES = [
    { name: 'To Do',       colour: '#6b7280' },
    { name: 'In Progress', colour: '#9333ea' },
    { name: 'Done',        colour: '#16a34a' }
];

// Build one board column per status — run once after statuses load
function buildBoardColumns() {
    // By analyst, renderBoardByAnalyst() builds the columns itself on every draw.
    if (boardGroup === 'analyst') return;
    const board = document.getElementById('boardView');
    // Hide done (GH #184): a done column would only ever be empty.
    const cols = (statusList.length ? statusList : DEFAULT_STATUSES).filter(s => !(hideDone && Number(s.is_closed)));
    board.innerHTML = '';
    cols.forEach(s => {
        const col = document.createElement('div');
        col.className = 'board-column';
        col.dataset.status = s.name;
        col.innerHTML = `
            <div class="board-column-header">
                <span class="column-status-dot" style="background:${escAttr(s.colour || '#6b7280')}"></span>
                <span class="column-title">${esc(s.name)}</span>
                <span class="column-count">0</span>
                <button class="column-add-btn" title="${escAttr(window.t('tasks.board.add_task'))}">+</button>
            </div>
            <div class="quick-add-container" style="display:none;">
                <input type="text" class="quick-add-input" placeholder="${escAttr(window.t('tasks.board.quick_add_placeholder'))}">
            </div>
            <div class="board-cards"></div>`;
        col.querySelector('.column-add-btn').addEventListener('click', () => showQuickAdd(col));
        col.querySelector('.quick-add-input')
           .addEventListener('keydown', e => handleQuickAdd(e, s.name, col));
        col.querySelector('.board-column-header')
           .addEventListener('mousedown', e => startColumnDrag(e, col));
        board.appendChild(col);
    });
}

// ── Column drag-to-reorder ─────────────────────────────────────────

/**
 * Starts dragging a status column by its header to reorder the board. Ignores right clicks and the +
 * button, and only counts as a drag once the mouse has moved 5px.
 */
function startColumnDrag(e, column) {
    // Left-button only; the + button is not a drag handle
    if (e.button !== 0 || e.target.closest('.column-add-btn')) return;
    if (document.body.classList.contains('module-read-only')) return;   // #181 Part G
    const board = document.getElementById('boardView');
    const startX = e.clientX;
    let dragging = false;

    /**
     * Once the mouse has moved 5px, marks the column as dragging and slots it before the first column
     * whose middle the cursor is left of.
     */
    const onMove = (e2) => {
        if (!dragging) {
            if (Math.abs(e2.clientX - startX) < 5) return;
            dragging = true;
            column.classList.add('col-dragging');
        }
        // Slot the dragged column before the first column the cursor is left of
        const others = [...board.querySelectorAll('.board-column:not(.col-dragging)')];
        let placed = false;
        for (const other of others) {
            const r = other.getBoundingClientRect();
            if (e2.clientX < r.left + r.width / 2) {
                board.insertBefore(column, other);
                placed = true;
                break;
            }
        }
        if (!placed) board.appendChild(column);
    };

    /** Removes the drag listeners and, if the column was actually dragged, saves the new column order. */
    const onUp = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        if (dragging) {
            column.classList.remove('col-dragging');
            persistColumnOrder();
        }
    };

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
    e.preventDefault();
}

/**
 * Saves the board's column order as the task status order, after re-sorting the local status list to
 * match so menus and dropdowns follow.
 */
function persistColumnOrder() {
    const order = [...document.querySelectorAll('#boardView .board-column')]
        .map(col => {
            const s = statusList.find(x => x.name === col.dataset.status);
            return s ? s.id : null;
        })
        .filter(id => id !== null);
    if (!order.length) return;
    // Keep the local status list in step so menus / dropdowns follow suit
    statusList.sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id));
    fetch(API_BASE + 'reorder_task_statuses.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order })
    }).then(r => r.json()).then(d => {
        showToast(d.success ? window.t('tasks.toast.order_saved')
            : window.t('tasks.toast.error_prefix', { message: d.error || window.t('tasks.toast.order_failed') }));
    }).catch(() => showToast(window.t('tasks.toast.order_failed'), 'error'));
}

// ── Board columns: by status (default) or by analyst (2.10.0) ──────
//
// No schema change: the choice is a user_preferences row (tasks_board_group),
// like the board/list switch. By analyst, the columns are worked out from the
// tasks in view each time the board draws, because who has work changes as
// tasks are filtered, searched and reassigned; dropping a card on another
// analyst's column reassigns it through the ordinary save, so every rule that
// applies to reassigning (analyst exists, notifications, workflow events)
// applies here too.
let boardGroup = window.TASK_BOARD_GROUP === 'analyst' ? 'analyst' : 'status';

/**
 * Switches the board between columns by status and columns by analyst, saves the choice as the
 * analyst's preference and redraws.
 */
function switchBoardGroup(group) {
    boardGroup = group === 'analyst' ? 'analyst' : 'status';
    document.querySelectorAll('#boardGroupToggle .view-btn')
        .forEach(b => b.classList.toggle('active', b.dataset.group === boardGroup));
    saveTaskPreference('tasks_board_group', boardGroup);
    buildBoardColumns();
    renderBoard();
}

// ── Card order: manual or by due date (GH #191) ────────────────────
//
// A personal choice (user_preferences tasks_board_sort). Due date orders the
// DISPLAY only: board_position - the manual order everybody shares - is never
// rewritten, so switching back to Manual restores it exactly. Applied in both
// renderers, so it holds after loading, filtering, searching and any save that
// reloads the board (a due-date change included).
let boardSort = window.TASK_BOARD_SORT === 'due' ? 'due' : 'manual';

/** Switches the card order, remembers it, and redraws. */
function setBoardSort(mode) {
    boardSort = mode === 'due' ? 'due' : 'manual';
    document.querySelectorAll('#boardSortToggle .view-btn')
        .forEach(b => b.classList.toggle('active', b.dataset.sort === boardSort));
    const hint = document.getElementById('boardSortHint');
    if (hint) hint.style.display = boardSort === 'due' ? '' : 'none';
    saveTaskPreference('tasks_board_sort', boardSort);
    renderBoard();
}

/** Today as YYYY-MM-DD in the viewer's own calendar - the same day formatDueBadge() calls "today". */
function boardTodayYmd() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

/**
 * Where a task falls in due-date order: open work by date (overdue, then due today, then future -
 * which is simply ascending date), then open work with no due date, then done work (which is never
 * "overdue") in the same shape. A due date is a bare calendar day, compared as text.
 */
function boardDueRank(t) {
    const done = taskIsDone(t) ? 1 : 0;
    const due = t.due_date ? String(t.due_date).slice(0, 10) : '';
    return [done, due ? 0 : 1, due];
}

/**
 * Sorts one column's tasks in place by due date when that order is chosen, keeping the order they
 * arrived in for equal dates - so ties fall back to the manual order, stably.
 *
 * @param tiebreak  optional comparator applied after the due date (the analyst board's status order)
 */
function applyBoardSort(list, tiebreak) {
    if (boardSort !== 'due') return list;
    const index = new Map(list.map((t, i) => [t, i]));
    return list.sort((a, b) => {
        const ra = boardDueRank(a), rb = boardDueRank(b);
        if (ra[0] !== rb[0]) return ra[0] - rb[0];
        if (ra[1] !== rb[1]) return ra[1] - rb[1];
        if (ra[2] !== rb[2]) return ra[2] < rb[2] ? -1 : 1;
        return (tiebreak ? tiebreak(a, b) : 0) || (index.get(a) - index.get(b));
    });
}

/**
 * Draws the board as one column per analyst with a task in view - the viewer's own column first and
 * always present, then by name, with Unassigned last.
 *
 * Cards within a column are sorted by status order and then board position.
 */
function renderBoardByAnalyst() {
    const board = document.getElementById('boardView');
    const visible = tasks.filter(t => taskMatchesSearch(t) && taskMatchesTag(t) && taskMatchesProject(t) && taskMatchesContractor(t) && taskMatchesDone(t));

    // One column per analyst who has a task in view, yours first, then by name;
    // Unassigned last. Your own column is always there, so you can drag work
    // to yourself even when you have none yet.
    const groups = new Map();
    const me = String(ANALYST_ID || '');
    if (me) groups.set(me, { id: me, name: ((analysts.find(a => String(a.id) === me) || {}).name) || '', tasks: [] });
    visible.forEach(t => {
        const id = t.assigned_analyst_id ? String(t.assigned_analyst_id) : '';
        if (!groups.has(id)) {
            const a = analysts.find(x => String(x.id) === id);
            // A task can point at an analyst who no longer exists (deleted, or removed
            // with old demo data); say so rather than show a bare number.
            groups.set(id, { id, name: id ? ((a && a.name) || t.analyst_name || window.t('tasks.board.former_analyst', { id })) : '', tasks: [] });
        }
        groups.get(id).tasks.push(t);
    });
    const unassigned = groups.get('') || { id: '', name: '', tasks: [] };
    groups.delete('');
    const cols = [...groups.values()].sort((a, b) =>
        (a.id === me ? -1 : b.id === me ? 1 : a.name.localeCompare(b.name)));
    cols.push(unassigned);

    // Within a column, in status order (the board's own column order), then
    // the board position each task already has.
    const statusRank = name => {
        const i = (statusList || []).findIndex(s => s.name === name);
        return i < 0 ? 999 : i;
    };

    board.innerHTML = '';
    cols.forEach(g => {
        g.tasks.sort((a, b) => statusRank(a.status) - statusRank(b.status)
            || (a.board_position || 0) - (b.board_position || 0));
        // GH #191: by due date the date decides, and status order only breaks a tie -
        // the card's status chip still says where it stands.
        applyBoardSort(g.tasks, (a, b) => statusRank(a.status) - statusRank(b.status));
        const initials = g.id ? g.name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase() : '–';
        const col = document.createElement('div');
        col.className = 'board-column board-column--analyst';
        col.dataset.analystId = g.id;
        col.innerHTML = `
            <div class="board-column-header">
                <span class="column-analyst-badge">${esc(initials)}</span>
                <span class="column-title">${esc(g.id ? g.name : window.t('tasks.board.unassigned'))}</span>
                <span class="column-count">${g.tasks.length}</span>
            </div>
            <div class="board-cards">${g.tasks.length
                ? g.tasks.map(renderCard).join('')
                : '<div class="board-empty">' + esc(window.t('tasks.board.no_tasks')) + '</div>'}</div>`;
        col.querySelectorAll('.task-card').forEach(card => {
            card.addEventListener('mousedown', e => startDrag(e, card));
        });
        board.appendChild(col);
    });
}

/** Drop a card on another analyst's column: reassign it. Returns true if handled. */
async function dropOnAnalystColumn(taskId, targetColumn) {
    const task = tasks.find(t => t.id == taskId);
    const from = task && task.assigned_analyst_id ? String(task.assigned_analyst_id) : '';
    const to = targetColumn.dataset.analystId || '';
    if (from === to) { renderBoard(); return true; }   // same column: nothing to change
    const ok = await postTaskChange({ id: taskId, assigned_analyst_id: to || null }, 'tasks.board.reassign_failed');
    if (ok) {
        const name = to ? ((analysts.find(a => String(a.id) === to) || {}).name || '') : window.t('tasks.board.unassigned');
        showToast(window.t('tasks.board.reassigned', { name }), 'success');
    }
    loadTasks();
    return true;
}

/**
 * Draws the board: hands over to renderBoardByAnalyst() when grouped by analyst, otherwise fills each
 * status column with its filtered cards and count.
 */
function renderBoard() {
    if (boardGroup === 'analyst') { renderBoardByAnalyst(); return; }
    document.querySelectorAll('#boardView .board-column').forEach(col => {
        const status = col.dataset.status;
        const cardsEl = col.querySelector('.board-cards');
        const countEl = col.querySelector('.column-count');
        const filtered = applyBoardSort(tasks.filter(t =>
            t.status === status && taskMatchesSearch(t) && taskMatchesTag(t) && taskMatchesProject(t) && taskMatchesContractor(t) && taskMatchesDone(t)));
        if (countEl) countEl.textContent = filtered.length;

        if (filtered.length === 0) {
            cardsEl.innerHTML = '<div class="board-empty">' + esc(window.t('tasks.board.no_tasks')) + '</div>';
            return;
        }

        cardsEl.innerHTML = filtered.map(renderCard).join('');
        cardsEl.querySelectorAll('.task-card').forEach(card => {
            card.addEventListener('mousedown', e => startDrag(e, card));
        });
    });
}

/**
 * Returns the HTML for one board card - status, project and contractor chips, title, optional
 * description excerpt, the meta row chosen in Settings -> Card, tags and subtasks.
 */
function renderCard(t) {
    const cf = cardFields;
    const initials = t.analyst_name ? t.analyst_name.split(' ').map(w => w[0]).join('').substring(0, 2) : '';

    // Meta row — each piece is opt-in via Settings → Card
    const meta = [];
    // Priority is a placement, not a tick: off / dot / pill / border (#108).
    // 'border' draws on the card element itself, so it contributes nothing here.
    const priorityMarkup = TasksPriority.markup(t.priority, t.priority_colour, cf.priority);
    if (priorityMarkup) meta.push(priorityMarkup);
    if (cf.assignee && initials) {
        meta.push(`<span class="assignee-badge" title="${esc(t.analyst_name)}">${esc(initials)}</span>`);
    }
    // The other people on this task (GH #89), as initials beside the owner's.
    // Rides on the SAME `assignee` card setting rather than adding a second one:
    // somebody who has turned "who it is assigned to" off the card is saying they
    // do not want people on the card, and a separate toggle would let the card
    // show four helpers and not the person accountable.
    if (cf.assignee && t.collaborators && t.collaborators.length) {
        meta.push(t.collaborators.map(c => {
            const ci = (c.analyst_name || '').split(' ').map(w => w[0]).join('').substring(0, 2);
            const done = c.is_completed ? ' is-done' : '';
            return `<span class="assignee-badge involved-badge${done}" title="${escAttr(window.t('tasks.detail.involved') + ': ' + (c.analyst_name || ''))}">${esc(ci)}</span>`;
        }).join(''));
    }
    // ⭐ Ed's call: ONE list, with the ones you do not own marked. The server
    // works out which those are, because it is the only side that knows which
    // analyst the list was filtered for — the browser gets it wrong the moment
    // the analyst dropdown is pointed at somebody else.
    if (t.viewer_is_collaborator && !t.viewer_is_owner) {
        meta.push(`<span class="involved-flag" title="${escAttr(
            window.t('tasks.detail.involved_badge_title', { owner: t.analyst_name || window.t('tasks.detail.unassigned') })
        )}">${esc(window.t('tasks.detail.involved_badge'))}</span>`);
    }
    if (cf.team && t.team_name) {
        meta.push(`<span class="team-badge" title="${escAttr(window.t('tasks.detail.team'))}">${esc(t.team_name)}</span>`);
    }
    if (cf.start_date && t.start_date) {
        meta.push(`<span class="due-badge start-date-badge" title="${escAttr(window.t('tasks.detail.start_date'))}">${formatShortDate(t.start_date)}</span>`);
    }
    if (cf.due_date) {
        const dueBadge = formatDueBadge(t.due_date, taskIsDone(t));
        if (dueBadge) meta.push(dueBadge);
    }

    if (cf.links && (t.ticket_id || t.change_id)) {
        meta.push(`<span class="link-badge"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg></span>`);
    }

    let descHtml = '';
    if (cf.description) {
        const excerpt = descExcerpt(t.description);
        if (excerpt) descHtml = `<div class="task-card-desc">${esc(excerpt)}</div>`;
    }

    let tagsHtml = '';
    if (tagSettings.surface_card && t.tags && t.tags.length) {
        tagsHtml = `<div class="task-card-tags">${t.tags.map(tg => tagChipHtml(tg)).join('')}</div>`;
    }

    const accent = TasksPriority.accentAttrs(t.priority, t.priority_colour, cf.priority);

    // In columns-by-analyst the column no longer says what state the task is
    // in, so the card does - a chip in the status's own colour.
    let statusChip = '';
    if (boardGroup === 'analyst' && t.status) {
        const st = (statusList || []).find(s => s.name === t.status);
        statusChip = `<span class="task-card-status" style="--st:${escAttr((st && st.colour) || '#6b7280')}">${esc(t.status)}</span>`;
    }
    // Projects (3.2.0): which project this task is part of, in the project's own colour.
    const projectChip = t.project_id
        ? `<div class="task-card-project" style="--pc:${escAttr(t.project_colour || '#e11d48')}" title="${escAttr(window.t('tasks.detail.project') + ': ' + t.project_name + (t.project_stage_name ? ' - ' + t.project_stage_name : ''))}"><span class="task-card-project-dot"></span>${esc(t.project_name)}</div>`
        : '';
    // Contractors (3.3.0): a supplier doing the work, and the person there.
    const contractorChip = t.supplier_id
        ? `<div class="task-card-contractor" title="${escAttr(window.t('tasks.detail.contractor') + ': ' + t.supplier_name + (t.contact_name ? ' - ' + t.contact_name : ''))}"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="1"></rect><path d="M9 7h1M14 7h1M9 11h1M14 11h1M10 21v-4h4v4"></path></svg>${esc(t.supplier_name)}${t.contact_name ? ' <span>&middot; ' + esc(t.contact_name) + '</span>' : ''}</div>`
        : '';
    return `<div class="task-card" data-id="${t.id}" onclick="openDetailPanel(${t.id})"${accent}>
        ${statusChip}
        ${projectChip}
        ${contractorChip}
        <div class="task-card-title">${esc(t.title)}</div>
        ${descHtml}
        ${meta.length ? `<div class="task-card-meta">${meta.join('')}</div>` : ''}
        ${tagsHtml}
        ${cardSubtaskHeadHtml(t)}
        ${boardSubtasksHtml(t)}
    </div>`;
}

/**
 * The subtasks' own line on a card: a chevron, the progress bar and "1/4 subtasks",
 * left-aligned directly above the list - and the button that shows or hides this
 * card's list (GH #184). It used to sit at the end of the meta row, where it
 * wrapped onto a line of its own with a 9px arrow and, in dark mode, an empty bar
 * you could not see - so the count looked like it was floating. A button, so the
 * keyboard reaches it; mousedown stops here so pressing it never drags the card.
 */
function cardSubtaskHeadHtml(t) {
    if (!cardFields.subtasks || !(t.subtasks && t.subtasks.total > 0)) return '';
    const open = cardSubtasks(t).length > 0;
    const pct = Math.round(t.subtasks.done / t.subtasks.total * 100);
    return `<button type="button" class="card-subtask-head${open ? ' is-open' : ''}" aria-expanded="${open}"
            title="${escAttr(window.t(open ? 'tasks.board.subtasks_card_hide' : 'tasks.board.subtasks_card_show'))}"
            onmousedown="event.stopPropagation()" onclick="toggleCardSubtasks(event, ${t.id})">
        <svg class="card-subtask-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"></polyline></svg>
        <span class="subtask-bar"><span class="subtask-bar-fill" style="width:${pct}%"></span></span>
        <span>${esc(window.t('tasks.board.subtasks_count', { done: t.subtasks.done, total: t.subtasks.total }))}</span>
    </button>`;
}

/**
 * The subtasks listed, indented, under their card. Rides on the same Settings
 * -> Card "subtasks" switch as the 2/5 bar: somebody who turned subtasks off
 * the card does not want them back as a list. The personal preference in
 * System -> Preferences tidies the list away once every one is complete.
 *
 * Each row opens ITS subtask, not the parent - the click stops at the row.
 */
function boardSubtasksHtml(t) {
    // All, open only or none, unless this card has its own choice (GH #184).
    const items = cardSubtasks(t);
    if (!cardFields.subtasks || !items.length) return '';
    return `<div class="task-card-subtasks">${items.map(s => {
        const due = s.due_date ? formatDueBadge(s.due_date) : '';
        return `<div class="task-card-subtask${s.status_is_closed ? ' is-done' : ''}"
                     onclick="event.stopPropagation(); openDetailPanel(${s.id})">
            <button type="button" class="task-card-subtask-tick" role="checkbox" aria-checked="${taskIsDone(s)}"
                    aria-label="${escAttr(window.t(taskIsDone(s) ? 'tasks.board.subtask_reopen' : 'tasks.board.subtask_complete', { title: s.title }))}"
                    title="${escAttr(window.t(taskIsDone(s) ? 'tasks.board.subtask_reopen' : 'tasks.board.subtask_complete', { title: s.title }))}"
                    onmousedown="event.stopPropagation()" onclick="boardToggleSubtask(event, ${s.id}, ${!taskIsDone(s)})"></button>
            <span class="task-card-subtask-title">${esc(s.title)}</span>
            ${due && !s.status_is_closed ? due : ''}
        </div>`;
    }).join('')}</div>`;
}

// Short date for the start badge, e.g. "12 Jun"
function formatShortDate(dateStr) {
    if (!dateStr) return '';
    return fmtNaiveDayMonth(new Date(dateStr + 'T00:00:00'));
}

// Plain-text excerpt of a (HTML) description, capped at 250 characters.
// DOMParser keeps it inert — no scripts run and no resources load.
function descExcerpt(html) {
    if (!html) return '';
    const doc = new DOMParser().parseFromString(html, 'text/html');
    let text = (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
    if (text.length > 250) text = text.slice(0, 250).replace(/\s+\S*$/, '') + '…';
    return text;
}

/**
 * Whether a task is done - its status is a closed one. The list endpoint has sent
 * this as a number, a boolean and the string "0", and "0" is truthy, so always
 * through Number().
 */
function taskIsDone(t) {
    return !!Number(t && t.status_is_closed);
}

/**
 * Returns a due-date badge: "Overdue" or "Due today" with a matching class,
 * otherwise the day and month. A DONE task only ever shows its date: finishing
 * late is not being overdue (GH #184 - done tasks said "Overdue" for ever).
 *
 * @param {string} dateStr  the due date, YYYY-MM-DD
 * @param {boolean} [done]  the task is done
 */
function formatDueBadge(dateStr, done) {
    if (!dateStr) return '';
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const due = new Date(dateStr + 'T00:00:00');
    const diff = Math.floor((due - today) / 86400000);
    let cls = '';
    let text = '';
    if (done) { text = fmtNaiveDayMonth(due); }
    else if (diff < 0) { cls = 'overdue'; text = window.t('tasks.board.overdue'); }
    else if (diff === 0) { cls = 'today'; text = window.t('tasks.board.due_today'); }
    else if (diff <= 7) { text = fmtNaiveDayMonth(due); }
    else { text = fmtNaiveDayMonth(due); }
    return `<span class="due-badge ${cls}">${text}</span>`;
}

// ── Quick Add ──────────────────────────────────────────────────────

/**
 * New in the action row (#185). Asks for a title, creates the task the way the
 * board's quick add does (the default status, assigned to you), then opens it
 * so everything else can be filled in.
 */
async function openNewTask() {
    let title = '';
    const ok = await showConfirm({
        title: window.t('tasks.board.new_task'),
        okLabel: window.t('tasks.board.new_task_create'),
        textarea: { label: window.t('tasks.board.new_task_title'), placeholder: window.t('tasks.board.quick_add_placeholder'), focus: true },
        onConfirm: r => { title = r.text.replace(/\s+/g, ' '); }
    });
    if (!ok || !title) return;
    try {
        const data = await fetch(API_BASE + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title, assigned_analyst_id: ANALYST_ID || null })
        }).then(r => r.json());
        if (!data.success) {
            showToast(window.t('tasks.toast.error_prefix', { message: data.error || window.t('tasks.toast.create_failed') }));
            return;
        }
        showToast(window.t('tasks.toast.task_created'), 'success');
        await loadTasks();
        openDetailPanel(data.id);
    } catch (e) {
        showToast(window.t('tasks.toast.create_failed'), 'error');
    }
}

/** Opens a column's quick-add box and focuses it, hiding it again on blur if nothing was typed. */
function showQuickAdd(col) {
    const container = col.querySelector('.quick-add-container');
    container.style.display = 'block';
    const input = container.querySelector('input');
    input.value = '';
    input.focus();

    // Hide on blur if left empty
    input.onblur = () => {
        setTimeout(() => {
            if (!input.value.trim()) container.style.display = 'none';
        }, 150);
    };
}

/**
 * Quick-add key handler: Escape closes the box, Enter creates a task with that title in the column's
 * status, assigned to the current analyst, and reloads the board.
 */
async function handleQuickAdd(event, status, col) {
    if (event.key === 'Escape') {
        event.target.value = '';
        col.querySelector('.quick-add-container').style.display = 'none';
        return;
    }
    if (event.key !== 'Enter') return;
    const input = event.target;
    const title = input.value.trim();
    if (!title) return;

    input.disabled = true;
    try {
        const data = await fetch(API_BASE + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title, status, assigned_analyst_id: ANALYST_ID || null })
        }).then(r => r.json());

        if (data.success) {
            input.value = '';
            col.querySelector('.quick-add-container').style.display = 'none';
            loadTasks();
            showToast(window.t('tasks.toast.task_created'), 'success');
        } else {
            showToast(window.t('tasks.toast.error_prefix', { message: data.error || window.t('tasks.toast.create_failed') }));
        }
    } catch (e) {
        showToast(window.t('tasks.toast.create_failed'), 'error');
    }
    input.disabled = false;
}

// ── Drag & Drop ────────────────────────────────────────────────────

let dragState = null;

/**
 * Mouse-down on a board card. Starts a card drag only once the mouse has moved more than 5px, so a
 * plain click still opens the task.
 */
function startDrag(e, card) {
    if (e.button !== 0) return;
    // Read only access to Tasks (#181 Part G) moves nothing - the server refuses a
    // drag anyway, and a card that springs back reads as the board being broken.
    // A click still opens the task, which is handled elsewhere.
    if (document.body.classList.contains('module-read-only')) return;
    // Don't start drag on click (for opening detail panel)
    const startX = e.clientX;
    const startY = e.clientY;
    let moved = false;

    /** Starts the drag on the first move beyond 5px, then moves the ghost card with the mouse. */
    const onMove = (e2) => {
        const dx = Math.abs(e2.clientX - startX);
        const dy = Math.abs(e2.clientY - startY);
        if (!moved && (dx > 5 || dy > 5)) {
            moved = true;
            initDrag(card, e2);
        }
        if (moved) moveDrag(e2);
    };

    /** Removes the drag listeners and finishes the drop if a drag actually started. */
    const onUp = (e2) => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        if (moved) endDrag(e2);
    };

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
    e.preventDefault();
}

/**
 * Marks the card as dragging and creates the floating ghost copy, recording where in the card it was
 * grabbed and the column it came from.
 */
function initDrag(card, e) {
    card.classList.add('dragging');
    const rect = card.getBoundingClientRect();

    // Create ghost
    const ghost = card.cloneNode(true);
    ghost.classList.add('drag-ghost');
    ghost.style.width = rect.width + 'px';
    document.body.appendChild(ghost);

    dragState = {
        taskId: parseInt(card.dataset.id),
        card,
        ghost,
        offsetX: e.clientX - rect.left,
        offsetY: e.clientY - rect.top,
        sourceStatus: card.closest('.board-column').dataset.status
    };

    moveDrag(e);
}

/**
 * Moves the ghost card with the mouse and shows the drop indicator in the column under the cursor,
 * before the first card whose middle is below it.
 */
function moveDrag(e) {
    if (!dragState) return;
    dragState.ghost.style.left = (e.clientX - dragState.offsetX) + 'px';
    dragState.ghost.style.top = (e.clientY - dragState.offsetY) + 'px';

    // Remove old indicators
    document.querySelectorAll('.drop-indicator').forEach(el => el.remove());
    document.querySelectorAll('.board-column.drag-over').forEach(el => el.classList.remove('drag-over'));

    // Find target column
    const columns = document.querySelectorAll('.board-column');
    let targetColumn = null;
    columns.forEach(col => {
        const r = col.getBoundingClientRect();
        if (e.clientX >= r.left && e.clientX <= r.right) targetColumn = col;
    });

    if (!targetColumn) return;
    targetColumn.classList.add('drag-over');

    // Find insertion point
    const cards = targetColumn.querySelectorAll('.task-card:not(.dragging)');
    let insertBefore = null;
    cards.forEach(c => {
        const r = c.getBoundingClientRect();
        if (e.clientY < r.top + r.height / 2 && !insertBefore) {
            insertBefore = c;
        }
    });

    // Sorted by due date (GH #191) a card's place in a column is decided by its
    // date, not by where it is dropped - so there is no line to drop it on.
    if (boardSort === 'due') return;

    // Show indicator
    const indicator = document.createElement('div');
    indicator.className = 'drop-indicator';
    const container = targetColumn.querySelector('.board-cards');
    if (insertBefore) container.insertBefore(indicator, insertBefore);
    else container.appendChild(indicator);
}

/**
 * Earlier version of the card drop handler.
 *
 * TRAP: the second endDrag declared below replaces this one when the script loads, so this version
 * never runs - change that one instead.
 */
async function endDrag(e) {
    if (!dragState) return;

    // Clean up ghost and indicators
    dragState.ghost.remove();
    dragState.card.classList.remove('dragging');
    document.querySelectorAll('.drop-indicator').forEach(el => el.remove());
    document.querySelectorAll('.board-column.drag-over').forEach(el => el.classList.remove('drag-over'));

    // Find target column
    const columns = document.querySelectorAll('.board-column');
    let targetColumn = null;
    columns.forEach(col => {
        const r = col.getBoundingClientRect();
        if (e.clientX >= r.left && e.clientX <= r.right) targetColumn = col;
    });

    if (!targetColumn) { dragState = null; return; }

    // Columns by analyst: a drop reassigns the task (see dropOnAnalystColumn).
    if (boardGroup === 'analyst') {
        const movedId = dragState.taskId;
        dragState = null;
        await dropOnAnalystColumn(movedId, targetColumn);
        return;
    }

    const newStatus = targetColumn.dataset.status;
    const container = targetColumn.querySelector('.board-cards');

    // Determine new position order
    const cards = container.querySelectorAll('.task-card:not(.dragging)');
    let insertIndex = cards.length;
    cards.forEach((c, i) => {
        const r = c.getBoundingClientRect();
        if (e.clientY < r.top + r.height / 2 && insertIndex === cards.length) {
            insertIndex = i;
        }
    });

    // Build positions array: all cards in target column with new order
    const positions = [];
    let pos = 0;
    const allCards = Array.from(cards);
    for (let i = 0; i < allCards.length; i++) {
        if (i === insertIndex) {
            positions.push({ id: dragState.taskId, board_position: pos++ });
        }
        const cardId = parseInt(allCards[i].dataset.id);
        if (cardId !== dragState.taskId) {
            positions.push({ id: cardId, board_position: pos++ });
        }
    }
    if (insertIndex >= allCards.length) {
        positions.push({ id: dragState.taskId, board_position: pos++ });
    }

    dragState = null;

    // Call API
    try {
        await fetch(API_BASE + 'reorder.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_id: positions.find(p => true).id !== undefined ? positions[0].id : 0, new_status: newStatus, positions, task_id: positions.find(p => p.board_position === insertIndex)?.id || positions[0].id })
        });
    } catch (e) { console.error(e); }

    // Send the actual moved task's reorder
    try {
        await fetch(API_BASE + 'reorder.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                task_id: parseInt(document.querySelector('.task-card[data-id]')?.dataset.id) || 0,
                new_status: newStatus,
                positions
            })
        });
    } catch (e) {}

    loadTasks();
}

// Fix: simplified endDrag reorder call
async function endDrag(e) {
    if (!dragState) return;

    dragState.ghost.remove();
    dragState.card.classList.remove('dragging');
    document.querySelectorAll('.drop-indicator').forEach(el => el.remove());
    document.querySelectorAll('.board-column.drag-over').forEach(el => el.classList.remove('drag-over'));

    const columns = document.querySelectorAll('.board-column');
    let targetColumn = null;
    columns.forEach(col => {
        const r = col.getBoundingClientRect();
        if (e.clientX >= r.left && e.clientX <= r.right) targetColumn = col;
    });

    if (!targetColumn) { dragState = null; return; }

    // Columns by analyst: a drop reassigns the task (see dropOnAnalystColumn).
    if (boardGroup === 'analyst') {
        const movedId = dragState.taskId;
        dragState = null;
        await dropOnAnalystColumn(movedId, targetColumn);
        return;
    }

    const newStatus = targetColumn.dataset.status;
    const container = targetColumn.querySelector('.board-cards');
    const otherCards = Array.from(container.querySelectorAll('.task-card:not(.dragging)'));

    // Sorted by due date (GH #191): a drop in the SAME column changes nothing - the
    // shared manual order is not rewritten from a display order. A drop in another
    // column still moves the task there, placed after that column's manual order.
    if (boardSort === 'due') {
        const moved = tasks.find(t => t.id == dragState.taskId) || {};
        const movedId = dragState.taskId;
        dragState = null;
        if (moved.status === newStatus) { renderBoard(); return; }
        const last = tasks.filter(t => t.status === newStatus && !t.parent_task_id)
            .reduce((m, t) => Math.max(m, Number(t.board_position) || 0), -1);
        await dropWithStatus(movedId, newStatus, moved.status, [{ id: movedId, board_position: last + 1 }]);
        return;
    }

    // Find insert position
    let insertIndex = otherCards.length;
    for (let i = 0; i < otherCards.length; i++) {
        const r = otherCards[i].getBoundingClientRect();
        if (e.clientY < r.top + r.height / 2) { insertIndex = i; break; }
    }

    // Build ordered list
    const ordered = [];
    for (let i = 0; i < otherCards.length; i++) {
        if (i === insertIndex) ordered.push(dragState.taskId);
        const cid = parseInt(otherCards[i].dataset.id);
        if (cid !== dragState.taskId) ordered.push(cid);
    }
    if (insertIndex >= otherCards.length) ordered.push(dragState.taskId);

    const positions = ordered.map((id, idx) => ({ id, board_position: idx }));
    const movedTaskId = dragState.taskId;
    dragState = null;
    await dropWithStatus(movedTaskId, newStatus, (tasks.find(t => t.id == movedTaskId) || {}).status, positions);
}

/**
 * Sends a card drop to the server - the status it landed in and the positions to store - after the
 * checklist question a completing drop asks, then reloads the board. Shared by the manual drop and
 * the due-date drop (GH #191).
 *
 * @param positions  [{id, board_position}] to store; the due-date drop sends only the moved card's
 */
async function dropWithStatus(movedTaskId, newStatus, movedFrom, positions) {
    // Dropping into a closed column is completing the task - the commonest way
    // it happens - so it gets the same checklist question as the status box
    // (discussion #138). Declined: the card goes back where it was.
    if (!(await confirmChecklistCompletion(movedTaskId, newStatus, movedFrom))) {
        loadTasks();
        return;
    }

    // Same reasoning as postTaskChange above: a refused reorder used to leave the
    // card springing back to where it started with nothing said, which reads as
    // the drag not having worked rather than as the server declining it.
    try {
        const res = await fetch(API_BASE + 'reorder.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_id: movedTaskId, new_status: newStatus, positions })
        });
        const data = await res.json();
        if (!data || !data.success) {
            showToast(data && data.error === 'Not authenticated'
                ? window.t('tasks.toast.session_expired')
                // A checklist refusal says WHICH steps - "could not reorder"
                // would read as the drag having broken.
                : (data && data.code === 'mandatory_steps_outstanding' ? data.error
                : window.t('tasks.toast.order_failed')), 'error');
        }
    } catch (e) {
        console.error(e);
        showToast(window.t('tasks.toast.order_failed'), 'error');
    }

    loadTasks();
}

// ── List Rendering ─────────────────────────────────────────────────

/**
 * Draws the list view: the filtered tasks sorted by the chosen column, one table row each, and marks
 * the sorted column header.
 */
function renderList() {
    const sorted = tasks.filter(t => taskMatchesSearch(t) && taskMatchesTag(t) && taskMatchesProject(t) && taskMatchesContractor(t) && taskMatchesDone(t)).sort((a, b) => {
        let va = a[sortField] || '';
        let vb = b[sortField] || '';
        if (typeof va === 'string') va = va.toLowerCase();
        if (typeof vb === 'string') vb = vb.toLowerCase();
        if (va < vb) return sortDir === 'asc' ? -1 : 1;
        if (va > vb) return sortDir === 'asc' ? 1 : -1;
        return 0;
    });

    const tbody = document.getElementById('listTableBody');
    if (sorted.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#999;padding:30px;">' + esc(window.t('tasks.list.no_tasks')) + '</td></tr>';
        return;
    }

    tbody.innerHTML = sorted.map(t => {
        const sc = statusColour(t.status);
        const subtaskText = t.subtasks.total > 0 ? `${t.subtasks.done}/${t.subtasks.total}` : '—';
        const dueBadge = formatDueBadge(t.due_date, taskIsDone(t));

        const tagsHtml = (tagSettings.surface_card && t.tags && t.tags.length)
            ? `<div class="task-card-tags">${t.tags.map(tg => tagChipHtml(tg)).join('')}</div>` : '';
        // Always the pill here, whatever the card placement says: this is a column
        // headed "Priority", and honouring 'off' would empty it while leaving the
        // header in place. The card setting governs cards.
        // The old code called t.priority.toLowerCase() unguarded, which throws on a
        // task with no priority (priority_id is nullable) and takes the whole list
        // render down with it — the map() never completes.
        const priorityCell = TasksPriority.markup(t.priority, t.priority_colour, 'pill') || '—';

        return `<tr onclick="openDetailPanel(${t.id})">
            <td><strong>${esc(t.title)}</strong>${tagsHtml}</td>
            <td><span class="status-pill" style="background:${sc}1f;color:${sc}">${esc(t.status)}</span></td>
            <td>${priorityCell}</td>
            <td>${esc(t.analyst_name || '—')}</td>
            <td>${esc(t.team_name || '—')}</td>
            <td>${dueBadge || '—'}</td>
            <td>${subtaskText}</td>
        </tr>`;
    }).join('');

    // Update sort indicators
    document.querySelectorAll('.task-table th').forEach(th => th.classList.remove('sorted'));
    const sortedTh = document.querySelector(`.task-table th[data-sort="${sortField}"]`);
    if (sortedTh) sortedTh.classList.add('sorted');
}

/** Sorts the list by a column - the same column again flips the direction, a new one starts ascending. */
function sortList(field) {
    if (sortField === field) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
    else { sortField = field; sortDir = 'asc'; }
    renderList();
}

// ── Lookup helpers ─────────────────────────────────────────────────

// Configured colour for a status name (falls back to a neutral grey)
function statusColour(name) {
    const s = statusList.find(x => x.name === name);
    return (s && s.colour) ? s.colour : '#6b7280';
}

// <option> markup for a status/priority dropdown — always keeps the
// task's current value, even if it is no longer in the active list
function lookupOptions(list, current) {
    const names = list.map(x => x.name);
    if (current && !names.includes(current)) names.unshift(current);
    return names.map(n =>
        `<option ${n === current ? 'selected' : ''}>${esc(n)}</option>`).join('');
}

// ── Detail Panel ───────────────────────────────────────────────────

// Does this analyst open tasks in the large window? Set per analyst in
// System → Preferences and published by tasks/index.php, so the choice is known
// before anything opens rather than fetched on the way.
function taskViewIsModal() {
    return window.TASK_DETAIL_VIEW === 'modal';
}

// The same content, laid out in two columns: the things that want width on the
// left, the properties down the right. Called only in the large-window view.
//
// ⚠️ Runs BEFORE the description editor and the documents panel are mounted.
// Moving a node after TinyMCE has attached to it tears the editor's iframe out
// of the document, and it does not come back.
function applyModalLayout(body) {
    // Two shapes for the large window, chosen per analyst. BOTH rearrange the
    // markup that is already there — neither renders a second template. Two
    // copies of this panel would be two things to keep in step, and the one
    // nobody is looking at is the one that rots.
    if (modalLayoutIsTabs()) {
        applyModalTabs(body);
        return;
    }

    // The blocks that earn the wide column: prose and conversation. Everything
    // else is a short field and reads better in a narrow stack.
    const WIDE = '.detail-description, .subtask-section, .comments-section, #taskWorkSection';
    const layout = document.createElement('div');
    layout.className = 'tdm-layout';
    const main = document.createElement('div');
    main.className = 'tdm-main';
    const side = document.createElement('div');
    side.className = 'tdm-side';

    Array.from(body.children).forEach(el => {
        // The title stays full width across the top — it is the heading of the
        // window, not a field in either column.
        if (el.querySelector && el.querySelector('#detailTitle')) return;
        (el.matches(WIDE) ? main : side).appendChild(el);
    });

    layout.appendChild(main);
    layout.appendChild(side);
    body.appendChild(layout);
}

// ── The large window as TABS ────────────────────────────────────────────────
//
// A task has grown a lot: fields, the people involved, tags, a description,
// repeats, links, subtasks, time, comments and documents. As one column that is
// a long scroll for somebody who only wants the comments. Tabs put each part one
// click away instead.
//
// ⚠️ Offered rather than imposed, and columns stay the default. A long page is
// genuinely better when you want to take the whole task in at once, and tabs
// hide things — somebody who never scrolled past the fold will now never see the
// subtasks either unless the tab tells them the count.

/** True when this analyst has chosen tabs rather than columns for the large task window. */
function modalLayoutIsTabs() {
    return window.TASK_MODAL_LAYOUT === 'tabs';
}

/**
 * The tabs, in reading order. Each names the selectors it collects.
 *
 * 🔑 Anything NOT matched by one of these falls into the first tab. That is the
 * rule that stops this becoming a maintenance trap: add a new section to the
 * panel and it appears in Details rather than vanishing from the window
 * entirely, which is what a hardcoded "everything else goes here" list would do
 * the first time somebody forgot to update it.
 */
const TASK_MODAL_TABS = [
    { key: 'details',  match: null },     // the catch-all, and deliberately first
    { key: 'work',     match: '#taskWorkSection' },   // GH #190 - opened first when the task has work in it
    { key: 'subtasks', match: '.subtask-section',  count: '.subtask-item, .subtask-row' },
    { key: 'time',     match: '#taskTimeSection' },
    { key: 'comments', match: '.comments-section', count: '.comment-item' },
    { key: 'links',    match: '.link-section' },
    { key: 'documents',match: '#taskDocuments' },
    { key: 'history',  match: '.history-section' },   // #181 - what happened to the task
];

/**
 * Rearranges the task window's existing sections into tabs, filing each block by TASK_MODAL_TABS,
 * dropping empty tabs, adding counts, and opening the first tab that remains.
 */
function applyModalTabs(body) {
    const strip  = document.createElement('div');
    strip.className = 'tdm-tabs';
    strip.setAttribute('role', 'tablist');
    const panels = document.createElement('div');
    panels.className = 'tdm-tabpanels';

    // One panel per tab, built up front so the sorting loop below can just file
    // each block into the right one.
    const panelFor = {};
    TASK_MODAL_TABS.forEach(t => {
        const p = document.createElement('div');
        p.className = 'tdm-tabpanel';
        p.id = 'tdmTab-' + t.key;
        p.setAttribute('role', 'tabpanel');
        panels.appendChild(p);
        panelFor[t.key] = p;
    });

    Array.from(body.children).forEach(el => {
        // The title stays across the top, above the tabs — it says which task
        // you are looking at, which is true on every tab.
        if (el.querySelector && el.querySelector('#detailTitle')) return;
        // ⚠️ `matches` OR `querySelector`. Some blocks ARE the thing named
        // (`#taskTimeSection` is itself the child); others are a plain wrapper
        // around it (`#taskDocuments` sits inside an unclassed `.detail-section`).
        // Testing only the element itself sent Documents to the catch-all — not
        // lost, but not its own tab either, which is the quieter kind of wrong.
        const hit = TASK_MODAL_TABS.find(t => t.match && el.matches &&
            (el.matches(t.match) || el.querySelector(t.match)));
        panelFor[hit ? hit.key : 'details'].appendChild(el);
    });

    // A tab with nothing in it is not drawn. Repeats and documents are optional,
    // and a subtask section does not exist at all on a subtask — an empty tab
    // would be a promise of content that is not there.
    TASK_MODAL_TABS.forEach((t, i) => {
        const panel = panelFor[t.key];
        if (!panel.children.length) { panel.remove(); return; }

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tdm-tab';
        btn.setAttribute('role', 'tab');
        btn.dataset.tab = t.key;
        btn.textContent = window.t('tasks.detail.tab_' + t.key);

        // ⭐ The count is what stops tabs HIDING things. Without it, three
        // subtasks behind a tab are indistinguishable from none, and the person
        // who never scrolled simply never learns they exist.
        if (t.count) {
            setModalTabCount(btn, panel.querySelectorAll(t.count).length);
        }
        // Documents load after the tabs are drawn, so their count arrives later,
        // from the documents panel itself.
        if (t.key === 'documents') {
            panel.addEventListener('fd:count', e => setModalTabCount(btn, e.detail.total));
        }
        btn.onclick = () => showModalTab(t.key);
        strip.appendChild(btn);
    });

    body.appendChild(strip);
    body.appendChild(panels);

    // Open on whichever tab survived and comes first — normally Details, but a
    // subtask has no Subtasks tab, so never assume a fixed key exists.
    const first = strip.querySelector('.tdm-tab');
    if (first) showModalTab(first.dataset.tab);
}

/** Sets the count badge on a task-window tab, removing the badge when the count is zero. */
function setModalTabCount(btn, n) {
    let badge = btn.querySelector('.tdm-tab-count');
    if (!n) { if (badge) badge.remove(); return; }
    if (!badge) {
        badge = document.createElement('span');
        badge.className = 'tdm-tab-count';
        btn.appendChild(badge);
    }
    badge.textContent = n;
}

/** Shows one tab of the task window and hides the others, keeping aria-selected in step. */
function showModalTab(key) {
    document.querySelectorAll('.tdm-tab').forEach(b => {
        const on = b.dataset.tab === key;
        b.classList.toggle('active', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    document.querySelectorAll('.tdm-tabpanel').forEach(p => {
        p.classList.toggle('active', p.id === 'tdmTab-' + key);
    });
}

/** Columns ⇄ tabs, remembered per analyst. Mirrors toggleTaskView(). */
async function toggleModalLayout() {
    window.TASK_MODAL_LAYOUT = modalLayoutIsTabs() ? 'columns' : 'tabs';
    const id = selectedTaskId;
    // Redraw first so the change is instant; storing the preference is a
    // background detail and must not make the button feel slow.
    if (id) await openDetailPanel(id);
    try {
        const r = await fetch(APP_BASE + 'api/system/set_user_preference.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: 'tasks_modal_layout', value: window.TASK_MODAL_LAYOUT })
        }).then(r => r.json());
        if (!r || !r.success) {
            showToast(window.t('tasks.detail.view_not_saved'), 'error');
        }
    } catch (e) {
        console.error(e);
        showToast(window.t('tasks.detail.view_not_saved'), 'error');
    }
}

// The header button that swaps between the side panel and the large window.
// Drawn per state: an expand icon while you are in the drawer, a shrink icon
// while you are in the window, so the icon shows what pressing it will DO.
function paintViewToggle() {
    paintLayoutToggle();
    const btn  = document.getElementById('detailViewToggle');
    const icon = document.getElementById('detailViewToggleIcon');
    if (!btn || !icon) return;
    const modal = taskViewIsModal();
    icon.innerHTML = modal
        // Shrink back to the side panel
        ? '<polyline points="4 14 10 14 10 20"></polyline><polyline points="20 10 14 10 14 4"></polyline><line x1="14" y1="10" x2="21" y2="3"></line><line x1="3" y1="21" x2="10" y2="14"></line>'
        // Grow into the large window
        : '<polyline points="15 3 21 3 21 9"></polyline><polyline points="9 21 3 21 3 15"></polyline><line x1="21" y1="3" x2="14" y2="10"></line><line x1="3" y1="21" x2="10" y2="14"></line>';
    btn.title = window.t(modal ? 'tasks.detail.view_to_panel' : 'tasks.detail.view_to_modal');
}

/**
 * The columns/tabs button, which only exists in the large window.
 *
 * ⚠️ Hidden rather than disabled in the side panel. A greyed-out control still
 * asks to be understood; one that is not there costs nothing.
 */
function paintLayoutToggle() {
    const btn  = document.getElementById('detailLayoutToggle');
    const icon = document.getElementById('detailLayoutToggleIcon');
    if (!btn || !icon) return;

    if (!taskViewIsModal()) { btn.style.display = 'none'; return; }
    btn.style.display = '';

    const tabs = modalLayoutIsTabs();
    icon.innerHTML = tabs
        // Back to one long page: a single column of stacked rows
        ? '<rect x="3" y="4" width="18" height="4" rx="1"></rect><rect x="3" y="10" width="18" height="4" rx="1"></rect><rect x="3" y="16" width="18" height="4" rx="1"></rect>'
        // Into tabs: a strip of headers above a pane
        : '<path d="M3 8V5a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v1"></path><rect x="3" y="8" width="18" height="12" rx="1"></rect>';
    btn.title = window.t(tabs ? 'tasks.detail.layout_to_columns' : 'tasks.detail.layout_to_tabs');
}

// Swap the view, remember it, and redraw the task that is already open.
//
// The preference is saved rather than held for the session: somebody who moves
// a task into the big window means "this is how I want tasks", not "just this
// once". It is the same key System → Preferences writes, so the two agree.
async function toggleTaskView() {
    window.TASK_DETAIL_VIEW = taskViewIsModal() ? 'panel' : 'modal';
    const id = selectedTaskId;
    // Redraw first so the change is instant; the preference is a background
    // detail and must not make the button feel slow.
    if (id) await openDetailPanel(id);
    try {
        const r = await fetch(APP_BASE + 'api/system/set_user_preference.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: 'tasks_detail_view', value: window.TASK_DETAIL_VIEW })
        }).then(r => r.json());
        if (!r || !r.success) {
            // The view still changed for this session; be honest that it will
            // not be remembered rather than silently forgetting it.
            showToast(window.t('tasks.detail.view_not_saved'), 'error');
        }
    } catch (e) {
        console.error(e);
        showToast(window.t('tasks.detail.view_not_saved'), 'error');
    }
}

/**
 * Fetches a task and opens it in the side panel or the large window, along with its checklists and the
 * people involved.
 *
 * Does nothing while a card is being dragged, and scrolls back to the top on every open.
 */
async function openDetailPanel(taskId) {
    // Prevent opening from drag
    if (dragState) return;

    if (window.TASK_HOST === 'ticket' && selectedTaskId && selectedTaskId !== taskId && !await TaskEmbedBridge.canLeave()) return;
    selectedTaskId = taskId;
    const generation = ++taskDetailGeneration;
    const embedQuery = window.TASK_HOST === 'ticket'
        ? '&embed_root_id=' + window.TASK_EMBED_CONTEXT.taskId + '&ticket_id=' + window.TASK_EMBED_CONTEXT.ticketId : '';
    try {
        const [data] = await Promise.all([
            fetch(API_BASE + 'get.php?id=' + taskId + embedQuery).then(r => r.json()),
            loadProjectChoices(),   // once per page, for the Project field (3.2.0)
            loadContractorChoices(),   // and the Contractor field (3.3.0)
        ]);
        if (!data.success) { window.TaskEmbedBridge?.unavailable(); return; }
        if (selectedTaskId !== taskId || generation !== taskDetailGeneration) return;
        // The recent trail (#124).
        if (window.trailVisit) window.trailVisit('task', taskId);
        const panel = document.getElementById('detailPanel');
        // One panel, two shapes. The drawer is the default and is untouched;
        // the modal is the same element with a class on it, so there is no
        // second panel to keep in step.
        panel.classList.toggle('as-modal', taskViewIsModal());
        paintViewToggle();
        detailTaskStatus = data.task.status || null;
        renderDetailPanel(data.task);
        workState = { taskId, task: data.task, loaded: {}, autoOpened: false };
    loadTaskChecklists(taskId);
    loadTaskForms(taskId);
    loadTaskProcedures(taskId);
    loadTaskFollowUps(taskId);
        loadTaskHistory(taskId);
        // Who else is on it (GH #89). Fetched separately from the task itself
        // because the same call returns the CANDIDATE list, which is a different
        // question — "who could be added" depends on who is already on it.
        involvedState.ownerId = data.task.assigned_analyst_id ? Number(data.task.assigned_analyst_id) : null;
        loadInvolved(taskId);
        // Back to the top on every open (Ed). Clicking a subtask replaces the
        // panel's contents but not its scroll position, so you arrived at the
        // NEW task already scrolled down to where the subtask list was on the
        // old one - past its title, and past the "Subtask of" line that says
        // what you are now looking at.
        const body = panel.querySelector('.detail-panel-body') || panel;
        if (body) body.scrollTop = 0;
        panel.scrollTop = 0;
        panel.classList.add('open');
        document.getElementById('detailOverlay').classList.add('open');
        window.TaskEmbedBridge?.ready();
    } catch (e) { console.error(e); window.TaskEmbedBridge?.unavailable(); }
}

/** Closes the task panel, destroys the description editor and reloads the board. */
async function closeDetailPanel() {
    if (window.TASK_HOST === 'ticket' && !await TaskEmbedBridge.canLeave()) return;
    document.getElementById('detailPanel').classList.remove('open');
    document.getElementById('detailOverlay').classList.remove('open');
    if (tinyEditor) { tinyEditor.destroy(); tinyEditor = null; }
    selectedTaskId = null;
    loadTasks();
    window.TaskEmbedBridge?.closed();
}

/**
 * Builds the whole task panel for one task - fields, company, project, contractor, involved, dates,
 * time, checklists, tags, description, repeats, links, subtasks, comments and documents.
 *
 * Also starts the TinyMCE description editor, which saves a second after typing stops.
 */
function renderDetailPanel(task) {
    const body = document.getElementById('detailPanelBody');
    // What is currently linked, kept so linkItem() can warn before REPLACING it
    // rather than silently overwriting. A task holds one ticket and one change.
    detailLinks = {
        ticket_id: task.ticket_id || null,
        ticket_label: task.ticket_id
            ? ('#' + (task.ticket_number || task.ticket_id) + (task.ticket_subject ? ' — ' + task.ticket_subject : ''))
            : '',
        change_id: task.change_id || null,
        change_label: task.change_id ? (task.change_title || ('#' + task.change_id)) : '',
    };
    detailTags = (task.tags || []).map(t => ({ id: t.id, name: t.name, colour: t.colour }));
    const analystOptions = analysts.map(a =>
        `<option value="${a.id}" ${a.id == task.assigned_analyst_id ? 'selected' : ''}>${esc(a.name)}</option>`
    ).join('');
    const teamOptions = teams.map(t =>
        `<option value="${t.id}" ${t.id == task.assigned_team_id ? 'selected' : ''}>${esc(t.name)}</option>`
    ).join('');

    body.innerHTML = `
        <!-- What this is a subtask OF (Ed). It used to sit below the fields with
             no styling and no words - a back-chevron and a title, which says
             where the link goes but never says what the relationship is. It
             belongs at the top, because "this is part of something bigger" is
             context you need before you read anything else on the panel. -->
        ${task.parent_task ? `
        <button type="button" class="subtask-of" onclick="openDetailPanel(${task.parent_task.id})">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
            <span class="subtask-of-label">${esc(window.t('tasks.detail.subtask_of'))}</span>
            <span class="subtask-of-title">${esc(task.parent_task.title)}</span>
        </button>` : ''}

        <div class="detail-field">
            <input class="detail-title-input" id="detailTitle" value="${esc(task.title)}" data-task-field="title" onchange="saveField('title', this.value)">
        </div>

        <div class="detail-row">
            <div class="detail-field">
                <label>${esc(window.t('tasks.detail.status'))}</label>
                <select class="detail-select" data-task-field="status" onchange="saveField('status', this.value)">
                    ${lookupOptions(statusList, task.status)}
                </select>
            </div>
            <div class="detail-field">
                <label>${esc(window.t('tasks.detail.priority'))}</label>
                <select class="detail-select" data-task-field="priority" onchange="saveField('priority', this.value)">
                    ${lookupOptions(priorityList, task.priority)}
                </select>
            </div>
        </div>

        <div class="detail-row">
            <div class="detail-field">
                <label>${esc(window.t('tasks.detail.assignee'))}</label>
                <select class="detail-select" data-task-field="assigned_analyst_id" onchange="saveField('assigned_analyst_id', this.value || null)">
                    <option value="">${esc(window.t('tasks.detail.unassigned'))}</option>
                    ${analystOptions}
                </select>
            </div>
            <div class="detail-field">
                <label>${esc(window.t('tasks.detail.team'))}</label>
                <select class="detail-select" data-task-field="assigned_team_id" onchange="saveField('assigned_team_id', this.value || null)">
                    <option value="">${esc(window.t('tasks.detail.no_team'))}</option>
                    ${teamOptions}
                </select>
            </div>
        </div>

        <!-- WHICH COMPANY THIS TASK IS FOR. Hidden on a single-company install
             and on a subtask, which always follows its parent - the same rule
             that hides Involved below. -->
        ${(moveCompanies.length > 1 && !task.parent_task_id) ? `
        <div class="detail-field">
            <label>${esc(window.t('tasks.detail.company'))}</label>
            <select class="detail-select" id="detailCompany" data-previous="${task.tenant_id ?? defaultTenantId}" onchange="moveToCompanyFromPanel(this)">
                ${moveCompanies.map(co => `<option value="${co.id}"${String(co.id) === String(task.tenant_id ?? defaultTenantId) ? ' selected' : ''}>${esc(co.name)}</option>`).join('')}
            </select>
        </div>` : ''}

        ${!task.parent_task_id ? projectFieldHtml(task) : ''}
        ${contractorFieldHtml(task)}

        <!-- Who else is on this task (GH #89). Directly under Assignee, because
             "who owns it" and "who else is on it" are one question asked twice,
             and separating them would make the second look like an afterthought.
             Top-level tasks only — a subtask already carries its own assignee. -->
        ${!task.parent_task_id ? `
        <div class="detail-field" id="involvedField">
            <label>${esc(window.t('tasks.detail.involved'))}</label>
            <div id="involvedList"></div>
        </div>` : ''}

        <div class="detail-row">
            <div class="detail-field">
                <label>${esc(window.t('tasks.detail.start_date'))}</label>
                <input type="date" class="detail-input" value="${task.start_date || ''}" data-task-field="start_date" onchange="saveField('start_date', this.value || null)">
            </div>
            <div class="detail-field">
                <label>${esc(window.t('tasks.detail.due_date'))}</label>
                <input type="date" class="detail-input" value="${task.due_date || ''}" data-task-field="due_date" onchange="saveField('due_date', this.value || null)">
            </div>
        </div>

        <!-- How long it should take (3.3.0) - Projects reads it for capacity and estimate vs logged. -->
        <div class="detail-field">
            <label>${esc(window.t('tasks.detail.estimate'))}</label>
            <input type="number" class="detail-input" min="0" max="9999" step="0.25" inputmode="decimal" style="max-width:140px"
                   value="${task.estimate_hours !== null && task.estimate_hours !== undefined ? Number(task.estimate_hours) : ''}"
                   placeholder="${esc(window.t('tasks.detail.estimate_ph'))}" data-task-field="estimate_hours" onchange="saveField('estimate_hours', this.value === '' ? null : this.value)">
        </div>

        ${timeAllowedFor(task) ? `
        <!-- WHEN THE WORK IS PLANNED FOR. Naive wall-clock values, shown exactly
             as typed for every reader, like a ticket's scheduled work. Never run
             these through the timezone helpers. -->
        <div class="detail-row">
            <div class="detail-field">
                <label>${esc(window.t('tasks.detail.work_start'))}</label>
                <input type="${task.work_all_day == 1 ? 'date' : 'datetime-local'}" class="detail-input" id="detailWorkStart"
                       value="${workValue(task.work_start_datetime, task.work_all_day)}"
                       onchange="saveWorkStart(this.value)">
            </div>
            <div class="detail-field">
                <label>${esc(window.t('tasks.detail.work_end'))}</label>
                <input type="${task.work_all_day == 1 ? 'date' : 'datetime-local'}" class="detail-input" id="detailWorkEnd"
                       value="${workValue(task.work_end_datetime, task.work_all_day)}"
                       onchange="saveWorkEnd(this.value)">
            </div>
        </div>
        <div class="detail-field">
            <label class="detail-checkbox">
                <input type="checkbox" id="detailWorkAllDay" ${task.work_all_day == 1 ? 'checked' : ''}
                       data-task-field="work_all_day" onchange="saveField('work_all_day', this.checked ? 1 : 0); openDetailPanel(${task.id});">
                ${esc(window.t('tasks.detail.work_all_day'))}
            </label>
        </div>

        <!-- TIME ACTUALLY SPENT, as many sittings as it took. -->
        <div class="detail-field" id="taskTimeSection"></div>
        ` : ''}

        <!-- Checklists (discussion #138). Filled by loadTaskChecklists(); stays
             hidden unless Tasks → Settings → Checklists is switched on. -->
        <!-- The WORK view (GH #190 phase 2): the occurrence's working material in one
             place - progress and Complete, its instructions, the Knowledge procedures it
             follows, its checklists and the forms it needs. One block, so the tabs layout
             files it as the Work tab, columns put it in the wide column, and the panel
             shows it in line. Hidden unless one of the three is switched on. -->
        <div class="detail-field work-section" id="taskWorkSection" style="display:none">
            <div class="work-head" id="taskWorkHead"></div>
            <div class="work-instructions" id="taskWorkInstructions" style="display:none"></div>
            <div class="detail-field" id="taskProcedureSection" style="display:none"></div>
            <div class="detail-field" id="taskChecklistSection" style="display:none"></div>
            <div class="detail-field" id="taskFormsSection" style="display:none"></div>
            <div class="detail-field" id="taskFollowUpSection" style="display:none"></div>
        </div>

        <div class="detail-field">
            <label>${esc(window.t('tasks.detail.tags'))}</label>
            <div id="detailTagSection"></div>
        </div>

        <div class="detail-field detail-description">
            <label>${esc(window.t('tasks.detail.description'))}</label>
            <div id="descriptionEditor">${taskDescriptionHtml(task.description)}</div>
        </div>

        <!-- Repeats (#94) -->
        ${renderRecurrenceSection(task)}

        <!-- Links -->
        <div class="link-section">
            <h4>${esc(window.t('tasks.detail.links'))}</h4>
            <div id="linkList">
                ${task.ticket_id ? `<div class="link-item"><span class="link-type">${esc(window.t('tasks.detail.link_ticket'))}</span> ${linkedRecord(task.ticket_url, '#' + (task.ticket_number || task.ticket_id) + ' — ' + (task.ticket_subject || ''))}${taskPreviewBadge('ticket', task.ticket_id)}<button class="link-remove" onclick="removeLink('ticket_id')">&times;</button></div>` : ''}
                ${task.change_id ? `<div class="link-item"><span class="link-type">${esc(window.t('tasks.detail.link_change'))}</span> ${linkedRecord(task.change_url, task.change_title || (window.t('tasks.detail.link_change') + ' #' + task.change_id))}${taskPreviewBadge('change', task.change_id)}<button class="link-remove" onclick="removeLink('change_id')">&times;</button></div>` : ''}
            </div>
            <!--
                ⚠️ Shown even when something is ALREADY linked. A task holds one
                ticket and one change (they are single columns), so the box used
                to disappear the moment you filled it — which read as "you can
                only link changes here" rather than as "this is already full".
                It stays, and linking over the top asks first. (Ed)
            -->
            <div class="link-search-container">
                <input class="link-search-input" placeholder="${escAttr(window.t(
                    task.ticket_id ? 'tasks.detail.replace_ticket' : 'tasks.detail.search_tickets'))}"
                    oninput="searchLink(this.value, 'ticket')">
                <div class="link-search-results" id="ticketSearchResults"></div>
            </div>
            <div class="link-search-container">
                <input class="link-search-input" placeholder="${escAttr(window.t(
                    task.change_id ? 'tasks.detail.replace_change' : 'tasks.detail.search_changes'))}"
                    oninput="searchLink(this.value, 'change')">
                <div class="link-search-results" id="changeSearchResults"></div>
            </div>
        </div>


        <!-- Subtasks -->
        ${!task.parent_task_id ? `
        <div class="subtask-section">
            <h4>${esc(window.t('tasks.detail.subtasks'))}</h4>
            <div class="subtask-list" id="subtaskList" ondragover="subtaskDragOver(event)" ondrop="event.preventDefault()">
                ${(task.subtasks || []).length ? subtaskHeadHtml() : ''}
                ${(task.subtasks || []).map(subtaskRowHtml).join('')}
            </div>
            <div class="subtask-add">
                <input type="text" placeholder="${escAttr(window.t('tasks.detail.add_subtask'))}" id="newSubtaskInput" onkeydown="if(event.key==='Enter')addSubtask()">
                <input type="date" id="newSubtaskStart" class="subtask-add-due"
                       title="${escAttr(window.t('tasks.detail.start_date'))}"
                       onkeydown="if(event.key==='Enter')addSubtask()">
                <input type="date" id="newSubtaskDue" class="subtask-add-due"
                       title="${escAttr(window.t('tasks.detail.subtask_set_due'))}"
                       onkeydown="if(event.key==='Enter')addSubtask()">
                <!-- Enter still works. The button exists because picking a date
                     moves focus out of the text box, and without it the only way
                     to commit was to click back into the box first. -->
                <button type="button" class="subtask-add-btn" id="newSubtaskBtn" onclick="addSubtask()">${esc(window.t('tasks.detail.subtask_add'))}</button>
            </div>
        </div>` : ''}

        <!-- Comments -->
        <div class="comments-section">
            <h4>${esc(window.t('tasks.detail.comments'))}</h4>
            <div class="comment-list" id="commentList">
                ${(task.comments || []).map(c => `
                    <div class="comment-item">
                        <div class="comment-header">
                            <span class="comment-author">${esc(c.analyst_name)}</span>
                            <span class="comment-time">${formatDateTime(c.created_datetime)}</span>
                        </div>
                        <div class="comment-body">${esc(c.comment)}</div>
                    </div>
                `).join('')}
            </div>
            <div class="comment-add">
                <textarea id="newCommentInput" placeholder="${escAttr(window.t('tasks.detail.add_comment'))}" rows="3"></textarea>
                <button onclick="addComment()">${esc(window.t('tasks.detail.post'))}</button>
            </div>
        </div>

        <!-- Timestamps. An occurrence the repeat made says so (#181): it used to
             name whoever created the FIRST task, who did not make this one. -->
        <div class="detail-timestamps">
            <span>${esc(String(task.created_via || '').indexOf('repeat') === 0
                ? window.t('tasks.detail.created_by_repeat', { datetime: formatDateTime(task.created_datetime) })
                : window.t('tasks.detail.created_by', { datetime: formatDateTime(task.created_datetime), name: task.created_by_name || '' }))}</span>
            <span>${esc(window.t('tasks.detail.updated', { datetime: formatDateTime(task.updated_datetime) }))}</span>
            ${task.completed_datetime ? `<span>${esc(task.skipped_datetime
                ? window.t('tasks.detail.skipped_on', { datetime: formatDateTime(task.skipped_datetime) })
                : (task.completed_by_name
                    ? window.t('tasks.detail.completed_by', { datetime: formatDateTime(task.completed_datetime), name: task.completed_by_name })
                    : window.t('tasks.detail.completed', { datetime: formatDateTime(task.completed_datetime) })))}</span>` : ''}
        </div>
        <div class="detail-section" style="margin-top:18px;">
            <div id="taskDocuments"></div>
        </div>

        <!-- History (#181). Loaded after the panel is drawn - see loadTaskHistory(). -->
        <div class="history-section" id="taskHistorySection">
            <h4>${esc(window.t('tasks.history.heading'))}</h4>
            <div id="taskHistoryList" class="history-list"><div class="history-empty">${esc(window.t('tasks.history.loading'))}</div></div>
        </div>
    `;

    // In the large-window view, the SAME markup is regrouped into two columns.
    // Deliberately a rearrangement rather than a second template: two copies of
    // this panel would be two things to keep in step, and the one that is not
    // being looked at is the one that rots.
    if (taskViewIsModal()) applyModalLayout(body);

    renderTagSection();

    // Time actually spent (GH #112). Fetched rather than rendered from the task
    // payload, because the totals include the subtasks' entries and are worked
    // out by the server — the panel must not be a second place that knows how to
    // add up somebody's hours.
    if (timeAllowedFor(task)) {
        loadTaskTime(task.id);
    }

    // Attached documents (discussion #76). Mounted, not re-pointed — this panel
    // is rebuilt for every task, so the previous element is already gone.
    if (window.FreeITSMDocuments) {
        FreeITSMDocuments.mount(document.getElementById('taskDocuments'), {
            parentType: 'task',
            parentId:   task.id,
            apiBase:    '../api/documents/',
            showHeading: true      // nothing else in this panel names the section
        });
    }

    // Init TinyMCE for description
    if (tinyEditor) { tinyEditor.destroy(); tinyEditor = null; }
    // TinyMCE renders in an iframe with its own skin files, so it can't read
    // our CSS tokens — swap its bundled oxide-dark UI skin + dark content CSS
    // by the palette's declared mode (data-theme-mode on <html>). Any new
    // palette works with no change here.
    const tinyDark = (document.documentElement.getAttribute('data-theme-mode') || 'light') === 'dark';
    tinymce.init({
        target: document.getElementById('descriptionEditor'),
        license_key: 'gpl',
        menubar: false,
        statusbar: false,
        height: 200,
        skin: tinyDark ? 'oxide-dark' : 'oxide',
        content_css: tinyDark ? 'dark' : 'default',
        plugins: 'lists link',
        toolbar: 'bold italic underline | bullist numlist | link',
        content_style: 'body { font-family: Segoe UI, sans-serif; font-size: 13px; color: ' + (tinyDark ? '#e6e8eb' : '#333') + '; } @media (pointer: coarse) { body { font-size: 16px; } }',
        setup: editor => {
            tinyEditor = editor;
            editor.on('init', () => window.TaskEmbedBridge?.editorReady(editor));
            editor.on('change keyup', () => {
                clearTimeout(descSaveTimer);
                descSaveTimer = setTimeout(() => {
                    descSaveTimer = null;
                    saveField('description', editor.getContent());
                }, 1000);
            });
        }
    });
}

// ── Who else is on the task — "Involved" (GH #89) ──────────────────
//
// ⚠️ THE CODE SAYS "collaborator" AND THE SCREEN SAYS "Involved", on purpose.
// The API field and the table are English identifiers that are never translated;
// the visible word is, and the cognate of "collaborator" means a wartime traitor
// in German, Dutch, Danish, Norwegian, French, Polish and Russian — and in
// Ukrainian it is a live criminal charge rather than a historical term. The
// translation pipeline would have produced exactly that word in all nine.

let involvedState = { taskId: null, rows: [], candidates: [], completion: false, ownerId: null };
// The open task's status as the panel drew it - what a status change is changing FROM.
let detailTaskStatus = null;

/** Fetch and draw the Involved section for the open task. */
async function loadInvolved(taskId) {
    const host = document.getElementById('involvedList');
    if (!host) return;                       // a subtask — the section is not built
    try {
        const data = await fetch(API_BASE + 'collaborators.php?task_id=' + taskId).then(r => r.json());
        if (!data.success) { host.textContent = ''; return; }
        involvedState = {
            taskId,
            rows: data.collaborators || [],
            candidates: data.candidates || [],
            completion: !!data.completion_enabled,
            ownerId: involvedState.ownerId,
        };
        renderInvolved();
    } catch (e) {
        // Silent: an install mid-upgrade has no table yet, and an empty section is
        // the honest rendering of "there is nobody on this task".
        host.textContent = '';
    }
}

/**
 * Draws the Involved chips (with a done tick when that setting is on) and the add picker, built from
 * the server's candidate list.
 */
function renderInvolved() {
    const host = document.getElementById('involvedList');
    if (!host) return;
    const s = involvedState;

    const chips = s.rows.map(c => {
        // The tick is only OFFERED when the setting is on; the value is stored
        // either way, so switching the setting back on shows what was already
        // recorded rather than starting everybody from nothing.
        const tick = s.completion
            ? `<input type="checkbox" class="involved-tick" ${c.is_completed ? 'checked' : ''}
                      title="${escAttr(window.t('tasks.detail.involved_done'))}"
                      onchange="setInvolvedDone(${c.analyst_id}, this.checked)">`
            : '';
        return `<span class="involved-chip${c.is_completed && s.completion ? ' is-done' : ''}">
            ${tick}<span class="involved-chip-name">${esc(c.analyst_name)}</span>
            <button type="button" class="involved-chip-x"
                    title="${escAttr(window.t('tasks.detail.involved_remove'))}"
                    onclick="removeInvolved(${c.analyst_id})" aria-label="${escAttr(window.t('tasks.detail.involved_remove'))}">&times;</button>
        </span>`;
    }).join('');

    // ⚠️ The picker is built from the CANDIDATES the server sent, never from the
    // module's own `analysts` list. That list is everybody; the candidates have
    // already had the owner, the people already on the task, and — on a
    // multi-company install — analysts who cannot reach this task's company taken
    // out of them. Offering a name the save would refuse is the smaller problem;
    // offering a name from another company is a disclosure on its own.
    const options = s.candidates.map(a =>
        `<option value="${a.id}">${esc(a.name)}</option>`).join('');

    host.innerHTML = `
        <div class="involved-chips">${chips || `<span class="involved-empty">${esc(window.t('tasks.detail.involved_none'))}</span>`}</div>
        ${options ? `
        <select class="detail-select involved-add" onchange="addInvolved(this.value); this.value='';">
            <option value="">${esc(window.t('tasks.detail.involved_add'))}</option>
            ${options}
        </select>` : ''}`;
}

/**
 * Sends an Involved change (add, remove, done, undone) for the open task, then reloads the section and
 * the board; shows the server's error on a refusal.
 */
async function postInvolved(action, analystId) {
    if (!involvedState.taskId) return;
    try {
        const res = await fetch(API_BASE + 'collaborators.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_id: involvedState.taskId, analyst_id: Number(analystId), action })
        });
        const data = await res.json();
        if (!data.success) {
            showToast(data.error || window.t('tasks.toast.save_failed'), 'error');
            return;
        }
        // Re-read the candidate list too: adding somebody removes them from it,
        // and removing somebody puts them back.
        await loadInvolved(involvedState.taskId);
        loadTasks();                     // the board's chips and marks move with it
    } catch (e) {
        showToast(window.t('tasks.toast.save_failed'), 'error');
    }
}

/** Adds an analyst to the open task's Involved list. */
function addInvolved(analystId)    { if (analystId) postInvolved('add', analystId); }
/** Removes an analyst from the open task's Involved list. */
function removeInvolved(analystId) { postInvolved('remove', analystId); }
/** Ticks or unticks an involved analyst's part as done. */
function setInvolvedDone(analystId, done) { postInvolved(done ? 'done' : 'undone', analystId); }

// ── Field Save ─────────────────────────────────────────────────────

// Post a change and SAY SO IF IT DID NOT HAPPEN. Returns true on success.
//
// ⚠️ These calls used to be fired and forgotten — `await fetch(...)` with the
// answer never read — so a refused save was indistinguishable from a successful
// one and the analyst's typing was discarded in silence. That is what GH #107
// felt like from this screen: the session had been collected, every save was
// being refused, and the board simply stopped responding without ever saying
// why. #1260 fixed the sessions; this is so that the next thing to refuse a save
// cannot do it quietly.
//
// An expired session is named explicitly, because "failed to save" is no help
// when the real answer is "sign in again" — without that, an analyst retypes the
// same change and watches it vanish repeatedly.
async function postTaskChange(payload, failedKey) {
    let data;
    try {
        const res = await fetch(API_BASE + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        data = await res.json();
    } catch (e) {
        console.error(e);
        showToast(window.t(failedKey), 'error');
        return false;
    }
    if (data && data.success) { window.TaskEmbedBridge?.saved(payload); return true; }
    if (data && data.error === 'Not authenticated') {
        showToast(window.t('tasks.toast.session_expired'), 'error');
        return false;
    }
    showToast(window.t('tasks.toast.error_prefix', {
        message: (data && data.error) || window.t(failedKey)
    }), 'error');
    return false;
}

/* The companies this analyst can reach, for the panel's Company picker.
   Fetched once on load: the list does not change while somebody works, and a
   fetch per panel open would leave the select empty for the first frame.
   An empty list hides the field, which is the right way round - a picker that
   cannot work is worse than no picker. */
let moveCompanies = [];
let defaultTenantId = null;

/**
 * Fetches the companies this analyst can reach for the panel's Company picker, and works out the
 * Default company's id for tasks with no company.
 */
async function loadMoveCompanies() {
    try {
        const res = await fetch('../api/system/get_tenants.php?accessible=1');
        const data = await res.json();
        moveCompanies = (data && data.success && data.companies) ? data.companies : [];
        // A task with no company belongs to the Default one, so the picker has
        // to resolve NULL to a real id or it would show nothing selected.
        const def = moveCompanies.find(co => co.is_default);
        defaultTenantId = def ? def.id : (moveCompanies.length ? moveCompanies[0].id : null);
    } catch (e) {
        moveCompanies = [];
    }
}
loadMoveCompanies();

/**
 * Move the open task to the company just chosen in the panel.
 *
 * Deliberately NOT saveField(): this is its own endpoint, it takes the subtasks
 * with it, and it can be refused outright - a task linked to a ticket in
 * another company, for instance. On a refusal the select is put BACK to where
 * it was, because leaving it showing a company the task is not in would let
 * somebody walk away believing the move happened.
 */
async function moveToCompanyFromPanel(sel) {
    const id = selectedTaskId;
    const chosen = parseInt(sel.value, 10);
    const previous = sel.dataset.previous || '';
    if (!id || !chosen) return;
    sel.disabled = true;
    try {
        const res = await fetch(API_BASE + 'move_to_company.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_id: id, tenant_id: chosen })
        });
        const data = await res.json();
        if (!data.success) {
            if (previous) sel.value = previous;
            showToast(data.error || window.t('tasks.context.move_error'), 'error');
            return;
        }
        sel.dataset.previous = String(chosen);
        showToast(data.message || window.t('tasks.context.moved'), 'success');
        loadTasks();
    } catch (e) {
        if (previous) sel.value = previous;
        showToast(window.t('tasks.context.move_error'), 'error');
    } finally {
        sel.disabled = false;
    }
}
/**
 * Saves one field of the open task. A status change first asks about outstanding Involved ticks and
 * checklist steps, and redraws the panel if declined.
 */
async function saveField(field, value) {
    if (!selectedTaskId) return;
    if (field === 'status' && !(await confirmCloseWithInvolved(value))) return;
    if (field === 'status') {
        const current = detailTaskStatus;   // the status the open task had when the panel drew it
        if (!(await confirmChecklistCompletion(selectedTaskId, value, current))) {
            openDetailPanel(selectedTaskId);   // put the status box back
            return;
        }
    }
    await postTaskChange({ id: selectedTaskId, [field]: value }, 'tasks.toast.save_failed');
}

// ── Checklists on a task (discussion #138) ─────────────────────────
//
// Only while Tasks → Settings → Checklists is on; the endpoint says so and the
// section stays empty otherwise. Every rule - who may see it, whether a step
// needs a value, whether completing is allowed - is the server's
// (ChecklistsService). This code shows the state and asks the questions.

let checklistState = { taskId: null, enabled: false, checklists: [], templates: [] };

const ICON_LOCK = '<svg class="chk-lock" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>';

/** Fetches the open task's checklists and templates, hiding the section when checklists are switched off. */
async function loadTaskChecklists(taskId) {
    const host = document.getElementById('taskChecklistSection');
    if (!host) return;
    try {
        const data = await fetch(API_BASE + 'checklists.php?task_id=' + taskId).then(r => r.json());
        if (!data.success || !data.enabled) { host.innerHTML = ''; host.style.display = 'none'; workLoaded(taskId, 'checklists', false, 0); return; }
        checklistState = { taskId, enabled: true, checklists: data.checklists || [], templates: data.templates || [] };
        renderTaskChecklists();
        workLoaded(taskId, 'checklists', true, checklistState.checklists.length);
    } catch (e) { console.error(e); host.innerHTML = ''; host.style.display = 'none'; }
}

/**
 * Draws the task's checklists - steps with ticks, values and who did them, progress bars - and the
 * template picker for attaching another.
 */
function renderTaskChecklists() {
    const host = document.getElementById('taskChecklistSection');
    if (!host || checklistState.taskId !== selectedTaskId) return;
    host.style.display = '';
    /** Shorthand for a tasks.detail translation. */
    const T = (k, p) => window.t('tasks.detail.' + k, p);

    const lists = checklistState.checklists.map(c => {
        const critical = c.effective_closure_mode === 'block';
        const steps = (c.items || []).map(i => {
            const done = Number(i.is_completed) === 1;
            const needsValue = Number(i.requires_input) === 1;
            const by = done && i.completed_by_name
                ? `<div class="chk-step-by">${esc(T('checklist_done_by', { name: i.completed_by_name, when: formatDateTime(i.completed_datetime) }))}</div>` : '';
            const value = needsValue
                ? `<input type="text" class="chk-step-value" id="chkValue${i.id}" value="${esc(i.response_value || '')}"
                          placeholder="${esc(i.input_placeholder || T('checklist_value_placeholder'))}" ${done ? 'disabled' : ''}>` : '';
            // #181: a step that asks for a RESULT records Pass, Fail or Not applicable
            // instead of a bare tick - so a task can be done while saying the control
            // it checked failed. Fail asks for an exception note before it is saved.
            const asksResult = i.result_mode === 'pass_fail';
            const results = asksResult ? `
                <div class="chk-results" role="group" aria-label="${esc(T('checklist_result'))}">
                    ${['pass', 'fail', 'na'].map(r => `<button type="button" class="chk-result chk-result-${r}${i.result === r ? ' active' : ''}"
                        onclick="chooseTaskStepResult(${i.id}, '${r}')">${esc(T('checklist_result_' + r))}</button>`).join('')}
                    ${done ? `<button type="button" class="btn-link-small" onclick="postTaskChecklist({ action: 'toggle', item_id: ${i.id}, completed: 0 })">${esc(T('checklist_result_clear'))}</button>` : ''}
                </div>
                <div class="chk-exception" id="chkException${i.id}" style="display:none">
                    <textarea id="chkNote${i.id}" rows="2" placeholder="${esc(T('checklist_exception_placeholder'))}"></textarea>
                    <button type="button" class="btn btn-primary btn-sm" onclick="saveTaskStepFail(${i.id})">${esc(T('checklist_result_save'))}</button>
                </div>
                ${i.result === 'fail' && i.exception_note ? `<div class="chk-exception-note">${esc(T('checklist_exception', { note: i.exception_note }))}
                    <button type="button" class="btn-link-small chk-followup" onclick="followUpFrom('step', ${i.id}, ${escAttr(JSON.stringify(i.title))}, ${escAttr(JSON.stringify(i.exception_note))})">${esc(window.t('tasks.followups.raise'))}</button></div>` : ''}` : '';
            return `
                <div class="chk-step${done ? ' done' : ''}${i.result === 'fail' ? ' failed' : ''}" data-sort-id="${i.id}" draggable="true"
                     ondragstart="chkDragStart(event)" ondragend="chkDragEnd(event)">
                    <span class="chk-handle" title="${esc(T('checklist_drag'))}" onmousedown="this.closest('[data-sort-id]').dataset.grab = '1'" aria-hidden="true">&#8942;&#8942;</span>
                    ${asksResult ? '<span class="chk-result-spacer"></span>' : `<input type="checkbox" ${done ? 'checked' : ''} onchange="toggleTaskChecklistItem(${i.id}, this)">`}
                    <div class="chk-step-body">
                        <div class="chk-step-title">${esc(i.title)}
                            ${Number(i.is_mandatory) === 1 ? `<span class="chk-mandatory">${esc(T('checklist_mandatory'))}</span>` : ''}
                            ${i.suggested_role ? `<span class="chk-role">${esc(i.suggested_role)}</span>` : ''}
                        </div>
                        ${i.description ? `<div class="chk-step-desc">${esc(i.description)}</div>` : ''}
                        ${value}${results}${by}
                    </div>
                </div>`;
        }).join('');
        return `
            <div class="chk-card" data-sort-id="${c.id}" draggable="true"
                 ondragstart="chkDragStart(event)" ondragend="chkDragEnd(event)">
                <div class="chk-card-head">
                    <span class="chk-handle" title="${esc(T('checklist_drag'))}" onmousedown="this.closest('[data-sort-id]').dataset.grab = '1'" aria-hidden="true">&#8942;&#8942;</span>
                    <span class="chk-card-title">${critical ? `<span title="${esc(T('checklist_critical'))}">${ICON_LOCK}</span>` : ''}${esc(c.title)}</span>
                    <span class="chk-card-progress">${esc(T('checklist_progress', { done: c.completed_items, total: c.total_items }))}</span>
                    <button type="button" class="btn-link-small" onclick="removeTaskChecklist(${c.id})">${esc(T('checklist_remove'))}</button>
                </div>
                <div class="chk-bar"><div class="chk-bar-fill" style="width:${Number(c.percent) || 0}%"></div></div>
                <div class="chk-steps" data-sort="items" data-checklist-id="${c.id}" ondragover="chkDragOver(event)">${steps}</div>
            </div>`;
    }).join('');

    const options = checklistState.templates.map(t =>
        `<option value="${t.id}">${t.effective_closure_mode === 'block' ? '🔒 ' : ''}${esc(t.title)}${t.category ? ' (' + esc(t.category) + ')' : ''}</option>`
    ).join('');
    const attach = checklistState.templates.length
        ? `<div class="chk-attach">
               <select id="chkTemplatePick" class="detail-select"><option value="">${esc(T('checklist_choose'))}</option>${options}</select>
               <button type="button" class="btn btn-secondary btn-sm" onclick="attachTaskChecklist()">${esc(T('checklist_attach'))}</button>
           </div>`
        : `<div class="chk-empty">${esc(T('checklist_no_templates'))}</div>`;

    host.innerHTML = `
        <label>${esc(T('checklist_heading'))}</label>
        ${lists ? `<div class="chk-lists" data-sort="lists" ondragover="chkDragOver(event)">${lists}</div>`
                : `<div class="chk-empty">${esc(T('checklist_none'))}</div>`}
        ${attach}`;
}

// ── Re-ordering checklists, and the steps inside one, by dragging ───────────
// The same rule as subtasks: only the handle starts a drag. A step sits inside
// its checklist's card and both are draggable, so each handler acts only on a
// drag that began on ITS OWN element (e.target), and a container only re-orders
// rows that are its own children - a step can never be dropped into another
// checklist, nor a checklist among steps.
let chkDragRow = null;

/**
 * Starts dragging a checklist or step, but only when the drag began on its own handle and not on a
 * step inside it.
 */
function chkDragStart(e) {
    const row = e.currentTarget;
    if (e.target !== row) return;                    // a step inside this card
    if (row.dataset.grab !== '1') { e.preventDefault(); return; }
    chkDragRow = row;
    row.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', row.dataset.sortId); } catch (err) {}
}

/** Moves the dragged checklist or step to its new place as it passes over, only within its own container. */
function chkDragOver(e) {
    const box = e.currentTarget;
    if (!chkDragRow || chkDragRow.parentElement !== box) return;
    e.preventDefault();
    e.stopPropagation();
    const rows = Array.from(box.children).filter(el => el.dataset.sortId && el !== chkDragRow);
    const before = rows.find(r => {
        const b = r.getBoundingClientRect();
        return e.clientY < b.top + b.height / 2;
    });
    if (before) box.insertBefore(chkDragRow, before);
    else box.appendChild(chkDragRow);
}

/** Ends a checklist or step drag and saves the new order of the lists or of the steps in that checklist. */
function chkDragEnd(e) {
    const row = e.currentTarget;
    if (e.target !== row) return;
    delete row.dataset.grab;
    if (chkDragRow !== row) return;
    row.classList.remove('dragging');
    chkDragRow = null;
    const box = row.parentElement;
    const ids = Array.from(box.children).filter(el => el.dataset.sortId).map(el => Number(el.dataset.sortId));
    if (box.dataset.sort === 'lists') {
        postTaskChecklist({ action: 'reorder', task_id: selectedTaskId, ids });
    } else {
        postTaskChecklist({ action: 'reorder_items', checklist_id: Number(box.dataset.checklistId), ids });
    }
}

/**
 * Sends a checklist action to the server and redraws from the checklists it returns; on a refusal
 * shows the error and redraws to put the boxes back.
 */
async function postTaskChecklist(payload) {
    try {
        const data = await fetch(API_BASE + 'checklists.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        }).then(r => r.json());
        if (!data.success) {
            showToast(data.error || window.t('tasks.detail.checklist_failed'), 'error');
            renderTaskChecklists();      // put any box the server refused back
            return false;
        }
        checklistState.checklists = data.checklists || [];
        checklistState.templates  = data.templates || [];
        renderTaskChecklists();
        workLoaded(selectedTaskId, 'checklists', true, checklistState.checklists.length);
        if (selectedTaskId) loadTaskHistory(selectedTaskId);   // the step change is on the record now (#181)
        return true;
    } catch (e) {
        showToast(window.t('tasks.detail.checklist_failed'), 'error');
        renderTaskChecklists();
        return false;
    }
}

/** Attaches the checklist template chosen in the picker to the open task. */
function attachTaskChecklist() {
    const pick = document.getElementById('chkTemplatePick');
    if (!pick || !pick.value) return;
    postTaskChecklist({ action: 'attach', task_id: selectedTaskId, template_id: Number(pick.value) });
}

/** Ticks or unticks a checklist step, refusing to tick a step that needs a value while its box is empty. */
function toggleTaskChecklistItem(itemId, box) {
    const valueBox = document.getElementById('chkValue' + itemId);
    const value = valueBox ? valueBox.value.trim() : null;
    // The server refuses this too; asking here saves a round trip and keeps the
    // tick from flickering on and off.
    if (box.checked && valueBox && value === '') {
        box.checked = false;
        showToast(window.t('tasks.detail.checklist_value_needed'), 'error');
        valueBox.focus();
        return;
    }
    postTaskChecklist({ action: 'toggle', item_id: itemId, completed: box.checked ? 1 : 0, response_value: value });
}

/**
 * Records a step's result (#181). Pass and Not applicable save at once; Fail opens the exception note
 * first, because a failed control with no word on what went wrong is not evidence of anything.
 */
function chooseTaskStepResult(itemId, result) {
    if (result === 'fail') {
        const box = document.getElementById('chkException' + itemId);
        if (box) { box.style.display = ''; document.getElementById('chkNote' + itemId).focus(); }
        return;
    }
    postTaskChecklist({ action: 'result', item_id: itemId, result });
}

/** Saves a Fail result with its exception note, refusing an empty note here as the server does. */
function saveTaskStepFail(itemId) {
    const note = (document.getElementById('chkNote' + itemId).value || '').trim();
    if (!note) {
        showToast(window.t('tasks.detail.checklist_exception_needed'), 'error');
        return;
    }
    postTaskChecklist({ action: 'result', item_id: itemId, result: 'fail', exception_note: note });
}

/** Asks for confirmation, then removes a checklist from the open task. */
async function removeTaskChecklist(checklistId) {
    const c = checklistState.checklists.find(x => x.id == checklistId);
    if (!(await showConfirm({
        title: window.t('tasks.detail.checklist_remove'),
        message: window.t('tasks.detail.checklist_remove_confirm', { name: c ? c.title : '' }),
        okLabel: window.t('tasks.detail.checklist_remove'),
        okClass: 'danger'
    }))) return;
    postTaskChecklist({ action: 'remove', checklist_id: checklistId });
}

// ── Forms a task needs (GH #190) ─────────────────────────────────────────────
//
// Every rule is on the server (TaskForms): who may fill, who may read the answers,
// the revision check on the shared draft. This shows the state - required or not,
// submitted by whom, a draft in progress - and opens the form to fill it in.

let formState = { taskId: null, forms: [], available: [] };

/** Fetches the open task's forms, hiding the section while forms on tasks are switched off. */
async function loadTaskForms(taskId) {
    const host = document.getElementById('taskFormsSection');
    if (!host) return;
    try {
        const data = await fetch(API_BASE + 'forms.php?answers=1&task_id=' + taskId).then(r => r.json());
        if (!data.success || !data.enabled) { host.innerHTML = ''; host.style.display = 'none'; workLoaded(taskId, 'forms', false, 0); return; }
        formState = { taskId, forms: data.forms || [], available: data.available || [], open: formState.taskId === taskId ? (formState.open || {}) : {} };
        renderTaskForms();
        workLoaded(taskId, 'forms', true, formState.forms.length);
    } catch (e) { console.error(e); host.innerHTML = ''; host.style.display = 'none'; }
}

/** Draws each form the task needs - its requirement, who submitted or is drafting it, its answers - and the picker. */
function renderTaskForms() {
    const host = document.getElementById('taskFormsSection');
    if (!host || formState.taskId !== selectedTaskId) return;
    host.style.display = '';
    /** Shorthand for a tasks.forms translation. */
    const T = (k, p) => window.t('tasks.forms.' + k, p);

    const cards = formState.forms.map(f => {
        const req = !f.is_required ? 'optional' : f.requirement_mode;
        const state = f.submission_id
            ? `<span class="tf-state tf-done">${esc(T('submitted_by', { name: f.submitted_by || '-', when: formatDateTime(f.submitted_utc) }))}</span>`
            : f.has_draft
                ? `<span class="tf-state tf-draft">${esc(T('draft_by', { name: f.draft_by || '-', when: formatDateTime(f.draft_utc) }))}</span>`
                : `<span class="tf-state">${esc(T('not_started'))}</span>`;
        const answers = f.submission_id
            ? (f.may_read
                ? `<details class="tf-answers"><summary>${esc(T('answers'))}</summary>
                       <table>${(f.answers || []).map(a => `<tr${a.exception ? ' class="tf-exception"' : ''}><th>${esc(a.label)}</th><td>${esc(a.value)}</td></tr>`).join('')}</table>
                   </details>`
                : `<div class="tf-note">${esc(T('no_answer_access'))}</div>`)
            : '';
        const fill = !f.form_exists
            ? `<div class="tf-note">${esc(T('form_gone'))}</div>`
            : f.may_fill
                ? `<button type="button" class="btn btn-secondary btn-sm tf-fill" onclick="toggleTaskFormInline(${f.id})">${esc(T(formState.open && formState.open[f.id] ? 'close' : (f.submission_id ? 'correct' : 'fill')))}</button>`
                : `<div class="tf-note">${esc(T('need_forms_access'))}</div>`;
        return `
            <div class="tf-card${f.submission_id ? ' done' : ''}">
                <div class="tf-head">
                    <span class="tf-title">${req === 'block' ? `<span title="${esc(T('req_block'))}">${ICON_LOCK}</span>` : ''}${esc(f.title)}</span>
                    <select class="tf-req" onchange="setTaskFormRequirement(${f.id}, this.value)" aria-label="${esc(T('requirement'))}">
                        ${['block', 'warn', 'optional'].map(v => `<option value="${v}"${v === req ? ' selected' : ''}>${esc(T('req_' + v))}</option>`).join('')}
                    </select>
                    <button type="button" class="btn-link-small" onclick="removeTaskForm(${f.id})">${esc(T('remove'))}</button>
                </div>
                <div class="tf-row">${state}${fill}</div>
                ${f.submission_id && f.may_fill !== undefined ? `<button type="button" class="btn-link-small tf-followup" onclick="followUpFrom('form', ${f.submission_id}, ${escAttr(JSON.stringify(f.title))}, '')">${esc(window.t('tasks.followups.raise'))}</button>` : ''}
                ${formState.open && formState.open[f.id] ? `<div class="tf-inline">
                    <iframe class="tf-frame" id="tfFrame${f.id}" title="${esc(f.title)}" src="${(window.APP_BASE || '/')}forms/fill.php?embed=1&task_form=${f.id}"></iframe>
                    <a class="btn-link-small" href="#" onclick="openTaskForm(${f.id}); return false;">${esc(T('new_tab'))}</a>
                </div>` : ''}
                ${answers}
            </div>`;
    }).join('');

    // Not the ones already on the task (the server refuses a second copy too).
    const onTask = new Set(formState.forms.map(f => Number(f.form_id)));
    const offer = formState.available.filter(f => !onTask.has(Number(f.id)));
    const options = offer.map(f => `<option value="${f.id}">${esc(f.title)}</option>`).join('');
    const add = offer.length
        ? `<div class="tf-add">
               <select id="tfFormPick" class="detail-select"><option value="">${esc(T('choose'))}</option>${options}</select>
               <button type="button" class="btn btn-secondary btn-sm" onclick="addTaskForm()">${esc(T('add'))}</button>
           </div>`
        : `<div class="tf-empty">${esc(T('none_available'))}</div>`;

    host.innerHTML = `
        <label>${esc(T('heading'))}</label>
        ${cards || `<div class="tf-empty">${esc(T('none'))}</div>`}
        ${add}`;
}

/** Sends a forms action and redraws from what comes back; on a refusal says why and redraws. */
async function postTaskForm(payload) {
    try {
        const data = await fetch(API_BASE + 'forms.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        }).then(r => r.json());
        if (!data.success) {
            showToast(data.error || window.t('tasks.forms.failed'), 'error');
            renderTaskForms();
            return false;
        }
        formState.forms = data.forms || [];
        loadTaskForms(selectedTaskId);          // answers too - and the Work head with them
        if (selectedTaskId) loadTaskHistory(selectedTaskId);
        return true;
    } catch (e) {
        showToast(window.t('tasks.forms.failed'), 'error');
        renderTaskForms();
        return false;
    }
}

/** Adds the form chosen in the picker - required, blocking completion until it is submitted. */
function addTaskForm() {
    const pick = document.getElementById('tfFormPick');
    if (!pick || !pick.value) return;
    postTaskForm({ action: 'add', task_id: selectedTaskId, form_id: Number(pick.value), required: 1, mode: 'block' });
}

/** Required and blocking, required with a warning, or optional. */
function setTaskFormRequirement(taskFormId, value) {
    postTaskForm({ action: 'update', task_form_id: taskFormId, required: value === 'optional' ? 0 : 1, mode: value === 'warn' ? 'warn' : 'block' });
}

/** Asks first, then takes a form off the task. A submission it already has stays with the task. */
async function removeTaskForm(taskFormId) {
    const f = formState.forms.find(x => x.id == taskFormId);
    if (!(await showConfirm({
        title: window.t('tasks.forms.remove'),
        message: window.t(f && f.submission_id ? 'tasks.forms.remove_confirm_submitted' : 'tasks.forms.remove_confirm', { name: f ? f.title : '' }),
        okLabel: window.t('tasks.forms.remove'),
        okClass: 'danger'
    }))) return;
    postTaskForm({ action: 'remove', task_form_id: taskFormId });
}

/**
 * Opens the form to fill in for this occurrence, in a new tab. The fill page says when it has
 * saved or submitted, so this section redraws without anybody refreshing.
 */
function openTaskForm(taskFormId) {
    window.open((window.APP_BASE || '/') + 'forms/fill.php?task_form=' + taskFormId, '_blank');
}
try {
    new BroadcastChannel('freeitsm-task-forms').onmessage = e => {
        if (e.data && formState.taskId && Number(e.data.task_id) === Number(formState.taskId)) {
            // A submit closes the form in line; a draft save leaves it open where it is.
            if (e.data.submitted && formState.open) delete formState.open[e.data.task_form_id];
            loadTaskForms(formState.taskId);
            loadTaskHistory(formState.taskId);
        }
    };
} catch (e) { /* no BroadcastChannel: the section refreshes the next time the task opens */ }

/** Fills a form in line in the Work view (GH #190 phase 2), or closes it. The page inside is fill.php's, so nothing about filling is copied. */
function toggleTaskFormInline(taskFormId) {
    formState.open = formState.open || {};
    if (formState.open[taskFormId]) delete formState.open[taskFormId];
    else formState.open[taskFormId] = true;
    renderTaskForms();
}
// The embedded form says how tall it is, so the Work view never scrolls inside a scroll.
window.addEventListener('message', e => {
    if (e.origin !== location.origin || !e.data) return;
    // Saved or submitted in line: the same refresh the BroadcastChannel gives a separate tab.
    if (e.data.type === 'tf-saved' && formState.taskId && Number(e.data.task_id) === Number(formState.taskId)) {
        if (e.data.submitted && formState.open) delete formState.open[e.data.task_form_id];
        loadTaskForms(formState.taskId);
        loadTaskHistory(formState.taskId);
        return;
    }
    if (e.data.type !== 'tf-height') return;
    const frame = document.getElementById('tfFrame' + e.data.task_form_id);
    if (frame) {
        // The message describes the content viewport, not the iframe's border.
        const style = getComputedStyle(frame);
        const border = style.boxSizing === 'border-box'
            ? parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth) : 0;
        frame.style.height = (Math.max(200, Number(e.data.height) || 0) + border) + 'px';
    }
});

// ── The Work view (GH #190 phase 2) ──────────────────────────────────────────
//
// One block - progress and Complete, instructions, procedures, checklists, forms -
// assembled from sections that load on their own. Each loader reports in through
// workLoaded(); once all three have, the block shows if any is switched on, and a
// task with working material opens on its Work tab (a personal preference).

let workState = { taskId: null, task: null, loaded: {}, autoOpened: false };

/** One of the three sections has loaded: whether it is switched on, and how many things it holds. */
function workLoaded(taskId, part, enabled, count) {
    if (workState.taskId !== taskId) return;
    workState.loaded[part] = { enabled, count };
    renderWorkHead();
}

/** Draws the Work block's head - progress and Complete - and decides whether the block and its tab show. */
function renderWorkHead() {
    const section = document.getElementById('taskWorkSection');
    const head = document.getElementById('taskWorkHead');
    if (!section || !head || workState.taskId !== selectedTaskId) return;
    const L = workState.loaded;
    const parts = ['checklists', 'forms', 'procedures'];
    const anyOn = parts.some(p => L[p] && L[p].enabled);
    const material = parts.reduce((n, p) => n + ((L[p] && L[p].count) || 0), 0);
    const allIn = parts.every(p => L[p]);
    section.style.display = anyOn ? '' : 'none';
    const tabBtn = document.querySelector('.tdm-tab[data-tab="work"]');
    if (tabBtn) tabBtn.style.display = anyOn ? '' : 'none';
    if (!anyOn) return;

    /** Shorthand for a tasks.work translation. */
    const T = (k, p) => window.t('tasks.work.' + k, p);
    // Progress: every checklist step, and every REQUIRED form.
    let done = 0, total = 0;
    (checklistState.taskId === selectedTaskId ? checklistState.checklists : []).forEach(c => (c.items || []).forEach(i => {
        total++; if (Number(i.is_completed) === 1) done++;
    }));
    (formState.taskId === selectedTaskId ? formState.forms : []).filter(f => f.is_required).forEach(f => {
        total++; if (f.submission_id) done++;
    });
    const task = workState.task || {};
    const closed = Number(task.status_is_closed) === 1;
    const pct = total ? Math.round(done * 100 / total) : 0;
    head.innerHTML = `
        <div class="work-title">
            <label>${esc(T('heading'))}</label>
            ${task.scheduled_date ? `<span class="work-sub">${esc(T('for_day', { date: formatDate(task.scheduled_date) }))}</span>` : ''}
            ${task.period_start ? `<span class="work-sub">${esc(task.period_start === task.period_end
                ? T('checks_day', { date: formatDate(task.period_start) })
                : T('checks_range', { from: formatDate(task.period_start), to: formatDate(task.period_end) }))}</span>` : ''}
        </div>
        ${total ? `<div class="work-progress"><div class="chk-bar"><div class="chk-bar-fill" style="width:${pct}%"></div></div>
                   <span class="work-sub">${esc(T('progress', { done, total }))}</span></div>` : ''}
        <div class="work-actions">
            ${modalLayoutIsTabs() ? `<label class="work-open-pref" title="${esc(T('open_here_title'))}"><input type="checkbox" ${window.TASK_WORK_OPEN !== false ? 'checked' : ''} onchange="setWorkOpenPref(this.checked)"> ${esc(T('open_here'))}</label>` : ''}
            ${closed ? `<span class="work-sub">${esc(T('done_already'))}</span>`
                     : `<button type="button" class="btn btn-primary btn-sm work-complete" onclick="completeFromWork()">${esc(T('complete'))}</button>`}
        </div>`;

    // Instructions: the task's description, read-only - in the tabs layout only, where
    // the editable description sits on the Details tab out of sight.
    const ins = document.getElementById('taskWorkInstructions');
    if (ins) {
        const html = modalLayoutIsTabs() && task.description && typeof safeHtmlFragment === 'function' ? safeHtmlFragment(task.description) : '';
        ins.style.display = html ? '' : 'none';
        ins.innerHTML = html ? `<label>${esc(T('instructions'))}</label><div class="work-instructions-body">${html}</div>` : '';
    }

    // Open on the Work tab when the task has something to work through - once per opening.
    if (allIn && material && !workState.autoOpened && modalLayoutIsTabs() && window.TASK_WORK_OPEN !== false && tabBtn) {
        workState.autoOpened = true;
        showModalTab('work');
    }
}

/** Whether a task with work in it opens on its Work tab - a personal preference (GH #190 decision 1). */
async function setWorkOpenPref(on) {
    window.TASK_WORK_OPEN = !!on;
    try {
        const r = await fetch(APP_BASE + 'api/system/set_user_preference.php', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: 'tasks_work_open', value: on ? 'on' : 'off' })
        }).then(r => r.json());
        if (!r || !r.success) showToast(window.t('tasks.detail.view_not_saved'), 'error');
    } catch (e) { showToast(window.t('tasks.detail.view_not_saved'), 'error'); }
}

// ── Follow-ups: tickets and tasks that fix what a check found (GH #190 phase 3) ──

let followState = { taskId: null, items: [], mayTicket: false, source: null, ref: null };

/** Fetches the occurrence's follow-ups. Shown inside the Work view, so only while that is. */
async function loadTaskFollowUps(taskId) {
    const host = document.getElementById('taskFollowUpSection');
    if (!host) return;
    try {
        const d = await fetch(API_BASE + 'follow_ups.php?task_id=' + taskId).then(r => r.json());
        if (!d.success) { host.style.display = 'none'; return; }
        followState = { taskId, items: d.follow_ups || [], mayTicket: !!d.may_ticket, source: null, ref: null };
        renderTaskFollowUps();
    } catch (e) { console.error(e); host.style.display = 'none'; }
}

/** Draws the follow-ups with their live state, and the create / link row. */
function renderTaskFollowUps() {
    const host = document.getElementById('taskFollowUpSection');
    if (!host || followState.taskId !== selectedTaskId) return;
    host.style.display = '';
    /** Shorthand for a tasks.followups translation. */
    const T = (k, p) => window.t('tasks.followups.' + k, p);
    const base = window.APP_BASE || '/';
    const rows = followState.items.map(f => `
        <div class="fu-row">
            <span class="fu-num">${f.url ? `<a href="${escAttr(base + f.url)}" target="_blank" rel="noopener">${esc(f.number || '')}</a>` : esc(f.number || (f.kind + ' #' + f.record_id))}</span>
            <span class="fu-title">${f.visible ? esc(f.title || '') : esc(T('not_visible'))}</span>
            <span class="fu-state ${f.open === null ? '' : (f.open ? 'fu-open' : 'fu-closed')}">${esc(T(f.open === null ? 'gone' : (f.open ? 'open' : 'closed')))}</span>
            <button type="button" class="btn-link-small fu-remove" onclick="unlinkTaskFollowUp(${f.id})">${esc(T('remove'))}</button>
        </div>`).join('');
    const src = followState.source;
    host.innerHTML = `
        <label>${esc(T('heading'))}</label>
        ${rows || `<div class="tf-empty">${esc(T('none'))}</div>`}
        <div class="fu-add">
            ${src ? `<div class="work-sub">${esc(T('from_' + src.kind, { name: src.name }))}</div>` : ''}
            <div class="fu-line">
                <select id="fuKind" class="detail-select" aria-label="${esc(T('kind'))}">
                    <option value="task">${esc(T('kind_task'))}</option>
                    ${followState.mayTicket ? `<option value="ticket">${esc(T('kind_ticket'))}</option>` : ''}
                </select>
                <input type="text" id="fuTitle" class="detail-input" placeholder="${esc(T('title_placeholder'))}" value="${escAttr(src ? src.title : '')}">
                <button type="button" class="btn btn-secondary btn-sm" onclick="createTaskFollowUp()">${esc(T('create'))}</button>
            </div>
            <div class="fu-line">
                <input type="text" id="fuRef" class="detail-input" placeholder="${esc(T('link_placeholder'))}">
                <button type="button" class="btn btn-secondary btn-sm" onclick="linkTaskFollowUp()">${esc(T('link'))}</button>
            </div>
        </div>`;
}

/** Starts a follow-up from a failed step or a submitted form: prefilled, and remembered as its source. */
function followUpFrom(kind, id, name, note) {
    followState.source = { kind, id, name, title: window.t('tasks.followups.default_title', { name }) , note: note || '' };
    renderTaskFollowUps();
    const box = document.getElementById('fuTitle');
    if (box) { box.focus(); box.scrollIntoView({ block: 'center' }); }
}

/** Sends a follow-up action and redraws from what comes back. */
async function postTaskFollowUp(payload) {
    try {
        const d = await fetch(API_BASE + 'follow_ups.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        }).then(r => r.json());
        if (!d.success) { showToast(d.error || window.t('tasks.followups.failed'), 'error'); return false; }
        followState.items = d.follow_ups || [];
        followState.source = null;
        followState.ref = null;
        renderTaskFollowUps();
        loadTaskHistory(selectedTaskId);
        return true;
    } catch (e) { showToast(window.t('tasks.followups.failed'), 'error'); return false; }
}

/**
 * Raises a follow-up task or ticket. The client reference is kept until it succeeds, so pressing
 * Create again after a dropped connection returns the one already made rather than a second.
 */
function createTaskFollowUp() {
    const kind = (document.getElementById('fuKind') || {}).value || 'task';
    const title = ((document.getElementById('fuTitle') || {}).value || '').trim();
    const src = followState.source;
    followState.ref = followState.ref || ('fu-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8));
    postTaskFollowUp({ action: kind === 'ticket' ? 'create_ticket' : 'create_task', task_id: selectedTaskId, title,
                       note: src ? src.note : '', source_kind: src ? src.kind : null, source_id: src ? src.id : null,
                       client_reference: followState.ref });
}

/** Links an existing ticket (by number) or task (by number) as a follow-up. */
function linkTaskFollowUp() {
    const ref = ((document.getElementById('fuRef') || {}).value || '').trim();
    if (!ref) return;
    const kind = /^task-?\d+$/i.test(ref) || /^\d+$/.test(ref) && (document.getElementById('fuKind') || {}).value === 'task' ? 'task' : 'ticket';
    const src = followState.source;
    postTaskFollowUp({ action: 'link', task_id: selectedTaskId, kind, ref, source_kind: src ? src.kind : null, source_id: src ? src.id : null });
}

/** Removes a follow-up link. The ticket or task itself is untouched. */
function unlinkTaskFollowUp(id) { postTaskFollowUp({ action: 'unlink', follow_up_id: id }); }

/**
 * Complete, from the Work view: the first closed status, through saveField() - so the
 * same questions are asked (people involved, the completion check) as from the status box.
 */
async function completeFromWork() {
    const target = (statusList || []).find(s => Number(s.is_closed) === 1);
    if (!target) { showToast(window.t('tasks.context.no_status_for_that'), 'error'); return; }
    const id = selectedTaskId;
    await saveField('status', target.name);
    // Redraw the window: the head now says the task is complete, and the procedures show
    // the text they were completed against.
    if (id && selectedTaskId === id) await openDetailPanel(id);
}

// ── Procedures: Knowledge articles the task follows (GH #190 phase 2) ────────

let procState = { taskId: null, procedures: [], mayAdd: false };

/** Fetches the task's procedures, hiding the section while procedures on tasks are switched off. */
async function loadTaskProcedures(taskId) {
    const host = document.getElementById('taskProcedureSection');
    if (!host) return;
    try {
        const data = await fetch(API_BASE + 'procedures.php?task_id=' + taskId).then(r => r.json());
        if (!data.success || !data.enabled) { host.innerHTML = ''; host.style.display = 'none'; workLoaded(taskId, 'procedures', false, 0); return; }
        procState = { taskId, procedures: data.procedures || [], mayAdd: !!data.may_add };
        renderTaskProcedures();
        workLoaded(taskId, 'procedures', true, procState.procedures.length);
    } catch (e) { console.error(e); host.innerHTML = ''; host.style.display = 'none'; }
}

/** Draws each procedure - the article in line where the viewer may read it - and the picker. */
function renderTaskProcedures() {
    const host = document.getElementById('taskProcedureSection');
    if (!host || procState.taskId !== selectedTaskId) return;
    host.style.display = '';
    /** Shorthand for a tasks.work translation. */
    const T = (k, p) => window.t('tasks.work.' + k, p);
    const clean = h => typeof safeHtmlFragment === 'function' ? safeHtmlFragment(h || '') : esc(h || '');
    const cards = procState.procedures.map(p => `
        <div class="tp-card">
            <div class="tp-head">
                <span class="tp-title">${p.may_read ? esc(p.title) : esc(T('procedure_hidden'))}</span>
                ${p.may_read ? `<span class="work-sub">${esc(T('version', { n: p.version }))}</span>
                    <a class="btn-link-small" href="${(window.APP_BASE || '/')}knowledge/?article=${p.article_id}" target="_blank" rel="noopener">${esc(T('open'))}</a>` : ''}
                <button type="button" class="btn-link-small tp-remove" onclick="removeTaskProcedure(${p.id})">${esc(T('remove'))}</button>
            </div>
            ${p.frozen ? `<div class="work-sub">${esc(T('frozen', { n: p.frozen.version ?? '-', when: formatDateTime(p.frozen.at_utc) }))}</div>` : ''}
            ${p.may_read ? `<details class="tp-body"${procState.procedures.length === 1 ? ' open' : ''}><summary>${esc(T('read'))}</summary>
                <div class="tp-article">${clean(p.body)}</div></details>` : ''}
        </div>`).join('');
    const add = procState.mayAdd ? `
        <div class="tp-add">
            <input type="search" id="tpSearch" class="detail-input" placeholder="${esc(T('search'))}" oninput="searchTaskProcedures(this.value)" autocomplete="off">
            <div class="tp-results" id="tpResults"></div>
        </div>` : `<div class="tf-empty">${esc(T('need_knowledge'))}</div>`;
    host.innerHTML = `<label>${esc(T('procedures'))}</label>${cards || `<div class="tf-empty">${esc(T('no_procedures'))}</div>`}${add}`;
}

let tpSearchTimer = null;
/** Searches Knowledge for an article to add, as the analyst types. */
function searchTaskProcedures(q) {
    clearTimeout(tpSearchTimer);
    const box = document.getElementById('tpResults');
    if (!box) return;
    if (q.trim().length < 2) { box.innerHTML = ''; return; }
    tpSearchTimer = setTimeout(async () => {
        const d = await fetch(API_BASE + 'procedures.php?task_id=' + selectedTaskId + '&search=' + encodeURIComponent(q.trim())).then(r => r.json()).catch(() => null);
        if (!d || !d.success) return;
        box.innerHTML = (d.results || []).map(a => `<button type="button" class="tp-result" onclick="addTaskProcedure(${a.id})">${esc(a.title)}</button>`).join('')
            || `<div class="tf-empty">${esc(window.t('tasks.work.no_results'))}</div>`;
    }, 250);
}

/** Sends a procedures action and redraws from what comes back. */
async function postTaskProcedure(payload) {
    try {
        const d = await fetch(API_BASE + 'procedures.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        }).then(r => r.json());
        if (!d.success) { showToast(d.error || window.t('tasks.work.failed'), 'error'); return; }
        procState.procedures = d.procedures || [];
        renderTaskProcedures();
        workLoaded(selectedTaskId, 'procedures', true, procState.procedures.length);
        loadTaskHistory(selectedTaskId);
    } catch (e) { showToast(window.t('tasks.work.failed'), 'error'); }
}

/** Adds a Knowledge article to the task as a procedure. */
function addTaskProcedure(articleId) { postTaskProcedure({ action: 'add', task_id: selectedTaskId, article_id: articleId }); }

/** Takes a procedure off the task. */
function removeTaskProcedure(procedureId) { postTaskProcedure({ action: 'remove', procedure_id: procedureId }); }

/**
 * Before completing a task from ANY route in this page (the status box, a drag
 * into a closed column, a subtask tick): ask the server what completing it now
 * would run into. A Critical checklist with steps outstanding is refused here
 * with the list; a Standard one asks first. The server enforces the Critical
 * case regardless - this exists so nobody meets a bare refusal, and so a
 * Standard override is a decision rather than an accident.
 *
 * Returns true to go ahead. Anything that is not a completion goes ahead.
 */
async function confirmChecklistCompletion(taskId, newStatusName, currentStatusName) {
    /** True when the named status is a closed one. */
    const isClosed = name => !!((statusList || []).find(s => s.name === name) || {}).is_closed;
    if (!isClosed(newStatusName) || (currentStatusName && isClosed(currentStatusName))) return true;
    return confirmChecklistCompletionById(taskId);
}

/**
 * Asks the server what completing the task would run into: blocks with an alert for Critical steps
 * outstanding, asks to continue for Standard ones.
 *
 * Returns true to go ahead, including when the check cannot be reached or checklists are off.
 */
async function confirmChecklistCompletionById(taskId) {
    let data;
    try {
        data = await fetch(API_BASE + 'checklists.php?check=complete&task_id=' + taskId).then(r => r.json());
    } catch (e) { return true; }            // the server still enforces Critical
    // Not `!data.enabled`: that is the CHECKLIST switch, and the same check now also
    // covers required forms (GH #190), which can be on while checklists are off. The
    // server answers empty lists for whatever is switched off.
    if (!data || !data.success) return true;

    /** Formats checklist groups as bullet lines - the checklist name, then its outstanding steps. */
    const fmt = groups => Object.entries(groups || {})
        .map(([name, steps]) => '• ' + name + ': ' + steps.join(', ')).join('\n');
    if (data.blocking && Object.keys(data.blocking).length) {
        window.alert(window.t('tasks.detail.checklist_block', { list: fmt(data.blocking) }));
        return false;
    }
    if (data.warning && Object.keys(data.warning).length) {
        return window.confirm(window.t('tasks.detail.checklist_warn', { list: fmt(data.warning) }));
    }
    return true;
}

/**
 * Closing a task while people on it have not ticked their part off.
 *
 * 🔴 A WARNING, NEVER A BLOCK. Ticks are progress, not a gate: making the close
 * conditional on them would mean one person leaving makes a task permanently
 * uncloseable, and would hand every person on it a veto — which is co-ownership
 * again, the exact thing owner-plus-involved exists to avoid.
 *
 * Only asked when the per-person tick is switched ON. With the setting off there
 * are no ticks to be outstanding, so the question would be meaningless.
 */
async function confirmCloseWithInvolved(newStatusName) {
    if (!involvedState.completion || involvedState.taskId !== selectedTaskId) return true;
    const status = (statusList || []).find(s => s.name === newStatusName);
    if (!status || !status.is_closed) return true;

    const outstanding = involvedState.rows.filter(r => !r.is_completed).length;
    if (outstanding === 0) return true;

    return window.confirm(window.t('tasks.detail.involved_outstanding', { n: outstanding }));
}

// ── Scheduled work (GH #112) ───────────────────────────────────────
//
// ⚠️ NAIVE WALL CLOCK. What the input holds is what gets stored and what every
// reader sees: a 2pm slot means 2pm in Vienna and in London alike, exactly as a
// ticket's scheduled work behaves. So these values are sent AS TYPED and must
// never go through inputToUTC() — that helper is for the time entries below,
// which are instants. Sending one through the other is the GH #116 bug in
// reverse, and it would be silent.
function workValue(stored, allDay) {
    if (!stored) return '';
    const s = String(stored).replace(' ', 'T');
    return allDay == 1 ? s.substring(0, 10) : s.substring(0, 16);
}

// An all-day slot is a whole day, so a date-only box sends midnight to midnight
// rather than a time nobody chose.
function workPayload(value) {
    if (!value) return null;
    return value.length === 10 ? value + ' 00:00' : value.replace('T', ' ');
}

/**
 * Saves the scheduled work start as typed, and reopens the panel when it was cleared because that
 * clears the whole slot.
 */
async function saveWorkStart(value) {
    if (!selectedTaskId) return;
    // Clearing the start clears the whole slot, server-side. Reopen so the end
    // box does not sit there showing a time that no longer exists.
    const ok = await postTaskChange({ id: selectedTaskId, work_start_at: workPayload(value) }, 'tasks.toast.save_failed');
    if (ok && !value) openDetailPanel(selectedTaskId);
}

/**
 * Saves the scheduled work end as typed, and reopens the panel if the server refuses it so the stored
 * value shows again.
 */
async function saveWorkEnd(value) {
    if (!selectedTaskId) return;
    // The server refuses an end before the start, or an end with no start. When
    // it does, put the field back to what is actually stored rather than leaving
    // the rejected value on screen looking saved.
    const ok = await postTaskChange({ id: selectedTaskId, work_end_at: workPayload(value) }, 'tasks.toast.save_failed');
    if (!ok) openDetailPanel(selectedTaskId);
}

// ── Time spent (GH #112) ───────────────────────────────────────────

// "Log time" from the right-click menu. Opens the task and puts the cursor in
// the minutes box rather than inventing a second little dialog for it — one
// place where time is recorded, and you land looking at what is already there.
async function openTaskAndFocusTime(taskId) {
    await openDetailPanel(taskId);
    const box = document.getElementById('taskTimeMinutes');
    if (box) {
        box.scrollIntoView({ block: 'center' });
        box.focus();
    }
}

// Delete from the right-click menu, which may be a card that is not the one
// open in the panel — so it takes the id rather than using selectedTaskId.
async function deleteTaskById(taskId, task) {
    const name = (task && task.title) ? task.title : '#' + taskId;
    if (!(await showConfirm({
        title: window.t('tasks.context.delete'),
        message: window.t('tasks.context.delete_confirm', { title: name }),
        okLabel: window.t('tasks.context.delete'),
        okClass: 'danger'
    }))) return;
    try {
        const d = await fetch(API_BASE + 'delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: taskId })
        }).then(r => r.json());
        if (!d || !d.success) {
            showToast((d && d.error) || window.t('tasks.toast.delete_failed'), 'error');
            return;
        }
        // If the deleted task was the one on screen, the panel is now showing a
        // record that no longer exists.
        if (selectedTaskId === taskId) closeDetailPanel();
        showToast(window.t('tasks.toast.task_deleted'), 'success');
        loadTasks();
    } catch (e) {
        console.error(e);
        showToast(window.t('tasks.toast.delete_failed'), 'error');
    }
}

/** Fetches the task's time entries and totals and draws the Time section. */
async function loadTaskTime(taskId) {
    const el = document.getElementById('taskTimeSection');
    if (!el) return;
    let d;
    try {
        d = await fetch(API_BASE + 'get_time_entries.php?task_id=' + taskId).then(r => r.json());
    } catch (e) { console.error(e); return; }
    if (!d || !d.success) return;
    renderTaskTime(taskId, d);
}

/**
 * Draws the Time section - the total (plus the total including subtasks when they have time), the add
 * form, and each entry with delete on the viewer's own.
 */
function renderTaskTime(taskId, d) {
    const el = document.getElementById('taskTimeSection');
    if (!el) return;

    // Two totals, and only when they differ. "3h 30m · 6h 10m including
    // subtasks" on a task with no subtasks would just be the same number twice.
    //
    // ⚠️ The separator is built HERE, not inside the translated string. A
    // '&middot;' in the lang file goes through esc() with everything else and
    // renders as the literal text "&middot;" — which is exactly what it did
    // until the page was driven in a browser and read.
    // The heading is separated from the total by a middot, exactly as the ticket
    // panel reads: "Time · Total 30m". Without the word "Total" the number is
    // ambiguous — "Time 30m" could as easily be a name as a sum.
    const parts = [esc(window.t('tasks.time.total', { amount: formatMinutes(d.total_minutes) }))];
    if (d.subtask_minutes > 0) {
        parts.push(esc(window.t('tasks.time.total_with_subtasks', {
            amount: formatMinutes(d.total_with_subtasks_minutes)
        })));
    }
    const total = parts.join(' &middot; ');

    const rows = (d.entries || []).length
        ? d.entries.map(e => {
            // ⚠️ Bare ANALYST_ID, not window.ANALYST_ID: it is declared `const` at
            // the top of this file, and a const is NOT a property of window. The
            // window form reads undefined, so every row would lose its delete
            // button while looking perfectly fine.
            const mine = parseInt(e.analyst_id, 10) === parseInt(ANALYST_ID, 10);
            // entry_datetime IS an instant, so it goes through the timezone
            // helpers -- the opposite of the scheduled fields above.
            const when = formatDateTime(e.entry_datetime);
            return `
            <div class="time-entry-item">
                <div class="time-entry-row">
                    <span class="time-entry-spent">${esc(formatMinutes(e.time_spent_minutes))}</span>
                    <span class="time-entry-analyst">${esc(e.analyst_name || '')}</span>
                    <span class="time-entry-date">${esc(when)}</span>
                    ${mine ? `<button class="time-entry-delete" title="${escAttr(window.t('tasks.time.delete'))}"
                                onclick="deleteTaskTime(${e.id})">&times;</button>` : ''}
                </div>
                ${e.notes ? `<div class="time-entry-notes">${esc(e.notes)}</div>` : ''}
            </div>`;
        }).join('')
        : `<div class="time-entry-empty">${esc(window.t('tasks.time.empty'))}</div>`;

    // ⚠️ A <label>, which is what this used to be, is styled for FIELD NAMES:
    // 11px, uppercased and muted. The total was genuinely on screen and read as
    // "TIME 30M" — indistinguishable from a heading, and impossible to spot as a
    // number. It uses the ticket panel's own header rule instead, so the total
    // looks like a total and looks the same in both modules.
    el.innerHTML = `
        <div class="time-entries-header">${esc(window.t('tasks.time.heading'))} &middot; ${total}</div>
        <form class="time-entry-form" onsubmit="event.preventDefault(); addTaskTime(${taskId});">
            <input type="number" min="1" step="1" id="taskTimeMinutes" class="time-entry-input-minutes"
                   placeholder="${escAttr(window.t('tasks.time.minutes_placeholder'))}" required>
            <input type="text" id="taskTimeNotes" class="time-entry-input-notes"
                   placeholder="${escAttr(window.t('tasks.time.notes_placeholder'))}">
            <button type="submit" class="time-entry-add-btn">${esc(window.t('tasks.time.add'))}</button>
        </form>
        <div class="time-entry-list">${rows}</div>`;
}

/** Logs minutes and optional notes against the task and redraws the Time section. */
async function addTaskTime(taskId) {
    const minutes = parseInt(document.getElementById('taskTimeMinutes').value, 10);
    const notes   = document.getElementById('taskTimeNotes').value.trim();
    if (!minutes || minutes <= 0) {
        showToast(window.t('tasks.time.minutes_required'), 'error');
        return;
    }
    try {
        const d = await fetch(API_BASE + 'save_time_entry.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_id: taskId, time_spent_minutes: minutes, notes: notes })
        }).then(r => r.json());
        if (!d || !d.success) {
            showToast((d && d.error) || window.t('tasks.toast.save_failed'), 'error');
            return;
        }
        loadTaskTime(taskId);
    } catch (e) {
        console.error(e);
        showToast(window.t('tasks.toast.save_failed'), 'error');
    }
}

/** Asks for confirmation, deletes a time entry and redraws the open task's Time section. */
async function deleteTaskTime(entryId) {
    if (!(await showConfirm({
        title: window.t('tasks.time.delete'),
        message: window.t('tasks.time.delete_confirm'),
        okLabel: window.t('common.delete') || 'Delete',
        okClass: 'danger'
    }))) return;
    try {
        const d = await fetch(API_BASE + 'delete_time_entry.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: entryId })
        }).then(r => r.json());
        if (!d || !d.success) {
            showToast((d && d.error) || window.t('tasks.toast.delete_failed'), 'error');
            return;
        }
        if (selectedTaskId) loadTaskTime(selectedTaskId);
    } catch (e) {
        console.error(e);
        showToast(window.t('tasks.toast.delete_failed'), 'error');
    }
}

// ── Detail-panel tag picker ────────────────────────────────────────

// One tag chip; pass removable=true for the editable chips in the panel
function tagChipHtml(tag, removable) {
    const colour = tag.colour || '#6b7280';
    const x = removable
        ? `<button type="button" class="tag-chip-x" title="${escAttr(window.t('tasks.detail.remove_tag'))}"
             onclick="event.stopPropagation(); removeDetailTag(${tag.id})">&times;</button>`
        : '';
    return `<span class="tag-chip" style="background:${escAttr(colour)}1f;` +
        `color:${escAttr(colour)};border-color:${escAttr(colour)}55">${esc(tag.name)}${x}</span>`;
}

/** Draws the open task's tag chips and the tag picker box. */
function renderTagSection() {
    const el = document.getElementById('detailTagSection');
    if (!el) return;
    const chips = detailTags.map(tg => tagChipHtml(tg, true)).join('');
    el.innerHTML = `
        <div class="tag-edit-chips">${chips || `<span class="tag-edit-empty">${esc(window.t('tasks.tagpicker.none'))}</span>`}</div>
        <div class="tag-picker">
            <input type="text" id="tagPickerInput" class="tag-picker-input" placeholder="${escAttr(window.t('tasks.tagpicker.add'))}"
                   autocomplete="off" oninput="filterTagPicker()" onfocus="filterTagPicker()"
                   onkeydown="tagPickerKey(event)" onblur="setTimeout(closeTagPicker, 150)">
            <div class="tag-picker-results" id="tagPickerResults"></div>
        </div>`;
}

/** Closes the tag picker's results list. */
function closeTagPicker() {
    const r = document.getElementById('tagPickerResults');
    if (r) r.classList.remove('open');
}

/**
 * Lists the tags matching what is typed that the task does not already have, plus a create option for
 * a new name when tag creation is allowed.
 */
function filterTagPicker() {
    const input = document.getElementById('tagPickerInput');
    const results = document.getElementById('tagPickerResults');
    if (!input || !results) return;
    const q = input.value.trim().toLowerCase();
    const chosen = new Set(detailTags.map(t => t.id));
    const matches = tagList.filter(tg => !chosen.has(tg.id) && tg.name.toLowerCase().includes(q));

    let html = matches.map(tg =>
        `<div class="tag-pick-opt" onmousedown="event.preventDefault()" onclick="addDetailTag(${tg.id})">
            <span class="tag-swatch" style="background:${escAttr(tg.colour || '#6b7280')}"></span>${esc(tg.name)}
         </div>`).join('');

    // Offer to create the typed tag when allowed and it is genuinely new
    const exact = tagList.some(tg => tg.name.toLowerCase() === q);
    if (tagSettings.allow_create && q && !exact) {
        html += `<div class="tag-pick-opt tag-pick-create" onmousedown="event.preventDefault()"
                   onclick="createAndAddTag()">+ ${esc(window.t('tasks.tagpicker.create', { name: input.value.trim() }))}</div>`;
    }
    results.innerHTML = html || `<div class="tag-pick-empty">${esc(window.t('tasks.tagpicker.no_match'))}</div>`;
    results.classList.add('open');
}

// Enter picks the first option (an existing match, or the create row)
function tagPickerKey(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const first = document.querySelector('#tagPickerResults .tag-pick-opt');
    if (first) first.click();
}

/** Adds an existing tag to the open task and saves the tag list. */
function addDetailTag(tagId) {
    const tag = tagList.find(t => t.id === tagId);
    if (tag && !detailTags.some(t => t.id === tagId)) {
        detailTags.push({ id: tag.id, name: tag.name, colour: tag.colour });
        saveDetailTags();
    }
    renderTagSection();
    const input = document.getElementById('tagPickerInput');
    if (input) input.focus();
}

/** Removes a tag from the open task and saves the tag list. */
function removeDetailTag(tagId) {
    detailTags = detailTags.filter(t => t.id !== tagId);
    saveDetailTags();
    renderTagSection();
}

/**
 * Creates a new tag from the typed name with the next palette colour, adds it to the open task and
 * refreshes the sidebar Tag filter.
 */
async function createAndAddTag() {
    const input = document.getElementById('tagPickerInput');
    if (!input) return;
    const name = input.value.trim();
    if (!name) return;
    const colour = TAG_PALETTE[tagList.length % TAG_PALETTE.length];
    try {
        const data = await fetch(API_BASE + 'save_task_tag.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, colour, display_order: (tagList.length + 1) * 10 })
        }).then(r => r.json());
        if (data.success && data.id) {
            const tag = { id: data.id, name: name, colour: colour };
            tagList.push(tag);
            detailTags.push({ id: tag.id, name: tag.name, colour: tag.colour });
            saveDetailTags();
            applyTagSettings();        // refresh the sidebar filter list
            renderTagSection();
            const fresh = document.getElementById('tagPickerInput');
            if (fresh) fresh.focus();
        } else {
            // 'error', not 'success' - this is the failure branch.
            showToast(data.error || window.t('tasks.toast.tag_create_failed'), 'error');
        }
    } catch (e) { showToast(window.t('tasks.toast.tag_create_failed'), 'error'); }
}

/** Saves the open task's tag ids. */
function saveDetailTags() {
    saveField('tags', detailTags.map(t => t.id));
}

// ── Subtasks ───────────────────────────────────────────────────────

/**
 * Ticks or unticks a subtask; completing one first runs the checklist check. Reopens the panel
 * afterwards and shows any refusal.
 */
/**
 * Ticks a subtask done or open on the server - the one call behind the tick box in
 * the task window and the one on the board. Completing it can be stopped by its
 * own checklist (discussion #138); unticking never is. Says why when refused.
 *
 * @returns {Promise<boolean>} true when the server changed it
 */
async function postSubtaskToggle(id, completing) {
    if (completing && !(await confirmChecklistCompletionById(id))) return false;
    try {
        // The response used to be thrown away — not parsed, not checked. The
        // server could refuse outright and the only visible result was the panel
        // repainting with the box still empty and nothing in the console, which
        // is exactly how issue #88 was reported. A refusal must say so.
        const data = await fetch(API_BASE + 'toggle_subtask.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        }).then(r => r.json());
        if (!data.success) {
            showToast(data.error || window.t('tasks.toast.subtask_toggle_failed'), 'error');
            return false;
        }
        return true;
    } catch (e) {
        console.error(e);
        showToast(window.t('tasks.toast.subtask_toggle_failed'), 'error');
        return false;
    }
}

/** The tick box on a subtask row in the task window: change it, then redraw the window (which also un-ticks a refused box). */
async function toggleSubtask(id, completing) {
    const changed = await postSubtaskToggle(id, completing);
    if (selectedTaskId) openDetailPanel(selectedTaskId);
    // The board behind the window: its count, bar and list follow straight away.
    if (changed) loadTasks();
}

/**
 * The tick box on a subtask listed under its card on the board (Ed: it opened the
 * subtask instead). Completes or reopens it without opening anything - the click
 * stops at the box - then redraws the board so the card's count and list follow.
 */
async function boardToggleSubtask(event, id, completing) {
    event.stopPropagation();
    const box = event.currentTarget;
    if (box) box.disabled = true;
    await postSubtaskToggle(id, completing);
    loadTasks();
}

// ── Repeats (#94) ───────────────────────────────────────────────────────────
//
// A recurrence is a SERIES: the rule lives once, every occurrence points at it,
// and every occurrence points at the first task so a reader can get back to
// where it started. This section shows, in order of what people actually want
// to know: is this one of a series, which one, where did it start, and what is
// the rule.
//
// A SUBTASK never offers this. The series belongs to the piece of work, not a
// step inside it - the API refuses it too, this just does not tempt anyone.

const RECUR_WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

// The repeat mark. Used in the panel and on the calendar, so it is one shape
// wherever it appears - a reader should not have to learn two.
const ICON_RECUR = '<svg class="recur-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>';

/**
 * Returns the Repeats section for a top-level task - the rule summary, its place in the series, a link
 * back to the first task and the hidden editor.
 */
function renderRecurrenceSection(task) {
    if (task.parent_task_id) return '';
    const r  = task.recurrence;
    const on = !!(r && Number(r.is_active));

    // "3 of 12", or just "3" when the series has no end.
    let position = '';
    if (on && task.recurrence_position) {
        position = r.ends_mode === 'after_count' && r.max_occurrences
            ? window.t('tasks.recur.position_of', { n: task.recurrence_position, total: r.max_occurrences })
            : window.t('tasks.recur.position', { n: task.recurrence_position });
    }

    // The way back to the original. Only shown on a LATER occurrence - on the
    // first one it would be a link to the thing you are already looking at.
    const master = task.recurrence_master
        ? `<button type="button" class="recur-master-link" onclick="openDetailPanel(${task.recurrence_master.id})">
               ${ICON_RECUR}<span>${esc(window.t('tasks.recur.open_master'))}: ${esc(task.recurrence_master.title)}</span>
           </button>`
        : '';

    // Repeating task evidence (#181). The date the repeat made this occurrence FOR
    // never changes; when the due date has been moved since, the window says so -
    // a rescheduled deadline must stay visible, here as in the report.
    const sched = task.scheduled_date && task.recurrence_id
        ? `<div class="recur-scheduled">${esc(task.due_date && task.due_date !== task.scheduled_date
              ? window.t('tasks.evidence.rescheduled_from', { scheduled: formatDate(task.scheduled_date), due: formatDate(task.due_date) })
              : window.t('tasks.evidence.scheduled_for', { scheduled: formatDate(task.scheduled_date) }))}</div>`
        : '';
    const evidence = (task.can_skip || task.can_evidence)
        ? `<div class="recur-evidence">
               ${task.can_evidence ? `<a class="recur-link" href="evidence/report.php?series=${Number(task.recurrence_id)}" target="_blank" rel="noopener">${esc(window.t('tasks.evidence.open_report'))}</a>` : ''}
               ${task.can_skip ? `<button type="button" class="recur-link" onclick="toggleSkipForm()">${esc(window.t('tasks.evidence.skip'))}</button>` : ''}
           </div>
           ${task.can_skip ? `<div class="recur-skip" id="recurSkip" style="display:none">
               <label for="recurSkipReason">${esc(window.t('tasks.evidence.skip_reason'))}</label>
               <textarea id="recurSkipReason" rows="2" maxlength="255"></textarea>
               <div class="recur-skip-actions">
                   <button type="button" class="btn btn-secondary btn-sm" onclick="toggleSkipForm()">${esc(window.t('tasks.evidence.cancel'))}</button>
                   <button type="button" class="btn btn-primary btn-sm" onclick="skipCurrentOccurrence()">${esc(window.t('tasks.evidence.skip'))}</button>
               </div>
           </div>` : ''}`
        : '';

    return `
        <div class="recur-section" id="recurSection">
            <h4>
                ${esc(window.t('tasks.recur.heading'))}
                ${on ? `<span class="recur-badge">${ICON_RECUR}${esc(position)}</span>` : ''}
            </h4>
            <div class="recur-summary" id="recurSummary">
                <span>${on ? esc(describeRecurrence(r)) : esc(window.t('tasks.recur.none'))}</span>
                <button type="button" class="recur-link" onclick="toggleRecurrenceEditor()">
                    ${esc(window.t(on ? 'tasks.recur.change' : 'tasks.recur.set_up'))}
                </button>
            </div>
            ${sched}
            ${master}
            ${evidence}
            <div class="recur-editor" id="recurEditor" style="display:none">${renderRecurrenceEditor(r)}</div>
        </div>`;
}

/** Shows or hides the Skip form under Repeats (#181). */
function toggleSkipForm() {
    const el = document.getElementById('recurSkip');
    if (!el) return;
    const open = el.style.display === 'none';
    el.style.display = open ? '' : 'none';
    if (open) document.getElementById('recurSkipReason').focus();
}

/**
 * Skips the open occurrence with the reason given (#181 Part D): it closes as skipped, not done, and a
 * repeat set to "after completion" makes its next one, as completing would.
 */
async function skipCurrentOccurrence() {
    const reason = (document.getElementById('recurSkipReason').value || '').trim();
    if (!reason) {
        showToast(window.t('tasks.evidence.skip_reason_required'), 'error');
        return;
    }
    try {
        const d = await fetch(API_BASE + 'skip.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: selectedTaskId, reason })
        }).then(r => r.json());
        if (!d.success) { showToast(d.error || window.t('tasks.evidence.skip_failed'), 'error'); return; }
        showToast(window.t('tasks.evidence.skipped_toast'), 'success');
        openDetailPanel(selectedTaskId);
        loadTasks();
    } catch (e) {
        showToast(window.t('tasks.evidence.skip_failed'), 'error');
    }
}

/**
 * Loads and draws the task's History (#181): its events, the older audit rows and its checklist step
 * changes, newest first. Each line says who (or "the repeat"), what, and when.
 */
async function loadTaskHistory(taskId) {
    const host = document.getElementById('taskHistoryList');
    if (!host) return;
    const T = (k, p) => window.t('tasks.history.' + k, p);
    let d;
    try { d = await fetch(API_BASE + 'history.php?task_id=' + taskId).then(r => r.json()); }
    catch (e) { d = null; }
    if (selectedTaskId !== taskId) return;
    if (!d || !d.success) { host.innerHTML = `<div class="history-empty">${esc(T('failed'))}</div>`; return; }
    if (!d.history.length) { host.innerHTML = `<div class="history-empty">${esc(T('none'))}</div>`; return; }
    const who = h => h.who || T(h.actor_kind === 'system' ? 'by_system' : (h.actor_kind === 'workflow' ? 'by_workflow' : 'by_nobody'));
    const line = h => {
        const det = h.detail || {};
        if (h.kind === 'step') {
            const after = det.after || {};
            let what;
            if (h.type === 'result') what = T('step_result', { step: det.step, result: T('result_' + (after.result || 'none')) });
            else if (h.type === 'unticked') what = T('step_unticked', { step: det.step });
            else if (h.type === 'value') what = T('step_value', { step: det.step, value: after.response_value || '' });
            else what = T('step_ticked', { step: det.step });
            if (after.exception_note) what += ' - ' + after.exception_note;
            if (det.after_completion) what += ' ' + T('after_completion');
            return what;
        }
        if (h.kind === 'audit') return T('audit', { field: h.type, from: h.from || '-', to: h.to || '-' });
        switch (h.type) {
            case 'created':        return T(det.via && det.via.indexOf('repeat') === 0 ? 'created_' + det.via : 'created');
            case 'status_changed': return T('status_changed', { from: h.from || '-', to: h.to || '-' });
            case 'completed':      return T('completed', { due: h.to ? formatDate(h.to) : '-' });
            case 'reopened':       return T(det.was_skipped ? 'reopened_skip' : 'reopened');
            case 'skipped':        return T('skipped', { reason: h.to || '' });
            case 'due_date_changed':   return T('due_changed', { from: h.from ? formatDate(h.from) : '-', to: h.to ? formatDate(h.to) : '-' })
                                            + (det.task_closed ? ' ' + T('after_completion') : '');
            case 'start_date_changed': return T('start_changed', { from: h.from ? formatDate(h.from) : '-', to: h.to ? formatDate(h.to) : '-' });
            case 'assigned':       return T('assigned', { from: h.from || '-', to: h.to || '-' });
            case 'team_assigned':  return T('team_assigned', { from: h.from || '-', to: h.to || '-' });
            case 'successor':      return det.expected
                                        ? (det.existing_task_id ? T('successor_exists', { id: det.existing_task_id }) : T('successor', { due: h.to ? formatDate(h.to) : '-' }))
                                        : T('successor_none_' + (det.reason || 'stopped'));
            case 'checklist_attached': return T('checklist_attached', { name: h.to || '' }) + (det.after_completion ? ' ' + T('after_completion') : '');
            case 'checklist_removed':  return T('checklist_removed', { name: h.from || '' }) + (det.after_completion ? ' ' + T('after_completion') : '');
            case 'evidence_exported':  return T('evidence_exported', { what: h.to || '' });
            // GH #190 - the form's name only. Its answers follow the answers rule, not the history.
            case 'form_added':     return T('form_added', { name: h.to || '' });
            case 'form_removed':   return T('form_removed', { name: h.from || '' });
            case 'form_submitted': return T('form_submitted', { name: h.to || '' });
            case 'form_replaced':  return T('form_replaced', { name: h.to || '' });
            case 'procedure_added':   return T('procedure_added', { name: h.to || '' });
            case 'procedure_removed': return T('procedure_removed', { name: h.from || '' });
            case 'follow_up_created': return T('follow_up_created', { what: h.to || '' });
            case 'follow_up_linked':  return T('follow_up_linked', { what: h.to || '' });
            case 'follow_up_removed': return T('follow_up_removed', { what: h.from || '' });
        }
        return h.type;
    };
    host.innerHTML = d.history.map(h => `
        <div class="history-item history-${esc(h.kind)}">
            <div class="history-what">${esc(line(h))}</div>
            <div class="history-meta">${esc(who(h))} · ${esc(formatDateTime(h.at))}</div>
        </div>`).join('');
}

/** One sentence describing a rule, built from the same parts the editor sets. */
function describeRecurrence(r) {
    if (!r) return window.t('tasks.recur.none');
    const n = Number(r.interval_n) || 1;
    /** Shorthand for a tasks.recur translation. */
    const T = (k, p) => window.t('tasks.recur.' + k, p);

    let what;
    if (r.freq === 'daily')  what = n === 1 ? T('every_day')   : T('every_n_days',   { n });
    else if (r.freq === 'weekly') {
        const days = String(r.weekdays || '').split(',').filter(Boolean)
            .map(d => window.t('common.calendar.weekdays_short.' + ['', 'monday','tuesday','wednesday','thursday','friday','saturday','sunday'][Number(d)]))
            .join(', ');
        what = (n === 1 ? T('every_week') : T('every_n_weeks', { n })) + (days ? ' — ' + days : '');
    }
    else if (r.freq === 'monthly' || r.freq === 'yearly') {
        const base = r.freq === 'monthly'
            ? (n === 1 ? T('every_month') : T('every_n_months', { n }))
            : (n === 1 ? T('every_year')  : T('every_n_years',  { n }));
        let on;
        if (r.month_mode === 'nth') {
            const ord = Number(r.nth) === -1 ? T('ord_last') : T('ord_' + r.nth);
            const wd  = window.t('common.calendar.weekdays.' + ['', 'monday','tuesday','wednesday','thursday','friday','saturday','sunday'][Number(r.nth_weekday)]);
            on = T('on_nth_weekday', { ord, weekday: wd });
        } else if (Number(r.day_of_month) === -1) {
            on = T('on_last_day');
        } else if (r.day_of_month) {
            on = T('on_day', { day: r.day_of_month });
        }
        what = base + (on ? ' — ' + on : '');
    } else {
        what = '';
    }

    let ends = '';
    if (r.ends_mode === 'on_date' && r.ends_on)          ends = ' · ' + T('until', { date: r.ends_on });
    else if (r.ends_mode === 'after_count' && r.max_occurrences) ends = ' · ' + T('for_n', { n: r.max_occurrences });

    const mode = ' · ' + T(r.mode === 'schedule' ? 'mode_schedule_short' : 'mode_completion_short');
    return what + ends + mode;
}

/** Shows or hides the Repeats editor, applying the frequency rows on opening. */
function toggleRecurrenceEditor() {
    const el = document.getElementById('recurEditor');
    if (!el) return;
    const opening = el.style.display === 'none';
    el.style.display = opening ? '' : 'none';
    // The editor is built with every row present and hidden afterwards, so the
    // first paint has to run the same pass a change does - otherwise it opens
    // showing weekday boxes on a monthly rule.
    if (opening) recurEditorRefresh();
}

/** The editor. Only the rows that apply to the chosen frequency are shown. */
function renderRecurrenceEditor(r) {
    /** Shorthand for an escaped tasks.recur translation. */
    const T = k => esc(window.t('tasks.recur.' + k));
    const v = Object.assign({
        mode: 'completion', freq: 'weekly', interval_n: 1, weekdays: '',
        month_mode: 'dom', day_of_month: '', nth: 1, nth_weekday: 1, month_of_year: '',
        ends_mode: 'never', ends_on: '', max_occurrences: '',
        copy_description: 1, copy_subtasks: 1, copy_assignee: 1, copy_tags: 1,
        copy_links: 0, copy_attachments: 0,
        period_mode: 'none', due_offset_mode: 'same_day', due_offset_days: 0,
    }, r || {});
    /** Returns "selected" when the two values match. */
    const sel = (a, b) => String(a) === String(b) ? 'selected' : '';
    /** Returns "checked" when the value is truthy as a number. */
    const chk = x => Number(x) ? 'checked' : '';
    const days = String(v.weekdays || '').split(',').filter(Boolean).map(Number);
    /** Short weekday name for 1 (Monday) to 7 (Sunday). */
    const wdName = i => window.t('common.calendar.weekdays_short.' + ['', 'monday','tuesday','wednesday','thursday','friday','saturday','sunday'][i]);

    return `
      <div class="recur-grid">
        <label>${T('mode')}</label>
        <select id="recMode" class="detail-input">
            <option value="completion" ${sel(v.mode,'completion')}>${T('mode_completion')}</option>
            <option value="schedule"   ${sel(v.mode,'schedule')}>${T('mode_schedule')}</option>
        </select>

        <label>${T('repeats')}</label>
        <div class="recur-inline">
            <select id="recFreq" class="detail-input" onchange="recurEditorRefresh()">
                <option value="daily"   ${sel(v.freq,'daily')}>${T('freq_daily')}</option>
                <option value="weekly"  ${sel(v.freq,'weekly')}>${T('freq_weekly')}</option>
                <option value="monthly" ${sel(v.freq,'monthly')}>${T('freq_monthly')}</option>
                <option value="yearly"  ${sel(v.freq,'yearly')}>${T('freq_yearly')}</option>
            </select>
            <span>${T('every')}</span>
            <input type="number" id="recInterval" class="detail-input recur-n" min="1" max="365" value="${esc(v.interval_n)}">
        </div>

        <label class="recur-weekly">${T('on_days')}</label>
        <div class="recur-weekly recur-days">
            ${RECUR_WEEKDAYS.map(i => `
                <label class="recur-day">
                    <input type="checkbox" class="recDay" value="${i}" ${days.includes(i) ? 'checked' : ''}>
                    <span>${esc(wdName(i))}</span>
                </label>`).join('')}
        </div>

        <label class="recur-monthly">${T('on_the')}</label>
        <div class="recur-monthly recur-inline">
            <select id="recMonthMode" class="detail-input" onchange="recurEditorRefresh()">
                <option value="dom" ${sel(v.month_mode,'dom')}>${T('by_day_number')}</option>
                <option value="nth" ${sel(v.month_mode,'nth')}>${T('by_weekday')}</option>
            </select>
            <span class="recur-dom">
                <input type="number" id="recDom" class="detail-input recur-n" min="-1" max="31" value="${esc(v.day_of_month)}">
                <small>${T('day_hint')}</small>
            </span>
            <span class="recur-nth">
                <select id="recNth" class="detail-input">
                    ${[1,2,3,4,5].map(i => `<option value="${i}" ${sel(v.nth,i)}>${T('ord_' + i)}</option>`).join('')}
                    <option value="-1" ${sel(v.nth,-1)}>${T('ord_last')}</option>
                </select>
                <select id="recNthWd" class="detail-input">
                    ${RECUR_WEEKDAYS.map(i => `<option value="${i}" ${sel(v.nth_weekday,i)}>${esc(wdName(i))}</option>`).join('')}
                </select>
            </span>
        </div>

        <label class="recur-yearly">${T('in_month')}</label>
        <div class="recur-yearly">
            <select id="recMonth" class="detail-input">
                <option value="">${T('same_month')}</option>
                ${[1,2,3,4,5,6,7,8,9,10,11,12].map(m => `<option value="${m}" ${sel(v.month_of_year,m)}>${esc(window.t('common.calendar.months.' + ['','january','february','march','april','may','june','july','august','september','october','november','december'][m]))}</option>`).join('')}
            </select>
        </div>

        <label>${T('ends')}</label>
        <div class="recur-inline">
            <select id="recEnds" class="detail-input" onchange="recurEditorRefresh()">
                <option value="never"       ${sel(v.ends_mode,'never')}>${T('ends_never')}</option>
                <option value="on_date"     ${sel(v.ends_mode,'on_date')}>${T('ends_on_date')}</option>
                <option value="after_count" ${sel(v.ends_mode,'after_count')}>${T('ends_after')}</option>
            </select>
            <input type="date"   id="recEndsOn" class="detail-input recur-ends-date"  value="${esc(v.ends_on || '')}">
            <input type="number" id="recMax"    class="detail-input recur-n recur-ends-count" min="1" max="1000" value="${esc(v.max_occurrences || '')}">
        </div>

        <label>${T('carry_over')}</label>
        <div class="recur-copy">
            ${[['copy_description','carry_description'],['copy_subtasks','carry_subtasks'],
               ['copy_assignee','carry_assignee'],['copy_tags','carry_tags'],
               ['copy_links','carry_links'],['copy_attachments','carry_attachments']]
              .map(([k, label]) => `<label class="recur-day"><input type="checkbox" id="rec_${k}" ${chk(v[k])}><span>${T(label)}</span></label>`).join('')}
        </div>

        <!-- GH #190 phase 4: the period each occurrence checks, and when it is due. -->
        <label>${T('period_label')}</label>
        <div class="recur-row">
            <select id="recPeriod" class="detail-select">
                ${['none','scheduled_day','day_before','since_previous'].map(p => `<option value="${p}" ${sel(v.period_mode, p)}>${T('period_' + p)}</option>`).join('')}
            </select>
        </div>
        <label>${T('due_label')}</label>
        <div class="recur-row">
            <select id="recDueMode" class="detail-select" onchange="recurEditorRefresh()">
                ${['same_day','days','next_working_day'].map(p => `<option value="${p}" ${sel(v.due_offset_mode, p)}>${T('due_' + p)}</option>`).join('')}
            </select>
            <input type="number" id="recDueDays" class="detail-input recur-n recur-due-days" min="0" max="60" value="${esc(v.due_offset_days || 0)}">
            <span class="recur-due-days">${T('due_days_after')}</span>
        </div>
      </div>
      <div class="recur-actions">
        <button type="button" class="btn btn-primary" onclick="saveRecurrence()">${esc(window.t('common.save'))}</button>
        <button type="button" class="btn btn-secondary" onclick="previewRecurrence()">${esc(T('preview'))}</button>
        ${r && Number(r.is_active) ? `<button type="button" class="recur-link recur-stop" onclick="stopRecurrence()">${T('stop')}</button>` : ''}
      </div>`;
}

/** Show only the rows the chosen frequency and ending actually use. */
function recurEditorRefresh() {
    const root = document.getElementById('recurEditor');
    if (!root) return;
    const freq = (document.getElementById('recFreq') || {}).value || 'weekly';
    const mm   = (document.getElementById('recMonthMode') || {}).value || 'dom';
    const ends = (document.getElementById('recEnds') || {}).value || 'never';
    /** Shows or hides every editor element matching a selector. */
    const show = (sel, on) => root.querySelectorAll(sel).forEach(el => el.style.display = on ? '' : 'none');

    show('.recur-weekly',  freq === 'weekly');
    show('.recur-monthly', freq === 'monthly' || freq === 'yearly');
    show('.recur-yearly',  freq === 'yearly');
    show('.recur-dom',     mm === 'dom');
    show('.recur-nth',     mm === 'nth');
    show('.recur-ends-date',  ends === 'on_date');
    show('.recur-ends-count', ends === 'after_count');
    show('.recur-due-days', ((document.getElementById('recDueMode') || {}).value || 'same_day') === 'days');
}

/**
 * The editor's current settings, as the API wants them.
 *
 * Shared by Save and Preview, so the dates previewed are produced from exactly
 * the same values that would be saved. Reading the form twice, in two places,
 * is how a preview quietly stops describing the thing it is previewing.
 */
function recurrenceFormPayload() {
    /** The value of an editor field by id. */
    const val = id => (document.getElementById(id) || {}).value;
    /** Whether an editor checkbox is ticked, by id. */
    const on  = id => !!(document.getElementById(id) || {}).checked;
    return {
        task_id: selectedTaskId,
        mode: val('recMode'), freq: val('recFreq'),
        interval_n: parseInt(val('recInterval'), 10) || 1,
        weekdays: Array.from(document.querySelectorAll('.recDay:checked')).map(el => el.value).join(','),
        month_mode: val('recMonthMode'),
        day_of_month: val('recDom'), nth: val('recNth'), nth_weekday: val('recNthWd'),
        month_of_year: val('recMonth'),
        ends_mode: val('recEnds'), ends_on: val('recEndsOn'), max_occurrences: val('recMax'),
        copy_description: on('rec_copy_description'), copy_subtasks: on('rec_copy_subtasks'),
        copy_assignee: on('rec_copy_assignee'), copy_tags: on('rec_copy_tags'),
        copy_links: on('rec_copy_links'), copy_attachments: on('rec_copy_attachments'),
        period_mode: val('recPeriod') || 'none', due_offset_mode: val('recDueMode') || 'same_day',
        due_offset_days: parseInt(val('recDueDays'), 10) || 0,
    };
}

/**
 * Show the dates the current settings would produce, before committing to them
 * (Ed's idea). "Every second Tuesday of the month, 5 times" is not something
 * most people can turn into dates in their head, and without this the only way
 * to find out what a repeat does is to save it and wait.
 *
 * ⚠️ The dates come from the SERVER, from the same engine the cron runs.
 * Working them out here would be faster and would drift, and a preview that
 * disagrees with what actually happens is worse than no preview.
 */
async function previewRecurrence() {
    if (!selectedTaskId) return;
    try {
        const d = await fetch(API_BASE + 'recurrence_preview.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(recurrenceFormPayload()),
        }).then(r => r.json());
        if (!d.success) { showToast(d.error || window.t('tasks.toast.save_failed'), 'error'); return; }
        showRecurrencePreview(d);
    } catch (e) { showToast(window.t('tasks.toast.save_failed'), 'error'); }
}

/**
 * Opens the preview dialog listing the dates the repeat would produce, or explains that a
 * repeat-on-completion has no dates to list.
 */
function showRecurrencePreview(d) {
    /** Shorthand for a tasks.recur translation. */
    const T = (k, p) => window.t('tasks.recur.' + k, p);
    closeRecurrencePreview();

    const rows = (d.occurrences || []).map(o => {
        const marks = [];
        if (o.first)  marks.push(`<span class="recur-prev-tag">${esc(T('preview_this_task'))}</span>`);
        if (o.exists) marks.push(`<span class="recur-prev-tag recur-prev-tag-made">${esc(T('preview_created'))}</span>`);
        // Plain calendar dates, so they are formatted naively — converting them
        // to a display timezone would move somebody's due date by a day.
        const due   = fmtNaiveDate(o.due_date);
        const start = o.start_date ? fmtNaiveDate(o.start_date) : '';
        return `
            <tr${o.exists ? ' class="recur-prev-made"' : ''}>
                <td class="recur-prev-n">${o.n}</td>
                <td class="recur-prev-span">${esc(start)}</td>
                <td>${esc(due)}</td>
                <td class="recur-prev-marks">${marks.join(' ')}</td>
            </tr>`;
    }).join('');

    // A repeat that fires on completion has no dates to list: its next one is
    // counted from the day somebody actually finishes, which has not happened.
    // Saying so is honest; listing guesses would not be.
    const body = d.mode === 'schedule'
        ? `<table class="recur-prev-table">
             <thead><tr>
               <th></th>
               <th>${esc(T('preview_starts'))}</th>
               <th>${esc(T('preview_due'))}</th>
               <th></th>
             </tr></thead>
             <tbody>${rows}</tbody>
           </table>
           ${d.truncated ? `<p class="recur-prev-note">${esc(T('preview_truncated', { n: (d.occurrences || []).length }))}</p>` : ''}
           ${(d.occurrences || []).length < 2 ? `<p class="recur-prev-note recur-prev-warn">${esc(T('preview_none'))}</p>` : ''}`
        : `<p class="recur-prev-note">${esc(T('preview_completion_mode'))}</p>`;

    const el = document.createElement('div');
    el.className = 'modal-overlay recur-prev-overlay';
    el.id = 'recurPreviewOverlay';
    el.onclick = closeRecurrencePreview;
    el.innerHTML = `
        <div class="modal-box recur-prev-box" onclick="event.stopPropagation()">
            <h3>${esc(T('preview_heading'))}</h3>
            <p class="recur-prev-sub">${esc(d.title || '')}</p>
            ${body}
            <div class="recur-prev-actions">
                <button type="button" class="btn btn-secondary" onclick="closeRecurrencePreview()">${esc(window.t('common.close'))}</button>
            </div>
        </div>`;
    document.body.appendChild(el);
    document.addEventListener('keydown', recurPreviewEsc);
}

/** Closes the repeat preview on Escape. */
function recurPreviewEsc(e) { if (e.key === 'Escape') closeRecurrencePreview(); }

/** Removes the repeat preview dialog and its Escape listener. */
function closeRecurrencePreview() {
    const el = document.getElementById('recurPreviewOverlay');
    if (el) el.remove();
    document.removeEventListener('keydown', recurPreviewEsc);
}

/** Saves the Repeats editor's settings for the open task and reopens the panel. */
async function saveRecurrence() {
    if (!selectedTaskId) return;
    const payload = recurrenceFormPayload();
    try {
        const d = await fetch(API_BASE + 'save_recurrence.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(r => r.json());
        if (!d.success) { showToast(d.error || window.t('tasks.toast.save_failed'), 'error'); return; }
        showToast(window.t('tasks.recur.saved'), 'success');
        openDetailPanel(selectedTaskId);
    } catch (e) { showToast(window.t('tasks.toast.save_failed'), 'error'); }
}

/** Asks for confirmation, then switches the open task's repeat off and reopens the panel. */
async function stopRecurrence() {
    if (!selectedTaskId) return;
    // Says plainly what it does and does not do. "Stop" next to a list of tasks
    // reads as "delete them" to enough people to be worth a sentence.
    if (!(await showConfirm({
        title: window.t('tasks.recur.stop_title'),
        message: window.t('tasks.recur.stop_message'),
        okLabel: window.t('tasks.recur.stop'), okClass: 'danger',
    }))) return;
    try {
        const d = await fetch(API_BASE + 'save_recurrence.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_id: selectedTaskId, off: true }),
        }).then(r => r.json());
        if (!d.success) { showToast(d.error || window.t('tasks.toast.save_failed'), 'error'); return; }
        openDetailPanel(selectedTaskId);
    } catch (e) { showToast(window.t('tasks.toast.save_failed'), 'error'); }
}

/**
 * Give an existing subtask a due date, without opening it (#90).
 *
 * A subtask has always been able to carry one - it is the same record as a task
 * and the detail panel has always offered the field - but you had to open the
 * subtask to find it, so in practice none ever had a date and the calendar had
 * nothing to show. This is the shortcut, offered on the row only while the
 * subtask has no date; once it has one the row shows the ordinary due badge,
 * which carries the overdue colouring this input cannot.
 */
/**
 * One subtask row in the task window: drag handle, tick box, priority, title,
 * who has it, then Start and Due as columns that line up down the list.
 *
 * The dates are always editable in place. Changing one saves just that field
 * and does NOT rebuild the window (which would throw you back to the top, and
 * off the Subtasks tab) - the board catches up in the background.
 *
 * The propagation guard belongs on CLICK, not on CHANGE: ticking a checkbox
 * fires a click on the input, which bubbles to the row's onclick and used to
 * open the subtask instead of ticking it. Ticking a box must tick the box and
 * nothing else. "checked" comes from the is_closed FLAG, never from comparing
 * the status name to the English word Done.
 */
function subtaskRowHtml(s) {
    const assignee = s.analyst_name ? esc(s.analyst_name) : '';
    // Follows the same card placement, so a board set to pills reads the same
    // way inside a task.
    const priorityHtml   = TasksPriority.markup(s.priority, s.priority_colour, cardFields.priority);
    const priorityAccent = TasksPriority.accentAttrs(s.priority, s.priority_colour, cardFields.priority);
    /**
     * Returns a date box for a subtask's start or due date that saves on change without opening the
     * subtask.
     */
    const dateInput = (field, value, label) =>
        `<input type="date" class="subtask-date${value ? '' : ' is-empty'}" value="${escAttr(value || '')}"
                onclick="event.stopPropagation()"
                onchange="setSubtaskDate(${s.id}, '${field}', this)"
                title="${escAttr(label)}" aria-label="${escAttr(label)}">`;
    return `
    <div class="subtask-item" data-id="${s.id}" draggable="true"
         ondragstart="subtaskDragStart(event)" ondragend="subtaskDragEnd(event)"
         onclick="openDetailPanel(${s.id})"${priorityAccent}>
        <span class="subtask-handle" title="${escAttr(window.t('tasks.detail.subtask_drag'))}"
              onmousedown="this.closest('.subtask-item').dataset.grab = '1'"
              onclick="event.stopPropagation()" aria-hidden="true">&#8942;&#8942;</span>
        <input type="checkbox" ${s.status_is_closed ? 'checked' : ''}
               onclick="event.stopPropagation()"
               onchange="toggleSubtask(${s.id}, this.checked)">
        <span class="subtask-name">
            ${priorityHtml}
            <span class="subtask-title ${s.status_is_closed ? 'completed' : ''}">${esc(s.title)}</span>
        </span>
        <span class="subtask-assignee">${assignee}</span>
        ${dateInput('start_date', s.start_date, window.t('tasks.detail.start_date'))}
        ${dateInput('due_date', s.due_date, window.t('tasks.detail.due_date'))}
    </div>`;
}

// The column headings over the two date columns. Same grid as a row, so the
// headings sit exactly over their dates.
function subtaskHeadHtml() {
    return `<div class="subtask-head" aria-hidden="true">
        <span></span><span></span><span></span><span></span>
        <span>${esc(window.t('tasks.detail.start_date'))}</span>
        <span>${esc(window.t('tasks.detail.due_date'))}</span>
    </div>`;
}

/** Saves a subtask's start or due date from its row and refreshes the board in the background. */
async function setSubtaskDate(subtaskId, field, input) {
    input.classList.toggle('is-empty', !input.value);
    const ok = await postTaskChange({ id: subtaskId, [field]: input.value || null }, 'tasks.toast.save_failed');
    if (ok) loadTasks();
}

// ── Re-ordering subtasks by dragging ────────────────────────────────────────
// Only the handle starts a drag: the row is draggable, but a drag that did not
// begin on the handle is cancelled, so selecting a title's text or nudging the
// mouse while clicking a row still behaves as it always did. The order is
// saved as each subtask's board_position, which get.php already sorts by.
let subtaskDragRow = null;

/** Starts dragging a subtask row, but only when the drag began on its handle. */
function subtaskDragStart(e) {
    const row = e.currentTarget;
    if (row.dataset.grab !== '1') { e.preventDefault(); return; }
    subtaskDragRow = row;
    row.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', row.dataset.id); } catch (err) {}
}

/** Moves the dragged subtask row to its new place as it passes over the list. */
function subtaskDragOver(e) {
    if (!subtaskDragRow) return;
    e.preventDefault();
    const list = e.currentTarget;
    const rows = Array.from(list.querySelectorAll('.subtask-item:not(.dragging)'));
    const before = rows.find(r => {
        const box = r.getBoundingClientRect();
        return e.clientY < box.top + box.height / 2;
    });
    if (before) list.insertBefore(subtaskDragRow, before);
    else list.appendChild(subtaskDragRow);
}

/**
 * Ends a subtask drag and saves the new subtask order; on a refusal reopens the panel to show the
 * stored order.
 */
async function subtaskDragEnd(e) {
    const row = e.currentTarget;
    delete row.dataset.grab;
    if (!subtaskDragRow) return;
    subtaskDragRow.classList.remove('dragging');
    subtaskDragRow = null;
    const list = row.closest('.subtask-list');
    const ids = Array.from(list.querySelectorAll('.subtask-item')).map(r => Number(r.dataset.id));
    if (!ids.length || !selectedTaskId) return;
    try {
        const data = await fetch(API_BASE + 'reorder_subtasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ parent_id: selectedTaskId, ids })
        }).then(r => r.json());
        if (!data.success) {
            showToast(data.error || window.t('tasks.toast.save_failed'), 'error');
            openDetailPanel(selectedTaskId);   // put the list back as it is stored
            return;
        }
        loadTasks();   // the board lists subtasks in this order too
    } catch (err) {
        console.error(err);
        showToast(window.t('tasks.toast.save_failed'), 'error');
    }
}

// A mouse that went down on the handle but never dragged must not leave the
// row armed, or the next drag from anywhere on it would be let through.
document.addEventListener('mouseup', () => {
    document.querySelectorAll('[data-grab]').forEach(r => delete r.dataset.grab);
});

/**
 * Put one newly-created subtask into the list already on screen, without a
 * refetch (which is the thing addSubtask() exists to avoid).
 */
function appendSubtaskRow(sub) {
    const list = document.querySelector('.subtask-list');
    if (!list) return;
    const empty = list.querySelector('.subtask-empty');
    if (empty) empty.remove();
    if (!list.querySelector('.subtask-head')) list.insertAdjacentHTML('afterbegin', subtaskHeadHtml());
    const tab = document.querySelector('.tdm-tab[data-tab="subtasks"]');
    if (tab) setModalTabCount(tab, list.querySelectorAll('.subtask-item').length + 1);
    list.insertAdjacentHTML('beforeend', subtaskRowHtml({
        id: sub.id, title: sub.title, start_date: sub.start_date || null,
        due_date: sub.due_date || null, status_is_closed: 0
    }));
}

/**
 * Add a subtask to the task the panel is showing.
 *
 * 🔴 DOES NOT REBUILD THE PANEL. It used to call openDetailPanel(), which
 * refetches the task and re-renders everything - and, deliberately, resets the
 * scroll position to the top (right when you OPEN a task, wrong when you are
 * already in one). So adding a subtask threw you back to the title every time,
 * and the wait people read as "the database is slow" was the rebuild: measured
 * over HTTP, save.php is ~95ms and get.php ~20ms, which is not what three
 * seconds feels like.
 *
 * The new row is inserted into the list that is already on screen. Nothing else
 * moves, the scroll position is untouched, and the cursor goes back to the box
 * so several subtasks can be typed one after another.
 */
async function addSubtask() {
    const input = document.getElementById('newSubtaskInput');
    const btn   = document.getElementById('newSubtaskBtn');
    const title = input ? input.value.trim() : '';
    if (!title || !selectedTaskId) return;
    // Guard against a second click, and against Enter while the first is still
    // in flight - both would create the same subtask twice.
    if (btn && btn.disabled) return;

    const parentId = selectedTaskId;
    if (btn) { btn.disabled = true; btn.classList.add('working'); }
    if (input) input.disabled = true;

    try {
        // A due date is optional and only sent when one was typed, so a subtask
        // created without a date is stored exactly as it always was (#90).
        const dueEl   = document.getElementById('newSubtaskDue');
        const payload = { title, parent_task_id: parentId, assigned_analyst_id: ANALYST_ID };
        if (dueEl && dueEl.value) payload.due_date = dueEl.value;
        const startEl = document.getElementById('newSubtaskStart');
        if (startEl && startEl.value) payload.start_date = startEl.value;

        const data = await fetch(API_BASE + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(r => r.json());

        if (data.success) {
            input.value = '';
            if (dueEl) dueEl.value = '';
            if (startEl) startEl.value = '';
            // Someone may have clicked into a different task while this was in
            // flight; appending then would put the row under the wrong parent.
            if (selectedTaskId === parentId) {
                appendSubtaskRow({ id: data.id, title, start_date: payload.start_date || null, due_date: payload.due_date || null });
                input.focus();
            }
            // The board card shows "2/5", so it has to catch up - but in the
            // background, because nobody is waiting to look at it.
            loadTasks();
        } else {
            showToast(data.error || window.t('tasks.toast.save_failed'), 'error');
        }
    } catch (e) {
        console.error(e);
        showToast(window.t('tasks.toast.save_failed'), 'error');
    } finally {
        // 🔴 ALWAYS re-enable. Without this a dropped connection leaves the
        // box and the button disabled until the panel is reopened, and the
        // person cannot even retry what just failed.
        if (btn) { btn.disabled = false; btn.classList.remove('working'); }
        if (input) { input.disabled = false; }
    }
}

// ── Comments ───────────────────────────────────────────────────────

/** Posts a comment on the open task and appends it to the comment list. */
async function addComment() {
    const input = document.getElementById('newCommentInput');
    const comment = input.value.trim();
    if (!comment || !selectedTaskId) return;

    try {
        const data = await fetch(API_BASE + 'save_comment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_id: selectedTaskId, comment })
        }).then(r => r.json());

        if (data.success) {
            input.value = '';
            const list = document.getElementById('commentList');
            list.innerHTML += `
                <div class="comment-item">
                    <div class="comment-header">
                        <span class="comment-author">${esc(data.comment.analyst_name)}</span>
                        <span class="comment-time">${formatDateTime(data.comment.created_datetime)}</span>
                    </div>
                    <div class="comment-body">${esc(data.comment.comment)}</div>
                </div>`;
            list.scrollTop = list.scrollHeight;
        }
    } catch (e) { console.error(e); }
}

// ── Linking ────────────────────────────────────────────────────────

let searchTimer = null;

/**
 * Searches tickets or changes to link, 300ms after typing stops and from 2 characters, and lists the
 * results to pick from.
 */
async function searchLink(query, type) {
    clearTimeout(searchTimer);
    const resultsEl = document.getElementById(type + 'SearchResults');
    if (!query || query.length < 2) { resultsEl.classList.remove('open'); return; }

    searchTimer = setTimeout(async () => {
        try {
            const data = await fetch(API_BASE + 'search_links.php?type=' + type + '&q=' + encodeURIComponent(query)).then(r => r.json());
            if (data.success && data.results.length > 0) {
                resultsEl.innerHTML = data.results.map(r => {
                    if (type === 'ticket') {
                        return `<div class="link-search-result" onclick="linkItem('ticket_id', ${r.id})"><span class="result-id">#${esc(r.ticket_number)}</span> ${esc(r.subject)}</div>`;
                    } else {
                        return `<div class="link-search-result" onclick="linkItem('change_id', ${r.id})">${esc(r.title)}</div>`;
                    }
                }).join('');
                resultsEl.classList.add('open');
            } else {
                resultsEl.classList.remove('open');
            }
        } catch (e) { console.error(e); }
    }, 300);
}

/**
 * Link a ticket or a change to the open task.
 *
 * ⚠️ A task holds ONE of each — `ticket_id` and `change_id` are single columns,
 * not join tables — so linking when something is already there REPLACES it.
 * That is a quiet way to lose a link you meant to keep, so it asks first and
 * names what is about to go (Ed). The confirm is skipped when the slot is
 * empty, which is the ordinary case.
 */
async function linkItem(field, id) {
    const kind    = field === 'ticket_id' ? 'ticket' : 'change';
    const current = detailLinks[field];

    // Re-picking the one that is already linked is a no-op, not a replacement —
    // asking "replace X with X?" would be nonsense.
    if (current && String(current) !== String(id)) {
        const ok = await showConfirm({
            title:   window.t('tasks.detail.replace_' + kind + '_title'),
            message: window.t('tasks.detail.replace_' + kind + '_confirm', {
                current: detailLinks[kind + '_label'] || ('#' + current)
            }),
            okLabel: window.t('tasks.detail.replace_ok'),
            okClass: 'primary'
        });
        if (!ok) {
            // Put the picker away, or the results stay open over a change the
            // analyst just declined to make.
            const results = document.getElementById(kind + 'SearchResults');
            if (results) results.classList.remove('open');
            return;
        }
    }

    await saveField(field, id);
    openDetailPanel(selectedTaskId);
}

/** Clears the open task's linked ticket or change and reopens the panel. */
async function removeLink(field) {
    await saveField(field, null);
    openDetailPanel(selectedTaskId);
}

// ── Case report (GH #204) ──────────────────────────────────────────

/** Opens the case report for the open task - it and its subtasks as one printable record - in a new tab. */
function openTaskCaseReport() {
    if (!selectedTaskId) return;
    window.open((window.CASE_REPORT_URL || 'case-report.php') + '?id=' + encodeURIComponent(selectedTaskId), '_blank', 'noopener');
}

// ── Delete ─────────────────────────────────────────────────────────

/** Asks for confirmation, deletes the open task and closes the panel. */
async function deleteCurrentTask() {
    if (!selectedTaskId) return;
    if (!(await showConfirm({ title: 'Confirm', message: window.t('tasks.detail.delete_confirm'), okLabel: 'OK', okClass: 'primary' }))) return;

    try {
        const data = await fetch(API_BASE + 'delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: selectedTaskId })
        }).then(r => r.json());

        if (data.success) {
            closeDetailPanel();
            showToast(window.t('tasks.toast.task_deleted'), 'success');
        } else {
            // A refusal used to be silent - the window simply stayed open. It can
            // now be a deliberate one (#181: occurrences of a repeat kept as
            // evidence), which has to say so and what to do instead.
            showToast(data.error || window.t('tasks.toast.delete_failed'), 'error');
        }
    } catch (e) { console.error(e); }
}

// ── Lookups ────────────────────────────────────────────────────────

let statusList = [];
let priorityList = [];

// Active statuses and priorities — drive the board columns, the
// shared context menu (assets/js/tasks-ctx-menu.js), and the
// detail-panel dropdowns
// ── Projects in the task window (3.2.0) ────────────────────────────────

/**
 * The live projects the task window can file a task under. Only fetched for
 * analysts who can open Projects (TASK_CAN_PROJECTS) - the endpoint refuses
 * everyone else, and they see the project as plain text instead.
 */
async function loadProjectChoices() {
    if (!window.TASK_CAN_PROJECTS || projectChoices !== null) return;
    try {
        const d = await fetch(APP_BASE + 'api/projects/list.php').then(r => r.json());
        projectChoices = d.success ? (d.projects || []) : [];
    } catch (e) { projectChoices = []; }
}

/**
 * Top-level tasks only: a subtask belongs wherever its parent does. Offers the
 * live projects (plus the task's own, if that one has since finished), and links
 * to the project's page. The server refuses a project from another company.
 */
function projectFieldHtml(task) {
    const link = task.project_id && window.TASK_CAN_PROJECTS
        ? ` <a class="detail-project-open" href="${escAttr(APP_BASE + 'projects/view.php?id=' + task.project_id + '#plan')}">${esc(window.t('tasks.detail.open_project'))}</a>` : '';
    const stage = task.project_stage_name ? `<span class="detail-project-stage">${esc(task.project_stage_name)}</span>` : '';
    if (!window.TASK_CAN_PROJECTS) {
        if (!task.project_id) return '';
        return `<div class="detail-field"><label>${esc(window.t('tasks.detail.project'))}</label>
            <div class="detail-project-readonly"><span class="task-card-project-dot" style="--pc:${escAttr(task.project_colour || '#e11d48')}"></span>${esc(task.project_name || '')} ${stage}</div></div>`;
    }
    const live = (projectChoices || []).filter(p => !['closed', 'cancelled'].includes(p.status) || String(p.id) === String(task.project_id));
    const opts = `<option value="">${esc(window.t('tasks.detail.no_project'))}</option>`
        + live.map(p => `<option value="${p.id}"${String(p.id) === String(task.project_id) ? ' selected' : ''}>${esc(p.code + ' ' + p.name)}</option>`).join('')
        + (task.project_id && !live.some(p => String(p.id) === String(task.project_id))
            ? `<option value="${task.project_id}" selected>${esc(task.project_name || ('#' + task.project_id))}</option>` : '');
    return `<div class="detail-field">
        <label>${esc(window.t('tasks.detail.project'))}${link}</label>
        <select class="detail-select" data-previous="${task.project_id || ''}" onchange="setTaskProject(${task.id}, this)">${opts}</select>
        ${stage ? `<div class="detail-project-under">${esc(window.t('tasks.detail.project_stage'))} ${stage}</div>` : ''}
    </div>`;
}

// ── Contractor (3.3.0) - includes/task_contractors.php ──────────────────────────

/**
 * Fetches the suppliers and their contacts once per page; false when this analyst may not choose a
 * contractor.
 */
async function loadContractorChoices() {
    if (contractorChoices !== null) return;
    try {
        const d = await fetch(API_BASE + 'contractors.php').then(r => r.json());
        contractorChoices = d.success && d.allowed && d.ready !== false ? (d.suppliers || []) : false;
    } catch (e) { contractorChoices = false; }
}

/**
 * A supplier doing the work, and optionally the person there. Choosing one needs
 * Contracts (where suppliers live); without it the field only names the contractor.
 * The assignee above stays the person here who chases it.
 */
function contractorFieldHtml(task) {
    const label = `<label>${esc(window.t('tasks.detail.contractor'))}</label>`;
    if (!contractorChoices) {
        if (!task.supplier_id) return '';
        return `<div class="detail-field">${label}<div class="detail-project-readonly">${esc(task.supplier_name)}${task.contact_name ? ' &middot; ' + esc(task.contact_name) : ''}</div></div>`;
    }
    const sup = contractorChoices.find(s => String(s.id) === String(task.supplier_id));
    const supOpts = `<option value="">${esc(window.t('tasks.detail.no_contractor'))}</option>`
        + contractorChoices.map(s => `<option value="${s.id}"${String(s.id) === String(task.supplier_id) ? ' selected' : ''}>${esc(s.name)}</option>`).join('');
    const conOpts = `<option value="">${esc(window.t('tasks.detail.no_contact'))}</option>`
        + ((sup && sup.contacts) || []).map(c => `<option value="${c.id}"${String(c.id) === String(task.contact_id) ? ' selected' : ''}>${esc(c.name + (c.job_title ? ' - ' + c.job_title : '') + (c.has_email ? '' : ' (' + window.t('tasks.detail.no_email') + ')'))}</option>`).join('');
    return `<div class="detail-row">
        <div class="detail-field">${label}
            <select class="detail-select" onchange="setTaskContractor(${task.id}, this.value, null)">${supOpts}</select>
        </div>
        <div class="detail-field"><label>${esc(window.t('tasks.detail.contractor_contact'))}</label>
            <select class="detail-select" ${task.supplier_id ? '' : 'disabled'} onchange="setTaskContractor(${task.id}, ${task.supplier_id || 'null'}, this.value)">${conOpts}</select>
        </div>
    </div>`;
}

/** Saves a task's contractor and contact, then reloads the board and reopens the task. */
async function setTaskContractor(taskId, supplierId, contactId) {
    try {
        const r = await fetch(API_BASE + 'save.php', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: taskId, assigned_supplier_id: supplierId || null, assigned_contact_id: contactId || null }),
        }).then(x => x.json());
        if (!r.success) throw new Error(r.error || 'Failed');
        if (typeof showToast === 'function') showToast(window.t(supplierId ? 'tasks.detail.contractor_set' : 'tasks.detail.contractor_cleared'), 'success');
        await loadTasks();
        openDetailPanel(taskId);
    } catch (e) {
        if (typeof showToast === 'function') showToast(e.message, 'error');
        openDetailPanel(taskId);
    }
}

/**
 * Files a task under a project (or none), then reloads the board and reopens the task; on a refusal
 * puts the dropdown back.
 */
async function setTaskProject(taskId, sel) {
    const value = sel.value;
    try {
        const r = await fetch(APP_BASE + 'api/projects/task_assign.php', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_id: taskId, project_id: value || null, stage_id: null }),
        }).then(x => x.json());
        if (!r.success) throw new Error(r.error || 'Failed');
        sel.dataset.previous = value;
        if (typeof showToast === 'function') showToast(window.t(value ? 'tasks.detail.project_set' : 'tasks.detail.project_cleared'), 'success');
        await loadTasks();
        openDetailPanel(taskId);
    } catch (e) {
        sel.value = sel.dataset.previous || '';
        if (typeof showToast === 'function') showToast(e.message, 'error');
    }
}

/** Fetches the task statuses, priorities and tags, keeping only the active statuses and priorities. */
async function loadLookups() {
    try {
        const [sRes, pRes, tRes] = await Promise.all([
            fetch(API_BASE + 'get_task_statuses.php').then(r => r.json()),
            fetch(API_BASE + 'get_task_priorities.php').then(r => r.json()),
            fetch(API_BASE + 'get_task_tags.php').then(r => r.json())
        ]);
        if (sRes.success) statusList = (sRes.statuses || []).filter(s => s.is_active);
        if (pRes.success) priorityList = (pRes.priorities || []).filter(p => p.is_active);
        if (tRes.success) tagList = tRes.tags || [];
    } catch (e) { console.error('Failed to load lookups:', e); }
}

// Escape a value for safe use inside an HTML attribute
function escAttr(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

// ── Utilities ──────────────────────────────────────────────────────

/**
 * Render a reference to another record as a link when there is somewhere to go,
 * and as plain text when there is not (GH #91).
 *
 * ⚠️ The URL is supplied by the SERVER, from entityLink() in
 * includes/entity_links.php. Do not rebuild it here. Three separate copies of
 * that map had already drifted apart, and the two links that turned out to be
 * dead were both hand-built ones pointing at the two modules that accept exactly
 * one parameter name each.
 *
 * A missing url renders as text rather than href="#": an anchor that goes
 * nowhere is worse than no anchor, because it looks like it should work.
 */
/**
 * The ⓘ preview badge (#91). Guarded, so a page that somehow loaded without
 * record-preview.js loses the preview rather than the whole detail pane.
 */
function taskPreviewBadge(type, id) {
    return window.FreeITSMPreview ? window.FreeITSMPreview.badge(type, id) : '';
}

/** Returns a link to another record when the server gave a URL, or plain escaped text when it did not. */
function linkedRecord(url, label) {
    if (!url) return esc(label);
    const base = window.APP_BASE || '';
    return `<a class="linked-record" href="${escAttr(base + url)}">${esc(label)}</a>`;
}

/** Escapes text for safe use in HTML. */
/**
 * A task description as markup that is safe to insert. It is rich text, so it is
 * cleaned rather than escaped - formatting and pasted screenshots survive, event
 * handlers and scripts do not.
 *
 * TRAP: never put task.description into innerHTML or a template directly.
 * `<img src=x onerror=...>` runs the moment it is inserted - the editor is no
 * protection. Fails closed (shown as text) if safe-html.js is not loaded.
 */
function taskDescriptionHtml(html) {
    if (!html) return '';
    return typeof safeHtmlFragment === 'function' ? safeHtmlFragment(html) : esc(html);
}

function esc(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/**
 * Formats a stored UTC datetime in the analyst's display timezone, returning the raw value if it
 * cannot be parsed.
 */
function formatDateTime(dt) {
    if (!dt) return '';
    // (formatDate below is the bare-date twin.)
    // Stored UTC → render in the analyst's chosen display zone (parseUTCDate /
    // tzOpts from assets/js/tz.js). Used for comment/created/updated/completed
    // timestamps — all true datetimes.
    const d = parseUTCDate(dt);
    if (!d || isNaN(d)) return dt;
    return fmtDateTime(d);
}

/**
 * Formats a bare calendar date (a due or scheduled date, YYYY-MM-DD) in the analyst's date format -
 * NOT converted between zones, because "the 1st" is the 1st wherever the reader is.
 */
function formatDate(ymd) {
    if (!ymd) return '';
    return window.fmtNaiveDate ? fmtNaiveDate(String(ymd).slice(0, 10)) : String(ymd).slice(0, 10);
}

