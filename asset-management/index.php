<?php
/**
 * Assets - View and manage IT assets and their user assignments
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
I18n::initFromSession();

$current_page = 'assets';
$path_prefix = '../';
$translationNamespaces = ['common', 'asset-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('asset-management.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=10">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js"></script>
    <style>
        .assets-container {
            display: flex;
            flex: 1;
            overflow: hidden;
            gap: 1px;
            background-color: var(--border, #e0e0e0);
        }

        .assets-list-container {
            width: 400px;
            min-width: 300px;
            background-color: var(--surface, #fff);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .assets-list-header {
            padding: 15px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background-color: var(--surface-3, #f8f9fa);
        }

        .assets-list-header h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: var(--text, #333);
        }

        .search-box {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .search-box:focus {
            outline: none;
            border-color: var(--accent, #0078d4);
            box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.1);
        }

        .assets-list {
            flex: 1;
            overflow-y: auto;
        }

        .asset-item {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-soft, #eee);
            cursor: pointer;
            transition: background-color 0.15s;
        }

        .asset-item:hover {
            background-color: var(--app-bg, #f5f5f5);
        }

        .asset-item.selected {
            background-color: var(--accent-soft, #e8f4fc);
            border-left: 3px solid var(--accent, #0078d4);
        }

        .asset-hostname {
            font-weight: 600;
            color: var(--text, #333);
            margin-bottom: 4px;
            font-family: monospace;
            font-size: 14px;
        }

        .asset-meta {
            font-size: 12px;
            color: var(--text-dim, #888);
            display: flex;
            gap: 15px;
        }

        .asset-assigned {
            color: #2e7d32;
        }

        .asset-unassigned {
            color: var(--text-dim, #888);
        }

        .asset-detail-container {
            flex: 1;
            background-color: var(--surface, #fff);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .asset-detail-sticky {
            flex-shrink: 0;
        }

        /* Body below the sticky header+tabs. The active tab panel fills it; each
           panel owns its own scrolling so a long device/software list never pushes
           the page off the bottom. min-height:0 lets the flex child actually shrink. */
        .asset-detail-body {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .asset-detail-header {
            padding: 20px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background-color: var(--surface-3, #f8f9fa);
        }

        .asset-detail-hostname {
            font-size: 22px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 0 0 4px 0;
        }

        .asset-detail-subtitle {
            font-size: 14px;
            color: var(--text-muted, #666);
            margin: 0;
        }

        .asset-assigned-bar {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border, #e0e0e0);
        }

        .asset-assigned-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
            min-width: 0;
        }

        .asset-assigned-info .user-name {
            font-weight: 600;
            color: var(--text, #333);
            font-size: 14px;
        }

        .asset-assigned-info .user-email {
            color: var(--text-muted, #666);
            font-size: 13px;
        }

        .asset-assigned-info .user-assigned-date {
            color: var(--text-faint, #999);
            font-size: 12px;
        }

        .asset-assigned-info .unassigned-text {
            color: var(--text-faint, #999);
            font-size: 13px;
            font-style: italic;
        }

        #assignButtons {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .asset-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding: 20px;
            border-bottom: 1px solid var(--border, #e0e0e0);
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 12px;
            color: var(--text-dim, #888);
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .info-value {
            font-size: 14px;
            color: var(--text, #333);
        }

        .info-value-select {
            font-size: 14px;
            color: var(--text, #333);
            padding: 4px 8px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            background-color: var(--surface, #fff);
            cursor: pointer;
            max-width: 200px;
        }

        .info-value-select:focus {
            outline: none;
            border-color: #107c10;
            box-shadow: 0 0 0 2px rgba(16, 124, 16, 0.1);
        }

        .info-value-input {
            font-size: 14px;
            color: var(--text, #333);
            padding: 4px 8px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            background-color: var(--surface, #fff);
            max-width: 200px;
            width: 100%;
            box-sizing: border-box;
        }
        .info-value-input:focus {
            outline: none;
            border-color: #107c10;
            box-shadow: 0 0 0 2px rgba(16, 124, 16, 0.1);
        }

        .assigned-users-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .section-header {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background-color: var(--surface-3, #f8f9fa);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-title {
            font-weight: 600;
            color: var(--text, #333);
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: background-color 0.15s;
        }

        .btn-primary {
            background-color: var(--accent, #0078d4);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--accent-hover, #106ebe);
        }

        .btn-danger {
            background-color: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 12px;
        }

        .assigned-users-list {
            flex: 1;
            overflow-y: auto;
        }

        .user-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-soft, #eee);
        }

        .user-row:hover {
            background-color: var(--app-bg, #f5f5f5);
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 500;
            color: var(--text, #333);
        }

        .user-email {
            font-size: 13px;
            color: var(--text-muted, #666);
        }

        .user-assigned-date {
            font-size: 12px;
            color: var(--text-dim, #888);
            margin-top: 2px;
        }

        .empty-state {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 1;
            color: var(--text-dim, #888);
            font-size: 14px;
            padding: 40px;
            text-align: center;
        }

        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }

        .spinner {
            width: 30px;
            height: 30px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--accent, #0078d4);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .asset-count {
            font-size: 12px;
            color: var(--text-dim, #888);
            margin-top: 8px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--surface, #fff);
            border-radius: 8px;
            width: 500px;
            max-width: 90%;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 20px var(--shadow, rgba(0, 0, 0, 0.2));
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            font-weight: 600;
            font-size: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted, #666);
            line-height: 1;
        }

        .modal-close:hover {
            color: var(--text, #333);
        }

        .modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--border, #e0e0e0);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text, #333);
        }

        .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 14px;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--accent, #0078d4);
        }

        .user-search-results {
            height: 300px;
            overflow-y: auto;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            margin-top: 10px;
        }

        .user-search-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-soft, #eee);
        }

        .user-search-item:last-child {
            border-bottom: none;
        }

        .user-search-item:hover {
            background-color: var(--app-bg, #f5f5f5);
        }

        .user-search-item.selected {
            background-color: var(--accent-soft, #e8f4fc);
        }

        .user-search-name {
            font-weight: 500;
        }

        .user-search-email {
            font-size: 13px;
            color: var(--text-muted, #666);
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        .btn-outline {
            background-color: transparent;
            color: #546e7a;
            border: 1px solid #b0bec5;
        }

        .btn-outline:hover {
            background-color: #eceff1;
        }

        /* History Modal */
        .modal-content.modal-wide {
            width: 700px;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
        }

        .history-table thead th {
            background-color: var(--surface-3, #f8f9fa);
            padding: 10px 14px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted, #666);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 2px solid var(--border, #e0e0e0);
        }

        .history-table tbody td {
            padding: 9px 14px;
            font-size: 13px;
            color: var(--text, #333);
            border-bottom: 1px solid var(--surface-hover, #f0f0f0);
            vertical-align: top;
        }

        .history-table tbody tr:hover {
            background-color: var(--surface-3, #f9f9f9);
        }

        .history-field-badge {
            display: inline-block;
            background-color: #e8eaf6;
            color: #3f51b5;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }

        .history-value-old {
            color: var(--text-faint, #999);
            text-decoration: line-through;
        }

        .history-value-new {
            color: #2e7d32;
            font-weight: 500;
        }

        .history-arrow {
            color: var(--text-faint, #999);
            margin: 0 4px;
        }

        .history-meta {
            font-size: 12px;
            color: var(--text-dim, #888);
        }

        /* Disk Usage Section */
        .disks-section {
            border-top: 1px solid var(--border, #e0e0e0);
        }

        .disks-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            padding: 16px 20px;
        }

        .disk-card {
            background: var(--surface-3, #f8f9fa);
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            padding: 14px 16px;
        }

        .disk-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .disk-drive {
            font-weight: 600;
            font-size: 14px;
            color: var(--text, #333);
            font-family: monospace;
        }

        .disk-label {
            font-size: 12px;
            color: var(--text-dim, #888);
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .disk-bar-container {
            background: var(--border, #e0e0e0);
            border-radius: 4px;
            height: 8px;
            overflow: hidden;
            margin-bottom: 8px;
        }

        .disk-bar-fill {
            height: 100%;
            border-radius: 4px;
            width: 0;
            transition: width 0.8s ease-out;
        }

        .disk-bar-fill.usage-low { background: #4caf50; }
        .disk-bar-fill.usage-medium { background: #ff9800; }
        .disk-bar-fill.usage-high { background: #f44336; }

        .disk-details {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: var(--text-muted, #666);
        }

        .disk-percent {
            font-weight: 600;
        }

        .disk-percent.usage-low { color: #4caf50; }
        .disk-percent.usage-medium { color: #e65100; }
        .disk-percent.usage-high { color: #f44336; }

        /* Installed Software Section */

        .software-list {
            padding: 0;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
        }

        .software-table {
            width: 100%;
            border-collapse: collapse;
        }

        .software-table thead th {
            position: sticky;
            top: 0;
            background-color: var(--surface-hover, #f0f0f0);
            padding: 8px 20px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted, #666);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            z-index: 1;
        }

        .software-table tbody td {
            padding: 7px 20px;
            font-size: 13px;
            color: var(--text, #333);
            border-bottom: 1px solid var(--surface-hover, #f0f0f0);
        }

        .software-table tbody tr:hover {
            background-color: var(--surface-3, #f9f9f9);
        }

        .software-count-badge {
            display: inline-block;
            background-color: #e8eaf6;
            color: #3f51b5;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }

        .sw-filter-tabs {
            display: flex;
            gap: 0;
            padding: 0 20px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background-color: var(--surface, #fff);
            flex-shrink: 0;
        }

        .sw-filter-tab {
            padding: 8px 16px;
            font-size: 12px;
            font-weight: 500;
            color: var(--text-muted, #666);
            cursor: pointer;
            border: none;
            background: none;
            border-bottom: 2px solid transparent;
            transition: color 0.15s, border-color 0.15s;
        }

        .sw-filter-tab:hover {
            color: var(--text, #333);
        }

        .sw-filter-tab.active {
            color: #3f51b5;
            border-bottom-color: #3f51b5;
        }

        .sw-filter-tab .sw-tab-count {
            display: inline-block;
            background-color: var(--border-soft, #eee);
            color: var(--text-muted, #666);
            padding: 1px 6px;
            border-radius: 10px;
            font-size: 10px;
            margin-left: 4px;
        }

        .sw-filter-tab.active .sw-tab-count {
            background-color: #e8eaf6;
            color: #3f51b5;
        }

        /* Detail Tabs */
        .detail-tabs {
            display: flex;
            gap: 0;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background-color: var(--surface-3, #f8f9fa);
        }

        .detail-tab {
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

        .detail-tab:hover {
            color: var(--text, #333);
        }

        .detail-tab.active {
            color: var(--accent, #0078d4);
            border-bottom-color: var(--accent, #0078d4);
        }

        .detail-tab .tab-count {
            display: inline-block;
            background-color: var(--border-soft, #eee);
            color: var(--text-muted, #666);
            padding: 1px 6px;
            border-radius: 10px;
            font-size: 10px;
            margin-left: 4px;
        }

        .detail-tab.active .tab-count {
            background-color: var(--accent-soft, #e0ecf8);
            color: var(--accent, #0078d4);
        }

        .detail-tab-panel {
            display: none;
        }

        /* Active panel fills the body. Devices/Software are flex columns whose
           inner list scrolls; "--scroll" panels (Key info, Intune) scroll as a block. */
        .detail-tab-panel.active {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
        }

        .detail-tab-panel--scroll.active {
            display: block;
            overflow-y: auto;
        }

        /* Devices Section */
        .devices-search {
            padding: 10px 20px;
            border-bottom: 1px solid var(--surface-hover, #f0f0f0);
            flex-shrink: 0;
        }

        .devices-search input {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 13px;
            outline: none;
            box-sizing: border-box;
        }

        .devices-search input:focus {
            border-color: var(--accent, #0078d4);
        }

        .devices-list {
            padding: 0;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
        }

        .devices-table {
            width: 100%;
            border-collapse: collapse;
        }

        .devices-table thead th {
            position: sticky;
            top: 0;
            background-color: var(--surface-hover, #f0f0f0);
            padding: 8px 20px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted, #666);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            z-index: 1;
        }

        .devices-table tbody td {
            padding: 7px 20px;
            font-size: 13px;
            color: var(--text, #333);
            border-bottom: 1px solid var(--surface-hover, #f0f0f0);
        }

        .devices-table tbody tr:hover {
            background-color: var(--surface-3, #f9f9f9);
        }

        .device-class-row td {
            background-color: var(--surface-3, #f8f9fa);
            font-weight: 600;
            font-size: 12px;
            color: var(--text-muted, #555);
            padding: 6px 20px !important;
            border-bottom: 1px solid var(--border, #e0e0e0);
        }

        .device-class-row:hover td {
            background-color: var(--surface-3, #f8f9fa) !important;
        }

        .device-status {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }

        .device-status-ok {
            background-color: #e8f5e9;
            color: #2e7d32;
        }

        .device-status-error {
            background-color: #ffebee;
            color: #c62828;
        }

        .device-status-degraded {
            background-color: #fff3e0;
            color: #e65100;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container assets-container">
        <!-- Assets List -->
        <div class="assets-list-container">
            <div class="assets-list-header">
                <h3><?php echo htmlspecialchars(t('asset-management.nav.assets')); ?></h3>
                <input type="text" class="search-box" id="assetSearch" placeholder="<?php echo htmlspecialchars(t('asset-management.list.search_placeholder')); ?>" oninput="searchAssets()" autocomplete="off">
                <div class="asset-count" id="assetCount"></div>
            </div>
            <div class="assets-list" id="assetsList">
                <div class="loading">
                    <div class="spinner"></div>
                </div>
            </div>
        </div>

        <!-- Asset Detail -->
        <div class="asset-detail-container" id="assetDetail">
            <div class="empty-state">
                <?php echo htmlspecialchars(t('asset-management.detail.select_prompt')); ?>
            </div>
        </div>
    </div>

    <!-- Assign User Modal -->
    <div class="modal" id="assignUserModal">
        <div class="modal-content">
            <div class="modal-header">
                <span><?php echo htmlspecialchars(t('asset-management.assign.heading')); ?></span>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('asset-management.assign.search_label')); ?></label>
                    <input type="text" class="search-box" id="userSearchInput" placeholder="<?php echo htmlspecialchars(t('asset-management.assign.search_placeholder')); ?>" oninput="searchUsersForAssign()">
                </div>
                <div class="user-search-results" id="userSearchResults">
                    <div class="empty-state" style="padding: 20px;"><?php echo htmlspecialchars(t('asset-management.assign.type_to_search')); ?></div>
                </div>
                <div class="form-group" style="margin-top: 14px;">
                    <label class="form-label"><?php echo htmlspecialchars(t('asset-management.assign.expected_return_label')); ?></label>
                    <input type="date" class="search-box" id="assignExpectedReturn">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeAssignModal()"><?php echo htmlspecialchars(t('asset-management.common.cancel')); ?></button>
                <button class="btn btn-primary" onclick="confirmAssignUser()" id="assignBtn" disabled><?php echo htmlspecialchars(t('asset-management.detail.assign')); ?></button>
            </div>
        </div>
    </div>

    <!-- Asset History Modal -->
    <div class="modal" id="assetHistoryModal">
        <div class="modal-content modal-wide">
            <div class="modal-header">
                <span><?php echo htmlspecialchars(t('asset-management.history.heading')); ?></span>
            </div>
            <div class="modal-body" id="historyModalBody">
                <div class="loading"><div class="spinner"></div></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeHistoryModal()"><?php echo htmlspecialchars(t('asset-management.common.close')); ?></button>
            </div>
        </div>
    </div>

    <!-- Custody (check-in / check-out) Modal -->
    <div class="modal" id="checkoutLogModal">
        <div class="modal-content modal-wide">
            <div class="modal-header">
                <span><?php echo htmlspecialchars(t('asset-management.custody.heading')); ?></span>
            </div>
            <div class="modal-body" id="checkoutLogBody">
                <div class="loading"><div class="spinner"></div></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeCheckoutLog()"><?php echo htmlspecialchars(t('asset-management.common.close')); ?></button>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/assets/';
        const API_TICKETS = '../api/tickets/';
        let assets = [];
        let selectedAssetId = null;
        let selectedAsset = null;
        let searchTimeout = null;
        let selectedUserForAssign = null;
        let currentAssignedUserId = null;
        let assetTypes = [];
        let assetStatusTypes = [];
        let assetLocations = [];
        let assetSuppliers = [];
        let allAssetSoftware = [];
        let activeSwFilter = 'apps';
        let allDevices = [];

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadAssets();
            loadAssetTypesForDropdown();
            loadAssetStatusTypesForDropdown();
            loadLocationsForDropdown();
            loadAssetSuppliersForDropdown();
        });

        async function loadAssetTypesForDropdown() {
            try {
                const response = await fetch(API_BASE + 'get_asset_types.php');
                const data = await response.json();
                if (data.success) assetTypes = data.asset_types.filter(t => t.is_active);
            } catch (e) { console.error('Error loading asset types:', e); }
        }

        async function loadAssetStatusTypesForDropdown() {
            try {
                const response = await fetch(API_BASE + 'get_asset_status_types.php');
                const data = await response.json();
                if (data.success) assetStatusTypes = data.asset_status_types.filter(t => t.is_active);
            } catch (e) { console.error('Error loading asset status types:', e); }
        }

        async function loadLocationsForDropdown() {
            try {
                const response = await fetch(API_BASE + 'get_asset_locations.php');
                const data = await response.json();
                if (data.success) assetLocations = data.locations || [];
            } catch (e) { console.error('Error loading locations:', e); }
        }

        async function loadAssetSuppliersForDropdown() {
            try {
                const response = await fetch(API_BASE + 'get_asset_suppliers.php');
                const data = await response.json();
                if (data.success) assetSuppliers = data.suppliers || [];
            } catch (e) { console.error('Error loading suppliers:', e); }
        }

        // Build indented full-path <option>s for the location picker, e.g.
        //   UK
        //      London
        //         Office 1
        function buildLocationOptions(selectedId) {
            const childrenOf = (pid) => assetLocations.filter(l => l.parent_id === pid);
            const opts = [`<option value="">${window.t('asset-management.common.none_option')}</option>`];
            const walk = (pid, depth) => {
                childrenOf(pid).forEach(loc => {
                    const indent = '   '.repeat(depth);
                    const sel = (selectedId != null && String(loc.id) === String(selectedId)) ? ' selected' : '';
                    opts.push(`<option value="${loc.id}"${sel}>${indent}${escapeHtml(loc.name)}</option>`);
                    walk(loc.id, depth + 1);
                });
            };
            walk(null, 0);
            return opts.join('');
        }

        async function updateAssetField(field, value) {
            if (!selectedAssetId) return;
            try {
                const response = await fetch(API_BASE + 'update_asset_field.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        asset_id: selectedAssetId,
                        field: field,
                        value: value || null
                    })
                });
                const data = await response.json();
                if (data.success) {
                    const asset = assets.find(a => a.id == selectedAssetId);
                    if (asset) asset[field] = value || null;
                } else {
                    showToast(window.t('asset-management.toast.update_error', { error: data.error }), 'error');
                }
            } catch (error) {
                console.error('Error updating asset:', error);
            }
        }

        // Load assets from API
        async function loadAssets(search = '') {
            try {
                const url = search ? `${API_BASE}get_assets.php?search=${encodeURIComponent(search)}` : API_BASE + 'get_assets.php';
                const response = await fetch(url);
                const data = await response.json();

                if (data.success) {
                    assets = data.assets;
                    renderAssetsList();
                } else {
                    console.error('Error loading assets:', data.error);
                }
            } catch (error) {
                console.error('Error loading assets:', error);
            }
        }

        // Render assets list
        function renderAssetsList() {
            const container = document.getElementById('assetsList');
            const countEl = document.getElementById('assetCount');

            if (assets.length === 0) {
                container.innerHTML = `<div class="empty-state">${window.t('asset-management.list.no_assets')}</div>`;
                countEl.textContent = window.t('asset-management.list.count', { count: 0 });
                return;
            }

            countEl.textContent = window.t('asset-management.list.count', { count: assets.length });

            container.innerHTML = assets.map(asset => `
                <div class="asset-item ${selectedAssetId == asset.id ? 'selected' : ''}" onclick="selectAsset(${asset.id})">
                    <div class="asset-hostname">${escapeHtml(asset.hostname)}</div>
                    <div class="asset-meta">
                        <span class="${asset.user_count > 0 ? 'asset-assigned' : 'asset-unassigned'}">
                            ${asset.user_count > 0 ? window.t('asset-management.status.assigned') : window.t('asset-management.status.unassigned')}
                        </span>
                    </div>
                </div>
            `).join('');
        }

        // Search assets with debounce
        function searchAssets() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const search = document.getElementById('assetSearch').value;
                loadAssets(search);
            }, 300);
        }

        // Select an asset and show details
        async function selectAsset(assetId) {
            selectedAssetId = assetId;
            selectedAsset = assets.find(a => a.id == assetId);
            renderAssetsList();

            if (!selectedAsset) return;

            const detailContainer = document.getElementById('assetDetail');
            detailContainer.innerHTML = `
                <div class="asset-detail-sticky">
                    <div class="asset-detail-header">
                        <h2 class="asset-detail-hostname">${escapeHtml(selectedAsset.hostname)}</h2>
                        <div class="asset-detail-subtitle">${window.t('asset-management.detail.service_tag')}: ${escapeHtml(selectedAsset.service_tag) || '-'}</div>
                        <div style="margin-top: 10px;">
                            <button class="btn btn-outline btn-sm" onclick="openHistoryModal(${selectedAsset.id})">${window.t('asset-management.detail.view_history')}</button>
                            <button class="btn btn-outline btn-sm" onclick="openCheckoutLog(${selectedAsset.id})">${window.t('asset-management.detail.custody')}</button>
                        </div>
                        <div class="asset-assigned-bar" id="assignedBar">
                            <div class="asset-assigned-info" id="assignedInfo">
                                <span class="unassigned-text">${window.t('asset-management.common.loading')}</span>
                            </div>
                            <span id="assignButtons"></span>
                        </div>
                    </div>
                    <div class="detail-tabs" id="detailTabs">
                        <button class="detail-tab active" onclick="switchDetailTab('keyinfo')" data-dtab="keyinfo">${window.t('asset-management.detail.tab_keyinfo')}</button>
                        <button class="detail-tab" onclick="switchDetailTab('devices')" data-dtab="devices">${window.t('asset-management.detail.tab_devices')} <span class="tab-count" id="devicesCountBadge">...</span></button>
                        <button class="detail-tab" onclick="switchDetailTab('software')" data-dtab="software">${window.t('asset-management.detail.tab_software')} <span class="tab-count" id="softwareCountBadge">...</span></button>
                    </div>
                </div>
                <div class="asset-detail-body" id="detailBody">
                    <div class="detail-tab-panel detail-tab-panel--scroll active" id="keyinfoPanel" data-dtab-panel="keyinfo">
                    <div class="asset-info-grid">
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.type')}</span>
                            <select class="info-value-select" onchange="updateAssetField('asset_type_id', this.value)">
                                <option value="">${window.t('asset-management.common.none_option')}</option>
                                ${assetTypes.map(t => `<option value="${t.id}" ${t.id == selectedAsset.asset_type_id ? 'selected' : ''}>${escapeHtml(t.name)}</option>`).join('')}
                            </select>
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.status')}</span>
                            <select class="info-value-select" onchange="updateAssetField('asset_status_id', this.value)">
                                <option value="">${window.t('asset-management.common.none_option')}</option>
                                ${assetStatusTypes.map(s => `<option value="${s.id}" ${s.id == selectedAsset.asset_status_id ? 'selected' : ''}>${escapeHtml(s.name)}</option>`).join('')}
                            </select>
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.location')}</span>
                            <select class="info-value-select" onchange="updateAssetField('location_id', this.value)">
                                ${buildLocationOptions(selectedAsset.location_id)}
                            </select>
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.manufacturer')}</span>
                            <span class="info-value">${escapeHtml(selectedAsset.manufacturer) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.model')}</span>
                            <span class="info-value">${escapeHtml(selectedAsset.model) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.cpu')}</span>
                            <span class="info-value">${escapeHtml(selectedAsset.cpu_name) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.cpu_speed')}</span>
                            <span class="info-value">${escapeHtml(selectedAsset.speed) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.memory')}</span>
                            <span class="info-value">${escapeHtml(selectedAsset.memory) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.operating_system')}</span>
                            <span class="info-value">${escapeHtml(selectedAsset.operating_system) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.feature_release')}</span>
                            <span class="info-value">${escapeHtml(selectedAsset.feature_release) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.build_number')}</span>
                            <span class="info-value">${escapeHtml(selectedAsset.build_number) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.bios_version')}</span>
                            <span class="info-value">${escapeHtml(selectedAsset.bios_version) || '-'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.purchase_date')}</span>
                            <input type="date" class="info-value-input" value="${selectedAsset.purchase_date || ''}" onchange="updateAssetField('purchase_date', this.value)">
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.purchase_cost')}</span>
                            <input type="number" step="0.01" min="0" class="info-value-input" value="${selectedAsset.purchase_cost != null ? selectedAsset.purchase_cost : ''}" placeholder="0.00" onchange="updateAssetField('purchase_cost', this.value)">
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.supplier')}</span>
                            <select class="info-value-select" onchange="updateAssetField('supplier_id', this.value)">
                                <option value="">${window.t('asset-management.common.none_option')}</option>
                                ${assetSuppliers.map(s => `<option value="${s.id}" ${s.id == selectedAsset.supplier_id ? 'selected' : ''}>${escapeHtml(s.name)}</option>`).join('')}
                            </select>
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.order_number')}</span>
                            <input type="text" class="info-value-input" value="${escapeHtml(selectedAsset.order_number || '')}" placeholder="-" onchange="updateAssetField('order_number', this.value)">
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.warranty_expiry')}</span>
                            <input type="date" class="info-value-input" value="${selectedAsset.warranty_expiry || ''}" onchange="updateAssetField('warranty_expiry', this.value)">
                        </div>
                    </div>
                    <div class="disks-section">
                        <div class="section-header">
                            <span class="section-title">${window.t('asset-management.detail.storage')}</span>
                        </div>
                        <div class="disks-grid" id="disksGrid">
                            <div class="loading"><div class="spinner"></div></div>
                        </div>
                    </div>
                    </div>
                    <div class="detail-tab-panel" id="devicesPanel" data-dtab-panel="devices">
                        <div class="devices-search">
                            <input type="text" id="devicesSearch" placeholder="${window.t('asset-management.devices.filter_placeholder')}" oninput="filterDevices()" autocomplete="off">
                        </div>
                        <div class="devices-list" id="devicesList">
                            <div class="loading"><div class="spinner"></div></div>
                        </div>
                    </div>
                    <div class="detail-tab-panel" id="softwarePanel" data-dtab-panel="software">
                        <div class="sw-filter-tabs">
                            <button class="sw-filter-tab active" data-swfilter="apps" onclick="switchSwTab('apps')">${window.t('asset-management.software.applications')} <span class="sw-tab-count" id="swCountApps">0</span></button>
                            <button class="sw-filter-tab" data-swfilter="components" onclick="switchSwTab('components')">${window.t('asset-management.software.components')} <span class="sw-tab-count" id="swCountComponents">0</span></button>
                            <button class="sw-filter-tab" data-swfilter="" onclick="switchSwTab('')">${window.t('asset-management.software.all')} <span class="sw-tab-count" id="swCountAll">0</span></button>
                        </div>
                        <div class="software-list" id="installedSoftwareList">
                            <div class="loading"><div class="spinner"></div></div>
                        </div>
                    </div>
                </div>
            `;

            // Load assigned users, disks, devices, installed software, and (if matched) Intune data
            loadAssignedUsers(assetId);
            loadDisks(assetId);
            loadDevices(assetId);
            loadInstalledSoftware(assetId);
            loadIntuneDevice(assetId);
        }

        // Load assigned users for an asset
        async function loadAssignedUsers(assetId) {
            try {
                const response = await fetch(`${API_BASE}get_asset_users.php?asset_id=${assetId}`);
                const data = await response.json();

                const infoSpan = document.getElementById('assignedInfo');
                const buttonsSpan = document.getElementById('assignButtons');

                if (data.success) {
                    const user = data.users.length > 0 ? data.users[0] : null;

                    if (user) {
                        currentAssignedUserId = user.user_id;
                        infoSpan.innerHTML = `
                            <span class="user-name">${escapeHtml(user.display_name || window.t('asset-management.common.unknown'))}</span>
                            <span class="user-email">${escapeHtml(user.email || '')}</span>
                            <span class="user-assigned-date">${window.t('asset-management.detail.assigned_on', { date: formatDate(user.assigned_datetime) })}</span>
                            ${user.expected_return_date ? `<span class="user-assigned-date">${window.t('asset-management.detail.due_back', { date: escapeHtml(user.expected_return_date) })}</span>` : ''}
                        `;
                        buttonsSpan.innerHTML = `
                            <button class="btn btn-primary btn-sm" onclick="reassignUser()">${window.t('asset-management.detail.reassign')}</button>
                            <button class="btn btn-danger btn-sm" onclick="unassignUser(${user.user_id})">${window.t('asset-management.detail.remove')}</button>
                        `;
                    } else {
                        currentAssignedUserId = null;
                        infoSpan.innerHTML = `<span class="unassigned-text">${window.t('asset-management.status.unassigned')}</span>`;
                        buttonsSpan.innerHTML = `
                            <button class="btn btn-primary btn-sm" onclick="openAssignModal()">${window.t('asset-management.detail.assign')}</button>
                        `;
                    }
                } else {
                    infoSpan.innerHTML = `<span class="unassigned-text">${window.t('asset-management.detail.assignment_error')}</span>`;
                }
            } catch (error) {
                console.error('Error loading assigned users:', error);
            }
        }

        // Load disks for an asset
        async function loadDisks(assetId) {
            try {
                const response = await fetch(`${API_BASE}get_asset_disks.php?asset_id=${assetId}`);
                const data = await response.json();
                const container = document.getElementById('disksGrid');

                if (data.success && data.disks.length > 0) {
                    container.innerHTML = data.disks.map(disk => {
                        const pct = parseFloat(disk.used_percent) || 0;
                        const sizeGB = (disk.size_bytes / 1073741824).toFixed(1);
                        const freeGB = (disk.free_bytes / 1073741824).toFixed(1);
                        const usedGB = (sizeGB - freeGB).toFixed(1);
                        const level = pct >= 90 ? 'high' : pct >= 75 ? 'medium' : 'low';

                        return `<div class="disk-card">
                            <div class="disk-card-header">
                                <span class="disk-drive">${escapeHtml(disk.drive)}</span>
                                <span class="disk-label">${escapeHtml(disk.label || '')}</span>
                            </div>
                            <div class="disk-bar-container">
                                <div class="disk-bar-fill usage-${level}" data-pct="${pct}"></div>
                            </div>
                            <div class="disk-details">
                                <span>${window.t('asset-management.disk.used_of', { used: usedGB, total: sizeGB })}</span>
                                <span class="disk-percent usage-${level}">${pct}%</span>
                            </div>
                            <div class="disk-details" style="margin-top: 4px;">
                                <span>${window.t('asset-management.disk.free', { free: freeGB })}</span>
                                <span>${escapeHtml(disk.file_system || '')}</span>
                            </div>
                        </div>`;
                    }).join('');
                    // Animate bars from 0 to actual width
                    requestAnimationFrame(() => {
                        container.querySelectorAll('.disk-bar-fill').forEach(bar => {
                            bar.style.width = bar.dataset.pct + '%';
                        });
                    });
                } else if (data.success) {
                    container.innerHTML = `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.disk.no_data')}</div>`;
                }
            } catch (error) {
                console.error('Error loading disks:', error);
                document.getElementById('disksGrid').innerHTML = `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.disk.load_error')}</div>`;
            }
        }

        // Load devices for an asset
        async function loadDevices(assetId) {
            try {
                const response = await fetch(`${API_BASE}get_asset_devices.php?asset_id=${assetId}`);
                const data = await response.json();

                const badge = document.getElementById('devicesCountBadge');

                if (data.success && data.devices.length > 0) {
                    allDevices = data.devices;
                    badge.textContent = data.devices.length;
                    renderDevices(allDevices);
                } else {
                    allDevices = [];
                    badge.textContent = '0';
                    document.getElementById('devicesList').innerHTML = `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.devices.no_data')}</div>`;
                }
            } catch (error) {
                console.error('Error loading devices:', error);
                document.getElementById('devicesList').innerHTML = `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.devices.load_error')}</div>`;
            }
        }

        function renderDevices(devices) {
            const container = document.getElementById('devicesList');
            if (devices.length === 0) {
                container.innerHTML = `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.devices.no_match')}</div>`;
                return;
            }

            const grouped = {};
            devices.forEach(d => {
                const cls = d.device_class || window.t('asset-management.devices.other');
                if (!grouped[cls]) grouped[cls] = [];
                grouped[cls].push(d);
            });

            const classes = Object.keys(grouped).sort();
            let html = `<table class="devices-table">
                <thead><tr>
                    <th>${window.t('asset-management.devices.col_device')}</th>
                    <th>${window.t('asset-management.devices.col_manufacturer')}</th>
                    <th>${window.t('asset-management.devices.col_driver_version')}</th>
                    <th>${window.t('asset-management.devices.col_status')}</th>
                </tr></thead><tbody>`;

            classes.forEach(cls => {
                html += `<tr class="device-class-row"><td colspan="4">${escapeHtml(cls)} (${grouped[cls].length})</td></tr>`;
                grouped[cls].forEach(d => {
                    const statusClass = d.status === 'OK' ? 'device-status-ok' :
                        d.status === 'Error' ? 'device-status-error' :
                        d.status === 'Degraded' ? 'device-status-degraded' : '';
                    html += `<tr>
                        <td style="padding-left: 36px;">${escapeHtml(d.device_name)}</td>
                        <td>${escapeHtml(d.manufacturer || '-')}</td>
                        <td>${escapeHtml(d.driver_version || '-')}</td>
                        <td>${d.status ? `<span class="device-status ${statusClass}">${escapeHtml(d.status)}</span>` : '-'}</td>
                    </tr>`;
                });
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        }

        function filterDevices() {
            const query = (document.getElementById('devicesSearch').value || '').toLowerCase();
            if (!query) {
                renderDevices(allDevices);
                return;
            }
            const filtered = allDevices.filter(d =>
                (d.device_name || '').toLowerCase().includes(query) ||
                (d.device_class || '').toLowerCase().includes(query) ||
                (d.manufacturer || '').toLowerCase().includes(query) ||
                (d.driver_version || '').toLowerCase().includes(query) ||
                (d.status || '').toLowerCase().includes(query)
            );
            renderDevices(filtered);
        }

        function switchDetailTab(tab) {
            document.querySelectorAll('.detail-tab').forEach(t => t.classList.toggle('active', t.dataset.dtab === tab));
            document.querySelectorAll('.detail-tab-panel').forEach(p => p.classList.toggle('active', p.dataset.dtabPanel === tab));
        }

        // Load Intune device data for this asset, if any. Renders a third tab when matched.
        async function loadIntuneDevice(assetId) {
            try {
                const response = await fetch(`../api/intune/get_intune_device.php?asset_id=${assetId}`);
                const data = await response.json();
                if (!data.success || !data.device) return;
                renderIntuneTab(data.device);
            } catch (e) {
                // Intune endpoint may not exist on older deployments — fail silently
            }
        }

        function renderIntuneTab(d) {
            const tabs = document.getElementById('detailTabs');
            const body = document.getElementById('detailBody');
            if (!tabs || !body) return;

            const tabBtn = document.createElement('button');
            tabBtn.className = 'detail-tab';
            tabBtn.dataset.dtab = 'intune';
            tabBtn.textContent = window.t('asset-management.intune.tab');
            tabBtn.onclick = () => switchDetailTab('intune');
            tabs.appendChild(tabBtn);

            const panel = document.createElement('div');
            panel.className = 'detail-tab-panel detail-tab-panel--scroll';
            panel.dataset.dtabPanel = 'intune';
            panel.innerHTML = renderIntuneTabBody(d);
            body.appendChild(panel);
        }

        function renderIntuneTabBody(d) {
            const totalGB = d.total_storage_bytes ? (d.total_storage_bytes / 1073741824).toFixed(1) : null;
            const freeGB  = d.free_storage_bytes  ? (d.free_storage_bytes  / 1073741824).toFixed(1) : null;
            const yes = window.t('asset-management.common.yes');
            const no = window.t('asset-management.common.no');
            const storage = (totalGB && freeGB) ? window.t('asset-management.intune.storage_value', { free: freeGB, total: totalGB }) : '-';

            const fields = [
                [window.t('asset-management.intune.compliance_state'),     d.compliance_state],
                [window.t('asset-management.intune.management_state'),     d.management_state],
                [window.t('asset-management.intune.owner_type'),           d.managed_device_owner_type],
                [window.t('asset-management.intune.enrollment_type'),      d.device_enrollment_type],
                [window.t('asset-management.intune.registration_state'),   d.device_registration_state],
                [window.t('asset-management.intune.enrolled'),             d.enrolled_datetime ? formatDate(d.enrolled_datetime) : '-'],
                [window.t('asset-management.intune.last_checkin'),         d.last_sync_datetime ? formatDateTime(d.last_sync_datetime) : '-'],
                [window.t('asset-management.intune.primary_user'),         d.user_display_name || '-'],
                [window.t('asset-management.intune.user_principal_name'),  d.user_principal_name || '-'],
                [window.t('asset-management.intune.os_version'),           (d.operating_system || '-') + (d.os_version ? ' ' + d.os_version : '')],
                [window.t('asset-management.field.manufacturer'),          d.manufacturer || '-'],
                [window.t('asset-management.field.model'),                 d.model || '-'],
                [window.t('asset-management.intune.serial_number'),        d.serial_number || '-'],
                [window.t('asset-management.detail.storage'),              storage],
                [window.t('asset-management.intune.encrypted'),            d.is_encrypted == 1 ? yes : (d.is_encrypted == 0 ? no : '-')],
                [window.t('asset-management.intune.supervised'),           d.is_supervised == 1 ? yes : (d.is_supervised == 0 ? no : '-')],
                [window.t('asset-management.intune.jail_broken'),          d.jail_broken || '-'],
                [window.t('asset-management.intune.imei'),                 d.imei || '-'],
                [window.t('asset-management.intune.meid'),                 d.meid || '-'],
                [window.t('asset-management.intune.wifi_mac'),             d.wifi_mac_address || '-'],
                [window.t('asset-management.intune.ethernet_mac'),         d.ethernet_mac_address || '-'],
                [window.t('asset-management.intune.azure_ad_device_id'),   d.azure_ad_device_id || '-'],
                [window.t('asset-management.intune.intune_device_id'),     d.intune_id || '-'],
                [window.t('asset-management.intune.cached'),               d.last_seen_local ? formatDateTime(d.last_seen_local) : '-'],
            ];

            return `<div class="asset-info-grid">${fields.map(([k, v]) => `
                <div class="info-item">
                    <span class="info-label">${escapeHtml(k)}</span>
                    <span class="info-value">${escapeHtml(v == null ? '-' : String(v))}</span>
                </div>`).join('')}</div>`;
        }

        // Load installed software for an asset
        async function loadInstalledSoftware(assetId) {
            activeSwFilter = 'apps';
            try {
                const response = await fetch(`${API_BASE}get_asset_software.php?asset_id=${assetId}`);
                const data = await response.json();

                if (data.success) {
                    allAssetSoftware = data.software;
                    updateSwTabCounts();
                    renderAssetSoftware();
                } else {
                    allAssetSoftware = [];
                    document.getElementById('softwareCountBadge').textContent = '0';
                    document.getElementById('installedSoftwareList').innerHTML = `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.software.load_error')}</div>`;
                }
            } catch (error) {
                console.error('Error loading installed software:', error);
                allAssetSoftware = [];
                document.getElementById('installedSoftwareList').innerHTML = `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.software.load_error')}</div>`;
                document.getElementById('softwareCountBadge').textContent = '0';
            }
        }

        function updateSwTabCounts() {
            const apps = allAssetSoftware.filter(s => !parseInt(s.system_component));
            const components = allAssetSoftware.filter(s => parseInt(s.system_component));
            document.getElementById('swCountApps').textContent = apps.length;
            document.getElementById('swCountComponents').textContent = components.length;
            document.getElementById('swCountAll').textContent = allAssetSoftware.length;
        }

        function switchSwTab(filter) {
            activeSwFilter = filter;
            document.querySelectorAll('.sw-filter-tab').forEach(tab => {
                tab.classList.toggle('active', tab.dataset.swfilter === filter);
            });
            renderAssetSoftware();
        }

        function renderAssetSoftware() {
            const container = document.getElementById('installedSoftwareList');
            const badge = document.getElementById('softwareCountBadge');

            let software = allAssetSoftware;
            if (activeSwFilter === 'apps') {
                software = software.filter(s => !parseInt(s.system_component));
            } else if (activeSwFilter === 'components') {
                software = software.filter(s => parseInt(s.system_component));
            }

            badge.textContent = software.length;

            if (software.length === 0) {
                container.innerHTML = `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.software.no_data')}</div>`;
                return;
            }

            container.innerHTML = `
                <table class="software-table">
                    <thead>
                        <tr>
                            <th>${window.t('asset-management.software.col_application')}</th>
                            <th>${window.t('asset-management.software.col_publisher')}</th>
                            <th>${window.t('asset-management.software.col_version')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${software.map(sw => `
                            <tr>
                                <td>${escapeHtml(sw.display_name)}</td>
                                <td>${escapeHtml(sw.publisher || '\u2014')}</td>
                                <td>${escapeHtml(sw.display_version || '\u2014')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        }

        // Open assign user modal
        function openAssignModal() {
            selectedUserForAssign = null;
            document.getElementById('userSearchInput').value = '';
            document.getElementById('userSearchResults').innerHTML = `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.assign.type_to_search')}</div>`;
            document.getElementById('assignExpectedReturn').value = '';
            document.getElementById('assignBtn').disabled = true;
            document.getElementById('assignUserModal').classList.add('active');
            document.getElementById('userSearchInput').focus();
        }

        // Close assign modal
        function closeAssignModal() {
            document.getElementById('assignUserModal').classList.remove('active');
            selectedUserForAssign = null;
        }

        // Search users for assignment
        async function searchUsersForAssign() {
            const search = document.getElementById('userSearchInput').value;

            if (search.length < 2) {
                document.getElementById('userSearchResults').innerHTML = `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.assign.min_chars')}</div>`;
                return;
            }

            try {
                const response = await fetch(`${API_TICKETS}get_users.php?search=${encodeURIComponent(search)}`);
                const data = await response.json();

                const container = document.getElementById('userSearchResults');

                if (data.success && data.users.length > 0) {
                    container.innerHTML = data.users.map(user => `
                        <div class="user-search-item ${selectedUserForAssign == user.id ? 'selected' : ''}" onclick="selectUserForAssign(${user.id}, '${escapeHtml(user.display_name)}')">
                            <div class="user-search-name">${escapeHtml(user.display_name || window.t('asset-management.common.unknown'))}</div>
                            <div class="user-search-email">${escapeHtml(user.email || '')}</div>
                        </div>
                    `).join('');
                } else {
                    container.innerHTML = `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.assign.no_users')}</div>`;
                }
            } catch (error) {
                console.error('Error searching users:', error);
            }
        }

        // Select a user for assignment
        function selectUserForAssign(userId, userName) {
            selectedUserForAssign = userId;
            document.getElementById('assignBtn').disabled = false;

            // Update UI to show selection
            document.querySelectorAll('.user-search-item').forEach(item => {
                item.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
        }

        // Re-assign: open the assign modal (will remove current user on confirm)
        function reassignUser() {
            openAssignModal();
        }

        // Confirm user assignment (handles both assign and re-assign)
        async function confirmAssignUser() {
            if (!selectedUserForAssign || !selectedAssetId) return;

            try {
                const previousUserId = currentAssignedUserId;

                // If re-assigning, remove current user first (skip audit, assign will log it)
                if (previousUserId) {
                    await fetch(API_BASE + 'unassign_asset_user.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            asset_id: selectedAssetId,
                            user_id: previousUserId,
                            skip_audit: true
                        })
                    });
                }

                const assignBody = {
                    asset_id: selectedAssetId,
                    user_id: selectedUserForAssign,
                    expected_return_date: document.getElementById('assignExpectedReturn').value || null
                };
                if (previousUserId) {
                    assignBody.previous_user_id = previousUserId;
                }

                const response = await fetch(API_BASE + 'assign_asset_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(assignBody)
                });
                const data = await response.json();

                if (data.success) {
                    closeAssignModal();
                    showToast(window.t('asset-management.toast.user_assigned'), 'success');
                    // Refresh the asset details and list
                    loadAssets(document.getElementById('assetSearch').value);
                    selectAsset(selectedAssetId);
                } else {
                    showToast(window.t('asset-management.toast.assign_error', { error: data.error }), 'error');
                }
            } catch (error) {
                console.error('Error assigning user:', error);
                showToast(window.t('asset-management.toast.assign_failed'), 'error');
            }
        }

        // Unassign a user from the asset
        async function unassignUser(userId) {
            if (!(await showConfirm({ title: window.t('asset-management.common.delete'), message: window.t('asset-management.confirm.remove_user'), okLabel: window.t('asset-management.common.delete'), okClass: 'danger' }))) return;

            try {
                const response = await fetch(API_BASE + 'unassign_asset_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        asset_id: selectedAssetId,
                        user_id: userId
                    })
                });
                const data = await response.json();

                if (data.success) {
                    showToast(window.t('asset-management.toast.user_removed'), 'success');
                    // Refresh the asset details and list
                    loadAssets(document.getElementById('assetSearch').value);
                    selectAsset(selectedAssetId);
                } else {
                    showToast(window.t('asset-management.toast.remove_error', { error: data.error }), 'error');
                }
            } catch (error) {
                console.error('Error removing user:', error);
                showToast(window.t('asset-management.toast.remove_failed'), 'error');
            }
        }

        // Escape HTML for safe display
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Format date for display
        function formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        }

        // Close modal on outside click
        document.getElementById('assignUserModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAssignModal();
            }
        });

        // Asset History functions
        async function openHistoryModal(assetId) {
            document.getElementById('assetHistoryModal').classList.add('active');
            document.getElementById('historyModalBody').innerHTML = '<div class="loading"><div class="spinner"></div></div>';

            try {
                const response = await fetch(`${API_BASE}get_asset_history.php?asset_id=${assetId}`);
                const data = await response.json();

                if (data.success) {
                    renderHistory(data.history);
                } else {
                    document.getElementById('historyModalBody').innerHTML =
                        `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.history.load_error', { error: escapeHtml(data.error) })}</div>`;
                }
            } catch (error) {
                document.getElementById('historyModalBody').innerHTML =
                    `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.history.load_failed')}</div>`;
            }
        }

        function renderHistory(history) {
            const container = document.getElementById('historyModalBody');

            if (history.length === 0) {
                container.innerHTML = `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.history.no_history')}</div>`;
                return;
            }

            let html = `<table class="history-table">
                <thead>
                    <tr>
                        <th>${window.t('asset-management.history.col_date')}</th>
                        <th>${window.t('asset-management.history.col_field')}</th>
                        <th>${window.t('asset-management.history.col_change')}</th>
                        <th>${window.t('asset-management.history.col_analyst')}</th>
                    </tr>
                </thead>
                <tbody>`;

            // Field names are stored as stable keys (e.g. 'purchase_date') so they
            // localise here. Legacy rows hold an English label (with spaces/capitals)
            // — those don't match a key, so we show them as-is.
            const FIELD_KEYS = ['type','status','location','supplier','purchase_date',
                'purchase_cost','order_number','warranty_expiry','assigned_user'];

            history.forEach(entry => {
                const noneEm = `<em style="color:#999;">${window.t('asset-management.common.none')}</em>`;
                const oldVal = entry.old_value ? escapeHtml(entry.old_value) : noneEm;
                const newVal = entry.new_value ? escapeHtml(entry.new_value) : noneEm;
                const fieldLabel = FIELD_KEYS.includes(entry.field_name)
                    ? window.t('asset-management.field.' + entry.field_name)
                    : entry.field_name;

                html += `<tr>
                    <td class="history-meta">${formatDateTime(entry.created_datetime)}</td>
                    <td><span class="history-field-badge">${escapeHtml(fieldLabel)}</span></td>
                    <td>
                        <span class="history-value-old">${oldVal}</span>
                        <span class="history-arrow">&rarr;</span>
                        <span class="history-value-new">${newVal}</span>
                    </td>
                    <td class="history-meta">${escapeHtml(entry.analyst_name || window.t('asset-management.common.unknown'))}</td>
                </tr>`;
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        }

        function formatDateTime(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString + 'Z');
            return date.toLocaleDateString('en-GB', {
                day: '2-digit', month: 'short', year: 'numeric'
            }) + ' ' + date.toLocaleTimeString('en-GB', {
                hour: '2-digit', minute: '2-digit'
            });
        }

        function closeHistoryModal() {
            document.getElementById('assetHistoryModal').classList.remove('active');
        }

        document.getElementById('assetHistoryModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeHistoryModal();
            }
        });

        // ─── Custody (check-in / check-out) trail ───────────────────────────
        async function openCheckoutLog(assetId) {
            document.getElementById('checkoutLogModal').classList.add('active');
            document.getElementById('checkoutLogBody').innerHTML = '<div class="loading"><div class="spinner"></div></div>';
            try {
                const response = await fetch(`${API_BASE}get_asset_checkout_log.php?asset_id=${assetId}`);
                const data = await response.json();
                if (data.success) {
                    renderCheckoutLog(data.log);
                } else {
                    document.getElementById('checkoutLogBody').innerHTML =
                        `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.custody.load_error', { error: escapeHtml(data.error) })}</div>`;
                }
            } catch (error) {
                document.getElementById('checkoutLogBody').innerHTML =
                    `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.custody.load_failed')}</div>`;
            }
        }

        function renderCheckoutLog(log) {
            const container = document.getElementById('checkoutLogBody');
            if (!log || log.length === 0) {
                container.innerHTML = `<div class="empty-state" style="padding: 20px;">${window.t('asset-management.custody.no_events')}</div>`;
                return;
            }
            let html = `<table class="history-table">
                <thead>
                    <tr><th>${window.t('asset-management.custody.col_date')}</th><th>${window.t('asset-management.custody.col_event')}</th><th>${window.t('asset-management.custody.col_user')}</th><th>${window.t('asset-management.custody.col_due_back')}</th><th>${window.t('asset-management.custody.col_analyst')}</th></tr>
                </thead>
                <tbody>`;
            log.forEach(e => {
                const isOut = e.action === 'checkout';
                const badge = isOut
                    ? `<span class="history-field-badge" style="background:#e8f5e9;color:#2e7d32;">${window.t('asset-management.custody.checked_out')}</span>`
                    : `<span class="history-field-badge" style="background:#eef2f7;color:#37474f;">${window.t('asset-management.custody.checked_in')}</span>`;
                html += `<tr>
                    <td class="history-meta">${formatDateTime(e.action_datetime)}</td>
                    <td>${badge}</td>
                    <td>${escapeHtml(e.user_name || window.t('asset-management.common.unknown'))}</td>
                    <td class="history-meta">${e.expected_return_date ? escapeHtml(e.expected_return_date) : '-'}</td>
                    <td class="history-meta">${escapeHtml(e.analyst_name || window.t('asset-management.common.unknown'))}</td>
                </tr>`;
            });
            html += '</tbody></table>';
            container.innerHTML = html;
        }

        function closeCheckoutLog() {
            document.getElementById('checkoutLogModal').classList.remove('active');
        }

        document.getElementById('checkoutLogModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCheckoutLog();
            }
        });
    </script>
</body>
</html>
