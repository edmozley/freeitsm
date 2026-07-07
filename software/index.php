<?php
/**
 * Software - View software inventory across all managed machines
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'software';
$path_prefix = '../';
$translationNamespaces = ['common', 'software'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('software.inventory.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=15">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        /* Module accent (indigo). */
        body { --accent: var(--sw-accent, #5c6bc0); --accent-hover: var(--sw-accent-hover, #3f51b5); }

        .software-container {
            display: flex;
            flex-direction: column;
            flex: 1;
            overflow: hidden;
            background-color: var(--surface, #fff);
        }

        .software-toolbar {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background-color: var(--surface-2, #f8f9fa);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .software-toolbar h3 {
            margin: 0;
            font-size: 16px;
            color: var(--text, #333);
        }

        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .search-box {
            width: 350px;
            padding: 8px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .search-box:focus {
            outline: none;
            border-color: var(--sw-accent, #5c6bc0);
            box-shadow: 0 0 0 2px rgba(92, 107, 192, 0.15);
        }

        .software-count {
            font-size: 13px;
            color: var(--text-dim, #888);
            white-space: nowrap;
        }

        .filter-tabs {
            display: flex;
            gap: 0;
            padding: 0 20px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background-color: var(--surface, #fff);
        }

        .filter-tab {
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted, #666);
            cursor: pointer;
            border: none;
            background: none;
            border-bottom: 2px solid transparent;
            transition: color 0.15s, border-color 0.15s;
        }

        .filter-tab:hover {
            color: var(--text, #333);
        }

        .filter-tab.active {
            color: var(--sw-accent, #5c6bc0);
            border-bottom-color: var(--sw-accent, #5c6bc0);
        }

        .filter-tab .tab-count {
            display: inline-block;
            background-color: var(--border-soft, #eee);
            color: var(--text-muted, #666);
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 6px;
        }

        .filter-tab.active .tab-count {
            background-color: var(--sw-accent-soft, #e8eaf6);
            color: var(--sw-accent, #5c6bc0);
        }

        .software-table-container {
            flex: 1;
            overflow-y: auto;
        }

        .software-table {
            width: 100%;
            border-collapse: collapse;
        }

        .software-table thead th {
            position: sticky;
            top: 0;
            background-color: var(--surface-2, #f8f9fa);
            padding: 12px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            color: var(--text-muted, #555);
            border-bottom: 2px solid var(--border, #e0e0e0);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            user-select: none;
            z-index: 1;
        }

        .software-table thead th:hover {
            background-color: var(--surface-hover, #eee);
        }

        .software-table thead th.sort-active {
            color: var(--sw-accent, #5c6bc0);
        }

        .software-table thead th .sort-icon {
            margin-left: 4px;
            font-size: 10px;
        }

        .software-table tbody tr.app-row {
            cursor: pointer;
            transition: background-color 0.15s;
        }

        .software-table tbody tr.app-row:hover {
            background-color: var(--surface-hover, #f5f5f5);
        }

        .software-table tbody tr.app-row.expanded {
            background-color: var(--sw-accent-soft, #e8eaf6);
            border-left: 3px solid var(--sw-accent, #5c6bc0);
        }

        .software-table tbody td {
            padding: 10px 20px;
            border-bottom: 1px solid var(--border-soft, #eee);
            font-size: 14px;
            color: var(--text, #333);
        }

        .install-count-badge {
            display: inline-block;
            background-color: var(--sw-accent-soft, #e8eaf6);
            color: var(--sw-accent-hover, #3f51b5);
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 13px;
            min-width: 28px;
            text-align: center;
        }

        /* Modal overlay */
        .detail-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: flex-start;
            justify-content: center;
            padding: 60px 20px;
        }

        .detail-overlay.open {
            display: flex;
        }

        .detail-box {
            background: var(--surface, #fff);
            border-radius: 8px;
            width: 100%;
            max-width: 800px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 30px var(--shadow, rgba(0, 0, 0, 0.2));
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background: linear-gradient(135deg, var(--sw-accent, #5c6bc0), var(--sw-accent-hover, #3f51b5));
            border-radius: 8px 8px 0 0;
            color: white;
        }

        .detail-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }

        .detail-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            width: 32px;
            height: 32px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .detail-close:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .detail-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 24px;
            border-bottom: 1px solid var(--border-soft, #f0f0f0);
            background: var(--surface-2, #f8f9fa);
        }

        .detail-toolbar .machine-count {
            font-size: 13px;
            color: var(--text-muted, #666);
        }

        .export-btn {
            background: var(--sw-accent, #5c6bc0);
            color: white;
            border: none;
            padding: 6px 14px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .export-btn:hover {
            background: var(--sw-accent-hover, #3f51b5);
        }

        .detail-body {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }

        .machine-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--surface, #fff);
            border-radius: 4px;
            overflow: hidden;
            box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.08));
        }

        .machine-table thead th {
            background-color: var(--surface-2, #f0f0f0);
            padding: 8px 15px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted, #666);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .machine-table tbody td {
            padding: 8px 15px;
            font-size: 13px;
            color: var(--text, #333);
            border-bottom: 1px solid var(--border-soft, #eee);
        }

        .machine-table tbody tr:last-child td {
            border-bottom: none;
        }

        .machine-table tbody tr:hover {
            background-color: var(--surface-hover, #f9f9f9);
        }

        .machine-hostname {
            font-family: 'Consolas', 'Courier New', monospace;
            font-weight: 500;
        }

        .detail-loading {
            padding: 20px;
            text-align: center;
            color: var(--text-dim, #888);
            font-size: 13px;
        }

        .loading-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 60px;
        }

        .spinner {
            width: 30px;
            height: 30px;
            border: 3px solid var(--surface-2, #f3f3f3);
            border-top: 3px solid var(--sw-accent, #5c6bc0);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .empty-state {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 60px;
            color: var(--text-dim, #888);
            font-size: 14px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container software-container">
        <div class="software-toolbar">
            <h3><?php echo htmlspecialchars(t('software.inventory.heading')); ?></h3>
            <div class="toolbar-right">
                <input type="text" class="search-box" id="softwareSearch"
                       placeholder="<?php echo htmlspecialchars(t('software.inventory.search')); ?>"
                       oninput="searchSoftware()">
                <span class="software-count" id="softwareCount"></span>
            </div>
        </div>
        <div class="filter-tabs">
            <button class="filter-tab active" data-filter="apps" onclick="switchTab('apps')"><?php echo htmlspecialchars(t('software.inventory.tab_apps')); ?> <span class="tab-count" id="countApps">0</span></button>
            <button class="filter-tab" data-filter="components" onclick="switchTab('components')"><?php echo htmlspecialchars(t('software.inventory.tab_components')); ?> <span class="tab-count" id="countComponents">0</span></button>
            <button class="filter-tab" data-filter="" onclick="switchTab('')"><?php echo htmlspecialchars(t('software.inventory.tab_all')); ?> <span class="tab-count" id="countAll">0</span></button>
        </div>
        <div class="software-table-container">
            <table class="software-table">
                <thead>
                    <tr>
                        <th onclick="sortBy('display_name')" id="thName">
                            <?php echo htmlspecialchars(t('software.inventory.col_name')); ?> <span class="sort-icon">&#9650;</span>
                        </th>
                        <th onclick="sortBy('publisher')" id="thPublisher">
                            <?php echo htmlspecialchars(t('software.inventory.col_publisher')); ?> <span class="sort-icon"></span>
                        </th>
                        <th onclick="sortBy('install_count')" id="thCount">
                            <?php echo htmlspecialchars(t('software.inventory.col_installed')); ?> <span class="sort-icon"></span>
                        </th>
                    </tr>
                </thead>
                <tbody id="softwareTableBody">
                    <tr><td colspan="3">
                        <div class="loading-spinner"><div class="spinner"></div></div>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Machine Detail Modal -->
    <div class="detail-overlay" id="detailOverlay" onclick="if(event.target===this)closeDetail()">
        <div class="detail-box">
            <div class="detail-header">
                <h3 id="modalTitle"><?php echo htmlspecialchars(t('software.inventory.modal_title')); ?></h3>
                <button class="detail-close" onclick="closeDetail()">&times;</button>
            </div>
            <div class="detail-toolbar" id="modalToolbar" style="display:none">
                <span class="machine-count" id="modalCount"></span>
                <button class="export-btn" onclick="exportCSV()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <?php echo htmlspecialchars(t('software.inventory.export_csv')); ?>
                </button>
            </div>
            <div class="detail-body" id="modalBody">
                <div class="detail-loading"><?php echo htmlspecialchars(t('software.inventory.loading_machines')); ?></div>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/software/';
        let allApps = [];
        let filteredApps = [];
        let currentModalApp = null;
        let currentMachines = [];
        let searchTimeout = null;
        let sortColumn = 'display_name';
        let sortDirection = 'asc';
        let activeFilter = 'apps';

        document.addEventListener('DOMContentLoaded', function() {
            loadSoftware();
        });

        async function loadSoftware() {
            try {
                // Load all apps to get counts, then filter client-side
                const response = await fetch(API_BASE + 'get_apps.php');
                const data = await response.json();
                if (data.success) {
                    allApps = data.apps;
                    updateTabCounts();
                    applyFilters();
                } else {
                    document.getElementById('softwareTableBody').innerHTML =
                        '<tr><td colspan="3"><div class="empty-state">' + window.t('software.inventory.load_error', { message: escapeHtml(data.error) }) + '</div></td></tr>';
                }
            } catch (error) {
                console.error('Error loading software:', error);
                document.getElementById('softwareTableBody').innerHTML =
                    '<tr><td colspan="3"><div class="empty-state">' + window.t('software.inventory.load_failed') + '</div></td></tr>';
            }
        }

        function updateTabCounts() {
            const apps = allApps.filter(a => !parseInt(a.system_component));
            const components = allApps.filter(a => parseInt(a.system_component));
            document.getElementById('countApps').textContent = apps.length;
            document.getElementById('countComponents').textContent = components.length;
            document.getElementById('countAll').textContent = allApps.length;
        }

        function switchTab(filter) {
            activeFilter = filter;
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.classList.toggle('active', tab.dataset.filter === filter);
            });
            applyFilters();
        }

        function applyFilters() {
            // Apply tab filter
            let apps = allApps;
            if (activeFilter === 'apps') {
                apps = apps.filter(a => !parseInt(a.system_component));
            } else if (activeFilter === 'components') {
                apps = apps.filter(a => parseInt(a.system_component));
            }

            // Apply search filter
            const search = document.getElementById('softwareSearch').value.toLowerCase().trim();
            if (search !== '') {
                apps = apps.filter(app =>
                    (app.display_name || '').toLowerCase().includes(search) ||
                    (app.publisher || '').toLowerCase().includes(search)
                );
            }

            filteredApps = apps;
            applySortAndRender();
        }

        function searchSoftware() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                applyFilters();
            }, 300);
        }

        function sortBy(column) {
            if (sortColumn === column) {
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                sortColumn = column;
                sortDirection = 'asc';
            }
            applySortAndRender();
        }

        function applySortAndRender() {
            filteredApps.sort((a, b) => {
                let valA, valB;
                if (sortColumn === 'install_count') {
                    valA = parseInt(a[sortColumn]) || 0;
                    valB = parseInt(b[sortColumn]) || 0;
                } else {
                    valA = (a[sortColumn] || '').toString().toLowerCase();
                    valB = (b[sortColumn] || '').toString().toLowerCase();
                }
                if (valA < valB) return sortDirection === 'asc' ? -1 : 1;
                if (valA > valB) return sortDirection === 'asc' ? 1 : -1;
                return 0;
            });
            renderTable();
            updateSortIndicators();
        }

        function renderTable() {
            const tbody = document.getElementById('softwareTableBody');
            const countEl = document.getElementById('softwareCount');

            const n = filteredApps.length;
            if (activeFilter === 'components') {
                countEl.textContent = window.t(n !== 1 ? 'software.inventory.count_components' : 'software.inventory.count_component', { count: n });
            } else {
                countEl.textContent = window.t(n !== 1 ? 'software.inventory.count_apps' : 'software.inventory.count_app', { count: n });
            }

            if (filteredApps.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3"><div class="empty-state">' + window.t('software.inventory.none') + '</div></td></tr>';
                return;
            }

            tbody.innerHTML = filteredApps.map(app => `
                <tr class="app-row" onclick="showDetail(${app.id}, '${escapeHtml(app.display_name).replace(/'/g, "\\'")}')">
                    <td>${escapeHtml(app.display_name)}</td>
                    <td>${escapeHtml(app.publisher || '\u2014')}</td>
                    <td><span class="install-count-badge">${app.install_count}</span></td>
                </tr>
            `).join('');
        }

        async function showDetail(appId, appName) {
            currentModalApp = appName;
            currentMachines = [];

            document.getElementById('modalTitle').textContent = appName;
            document.getElementById('modalToolbar').style.display = 'none';
            document.getElementById('modalBody').innerHTML = '<div class="detail-loading">' + window.t('software.inventory.loading_machines') + '</div>';
            document.getElementById('detailOverlay').classList.add('open');

            try {
                const response = await fetch(API_BASE + 'get_app_machines.php?app_id=' + appId);
                const data = await response.json();

                if (data.success && data.machines.length > 0) {
                    currentMachines = data.machines;
                    document.getElementById('modalCount').textContent =
                        window.t(data.machines.length !== 1 ? 'software.inventory.installed_on_many' : 'software.inventory.installed_on_one', { count: data.machines.length });
                    document.getElementById('modalToolbar').style.display = 'flex';
                    document.getElementById('modalBody').innerHTML = `
                        <table class="machine-table">
                            <thead>
                                <tr>
                                    <th>${window.t('software.inventory.machine_hostname')}</th>
                                    <th>${window.t('software.inventory.machine_version')}</th>
                                    <th>${window.t('software.inventory.machine_install_date')}</th>
                                    <th>${window.t('software.inventory.machine_last_seen')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.machines.map(m => `
                                    <tr>
                                        <td class="machine-hostname">${escapeHtml(m.hostname)}</td>
                                        <td>${escapeHtml(m.display_version || '\u2014')}</td>
                                        <td>${escapeHtml(m.install_date || '\u2014')}</td>
                                        <td>${escapeHtml(m.last_seen || '\u2014')}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `;
                } else if (data.success) {
                    document.getElementById('modalBody').innerHTML =
                        '<div class="detail-loading">' + window.t('software.inventory.no_machines') + '</div>';
                } else {
                    document.getElementById('modalBody').innerHTML =
                        '<div class="detail-loading">' + window.t('software.inventory.machine_error') + '</div>';
                }
            } catch (error) {
                console.error('Error loading machines:', error);
                document.getElementById('modalBody').innerHTML =
                    '<div class="detail-loading">' + window.t('software.inventory.machine_failed') + '</div>';
            }
        }

        function closeDetail() {
            document.getElementById('detailOverlay').classList.remove('open');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDetail();
        });

        function csvCell(text) {
            text = String(text);
            if (text.includes(',') || text.includes('"') || text.includes('\n')) {
                return '"' + text.replace(/"/g, '""') + '"';
            }
            return text;
        }

        function exportCSV() {
            if (!currentMachines.length) return;

            const rows = [[
                window.t('software.inventory.machine_hostname'),
                window.t('software.inventory.machine_version'),
                window.t('software.inventory.machine_install_date'),
                window.t('software.inventory.machine_last_seen')
            ].map(h => csvCell(h)).join(',')];

            currentMachines.forEach(m => {
                rows.push([
                    csvCell(m.hostname || ''),
                    csvCell(m.display_version || ''),
                    csvCell(m.install_date || ''),
                    csvCell(m.last_seen || '')
                ].join(','));
            });

            const csv = '\uFEFF' + rows.join('\r\n');
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = (currentModalApp || 'software').replace(/[^a-zA-Z0-9 _-]/g, '') + window.t('software.inventory.csv_machines_suffix') + '.csv';
            a.click();
            URL.revokeObjectURL(url);
        }

        function updateSortIndicators() {
            const columns = {
                'display_name': 'thName',
                'publisher': 'thPublisher',
                'install_count': 'thCount'
            };

            Object.entries(columns).forEach(([col, id]) => {
                const th = document.getElementById(id);
                const icon = th.querySelector('.sort-icon');
                if (col === sortColumn) {
                    th.classList.add('sort-active');
                    icon.textContent = sortDirection === 'asc' ? '\u25B2' : '\u25BC';
                } else {
                    th.classList.remove('sort-active');
                    icon.textContent = '';
                }
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
