/**
 * FreeITSM Tasks Module — Board, List, Detail Panel, Drag & Drop
 */

// ── State ──────────────────────────────────────────────────────────

let currentFilter = 'my';
let currentFilterTeamId = null;
let currentFilterAnalystId = null;
let currentView = 'board';
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

// Tags — full list, display settings, the active sidebar filter, and the
// working set while a task is open in the detail panel
let tagList = [];
let tagSettings = {
    allow_create: 0, surface_card: 1, surface_filter: 1,
    surface_search: 1, surface_calendar: 0
};
let currentTagFilter = '';
let detailTags = [];
const TAG_PALETTE = ['#dc2626', '#ea580c', '#d97706', '#16a34a',
                     '#0891b2', '#2563eb', '#7c3aed', '#db2777'];

const ANALYST_ID = document.body.dataset.analystId;

// Locale for date formatting — matches the page's i18n locale
const UI_LOCALE = document.documentElement.lang || 'en';

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
        onUpdate: () => loadTasks(),
        apiBase: API_BASE,
        // Open the task and drop the cursor straight into the Add-subtask box
        onCreateSubtask: id => openDetailPanel(id).then(() => {
            const input = document.getElementById('newSubtaskInput');
            if (input) { input.scrollIntoView({ block: 'center' }); input.focus(); }
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
    } catch (e) { console.error('Failed to load card settings:', e); }
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

function switchView(view) {
    currentView = view;
    document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
    document.querySelector(`.view-btn[data-view="${view}"]`).classList.add('active');
    document.getElementById('boardView').style.display = view === 'board' ? 'flex' : 'none';
    document.getElementById('listView').style.display = view === 'list' ? 'block' : 'none';
    if (view === 'board') renderBoard();
    else renderList();
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
    }).catch(() => showToast(window.t('tasks.toast.order_failed'), 'success'));
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
    if (cf.priority && t.priority) {
        meta.push(`<span class="priority-dot ${t.priority.toLowerCase()}" title="${esc(t.priority)}"></span>`);
    }
    if (cf.assignee && initials) {
        meta.push(`<span class="assignee-badge" title="${esc(t.analyst_name)}">${esc(initials)}</span>`);
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

    return `<div class="task-card" data-id="${t.id}" onclick="openDetailPanel(${t.id})">
        <div class="task-card-title">${esc(t.title)}</div>
        ${descHtml}
        ${meta.length ? `<div class="task-card-meta">${meta.join('')}</div>` : ''}
        ${tagsHtml}
    </div>`;
}

// Short date for the start badge, e.g. "12 Jun"
function formatShortDate(dateStr) {
    if (!dateStr) return '';
    return new Date(dateStr + 'T00:00:00')
        .toLocaleDateString(UI_LOCALE, { day: 'numeric', month: 'short' });
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
    else if (diff <= 7) { text = due.toLocaleDateString(UI_LOCALE, { day: 'numeric', month: 'short' }); }
    else { text = due.toLocaleDateString(UI_LOCALE, { day: 'numeric', month: 'short' }); }
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
        showToast(window.t('tasks.toast.create_failed'), 'success');
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

    try {
        await fetch(API_BASE + 'reorder.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_id: movedTaskId, new_status: newStatus, positions })
        });
    } catch (e) { console.error(e); }

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
        return `<tr onclick="openDetailPanel(${t.id})">
            <td><strong>${esc(t.title)}</strong>${tagsHtml}</td>
            <td><span class="status-pill" style="background:${sc}1f;color:${sc}">${esc(t.status)}</span></td>
            <td><span class="priority-pill"><span class="priority-dot ${t.priority.toLowerCase()}"></span> ${esc(t.priority)}</span></td>
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

async function openDetailPanel(taskId) {
    // Prevent opening from drag
    if (dragState) return;

    selectedTaskId = taskId;
    try {
        const data = await fetch(API_BASE + 'get.php?id=' + taskId).then(r => r.json());
        if (!data.success) return;
        renderDetailPanel(data.task);
        document.getElementById('detailPanel').classList.add('open');
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
    detailTags = (task.tags || []).map(t => ({ id: t.id, name: t.name, colour: t.colour }));
    const analystOptions = analysts.map(a =>
        `<option value="${a.id}" ${a.id == task.assigned_analyst_id ? 'selected' : ''}>${esc(a.name)}</option>`
    ).join('');
    const teamOptions = teams.map(t =>
        `<option value="${t.id}" ${t.id == task.assigned_team_id ? 'selected' : ''}>${esc(t.name)}</option>`
    ).join('');

    body.innerHTML = `
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

        <div class="detail-field">
            <label>${esc(window.t('tasks.detail.tags'))}</label>
            <div id="detailTagSection"></div>
        </div>

        <div class="detail-field detail-description">
            <label>${esc(window.t('tasks.detail.description'))}</label>
            <div id="descriptionEditor">${task.description || ''}</div>
        </div>

        <!-- Links -->
        <div class="link-section">
            <h4>${esc(window.t('tasks.detail.links'))}</h4>
            <div id="linkList">
                ${task.ticket_id ? `<div class="link-item"><span class="link-type">${esc(window.t('tasks.detail.link_ticket'))}</span> #${esc(task.ticket_number)} — ${esc(task.ticket_subject || '')}<button class="link-remove" onclick="removeLink('ticket_id')">&times;</button></div>` : ''}
                ${task.change_id ? `<div class="link-item"><span class="link-type">${esc(window.t('tasks.detail.link_change'))}</span> ${esc(task.change_title || 'Change #' + task.change_id)}<button class="link-remove" onclick="removeLink('change_id')">&times;</button></div>` : ''}
            </div>
            ${!task.ticket_id ? `
            <div class="link-search-container">
                <input class="link-search-input" placeholder="${escAttr(window.t('tasks.detail.search_tickets'))}" oninput="searchLink(this.value, 'ticket')">
                <div class="link-search-results" id="ticketSearchResults"></div>
            </div>` : ''}
            ${!task.change_id ? `
            <div class="link-search-container">
                <input class="link-search-input" placeholder="${escAttr(window.t('tasks.detail.search_changes'))}" oninput="searchLink(this.value, 'change')">
                <div class="link-search-results" id="changeSearchResults"></div>
            </div>` : ''}
        </div>

        <!-- Parent breadcrumb (if subtask) -->
        ${task.parent_task ? `
        <div class="parent-breadcrumb">
            <a href="#" onclick="openDetailPanel(${task.parent_task.id}); return false;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"></polyline></svg>
                ${esc(task.parent_task.title)}
            </a>
        </div>` : ''}

        <!-- Subtasks -->
        ${!task.parent_task_id ? `
        <div class="subtask-section">
            <h4>${esc(window.t('tasks.detail.subtasks'))}</h4>
            <div class="subtask-list" id="subtaskList">
                ${(task.subtasks || []).map(s => {
                    const dueBadge = s.due_date ? formatDueBadge(s.due_date) : '';
                    const assignee = s.analyst_name ? esc(s.analyst_name) : '';
                    const priorityCls = (s.priority || 'medium').toLowerCase();
                    return `
                    <div class="subtask-item" onclick="openDetailPanel(${s.id})">
                        <input type="checkbox" ${s.status === 'Done' ? 'checked' : ''} onchange="event.stopPropagation(); toggleSubtask(${s.id})">
                        <span class="priority-dot ${priorityCls}" title="${esc(s.priority || '')}"></span>
                        <span class="subtask-title ${s.status === 'Done' ? 'completed' : ''}">${esc(s.title)}</span>
                        <span class="subtask-meta">
                            ${assignee ? '<span class="subtask-assignee">' + assignee + '</span>' : ''}
                            ${dueBadge}
                        </span>
                    </div>`;
                }).join('')}
            </div>
            <div class="subtask-add">
                <input type="text" placeholder="${escAttr(window.t('tasks.detail.add_subtask'))}" id="newSubtaskInput" onkeydown="if(event.key==='Enter')addSubtask()">
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
                <textarea id="newCommentInput" placeholder="${escAttr(window.t('tasks.detail.add_comment'))}" rows="2"></textarea>
                <button onclick="addComment()">${esc(window.t('tasks.detail.post'))}</button>
            </div>
        </div>

        <!-- Timestamps -->
        <div class="detail-timestamps">
            <span>${esc(window.t('tasks.detail.created_by', { datetime: formatDateTime(task.created_datetime), name: task.created_by_name || '' }))}</span>
            <span>${esc(window.t('tasks.detail.updated', { datetime: formatDateTime(task.updated_datetime) }))}</span>
            ${task.completed_datetime ? `<span>${esc(window.t('tasks.detail.completed', { datetime: formatDateTime(task.completed_datetime) }))}</span>` : ''}
        </div>
    `;

    renderTagSection();

    // Init TinyMCE for description
    if (tinyEditor) { tinyEditor.destroy(); tinyEditor = null; }
    tinymce.init({
        target: document.getElementById('descriptionEditor'),
        license_key: 'gpl',
        menubar: false,
        statusbar: false,
        height: 200,
        plugins: 'lists link',
        toolbar: 'bold italic underline | bullist numlist | link',
        content_style: 'body { font-family: Segoe UI, sans-serif; font-size: 13px; color: #333; }',
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

// ── Field Save ─────────────────────────────────────────────────────

async function saveField(field, value) {
    if (!selectedTaskId) return;
    try {
        await fetch(API_BASE + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: selectedTaskId, [field]: value })
        });
    } catch (e) { console.error(e); }
}

// ── Detail-panel tag picker ────────────────────────────────────────

// One tag chip; pass removable=true for the editable chips in the panel
function tagChipHtml(tag, removable) {
    const colour = tag.colour || '#6b7280';
    const x = removable
        ? `<button type="button" class="tag-chip-x" title="Remove"
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
            showToast(data.error || window.t('tasks.toast.tag_create_failed'), 'success');
        }
    } catch (e) { showToast(window.t('tasks.toast.tag_create_failed'), 'success'); }
}

function saveDetailTags() {
    saveField('tags', detailTags.map(t => t.id));
}

// ── Subtasks ───────────────────────────────────────────────────────

async function toggleSubtask(id) {
    try {
        await fetch(API_BASE + 'toggle_subtask.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        if (selectedTaskId) openDetailPanel(selectedTaskId);
    } catch (e) { console.error(e); }
}

async function addSubtask() {
    const input = document.getElementById('newSubtaskInput');
    const title = input.value.trim();
    if (!title || !selectedTaskId) return;

    try {
        const data = await fetch(API_BASE + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title, parent_task_id: selectedTaskId, assigned_analyst_id: ANALYST_ID })
        }).then(r => r.json());

        if (data.success) {
            input.value = '';
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

async function linkItem(field, id) {
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
    return d.toLocaleDateString(UI_LOCALE, tzOpts({ day: '2-digit', month: 'short', year: 'numeric' }))
        + ' ' + d.toLocaleTimeString(UI_LOCALE, tzOpts({ hour: '2-digit', minute: '2-digit' }));
}

