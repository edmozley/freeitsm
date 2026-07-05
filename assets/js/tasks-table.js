/**
 * FreeITSM Tasks — table view config
 *
 * Supplies the tasks-specific pieces to the shared data-table engine
 * (assets/js/data-table.js): the COLUMNS catalogue (with inline-edit controls),
 * task loading (respecting the My/Team/Analyst sidebar), and per-cell saves to
 * save.php. The sidebar filter handlers and the shared right-click context menu
 * are wired here too; everything else is the shared engine.
 */
(function () {
    'use strict';

    const API_BASE = '../../api/tasks/';

    let analysts = [], teams = [], statuses = [], priorities = [];
    let lookupsLoaded = false, dropdownsLoaded = false;
    let currentFilter = 'my';
    let currentFilterTeamId = null;
    let currentFilterAnalystId = null;
    let table = null;

    // created_datetime is a stored UTC timestamp; render its date in the
    // analyst's chosen display zone (parseUTCDate / tzOpts from assets/js/tz.js).
    function formatDate(iso) {
        if (!iso) return '';
        const d = parseUTCDate(iso);
        return (!d || isNaN(d.getTime())) ? iso : d.toLocaleDateString(undefined, tzOpts());
    }

    const COLUMNS = [
        { key: 'title', label: 'Title', type: 'string', defaultVisible: true, defaultOrder: 0,
          display: t => t.title || '', editable: { kind: 'text' } },
        { key: 'status', label: 'Status', type: 'string', defaultVisible: true, defaultOrder: 1,
          display: t => t.status || '',
          editable: { kind: 'lookup', listKey: 'statuses', valueKey: 'name', labelKey: 'name', colourKey: 'colour' } },
        { key: 'priority', label: 'Priority', type: 'string', defaultVisible: true, defaultOrder: 2,
          display: t => t.priority || '',
          editable: { kind: 'lookup', listKey: 'priorities', valueKey: 'name', labelKey: 'name', colourKey: 'colour' } },
        { key: 'assigned_analyst_id', label: 'Assignee', type: 'string', defaultVisible: true, defaultOrder: 3,
          display: t => t.analyst_name || '',
          editable: { kind: 'lookup', listKey: 'analysts', valueKey: 'id', labelKey: 'name', allowNull: true, nullLabel: '—' } },
        { key: 'assigned_team_id', label: 'Team', type: 'string', defaultVisible: true, defaultOrder: 4,
          display: t => t.team_name || '',
          editable: { kind: 'lookup', listKey: 'teams', valueKey: 'id', labelKey: 'name', allowNull: true, nullLabel: '—' } },
        { key: 'start_date', label: 'Start', type: 'date', defaultVisible: false, defaultOrder: 5,
          display: t => t.start_date || '', editable: { kind: 'date' } },
        { key: 'due_date', label: 'Due', type: 'date', defaultVisible: true, defaultOrder: 6,
          display: t => t.due_date || '', editable: { kind: 'date' } },
        { key: 'created_datetime', label: 'Created', type: 'date', defaultVisible: false, defaultOrder: 7,
          value: t => t.created_datetime || '', display: t => formatDate(t.created_datetime) },
        { key: 'subtask_progress', label: 'Subtasks', type: 'string', defaultVisible: false, defaultOrder: 8,
          display: t => (t.subtasks && t.subtasks.total > 0) ? `${t.subtasks.done}/${t.subtasks.total}` : '—' },
    ];

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
        table.reload();
    };
    window.setTeamFilter = function (id) {
        if (!id) { setFilter('my'); return; }
        currentFilter = 'team';
        currentFilterTeamId = id;
        currentFilterAnalystId = null;
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('analystFilter').value = '';
        table.reload();
    };
    window.setAnalystFilter = function (id) {
        if (!id) { setFilter('my'); return; }
        currentFilter = 'analyst';
        currentFilterAnalystId = id;
        currentFilterTeamId = null;
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('teamFilter').value = '';
        table.reload();
    };

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    table = createDataTable({
        accent: '#7c3aed',
        prefApi: '../../api/system/',
        prefKey: 'tasks_table_v1',
        noun: 'task',
        exportName: 'tasks',
        defaultSort: { key: 'due_date', dir: 'asc' },
        columns: COLUMNS,
        getLookups: () => ({ analysts, teams, statuses, priorities }),

        load: async () => {
            // Lookups must be ready before the first render so the inline
            // status/priority/assignee/team selects can build their options.
            if (!lookupsLoaded) { await loadLookups(); lookupsLoaded = true; }
            if (!dropdownsLoaded) { await loadDropdowns(); dropdownsLoaded = true; }

            let url = API_BASE + 'list.php?filter=' + currentFilter;
            if (currentFilter === 'team' && currentFilterTeamId) url += '&team_id=' + currentFilterTeamId;
            if (currentFilter === 'analyst' && currentFilterAnalystId) url += '&analyst_id=' + currentFilterAnalystId;
            const d = await fetch(url).then(r => r.json());
            return d.success ? d.tasks : [];
        },

        onSaveCell: async (row, col, value) => {
            row[col.key] = value;
            if (col.key === 'assigned_analyst_id') {
                const a = analysts.find(x => x.id == value);
                row.analyst_name = a ? a.name : null;
            }
            if (col.key === 'assigned_team_id') {
                const tm = teams.find(x => x.id == value);
                row.team_name = tm ? tm.name : null;
            }
            if (col.key === 'status') {
                const s = statuses.find(x => x.name === value);
                row.status_colour = s ? s.colour : null;
                row.status_is_closed = s ? s.is_closed : 0;
            }
            if (col.key === 'priority') {
                const p = priorities.find(x => x.name === value);
                row.priority_colour = p ? p.colour : null;
            }
            const d = await fetch(API_BASE + 'save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: row.id, [col.key]: value }),
            }).then(r => r.json());
            if (!d.success) throw new Error(d.error || 'Save failed');
        },
    });

    // Shared right-click context menu (analyst / team / status / priority).
    TasksCtxMenu.init({
        targetSelector: '.dt-row',
        getTaskId: el => parseInt(el.dataset.id, 10),
        getTask: id => table.findRow(id),
        getLookups: () => ({ analysts, teams, statuses, priorities }),
        onUpdate: () => table.reload(),
        apiBase: API_BASE,
    });
})();
