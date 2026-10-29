/**
 * Domains — table view config for the shared data-table engine
 * (assets/js/data-table.js). The engine does everything else: sorting,
 * per-column filters, search, the column drawer, CSV export, saved views.
 *
 * Inline edits post just the one changed field to api/domains/save.php — the
 * service updates only the keys it is sent, and records the change in the
 * domain's history exactly as the edit form would.
 *
 * @wiki Domains-Developer-Guide
 */
(function () {
    'use strict';
    const { T, api, fmtDate, purpose, renewal } = window.Dom;
    let L = { statuses: [], analysts: [], suppliers: [], purposes: [], renewal_modes: [] };

    /** Shows a lock or DNSSEC flag as on, off, or blank when unknown. */
    const yn = v => v === true ? T('lock.on') : (v === false ? T('lock.off') : '');
    /** Shows a days-left number as text, blank when unknown. */
    const days = n => n === null || n === undefined ? '' : String(n);

    const COLUMNS = [
        { key: 'domain_name', label: T('col.domain'), type: 'string', defaultVisible: true, defaultOrder: 0, display: r => r.display_name || r.domain_name },
        { key: 'company_name', label: T('field.company'), type: 'string', defaultVisible: false, defaultOrder: 1, display: r => r.company_name || '' },
        { key: 'status_id', label: T('col.status'), type: 'string', defaultVisible: true, defaultOrder: 2, display: r => r.status_name || '',
          editable: { kind: 'lookup', listKey: 'statuses', valueKey: 'id', labelKey: 'name', allowNull: true, nullLabel: '—', colourKey: 'colour' } },
        { key: 'purpose', label: T('field.purpose'), type: 'string', defaultVisible: true, defaultOrder: 3, display: r => purpose(r.purpose),
          editable: { kind: 'lookup', listKey: 'purposes', valueKey: 'id', labelKey: 'name' } },
        { key: 'expiry_date', label: T('col.expires'), type: 'date', defaultVisible: true, defaultOrder: 4, value: r => r.expiry_date || '', display: r => fmtDate(r.expiry_date), editable: { kind: 'date' } },
        { key: 'days_left', label: T('table.days_left'), type: 'number', defaultVisible: true, defaultOrder: 5, value: r => r.days_left === null ? 999999 : r.days_left, display: r => days(r.days_left) },
        { key: 'renewal_mode', label: T('col.renewal'), type: 'string', defaultVisible: true, defaultOrder: 6, display: r => renewal(r.renewal_mode),
          editable: { kind: 'lookup', listKey: 'renewal_modes', valueKey: 'id', labelKey: 'name' } },
        { key: 'registrar_supplier_id', label: T('col.registrar'), type: 'string', defaultVisible: true, defaultOrder: 7, display: r => r.supplier_name || r.registrar_name || '',
          editable: { kind: 'lookup', listKey: 'suppliers', valueKey: 'id', labelKey: 'name', allowNull: true, nullLabel: '—' } },
        { key: 'account_name', label: T('field.registrar_account'), type: 'string', defaultVisible: false, defaultOrder: 8, display: r => r.account_name || '' },
        { key: 'security_grade', label: T('col.grade'), type: 'string', defaultVisible: true, defaultOrder: 9, value: r => r.security_score === null ? -1 : r.security_score, display: r => r.scope === 'internal' ? T('health.' + ((r.health || {}).state || 'not_checked')) : (r.security_grade || '') },   // GH #203: health for an internal domain
        { key: 'transfer_lock', label: T('field.transfer_lock'), type: 'string', defaultVisible: true, defaultOrder: 10, display: r => yn(r.transfer_lock) },
        { key: 'registry_lock', label: T('field.registry_lock'), type: 'string', defaultVisible: false, defaultOrder: 11, display: r => r.registry_lock ? T('lock.on') : '' },
        { key: 'dnssec', label: T('field.dnssec'), type: 'string', defaultVisible: false, defaultOrder: 12, display: r => yn(r.dnssec) },
        { key: 'ssl_expiry_date', label: T('field.ssl_expiry'), type: 'date', defaultVisible: true, defaultOrder: 13, value: r => r.ssl_expiry_date || '', display: r => fmtDate(r.ssl_expiry_date) },
        { key: 'owner_analyst_id', label: T('col.owner'), type: 'string', defaultVisible: true, defaultOrder: 14, display: r => r.owner_name || '',
          editable: { kind: 'lookup', listKey: 'analysts', valueKey: 'id', labelKey: 'name', allowNull: true, nullLabel: '—' } },
        { key: 'tags', label: T('field.tags'), type: 'string', defaultVisible: true, defaultOrder: 15, display: r => r.tags || '', editable: { kind: 'text' } },
        { key: 'registration_date', label: T('field.registration_date'), type: 'date', defaultVisible: false, defaultOrder: 16, value: r => r.registration_date || '', display: r => fmtDate(r.registration_date), editable: { kind: 'date' } },
        { key: 'registrant_name', label: T('field.registrant_name'), type: 'string', defaultVisible: false, defaultOrder: 17, display: r => r.registrant_name || '', editable: { kind: 'text' } },
        { key: 'dns_provider', label: T('field.dns_provider'), type: 'string', defaultVisible: false, defaultOrder: 18, display: r => r.dns_provider || '', editable: { kind: 'text' } },
        { key: 'hosting_provider', label: T('field.hosting_provider'), type: 'string', defaultVisible: false, defaultOrder: 19, display: r => r.hosting_provider || '', editable: { kind: 'text' } },
        { key: 'cost', label: T('field.cost'), type: 'number', defaultVisible: false, defaultOrder: 20, value: r => r.cost === null ? -1 : Number(r.cost), display: r => r.cost === null ? '' : String(r.cost), editable: { kind: 'text' } },
        { key: 'currency', label: T('field.currency'), type: 'string', defaultVisible: false, defaultOrder: 21, display: r => r.currency || '', editable: { kind: 'lookup', listKey: 'currencies', valueKey: 'id', labelKey: 'id', allowNull: true, nullLabel: '—' } },
        { key: 'cost_centre', label: T('field.cost_centre'), type: 'string', defaultVisible: false, defaultOrder: 22, display: r => r.cost_centre_label || r.cost_centre || '' },   // GH #188: a real cost centre; changed on the domain
        { key: 'lookalike_count', label: T('tab.lookalikes'), type: 'number', defaultVisible: false, defaultOrder: 23, display: r => r.lookalike_count ? String(r.lookalike_count) : '' },
        { key: 'last_check_datetime', label: T('field.last_check'), type: 'date', defaultVisible: false, defaultOrder: 24, value: r => r.last_check_datetime || '', display: r => r.last_check_datetime ? fmtDate(r.last_check_datetime) : '' },
        // GH #203: public or internal. Read-only here - changing it is a decision for the Edit dialog, which explains it.
        { key: 'scope', label: T('field.scope'), type: 'string', defaultVisible: false, defaultOrder: 25, display: r => T('scope.' + (r.scope || 'public')) },
    ];

    createDataTable({
        accent: '#4d7c0f',
        prefApi: '../../api/system/',
        prefKey: 'domains_table_v1',
        viewsKey: 'domains',
        noun: 'domain',
        exportName: 'domains',
        defaultSort: { key: 'expiry_date', dir: 'asc' },
        columns: COLUMNS,
        getLookups: () => L,
        onRowClick: r => { location.href = '../view.php?id=' + r.id; },

        load: async () => {
            const d = await api('list.php?with_lookups=1');
            const lk = d.lookups || {};
            L = {
                statuses: lk.statuses || [],
                analysts: lk.analysts || [],
                suppliers: lk.suppliers || [],
                purposes: (lk.purposes || []).map(p => ({ id: p, name: purpose(p) })),
                renewal_modes: (lk.renewal_modes || []).map(m => ({ id: m, name: renewal(m) })),
                // Favourites first, then every other current currency (GH #189).
                currencies: (window.Money ? Money.favourites().concat(Money.current().filter(c => !Money.favourites().includes(c))) : []).map(c => ({ id: c })),
            };
            return d.domains || [];
        },

        onSaveCell: async (row, col, value) => {
            await api('save.php', { id: row.id, [col.key]: value === null ? '' : value });
            row[col.key] = value;
            /** Returns the name of the lookup item with the given id, so the edited cell shows its new label. */
            const pick = (list, v) => (list.find(x => String(x.id) === String(v)) || {}).name || null;
            if (col.key === 'status_id') row.status_name = pick(L.statuses, value);
            if (col.key === 'owner_analyst_id') row.owner_name = pick(L.analysts, value);
            if (col.key === 'registrar_supplier_id') row.supplier_name = pick(L.suppliers, value);
        },
    });
})();
