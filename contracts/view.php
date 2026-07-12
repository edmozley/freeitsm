<?php
/**
 * Contracts Module - View Contract
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('contracts');

$current_page = 'dashboard';
$path_prefix = '../';
$translationNamespaces = ['common', 'contracts'];
$contract_id = $_GET['id'] ?? null;

if (!$contract_id) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('contracts.detail.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        body { --accent: var(--con-accent, #f59e0b); }
        /* Full-screen layout with sidebar - matches contracts dashboard */
        .contracts-layout {
            display: flex;
            height: calc(100vh - 48px);
            background: var(--app-bg, #f5f5f5);
        }
        .contracts-sidebar {
            width: 260px;
            background: var(--surface, #fff);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            overflow-y: auto;
            flex-shrink: 0;
        }
        .contracts-main {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }

        .sidebar-section { margin-bottom: 24px; }
        .sidebar-section h3 {
            font-size: 14px; font-weight: 600; color: var(--text, #333);
            margin: 0 0 12px 0;
        }
        .sidebar-stat {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 12px; border-radius: 6px;
            font-size: 14px; color: var(--text, #333); cursor: default; margin-bottom: 4px;
        }
        .sidebar-stat .stat-value { font-weight: 700; font-size: 16px; }
        .sidebar-stat.warning .stat-value { color: var(--con-accent, #f59e0b); }
        .sidebar-links { display: flex; flex-direction: column; gap: 4px; }
        .sidebar-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 6px;
            font-size: 14px; color: var(--text, #333);
            text-decoration: none; transition: all 0.15s;
        }
        .sidebar-link:hover { background: #fff7ed; color: var(--con-accent, #f59e0b); }
        .sidebar-link svg { width: 18px; height: 18px; flex-shrink: 0; }
        .sidebar-add-btn {
            display: block; width: 100%;
            padding: 10px 16px;
            background: var(--con-accent, #f59e0b); color: #fff;
            border: none; border-radius: 6px;
            font-size: 14px; font-weight: 500;
            cursor: pointer; transition: background 0.2s;
            text-align: center; text-decoration: none;
            box-sizing: border-box;
        }
        .sidebar-add-btn:hover { background: var(--con-accent-hover, #d97706); }

        .contract-card {
            background: var(--surface, #fff);
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            overflow: hidden;
        }

        .contract-card-header {
            padding: 24px 30px;
            border-bottom: 1px solid var(--border-soft, #eee);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .contract-card-header h2 { margin: 0; font-size: 20px; color: var(--text, #333); }

        .contract-card-header .actions { display: flex; gap: 8px; }

        .contract-card-header .btn {
            padding: 8px 16px; border-radius: 4px; font-size: 13px; font-weight: 500;
            text-decoration: none; cursor: pointer; transition: all 0.2s;
        }

        .btn-edit-contract { background: var(--con-accent, #f59e0b); color: #fff; border: none; }
        .btn-edit-contract:hover { background: var(--con-accent-hover, #d97706); }
        .btn-back { background: var(--surface-3, #e0e0e0); color: var(--text, #333); border: none; }
        .btn-back:hover { background: var(--surface-hover, #d0d0d0); }

        .contract-details {
            padding: 30px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .detail-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-dim, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .detail-group .value {
            font-size: 15px;
            color: var(--text, #333);
        }

        .detail-group.full-width { grid-column: span 2; }

        .section-divider {
            grid-column: span 2;
            border-top: 1px solid var(--border-soft, #eee);
            padding-top: 16px;
            margin-top: 4px;
        }

        .section-divider h3 {
            margin: 0;
            font-size: 13px;
            font-weight: 600;
            color: var(--con-accent, #f59e0b);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge {
            display: inline-block; padding: 4px 8px; border-radius: 3px;
            font-size: 13px; font-weight: 500;
        }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.expired { background: #f8d7da; color: #721c24; }
        .status-badge.expiring { background: #fff3cd; color: #856404; }

        .bool-yes { color: #155724; font-weight: 500; }
        .bool-no { color: var(--text-dim, #999); }

        .dms-link a { color: var(--con-accent, #f59e0b); text-decoration: none; word-break: break-all; }
        .dms-link a:hover { text-decoration: underline; }

        .loading { text-align: center; padding: 60px; color: var(--text-dim, #999); }

        .terms-view-tabs { display: flex; gap: 0; border-bottom: 2px solid var(--border, #e0e0e0); margin-top: 8px; }
        .terms-view-tab {
            padding: 10px 20px; font-size: 13px; font-weight: 500; color: var(--text-muted, #666); cursor: pointer;
            background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.15s;
        }
        .terms-view-tab:hover { color: var(--text, #333); background: var(--surface-hover, #f5f5f5); }
        .terms-view-tab.active { color: var(--con-accent, #f59e0b); border-bottom-color: var(--con-accent, #f59e0b); font-weight: 600; }
        .terms-view-panel { display: none; padding: 20px 0; }
        .terms-view-panel.active { display: block; }
        .terms-view-panel .rich-content { font-size: 14px; line-height: 1.6; color: var(--text, #333); }
        .terms-view-panel .rich-content table { border-collapse: collapse; width: 100%; }
        .terms-view-panel .rich-content td, .terms-view-panel .rich-content th { border: 1px solid var(--border, #ddd); padding: 8px; }

        .btn-create-task { background: #6366f1; color: white; border: none; }
        .btn-create-task:hover { background: #4f46e5; }
        .btn-create-event { background: #0ea5e9; color: white; border: none; }
        .btn-create-event:hover { background: #0284c7; }

        .related-list { padding: 0 30px 20px 30px; }
        .related-section { margin-bottom: 24px; }
        .related-section h3 {
            margin: 0 0 10px 0; font-size: 13px; font-weight: 600;
            color: var(--con-accent, #f59e0b); text-transform: uppercase; letter-spacing: 0.5px;
            padding-top: 16px; border-top: 1px solid var(--border-soft, #eee);
        }
        .related-empty { color: var(--text-dim, #999); font-size: 13px; padding: 8px 0; }
        .related-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 0; border-bottom: 1px solid var(--border-soft, #f0f0f0);
            font-size: 13px;
        }
        .related-item:last-child { border-bottom: none; }
        .related-item a { color: var(--con-accent, #f59e0b); text-decoration: none; font-weight: 500; }
        .related-item a:hover { text-decoration: underline; }
        .related-item .meta { color: var(--text-muted, #666); }
        .related-item .meta-sep { color: var(--text-faint, #ccc); margin: 0 4px; }
        .related-pill {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 11px; font-weight: 500; background: var(--surface-3, #eee); color: var(--text, #333);
        }
        .related-pill.todo { background: #e5e7eb; color: #374151; }
        .related-pill.in-progress { background: #fde68a; color: #92400e; }
        .related-pill.done { background: #d1fae5; color: #065f46; }
        .related-pill.cancelled { background: #f3f4f6; color: #6b7280; }
        .related-pill.high, .related-pill.urgent { background: #fee2e2; color: #991b1b; }
        .related-cat-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; vertical-align: middle; margin-right: 4px; }

        /* Modal (namespaced to avoid clash with .modal in inbox.css) */
        .cv-modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.45);
            display: none; align-items: center; justify-content: center; z-index: 2500;
        }
        .cv-modal-overlay.active { display: flex; }
        .cv-modal {
            background: var(--surface, #fff); border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            width: 480px; max-width: calc(100vw - 40px); max-height: calc(100vh - 40px); overflow: auto;
        }
        .cv-modal-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border-soft, #eee);
            display: flex; justify-content: space-between; align-items: center;
        }
        .cv-modal-header h3 { margin: 0; font-size: 16px; color: var(--text, #333); }
        .cv-modal-close {
            background: none; border: none; font-size: 22px; line-height: 1;
            color: var(--text-dim, #999); cursor: pointer; padding: 0;
        }
        .cv-modal-close:hover { color: var(--text, #333); }
        .cv-modal-body { padding: 20px; }
        .cv-modal-body .form-group { margin-bottom: 14px; }
        .cv-modal-body label {
            display: block; margin-bottom: 6px; font-weight: 500;
            font-size: 13px; color: var(--text, #333);
        }
        .cv-modal-body input, .cv-modal-body select, .cv-modal-body textarea {
            width: 100%; padding: 8px 10px; border: 1px solid var(--border, #ddd); border-radius: 4px;
            font-size: 13px; box-sizing: border-box; font-family: inherit;
        }
        .cv-modal-body textarea { height: 70px; resize: vertical; }
        .cv-modal-body input:focus, .cv-modal-body select:focus, .cv-modal-body textarea:focus {
            outline: none; border-color: var(--con-accent, #f59e0b); box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.1);
        }
        .cv-modal-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .cv-modal-footer {
            padding: 14px 20px; border-top: 1px solid var(--border-soft, #eee);
            display: flex; justify-content: flex-end; gap: 8px;
        }
        .cv-modal-footer .btn {
            padding: 8px 16px; border-radius: 4px; font-size: 13px; font-weight: 500;
            cursor: pointer; border: none; transition: all 0.2s;
        }
        .cv-modal-footer .btn-primary { background: var(--con-accent, #f59e0b); color: #fff; }
        .cv-modal-footer .btn-primary:hover { background: var(--con-accent-hover, #d97706); }
        .cv-modal-footer .btn-primary:disabled { background: #fcd34d; cursor: not-allowed; }
        .cv-modal-footer .btn-secondary { background: var(--surface-3, #e0e0e0); color: var(--text, #333); }
        .cv-modal-footer .btn-secondary:hover { background: var(--surface-hover, #d0d0d0); }
        [data-theme-mode="dark"] .sidebar-link:hover { background: #3a2e12; }
        .checkbox-row { display: flex; align-items: center; gap: 8px; }
        .checkbox-row input { width: auto; }
        .checkbox-row label { margin: 0; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="contracts-layout">
        <!-- Left Sidebar -->
        <div class="contracts-sidebar">
            <div class="sidebar-section">
                <h3><?php echo htmlspecialchars(t('contracts.list.overview')); ?></h3>
                <div class="sidebar-stat">
                    <span><?php echo htmlspecialchars(t('contracts.nav.contracts')); ?></span>
                    <span class="stat-value" id="sideContracts">-</span>
                </div>
                <div class="sidebar-stat">
                    <span><?php echo htmlspecialchars(t('contracts.status.active')); ?></span>
                    <span class="stat-value" id="sideActive">-</span>
                </div>
                <div class="sidebar-stat warning">
                    <span><?php echo htmlspecialchars(t('contracts.list.expiring_90d')); ?></span>
                    <span class="stat-value" id="sideExpiring">-</span>
                </div>
                <div class="sidebar-stat">
                    <span><?php echo htmlspecialchars(t('contracts.nav.suppliers')); ?></span>
                    <span class="stat-value" id="sideSuppliers">-</span>
                </div>
                <div class="sidebar-stat">
                    <span><?php echo htmlspecialchars(t('contracts.nav.contacts')); ?></span>
                    <span class="stat-value" id="sideContacts">-</span>
                </div>
            </div>

            <div class="sidebar-section">
                <h3><?php echo htmlspecialchars(t('contracts.list.quick_links')); ?></h3>
                <div class="sidebar-links">
                    <a href="index.php" class="sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                        <?php echo htmlspecialchars(t('contracts.nav.contracts')); ?>
                    </a>
                    <a href="suppliers/" class="sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                        <?php echo htmlspecialchars(t('contracts.nav.suppliers')); ?>
                    </a>
                    <a href="contacts/" class="sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <?php echo htmlspecialchars(t('contracts.nav.contacts')); ?>
                    </a>
                    <a href="rfp-builder/" class="sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><path d="M9 13h6"></path><path d="M9 17h6"></path></svg>
                        <?php echo htmlspecialchars(t('contracts.nav.rfp_builder')); ?>
                    </a>
                    <a href="settings/" class="sidebar-link">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                        <?php echo htmlspecialchars(t('contracts.nav.settings')); ?>
                    </a>
                </div>
            </div>

            <div class="sidebar-section">
                <a href="edit.php" class="sidebar-add-btn">+ <?php echo htmlspecialchars(t('contracts.list.new_contract')); ?></a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="contracts-main">
            <div class="contract-card" id="contractCard">
                <div class="loading"><?php echo htmlspecialchars(t('contracts.detail.loading')); ?></div>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/contracts/';
        const TASKS_API = '../api/tasks/';
        const CALENDAR_API = '../api/calendar/';
        const contractId = <?php echo json_encode($contract_id); ?>;
        let currentContract = null;
        let analystOptions = [];
        let teamOptions = [];
        let categoryOptions = [];

        document.addEventListener('DOMContentLoaded', function() {
            loadStats();
            loadContract();
        });

        async function loadStats() {
            try {
                const response = await fetch(API_BASE + 'get_dashboard_stats.php');
                const data = await response.json();
                if (data.success) {
                    document.getElementById('sideContracts').textContent = data.stats.contracts;
                    document.getElementById('sideActive').textContent = data.stats.active_contracts;
                    document.getElementById('sideExpiring').textContent = data.stats.expiring_soon;
                    document.getElementById('sideSuppliers').textContent = data.stats.suppliers;
                    document.getElementById('sideContacts').textContent = data.stats.contacts;
                }
            } catch (error) { console.error('Error loading stats:', error); }
        }

        async function loadContract() {
            try {
                const response = await fetch(API_BASE + 'get_contract.php?id=' + contractId);
                const data = await response.json();
                if (data.success) {
                    currentContract = data.contract;
                    renderContract(data.contract);
                    loadAndRenderContractTerms();
                    loadRelatedItems();
                } else {
                    document.getElementById('contractCard').innerHTML =
                        '<div class="loading" style="color:#d13438;">' + escapeHtml(window.t('contracts.detail.error_prefix')) + ' ' + escapeHtml(data.error) + '</div>';
                }
            } catch (error) {
                document.getElementById('contractCard').innerHTML =
                    '<div class="loading" style="color:#d13438;">' + escapeHtml(window.t('contracts.detail.load_failed')) + '</div>';
            }
        }

        function renderContract(c) {
            const status = getContractStatus(c);
            const contractValue = c.contract_value ? (c.currency || '') + ' ' + parseFloat(c.contract_value).toLocaleString('en-GB', {minimumFractionDigits: 2}) : '-';

            document.getElementById('contractCard').innerHTML = `
                <div class="contract-card-header">
                    <h2>${escapeHtml(c.contract_number)} — ${escapeHtml(c.title)}</h2>
                    <div class="actions">
                        <a href="index.php" class="btn btn-back">${escapeHtml(window.t('contracts.detail.back'))}</a>
                        <button type="button" class="btn btn-create-task" onclick="openTaskModal()">${escapeHtml(window.t('contracts.detail.task'))}</button>
                        <button type="button" class="btn btn-create-event" onclick="openEventModal()">${escapeHtml(window.t('contracts.detail.calendar'))}</button>
                        <a href="edit.php?id=${c.id}" class="btn btn-edit-contract">${escapeHtml(window.t('contracts.actions.edit'))}</a>
                    </div>
                </div>
                <div class="contract-details">
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.contract_number'))}</label>
                        <div class="value">${escapeHtml(c.contract_number)}</div>
                    </div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.status'))}</label>
                        <div class="value"><span class="status-badge ${status.class}">${status.label}</span></div>
                    </div>
                    <div class="detail-group full-width">
                        <label>${escapeHtml(window.t('contracts.detail.title_label'))}</label>
                        <div class="value">${escapeHtml(c.title)}</div>
                    </div>
                    ${c.description ? `<div class="detail-group full-width">
                        <label>${escapeHtml(window.t('contracts.detail.description'))}</label>
                        <div class="value">${escapeHtml(c.description)}</div>
                    </div>` : ''}
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.supplier'))}</label>
                        <div class="value">${escapeHtml(c.supplier_name || '-')}${c.supplier_trading_name ? ' <span style="color:var(--text-dim, #888);">(t/a ' + escapeHtml(c.supplier_trading_name) + ')</span>' : ''}</div>
                    </div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.owner'))}</label>
                        <div class="value">${escapeHtml(c.owner_name || '-')}</div>
                    </div>

                    <div class="section-divider"><h3>${escapeHtml(window.t('contracts.detail.section_dates'))}</h3></div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.start_date'))}</label>
                        <div class="value">${formatDate(c.contract_start)}</div>
                    </div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.end_date'))}</label>
                        <div class="value">${formatDate(c.contract_end)}</div>
                    </div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.notice_period'))}</label>
                        <div class="value">${c.notice_period_days ? window.t('contracts.detail.days', { n: c.notice_period_days }) : '-'}</div>
                    </div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.notice_date'))}</label>
                        <div class="value">${formatDate(c.notice_date)}</div>
                    </div>

                    <div class="section-divider"><h3>${escapeHtml(window.t('contracts.detail.section_financial'))}</h3></div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.contract_value'))}</label>
                        <div class="value">${contractValue}</div>
                    </div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.payment_schedule'))}</label>
                        <div class="value">${escapeHtml(c.payment_schedule_name || '-')}</div>
                    </div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.cost_centre'))}</label>
                        <div class="value">${escapeHtml(c.cost_centre || '-')}</div>
                    </div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.dms_link'))}</label>
                        <div class="value dms-link">${c.dms_link ? '<a href="' + escapeHtml(c.dms_link) + '" target="_blank">' + escapeHtml(c.dms_link) + '</a>' : '-'}</div>
                    </div>

                    <div class="section-divider"><h3>${escapeHtml(window.t('contracts.detail.section_terms'))}</h3></div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.terms'))}</label>
                        <div class="value">${escapeHtml(formatTermsStatus(c.terms_status))}</div>
                    </div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.personal_data_transferred'))}</label>
                        <div class="value">${formatBool(c.personal_data_transferred)}</div>
                    </div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.dpia_required'))}</label>
                        <div class="value">${formatBool(c.dpia_required)}</div>
                    </div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.dpia_completed_date'))}</label>
                        <div class="value">${formatDate(c.dpia_completed_date)}</div>
                    </div>
                    ${c.dpia_dms_link ? `<div class="detail-group full-width">
                        <label>${escapeHtml(window.t('contracts.detail.dpia_dms_link'))}</label>
                        <div class="value dms-link"><a href="${escapeHtml(c.dpia_dms_link)}" target="_blank">${escapeHtml(c.dpia_dms_link)}</a></div>
                    </div>` : ''}

                    <div class="section-divider"><h3>${escapeHtml(window.t('contracts.detail.section_system'))}</h3></div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.created'))}</label>
                        <div class="value">${formatDate(c.created_datetime)}</div>
                    </div>
                    <div class="detail-group">
                        <label>${escapeHtml(window.t('contracts.detail.active'))}</label>
                        <div class="value">${c.is_active ? '<span class="bool-yes">' + escapeHtml(window.t('common.yes')) + '</span>' : '<span class="bool-no">' + escapeHtml(window.t('common.no')) + '</span>'}</div>
                    </div>
                </div>
                <div class="related-list">
                    <div class="related-section" id="relatedTasksSection">
                        <h3>${escapeHtml(window.t('contracts.detail.related_tasks'))}</h3>
                        <div id="relatedTasksList" class="related-empty">${escapeHtml(window.t('common.loading'))}</div>
                    </div>
                    <div class="related-section" id="relatedEventsSection">
                        <h3>${escapeHtml(window.t('contracts.detail.related_events'))}</h3>
                        <div id="relatedEventsList" class="related-empty">${escapeHtml(window.t('common.loading'))}</div>
                    </div>
                </div>
            `;
        }

        function getContractStatus(c) {
            if (!c.is_active) return { class: 'expired', label: window.t('contracts.status.inactive') };
            if (c.contract_end) {
                const end = new Date(c.contract_end);
                const today = new Date(); today.setHours(0,0,0,0);
                const daysLeft = Math.ceil((end - today) / (1000*60*60*24));
                if (daysLeft < 0) return { class: 'expired', label: window.t('contracts.status.expired') };
                if (c.contract_status_name) return { class: 'active', label: c.contract_status_name };
                if (daysLeft <= 90) return { class: 'expiring', label: window.t('contracts.status.expiring') };
                return { class: 'active', label: window.t('contracts.status.active') };
            }
            if (c.contract_status_name) return { class: 'active', label: c.contract_status_name };
            return { class: 'active', label: window.t('contracts.status.active') };
        }

        function formatDate(dateStr) {
            if (!dateStr) return '-';
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }

        function formatBool(val) {
            if (val === null || val === undefined || val === '') return '<span class="bool-no">-</span>';
            return val == 1 ? '<span class="bool-yes">' + escapeHtml(window.t('common.yes')) + '</span>' : '<span class="bool-no">' + escapeHtml(window.t('common.no')) + '</span>';
        }

        function formatTermsStatus(val) {
            if (!val) return '-';
            const labels = {
                received: window.t('contracts.terms_status.received'),
                reviewed: window.t('contracts.terms_status.reviewed'),
                agreed: window.t('contracts.terms_status.agreed')
            };
            return labels[val] || val;
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Contract Terms Detail (read-only)
        async function loadAndRenderContractTerms() {
            try {
                const [tabsResp, valuesResp] = await Promise.all([
                    fetch(API_BASE + 'get_contract_term_tabs.php'),
                    fetch(API_BASE + 'get_contract_terms.php?contract_id=' + contractId)
                ]);
                const tabsData = await tabsResp.json();
                const valuesData = await valuesResp.json();

                if (!tabsData.success || !valuesData.success) return;

                const activeTabs = tabsData.contract_term_tabs.filter(t => t.is_active);
                if (activeTabs.length === 0) return;

                const valueMap = {};
                (valuesData.contract_terms || []).forEach(tv => {
                    valueMap[tv.term_tab_id] = tv.content || '';
                });

                const hasAnyContent = activeTabs.some(tab => valueMap[tab.id] && valueMap[tab.id].trim());
                if (!hasAnyContent) return;

                const tabButtons = activeTabs.map((tab, i) =>
                    `<button class="terms-view-tab ${i === 0 ? 'active' : ''}" data-tab-id="${tab.id}" onclick="switchViewTermTab(${tab.id})">${escapeHtml(tab.name)}</button>`
                ).join('');

                const tabPanels = activeTabs.map((tab, i) =>
                    `<div class="terms-view-panel ${i === 0 ? 'active' : ''}" id="viewTermPanel_${tab.id}"><div class="rich-content">${valueMap[tab.id] || '<span style="color:var(--text-dim, #999);">' + escapeHtml(window.t('contracts.detail.no_content')) + '</span>'}</div></div>`
                ).join('');

                const termsHtml = `
                    <div class="section-divider"><h3>${escapeHtml(window.t('contracts.detail.terms_detail'))}</h3></div>
                    <div class="detail-group full-width">
                        <div class="terms-view-tabs">${tabButtons}</div>
                        ${tabPanels}
                    </div>
                `;

                // Insert before the System section divider
                const dividers = document.querySelectorAll('.section-divider');
                const systemDivider = dividers[dividers.length - 1];
                systemDivider.insertAdjacentHTML('beforebegin', termsHtml);

            } catch (error) {
                console.error('Error loading contract terms:', error);
            }
        }

        function switchViewTermTab(tabId) {
            document.querySelectorAll('.terms-view-tab').forEach(btn => btn.classList.remove('active'));
            document.querySelector('.terms-view-tab[data-tab-id="' + tabId + '"]').classList.add('active');
            document.querySelectorAll('.terms-view-panel').forEach(p => p.classList.remove('active'));
            document.getElementById('viewTermPanel_' + tabId).classList.add('active');
        }

        // Related items
        async function loadRelatedItems() {
            loadRelatedTasks();
            loadRelatedEvents();
        }

        async function loadRelatedTasks() {
            const list = document.getElementById('relatedTasksList');
            try {
                const resp = await fetch(TASKS_API + 'list.php?filter=contract&contract_id=' + contractId);
                const data = await resp.json();
                if (!data.success) {
                    list.innerHTML = '<div class="related-empty">' + escapeHtml(window.t('contracts.detail.tasks_load_failed')) + '</div>';
                    return;
                }
                if (!data.tasks.length) {
                    list.className = '';
                    list.innerHTML = '<div class="related-empty">' + escapeHtml(window.t('contracts.detail.no_related_tasks')) + '</div>';
                    return;
                }
                list.className = '';
                list.innerHTML = data.tasks.map(t => {
                    const statusClass = (t.status || '').toLowerCase().replace(/\s+/g, '-');
                    return `<div class="related-item">
                        <a href="../tasks/index.php?task=${t.id}">${escapeHtml(t.title)}</a>
                        <span class="related-pill ${statusClass}">${escapeHtml(t.status || '')}</span>
                        <span class="meta">
                            ${t.analyst_name ? escapeHtml(t.analyst_name) : (t.team_name ? escapeHtml(t.team_name) : escapeHtml(window.t('contracts.detail.unassigned')))}
                            ${t.due_date ? '<span class="meta-sep">•</span>' + escapeHtml(window.t('contracts.detail.due_prefix')) + ' ' + formatDate(t.due_date) : ''}
                        </span>
                    </div>`;
                }).join('');
            } catch (e) {
                list.innerHTML = '<div class="related-empty">' + escapeHtml(window.t('contracts.detail.tasks_load_failed')) + '</div>';
            }
        }

        async function loadRelatedEvents() {
            const list = document.getElementById('relatedEventsList');
            try {
                const resp = await fetch(CALENDAR_API + 'get_events.php?contract_id=' + contractId);
                const data = await resp.json();
                if (!data.success) {
                    list.innerHTML = '<div class="related-empty">' + escapeHtml(window.t('contracts.detail.events_load_failed')) + '</div>';
                    return;
                }
                if (!data.events.length) {
                    list.className = '';
                    list.innerHTML = '<div class="related-empty">' + escapeHtml(window.t('contracts.detail.no_related_events')) + '</div>';
                    return;
                }
                list.className = '';
                list.innerHTML = data.events.map(e => {
                    const dot = e.category_color ? `<span class="related-cat-dot" style="background:${escapeHtml(e.category_color)}"></span>` : '';
                    return `<div class="related-item">
                        <a href="../calendar/index.php?event=${e.id}">${dot}${escapeHtml(e.title)}</a>
                        <span class="meta">
                            ${formatDateTime(e.start_datetime, e.all_day)}
                            ${e.category_name ? '<span class="meta-sep">•</span>' + escapeHtml(e.category_name) : ''}
                        </span>
                    </div>`;
                }).join('');
            } catch (err) {
                list.innerHTML = '<div class="related-empty">' + escapeHtml(window.t('contracts.detail.events_load_failed')) + '</div>';
            }
        }

        function formatDateTime(dtStr, allDay) {
            if (!dtStr) return '-';
            const d = new Date(dtStr.replace(' ', 'T'));
            if (allDay) return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
            return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        }

        // Modals
        async function openTaskModal() {
            // Lazy-load analyst & team lists
            if (!analystOptions.length) {
                try {
                    const [aResp, tResp] = await Promise.all([
                        fetch(TASKS_API + 'list.php?analysts=1'),
                        fetch(TASKS_API + 'list.php?teams=1')
                    ]);
                    const aData = await aResp.json();
                    const tData = await tResp.json();
                    if (aData.success) analystOptions = aData.analysts;
                    if (tData.success) teamOptions = tData.teams;
                } catch (e) {
                    showToast(window.t('contracts.detail.toast_assignee_load_failed'), 'error');
                    return;
                }
            }

            const c = currentContract;
            const titleDefault = window.t('contracts.detail.task_title_default', { number: c.contract_number, title: c.title });
            const dueDefault = c.notice_date || c.contract_end || '';
            // Default assignee = contract owner if present
            const assigneeDefault = c.contract_owner_id || '';

            document.getElementById('taskTitle').value = titleDefault;
            document.getElementById('taskDescription').value = window.t('contracts.detail.linked_description', { number: c.contract_number, title: c.title }) + (c.supplier_name ? ' ' + window.t('contracts.detail.supplier_suffix', { supplier: c.supplier_name }) : '');
            document.getElementById('taskDueDate').value = dueDefault ? dueDefault.substring(0, 10) : '';
            document.getElementById('taskPriority').value = 'Medium';
            document.getElementById('taskStatus').value = 'To Do';

            const analystSel = document.getElementById('taskAnalyst');
            analystSel.innerHTML = '<option value="">' + escapeHtml(window.t('contracts.detail.unassigned')) + '</option>' +
                analystOptions.map(a => `<option value="${a.id}" ${a.id == assigneeDefault ? 'selected' : ''}>${escapeHtml(a.name)}</option>`).join('');

            const teamSel = document.getElementById('taskTeam');
            teamSel.innerHTML = '<option value="">' + escapeHtml(window.t('contracts.detail.no_team')) + '</option>' +
                teamOptions.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');

            document.getElementById('taskModal').classList.add('active');
        }

        function closeTaskModal() {
            document.getElementById('taskModal').classList.remove('active');
        }

        async function saveTask() {
            const btn = document.getElementById('taskSaveBtn');
            const title = document.getElementById('taskTitle').value.trim();
            if (!title) {
                showToast(window.t('contracts.detail.toast_title_required'), 'error');
                return;
            }
            btn.disabled = true;
            try {
                const resp = await fetch(TASKS_API + 'save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        title: title,
                        description: document.getElementById('taskDescription').value,
                        status: document.getElementById('taskStatus').value,
                        priority: document.getElementById('taskPriority').value,
                        due_date: document.getElementById('taskDueDate').value || null,
                        assigned_analyst_id: document.getElementById('taskAnalyst').value || null,
                        assigned_team_id: document.getElementById('taskTeam').value || null,
                        contract_id: contractId
                    })
                });
                const data = await resp.json();
                if (data.success) {
                    showToast(window.t('contracts.detail.toast_task_created'), 'success');
                    closeTaskModal();
                    loadRelatedTasks();
                } else {
                    showToast(window.t('contracts.detail.error_prefix') + ' ' + (data.error || window.t('contracts.detail.save_failed')), 'error');
                }
            } catch (e) {
                showToast(window.t('contracts.detail.toast_task_save_failed'), 'error');
            } finally {
                btn.disabled = false;
            }
        }

        async function openEventModal() {
            if (!categoryOptions.length) {
                try {
                    const resp = await fetch(CALENDAR_API + 'get_categories.php?active_only=1');
                    const data = await resp.json();
                    if (data.success) categoryOptions = data.categories;
                } catch (e) {
                    showToast(window.t('contracts.detail.toast_categories_load_failed'), 'error');
                    return;
                }
            }

            const c = currentContract;
            const dateDefault = c.contract_end || c.notice_date || '';
            const titleDefault = `${c.contract_number} — ${c.title}`;

            document.getElementById('eventTitle').value = titleDefault;
            document.getElementById('eventDescription').value = window.t('contracts.detail.linked_description', { number: c.contract_number, title: c.title }) + (c.supplier_name ? ' ' + window.t('contracts.detail.supplier_suffix', { supplier: c.supplier_name }) : '');
            document.getElementById('eventStart').value = dateDefault ? dateDefault.substring(0, 10) : '';
            document.getElementById('eventAllDay').checked = true;
            document.getElementById('eventLocation').value = '';

            const catSel = document.getElementById('eventCategory');
            catSel.innerHTML = '<option value="">' + escapeHtml(window.t('contracts.detail.no_category')) + '</option>' +
                categoryOptions.map(cat => `<option value="${cat.id}">${escapeHtml(cat.name)}</option>`).join('');

            updateEventStartType();
            document.getElementById('eventModal').classList.add('active');
        }

        function closeEventModal() {
            document.getElementById('eventModal').classList.remove('active');
        }

        function updateEventStartType() {
            const allDay = document.getElementById('eventAllDay').checked;
            document.getElementById('eventStart').type = allDay ? 'date' : 'datetime-local';
        }

        async function saveEvent() {
            const btn = document.getElementById('eventSaveBtn');
            const title = document.getElementById('eventTitle').value.trim();
            const start = document.getElementById('eventStart').value;
            if (!title) {
                showToast(window.t('contracts.detail.toast_title_required'), 'error');
                return;
            }
            if (!start) {
                showToast(window.t('contracts.detail.toast_start_required'), 'error');
                return;
            }
            const allDay = document.getElementById('eventAllDay').checked;
            // Calendar API expects 'YYYY-MM-DD HH:MM:SS'. For date-only, default to start of day.
            const startDateTime = allDay ? start + ' 00:00:00' : start.replace('T', ' ') + ':00';

            btn.disabled = true;
            try {
                const resp = await fetch(CALENDAR_API + 'save_event.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        title: title,
                        description: document.getElementById('eventDescription').value,
                        category_id: document.getElementById('eventCategory').value || null,
                        start_datetime: startDateTime,
                        end_datetime: startDateTime,
                        all_day: allDay,
                        location: document.getElementById('eventLocation').value,
                        contract_id: contractId
                    })
                });
                const data = await resp.json();
                if (data.success) {
                    showToast(window.t('contracts.detail.toast_event_added'), 'success');
                    closeEventModal();
                    loadRelatedEvents();
                } else {
                    showToast(window.t('contracts.detail.error_prefix') + ' ' + (data.error || window.t('contracts.detail.save_failed')), 'error');
                }
            } catch (e) {
                showToast(window.t('contracts.detail.toast_event_save_failed'), 'error');
            } finally {
                btn.disabled = false;
            }
        }
    </script>

    <!-- Create Task Modal -->
    <div class="cv-modal-overlay" id="taskModal">
        <div class="cv-modal">
            <div class="cv-modal-header">
                <h3><?php echo htmlspecialchars(t('contracts.detail.task_modal_title')); ?></h3>
            </div>
            <div class="cv-modal-body">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('contracts.detail.field_title')); ?></label>
                    <input type="text" id="taskTitle" />
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('contracts.detail.field_description')); ?></label>
                    <textarea id="taskDescription"></textarea>
                </div>
                <div class="cv-modal-row">
                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('contracts.detail.field_assignee')); ?></label>
                        <select id="taskAnalyst"></select>
                    </div>
                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('contracts.detail.field_team')); ?></label>
                        <select id="taskTeam"></select>
                    </div>
                </div>
                <div class="cv-modal-row">
                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('contracts.detail.field_due_date')); ?></label>
                        <input type="date" id="taskDueDate" />
                    </div>
                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('contracts.detail.field_priority')); ?></label>
                        <select id="taskPriority">
                            <option value="Low"><?php echo htmlspecialchars(t('contracts.priority.low')); ?></option>
                            <option value="Medium" selected><?php echo htmlspecialchars(t('contracts.priority.medium')); ?></option>
                            <option value="High"><?php echo htmlspecialchars(t('contracts.priority.high')); ?></option>
                            <option value="Urgent"><?php echo htmlspecialchars(t('contracts.priority.urgent')); ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('contracts.detail.status')); ?></label>
                    <select id="taskStatus">
                        <option value="To Do" selected><?php echo htmlspecialchars(t('contracts.task_status.todo')); ?></option>
                        <option value="In Progress"><?php echo htmlspecialchars(t('contracts.task_status.in_progress')); ?></option>
                        <option value="Blocked"><?php echo htmlspecialchars(t('contracts.task_status.blocked')); ?></option>
                        <option value="Done"><?php echo htmlspecialchars(t('contracts.task_status.done')); ?></option>
                    </select>
                </div>
            </div>
            <div class="cv-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeTaskModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="taskSaveBtn" onclick="saveTask()"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Create Event Modal -->
    <div class="cv-modal-overlay" id="eventModal">
        <div class="cv-modal">
            <div class="cv-modal-header">
                <h3><?php echo htmlspecialchars(t('contracts.detail.event_modal_title')); ?></h3>
            </div>
            <div class="cv-modal-body">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('contracts.detail.field_title')); ?></label>
                    <input type="text" id="eventTitle" />
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('contracts.detail.field_description')); ?></label>
                    <textarea id="eventDescription"></textarea>
                </div>
                <div class="form-group checkbox-row">
                    <input type="checkbox" id="eventAllDay" checked onchange="updateEventStartType()" />
                    <label for="eventAllDay"><?php echo htmlspecialchars(t('contracts.detail.field_all_day')); ?></label>
                </div>
                <div class="cv-modal-row">
                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('contracts.detail.field_start')); ?></label>
                        <input type="date" id="eventStart" />
                    </div>
                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('contracts.detail.field_category')); ?></label>
                        <select id="eventCategory"></select>
                    </div>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('contracts.detail.field_location')); ?></label>
                    <input type="text" id="eventLocation" />
                </div>
            </div>
            <div class="cv-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEventModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="eventSaveBtn" onclick="saveEvent()"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>
</body>
</html>
