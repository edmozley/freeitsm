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

    const COLUMNS = [
        { key: 'hostname',          label: 'Hostname',        type: 'string', defaultVisible: true,  defaultOrder: 0  },
        { key: 'asset_type_name',   label: 'Type',            type: 'string', defaultVisible: true,  defaultOrder: 1  },
        { key: 'asset_status_name', label: 'Status',          type: 'string', defaultVisible: true,  defaultOrder: 2  },
        { key: 'manufacturer',      label: 'Manufacturer',    type: 'string', defaultVisible: true,  defaultOrder: 3  },
        { key: 'model',             label: 'Model',           type: 'string', defaultVisible: true,  defaultOrder: 4  },
        { key: 'operating_system',  label: 'OS',              type: 'string', defaultVisible: true,  defaultOrder: 5  },
        { key: 'feature_release',   label: 'Feature release', type: 'string', defaultVisible: false, defaultOrder: 6  },
        { key: 'build_number',      label: 'Build',           type: 'string', defaultVisible: false, defaultOrder: 7  },
        { key: 'service_tag',       label: 'Service tag',     type: 'string', defaultVisible: false, defaultOrder: 8  },
        { key: 'cpu_name',          label: 'CPU',             type: 'string', defaultVisible: false, defaultOrder: 9  },
        { key: 'speed',             label: 'CPU speed',       type: 'number', defaultVisible: false, defaultOrder: 10 },
        { key: 'memory',            label: 'Memory',          type: 'number', defaultVisible: false, defaultOrder: 11 },
        { key: 'bios_version',      label: 'BIOS',            type: 'string', defaultVisible: false, defaultOrder: 12 },
        { key: 'user_count',        label: 'Assigned users',  type: 'number', defaultVisible: true,  defaultOrder: 13 },
        { key: 'location_path',     label: 'Location',        type: 'string', defaultVisible: true,  defaultOrder: 14 },
        { key: 'purchase_date',     label: 'Purchase date',   type: 'date',   defaultVisible: false, defaultOrder: 15 },
        { key: 'purchase_cost',     label: 'Cost',            type: 'number', defaultVisible: false, defaultOrder: 16 },
        { key: 'supplier',          label: 'Supplier',        type: 'string', defaultVisible: false, defaultOrder: 17 },
        { key: 'warranty_expiry',   label: 'Warranty expiry', type: 'date',   defaultVisible: false, defaultOrder: 18 },
    ];

    createDataTable({
        accent: '#0078d4',
        prefApi: '../api/system/',
        prefKey: 'asset_table_v1',
        noun: 'asset',
        exportName: 'assets',
        defaultSort: { key: 'hostname', dir: 'asc' },
        columns: COLUMNS,
        onRowClick: row => { window.location.href = `index.php?asset=${row.id}`; },
        pdf: { title: 'Assets', headFill: [0, 120, 212], logo: '../assets/images/CompanyLogo.png' },

        load: async () => {
            const d = await fetch('../api/assets/get_assets.php').then(r => r.json());
            if (!d.success) { console.error('get_assets:', d.error); return []; }
            return d.assets || [];
        },
    });
})();
