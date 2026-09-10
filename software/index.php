<?php
/**
 * Software - View software inventory across all managed machines
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('software');

$current_page = 'software';
$path_prefix = '../';
$translationNamespaces = ['common', 'software'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('software.inventory.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=68">
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

        .software-toolbar h3 {
            display: flex;
            align-items: baseline;
            gap: 8px;
        }

        .software-count {
            font-size: 13px;
            color: var(--text-dim, #888);
            white-space: nowrap;
            font-weight: 400;
        }

        /* The separator lives here rather than in the markup so it disappears
           with the count itself — before the list loads the span is empty, and a
           hardcoded dash would sit there on its own. */
        .software-count:not(:empty)::before {
            content: '\2014\00a0';
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
            /* Hold the scrollbar's space open even when the list is short enough
               not to need one. Without this, searching down to a handful of rows
               removes the scrollbar and every column jumps 8px wider — a smaller
               version of the same complaint the fixed layout above fixes, and the
               only movement left once it is in place. */
            scrollbar-gutter: stable;
        }

        .software-table {
            width: 100%;
            border-collapse: collapse;
            /* ⚠️ FIXED, so the column boundaries are decided ONCE by the header
               row rather than by whichever rows survive the current search.
               With the default `auto` the browser re-measures every visible cell
               on each keystroke, so the columns visibly jumped about while you
               typed — the longest publisher in the filtered set was setting the
               width of the whole table.
               Only the widths below are needed: under `table-layout: fixed` the
               first row decides everything and the body cells follow. */
            table-layout: fixed;
        }

        /* Application Name is deliberately left unsized — under a fixed layout the
           one column without a width absorbs whatever is left, so the name gets
           the room and the rest stay put. */
        #thPublisher { width: 260px; }
        #thCount     { width: 130px; }
        #thSeats     { width: 100px; }

        /* A fixed column cannot widen to fit, so a long unbroken string (registry
           publishers are full of them) has to be allowed to wrap or it spills
           across the cell beside it. */
        .software-table tbody td { overflow-wrap: anywhere; }

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

        /* Manually added applications (#1549). */
        .sw-add-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            background: var(--sw-accent, #5c6bc0);
            color: #fff;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
        }
        .sw-add-btn:hover { background: var(--sw-accent-hover, #3f51b5); }

        /* Marks a row somebody typed rather than one the agent found. Not a
           warning — it says who is responsible for the fields, which is why the
           edit buttons appear on these rows and nowhere else. */
        .sw-manual-tag {
            display: inline-block;
            margin-left: 8px;
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 11px;
            background: var(--surface-hover, #eef0f5);
            color: var(--text-muted, #666);
            vertical-align: middle;
        }
        .sw-dim { color: var(--text-dim, #aab2bd); }
        .sw-actions-col { width: 90px; white-space: nowrap; cursor: default; }
        .sw-row-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px 5px;
            color: var(--text-muted, #666);
            display: inline-flex;
            border-radius: 4px;
        }
        .sw-row-btn:hover { background: var(--surface-hover, #eee); color: var(--sw-accent, #5c6bc0); }
        .sw-row-btn.danger:hover { color: var(--danger-text, #c62828); }

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
    <link rel="stylesheet" href="../assets/css/mobile.css?v=138">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container software-container">
        <div class="software-toolbar">
            <h3>
                <?php echo htmlspecialchars(t('software.inventory.heading')); ?>
                <?php /* The count reads as part of the heading — "Software
                         Inventory — 1,500 applications" — rather than floating at
                         the far right of the toolbar away from the thing it counts.
                         The dash is drawn by CSS on :not(:empty), so there is no
                         dangling separator in the moment before the list loads. */ ?>
                <span class="software-count" id="softwareCount"></span>
            </h3>
            <div class="toolbar-right">
                <input type="text" class="search-box" id="softwareSearch"
                       placeholder="<?php echo htmlspecialchars(t('software.inventory.search')); ?>"
                       oninput="searchSoftware()">
                <?php /* Manually added applications (#1549). The inventory agent
                         finds what is installed; nothing installs a cloud
                         platform, so this is the only way Xero or Canva can be
                         recorded — and the only way a licence can be attached to
                         one, since software_licences.app_id is NOT NULL. */ ?>
                <button type="button" class="sw-add-btn" onclick="openAppModal(null)">
                    <?php echo htmlspecialchars(t('software.inventory.add_app')); ?>
                </button>
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
                        <?php /* Seats is a SEPARATE column from Installed, not a
                                 fallback (#1549). A cloud platform is installed on
                                 nothing, so 0 there is correct and says nothing
                                 about whether anyone uses it — the seats on its
                                 licences do. Two columns keeps "not installed
                                 anywhere" distinguishable from "not licensed to
                                 anyone". */ ?>
                        <th onclick="sortBy('seats')" id="thSeats">
                            <?php echo htmlspecialchars(t('software.inventory.col_seats')); ?> <span class="sort-icon"></span>
                        </th>
                        <th class="sw-actions-col"><?php echo htmlspecialchars(t('software.inventory.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="softwareTableBody">
                    <tr><td colspan="5">
                        <div class="loading-spinner"><div class="spinner"></div></div>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Machine Detail Modal -->
    <?php /* Add / edit a manually added application (#1549). Reuses the module's
             own overlay classes so it matches the machine-list dialog beside it. */ ?>
    <div class="detail-overlay" id="appModal" onclick="if(event.target===this)closeAppModal()">
        <div class="detail-box" style="max-width: 520px;">
            <div class="detail-header">
                <h3 id="appModalTitle"><?php echo htmlspecialchars(t('software.inventory.add_app')); ?></h3>
                <button class="detail-close" onclick="closeAppModal()">&times;</button>
            </div>
            <form id="appForm" onsubmit="saveApp(event)" style="padding: 20px 24px; overflow-y: auto;">
                <input type="hidden" id="appId">
                <div class="form-group">
                    <label class="form-label" for="appName"><?php echo htmlspecialchars(t('software.inventory.f_name')); ?></label>
                    <input type="text" class="form-input" id="appName" maxlength="512" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="appPublisher"><?php echo htmlspecialchars(t('software.inventory.f_publisher')); ?></label>
                    <input type="text" class="form-input" id="appPublisher" maxlength="512">
                </div>
                <div class="form-group">
                    <label class="form-label" for="appUrl"><?php echo htmlspecialchars(t('software.inventory.f_url')); ?></label>
                    <input type="text" class="form-input" id="appUrl" maxlength="500" placeholder="https://">
                    <div class="form-hint"><?php echo htmlspecialchars(t('software.inventory.f_url_hint')); ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="appNotes"><?php echo htmlspecialchars(t('software.inventory.f_notes')); ?></label>
                    <textarea class="form-input" id="appNotes" rows="3"></textarea>
                </div>
                <div class="form-hint" style="margin-bottom: 14px;">
                    <?php echo htmlspecialchars(t('software.inventory.licence_hint')); ?>
                </div>
                <div class="form-actions" style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeAppModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary" id="appSaveBtn"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

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
                        '<tr><td colspan="5"><div class="empty-state">' + window.t('software.inventory.load_error', { message: escapeHtml(data.error) }) + '</div></td></tr>';
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
                tbody.innerHTML = '<tr><td colspan="5"><div class="empty-state">' + window.t('software.inventory.none') + '</div></td></tr>';
                return;
            }

            tbody.innerHTML = filteredApps.map(app => {
                const manual = app.source === 'manual';
                // Only a manual row is editable. An agent-discovered application is
                // a REPORT of what is on somebody's machine \u2014 editing it here would
                // make the record disagree with the thing it describes, and the
                // next inventory run would overwrite the edit anyway.
                const actions = manual
                    ? `<button type="button" class="sw-row-btn" title="${escapeHtml(window.t('common.edit'))}"
                               onclick="event.stopPropagation(); openAppModal(${app.id})">${ICON_EDIT}</button>
                       <button type="button" class="sw-row-btn danger" title="${escapeHtml(window.t('common.delete'))}"
                               onclick="event.stopPropagation(); deleteApp(${app.id}, '${escapeHtml(app.display_name).replace(/'/g, "\\'")}')">${ICON_TRASH}</button>`
                    : '';
                return `
                <tr class="app-row" onclick="showDetail(${app.id}, '${escapeHtml(app.display_name).replace(/'/g, "\\'")}')">
                    <td>${escapeHtml(app.display_name)}${manual ? ` <span class="sw-manual-tag">${escapeHtml(window.t('software.inventory.added_by_hand'))}</span>` : ''}</td>
                    <td>${escapeHtml(app.publisher || '\u2014')}</td>
                    <td><span class="install-count-badge">${app.install_count}</span></td>
                    <td>${app.seats > 0 ? escapeHtml(String(app.seats)) : '<span class="sw-dim">\u2014</span>'}</td>
                    <td class="sw-actions-col">${actions}</td>
                </tr>`;
            }).join('');
        }

        // \u2500\u2500 Manually added applications (#1549) \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
        const ICON_EDIT  = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
        const ICON_TRASH = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';

        function openAppModal(appId) {
            const app = appId ? allApps.find(a => a.id === appId) : null;
            document.getElementById('appModalTitle').textContent = app
                ? window.t('software.inventory.edit_app')
                : window.t('software.inventory.add_app');
            document.getElementById('appId').value        = app ? app.id : '';
            document.getElementById('appName').value      = app ? app.display_name : '';
            document.getElementById('appPublisher').value = app ? (app.publisher || '') : '';
            document.getElementById('appUrl').value       = app ? (app.app_url || '') : '';
            document.getElementById('appNotes').value     = app ? (app.notes || '') : '';
            document.getElementById('appModal').classList.add('open');
            document.getElementById('appName').focus();
        }

        function closeAppModal() {
            document.getElementById('appModal').classList.remove('open');
        }

        async function saveApp(e) {
            e.preventDefault();
            const btn = document.getElementById('appSaveBtn');
            btn.disabled = true;
            try {
                const res = await fetch(API_BASE + 'save_app.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id:           document.getElementById('appId').value || null,
                        display_name: document.getElementById('appName').value.trim(),
                        publisher:    document.getElementById('appPublisher').value.trim(),
                        app_url:      document.getElementById('appUrl').value.trim(),
                        notes:        document.getElementById('appNotes').value.trim()
                    })
                });
                const data = await res.json();
                if (data.success) {
                    closeAppModal();
                    showToast(window.t('software.inventory.app_saved'), 'success');
                    await loadSoftware();
                } else {
                    // The endpoint explains WHY it refused \u2014 a name clash with an
                    // agent-discovered app reads very differently from a duplicate.
                    showToast(data.error || 'Failed', 'error');
                }
            } catch (err) {
                showToast('Failed', 'error');
            }
            btn.disabled = false;
        }

        async function deleteApp(appId, name) {
            if (!confirm(window.t('software.inventory.confirm_delete_app', { name: name }))) return;
            try {
                const res = await fetch(API_BASE + 'delete_app.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: appId })
                });
                const data = await res.json();
                showToast(data.success ? window.t('software.inventory.app_deleted') : (data.error || 'Failed'),
                          data.success ? 'success' : 'error');
                if (data.success) await loadSoftware();
            } catch (err) {
                showToast('Failed', 'error');
            }
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
    <script src="../assets/js/mobile.js?v=57"></script>
</body>
</html>
