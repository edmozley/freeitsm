/**
 * FreeITSM Calendar — table view config
 *
 * Supplies the calendar-specific pieces to the shared data-table engine
 * (assets/js/data-table.js): the COLUMNS catalogue, event loading, and
 * inline-edit persistence. Everything else (sort/filter/search/CSV/columns
 * drawer/preferences) is the shared engine.
 *
 * Inline editing: the calendar's save_event.php rewrites the whole row, so each
 * cell save posts the full in-memory event with the one changed field applied.
 */
(function () {
    'use strict';

    const API_BASE = '../../api/calendar/';
    let categories = [];

    const T = (k) => (window.t ? window.t(k) : k);

    function fmt(raw) { return raw ? String(raw).replace('T', ' ').slice(0, 16) : ''; }

    const COLUMNS = [
        { key: 'title', label: T('calendar.table.col_title'), type: 'string', defaultVisible: true, defaultOrder: 0,
          display: e => e.title || '', editable: { kind: 'text' } },
        { key: 'category_id', label: T('calendar.table.col_category'), type: 'string', defaultVisible: true, defaultOrder: 1,
          display: e => e.category_name || '',
          editable: { kind: 'lookup', listKey: 'categories', valueKey: 'id', labelKey: 'name', allowNull: true, nullLabel: '—', colourKey: 'color' } },
        { key: 'start_datetime', label: T('calendar.table.col_start'), type: 'date', defaultVisible: true, defaultOrder: 2,
          value: e => e.start_datetime || '', display: e => fmt(e.start_datetime), editable: { kind: 'datetime' } },
        { key: 'end_datetime', label: T('calendar.table.col_end'), type: 'date', defaultVisible: true, defaultOrder: 3,
          value: e => e.end_datetime || '', display: e => fmt(e.end_datetime), editable: { kind: 'datetime' } },
        { key: 'all_day', label: T('calendar.table.col_all_day'), type: 'string', defaultVisible: true, defaultOrder: 4,
          value: e => (e.all_day ? 1 : 0), display: e => (e.all_day ? T('common.yes') : T('common.no')), editable: { kind: 'bool' } },
        { key: 'location', label: T('calendar.table.col_location'), type: 'string', defaultVisible: true, defaultOrder: 5,
          display: e => e.location || '', editable: { kind: 'text' } },
        { key: 'description', label: T('calendar.table.col_description'), type: 'string', defaultVisible: false, defaultOrder: 6,
          display: e => e.description || '', editable: { kind: 'text' } },
        { key: 'created_by_name', label: T('calendar.table.col_created_by'), type: 'string', defaultVisible: false, defaultOrder: 7,
          display: e => e.created_by_name || '' },
        { key: 'created_at', label: T('calendar.table.col_created'), type: 'date', defaultVisible: false, defaultOrder: 8,
          value: e => e.created_at || '', display: e => fmt(e.created_at) },
    ];

    async function loadCategories() {
        try {
            const d = await fetch(API_BASE + 'get_categories.php').then(r => r.json());
            if (d.success) categories = d.categories || [];
        } catch (e) { console.error('Failed to load categories:', e); }
    }

    createDataTable({
        accent: '#ef6c00',
        prefApi: '../../api/system/',
        prefKey: 'calendar_table_v1',
        noun: 'event',
        exportName: 'calendar-events',
        defaultSort: { key: 'start_datetime', dir: 'asc' },
        columns: COLUMNS,
        getLookups: () => ({ categories }),

        load: async () => {
            await loadCategories();
            const d = await fetch(API_BASE + 'get_events.php?all=1').then(r => r.json());
            if (!d.success) return [];
            return (d.events || []).map(e => ({ ...e, all_day: e.all_day ? 1 : 0 }));
        },

        onSaveCell: async (row, col, value) => {
            row[col.key] = value;
            if (col.key === 'category_id') {
                const c = categories.find(x => String(x.id) === String(value));
                row.category_name = c ? c.name : null;
                row.category_color = c ? c.color : null;
            }
            if (col.key === 'start_datetime' && !value) throw new Error(T('calendar.table.start_required'));

            const payload = {
                id: row.id,
                title: row.title || '',
                description: row.description || '',
                category_id: row.category_id || '',
                start_datetime: row.start_datetime,
                end_datetime: row.end_datetime || '',
                all_day: row.all_day ? 1 : 0,
                location: row.location || '',
                contract_id: row.contract_id || '',
            };
            const d = await fetch(API_BASE + 'save_event.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            }).then(r => r.json());
            if (!d.success) throw new Error(d.error || T('calendar.table.save_failed'));
        },
    });
})();
