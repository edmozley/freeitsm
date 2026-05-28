<?php
/**
 * Tasks Module — Full-screen table view
 *
 * Excel-style grid with column show/hide + reorder (persisted), click-to-sort,
 * search, per-column tickbox filters, CSV export, and inline cell editing.
 * Mirrors the asset-management/table.php pattern; the editable-cell story is
 * the tasks-specific addition.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

$current_page = 'table';
$path_prefix = '../../';
$translationNamespaces = ['common', 'tasks'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('tasks.title') . ' ' . t('tasks.nav.table')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <link rel="stylesheet" href="../../assets/css/tasks.css?v=14">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
    <style>
        /* ── Table view ───────────────────────────────────────────── */
        .tt-layout { display: flex; flex-direction: column; height: 100%; min-height: 0; }

        .tt-toolbar {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            padding: 10px 16px;
            background: #fff;
            border-bottom: 1px solid #e0e0e0;
            flex-shrink: 0;
        }
        .tt-toolbar .tt-search {
            flex: 1 1 240px;
            max-width: 320px;
            padding: 7px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
        }
        .tt-toolbar .tt-search:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 2px rgba(124, 58, 237, 0.15);
        }
        .tt-toolbar .tt-btn {
            padding: 7px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fff;
            color: #333;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }
        .tt-toolbar .tt-btn:hover { border-color: #7c3aed; color: #7c3aed; }
        .tt-toolbar .tt-count { color: #666; font-size: 13px; margin-left: auto; }

        /* ── Table itself ─────────────────────────────────────────── */
        .tt-wrap {
            flex: 1;
            min-height: 0;
            overflow: auto;
            background: #fff;
            margin: 12px 16px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
        }
        table.tt-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 13px;
        }
        .tt-table thead th {
            position: sticky;
            top: 0;
            background: #f5f7fa;
            z-index: 2;
            border-bottom: 2px solid #e0e0e0;
            border-right: 1px solid #e0e0e0;
            padding: 0;
            text-align: left;
            color: #333;
            font-weight: 600;
            white-space: nowrap;
        }
        .tt-table thead th:last-child { border-right: none; }
        .tt-th-content {
            display: flex; align-items: center; gap: 6px;
            padding: 9px 10px;
            cursor: pointer;
            user-select: none;
        }
        .tt-th-content:hover { background: #eef2f7; }
        .tt-th-label { flex: 1; }
        .tt-sort-arrow { font-size: 11px; color: #999; line-height: 1; }
        .tt-th-content.sorted .tt-sort-arrow { color: #7c3aed; }

        .tt-filter-btn {
            width: 18px; height: 18px;
            border: none;
            background: transparent;
            cursor: pointer;
            color: #999;
            padding: 0;
            display: flex; align-items: center; justify-content: center;
            border-radius: 3px;
        }
        .tt-filter-btn:hover { background: #dde4ee; color: #333; }
        .tt-filter-btn.active { color: #7c3aed; }

        .tt-table tbody td {
            padding: 4px 6px;
            border-bottom: 1px solid #f0f0f0;
            border-right: 1px solid #f5f5f5;
            color: #333;
            vertical-align: middle;
            max-width: 320px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .tt-table tbody td:last-child { border-right: none; }
        .tt-table tbody tr:hover { background: #fafbfc; }

        /* Drag-reorder affordance on header cells */
        .tt-table thead th.tt-dragging { opacity: 0.4; }
        .tt-table thead th.tt-drag-over { background: #e8e2fb; }

        /* ── Inline edit controls ─────────────────────────────────── */
        .tt-cell-input,
        .tt-cell-date,
        .tt-cell-select {
            width: 100%;
            border: 1px solid transparent;
            background: transparent;
            padding: 4px 6px;
            border-radius: 3px;
            font-size: 13px;
            color: #333;
            font-family: inherit;
            box-sizing: border-box;
        }
        .tt-cell-input:hover,
        .tt-cell-date:hover,
        .tt-cell-select:hover {
            border-color: #ddd;
            background: #fafbfc;
        }
        .tt-cell-input:focus,
        .tt-cell-date:focus,
        .tt-cell-select:focus {
            outline: none;
            border-color: #7c3aed;
            background: #fff;
            box-shadow: 0 0 0 2px rgba(124, 58, 237, 0.12);
        }
        .tt-cell-wrap { display: flex; align-items: center; gap: 6px; }
        .tt-cell-wrap .tt-cell-select { flex: 1; min-width: 0; }
        .tt-swatch {
            width: 9px; height: 9px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .tt-empty {
            padding: 60px 20px;
            text-align: center;
            color: #888;
        }

        /* ── Popovers (filter + columns drawer) ───────────────────── */
        .tt-pop {
            position: absolute;
            z-index: 1000;
            background: #fff;
            border: 1px solid #d0d7e1;
            border-radius: 6px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            font-size: 13px;
        }
        .tt-filter-pop {
            min-width: 220px;
            max-width: 320px;
            padding: 10px 12px;
        }
        .tt-filter-pop .tt-pop-search {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
            margin-bottom: 8px;
        }
        .tt-filter-pop .tt-pop-actions {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 12px;
        }
        .tt-filter-pop .tt-pop-actions a {
            color: #7c3aed;
            cursor: pointer;
            text-decoration: none;
        }
        .tt-filter-pop .tt-pop-actions a:hover { text-decoration: underline; }
        .tt-filter-pop .tt-pop-list {
            max-height: 280px;
            overflow-y: auto;
            margin-bottom: 10px;
            border-top: 1px solid #eee;
            padding-top: 6px;
        }
        .tt-filter-pop .tt-pop-item {
            display: flex; align-items: center; gap: 8px;
            padding: 4px 2px;
            cursor: pointer;
        }
        .tt-filter-pop .tt-pop-item:hover { background: #f5f7fa; }
        .tt-filter-pop .tt-pop-item input { margin: 0; }
        .tt-filter-pop .tt-pop-value {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .tt-filter-pop .tt-pop-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            border-top: 1px solid #eee;
            padding-top: 8px;
        }
        .tt-filter-pop .tt-pop-buttons button {
            padding: 5px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fff;
            font-size: 12px;
            cursor: pointer;
        }
        .tt-filter-pop .tt-pop-buttons .tt-pop-apply {
            background: #7c3aed;
            color: #fff;
            border-color: #7c3aed;
        }

        .tt-cols-pop {
            min-width: 240px;
            max-height: 70vh;
            overflow-y: auto;
            padding: 12px 14px;
        }
        .tt-cols-pop h4 {
            margin: 0 0 8px;
            font-size: 13px;
            font-weight: 600;
            color: #333;
        }
        .tt-cols-hint { color: #888; font-size: 11px; margin-bottom: 10px; }
        .tt-cols-item {
            display: flex; align-items: center; gap: 8px;
            padding: 5px 6px;
            border-radius: 3px;
            cursor: grab;
        }
        .tt-cols-item:hover { background: #f5f7fa; }
        .tt-cols-item.dragging { opacity: 0.4; }
        .tt-cols-item.drag-over { background: #e8e2fb; }
        .tt-cols-drag {
            color: #aaa;
            cursor: grab;
            font-size: 14px;
            line-height: 1;
        }
    </style>
</head>
<body data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>">
    <?php include '../includes/header.php'; ?>

    <div class="tasks-container">
        <!-- Sidebar -->
        <div class="tasks-sidebar">
            <div class="sidebar-section">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.filter')); ?></div>
                <button class="filter-btn active" data-filter="my" onclick="setFilter('my')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    <?php echo htmlspecialchars(t('tasks.filter.my')); ?>
                </button>
                <button class="filter-btn" data-filter="all" onclick="setFilter('all')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    <?php echo htmlspecialchars(t('tasks.filter.all')); ?>
                </button>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.team')); ?></div>
                <select id="teamFilter" class="sidebar-select" onchange="setTeamFilter(this.value)">
                    <option value=""><?php echo htmlspecialchars(t('tasks.filter.all_teams')); ?></option>
                </select>
            </div>

            <div class="sidebar-section">
                <div class="sidebar-label"><?php echo htmlspecialchars(t('tasks.sidebar.analyst')); ?></div>
                <select id="analystFilter" class="sidebar-select" onchange="setAnalystFilter(this.value)">
                    <option value=""><?php echo htmlspecialchars(t('tasks.filter.all_analysts')); ?></option>
                </select>
            </div>
        </div>

        <!-- Main: toolbar + table -->
        <div class="tasks-main">
            <div class="tt-layout">
                <div class="tt-toolbar">
                    <input type="text" id="ttSearch" class="tt-search" placeholder="Search across visible columns..." autocomplete="off">
                    <button type="button" class="tt-btn" id="ttColumnsBtn" title="Choose visible columns and drag to reorder">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="8" y1="6" x2="21" y2="6"></line>
                            <line x1="8" y1="12" x2="21" y2="12"></line>
                            <line x1="8" y1="18" x2="21" y2="18"></line>
                            <line x1="3" y1="6" x2="3.01" y2="6"></line>
                            <line x1="3" y1="12" x2="3.01" y2="12"></line>
                            <line x1="3" y1="18" x2="3.01" y2="18"></line>
                        </svg>
                        Columns
                    </button>
                    <button type="button" class="tt-btn" id="ttResetBtn" title="Clear all filters, sort and search">Reset</button>
                    <button type="button" class="tt-btn" onclick="ttExportCSV()" title="Download visible rows as CSV">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="7 10 12 15 17 10"></polyline>
                            <line x1="12" y1="15" x2="12" y2="3"></line>
                        </svg>
                        CSV
                    </button>
                    <span class="tt-count" id="ttCount"></span>
                </div>

                <div class="tt-wrap">
                    <table class="tt-table" id="ttTable">
                        <thead id="ttHead"></thead>
                        <tbody id="ttBody">
                            <tr><td colspan="20" class="tt-empty">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Shared right-click context menu (same markup contract as dashboard + timeline) -->
    <div class="ctx-menu" id="ctxMenu">
        <div class="ctx-item ctx-has-sub">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.assign_analyst')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxAnalyst"></div>
        </div>
        <div class="ctx-item ctx-has-sub">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.assign_team')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxTeam"></div>
        </div>
        <div class="ctx-item ctx-has-sub">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.change_status')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxStatus"></div>
        </div>
        <div class="ctx-item ctx-has-sub">
            <span class="ctx-item-label"><?php echo htmlspecialchars(t('tasks.context.change_priority')); ?></span>
            <span class="ctx-arrow">&rsaquo;</span>
            <div class="ctx-submenu" id="ctxPriority"></div>
        </div>
    </div>

    <script>window.API_BASE = '../../api/tasks/';</script>
    <script src="../../assets/js/tasks-ctx-menu.js?v=1"></script>
    <script src="../../assets/js/tasks-table.js?v=1"></script>
</body>
</html>
