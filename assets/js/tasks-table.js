/**
 * FreeITSM Tasks — Full-screen table view
 *
 * Modelled on the asset-management table (asset-table.js / asset-management/table.php):
 *   - Column show/hide + drag-reorder (persisted per-user via user_preferences)
 *   - Click-to-sort on any column
 *   - Search across visible columns
 *   - Per-column Excel-style tickbox filter
 *   - CSV export of the current view
 *   - Right-click a row for the shared analyst/team/status/priority context menu
 *
 * Inline cell editing is the key differentiator: most columns render an
 * editable control (text input for title, select for status/priority/assignee/team,
 * date input for start/due) instead of static text. Change → POST save.php.
 * The COLUMNS catalogue is the single source of truth for what's editable
 * and how — adding a new column is just an entry in that array.
 */
(function () {
    'use strict';

    const PREF_KEY = 'tasks_table_v1';
    const API_BASE = '../../api/tasks/';
    const PREF_API = '../../api/system/';

    // --- Column catalogue ---------------------------------------------
    // editable kinds:
    //   false                  — static cell, no editing
    //   { kind: 'text' }       — borderless text input, save on change
    //   { kind: 'date' }       — date input, save YYYY-MM-DD (or null when cleared)
    //   { kind: 'lookup', listKey, valueKey, labelKey, allowNull?, colourKey? }
    //                          — select; valueKey is 'id' for FK lookups (assignee/team)
    //                            or 'name' for name-keyed lookups (status/priority)
    const COLUMNS = [
        {
            key: 'title',
            label: 'Title',
            type: 'string',
            defaultVisible: true,
            defaultOrder: 0,
            display: t => t.title || '',
            editable: { kind: 'text' },
        },
        {
            key: 'status',
            label: 'Status',
            type: 'string',
            defaultVisible: true,
            defaultOrder: 1,
            display: t => t.status || '',
            editable: { kind: 'lookup', listKey: 'statuses', valueKey: 'name', labelKey: 'name', colourKey: 'colour' },
        },
        {
            key: 'priority',
            label: 'Priority',
            type: 'string',
            defaultVisible: true,
            defaultOrder: 2,
            display: t => t.priority || '',
            editable: { kind: 'lookup', listKey: 'priorities', valueKey: 'name', labelKey: 'name', colourKey: 'colour' },
        },
        {
            key: 'assigned_analyst_id',
            label: 'Assignee',
            type: 'string',
            defaultVisible: true,
            defaultOrder: 3,
            display: t => t.analyst_name || '',
            editable: { kind: 'lookup', listKey: 'analysts', valueKey: 'id', labelKey: 'name', allowNull: true, nullLabel: '—' },
        },
        {
            key: 'assigned_team_id',
            label: 'Team',
            type: 'string',
            defaultVisible: true,
            defaultOrder: 4,
            display: t => t.team_name || '',
            editable: { kind: 'lookup', listKey: 'teams', valueKey: 'id', labelKey: 'name', allowNull: true, nullLabel: '—' },
        },
        {
            key: 'start_date',
            label: 'Start',
            type: 'date',
            defaultVisible: false,
            defaultOrder: 5,
            display: t => t.start_date || '',
            editable: { kind: 'date' },
        },
        {
            key: 'due_date',
            label: 'Due',
            type: 'date',
            defaultVisible: true,
            defaultOrder: 6,
            display: t => t.due_date || '',
            editable: { kind: 'date' },
        },
        {
            key: 'created_datetime',
            label: 'Created',
            type: 'date',
            defaultVisible: false,
            defaultOrder: 7,
            display: t => t.created_datetime ? formatDate(t.created_datetime) : '',
            editable: false,
        },
        {
            key: 'subtask_progress',
            label: 'Subtasks',
            type: 'string',
            defaultVisible: false,
            defaultOrder: 8,
            display: t => (t.subtasks && t.subtasks.total > 0) ? `${t.subtasks.done}/${t.subtasks.total}` : '—',
            editable: false,
        },
    ];
    const COL_BY_KEY = Object.fromEntries(COLUMNS.map(c => [c.key, c]));

    // --- State --------------------------------------------------------
    let allTasks = [];
    let analysts = [], teams = [], statuses = [], priorities = [];
    let columnState = [];                       // [{ key, visible }] in display order
    let sort = { key: 'due_date', dir: 'asc' };
    let filters = {};                           // { col_key: Set([allowed display values]) }
    let searchTerm = '';
    let openPopover = null;
    let currentFilter = 'my';
    let currentFilterTeamId = null;
    let currentFilterAnalystId = null;

    // --- Boot ---------------------------------------------------------
    document.addEventListener('DOMContentLoaded', async () => {
        columnState = COLUMNS.slice()
            .sort((a, b) => a.defaultOrder - b.defaultOrder)
            .map(c => ({ key: c.key, visible: c.defaultVisible }));

        await Promise.all([loadLookups(), loadDropdowns()]);
        await loadPreferences();
        await loadTasks();

        TasksCtxMenu.init({
            targetSelector: '.tt-row',
            getTaskId: el => parseInt(el.dataset.taskId, 10),
            getTask:   id => allTasks.find(t => t.id === id),
            getLookups: () => ({ analysts, teams, statuses, priorities }),
            onUpdate: () => loadTasks(),
            apiBase: API_BASE,
        });

        renderTable();
        wireToolbar();
    });

    // --- Data loading -------------------------------------------------
    async function loadLookups() {
        try {
            const [sRes, pRes] = await Promise.all([
                fetch(API_BASE + 'get_task_statuses.php').then(r => r.json()),
                fetch(API_BASE + 'get_task_priorities.php').then(r => r.json()),
            ]);
            if (sRes.success) statuses = (sRes.statuses || []).filter(s => s.is_active);
            if (pRes.success) priorities = (pRes.priorities || []).filter(p => p.is_active);
        } catch (e) { console.error('Failed to load lookups:', e); }
    }

    async function loadDropdowns() {
        try {
            const [aRes, tRes] = await Promise.all([
                fetch(API_BASE + 'list.php?analysts=1').then(r => r.json()),
                fetch(API_BASE + 'list.php?teams=1').then(r => r.json()),
            ]);
            if (aRes.success) {
                analysts = aRes.analysts;
                document.getElementById('analystFilter').innerHTML =
                    '<option value="">' + esc(window.t('tasks.filter.all_analysts')) + '</option>' +
                    analysts.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
            }
            if (tRes.success) {
                teams = tRes.teams;
                document.getElementById('teamFilter').innerHTML =
                    '<option value="">' + esc(window.t('tasks.filter.all_teams')) + '</option>' +
                    teams.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
            }
        } catch (e) { console.error('Failed to load dropdowns:', e); }
    }

    async function loadTasks() {
        let url = API_BASE + 'list.php?filter=' + currentFilter;
        if (currentFilter === 'team'    && currentFilterTeamId)    url += '&team_id='    + currentFilterTeamId;
        if (currentFilter === 'analyst' && currentFilterAnalystId) url += '&analyst_id=' + currentFilterAnalystId;
        try {
            const d = await fetch(url).then(r => r.json());
            if (d.success) {
                allTasks = d.tasks;
                renderTable();
            }
        } catch (e) { console.error('Failed to load tasks:', e); }
    }

    // --- Preferences --------------------------------------------------
    async function loadPreferences() {
        try {
            const res = await fetch(`${PREF_API}get_user_preference.php?key=${encodeURIComponent(PREF_KEY)}`);
            const data = await res.json();
            if (!data.success || !data.value) return;
            const parsed = JSON.parse(data.value);
            if (Array.isArray(parsed.cols)) {
                const known = new Set(COLUMNS.map(c => c.key));
                const seen = new Set();
                const merged = [];
                parsed.cols.forEach(c => {
                    if (known.has(c.k)) {
                        merged.push({ key: c.k, visible: c.v !== 0 });
                        seen.add(c.k);
                    }
                });
                COLUMNS.slice().sort((a, b) => a.defaultOrder - b.defaultOrder).forEach(c => {
                    if (!seen.has(c.key)) merged.push({ key: c.key, visible: c.defaultVisible });
                });
                columnState = merged;
            }
            if (parsed.sort && COL_BY_KEY[parsed.sort.k]) {
                sort = { key: parsed.sort.k, dir: parsed.sort.d === 'desc' ? 'desc' : 'asc' };
            }
        } catch (e) { /* defaults */ }
    }

    let saveTimer = null;
    function savePreferences() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            const payload = JSON.stringify({
                cols: columnState.map(c => ({ k: c.key, v: c.visible ? 1 : 0 })),
                sort: { k: sort.key, d: sort.dir },
            });
            fetch(PREF_API + 'set_user_preference.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key: PREF_KEY, value: payload }),
            }).catch(e => console.error('save prefs:', e));
        }, 400);
    }

    // --- Sidebar filters (My / Team / Analyst) ------------------------
    window.setFilter = function (filter) {
        currentFilter = filter;
        currentFilterTeamId = null;
        currentFilterAnalystId = null;
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        const btn = document.querySelector(`.filter-btn[data-filter="${filter}"]`);
        if (btn) btn.classList.add('active');
        document.getElementById('teamFilter').value = '';
        document.getElementById('analystFilter').value = '';
        loadTasks();
    };
    window.setTeamFilter = function (id) {
        if (!id) { setFilter('my'); return; }
        currentFilter = 'team';
        currentFilterTeamId = id;
        currentFilterAnalystId = null;
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('analystFilter').value = '';
        loadTasks();
    };
    window.setAnalystFilter = function (id) {
        if (!id) { setFilter('my'); return; }
        currentFilter = 'analyst';
        currentFilterAnalystId = id;
        currentFilterTeamId = null;
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('teamFilter').value = '';
        loadTasks();
    };

    // --- Toolbar ------------------------------------------------------
    function wireToolbar() {
        document.getElementById('ttSearch').addEventListener('input', e => {
            searchTerm = e.target.value.trim().toLowerCase();
            renderBody();
        });
        document.getElementById('ttColumnsBtn').addEventListener('click', e => {
            e.stopPropagation();
            openColumnsDrawer(e.currentTarget);
        });
        document.getElementById('ttResetBtn').addEventListener('click', () => {
            filters = {};
            searchTerm = '';
            sort = { key: 'due_date', dir: 'asc' };
            document.getElementById('ttSearch').value = '';
            closePopover();
            renderTable();
            savePreferences();
        });
        document.addEventListener('click', e => {
            if (openPopover && !openPopover.contains(e.target)) closePopover();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closePopover();
        });
    }

    // --- Rendering ----------------------------------------------------
    function visibleColumns() {
        return columnState.filter(c => c.visible).map(c => COL_BY_KEY[c.key]).filter(Boolean);
    }

    function renderTable() { renderHead(); renderBody(); }

    function renderHead() {
        const head = document.getElementById('ttHead');
        const cols = visibleColumns();
        head.innerHTML = `<tr>${cols.map(col => {
            const isSorted = sort.key === col.key;
            const arrow = isSorted ? (sort.dir === 'asc' ? '▲' : '▼') : '↕';
            const sortedClass = isSorted ? ' sorted' : '';
            const hasFilter = filters[col.key] && filters[col.key].size > 0;
            const filterClass = hasFilter ? ' active' : '';
            return `
                <th data-col-key="${esc(col.key)}" draggable="true">
                    <div class="tt-th-content${sortedClass}">
                        <span class="tt-th-label">${esc(col.label)}</span>
                        <span class="tt-sort-arrow">${arrow}</span>
                        <button type="button" class="tt-filter-btn${filterClass}" title="Filter ${esc(col.label)}" data-filter-key="${esc(col.key)}">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                            </svg>
                        </button>
                    </div>
                </th>`;
        }).join('')}</tr>`;

        head.querySelectorAll('th').forEach(th => {
            const key = th.dataset.colKey;
            th.querySelector('.tt-th-content').addEventListener('click', e => {
                if (e.target.closest('.tt-filter-btn')) return;
                toggleSort(key);
            });
            th.querySelector('.tt-filter-btn').addEventListener('click', e => {
                e.stopPropagation();
                openFilterDropdown(key, e.currentTarget);
            });
            th.addEventListener('dragstart', e => {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', key);
                th.classList.add('tt-dragging');
            });
            th.addEventListener('dragend', () => {
                th.classList.remove('tt-dragging');
                head.querySelectorAll('th').forEach(t => t.classList.remove('tt-drag-over'));
            });
            th.addEventListener('dragover', e => {
                e.preventDefault();
                head.querySelectorAll('th').forEach(t => t.classList.remove('tt-drag-over'));
                th.classList.add('tt-drag-over');
            });
            th.addEventListener('drop', e => {
                e.preventDefault();
                const from = e.dataTransfer.getData('text/plain');
                if (from && from !== key) reorderColumn(from, key);
            });
        });
    }

    function renderBody() {
        const body = document.getElementById('ttBody');
        const cols = visibleColumns();
        const rows = applyFiltersAndSort();

        const countEl = document.getElementById('ttCount');
        countEl.textContent = rows.length === allTasks.length
            ? `${rows.length} task${rows.length === 1 ? '' : 's'}`
            : `${rows.length} of ${allTasks.length}`;

        if (rows.length === 0) {
            body.innerHTML = `<tr><td colspan="${cols.length || 1}" class="tt-empty">No tasks match the current filters.</td></tr>`;
            return;
        }

        body.innerHTML = rows.map(task => {
            const tds = cols.map(col => renderCell(task, col)).join('');
            return `<tr class="tt-row" data-task-id="${task.id}">${tds}</tr>`;
        }).join('');
    }

    function renderCell(task, col) {
        const display = col.display(task);
        if (!col.editable) {
            return `<td title="${esc(display)}">${esc(display)}</td>`;
        }
        const kind = col.editable.kind;
        if (kind === 'text') {
            return `<td><input class="tt-cell-input" value="${esc(display)}"
                onfocus="event.target.select()"
                onchange="TasksTable.saveCell(${task.id}, '${esc(col.key)}', this.value)"></td>`;
        }
        if (kind === 'date') {
            return `<td><input type="date" class="tt-cell-date" value="${esc(display)}"
                onchange="TasksTable.saveCell(${task.id}, '${esc(col.key)}', this.value || null)"></td>`;
        }
        if (kind === 'lookup') {
            return renderLookupCell(task, col);
        }
        return `<td>${esc(display)}</td>`;
    }

    function renderLookupCell(task, col) {
        const ed = col.editable;
        const lookup = ({ analysts, teams, statuses, priorities })[ed.listKey] || [];
        const currentVal = task[col.key];
        const opts = [];
        if (ed.allowNull) {
            const selected = (currentVal === null || currentVal === undefined || currentVal === '') ? ' selected' : '';
            opts.push(`<option value=""${selected}>${esc(ed.nullLabel || '—')}</option>`);
        }
        let swatchColour = '';
        lookup.forEach(item => {
            const value = item[ed.valueKey];
            const label = item[ed.labelKey];
            // FK columns store ids → loose-equal so '12' == 12
            const isCurrent = String(value) === String(currentVal);
            if (isCurrent && ed.colourKey) swatchColour = item[ed.colourKey] || '';
            opts.push(`<option value="${esc(value)}"${isCurrent ? ' selected' : ''}>${esc(label)}</option>`);
        });
        const swatch = ed.colourKey
            ? `<span class="tt-swatch" style="background:${esc(swatchColour || '#bbb')}"></span>`
            : '';
        const cellCls = ed.colourKey ? ' tt-cell-status' : '';
        return `<td><div class="tt-cell-wrap${cellCls}">${swatch}<select class="tt-cell-select"
            onchange="TasksTable.saveCell(${task.id}, '${esc(col.key)}', this.value, '${esc(ed.valueKey)}')">${opts.join('')}</select></div></td>`;
    }

    // --- Cell save (exposed for inline handlers) ----------------------
    async function saveCell(taskId, fieldKey, rawValue, valueKind) {
        const task = allTasks.find(t => t.id === taskId);
        if (!task) return;

        let value = rawValue;
        if (valueKind === 'id') value = (rawValue === '' || rawValue === null) ? null : parseInt(rawValue, 10);
        if (rawValue === '' && fieldKey.endsWith('_date')) value = null;

        // Update local task data so display + future filters/sort reflect the new value.
        task[fieldKey] = value;
        if (fieldKey === 'assigned_analyst_id') {
            const a = analysts.find(x => x.id == value);
            task.analyst_name = a ? a.name : null;
        }
        if (fieldKey === 'assigned_team_id') {
            const tm = teams.find(x => x.id == value);
            task.team_name = tm ? tm.name : null;
        }
        if (fieldKey === 'status') {
            const s = statuses.find(x => x.name === value);
            task.status_colour = s ? s.colour : null;
            task.status_is_closed = s ? s.is_closed : 0;
        }
        if (fieldKey === 'priority') {
            const p = priorities.find(x => x.name === value);
            task.priority_colour = p ? p.colour : null;
        }

        try {
            const d = await fetch(API_BASE + 'save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: taskId, [fieldKey]: value }),
            }).then(r => r.json());
            if (d.success) {
                if (typeof showToast === 'function') showToast('Saved', 'success');
                // Re-render only the swatch on lookup cells with colour so the
                // dot reflects the new colour without nuking focus.
                refreshRowSwatch(taskId, fieldKey);
            } else if (typeof showToast === 'function') {
                showToast(d.error || 'Save failed', 'error');
            }
        } catch (e) {
            if (typeof showToast === 'function') showToast('Save failed', 'error');
        }
    }

    function refreshRowSwatch(taskId, fieldKey) {
        if (fieldKey !== 'status' && fieldKey !== 'priority') return;
        const task = allTasks.find(t => t.id === taskId);
        if (!task) return;
        const colour = fieldKey === 'status' ? task.status_colour : task.priority_colour;
        const tr = document.querySelector(`tr.tt-row[data-task-id="${taskId}"]`);
        if (!tr) return;
        const cols = visibleColumns();
        const idx = cols.findIndex(c => c.key === fieldKey);
        if (idx < 0) return;
        const td = tr.children[idx];
        const swatch = td && td.querySelector('.tt-swatch');
        if (swatch) swatch.style.background = colour || '#bbb';
    }

    // --- Filter, sort, search ----------------------------------------
    function applyFiltersAndSort() {
        const cols = visibleColumns();
        const search = searchTerm;
        let rows = allTasks;

        rows = rows.filter(row => {
            for (const colKey in filters) {
                const allowed = filters[colKey];
                if (!allowed || allowed.size === 0) continue;
                const col = COL_BY_KEY[colKey];
                if (!col) continue;
                const display = col.display(row);
                const key = display === '' ? '(empty)' : display;
                if (!allowed.has(key)) return false;
            }
            return true;
        });

        if (search) {
            rows = rows.filter(row => {
                for (const col of cols) {
                    if (String(col.display(row) || '').toLowerCase().indexOf(search) !== -1) return true;
                }
                return false;
            });
        }

        const sortCol = COL_BY_KEY[sort.key];
        if (sortCol) {
            const dir = sort.dir === 'desc' ? -1 : 1;
            rows = rows.slice().sort((a, b) => {
                const va = sortCol.display(a) || '';
                const vb = sortCol.display(b) || '';
                if (!va && !vb) return 0;
                if (!va) return 1;
                if (!vb) return -1;
                if (sortCol.type === 'date') return va < vb ? -1 * dir : va > vb ? 1 * dir : 0;
                return String(va).localeCompare(String(vb), undefined, { sensitivity: 'base' }) * dir;
            });
        }

        return rows;
    }

    function toggleSort(key) {
        if (sort.key === key) sort.dir = sort.dir === 'asc' ? 'desc' : 'asc';
        else sort = { key, dir: 'asc' };
        renderTable();
        savePreferences();
    }

    function reorderColumn(fromKey, toKey) {
        const fromIdx = columnState.findIndex(c => c.key === fromKey);
        const toIdx = columnState.findIndex(c => c.key === toKey);
        if (fromIdx < 0 || toIdx < 0) return;
        const [moved] = columnState.splice(fromIdx, 1);
        columnState.splice(toIdx, 0, moved);
        renderTable();
        savePreferences();
    }

    // --- Per-column filter dropdown ----------------------------------
    function openFilterDropdown(colKey, anchorEl) {
        closePopover();
        const col = COL_BY_KEY[colKey];
        if (!col) return;

        const otherFilters = Object.assign({}, filters);
        delete otherFilters[colKey];
        const baseRows = allTasks.filter(row => {
            for (const k in otherFilters) {
                const allowed = otherFilters[k];
                if (!allowed || allowed.size === 0) continue;
                const c = COL_BY_KEY[k];
                if (!c) continue;
                const display = c.display(row);
                const key = display === '' ? '(empty)' : display;
                if (!allowed.has(key)) return false;
            }
            return true;
        });
        const distinct = new Map();
        baseRows.forEach(row => {
            const display = col.display(row);
            const key = display === '' ? '(empty)' : display;
            distinct.set(key, (distinct.get(key) || 0) + 1);
        });
        const sorted = [...distinct.keys()].sort((a, b) =>
            a === '(empty)' ? -1 : b === '(empty)' ? 1
            : String(a).localeCompare(String(b), undefined, { sensitivity: 'base' })
        );

        const current = filters[colKey];
        const selected = new Set(current && current.size > 0 ? [...current] : sorted);

        const pop = document.createElement('div');
        pop.className = 'tt-pop tt-filter-pop';
        pop.innerHTML = `
            <input type="text" class="tt-pop-search" placeholder="Search values..." autocomplete="off">
            <div class="tt-pop-actions">
                <a class="tt-pop-select-all">Select all</a>
                <a class="tt-pop-clear">Clear</a>
            </div>
            <div class="tt-pop-list">
                ${sorted.map(v => `
                    <label class="tt-pop-item">
                        <input type="checkbox" value="${esc(v)}" ${selected.has(v) ? 'checked' : ''}>
                        <span class="tt-pop-value">${esc(v)}</span>
                        <span style="color:#999;font-size:11px;">${distinct.get(v)}</span>
                    </label>`).join('')}
            </div>
            <div class="tt-pop-buttons">
                <button type="button" class="tt-pop-cancel">Cancel</button>
                <button type="button" class="tt-pop-apply">Apply</button>
            </div>`;
        document.body.appendChild(pop);
        positionPopover(pop, anchorEl);
        openPopover = pop;

        const list = pop.querySelector('.tt-pop-list');
        const searchEl = pop.querySelector('.tt-pop-search');
        searchEl.focus();
        searchEl.addEventListener('input', () => {
            const term = searchEl.value.trim().toLowerCase();
            list.querySelectorAll('.tt-pop-item').forEach(item => {
                const txt = item.querySelector('.tt-pop-value').textContent.toLowerCase();
                item.style.display = term === '' || txt.indexOf(term) !== -1 ? '' : 'none';
            });
        });
        pop.querySelector('.tt-pop-select-all').addEventListener('click', () => {
            list.querySelectorAll('.tt-pop-item:not([style*="display: none"]) input').forEach(cb => cb.checked = true);
        });
        pop.querySelector('.tt-pop-clear').addEventListener('click', () => {
            list.querySelectorAll('.tt-pop-item:not([style*="display: none"]) input').forEach(cb => cb.checked = false);
        });
        pop.querySelector('.tt-pop-cancel').addEventListener('click', closePopover);
        pop.querySelector('.tt-pop-apply').addEventListener('click', () => {
            const checked = new Set();
            list.querySelectorAll('input:checked').forEach(cb => checked.add(cb.value));
            if (checked.size === sorted.length) delete filters[colKey];
            else filters[colKey] = checked;
            closePopover();
            renderTable();
        });
    }

    // --- Columns drawer (show/hide + drag-reorder) -------------------
    function openColumnsDrawer(anchorEl) {
        closePopover();
        const pop = document.createElement('div');
        pop.className = 'tt-pop tt-cols-pop';
        pop.innerHTML = `
            <h4>Columns</h4>
            <div class="tt-cols-hint">Drag to reorder. Tick to show.</div>
            <div class="tt-cols-list">
                ${columnState.map(c => {
                    const col = COL_BY_KEY[c.key];
                    if (!col) return '';
                    return `
                        <div class="tt-cols-item" draggable="true" data-col-key="${esc(c.key)}">
                            <span class="tt-cols-drag">⋮⋮</span>
                            <input type="checkbox" ${c.visible ? 'checked' : ''} data-toggle-key="${esc(c.key)}">
                            <span>${esc(col.label)}</span>
                        </div>`;
                }).join('')}
            </div>`;
        document.body.appendChild(pop);
        positionPopover(pop, anchorEl);
        openPopover = pop;

        const list = pop.querySelector('.tt-cols-list');
        list.querySelectorAll('.tt-cols-item').forEach(item => {
            const key = item.dataset.colKey;
            item.querySelector('input').addEventListener('change', e => {
                const entry = columnState.find(c => c.key === key);
                if (entry) {
                    entry.visible = e.target.checked;
                    renderTable();
                    savePreferences();
                }
            });
            item.addEventListener('dragstart', e => {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', key);
                item.classList.add('dragging');
            });
            item.addEventListener('dragend', () => {
                item.classList.remove('dragging');
                list.querySelectorAll('.tt-cols-item').forEach(i => i.classList.remove('drag-over'));
            });
            item.addEventListener('dragover', e => {
                e.preventDefault();
                list.querySelectorAll('.tt-cols-item').forEach(i => i.classList.remove('drag-over'));
                item.classList.add('drag-over');
            });
            item.addEventListener('drop', e => {
                e.preventDefault();
                const from = e.dataTransfer.getData('text/plain');
                if (from && from !== key) {
                    reorderColumn(from, key);
                    closePopover();
                    openColumnsDrawer(anchorEl);
                }
            });
        });
    }

    // --- Popover positioning + cleanup -------------------------------
    function positionPopover(pop, anchorEl) {
        const r = anchorEl.getBoundingClientRect();
        pop.style.visibility = 'hidden';
        pop.style.left = '0px';
        pop.style.top = '0px';
        const pw = pop.offsetWidth || 240;
        const left = Math.max(8, Math.min(r.left, window.innerWidth - pw - 8));
        pop.style.left = `${left}px`;
        pop.style.top = `${r.bottom + 4 + window.scrollY}px`;
        pop.style.visibility = 'visible';
    }

    function closePopover() {
        if (openPopover && openPopover.parentNode) openPopover.parentNode.removeChild(openPopover);
        openPopover = null;
    }

    // --- CSV export ---------------------------------------------------
    window.ttExportCSV = function () {
        const cols = visibleColumns();
        const rows = applyFiltersAndSort();
        const header = cols.map(c => csvCell(c.label)).join(',');
        const body = rows.map(row => cols.map(c => csvCell(c.display(row))).join(',')).join('\n');
        const csv = '﻿' + header + '\n' + body;  // BOM for Excel UTF-8
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `tasks-${formatDateStr()}.csv`;
        a.click();
        URL.revokeObjectURL(url);
        if (window.showToast) showToast(`Exported ${rows.length} task${rows.length === 1 ? '' : 's'} to CSV`, 'success');
    };

    function csvCell(v) {
        const s = String(v == null ? '' : v);
        if (s.indexOf('"') !== -1 || s.indexOf(',') !== -1 || s.indexOf('\n') !== -1) {
            return '"' + s.replace(/"/g, '""') + '"';
        }
        return s;
    }

    function formatDateStr() {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    }

    function formatDate(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleDateString();
    }

    // --- Utilities ---------------------------------------------------
    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Expose what the inline onchange handlers need.
    window.TasksTable = { saveCell };
})();
