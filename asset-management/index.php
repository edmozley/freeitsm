<?php
/**
 * Assets - View and manage IT assets and their user assignments
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('assets');

$current_page = 'assets';
$path_prefix = '../';
$translationNamespaces = ['common', 'asset-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('asset-management.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/record-preview.css?v=1">
    <script src="../assets/js/record-preview.js?v=1"></script>
    <?php
    // This page renders its detail pane in JavaScript, so it takes the panel's
    // assets here and mounts the component itself — renderDocumentsPanel() is for
    // server-rendered records like contracts/edit.php.
    require_once __DIR__ . '/../includes/documents_panel.php';
    documentsPanelAssets('../');
    ?>
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

        /* Picked for a batch (#935). Deliberately a different treatment from
           .selected — that means "this is the one you're looking at", this means
           "this is in the pile" — and both can be true of the same row. */
        .asset-item.multi-selected {
            background-color: var(--surface-hover, #eef2f6);
            box-shadow: inset 3px 0 0 var(--success-accent, #16a34a);
        }

        .asset-count-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .asset-count-actions {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .assets-tag-link {
            flex: 0 0 auto;
            font-size: 12px;
            color: var(--accent, #0078d4);
            text-decoration: none;
        }
        .assets-tag-link:hover { text-decoration: underline; }

        .asset-tag-chip {
            display: inline-block;
            margin-right: 6px;
            padding: 1px 6px;
            border-radius: 4px;
            background: var(--surface-3, #eef1f4);
            color: var(--text-muted, #556);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .asset-select-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
            padding: 8px 10px;
            border-radius: 6px;
            background: var(--surface-hover, #eef2f6);
            border: 1px solid var(--border, #dde3ea);
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text, #333);
        }
        .asset-select-bar[hidden] { display: none; }
        .asset-select-actions { display: flex; gap: 6px; }

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
        /* The holder's name links to their record. It inherits the weight and
           size above and only takes the accent colour, so the panel does not
           suddenly read as a row of links. */
        .user-name-link {
            color: var(--accent, #0078d4);
            text-decoration: none;
        }
        .user-name-link:hover { text-decoration: underline; }

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

        /* Modal Styles
           ⚠️ These OVERRIDE the shared definitions in assets/css/inbox.css,
           which this page already loads. inbox.css is the canonical source for
           .modal / .modal-content / .modal-header / .modal-body / .modal-footer
           / .modal-actions, and the house rule is not to redefine them.
           The overrides here narrow the dialog (500px / 80vh vs 900px / 90vh)
           and drop the entrance transition; because .modal-content ends up
           without a scroll container, .modal-actions cannot stick to it, so
           every modal on this page must use the header / body / footer layout.
           Left in place deliberately — removing them resizes every dialog on
           this page and needs its own look-over. */
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

        /* Add-an-asset modal (#1132) */
        .new-asset-intro {
            margin: 0 0 16px 0; font-size: 13px; line-height: 1.5;
            color: var(--text-muted, #666);
        }
        /* .modal-content is a flex column capped at 80vh, so a <form> wrapping
           the whole dialog has to become that column itself — otherwise
           .modal-body cannot scroll and the footer is pushed off the bottom. */
        .modal-form {
            display: flex; flex-direction: column;
            flex: 1; min-height: 0;
        }
        /* This page had no .form-hint (it lives on the settings page). Defined
           here rather than borrowed, because there is no shared modal CSS. */
        /* Same values as the settings page's copy — --text-dim, not
           --text-muted — so a hint reads identically in both places. */
        .form-hint {
            margin-top: 4px; font-size: 12px; line-height: 1.4;
            color: var(--text-dim, #888);
        }
        .new-asset-next { margin: 4px 0 0 0; }
        /* Separates the built-in columns from the type's own fields. Without it
           "Manufacturer" and "Make" read as the same question asked twice. */
        .na-group-title {
            margin: 18px 0 10px 0; padding-top: 14px;
            border-top: 1px solid var(--border-soft, #eee);
            font-size: 11px; font-weight: 600; letter-spacing: 0.4px;
            text-transform: uppercase; color: var(--text-muted, #666);
        }
        /* In the dialog a custom field is a .form-group like any other, so it
           needs no grid — it stacks with Manufacturer and Model. Only the
           number+unit pair needs a row, and the unit must not stretch. */
        #naNext .cf-numrow { display: flex; align-items: center; gap: 8px; }
        #naNext .cf-numrow .search-box { flex: 1 1 auto; min-width: 0; }

        /* Asset type icons (#1146) */
        .asset-type-icon { vertical-align: -2px; margin-right: 7px; color: var(--text-muted, #666); flex-shrink: 0; }
        .asset-detail-hostname .asset-type-icon { vertical-align: -2px; margin-right: 10px; color: var(--accent, #0078d4); }

        /* Custom fields (docs/design/flexible-asset-fields.md) */
        .custom-fields-section {
            border-top: 1px solid var(--border, #e0e0e0);
        }
        .custom-fields-section .section-header { display: flex; align-items: baseline; gap: 10px; }
        .cf-count { font-size: 12px; color: var(--text-muted, #666); }
        .cf-set { padding-bottom: 4px; }
        .cf-set-head { padding: 10px 20px 0 20px; }
        .cf-setname { font-size: 12px; font-weight: 600; letter-spacing: 0.3px;
                      text-transform: uppercase; color: var(--text-muted, #666); }
        /* A set attached to THIS asset alone. Visible and removable, so the
           extra fields are never magic. */
        .cf-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 6px 3px 10px; border-radius: 999px;
            background: var(--surface-3, #f8f9fa);
            border: 1px solid var(--border, #e0e0e0);
            font-size: 12px; color: var(--text, #333);
        }
        .cf-chip-x {
            border: 0; background: none; cursor: pointer; line-height: 1;
            font-size: 15px; color: var(--text-muted, #666); padding: 0 2px;
        }
        .cf-chip-x:hover { color: var(--danger-text, #c0392b); }
        .cf-req { color: var(--danger-text, #c0392b); margin-left: 3px; }
        .cf-numrow { display: flex; align-items: center; gap: 6px; }
        .cf-numrow .info-value-input { flex: 1 1 auto; min-width: 0; }
        .cf-unit { font-size: 12px; color: var(--text-muted, #666); flex-shrink: 0; }
        .cf-hint { display: block; font-size: 11px; color: var(--text-muted, #666); margin-top: 3px; }
        .cf-empty { padding: 14px 20px; font-size: 13px; color: var(--text-faint, #999); }
        .cf-toggle {
            margin: 4px 20px 12px 20px; padding: 0; border: 0; background: none;
            /* ⚠️ --link does NOT exist. This module's link colour is --accent,
               which is what every other control on this page already uses. */
            cursor: pointer; font-size: 12px; color: var(--accent, #0066cc);
        }
        .cf-toggle:hover { text-decoration: underline; }
        .cf-addset { padding: 0 20px 16px 20px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .cf-addset-hint { flex-basis: 100%; font-size: 11px; color: var(--text-muted, #666); }

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

        /* Tickets raised against this asset (discussion #57). Themed from real
           tokens throughout so dark mode needs no override block. */
        .asset-tickets-list { padding: 4px 0; }

        /* The documents panel brings no padding of its own — the host page owns
           its spacing, or the panel would double up inside a form that already
           has some (as contracts does). 20px matches .asset-info-grid, so the
           Documents tab lines up with Key info rather than nearly lining up. */
        #assetDocuments { padding: 20px; }
        .asset-tickets-empty {
            padding: 24px 16px;
            color: var(--text-faint, #9ca3af);
            font-style: italic;
            font-size: 13px;
            text-align: center;
        }
        .asset-tickets-group {
            padding: 10px 16px 4px;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            color: var(--text-faint, #9ca3af);
        }
        .asset-ticket-row {
            display: grid;
            /* Five columns, the last being the ⓘ preview badge (#91). It is a
               column of its own rather than a passenger in the date cell so it
               lines up down the panel, and so it survives the phone layout
               below — where the reference and the date are hidden. */
            grid-template-columns: minmax(90px, auto) 1fr auto auto auto;
            gap: 12px;
            align-items: center;
            padding: 9px 16px;
            border-bottom: 1px solid var(--border-soft, #f0f0f0);
            text-decoration: none;
            color: inherit;
            font-size: 13px;
        }
        .asset-ticket-row:hover { background-color: var(--surface-hover, #f8f9fa); }

        /* Contracts covering this asset (#106). Same shape as the ticket rows
           above, deliberately: two lists in one tab strip that look different
           read as two different kinds of thing. */
        .asset-contract-row {
            display: grid;
            /* Five columns: reference, title, supplier, ends, notice. The two
               dates are separate cells rather than one stacked cell (Ed) - they
               are two different deadlines, and stacking made the earlier and
               more urgent one look like a footnote to the later one. Fixed
               widths on the dates so they line up down the list; a column that
               shifts row to row is no easier to scan than no column. */
            /* ⚠️ EVERY column except the title is a FIXED width, and that is
               what makes the headings line up. The heading row and each data
               row are separate grids, so an `auto` column resolves to its own
               content in each one — "Supplier" and "Nexus IT" are not the same
               width, and the heading ends up a few pixels off the thing it
               labels. Only 1fr, which fills what is left, is safe to share. */
            grid-template-columns: 110px 1fr 140px 150px 165px;
            gap: 12px;
            align-items: center;
            padding: 9px 16px;
            border-bottom: 1px solid var(--border-soft, #f0f0f0);
            text-decoration: none;
            color: inherit;
            font-size: 13px;
        }
        .asset-contract-row:hover { background-color: var(--surface-hover, #f8f9fa); }
        .asset-contract-ref {
            color: var(--text-dim, #6b7280); font-family: monospace; font-size: 12px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .asset-contract-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        /* The link's own note - a phone number, a line ID - reads as a detail of
           the title rather than a field of its own. */
        .asset-contract-title em { color: var(--text-muted, #6b7280); font-style: normal; font-size: 12px; }
        /* Now that it has a fixed width, a long supplier name has to be told
           what to do rather than pushing the dates out of line. */
        .asset-contract-meta {
            color: var(--text-muted, #6b7280); font-size: 12px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .asset-contract-when { color: var(--text-muted, #6b7280); font-size: 12px; text-align: right; }
        /* The notice date is the one people miss, so it keeps the warning colour
           even now that it has a column of its own. */
        .asset-contract-notice {
            color: var(--warning-text, #92400e);
            font-size: 12px;
            text-align: right;
            white-space: nowrap;
        }

        /* The row and its remove button. The button is a sibling of the link,
           not inside it, so clicking it cannot also navigate. */
        .asset-contract-item { display: flex; align-items: center; }
        .asset-contract-item .asset-contract-row { flex: 1; min-width: 0; border-bottom: none; }
        .asset-contract-item { border-bottom: 1px solid var(--border-soft, #f0f0f0); }
        .asset-contract-remove {
            flex-shrink: 0; width: 26px; height: 26px; margin-right: 10px; line-height: 1;
            border: none; background: none; cursor: pointer;
            font-size: 18px; color: var(--text-faint, #bbb); border-radius: 4px;
        }
        .asset-contract-remove:hover { background: var(--danger-bg, #fee2e2); color: var(--danger-text, #991b1b); }
        /* Stands in for the remove button on the heading row, so the columns
           line up with the cells they label. Width must track .asset-contract-
           remove above: 26px plus its 10px right margin. */
        .asset-contract-remove-spacer { flex-shrink: 0; width: 26px; margin-right: 10px; }
        /* ⚠️ The heading row is a separate element from the rows it labels, so
           every item added beside a row has to be added to the head as an empty
           stand-in of the same width. Miss one and the headings sit a badge to
           the right of the columns they name. */
        .asset-contract-item > .rp-badge { flex-shrink: 0; margin: 0 2px 0 6px; }
        .asset-contract-preview-spacer { flex-shrink: 0; width: 20px; margin: 0 2px 0 6px; }

        .asset-contract-head .asset-contract-row {
            padding-top: 12px;
            padding-bottom: 6px;
            cursor: default;
        }
        .asset-contract-head span {
            font-family: inherit;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
            /* One colour for every heading. The notice column is coloured in the
               DATA because that date is urgent; a coloured heading would say the
               column itself is a warning. */
            color: var(--text-faint, #999);
        }
        .asset-contract-head:hover { background: none; }

        .asset-contract-add-bar { padding: 10px 16px 4px; }
        .asset-contract-add {
            border: 1px solid var(--border, #ddd); background: var(--surface, #fff);
            color: var(--accent, #0078d4); border-radius: 6px;
            padding: 5px 12px; font: inherit; font-size: 12px; font-weight: 600; cursor: pointer;
        }
        .asset-contract-add:hover { background: var(--surface-hover, #f5f5f5); }

        .contract-pick-results { max-height: 320px; overflow-y: auto; margin-top: 10px; }
        .ctr-pick-search {
            width: 100%; box-sizing: border-box; padding: 8px 10px;
            border: 1px solid var(--border, #ddd); border-radius: 6px;
            font: inherit; font-size: 13px;
            background: var(--surface, #fff); color: var(--text, #333);
        }
        .ctr-pick-search:focus { outline: none; border-color: var(--accent, #0078d4); }
        .contract-pick {
            display: block; width: 100%; text-align: left; cursor: pointer;
            padding: 9px 10px; border: none; border-radius: 6px;
            background: none; font: inherit; color: var(--text, #333);
        }
        .contract-pick:hover { background: var(--surface-hover, #f5f5f5); }
        .contract-pick-name { display: block; font-size: 13px; font-weight: 500; }
        .contract-pick-meta { display: block; font-size: 12px; color: var(--text-muted, #6b7280); }

        /* Right-click menu. The menu itself, its items and its flyouts come from
           .ticket-context-menu in inbox.css, which this page already loads; only
           the parts that menu has never needed are defined here. */
        .asset-ctx-sep { height: 1px; margin: 4px 0; background: var(--border-soft, #eee); }
        /* A fixed-width gutter so the labels line up whether or not a row is the
           current value - a tick that shifts the text is worse than no tick. */
        .asset-ctx-tick { display: inline-block; width: 14px; color: var(--accent, #0078d4); }
        .asset-ctx-empty { color: var(--text-dim, #999); cursor: default; }
        .asset-ctx-empty:hover { background: none; }
        .asset-ticket-ref { color: var(--text-dim, #6b7280); font-family: monospace; font-size: 12px; }
        .asset-ticket-subject {
            color: var(--text, #111827);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .asset-ticket-status {
            color: #fff;
            padding: 2px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 500;
            white-space: nowrap;
        }
        .asset-ticket-when { color: var(--text-faint, #9ca3af); font-size: 12px; white-space: nowrap; }

        @media (max-width: 700px) {
            /* Ref and date drop away first — the subject and its status are what
               you are actually scanning for on a phone. */
            /* Subject, status, and the preview badge — which keeps its column
               here precisely because the reference and the date have gone: on a
               phone the badge is the only way left to see what the ticket is
               without leaving the asset. */
            .asset-ticket-row { grid-template-columns: 1fr auto auto; }
            .asset-ticket-ref, .asset-ticket-when { display: none; }

            /* The contract rows lose the reference and the supplier, but KEEP
               both dates: when it ends and when notice is due is the whole
               reason for looking. The fixed date widths go too — on a phone
               there is not room to reserve them. */
            .asset-contract-row { grid-template-columns: 1fr auto auto; }
            .asset-contract-ref, .asset-contract-meta { display: none; }
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
    <?php /* Mobile-friendly opt-in (#936). Deliberately AFTER this page's own
             <style> block so its @media rules win on ties — the ordering rule
             from the wiki's Mobile-Friendly-Techniques. Every rule inside it is
             gated at 768px, so the desktop layout is untouched. */ ?>
    <link rel="stylesheet" href="../assets/css/mobile.css?v=133">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container assets-container">
        <!-- Assets List -->
        <div class="assets-list-container">
            <div class="assets-list-header">
                <h3><?php echo htmlspecialchars(t('asset-management.nav.assets')); ?></h3>
                <input type="text" class="search-box" id="assetSearch" placeholder="<?php echo htmlspecialchars(t('asset-management.list.search_placeholder')); ?>" oninput="searchAssets()" autocomplete="off">
                <?php /* The count and the bulk-tagging link are SIBLINGS, not
                         nested: renderAssetsList() sets #assetCount's textContent,
                         which would wipe any child element on the first render. */ ?>
                <div class="asset-count-row">
                    <div class="asset-count" id="assetCount"></div>
                    <?php /* Bulk tagging (#935) — an occasional job, so a quiet link
                             beside the count rather than a nav item competing with
                             the things people use every day. Scanning (#938) sits
                             here for the same reason, and next to it because the
                             two are the same kind of standing-up-with-a-phone job. */ ?>
                    <?php /* Grouped in one span: the row is space-between, so two
                             loose links would sit at opposite ends of it with the
                             count stranded in the middle. */ ?>
                    <span class="asset-count-actions">
                        <?php /* Adding an asset by hand (#1132). Until now the only
                                 ways in were the inventory agent, Intune, vCenter and
                                 the REST API — every one of which assumes the thing
                                 reports for itself. A television never will. It sits
                                 with Scan and Assign tags because it is the same kind
                                 of occasional job, and a promoted button here would
                                 compete with the search box people use constantly. */ ?>
                        <a class="assets-tag-link" href="#" onclick="openNewAssetModal(); return false;"><?php echo htmlspecialchars(t('asset-management.list.add_asset')); ?></a>
                        <a class="assets-tag-link" href="scanner.php"><?php echo htmlspecialchars(t('asset-management.list.scan')); ?></a>
                        <a class="assets-tag-link" href="assign-tags.php"><?php echo htmlspecialchars(t('asset-management.list.assign_tags')); ?></a>
                    </span>
                </div>
                <?php /* Appears only once more than one asset is picked with
                         Ctrl/Shift — see handleAssetRowClick(). Hidden by default
                         so the list is unchanged for anyone not using it. */ ?>
                <div class="asset-select-bar" id="assetSelectBar" hidden>
                    <span id="assetSelectCount"></span>
                    <span class="asset-select-actions">
                        <?php /* Plural: this bar only ever appears for 2+. */ ?>
                        <button class="btn btn-outline btn-sm" onclick="printSelectedLabels()"><?php echo htmlspecialchars(t('asset-management.list.print_labels')); ?></button>
                        <button class="btn btn-outline btn-sm" onclick="clearAssetSelection()"><?php echo htmlspecialchars(t('asset-management.list.clear_selection')); ?></button>
                    </span>
                </div>
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

    <?php /* New asset (#1132).
             CORE FIELDS ONLY, deliberately. Custom fields are filled in on the
             asset itself where there is room and the "3 of 3 filled in" counter
             says what is outstanding — a modal is the wrong place to answer
             eight questions. The agent-synced hardware columns (CPU, BIOS,
             memory…) are absent too: typing them in would be overwritten by the
             next sync on anything that does report for itself. */ ?>
    <div class="modal" id="newAssetModal">
        <div class="modal-content">
            <div class="modal-header">
                <span><?php echo htmlspecialchars(t('asset-management.new.heading')); ?></span>
            </div>
            <?php /* ⚠️ header / .modal-body / .modal-footer — the canonical
                     3-pane modal layout documented in assets/css/inbox.css,
                     which this page loads. See #assignUserModal below.

                     inbox.css offers TWO layouts and says when each applies:
                       - fields directly in .modal-content  → .modal-actions
                       - header / body / footer (this one)  → .modal-footer
                     .modal-actions is sticky against .modal-content as the
                     scroll container. This page OVERRIDES .modal-content
                     locally (500px / 80vh, no overflow), so it is not a scroll
                     container — which is why the first version of this dialog,
                     written with .modal-actions and no .modal-body, had its
                     buttons sitting on top of the content and no padding at all.

                     🔑 The local overrides at ~line 464 are the actual problem:
                     inbox.css already defines .modal / .modal-content /
                     .modal-body / .modal-footer, and redefining them is what
                     makes markup non-portable between two pages of the same
                     module. Not unpicked here — it would resize every modal on
                     this page. */ ?>
            <form id="newAssetForm" class="modal-form">
                <div class="modal-body">
                    <p class="new-asset-intro"><?php echo t('asset-management.new.intro'); ?></p>
                    <div class="form-group">
                        <label class="form-label" for="naName"><?php echo htmlspecialchars(t('asset-management.new.name')); ?></label>
                        <input type="text" class="search-box" id="naName" required maxlength="50" autocomplete="off"
                               placeholder="<?php echo htmlspecialchars(t('asset-management.new.name_ph')); ?>">
                        <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.new.name_hint')); ?></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="naType"><?php echo htmlspecialchars(t('asset-management.field.type')); ?></label>
                        <select class="search-box" id="naType"></select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="naStatus"><?php echo htmlspecialchars(t('asset-management.field.status')); ?></label>
                        <select class="search-box" id="naStatus"></select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="naLocation"><?php echo htmlspecialchars(t('asset-management.field.location')); ?></label>
                        <select class="search-box" id="naLocation"></select>
                    </div>
                    <?php /* The built-in columns every asset has, whatever it is.
                             Headed, because the type's own fields follow and the
                             two can legitimately look similar (Manufacturer here,
                             "Make" below) — unlabelled, that reads as the same
                             question asked twice. */ ?>
                    <div class="na-group-title"><?php echo htmlspecialchars(t('asset-management.new.builtin')); ?></div>
                    <div class="form-group">
                        <label class="form-label" for="naManufacturer"><?php echo htmlspecialchars(t('asset-management.field.manufacturer')); ?></label>
                        <input type="text" class="search-box" id="naManufacturer" maxlength="50" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="naModel"><?php echo htmlspecialchars(t('asset-management.field.model')); ?></label>
                        <input type="text" class="search-box" id="naModel" maxlength="50" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="naSerial"><?php echo htmlspecialchars(t('asset-management.detail.service_tag')); ?></label>
                        <input type="text" class="search-box" id="naSerial" maxlength="50" autocomplete="off">
                    </div>
                    <?php /* The chosen type's own fields, rendered live. */ ?>
                    <div class="new-asset-next" id="naNext" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeNewAssetModal()"><?php echo htmlspecialchars(t('asset-management.common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary" id="naSaveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                </div>
            </form>
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
    <!-- Add this equipment to a contract (discussion #106) -->
    <div class="modal" id="contractPickerModal">
        <div class="modal-content">
            <div class="modal-header">
                <span><?php echo htmlspecialchars(t('asset-management.detail.contract_picker_title')); ?></span>
            </div>
            <div class="modal-body">
                <input type="text" id="contractPickerSearch" class="ctr-pick-search" autocomplete="off"
                       oninput="contractPickerInput()"
                       placeholder="<?php echo htmlspecialchars(t('asset-management.detail.contract_search_placeholder')); ?>">
                <div id="contractPickerResults" class="contract-pick-results"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeContractPicker()"><?php echo htmlspecialchars(t('asset-management.common.close')); ?></button>
            </div>
        </div>
    </div>

    <!--
      Right-click an asset (Ed's request).

      ⚠️ Reuses the .ticket-context-menu classes from inbox.css, which this page
      already loads. The names say "ticket" and the styles are entirely
      structural - menu, header, item, submenu parent with a flyout, and the
      left-flip when there is no room on the right. A second copy of all that
      under an asset-flavoured name is how two menus end up looking different.
    -->
    <div class="ticket-context-menu" id="assetContextMenu" role="menu">
        <div class="ticket-context-menu-header" id="assetCtxHeader"></div>

        <button class="ticket-context-menu-item" type="button" data-action="open">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14L21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
            <span><?php echo htmlspecialchars(t('asset-management.ctx.open')); ?></span>
        </button>

        <div class="asset-ctx-sep"></div>

        <div class="ticket-context-menu-item ticket-context-menu-parent" tabindex="0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            <span><?php echo htmlspecialchars(t('asset-management.ctx.status')); ?></span>
            <span class="ctx-sub-arrow">&rsaquo;</span>
            <div class="ticket-context-submenu" id="assetCtxStatus"></div>
        </div>
        <div class="ticket-context-menu-item ticket-context-menu-parent" tabindex="0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></svg>
            <span><?php echo htmlspecialchars(t('asset-management.ctx.type')); ?></span>
            <span class="ctx-sub-arrow">&rsaquo;</span>
            <div class="ticket-context-submenu" id="assetCtxType"></div>
        </div>
        <div class="ticket-context-menu-item ticket-context-menu-parent" tabindex="0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <span><?php echo htmlspecialchars(t('asset-management.ctx.location')); ?></span>
            <span class="ctx-sub-arrow">&rsaquo;</span>
            <div class="ticket-context-submenu" id="assetCtxLocation"></div>
        </div>

        <div class="asset-ctx-sep"></div>

        <button class="ticket-context-menu-item" type="button" data-action="contract" id="assetCtxContract">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15h6"/></svg>
            <span><?php echo htmlspecialchars(t('asset-management.ctx.add_to_contract')); ?></span>
        </button>
        <button class="ticket-context-menu-item" type="button" data-action="assign">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/></svg>
            <span><?php echo htmlspecialchars(t('asset-management.ctx.assign')); ?></span>
        </button>

        <div class="asset-ctx-sep"></div>

        <button class="ticket-context-menu-item" type="button" data-action="label">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
            <span><?php echo htmlspecialchars(t('asset-management.ctx.print_label')); ?></span>
        </button>
        <button class="ticket-context-menu-item" type="button" data-action="copy-tag">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            <span><?php echo htmlspecialchars(t('asset-management.ctx.copy_tag')); ?></span>
        </button>
        <button class="ticket-context-menu-item" type="button" data-action="copy-serial">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            <span><?php echo htmlspecialchars(t('asset-management.ctx.copy_serial')); ?></span>
        </button>
    </div>

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
            // Load the list and the lookups the detail view needs, then honour a
            // deep link (?asset_id=N) — e.g. from the command palette. We wait for
            // the lookups so the selected asset's Type/Status/Location dropdowns
            // render populated rather than empty on first paint.
            Promise.all([
                loadAssets(),
                loadAssetTypesForDropdown(),
                loadAssetStatusTypesForDropdown(),
                loadLocationsForDropdown(),
                loadAssetSuppliersForDropdown()
            ]).then(function() {
                // `asset_id` is the canonical spelling (includes/entity_links.php).
                // `asset` is accepted as a LEGACY alias because the table view
                // sent it until issue #84 was fixed, so it is already sitting in
                // people's history and bookmarks.
                //
                // Accepted, but NOT silently: selectAsset() rewrites the address
                // bar to the canonical form, so an old link works once and then
                // corrects itself. That is deliberately different from being
                // tolerant — a reader that quietly accepts both spellings is how
                // the two drifted apart without anyone noticing (see GH #91).
                var params = new URLSearchParams(window.location.search);
                var aid = params.get('asset_id') || params.get('asset');
                if (aid) {
                    var n = parseInt(aid, 10);
                    if (n) selectAsset(n);
                }
            });
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

        /**
         * Save the asset tag (#935). Its own endpoint, because the tag is unique
         * per company — see api/assets/save_asset_tag.php.
         *
         * On a clash the field is put back to what it was: leaving the rejected
         * value on screen would let somebody walk away believing they'd numbered
         * the asset, and then print a label that says something else.
         */
        async function saveAssetTag(value) {
            if (!selectedAssetId) return;
            const input = document.getElementById('assetTagInput');
            const asset = assets.find(a => a.id == selectedAssetId);
            const previous = asset ? (asset.asset_tag || '') : '';
            try {
                const response = await fetch(API_BASE + 'save_asset_tag.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ asset_id: selectedAssetId, asset_tag: value })
                });
                const data = await response.json();
                if (data.success) {
                    if (asset) asset.asset_tag = data.asset_tag || null;
                    if (selectedAsset) selectedAsset.asset_tag = data.asset_tag || null;
                    renderAssetsList();
                } else {
                    showToast(data.error, 'error');
                    if (input) input.value = previous;
                }
            } catch (error) {
                showToast(window.t('asset-management.toast.update_error', { error: 'network' }), 'error');
                if (input) input.value = previous;
            }
        }

        /** Open the printable label sheet for one asset. */
        function printAssetLabel(assetId) {
            window.open('labels.php?ids=' + encodeURIComponent(assetId), '_blank');
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
        /**
         * The icon for an asset's type, as an <svg> string (#1146).
         *
         * Returns '' for no type, no icon, or a library that failed to load —
         * so the list is byte-identical on an install that has never picked one.
         * The markup is our own, from a fixed library, never user input.
         */
        function assetTypeIcon(typeId, size) {
            if (!typeId || !window.nmRenderIcon) return '';
            const t = assetTypes.find(x => x.id == typeId);
            if (!t || !t.icon_key) return '';
            return window.nmRenderIcon(t.icon_key, size || 15, 'class="asset-type-icon"');
        }

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
                <div class="asset-item ${selectedAssetId == asset.id ? 'selected' : ''} ${assetSelection.has(asset.id) ? 'multi-selected' : ''}"
                     data-asset-id="${asset.id}" onclick="handleAssetRowClick(event, ${asset.id})"
                     oncontextmenu="return openAssetContextMenu(event, ${asset.id});">
                    <?php /* The type's icon (#1146) — the whole point of the
                             feature. 576 rows of near-identical text become
                             scannable, and a television stops looking like a
                             laptop. Absent when the type has no icon, or has no
                             type at all, so nothing shifts for an install that
                             never sets one. */ ?>
                    <div class="asset-hostname">${assetTypeIcon(asset.asset_type_id)}${escapeHtml(asset.hostname)}</div>
                    <div class="asset-meta">
                        ${asset.asset_tag ? `<span class="asset-tag-chip">${escapeHtml(asset.asset_tag)}</span>` : ''}
                        <span class="${asset.user_count > 0 ? 'asset-assigned' : 'asset-unassigned'}">
                            ${asset.user_count > 0 ? window.t('asset-management.status.assigned') : window.t('asset-management.status.unassigned')}
                        </span>
                    </div>
                </div>
            `).join('');

            renderAssetSelectionBar();
        }

        // ===================================================================
        // Picking several assets — for printing a batch of labels (#935)
        // ===================================================================
        //
        // Same idiom as the ticket inbox (#910): a plain click still OPENS the
        // asset, exactly as before, and Ctrl/Shift build a selection on top.
        // No checkboxes and no "selection mode" toggle — both would change the
        // list for everyone to serve an occasional job.
        let assetSelection = new Set();
        let assetSelectionAnchor = null;

        /**
         * Stop Shift-click smearing the browser's text selection across the list.
         *
         * The selection starts on MOUSEDOWN, so a `user-select: none` class
         * toggled in the click handler is always one beat too late — the text is
         * already highlighted by then. Cancelling the mousedown default is what
         * actually prevents it, and it takes Edge's selection mini-menu with it:
         * that popup is triggered BY having text selected, so the two symptoms
         * are one cause.
         */
        document.addEventListener('mousedown', function (e) {
            if (!e.shiftKey) return;
            if (e.target.closest && e.target.closest('#assetsList')) e.preventDefault();
        });

        function handleAssetRowClick(event, assetId) {
            const ctrl = event.ctrlKey || event.metaKey;

            if (ctrl) {
                if (assetSelection.has(assetId)) assetSelection.delete(assetId);
                else assetSelection.add(assetId);
                assetSelectionAnchor = assetId;
            } else if (event.shiftKey && assetSelectionAnchor !== null) {
                // A block, from the anchor to here, in the order the list is in.
                const ids = assets.map(a => a.id);
                const from = ids.indexOf(assetSelectionAnchor);
                const to   = ids.indexOf(assetId);
                if (from !== -1 && to !== -1) {
                    const [lo, hi] = from <= to ? [from, to] : [to, from];
                    for (let i = lo; i <= hi; i++) assetSelection.add(ids[i]);
                }
            } else {
                // Plain click: open it. selectAsset() reseeds the selection to
                // just this asset, so a following Ctrl-click gives you two.
                selectAsset(assetId);
                return;
            }

            paintAssetSelection();
            renderAssetSelectionBar();
        }

        /** Toggle the highlight in place — no re-render, so the list doesn't jump. */
        function paintAssetSelection() {
            // Belt and braces: if a selection did start (a drag, or a browser
            // that ignores the mousedown cancel), clear it rather than leave the
            // list looking smeared.
            const sel = window.getSelection && window.getSelection();
            if (sel && !sel.isCollapsed) sel.removeAllRanges();
            document.querySelectorAll('#assetsList .asset-item').forEach(el => {
                const id = Number(el.dataset.assetId);
                el.classList.toggle('multi-selected', assetSelection.has(id));
            });
        }

        function renderAssetSelectionBar() {
            const bar = document.getElementById('assetSelectBar');
            if (!bar) return;
            const n = assetSelection.size;
            // One picked asset is just "the open one" — the bar would be noise.
            if (n < 2) { bar.hidden = true; return; }
            document.getElementById('assetSelectCount').textContent =
                window.t('asset-management.list.n_selected', { count: n });
            bar.hidden = false;
        }

        /**
         * Drop the extras, back to just the asset that's open.
         * NOT back to nothing: something is on screen, and a list where the row
         * you are looking at claims not to be selected is the same inconsistency
         * this whole fix is about.
         */
        function clearAssetSelection() {
            assetSelection = selectedAssetId ? new Set([Number(selectedAssetId)]) : new Set();
            assetSelectionAnchor = selectedAssetId ? Number(selectedAssetId) : null;
            paintAssetSelection();
            renderAssetSelectionBar();
        }

        /** Print labels for everything picked, in one sheet. */
        function printSelectedLabels() {
            if (!assetSelection.size) return;
            window.open('labels.php?ids=' + encodeURIComponent(Array.from(assetSelection).join(',')), '_blank');
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

            // The recent trail (#124).
            if (window.trailVisit) window.trailVisit('asset', assetId);

            // Put the open asset in the address bar, so the URL can be copied,
            // bookmarked or reloaded and land back on the same asset. replaceState
            // rather than pushState: the list stays on screen, so choosing an
            // asset is not navigation — pushing would fill the Back button with
            // steps that never visibly go anywhere. Matches the portal ticket
            // list (self-service/tickets.php).
            //
            // It also normalises the legacy `?asset=` spelling on arrival, so an
            // old link corrects itself the moment it lands.
            //
            // Guarded: history writes throw in some contexts (file:// origins,
            // strict embedders), and a URL nicety must never stop the asset
            // itself loading.
            try {
                if (window.history && window.history.replaceState) {
                    window.history.replaceState({ assetId: Number(assetId) }, '',
                        window.location.pathname + '?asset_id=' + Number(assetId));
                }
            } catch (e) { /* not fatal */ }

            // The asset you have OPEN is part of the selection — the way the
            // highlighted row is in Explorer and Outlook. Opening one and then
            // Ctrl-clicking a second must give you two, not one; seeding here
            // (rather than in the click handler) also covers arriving by deep
            // link, so the rule holds however the asset came to be open.
            assetSelection = new Set([Number(assetId)]);
            assetSelectionAnchor = Number(assetId);

            renderAssetsList();

            if (!selectedAsset) return;

            const detailContainer = document.getElementById('assetDetail');
            detailContainer.innerHTML = `
                <div class="asset-detail-sticky">
                    <div class="asset-detail-header">
                        <h2 class="asset-detail-hostname">${assetTypeIcon(selectedAsset.asset_type_id, 20)}${escapeHtml(selectedAsset.hostname)}</h2>
                        <div class="asset-detail-subtitle">${window.t('asset-management.detail.service_tag')}: ${escapeHtml(selectedAsset.service_tag) || '-'}</div>
                        <div style="margin-top: 10px;">
                            <button class="btn btn-outline btn-sm" onclick="openHistoryModal(${selectedAsset.id})">${window.t('asset-management.detail.view_history')}</button>
                            <button class="btn btn-outline btn-sm" onclick="openCheckoutLog(${selectedAsset.id})">${window.t('asset-management.detail.custody')}</button>
                            <?php /* QR label (#935). Opens the print sheet for this one asset;
                                     the sheet takes a list, so a future multi-select prints many. */ ?>
                            <button class="btn btn-outline btn-sm" onclick="printAssetLabel(${selectedAsset.id})">${window.t('asset-management.detail.print_label')}</button>
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
                        <button class="detail-tab" onclick="switchDetailTab('tickets')" data-dtab="tickets">${window.t('asset-management.detail.tab_tickets')} <span class="tab-count" id="ticketsCountBadge">...</span></button>
                        <button class="detail-tab" onclick="switchDetailTab('contracts')" data-dtab="contracts">${window.t('asset-management.detail.tab_contracts')} <span class="tab-count" id="contractsCountBadge">...</span></button>
                        <button class="detail-tab" onclick="switchDetailTab('documents')" data-dtab="documents">${window.t('common.documents.heading')}</button>
                    </div>
                </div>
                <div class="asset-detail-body" id="detailBody">
                    <div class="detail-tab-panel detail-tab-panel--scroll active" id="keyinfoPanel" data-dtab-panel="keyinfo">
                    <div class="asset-info-grid">
                        <?php /* The number printed on the label. Saved on change through its
                                 own endpoint, because it is unique per company and the generic
                                 field writer has no reason to know about tenants. */ ?>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.asset_tag')}</span>
                            <input type="text" class="info-value-input" id="assetTagInput" maxlength="64"
                                   value="${escapeHtml(selectedAsset.asset_tag || '')}"
                                   placeholder="${window.t('asset-management.field.asset_tag_ph')}"
                                   onchange="saveAssetTag(this.value)">
                        </div>
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
                        <?php /* Editable since #1143. On a machine that reports
                                 in these are overwritten by the next sync, which
                                 is why they used to be read-only — but nothing
                                 will ever report a television's model, and a
                                 typo made while adding one by hand had no way
                                 to be corrected. saveCoreField() warns rather
                                 than blocks; see it for the reasoning. */ ?>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.manufacturer')}</span>
                            <input type="text" class="info-value-input" maxlength="50"
                                   value="${escapeHtml(selectedAsset.manufacturer || '')}" placeholder="-"
                                   onchange="saveCoreField('manufacturer', this)">
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.field.model')}</span>
                            <input type="text" class="info-value-input" maxlength="50"
                                   value="${escapeHtml(selectedAsset.model || '')}" placeholder="-"
                                   onchange="saveCoreField('model', this)">
                        </div>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.detail.service_tag')}</span>
                            <input type="text" class="info-value-input" maxlength="50"
                                   value="${escapeHtml(selectedAsset.service_tag || '')}" placeholder="-"
                                   onchange="saveCoreField('service_tag', this)">
                        </div>
                        <?php /* The NAME. Shown here as well as in the heading
                                 because the heading is a title, not a control —
                                 and this is the field most likely to carry a
                                 typo from the Add dialog. */ ?>
                        <div class="info-item">
                            <span class="info-label">${window.t('asset-management.new.name')}</span>
                            <input type="text" class="info-value-input" maxlength="50"
                                   value="${escapeHtml(selectedAsset.hostname || '')}"
                                   onchange="saveCoreField('hostname', this)">
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
                    <?php /* Custom fields (docs/design/flexible-asset-fields.md).
                             Sits between the built-in details and Storage: it is
                             more of the same question ("what is this thing?"),
                             not a separate topic, so it does not earn a tab.
                             Empty for a plain laptop, and silent when so. */ ?>
                    <div class="custom-fields-section" id="customFieldsSection" style="display:none;">
                        <div class="section-header">
                            <span class="section-title">${window.t('asset-management.detail.cf_heading')}</span>
                            <span class="cf-count" id="cfCount"></span>
                        </div>
                        <div id="assetCustomFields"></div>
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
                    <div class="detail-tab-panel detail-tab-panel--scroll" id="ticketsPanel" data-dtab-panel="tickets">
                        <div class="asset-tickets-list" id="assetTicketsList">
                            <div class="loading"><div class="spinner"></div></div>
                        </div>
                    </div>
                    <div class="detail-tab-panel detail-tab-panel--scroll" id="contractsPanel" data-dtab-panel="contracts">
                        <div class="asset-contracts-list" id="assetContractsList">
                            <div class="loading"><div class="spinner"></div></div>
                        </div>
                    </div>
                    <div class="detail-tab-panel detail-tab-panel--scroll" id="documentsPanel" data-dtab-panel="documents">
                        <div id="assetDocuments"></div>
                    </div>
                </div>
            `;

            // Load assigned users, disks, devices, installed software, and (if matched) Intune data
            loadAssignedUsers(assetId);
            loadDisks(assetId);
            loadDevices(assetId);
            loadInstalledSoftware(assetId);
            loadIntuneDevice(assetId);
            loadAssetTickets(assetId);
            loadAssetContracts(assetId);
            loadCustomFields(assetId);

            // ⚠️ MOUNTED, not re-pointed. This detail pane rebuilds its whole DOM
            // on every asset you click, so the previous panel's element is already
            // gone — there is nothing to call setParent() on. A server-rendered
            // page like contracts/edit.php uses renderDocumentsPanel() instead;
            // this one loads the assets in the head and calls the JS directly,
            // which is why documentsPanelAssets() is separate from the renderer.
            FreeITSMDocuments.mount(document.getElementById('assetDocuments'), {
                parentType: 'asset',
                parentId:   assetId,
                apiBase:    '../api/documents/'
            });
        }

        /**
         * Tickets raised against this asset (discussion #57).
         *
         * The half of the feature that pays for the linking: the answer to "has
         * this thing broken before?" is only visible from the asset's side.
         * Open tickets first, then closed ones as history — the endpoint caps
         * closed at 20 and returns the true total so a long-suffering monitor
         * doesn't render five years of rows.
         */
        /**
         * Contracts covering this asset (discussion #106).
         *
         * The half of the linking that pays for it: standing on a handset and
         * asking what agreement it is on, when it ends, and by when you have to
         * give notice. The notice date is the one people actually miss, so it is
         * shown on the row rather than left on the contract page.
         *
         * ⚠️ An analyst without the Contracts module gets a sentence saying so,
         * not an empty list. "No contracts" and "you may not see contracts" are
         * different answers and reading one as the other is how somebody
         * concludes a contract was never set up.
         */
        async function loadAssetContracts(assetId) {
            const list  = document.getElementById('assetContractsList');
            const badge = document.getElementById('contractsCountBadge');
            if (!list) return;
            try {
                const response = await fetch(`../api/assets/get_asset_contracts.php?asset_id=${assetId}`);
                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'failed');

                if (!data.permitted) {
                    if (badge) badge.textContent = '';
                    list.innerHTML = `<div class="asset-tickets-empty">${escapeHtml(window.t('asset-management.detail.contracts_no_access'))}</div>`;
                    return;
                }

                const rows = data.contracts || [];
                if (badge) badge.textContent = rows.length;

                // The Add bar shows whenever contracts are permitted, INCLUDING
                // when the list is empty — an empty tab with no way to fill it is
                // where the feature looks unfinished.
                const addBar = `<div class="asset-contract-add-bar">
                        <button type="button" class="asset-contract-add" onclick="openContractPicker()">
                            ${escapeHtml(window.t('asset-management.detail.add_contract'))}
                        </button>
                    </div>`;

                if (!rows.length) {
                    list.innerHTML = addBar
                        + `<div class="asset-tickets-empty">${escapeHtml(window.t('asset-management.detail.no_contracts'))}</div>`;
                    return;
                }

                // Headings, so the dates do not have to introduce themselves on
                // every row. Same grid as a row, plus a spacer standing in for
                // the remove button, or the columns would not line up with what
                // they label.
                const head = `<div class="asset-contract-item asset-contract-head">
                        <div class="asset-contract-row">
                            <span class="asset-contract-ref">${escapeHtml(window.t('asset-management.detail.col_number'))}</span>
                            <span class="asset-contract-title">${escapeHtml(window.t('asset-management.detail.col_contract'))}</span>
                            <span class="asset-contract-meta">${escapeHtml(window.t('asset-management.detail.col_supplier'))}</span>
                            <span class="asset-contract-when">${escapeHtml(window.t('asset-management.detail.contract_ends'))}</span>
                            <span class="asset-contract-notice">${escapeHtml(window.t('asset-management.detail.contract_notice_by'))}</span>
                        </div>
                        <span class="asset-contract-preview-spacer"></span>
                        <span class="asset-contract-remove-spacer"></span>
                    </div>`;

                list.innerHTML = addBar + head + rows.map(c => {
                    const supplier = c.supplier_trading_name || c.supplier_name || '';
                    // Bare dates. The words that used to prefix them are now
                    // column headings (Ed) — "Ends" and "Notice by" repeated on
                    // every row is the same two words said as many times as you
                    // have contracts, and it crowds out the thing you came to
                    // read. A contract with no notice date still gets its cell,
                    // so the dates below stay in a straight line.
                    const ends = c.contract_end
                        ? escapeHtml(c.contract_end)
                        : escapeHtml(window.t('asset-management.detail.contract_no_end'));
                    const notice = c.notice_date ? escapeHtml(c.notice_date) : '';
                    // The remove button is a SIBLING of the anchor, not inside
                    // it: a button nested in a link is a link, and clicking it
                    // would navigate as well as unlink.
                    return `
                    <div class="asset-contract-item">
                        <a class="asset-contract-row" href="../contracts/view.php?id=${c.contract_id}">
                            <span class="asset-contract-ref">${escapeHtml(c.contract_number || '')}</span>
                            <span class="asset-contract-title">${escapeHtml(c.title || '')}${c.reference ? ` <em>${escapeHtml(c.reference)}</em>` : ''}</span>
                            <span class="asset-contract-meta">${escapeHtml(supplier)}</span>
                            <span class="asset-contract-when">${ends}</span>
                            <span class="asset-contract-notice">${notice}</span>
                        </a>
                        ${assetPreviewBadge('contract', c.contract_id)}
                        <button type="button" class="asset-contract-remove"
                                title="${escapeHtml(window.t('asset-management.detail.remove_contract'))}"
                                onclick="unlinkAssetContract(${c.link_id})">&times;</button>
                    </div>`;
                }).join('');
            } catch (e) {
                if (badge) badge.textContent = '0';
                list.innerHTML = `<div class="asset-tickets-empty">${escapeHtml(window.t('asset-management.detail.contracts_load_failed'))}</div>`;
            }
        }

        // ── Right-click an asset (Ed's request) ──────────────────────────────
        //
        // Every item is backed by something that ALREADY works: the three
        // submenus go through update_asset_field.php, which is a thin adapter
        // over AssetsService::updateFields, so validation, the audit trail and
        // the warranty-calendar sync come with them. A context menu that writes
        // its own SQL is a second set of rules nobody remembers to update.
        //
        // ⚠️ Right-clicking an asset SELECTS it first. The menu acts on the
        // selection, and acting on a row you have not selected is how somebody
        // changes the status of the wrong machine.
        let ctxAssetId = null;

        function openAssetContextMenu(event, assetId) {
            event.preventDefault();

            // Select first, so the panel on the right and the menu agree about
            // which asset this is. Skipped when it is already selected, because
            // re-selecting rebuilds the whole detail pane for nothing.
            if (selectedAssetId != assetId) selectAsset(assetId);
            ctxAssetId = assetId;

            const asset = assets.find(a => a.id == assetId);
            const menu  = document.getElementById('assetContextMenu');
            document.getElementById('assetCtxHeader').textContent = asset ? assetDisplayName(asset) : '';

            buildCtxSubmenu('assetCtxStatus',   assetStatusTypes, 'asset_status_id', asset && asset.asset_status_id);
            buildCtxSubmenu('assetCtxType',     assetTypes,       'asset_type_id',   asset && asset.asset_type_id);
            buildCtxSubmenu('assetCtxLocation', assetLocations,   'location_id',     asset && asset.location_id);

            // Copying is only offered when there is something to copy — an item
            // that silently does nothing is worse than one that is not there.
            toggleCtxItem('copy-tag',    !!(asset && asset.asset_tag));
            toggleCtxItem('copy-serial', !!(asset && asset.service_tag));

            menu.classList.add('active');

            // Position, then correct if it would hang off the edge. Measured
            // after showing, because a hidden element has no height.
            const w = menu.offsetWidth, h = menu.offsetHeight;
            const x = Math.min(event.clientX, window.innerWidth  - w - 8);
            const y = Math.min(event.clientY, window.innerHeight - h - 8);
            menu.style.left = Math.max(8, x) + 'px';
            menu.style.top  = Math.max(8, y) + 'px';

            // Flyouts open leftwards when there is no room to the right.
            menu.classList.toggle('flip-sub', (x + w + 200) > window.innerWidth);
            return false;
        }

        /** A readable name for an asset, matching what the list shows. */
        function assetDisplayName(a) {
            return a.hostname || a.asset_tag || a.service_tag
                || [a.manufacturer, a.model].filter(Boolean).join(' ')
                || ('#' + a.id);
        }

        function toggleCtxItem(action, show) {
            const el = document.querySelector(`#assetContextMenu [data-action="${action}"]`);
            if (el) el.style.display = show ? '' : 'none';
        }

        /**
         * Fill one flyout from a lookup list, ticking the current value.
         *
         * The tick matters: without it the menu tells you what you COULD set and
         * never what it currently is, so you cannot tell a no-op from a change.
         */
        function buildCtxSubmenu(elementId, items, field, currentId) {
            const el = document.getElementById(elementId);
            if (!el) return;
            if (!items || !items.length) {
                el.innerHTML = `<div class="ticket-context-menu-item asset-ctx-empty">${escapeHtml(window.t('asset-management.ctx.none_configured'))}</div>`;
                return;
            }
            el.innerHTML = items.map(i => `
                <button class="ticket-context-menu-item" type="button"
                        onclick="ctxSetField('${field}', ${i.id})">
                    <span class="asset-ctx-tick">${String(i.id) === String(currentId) ? '&check;' : ''}</span>
                    <span>${escapeHtml(i.name)}</span>
                </button>`).join('');
        }

        async function ctxSetField(field, value) {
            closeAssetContextMenu();
            if (!ctxAssetId) return;
            // updateAssetField acts on selectedAssetId, and the menu has already
            // made this asset the selected one.
            await updateAssetField(field, value);
            loadAssets(document.getElementById('assetSearch').value || '');
        }

        function closeAssetContextMenu() {
            const menu = document.getElementById('assetContextMenu');
            if (menu) menu.classList.remove('active');
        }

        document.addEventListener('click', closeAssetContextMenu);
        document.addEventListener('scroll', closeAssetContextMenu, true);
        window.addEventListener('resize', closeAssetContextMenu);
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAssetContextMenu(); });

        document.getElementById('assetContextMenu').addEventListener('click', function (e) {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const asset = assets.find(a => a.id == ctxAssetId);
            closeAssetContextMenu();
            switch (btn.dataset.action) {
                case 'open':        selectAsset(ctxAssetId); break;
                case 'contract':    openContractPicker(); break;
                case 'assign':      openAssignModal(); break;
                case 'label':       printAssetLabel(ctxAssetId); break;
                // ⚠️ copyToClipboard, never navigator.clipboard — the native API
                // is undefined outside a secure context and throws synchronously,
                // so a .catch() fallback never runs.
                case 'copy-tag':    if (asset) copyToClipboard(asset.asset_tag); break;
                case 'copy-serial': if (asset) copyToClipboard(asset.service_tag); break;
            }
        });

        // ── Linking a contract from the equipment's side (#106) ──────────────
        //
        // The same links as the contract page manages, reached from the other
        // end: somebody standing on a new handset should not have to go and find
        // the agreement first. Both ends call the SAME endpoints, so there is one
        // set of rules about who may link what.
        let contractPickerTimer = null;

        function openContractPicker() {
            if (!selectedAssetId) return;
            document.getElementById('contractPickerModal').classList.add('active');
            const box = document.getElementById('contractPickerSearch');
            box.value = '';
            box.focus();
            searchLinkableContracts();
        }

        function closeContractPicker() {
            document.getElementById('contractPickerModal').classList.remove('active');
        }

        function contractPickerInput() {
            clearTimeout(contractPickerTimer);
            contractPickerTimer = setTimeout(searchLinkableContracts, 200);
        }

        async function searchLinkableContracts() {
            const results = document.getElementById('contractPickerResults');
            const q = document.getElementById('contractPickerSearch').value.trim();
            try {
                const resp = await fetch(`${API_BASE}search_linkable_contracts.php?asset_id=${selectedAssetId}&q=${encodeURIComponent(q)}`);
                const data = await resp.json();
                if (!data.success) {
                    results.innerHTML = `<div class="asset-tickets-empty">${escapeHtml(data.error || window.t('asset-management.detail.contracts_load_failed'))}</div>`;
                    return;
                }
                if (!data.contracts.length) {
                    results.innerHTML = `<div class="asset-tickets-empty">${escapeHtml(window.t('asset-management.detail.no_contracts_found'))}</div>`;
                    return;
                }
                results.innerHTML = data.contracts.map(c => {
                    const supplier = c.supplier_trading_name || c.supplier_name || '';
                    const ends = c.contract_end
                        ? `${window.t('asset-management.detail.contract_ends')} ${c.contract_end}`
                        : window.t('asset-management.detail.contract_no_end');
                    return `
                    <button type="button" class="contract-pick" onclick="linkAssetContract(${c.contract_id})">
                        <span class="contract-pick-name">${escapeHtml(c.contract_number || '')} ${escapeHtml(c.title || '')}</span>
                        <span class="contract-pick-meta">${escapeHtml(supplier)}${supplier ? ' &bull; ' : ''}${escapeHtml(ends)}</span>
                    </button>`;
                }).join('');
            } catch (e) {
                results.innerHTML = `<div class="asset-tickets-empty">${escapeHtml(window.t('asset-management.detail.contracts_load_failed'))}</div>`;
            }
        }

        async function linkAssetContract(contractId) {
            try {
                const resp = await fetch('../api/contracts/save_contract_asset.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ contract_id: contractId, asset_id: selectedAssetId })
                });
                const data = await resp.json();
                if (!data.success) {
                    showToast(data.error || window.t('asset-management.detail.contracts_load_failed'), 'error');
                    return;
                }
                closeContractPicker();
                loadAssetContracts(selectedAssetId);
            } catch (e) {
                showToast(window.t('asset-management.detail.contracts_load_failed'), 'error');
            }
        }

        async function unlinkAssetContract(linkId) {
            // Same wording as the contract side, for the same reason: "Remove"
            // beside a contract reads as "cancel the contract" to enough people
            // to be worth a sentence.
            const okToGo = typeof window.showConfirm === 'function'
                ? await window.showConfirm({
                      title: window.t('asset-management.detail.remove_contract'),
                      message: window.t('asset-management.detail.remove_contract_message'),
                      okLabel: window.t('asset-management.detail.remove_contract'), okClass: 'danger'
                  })
                : confirm(window.t('asset-management.detail.remove_contract_message'));
            if (!okToGo) return;

            try {
                const resp = await fetch('../api/contracts/delete_contract_asset.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ link_id: linkId })
                });
                const data = await resp.json();
                if (!data.success) {
                    showToast(data.error || window.t('asset-management.detail.contracts_load_failed'), 'error');
                    return;
                }
                loadAssetContracts(selectedAssetId);
            } catch (e) {
                showToast(window.t('asset-management.detail.contracts_load_failed'), 'error');
            }
        }

        async function loadAssetTickets(assetId) {
            const list  = document.getElementById('assetTicketsList');
            const badge = document.getElementById('ticketsCountBadge');
            if (!list) return;
            try {
                const response = await fetch(`${API_BASE}get_asset_tickets.php?id=${assetId}`);
                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'failed');

                const open   = data.open || [];
                const closed = data.closed || [];
                if (badge) badge.textContent = open.length + closed.length;

                if (open.length === 0 && closed.length === 0) {
                    list.innerHTML = `<div class="asset-tickets-empty">${escapeHtml(window.t('asset-management.tickets.empty'))}</div>`;
                    return;
                }

                const row = tk => {
                    const when = tk.status_is_closed ? tk.closed_datetime : tk.updated_datetime;
                    return `
                    <a class="asset-ticket-row" href="../tickets/index.php?ticket_id=${tk.id}" title="${escapeHtml(window.t('asset-management.tickets.open_title'))}">
                        <span class="asset-ticket-ref">${escapeHtml(tk.ticket_number || '')}</span>
                        <span class="asset-ticket-subject">${escapeHtml(tk.subject || '')}</span>
                        <span class="asset-ticket-status" style="background:${escapeHtml(tk.status_colour || '#6b7280')}">${escapeHtml(tk.status || '')}</span>
                        <span class="asset-ticket-when">${escapeHtml((when || '').substring(0, 10))}</span>
                        ${assetPreviewBadge('ticket', tk.id)}
                    </a>`;
                };

                let html = '';
                if (open.length) {
                    html += `<div class="asset-tickets-group">${escapeHtml(window.t('asset-management.tickets.group_open'))}</div>`;
                    html += open.map(row).join('');
                }
                if (closed.length) {
                    const total = data.total_closed || closed.length;
                    const label = total > closed.length
                        ? window.t('asset-management.tickets.group_closed_capped', { shown: closed.length, total: total })
                        : window.t('asset-management.tickets.group_closed');
                    html += `<div class="asset-tickets-group">${escapeHtml(label)}</div>`;
                    html += closed.map(row).join('');
                }
                list.innerHTML = html;
            } catch (e) {
                if (badge) badge.textContent = '0';
                list.innerHTML = `<div class="asset-tickets-empty">${escapeHtml(window.t('asset-management.tickets.empty'))}</div>`;
            }
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
                        // The holder's name is a link to their record (Ed). The
                        // journey already worked the other way round after #85;
                        // this is the return leg, so "who has this?" and "what
                        // do they have?" are each one click from the other.
                        infoSpan.innerHTML = `
                            <span class="user-name"><a class="user-name-link" href="users.php?user_id=${user.user_id}">${escapeHtml(user.display_name || window.t('asset-management.common.unknown'))}</a></span>
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

        /**
         * Save one of the four fields that used to be read-only (#1143):
         * name, service tag, manufacturer, model.
         *
         * 🔑 WARNS, NEVER BLOCKS. Plenty of assets never report in, and a
         * blanket refusal would put us back where we started — unable to fix a
         * typo on a television.
         *
         * ⚠️ Renaming is the one that can actually hurt: the inventory agent
         * upserts on the NAME, so renaming a machine that reports in means the
         * next report does not recognise it and creates a SECOND asset. The
         * confirm says exactly that, and only appears when the asset looks like
         * it reports — judged by data only an automated source ever fills in.
         */
        function assetLooksReported(a) {
            return !!(a && (a.cpu_name || a.bios_version || a.operating_system));
        }

        async function saveCoreField(field, el) {
            const value = el.value.trim();

            if (field === 'hostname') {
                if (value === '') {
                    showToast(window.t('asset-management.detail.name_required'), 'error');
                    el.value = selectedAsset.hostname || '';
                    return;
                }
                // 🔑 TWO ways an asset can be recognised by its name, and both
                // break the same way when it is renamed here.
                //
                // The agent upserts on the name, so a renamed machine is not
                // recognised by its next report. An IMPORT matches on whatever
                // its match keys are, so a renamed asset is not found by the
                // next import of the same file. Either way you get a second,
                // duplicate record.
                //
                // The import case had no warning at all until #1144, because an
                // imported television has none of the hardware data that flags
                // an agent asset — the more dangerous half was the silent one.
                if (value !== (selectedAsset.hostname || '')) {
                    let warning = null;
                    if (assetLooksReported(selectedAsset)) {
                        warning = window.t('asset-management.detail.rename_reported_confirm');
                    } else if (selectedAsset.from_import) {
                        warning = window.t('asset-management.detail.rename_imported_confirm');
                    }
                    // ⚠️ The SHARED confirm, not the browser's. showConfirm takes
                    // an options OBJECT and returns a promise; its body is a <p>
                    // with white-space: pre-wrap, so the blank lines in these
                    // messages survive.
                    if (warning && !(await showConfirm({
                        title:    window.t('asset-management.detail.rename_title'),
                        message:  warning,
                        okLabel:  window.t('asset-management.detail.rename_ok'),
                        okClass:  'danger'
                    }))) {
                        el.value = selectedAsset.hostname || '';
                        return;
                    }
                }
            }

            try {
                const res = await fetch(`${API_BASE}update_asset_field.php`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ asset_id: selectedAsset.id, field, value })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);

                selectedAsset[field] = value;
                if (field === 'hostname') {
                    // Keep the heading in step, and the list — the row's label
                    // just changed, and leaving the old one on screen would look
                    // like the save had failed.
                    const h = document.querySelector('.asset-detail-hostname');
                    if (h) h.textContent = value;
                    await loadAssets(document.getElementById('assetSearch').value || '');
                }
                showToast(window.t('asset-management.detail.saved'), 'success');
            } catch (e) {
                showToast(e.message || window.t('asset-management.detail.save_failed'), 'error');
                // Put the stored value back, so the screen never shows something
                // the database does not hold.
                el.value = selectedAsset[field] || '';
            }
        }

        // ════════════════════════════════════════════════════════════════
        //  Adding an asset by hand (#1132)
        //
        //  The inventory agent, Intune and vCenter cover everything that
        //  reports for itself. This covers everything that does not: printers,
        //  monitors, headsets, televisions.
        // ════════════════════════════════════════════════════════════════

        function openNewAssetModal() {
            const typeSel = document.getElementById('naType');
            const statSel = document.getElementById('naStatus');
            const locSel  = document.getElementById('naLocation');

            const none = `<option value="">${window.t('asset-management.common.none_option')}</option>`;
            typeSel.innerHTML = none + assetTypes.map(t =>
                `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');
            statSel.innerHTML = none + assetStatusTypes.map(s =>
                `<option value="${s.id}">${escapeHtml(s.name)}</option>`).join('');
            locSel.innerHTML  = buildLocationOptions(null);

            document.getElementById('newAssetForm').reset();
            typeSel.value = ''; statSel.value = ''; locSel.value = '';
            naSyncNext();
            typeSel.onchange = naSyncNext;

            document.getElementById('newAssetModal').classList.add('active');
            document.getElementById('naName').focus();
        }

        function closeNewAssetModal() {
            document.getElementById('newAssetModal').classList.remove('active');
        }

        /**
         * Render the chosen type's OWN fields into the dialog.
         *
         * 🔑 Originally this just said "3 other details are coming". That was
         * wrong, and confusing for a reason worth writing down: the built-in
         * boxes above ask for Manufacturer and Model, so on a Television the
         * dialog asked for the model and then announced a second, different
         * model question for later. Asking both, together and clearly labelled,
         * is the only version that makes sense.
         */
        async function naSyncNext() {
            const box    = document.getElementById('naNext');
            const typeId = parseInt(document.getElementById('naType').value, 10) || 0;
            box.innerHTML = '';
            if (!typeId) { box.style.display = 'none'; return; }
            try {
                const res  = await fetch(`${API_BASE}get_asset_fields.php`);
                const data = await res.json();
                if (!data.success || !data.schema_ready) { box.style.display = 'none'; return; }

                const setIds = (data.type_sets && data.type_sets[typeId]) || [];
                const blocks = [];
                setIds.forEach(sid => {
                    const set = (data.sets || []).find(s => s.id === sid);
                    if (!set || !(set.fields || []).length) return;

                    // ⚠️ get_asset_fields.php returns the SET's membership rows
                    // (field_key/field_type/is_required), not the per-asset shape
                    // cfFieldRow expects. Map it rather than teaching the
                    // renderer a second input shape.
                    const rows = set.fields
                        .filter(m => m.field_type !== 'ref')   // see the 'ref' case
                        .map(m => {
                            const cat = (data.fields || []).find(x => x.id === m.field_id) || {};
                            return cfFieldRow({
                                key:       m.field_key,
                                label:     m.label,
                                type:      m.field_type,
                                config:    cat.config || {},
                                required:  m.is_required,
                                help_text: cat.help_text || null,
                                value:     null,
                                options:   cat.options || []
                            }, 'create');
                        }).join('');
                    if (!rows) return;
                    // No grid wrapper: cfFieldRow emits .form-group rows in
                    // create mode, so they stack exactly like Manufacturer and
                    // Model above. A two-column grid here made the custom fields
                    // half-width and visibly a different kind of thing.
                    blocks.push(`
                        <div class="na-group-title">${escapeHtml(set.name)}</div>
                        ${rows}`);
                });

                if (!blocks.length) { box.style.display = 'none'; return; }
                box.innerHTML = blocks.join('');
                box.style.display = '';
            } catch (e) {
                // Never block a create over this. The fields can be filled in on
                // the asset afterwards, which is where they live anyway.
                box.style.display = 'none';
            }
        }

        document.getElementById('newAssetForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = document.getElementById('naSaveBtn');
            btn.disabled = true;   // a duplicate hostname is refused, but a
                                   // double-click should not even ask twice
            try {
                const res = await fetch(`${API_BASE}create_asset.php`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        hostname:        document.getElementById('naName').value.trim(),
                        asset_type_id:   document.getElementById('naType').value,
                        asset_status_id: document.getElementById('naStatus').value,
                        location_id:     document.getElementById('naLocation').value,
                        manufacturer:    document.getElementById('naManufacturer').value.trim(),
                        model:           document.getElementById('naModel').value.trim(),
                        service_tag:     document.getElementById('naSerial').value.trim()
                    })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);

                // The type's own fields, in a second call: the asset has to
                // exist before anything can be recorded against it.
                //
                // ⚠️ If THIS half fails the asset still exists, so it says so
                // plainly and still opens the record — silently swallowing it
                // would leave somebody looking at blank fields they had filled
                // in, with no idea why.
                const values = {};
                document.querySelectorAll('#naNext [data-na-key]').forEach(el => {
                    values[el.getAttribute('data-na-key')] = el.value;
                });
                let fieldWarning = null;
                if (Object.keys(values).length) {
                    try {
                        const r2 = await fetch(`${API_BASE}save_asset_custom_fields.php`, {
                            method: 'POST', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ asset_id: data.id, values })
                        });
                        const d2 = await r2.json();
                        if (!d2.success) fieldWarning = d2.error;
                    } catch (e2) {
                        fieldWarning = e2.message;
                    }
                }

                closeNewAssetModal();
                showToast(fieldWarning || window.t('asset-management.new.created'),
                          fieldWarning ? 'error' : 'success');
                await loadAssets();
                selectAsset(data.id);
            } catch (err) {
                showToast(err.message || window.t('asset-management.new.failed'), 'error');
            } finally {
                btn.disabled = false;
            }
        });

        // ════════════════════════════════════════════════════════════════
        //  Custom fields on the asset
        //  docs/design/flexible-asset-fields.md §5.2
        // ════════════════════════════════════════════════════════════════

        let cfState = { assetId: 0, sets: [], available: [], showBlanks: false };

        function cfd(key, vars) { return window.t('asset-management.detail.' + key, vars); }

        async function loadCustomFields(assetId) {
            const section = document.getElementById('customFieldsSection');
            if (!section) return;
            try {
                const res  = await fetch(`${API_BASE}get_asset_custom_fields.php?asset_id=${assetId}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.error);

                cfState = {
                    assetId:    assetId,
                    sets:       data.sets || [],
                    available:  data.available_sets || [],
                    showBlanks: false,
                    filled:     data.filled || 0,
                    total:      data.total || 0
                };

                // Hidden entirely when this kind of asset records nothing extra
                // AND there is nothing to add — an empty panel on 560 laptops is
                // noise. It appears the moment there is either.
                const nothing = !cfState.sets.length && !cfState.available.length;
                section.style.display = nothing ? 'none' : '';
                if (nothing) return;

                renderCustomFields();
            } catch (e) {
                section.style.display = '';
                document.getElementById('assetCustomFields').innerHTML =
                    `<div class="cf-empty">${escapeHtml(e.message || '')}</div>`;
            }
        }

        function renderCustomFields() {
            // "6 of 8 filled in" is always shown, zeroes included: a field
            // nobody has filled in and a panel that failed to load must never
            // look the same.
            const count = document.getElementById('cfCount');
            if (count) {
                count.textContent = cfState.total
                    ? cfd('cf_filled', { filled: cfState.filled, total: cfState.total })
                    : '';
            }

            const blanks = cfState.total - cfState.filled;
            const parts  = [];

            cfState.sets.forEach(set => {
                const rows = set.fields
                    .filter(f => cfState.showBlanks || f.value !== null)
                    .map(f => cfFieldRow(f, 'edit')).join('');

                // A set attached to THIS asset alone gets a removable chip, so
                // "why does this TV have a field its type doesn't?" answers
                // itself on the page.
                const chip = set.via === 'asset'
                    ? `<span class="cf-chip" title="${escapeHtml(cfd('cf_via_asset'))}">${escapeHtml(set.name)}
                         <button type="button" class="cf-chip-x" onclick="cfRemoveSet(${set.id})"
                                 aria-label="${escapeHtml(cfd('cf_remove_set'))}">&times;</button>
                       </span>`
                    : `<span class="cf-setname">${escapeHtml(set.name)}</span>`;

                parts.push(`
                    <div class="cf-set">
                        <div class="cf-set-head">${chip}</div>
                        <div class="asset-info-grid">${rows}</div>
                    </div>`);
            });

            if (blanks > 0) {
                parts.push(`
                    <button type="button" class="cf-toggle" onclick="cfToggleBlanks()">
                        ${cfState.showBlanks ? cfd('cf_hide_blanks') : cfd('cf_show_blanks', { n: blanks })}
                    </button>`);
            }

            if (cfState.available.length) {
                parts.push(`
                    <div class="cf-addset">
                        <select id="cfAddSetSelect">
                            ${cfState.available.map(s => `<option value="${s.id}">${escapeHtml(s.name)}</option>`).join('')}
                        </select>
                        <button type="button" class="btn btn-outline btn-sm" onclick="cfAddSet()">${cfd('cf_add_set')}</button>
                        <div class="cf-addset-hint">${cfd('cf_add_set_hint')}</div>
                    </div>`);
            }

            if (!cfState.sets.length) {
                parts.unshift(`<div class="cf-empty">${cfd('cf_none')}<br><span class="cf-hint">${cfd('cf_none_hint')}</span></div>`);
            }

            document.getElementById('assetCustomFields').innerHTML = parts.join('');
        }

        /** One editable row. The control matches the field's declared type. */
        /**
         * One editable row. The control matches the field's declared type.
         *
         * @param mode 'edit'   — on an existing asset; each control saves itself
         *             'create' — inside the Add dialog; controls are tagged with
         *                        data-na-key and read once on submit, because
         *                        there is no asset to save to yet.
         *
         * 🔑 ONE renderer for both. A second copy would drift, and the
         * three-state boolean / unit / date-mode rules are exactly the things
         * that must not be got subtly differently in two places.
         */
        function cfFieldRow(f, mode) {
            const create = (mode === 'create');

            // 🔑 The CLASSES change with the mode, not just the save handler.
            // On the asset page these are detail-grid rows; in the dialog they
            // must be indistinguishable from the built-in form fields sitting
            // right above them, or a custom field reads as something bolted on.
            // Sharing the renderer is about the TYPE rules — three-state
            // booleans, unit suffixes, date modes — not about the chrome.
            const c = create
                ? { wrap: 'form-group', label: 'form-label', input: 'search-box',  hint: 'form-hint' }
                : { wrap: 'info-item',  label: 'info-label', input: 'info-value-input', hint: 'cf-hint' };
            const sel = create ? c.input : 'info-value-select';

            const label = `<label class="${c.label}">${escapeHtml(f.label)}${f.required ? '<span class="cf-req">*</span>' : ''}</label>`;
            const hint  = f.help_text ? `<span class="${c.hint}">${escapeHtml(f.help_text)}</span>` : '';
            // In the dialog a required field is marked `required`, so the browser
            // blocks submit. Without it the asset would be created and only THEN
            // the values rejected — leaving a half-made record behind.
            const save  = create
                ? `data-na-key="${escapeHtml(f.key)}"${f.required ? ' required' : ''}`
                : `onchange="cfSave('${f.key}', this)"`;
            let control;

            switch (f.type) {
                case 'boolean':
                    // ⚠️ THREE states, not two. "Not set" is a real option and
                    // must stay reachable — absent is not No.
                    control = `
                        <select class="${sel}" ${save}>
                            <option value=""  ${f.value === null  ? 'selected' : ''}>${cfd('cf_not_set')}</option>
                            <option value="1" ${f.value === true  ? 'selected' : ''}>${cfd('cf_yes')}</option>
                            <option value="0" ${f.value === false ? 'selected' : ''}>${cfd('cf_no')}</option>
                        </select>`;
                    break;
                case 'dropdown':
                    control = `
                        <select class="${sel}" ${save}>
                            <option value="">${cfd('cf_not_set')}</option>
                            ${(f.options || []).map(o =>
                                `<option value="${escapeHtml(o.option_value)}" ${f.value === o.option_value ? 'selected' : ''}>${escapeHtml(o.option_value)}</option>`
                            ).join('')}
                        </select>`;
                    break;
                case 'number': {
                    const step = f.config && f.config.decimals ? (1 / Math.pow(10, f.config.decimals)) : 1;
                    const input = `<input type="number" step="${step}" class="${c.input}" value="${f.value !== null ? f.value : ''}" ${save}>`;
                    // .info-item is a COLUMN, so a bare sibling span drops onto
                    // its own line under the box. The unit belongs beside the
                    // number it qualifies, hence the row wrapper.
                    control = (f.config && f.config.unit)
                        ? `<span class="cf-numrow">${input}<span class="cf-unit">${escapeHtml(f.config.unit)}</span></span>`
                        : input;
                    break;
                }
                case 'date': {
                    const mode = (f.config && f.config.date_mode) || 'date';
                    const input = mode === 'time' ? 'time' : (mode === 'datetime' ? 'datetime-local' : 'date');
                    control = `<input type="${input}" class="${c.input}" value="${cfDateValue(f.value, mode)}" ${save}>`;
                    break;
                }
                case 'ref':
                    // Read-only for now: picking a person or another asset needs
                    // a searchable picker, which is its own piece of work. The
                    // value still displays, so an imported link is visible.
                    // ⚠️ In the dialog it is skipped entirely (see the caller):
                    // an unfillable REQUIRED control would block Save with no
                    // way to satisfy it.
                    control = `<span class="info-value">${f.value_label ? escapeHtml(f.value_label) : (f.value !== null ? '#' + f.value : '-')}</span>`;
                    break;
                case 'url':
                    control = `<input type="url" class="${c.input}" value="${escapeHtml(f.value || '')}" ${save}>`;
                    break;
                case 'email':
                    control = `<input type="email" class="${c.input}" value="${escapeHtml(f.value || '')}" ${save}>`;
                    break;
                default:
                    control = (f.config && f.config.multiline)
                        ? `<textarea class="${c.input}" rows="2" ${save}>${escapeHtml(f.value || '')}</textarea>`
                        : `<input type="text" class="${c.input}" value="${escapeHtml(f.value || '')}" ${save}>`;
            }

            return `<div class="${c.wrap}">${label}${control}${hint}</div>`;
        }

        /** Stored 'Y-m-d H:i:s' -> what the matching input element expects. */
        function cfDateValue(v, mode) {
            if (!v) return '';
            const s = String(v).replace(' ', 'T');
            if (mode === 'time')     return s.substring(11, 16);
            if (mode === 'datetime') return s.substring(0, 16);
            return s.substring(0, 10);
        }

        async function cfSave(key, el) {
            const raw = el.value;
            // An empty control CLEARS the field. Sent as '' rather than omitted,
            // because omitting it would mean "leave alone" — a different thing.
            try {
                const res = await fetch(`${API_BASE}save_asset_custom_fields.php`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ asset_id: cfState.assetId, values: { [key]: raw } })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                showToast(cfd('cf_saved'), 'success');
                await loadCustomFields(cfState.assetId);
            } catch (e) {
                showToast(e.message || cfd('cf_save_failed'), 'error');
                // Put the stored value back, so the screen never shows a value
                // the database does not hold.
                await loadCustomFields(cfState.assetId);
            }
        }

        function cfToggleBlanks() {
            cfState.showBlanks = !cfState.showBlanks;
            renderCustomFields();
        }

        async function cfAddSet() {
            const sel = document.getElementById('cfAddSetSelect');
            if (!sel || !sel.value) return;
            await cfSetMembership(parseInt(sel.value, 10), 'attach');
        }

        async function cfRemoveSet(setId) {
            const set = cfState.sets.find(s => s.id === setId);
            if (set && !(await showConfirm({
                title:   cfd('cf_remove_set'),
                message: cfd('cf_remove_confirm', { name: set.name }),
                okLabel: cfd('cf_remove_set'),
                okClass: 'danger'
            }))) return;
            await cfSetMembership(setId, 'detach');
        }

        async function cfSetMembership(setId, action) {
            try {
                const res = await fetch(`${API_BASE}set_asset_field_set.php`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ asset_id: cfState.assetId, set_id: setId, action })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                showToast(cfd('cf_saved'), 'success');
                await loadCustomFields(cfState.assetId);
            } catch (e) {
                showToast(e.message || cfd('cf_save_failed'), 'error');
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

        /**
         * The ⓘ preview badge (#91). Guarded, so a page that somehow loaded
         * without record-preview.js loses the preview rather than the panel it
         * would have been drawn into.
         */
        function assetPreviewBadge(type, id) {
            return window.FreeITSMPreview ? window.FreeITSMPreview.badge(type, id) : '';
        }

        // Escape HTML for safe display
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Format a stored UTC datetime as a date, in the analyst's display zone.
        // Used for true audit/assignment timestamps (e.g. assigned_datetime,
        // Intune enrolled_datetime) — NOT for date-only fields.
        function formatDate(dateString) {
            if (!dateString) return '-';
            const date = parseUTCDate(dateString);
            return fmtDate(date);
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
            const date = parseUTCDate(dateString);
            return fmtDateTime(date);
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
    <?php /* Loaded LAST so it can wrap this page's own selectAsset() from the
             outside rather than editing it — the wrap-don't-edit rule. Every
             behaviour inside is gated on matchMedia(768px), so on desktop it is
             inert. (#936) */ ?>
    <script src="../assets/js/network-mapper-icons.js?v=2"></script>
    <script src="../assets/js/mobile.js?v=55"></script>
</body>
</html>
