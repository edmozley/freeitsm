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

const ANALYST_ID = document.body.dataset.analystId;

// ── Init ───────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    loadDropdowns();
    loadTasks();
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeDetailPanel();
    });
});

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
            sel.innerHTML = '<option value="">All analysts</option>' +
                analysts.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
        }
        if (tRes.success) {
            teams = tRes.teams;
            const sel = document.getElementById('teamFilter');
            sel.innerHTML = '<option value="">All teams</option>' +
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

function renderBoard() {
    const statuses = ['To Do', 'In Progress', 'Done'];
    statuses.forEach(status => {
        const container = document.getElementById('cards-' + status);
        const filtered = tasks.filter(t => t.status === status);
        const countEl = document.getElementById('count' + status.replace(/\s/g, ''));
        if (countEl) countEl.textContent = filtered.length;

        if (filtered.length === 0) {
            container.innerHTML = '<div class="board-empty">No tasks</div>';
            return;
        }

        container.innerHTML = filtered.map(t => renderCard(t)).join('');

        // Attach drag events
        container.querySelectorAll('.task-card').forEach(card => {
            card.addEventListener('mousedown', e => startDrag(e, card));
        });
    });
}

function renderCard(t) {
    const initials = t.analyst_name ? t.analyst_name.split(' ').map(w => w[0]).join('').substring(0, 2) : '';
    const dueBadge = formatDueBadge(t.due_date);
    const subtaskHtml = t.subtasks.total > 0
        ? `<span class="subtask-progress">
             <span class="subtask-bar"><span class="subtask-bar-fill" style="width:${Math.round(t.subtasks.done / t.subtasks.total * 100)}%"></span></span>
             ${t.subtasks.done}/${t.subtasks.total}
           </span>` : '';
    const linkHtml = (t.ticket_id || t.change_id)
        ? `<span class="link-badge"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg></span>` : '';

    return `<div class="task-card" data-id="${t.id}" onclick="openDetailPanel(${t.id})">
        <div class="task-card-title">${esc(t.title)}</div>
        <div class="task-card-meta">
            <span class="priority-dot ${t.priority.toLowerCase()}" title="${esc(t.priority)}"></span>
            ${initials ? `<span class="assignee-badge" title="${esc(t.analyst_name)}">${esc(initials)}</span>` : ''}
            ${dueBadge}
            ${subtaskHtml}
            ${linkHtml}
        </div>
    </div>`;
}

function formatDueBadge(dateStr) {
    if (!dateStr) return '';
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const due = new Date(dateStr + 'T00:00:00');
    const diff = Math.floor((due - today) / 86400000);
    let cls = '';
    let text = '';
    if (diff < 0) { cls = 'overdue'; text = 'Overdue'; }
    else if (diff === 0) { cls = 'today'; text = 'Today'; }
    else if (diff <= 7) { text = due.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }); }
    else { text = due.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }); }
    return `<span class="due-badge ${cls}">${text}</span>`;
}

// ── Quick Add ──────────────────────────────────────────────────────

function showQuickAdd(status) {
    const container = document.getElementById('quickAdd-' + status);
    container.style.display = 'block';
    const input = container.querySelector('input');
    input.value = '';
    input.focus();

    // Hide on blur if empty
    input.onblur = () => {
        setTimeout(() => {
            if (!input.value.trim()) {
                container.style.display = 'none';
            }
        }, 150);
    };
}

async function handleQuickAdd(event, status) {
    if (event.key === 'Escape') {
        event.target.value = '';
        event.target.parentElement.style.display = 'none';
        return;
    }
    if (event.key !== 'Enter') return;
    const input = event.target;
    const title = input.value.trim();
    if (!title) return;

    input.disabled = true;
    try {
        const resp = await fetch(API_BASE + 'save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title: title, status: status, assigned_analyst_id: ANALYST_ID || null })
        });
        const data = await resp.json();

        if (data.success) {
            input.value = '';
            input.parentElement.style.display = 'none';
            loadTasks();
            showToast('Task created');
        } else {
            console.error('Save failed:', data.error);
            showToast('Error: ' + (data.error || 'Failed to create task'));
        }
    } catch (e) {
        console.error('Quick add error:', e);
        showToast('Failed to create task');
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
    const sorted = [...tasks].sort((a, b) => {
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
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#999;padding:30px;">No tasks found</td></tr>';
        return;
    }

    tbody.innerHTML = sorted.map(t => {
        const statusCls = t.status.toLowerCase().replace(/\s/g, '-');
        const subtaskText = t.subtasks.total > 0 ? `${t.subtasks.done}/${t.subtasks.total}` : '—';
        const dueBadge = formatDueBadge(t.due_date);

        return `<tr onclick="openDetailPanel(${t.id})">
            <td><strong>${esc(t.title)}</strong></td>
            <td><span class="status-pill ${statusCls}">${esc(t.status)}</span></td>
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
                <label>Status</label>
                <select class="detail-select" onchange="saveField('status', this.value)">
                    <option ${task.status === 'To Do' ? 'selected' : ''}>To Do</option>
                    <option ${task.status === 'In Progress' ? 'selected' : ''}>In Progress</option>
                    <option ${task.status === 'Done' ? 'selected' : ''}>Done</option>
                    <option ${task.status === 'Cancelled' ? 'selected' : ''}>Cancelled</option>
                </select>
            </div>
            <div class="detail-field">
                <label>Priority</label>
                <select class="detail-select" onchange="saveField('priority', this.value)">
                    <option ${task.priority === 'Low' ? 'selected' : ''}>Low</option>
                    <option ${task.priority === 'Medium' ? 'selected' : ''}>Medium</option>
                    <option ${task.priority === 'High' ? 'selected' : ''}>High</option>
                    <option ${task.priority === 'Urgent' ? 'selected' : ''}>Urgent</option>
                </select>
            </div>
        </div>

        <div class="detail-row">
            <div class="detail-field">
                <label>Assignee</label>
                <select class="detail-select" onchange="saveField('assigned_analyst_id', this.value || null)">
                    <option value="">Unassigned</option>
                    ${analystOptions}
                </select>
            </div>
            <div class="detail-field">
                <label>Team</label>
                <select class="detail-select" onchange="saveField('assigned_team_id', this.value || null)">
                    <option value="">No team</option>
                    ${teamOptions}
                </select>
            </div>
        </div>

        <div class="detail-field">
            <label>Due Date</label>
            <input type="date" class="detail-input" value="${task.due_date || ''}" onchange="saveField('due_date', this.value || null)">
        </div>

        <div class="detail-field detail-description">
            <label>Description</label>
            <div id="descriptionEditor">${task.description || ''}</div>
        </div>

        <!-- Links -->
        <div class="link-section">
            <h4>Links</h4>
            <div id="linkList">
                ${task.ticket_id ? `<div class="link-item"><span class="link-type">Ticket</span> #${esc(task.ticket_number)} — ${esc(task.ticket_subject || '')}<button class="link-remove" onclick="removeLink('ticket_id')">&times;</button></div>` : ''}
                ${task.change_id ? `<div class="link-item"><span class="link-type">Change</span> ${esc(task.change_title || 'Change #' + task.change_id)}<button class="link-remove" onclick="removeLink('change_id')">&times;</button></div>` : ''}
            </div>
            ${!task.ticket_id ? `
            <div class="link-search-container">
                <input class="link-search-input" placeholder="Search tickets to link..." oninput="searchLink(this.value, 'ticket')">
                <div class="link-search-results" id="ticketSearchResults"></div>
            </div>` : ''}
            ${!task.change_id ? `
            <div class="link-search-container">
                <input class="link-search-input" placeholder="Search changes to link..." oninput="searchLink(this.value, 'change')">
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
            <h4>Subtasks</h4>
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
                <input type="text" placeholder="Add subtask..." id="newSubtaskInput" onkeydown="if(event.key==='Enter')addSubtask()">
            </div>
        </div>` : ''}

        <!-- Comments -->
        <div class="comments-section">
            <h4>Comments</h4>
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
                <textarea id="newCommentInput" placeholder="Add a comment..." rows="2"></textarea>
                <button onclick="addComment()">Post</button>
            </div>
        </div>

        <!-- Timestamps -->
        <div class="detail-timestamps">
            <span>Created: ${formatDateTime(task.created_datetime)} by ${esc(task.created_by_name || '')}</span>
            <span>Updated: ${formatDateTime(task.updated_datetime)}</span>
            ${task.completed_datetime ? `<span>Completed: ${formatDateTime(task.completed_datetime)}</span>` : ''}
        </div>
    `;

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
    if (!confirm('Delete this task and all its subtasks?')) return;

    try {
        const data = await fetch(API_BASE + 'delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: selectedTaskId })
        }).then(r => r.json());

        if (data.success) {
            closeDetailPanel();
            showToast('Task deleted');
        }
    } catch (e) { console.error(e); }
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
    const d = new Date(dt.replace(' ', 'T'));
    if (isNaN(d)) return dt;
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
        + ' ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2500);
}
