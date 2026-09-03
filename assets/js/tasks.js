/**
 * FreeITSM Tasks Module — Board, List, Detail Panel, Drag & Drop
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
    buildBoardColumns();
    applyTagSettings();
    // Open a task straight away if linked from the calendar/timeline (?task=N)
    loadDropdowns().then(() => {
        const taskParam = new URLSearchParams(location.search).get('task');
        if (taskParam) openDetailPanel(parseInt(taskParam, 10));
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

async function loadDropdowns() {
    try {
        const [aRes, tRes] = await Promise.all([
            fetch(API_BASE + 'list.php?analysts=1').then(r => r.json()),
            fetch(API_BASE + 'list.php?teams=1').then(r => r.json())
        ]);
        if (aRes.success) {
            analysts = aRes.analysts;
            const sel = document.getElementById('analystFilter');
            sel.innerHTML = '<option value="">' + esc(window.t('tasks.filter.all_analysts')) + '</option>' +
                analysts.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
        }
        if (tRes.success) {
            teams = tRes.teams;
            const sel = document.getElementById('teamFilter');
            sel.innerHTML = '<option value="">' + esc(window.t('tasks.filter.all_teams')) + '</option>' +
                teams.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
        }
    } catch (e) { console.error('Failed to load dropdowns:', e); }
}

async function loadTasks() {
    let url = API_BASE + 'list.php?filter=' + currentFilter;
    if (currentFilter === 'team' && currentFilterTeamId) url += '&team_id=' + currentFilterTeamId;
    if (currentFilter === 'analyst' && currentFilterAnalystId) url += '&analyst_id=' + currentFilterAnalystId;

    try {
        const data = await fetch(url).then(r => r.json());
        if (data.success) {
            tasks = data.tasks;
            tasks.forEach(t => t._search = buildSearchText(t));
            if (currentView === 'board') renderBoard();
            else renderList();
        }
    } catch (e) { console.error('Failed to load tasks:', e); }
}

// ── Filters ────────────────────────────────────────────────────────

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

function setTeamFilter(teamId) {
    if (!teamId) { setFilter(currentFilter === 'team' ? 'my' : currentFilter); return; }
    currentFilter = 'team';
    currentFilterTeamId = teamId;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('analystFilter').value = '';
    loadTasks();
}

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

function clearSearch() {
    document.getElementById('taskSearch').value = '';
    setSearch('');
}

// ── Tag filter ─────────────────────────────────────────────────────

function taskMatchesTag(t) {
    if (!currentTagFilter) return true;
    return (t.tags || []).some(tg => String(tg.id) === String(currentTagFilter));
}

function setTagFilter(tagId) {
    currentTagFilter = tagId;
    if (currentView === 'board') renderBoard();
    else renderList();
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

function switchView(view) {
    currentView = view;
    document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
    document.querySelector(`.view-btn[data-view="${view}"]`).classList.add('active');
    document.getElementById('boardView').style.display = view === 'board' ? 'flex' : 'none';
    document.getElementById('listView').style.display = view === 'list' ? 'block' : 'none';
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
    const board = document.getElementById('boardView');
    const cols = statusList.length ? statusList : DEFAULT_STATUSES;
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

function startColumnDrag(e, column) {
    // Left-button only; the + button is not a drag handle
    if (e.button !== 0 || e.target.closest('.column-add-btn')) return;
    const board = document.getElementById('boardView');
    const startX = e.clientX;
    let dragging = false;

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

function renderBoard() {
    document.querySelectorAll('#boardView .board-column').forEach(col => {
        const status = col.dataset.status;
        const cardsEl = col.querySelector('.board-cards');
        const countEl = col.querySelector('.column-count');
        const filtered = tasks.filter(t =>
            t.status === status && taskMatchesSearch(t) && taskMatchesTag(t));
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
        const dueBadge = formatDueBadge(t.due_date);
        if (dueBadge) meta.push(dueBadge);
    }
    if (cf.subtasks && t.subtasks.total > 0) {
        meta.push(`<span class="subtask-progress">
             <span class="subtask-bar"><span class="subtask-bar-fill" style="width:${Math.round(t.subtasks.done / t.subtasks.total * 100)}%"></span></span>
             ${t.subtasks.done}/${t.subtasks.total}
           </span>`);
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

    return `<div class="task-card" data-id="${t.id}" onclick="openDetailPanel(${t.id})"${accent}>
        <div class="task-card-title">${esc(t.title)}</div>
        ${descHtml}
        ${meta.length ? `<div class="task-card-meta">${meta.join('')}</div>` : ''}
        ${tagsHtml}
    </div>`;
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

function formatDueBadge(dateStr) {
    if (!dateStr) return '';
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const due = new Date(dateStr + 'T00:00:00');
    const diff = Math.floor((due - today) / 86400000);
    let cls = '';
    let text = '';
    if (diff < 0) { cls = 'overdue'; text = window.t('tasks.board.overdue'); }
    else if (diff === 0) { cls = 'today'; text = window.t('tasks.board.due_today'); }
    else if (diff <= 7) { text = fmtNaiveDayMonth(due); }
    else { text = fmtNaiveDayMonth(due); }
    return `<span class="due-badge ${cls}">${text}</span>`;
}

// ── Quick Add ──────────────────────────────────────────────────────

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

function startDrag(e, card) {
    if (e.button !== 0) return;
    // Don't start drag on click (for opening detail panel)
    const startX = e.clientX;
    const startY = e.clientY;
    let moved = false;

    const onMove = (e2) => {
        const dx = Math.abs(e2.clientX - startX);
        const dy = Math.abs(e2.clientY - startY);
        if (!moved && (dx > 5 || dy > 5)) {
            moved = true;
            initDrag(card, e2);
        }
        if (moved) moveDrag(e2);
    };

    const onUp = (e2) => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        if (moved) endDrag(e2);
    };

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
    e.preventDefault();
}

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

    // Show indicator
    const indicator = document.createElement('div');
    indicator.className = 'drop-indicator';
    const container = targetColumn.querySelector('.board-cards');
    if (insertBefore) container.insertBefore(indicator, insertBefore);
    else container.appendChild(indicator);
}

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

    const newStatus = targetColumn.dataset.status;
    const container = targetColumn.querySelector('.board-cards');
    const otherCards = Array.from(container.querySelectorAll('.task-card:not(.dragging)'));

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
                : window.t('tasks.toast.order_failed'), 'error');
        }
    } catch (e) {
        console.error(e);
        showToast(window.t('tasks.toast.order_failed'), 'error');
    }

    loadTasks();
}

// ── List Rendering ─────────────────────────────────────────────────

function renderList() {
    const sorted = tasks.filter(t => taskMatchesSearch(t) && taskMatchesTag(t)).sort((a, b) => {
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
        const dueBadge = formatDueBadge(t.due_date);

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
    const WIDE = '.detail-description, .subtask-section, .comments-section';
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
    { key: 'subtasks', match: '.subtask-section',  count: '.subtask-item, .subtask-row' },
    { key: 'time',     match: '#taskTimeSection' },
    { key: 'comments', match: '.comments-section', count: '.comment-item' },
    { key: 'links',    match: '.link-section' },
    { key: 'documents',match: '#taskDocuments' },
];

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
            const n = panel.querySelectorAll(t.count).length;
            if (n) {
                const badge = document.createElement('span');
                badge.className = 'tdm-tab-count';
                badge.textContent = n;
                btn.appendChild(badge);
            }
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

async function openDetailPanel(taskId) {
    // Prevent opening from drag
    if (dragState) return;

    selectedTaskId = taskId;
    try {
        const data = await fetch(API_BASE + 'get.php?id=' + taskId).then(r => r.json());
        if (!data.success) return;
        // The recent trail (#124).
        if (window.trailVisit) window.trailVisit('task', taskId);
        const panel = document.getElementById('detailPanel');
        // One panel, two shapes. The drawer is the default and is untouched;
        // the modal is the same element with a class on it, so there is no
        // second panel to keep in step.
        panel.classList.toggle('as-modal', taskViewIsModal());
        paintViewToggle();
        renderDetailPanel(data.task);
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
    } catch (e) { console.error(e); }
}

function closeDetailPanel() {
    document.getElementById('detailPanel').classList.remove('open');
    document.getElementById('detailOverlay').classList.remove('open');
    if (tinyEditor) { tinyEditor.destroy(); tinyEditor = null; }
    selectedTaskId = null;
    loadTasks();
}

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
            <input class="detail-title-input" id="detailTitle" value="${esc(task.title)}" onchange="saveField('title', this.value)">
        </div>

        <div class="detail-row">
            <div class="detail-field">
                <label>${esc(window.t('tasks.detail.status'))}</label>
                <select class="detail-select" onchange="saveField('status', this.value)">
                    ${lookupOptions(statusList, task.status)}
                </select>
            </div>
            <div class="detail-field">
                <label>${esc(window.t('tasks.detail.priority'))}</label>
                <select class="detail-select" onchange="saveField('priority', this.value)">
                    ${lookupOptions(priorityList, task.priority)}
                </select>
            </div>
        </div>

        <div class="detail-row">
            <div class="detail-field">
                <label>${esc(window.t('tasks.detail.assignee'))}</label>
                <select class="detail-select" onchange="saveField('assigned_analyst_id', this.value || null)">
                    <option value="">${esc(window.t('tasks.detail.unassigned'))}</option>
                    ${analystOptions}
                </select>
            </div>
            <div class="detail-field">
                <label>${esc(window.t('tasks.detail.team'))}</label>
                <select class="detail-select" onchange="saveField('assigned_team_id', this.value || null)">
                    <option value="">${esc(window.t('tasks.detail.no_team'))}</option>
                    ${teamOptions}
                </select>
            </div>
        </div>

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
                <input type="date" class="detail-input" value="${task.start_date || ''}" onchange="saveField('start_date', this.value || null)">
            </div>
            <div class="detail-field">
                <label>${esc(window.t('tasks.detail.due_date'))}</label>
                <input type="date" class="detail-input" value="${task.due_date || ''}" onchange="saveField('due_date', this.value || null)">
            </div>
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
                       onchange="saveField('work_all_day', this.checked ? 1 : 0); openDetailPanel(${task.id});">
                ${esc(window.t('tasks.detail.work_all_day'))}
            </label>
        </div>

        <!-- TIME ACTUALLY SPENT, as many sittings as it took. -->
        <div class="detail-field" id="taskTimeSection"></div>
        ` : ''}

        <div class="detail-field">
            <label>${esc(window.t('tasks.detail.tags'))}</label>
            <div id="detailTagSection"></div>
        </div>

        <div class="detail-field detail-description">
            <label>${esc(window.t('tasks.detail.description'))}</label>
            <div id="descriptionEditor">${task.description || ''}</div>
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
            <div class="subtask-list" id="subtaskList">
                ${(task.subtasks || []).map(s => {
                    const dueBadge = s.due_date ? formatDueBadge(s.due_date) : '';
                    const assignee = s.analyst_name ? esc(s.analyst_name) : '';
                    // Follows the same card placement, so a board set to pills
                    // reads the same way inside a task. Note the line below about
                    // never deriving anything from a status NAME — this dot used
                    // to do exactly that with the priority name, two lines apart.
                    const priorityHtml   = TasksPriority.markup(s.priority, s.priority_colour, cardFields.priority);
                    const priorityAccent = TasksPriority.accentAttrs(s.priority, s.priority_colour, cardFields.priority);
                    // The propagation guard belongs on CLICK, not on CHANGE.
                    // Ticking a checkbox fires a click on the input, which bubbles
                    // to the row's onclick — so the row opened the subtask instead
                    // of the box being ticked. Stopping propagation inside onchange
                    // was both too late and on an event the row never listens for.
                    // Ticking a box must tick the box and nothing else.
                    //
                    // "checked" comes from the is_closed FLAG, never from comparing
                    // the status name to the English word Done: rename the status
                    // and the box would never appear ticked however complete it was.
                    return `
                    <div class="subtask-item" onclick="openDetailPanel(${s.id})"${priorityAccent}>
                        <input type="checkbox" ${s.status_is_closed ? 'checked' : ''}
                               onclick="event.stopPropagation()"
                               onchange="toggleSubtask(${s.id})">
                        ${priorityHtml}
                        <span class="subtask-title ${s.status_is_closed ? 'completed' : ''}">${esc(s.title)}</span>
                        <span class="subtask-meta">
                            ${assignee ? '<span class="subtask-assignee">' + assignee + '</span>' : ''}
                            ${dueBadge || `<input type="date" class="subtask-due-set"
                                   onclick="event.stopPropagation()"
                                   onchange="event.stopPropagation(); setSubtaskDue(${s.id}, this.value)"
                                   title="${escAttr(window.t('tasks.detail.subtask_set_due'))}">`}
                        </span>
                    </div>`;
                }).join('')}
            </div>
            <div class="subtask-add">
                <input type="text" placeholder="${escAttr(window.t('tasks.detail.add_subtask'))}" id="newSubtaskInput" onkeydown="if(event.key==='Enter')addSubtask()">
                <input type="date" id="newSubtaskDue" class="subtask-add-due"
                       title="${escAttr(window.t('tasks.detail.subtask_set_due'))}"
                       onkeydown="if(event.key==='Enter')addSubtask()">
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

        <!-- Timestamps -->
        <div class="detail-timestamps">
            <span>${esc(window.t('tasks.detail.created_by', { datetime: formatDateTime(task.created_datetime), name: task.created_by_name || '' }))}</span>
            <span>${esc(window.t('tasks.detail.updated', { datetime: formatDateTime(task.updated_datetime) }))}</span>
            ${task.completed_datetime ? `<span>${esc(window.t('tasks.detail.completed', { datetime: formatDateTime(task.completed_datetime) }))}</span>` : ''}
        </div>
        <div class="detail-section" style="margin-top:18px;">
            <div id="taskDocuments"></div>
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
            editor.on('change keyup', () => {
                clearTimeout(descSaveTimer);
                descSaveTimer = setTimeout(() => {
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

function addInvolved(analystId)    { if (analystId) postInvolved('add', analystId); }
function removeInvolved(analystId) { postInvolved('remove', analystId); }
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
    if (data && data.success) return true;
    if (data && data.error === 'Not authenticated') {
        showToast(window.t('tasks.toast.session_expired'), 'error');
        return false;
    }
    showToast(window.t('tasks.toast.error_prefix', {
        message: (data && data.error) || window.t(failedKey)
    }), 'error');
    return false;
}

async function saveField(field, value) {
    if (!selectedTaskId) return;
    if (field === 'status' && !(await confirmCloseWithInvolved(value))) return;
    await postTaskChange({ id: selectedTaskId, [field]: value }, 'tasks.toast.save_failed');
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

async function saveWorkStart(value) {
    if (!selectedTaskId) return;
    // Clearing the start clears the whole slot, server-side. Reopen so the end
    // box does not sit there showing a time that no longer exists.
    const ok = await postTaskChange({ id: selectedTaskId, work_start_at: workPayload(value) }, 'tasks.toast.save_failed');
    if (ok && !value) openDetailPanel(selectedTaskId);
}

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

function closeTagPicker() {
    const r = document.getElementById('tagPickerResults');
    if (r) r.classList.remove('open');
}

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

function removeDetailTag(tagId) {
    detailTags = detailTags.filter(t => t.id !== tagId);
    saveDetailTags();
    renderTagSection();
}

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

function saveDetailTags() {
    saveField('tags', detailTags.map(t => t.id));
}

// ── Subtasks ───────────────────────────────────────────────────────

async function toggleSubtask(id) {
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
        }
        if (selectedTaskId) openDetailPanel(selectedTaskId);
    } catch (e) {
        console.error(e);
        showToast(window.t('tasks.toast.subtask_toggle_failed'), 'error');
        if (selectedTaskId) openDetailPanel(selectedTaskId);
    }
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
            ${master}
            <div class="recur-editor" id="recurEditor" style="display:none">${renderRecurrenceEditor(r)}</div>
        </div>`;
}

/** One sentence describing a rule, built from the same parts the editor sets. */
function describeRecurrence(r) {
    if (!r) return window.t('tasks.recur.none');
    const n = Number(r.interval_n) || 1;
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
    const T = k => esc(window.t('tasks.recur.' + k));
    const v = Object.assign({
        mode: 'completion', freq: 'weekly', interval_n: 1, weekdays: '',
        month_mode: 'dom', day_of_month: '', nth: 1, nth_weekday: 1, month_of_year: '',
        ends_mode: 'never', ends_on: '', max_occurrences: '',
        copy_description: 1, copy_subtasks: 1, copy_assignee: 1, copy_tags: 1,
        copy_links: 0, copy_attachments: 0,
    }, r || {});
    const sel = (a, b) => String(a) === String(b) ? 'selected' : '';
    const chk = x => Number(x) ? 'checked' : '';
    const days = String(v.weekdays || '').split(',').filter(Boolean).map(Number);
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
    const show = (sel, on) => root.querySelectorAll(sel).forEach(el => el.style.display = on ? '' : 'none');

    show('.recur-weekly',  freq === 'weekly');
    show('.recur-monthly', freq === 'monthly' || freq === 'yearly');
    show('.recur-yearly',  freq === 'yearly');
    show('.recur-dom',     mm === 'dom');
    show('.recur-nth',     mm === 'nth');
    show('.recur-ends-date',  ends === 'on_date');
    show('.recur-ends-count', ends === 'after_count');
}

/**
 * The editor's current settings, as the API wants them.
 *
 * Shared by Save and Preview, so the dates previewed are produced from exactly
 * the same values that would be saved. Reading the form twice, in two places,
 * is how a preview quietly stops describing the thing it is previewing.
 */
function recurrenceFormPayload() {
    const val = id => (document.getElementById(id) || {}).value;
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

function showRecurrencePreview(d) {
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

function recurPreviewEsc(e) { if (e.key === 'Escape') closeRecurrencePreview(); }

function closeRecurrencePreview() {
    const el = document.getElementById('recurPreviewOverlay');
    if (el) el.remove();
    document.removeEventListener('keydown', recurPreviewEsc);
}

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
async function setSubtaskDue(subtaskId, value) {
    if (!value) return;
    const ok = await postTaskChange({ id: subtaskId, due_date: value }, 'tasks.toast.save_failed');
    if (ok && selectedTaskId) openDetailPanel(selectedTaskId);
}

async function addSubtask() {
    const input = document.getElementById('newSubtaskInput');
    const title = input.value.trim();
    if (!title || !selectedTaskId) return;

    try {
        // A due date is optional and only sent when one was typed, so a subtask
        // created without a date is stored exactly as it always was (#90).
        const dueEl   = document.getElementById('newSubtaskDue');
        const payload = { title, parent_task_id: selectedTaskId, assigned_analyst_id: ANALYST_ID };
        if (dueEl && dueEl.value) payload.due_date = dueEl.value;

        const data = await fetch(API_BASE + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(r => r.json());

        if (data.success) {
            input.value = '';
            if (dueEl) dueEl.value = '';
            openDetailPanel(selectedTaskId);
        }
    } catch (e) { console.error(e); }
}

// ── Comments ───────────────────────────────────────────────────────

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

async function removeLink(field) {
    await saveField(field, null);
    openDetailPanel(selectedTaskId);
}

// ── Delete ─────────────────────────────────────────────────────────

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
        }
    } catch (e) { console.error(e); }
}

// ── Lookups ────────────────────────────────────────────────────────

let statusList = [];
let priorityList = [];

// Active statuses and priorities — drive the board columns, the
// shared context menu (assets/js/tasks-ctx-menu.js), and the
// detail-panel dropdowns
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

function linkedRecord(url, label) {
    if (!url) return esc(label);
    const base = window.APP_BASE || '';
    return `<a class="linked-record" href="${escAttr(base + url)}">${esc(label)}</a>`;
}

function esc(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function formatDateTime(dt) {
    if (!dt) return '';
    // Stored UTC → render in the analyst's chosen display zone (parseUTCDate /
    // tzOpts from assets/js/tz.js). Used for comment/created/updated/completed
    // timestamps — all true datetimes.
    const d = parseUTCDate(dt);
    if (!d || isNaN(d)) return dt;
    return fmtDateTime(d);
}

