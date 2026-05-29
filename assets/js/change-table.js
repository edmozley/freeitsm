/**
 * FreeITSM Change Management — table view config
 *
 * Supplies the change-specific pieces to the shared data-table engine
 * (assets/js/data-table.js): the COLUMNS catalogue and change loading. The table
 * is read-only — clicking a row deep-links to that change record. Inline editing
 * is intentionally omitted: change save.php rewrites the whole record (and would
 * null out longtext fields the list endpoint doesn't return), so edits belong in
 * the full change form, not in cells.
 */
(function () {
    'use strict';

    function fmt(raw) { return raw ? String(raw).replace('T', ' ').slice(0, 16) : ''; }

    const COLUMNS = [
        { key: 'id', label: 'Ref', type: 'number', defaultVisible: true, defaultOrder: 0,
          display: c => (c.id != null ? '#' + c.id : ''), value: c => Number(c.id) },
        { key: 'title', label: 'Title', type: 'string', defaultVisible: true, defaultOrder: 1,
          display: c => c.title || '' },
        { key: 'change_type', label: 'Type', type: 'string', defaultVisible: true, defaultOrder: 2,
          display: c => c.change_type || '' },
        { key: 'status', label: 'Status', type: 'string', defaultVisible: true, defaultOrder: 3,
          display: c => c.status || '' },
        { key: 'priority', label: 'Priority', type: 'string', defaultVisible: true, defaultOrder: 4,
          display: c => c.priority || '' },
        { key: 'impact', label: 'Impact', type: 'string', defaultVisible: false, defaultOrder: 5,
          display: c => c.impact || '' },
        { key: 'risk_level', label: 'Risk', type: 'string', defaultVisible: true, defaultOrder: 6,
          display: c => c.risk_level || '' },
        { key: 'assigned_to_name', label: 'Assigned to', type: 'string', defaultVisible: true, defaultOrder: 7,
          display: c => c.assigned_to_name || '' },
        { key: 'requester_name', label: 'Requester', type: 'string', defaultVisible: false, defaultOrder: 8,
          display: c => c.requester_name || '' },
        { key: 'category', label: 'Category', type: 'string', defaultVisible: false, defaultOrder: 9,
          display: c => c.category || '' },
        { key: 'work_start_datetime', label: 'Work start', type: 'date', defaultVisible: true, defaultOrder: 10,
          value: c => c.work_start_datetime || '', display: c => fmt(c.work_start_datetime) },
        { key: 'work_end_datetime', label: 'Work end', type: 'date', defaultVisible: false, defaultOrder: 11,
          value: c => c.work_end_datetime || '', display: c => fmt(c.work_end_datetime) },
        { key: 'created_datetime', label: 'Created', type: 'date', defaultVisible: false, defaultOrder: 12,
          value: c => c.created_datetime || '', display: c => fmt(c.created_datetime) },
        { key: 'modified_datetime', label: 'Modified', type: 'date', defaultVisible: false, defaultOrder: 13,
          value: c => c.modified_datetime || '', display: c => fmt(c.modified_datetime) },
    ];

    createDataTable({
        accent: '#00897b',
        prefApi: '../api/system/',
        prefKey: 'change_table_v1',
        noun: 'change',
        exportName: 'changes',
        defaultSort: { key: 'modified_datetime', dir: 'desc' },
        columns: COLUMNS,
        hideCount: true,   // the toolbar's right slot holds the read-only note instead
        onRowClick: row => { window.location.href = `index.php?change=${row.id}`; },

        load: async () => {
            const d = await fetch('../api/change-management/list.php').then(r => r.json());
            if (!d.success) { console.error('change list:', d.error); return []; }
            return d.changes || [];
        },
    });

    // Read-only note in place of the row count → opens the plain-English explainer.
    document.addEventListener('DOMContentLoaded', () => {
        const slot = document.getElementById('dtCount');
        if (slot) {
            slot.innerHTML =
                '<button type="button" class="dt-readonly-note" id="readonlyNote">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line>' +
                '</svg>Read only — click to learn more</button>';
        }

        const modal = document.getElementById('readonlyModal');
        const open = () => modal && modal.classList.add('active');
        const close = () => modal && modal.classList.remove('active');

        const note = document.getElementById('readonlyNote');
        if (note) note.addEventListener('click', open);
        const closeBtn = document.getElementById('readonlyModalClose');
        if (closeBtn) closeBtn.addEventListener('click', close);
        if (modal) modal.addEventListener('click', e => { if (e.target === modal) close(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    });
})();
