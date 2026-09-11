/**
 * FreeITSM Asset Management — table view config
 *
 * Supplies the asset-specific pieces to the shared data-table engine
 * (assets/js/data-table.js): the COLUMNS catalogue and asset loading. The table
 * is read-only — clicking a row deep-links to the split-pane view for that
 * asset — and adds PDF export on top of the shared CSV. Everything else
 * (sort/filter/search/columns/preferences) is the shared engine.
 */
(function () {
    'use strict';

    const tt = (k, p) => (window.t ? window.t('asset-management.' + k, p) : k);

    const COLUMNS = [
        { key: 'hostname',          label: tt('table.col_hostname'),        type: 'string', defaultVisible: true,  defaultOrder: 0  },
        { key: 'asset_type_name',   label: tt('field.type'),                type: 'string', defaultVisible: true,  defaultOrder: 1  },
        { key: 'asset_status_name', label: tt('field.status'),              type: 'string', defaultVisible: true,  defaultOrder: 2  },
        { key: 'manufacturer',      label: tt('field.manufacturer'),        type: 'string', defaultVisible: true,  defaultOrder: 3  },
        { key: 'model',             label: tt('field.model'),               type: 'string', defaultVisible: true,  defaultOrder: 4  },
        { key: 'operating_system',  label: tt('table.col_os'),              type: 'string', defaultVisible: true,  defaultOrder: 5  },
        { key: 'feature_release',   label: tt('field.feature_release'),     type: 'string', defaultVisible: false, defaultOrder: 6  },
        { key: 'build_number',      label: tt('table.col_build'),           type: 'string', defaultVisible: false, defaultOrder: 7  },
        { key: 'service_tag',       label: tt('detail.service_tag'),        type: 'string', defaultVisible: false, defaultOrder: 8  },
        { key: 'cpu_name',          label: tt('field.cpu'),                 type: 'string', defaultVisible: false, defaultOrder: 9  },
        { key: 'speed',             label: tt('field.cpu_speed'),           type: 'number', defaultVisible: false, defaultOrder: 10 },
        { key: 'memory',            label: tt('field.memory'),              type: 'number', defaultVisible: false, defaultOrder: 11 },
        { key: 'bios_version',      label: tt('table.col_bios'),            type: 'string', defaultVisible: false, defaultOrder: 12 },
        { key: 'user_count',        label: tt('table.col_assigned_users'),  type: 'number', defaultVisible: true,  defaultOrder: 13 },
        { key: 'location_path',     label: tt('field.location'),            type: 'string', defaultVisible: true,  defaultOrder: 14 },
        { key: 'purchase_date',     label: tt('field.purchase_date'),       type: 'date',   defaultVisible: false, defaultOrder: 15 },
        { key: 'purchase_cost',     label: tt('table.col_cost'),            type: 'number', defaultVisible: false, defaultOrder: 16 },
        { key: 'supplier_name',     label: tt('field.supplier'),            type: 'string', defaultVisible: false, defaultOrder: 17 },
        { key: 'warranty_expiry',   label: tt('field.warranty_expiry'),     type: 'date',   defaultVisible: false, defaultOrder: 18 },
        // When the inventory agent last reported (discussion #97).
        //
        // 🔑 SORTED, not filtered, is the point. The per-column filter is a list
        // of distinct values, which a timestamp defeats — but sorting this
        // column ascending puts the machines that stopped reporting at the top,
        // which is the question people actually bring to it.
        //
        // ⚠️ `display`, not the raw value: these are stored UTC and the reader
        // may be twelve hours from it. `type: 'date'` still sorts on the raw
        // string, which is correct precisely because it is UTC.
        //
        // Last seen ships VISIBLE and first seen hidden: staleness is worth
        // putting in front of people, an install date is worth having available.
        // A saved view or a column layout from before this update keeps its own
        // arrangement and gains these on the end — see applyColumnConfig().
        { key: 'last_seen',         label: tt('field.last_seen'),           type: 'date',   defaultVisible: true,  defaultOrder: 19,
          display: r => r.last_seen ? fmtDateTime(parseUTCDate(r.last_seen)) : tt('field.never_seen') },
        { key: 'first_seen',        label: tt('field.first_seen'),          type: 'date',   defaultVisible: false, defaultOrder: 20,
          display: r => r.first_seen ? fmtDateTime(parseUTCDate(r.first_seen)) : tt('field.never_seen') },
    ];

    /**
     * Custom field columns (docs/design/flexible-asset-fields.md §8).
     *
     * Handed over by table.php as a global rather than fetched here: the shared
     * engine boots on DOMContentLoaded, so an await before createDataTable()
     * would mean the event had already fired and the table never built.
     *
     * Hidden by default. Somebody who ticked "offer as a column" said it should
     * be AVAILABLE, not that everybody should have it forced on — the column
     * picker is where it gets turned on, and each analyst's choice is already
     * remembered.
     */
    (window.assetCustomColumns || []).forEach((c, i) => {
        COLUMNS.push({
            key: c.key,
            label: c.label,
            type: c.type,
            defaultVisible: false,
            defaultOrder: 100 + i,   // after every built-in, whatever gets added later
        });
    });

    /**
     * "Not seen in N days", arrived at from the Watchtower assets card (#97).
     *
     * 🔑 THE SAME RULE THE COUNT USED. includes/watchtower_queries.php counts
     * `last_seen IS NOT NULL AND last_seen < now - 7 days` — a machine that has
     * NEVER reported is deliberately not in that number, because it is not a
     * machine that stopped, it is a television somebody typed in. Filtering
     * differently here would land you on a list longer than the badge you
     * clicked, which is the fastest way to stop trusting a dashboard.
     *
     * ⚠️ The banner is required, not decoration. A table quietly showing nine of
     * five hundred assets is indistinguishable from a table showing all of them,
     * and the way out has to be on screen.
     */
    function staleDaysFromUrl() {
        const raw = new URLSearchParams(location.search).get('stale');
        if (raw === null) return null;
        const n = parseInt(raw, 10);
        return Number.isFinite(n) && n > 0 && n <= 3650 ? n : null;
    }

    function applyStaleFilter(rows) {
        const days = staleDaysFromUrl();
        if (days === null) return rows;

        const cutoff = Date.now() - days * 86400000;
        const kept = rows.filter(r => {
            if (!r.last_seen) return false;          // never reported — see above
            const d = parseUTCDate(r.last_seen);
            return d && !isNaN(d.getTime()) && d.getTime() < cutoff;
        });

        const notice = document.getElementById('assetStaleNotice');
        if (notice) {
            const key = kept.length === 1 ? 'table.stale_notice_one' : 'table.stale_notice';
            const text = tt(key, { count: kept.length, days: days });
            const href = location.pathname;
            notice.innerHTML = '<span></span><a></a>';
            notice.firstChild.textContent = text;
            const link = notice.lastChild;
            link.textContent = tt('table.stale_show_all');
            link.href = href;
            notice.hidden = false;
            // .dt-layout is height:100% against .dt-page, so a banner above it
            // pushes the table's bottom off the screen. Adjusted here rather
            // than in data-table.css because three other tables share that rule
            // and none of them has a banner.
            const layout = document.querySelector('.dt-layout');
            if (layout) {
                layout.style.height = 'auto';
                layout.style.flex = '1';
                layout.style.minHeight = '0';
            }
        }
        return kept;
    }

    createDataTable({
        accent: '#0078d4',
        prefApi: '../api/system/',
        prefKey: 'asset_table_v1',
        viewsKey: 'assets',   // saved views (#96); viewsApi is derived from prefApi
        noun: 'asset',
        exportName: 'assets',
        defaultSort: { key: 'hostname', dir: 'asc' },
        columns: COLUMNS,
        // `asset_id`, NOT `asset` (issue #84). The split-pane view reads only
        // `asset_id` — the spelling includes/entity_links.php declares canonical
        // and the ticket inbox already uses — so `asset` opened the module and
        // then sat there with nothing selected, which reads as "the asset failed
        // to load" rather than as a bad link.
        onRowClick: row => { window.location.href = `index.php?asset_id=${row.id}`; },
        pdf: { title: tt('nav.assets'), headFill: [0, 120, 212], logo: '../assets/images/CompanyLogo.png' },

        load: async () => {
            const d = await fetch('../api/assets/get_assets.php').then(r => r.json());
            if (!d.success) { console.error('get_assets:', d.error); return []; }
            return applyStaleFilter(d.assets || []);
        },
    });
})();
