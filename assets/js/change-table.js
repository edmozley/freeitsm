/**
 * FreeITSM Change Management — table view config
 *
 * Supplies the change-specific pieces to the shared data-table engine
 * (assets/js/data-table.js): the COLUMNS catalogue, change loading, and inline
 * editing for the low-risk list-level fields (priority, impact, type, assignee).
 *
 * Why only those fields edit inline: the full change save (save.php) rewrites
 * the whole record, and the list endpoint only returns a summary — so a generic
 * cell-save would blank out the longtext fields it doesn't carry. Instead, each
 * edit posts ONE field to a dedicated update_field.php that touches one column
 * and writes one audit row. Status is intentionally NOT editable here so a cell
 * can't bypass CAB voting / the approval workflow; the description, test/rollback
 * plans and risk scoring stay in the full form too. Click any row to open it.
 */
(function () {
    'use strict';

    const API_BASE = '../api/change-management/';

    let priorities = [], impacts = [], types = [], analysts = [];
    let lookupsLoaded = false;

    // Server-stamped UTC timestamp (created / modified) → analyst display zone,
    // as 'YYYY-MM-DD HH:MM' (parseUTCDate / tzOpts from assets/js/tz.js, loaded
    // before this file). Only the display text is zoned; the column's `value`
    // (used for sorting) stays the raw UTC string so ordering is unaffected.
    function fmt(raw) {
        if (!raw) return '';
        const d = parseUTCDate(raw);
        if (!d || isNaN(d.getTime())) return String(raw).replace('T', ' ').slice(0, 16);
        const datePart = d.toLocaleDateString('en-CA', tzOpts()); // YYYY-MM-DD
        const timePart = d.toLocaleTimeString('en-GB', tzOpts({ hour: '2-digit', minute: '2-digit', hour12: false })); // HH:MM
        return datePart + ' ' + timePart;
    }

    // NAIVE wall-clock scheduling datetime (planned work start / end) — shown
    // exactly as typed for every analyst, NO zone conversion (parseNaiveDate
    // from tz.js). Same 'YYYY-MM-DD HH:MM' shape as fmt().
    function fmtNaive(raw) {
        if (!raw) return '';
        const d = parseNaiveDate(raw);
        if (!d || isNaN(d.getTime())) return String(raw).replace('T', ' ').slice(0, 16);
        const datePart = d.toLocaleDateString('en-CA'); // YYYY-MM-DD, as typed
        const timePart = d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false }); // HH:MM
        return datePart + ' ' + timePart;
    }

    const T = (k) => (window.t ? window.t('change-management.table.' + k) : k);

    const COLUMNS = [
        { key: 'id', label: T('col_ref'), type: 'number', defaultVisible: true, defaultOrder: 0,
          display: c => (c.id != null ? '#' + c.id : ''), value: c => Number(c.id) },
        { key: 'title', label: T('col_title'), type: 'string', defaultVisible: true, defaultOrder: 1,
          display: c => c.title || '' },
        { key: 'change_type', label: T('col_type'), type: 'string', defaultVisible: true, defaultOrder: 2,
          display: c => c.change_type || '',
          editable: { kind: 'lookup', listKey: 'types', valueKey: 'name', labelKey: 'name', colourKey: 'colour' } },
        { key: 'status', label: T('col_status'), type: 'string', defaultVisible: true, defaultOrder: 3,
          display: c => c.status || '',
          // Read-only on purpose — open the change to move status (CAB / workflow).
          cellTitle: () => T('status_readonly') },
        { key: 'priority', label: T('col_priority'), type: 'string', defaultVisible: true, defaultOrder: 4,
          display: c => c.priority || '',
          editable: { kind: 'lookup', listKey: 'priorities', valueKey: 'name', labelKey: 'name', colourKey: 'colour' } },
        { key: 'impact', label: T('col_impact'), type: 'string', defaultVisible: false, defaultOrder: 5,
          display: c => c.impact || '',
          editable: { kind: 'lookup', listKey: 'impacts', valueKey: 'name', labelKey: 'name', colourKey: 'colour' } },
        { key: 'risk_level', label: T('col_risk'), type: 'string', defaultVisible: true, defaultOrder: 6,
          display: c => c.risk_level || '' },
        { key: 'assigned_to_id', label: T('col_assigned_to'), type: 'string', defaultVisible: true, defaultOrder: 7,
          display: c => c.assigned_to_name || '',
          editable: { kind: 'lookup', listKey: 'analysts', valueKey: 'id', labelKey: 'name', allowNull: true, nullLabel: '—' } },
        { key: 'requester_name', label: T('col_requester'), type: 'string', defaultVisible: false, defaultOrder: 8,
          display: c => c.requester_name || '' },
        { key: 'category', label: T('col_category'), type: 'string', defaultVisible: false, defaultOrder: 9,
          display: c => c.category || '' },
        { key: 'work_start_datetime', label: T('col_work_start'), type: 'date', defaultVisible: true, defaultOrder: 10,
          value: c => c.work_start_datetime || '', display: c => fmtNaive(c.work_start_datetime) },
        { key: 'work_end_datetime', label: T('col_work_end'), type: 'date', defaultVisible: false, defaultOrder: 11,
          value: c => c.work_end_datetime || '', display: c => fmtNaive(c.work_end_datetime) },
        { key: 'created_datetime', label: T('col_created'), type: 'date', defaultVisible: false, defaultOrder: 12,
          value: c => c.created_datetime || '', display: c => fmt(c.created_datetime) },
        { key: 'modified_datetime', label: T('col_modified'), type: 'date', defaultVisible: false, defaultOrder: 13,
          value: c => c.modified_datetime || '', display: c => fmt(c.modified_datetime) },
    ];

    async function loadLookups() {
        try {
            const [pRes, iRes, tRes, aRes] = await Promise.all([
                fetch(API_BASE + 'get_change_priorities.php').then(r => r.json()),
                fetch(API_BASE + 'get_change_impacts.php').then(r => r.json()),
                fetch(API_BASE + 'get_change_types.php').then(r => r.json()),
                fetch(API_BASE + 'list.php?analysts=1').then(r => r.json()),
            ]);
            if (pRes.success) priorities = (pRes.priorities || []).filter(p => p.is_active);
            if (iRes.success) impacts = (iRes.impacts || []).filter(i => i.is_active);
            if (tRes.success) types = (tRes.types || []).filter(t => t.is_active);
            if (aRes.success) analysts = aRes.analysts || [];
        } catch (e) { console.error('Failed to load change lookups:', e); }
    }

    createDataTable({
        accent: '#00897b',
        prefApi: '../api/system/',
        prefKey: 'change_table_v1',
        noun: 'change',
        exportName: 'changes',
        defaultSort: { key: 'modified_datetime', dir: 'desc' },
        columns: COLUMNS,
        getLookups: () => ({ priorities, impacts, types, analysts }),
        // Click a row (outside an edit control) to open the full change record.
        onRowClick: row => { window.location.href = `index.php?change=${row.id}`; },

        load: async () => {
            // Lookups must be ready before first render so the inline selects
            // (type / priority / impact / assignee) can build their options.
            if (!lookupsLoaded) { await loadLookups(); lookupsLoaded = true; }
            const d = await fetch(API_BASE + 'list.php').then(r => r.json());
            if (!d.success) { console.error('change list:', d.error); return []; }
            return d.changes || [];
        },

        onSaveCell: async (row, col, value) => {
            row[col.key] = value;
            if (col.key === 'assigned_to_id') {
                const a = analysts.find(x => x.id == value);
                row.assigned_to_name = a ? a.name : null;
            }
            const d = await fetch(API_BASE + 'update_field.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: row.id, field: col.key, value }),
            }).then(r => r.json());
            if (!d.success) throw new Error(d.error || T('save_failed'));
        },
    });
})();
