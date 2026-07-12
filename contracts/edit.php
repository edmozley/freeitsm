<?php
/**
 * Contracts Module - Add/Edit Contract
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
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($contract_id ? t('contracts.edit.page_title_edit') : t('contracts.edit.page_title_add')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script src="../assets/js/tinymce/tinymce.min.js"></script>
    <style>
        /* Full-screen layout with sidebar - matches contracts dashboard */
        body { --accent: var(--con-accent, #f59e0b); }
        .contracts-layout {
            display: flex;
            height: calc(100vh - 48px);
            background: var(--app-bg, #f5f5f5);
        }
        .contracts-sidebar {
            width: 260px;
            background: var(--surface, white);
            border-right: 1px solid var(--border, #ddd);
            padding: 20px;
            overflow-y: auto;
            flex-shrink: 0;
        }
        .contracts-main {
            flex: 1;
            overflow-y: auto;
            padding: 30px 30px 0 30px;
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
            background: #f59e0b; color: white;
            border: none; border-radius: 6px;
            font-size: 14px; font-weight: 500;
            cursor: pointer; transition: background 0.2s;
            text-align: center; text-decoration: none;
            box-sizing: border-box;
        }
        .sidebar-add-btn:hover { background: #d97706; }

        .form-card {
            background: var(--surface, #fff);
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            overflow: hidden;
        }

        .form-card-header {
            padding: 20px 30px;
            border-bottom: 1px solid var(--border-soft, #eee);
        }

        .form-card-header h2 { margin: 0; font-size: 20px; color: var(--text, #333); }

        .form-card-body { padding: 30px; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: var(--text, #333); }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 12px; border: 1px solid var(--border, #ddd); border-radius: 4px;
            font-size: 14px; box-sizing: border-box; font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .form-group textarea { height: 80px; resize: vertical; }

        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: var(--con-accent, #f59e0b); box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.1);
        }

        .form-section {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dim, #888);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 0 6px 0;
            margin-top: 10px;
            border-top: 1px solid var(--border-soft, #eee);
        }

        .form-hint { font-size: 12px; color: var(--text-dim, #888); margin-top: 4px; }

        .form-actions {
            position: sticky;
            bottom: 0;
            background: var(--surface, #fff);
            border-top: 1px solid var(--border, #ddd);
            box-shadow: 0 -2px 6px rgba(0,0,0,0.04);
            padding: 10px 16px 22px 16px;
            margin: 16px -30px 0 -30px;
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 10;
        }

        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background-color: #f59e0b; color: white; }
        .btn-primary:hover { background-color: #d97706; }
        .btn-secondary { background: var(--surface-3, #e0e0e0); color: var(--text, #333); }
        .btn-secondary:hover { background: var(--surface-hover, #d0d0d0); }


        .toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: var(--surface-3, #ccc); border-radius: 24px; transition: background 0.2s;
        }
        .toggle-slider::before {
            content: ''; position: absolute;
            height: 18px; width: 18px; left: 3px; bottom: 3px;
            background: white; border-radius: 50%; transition: transform 0.2s;
        }
        .toggle-switch input:checked + .toggle-slider { background: var(--con-accent, #f59e0b); }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }
        .toggle-row { display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer; margin-bottom: 15px; }

        .terms-tabs { display: flex; gap: 0; border-bottom: 2px solid var(--border, #e0e0e0); margin-bottom: 0; }
        .terms-tab {
            padding: 10px 20px; font-size: 13px; font-weight: 500; color: var(--text-muted, #666); cursor: pointer;
            background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.15s;
        }
        .terms-tab:hover { color: var(--text, #333); background: var(--surface-hover, #f5f5f5); }
        .terms-tab.active { color: var(--con-accent, #f59e0b); border-bottom-color: var(--con-accent, #f59e0b); font-weight: 600; }
        .terms-panel { display: none; padding-top: 16px; }
        .terms-panel.active { display: block; }
        .terms-empty { color: var(--text-dim, #999); font-size: 13px; padding: 12px 0; }
        .terms-empty a { color: var(--con-accent, #f59e0b); }

        [data-theme-mode="dark"] .sidebar-link:hover { background: #3a2e12; }
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
        </div>

        <!-- Main Content -->
        <div class="contracts-main">
        <div class="form-card">
            <div class="form-card-header">
                <h2 id="pageTitle"><?php echo htmlspecialchars($contract_id ? t('contracts.edit.heading_edit') : t('contracts.edit.heading_add')); ?></h2>
            </div>
            <div class="form-card-body">
                <form id="contractForm" autocomplete="off">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="contractNumber"><?php echo htmlspecialchars(t('contracts.edit.contract_number')); ?> *</label>
                            <input type="text" id="contractNumber" required placeholder="<?php echo htmlspecialchars(t('contracts.edit.contract_number_ph')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="contractStatusId"><?php echo htmlspecialchars(t('contracts.detail.status')); ?></label>
                            <select id="contractStatusId">
                                <option value="">-- <?php echo htmlspecialchars(t('contracts.edit.none')); ?> --</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="title"><?php echo htmlspecialchars(t('contracts.detail.title_label')); ?> *</label>
                        <input type="text" id="title" required placeholder="<?php echo htmlspecialchars(t('contracts.edit.title_ph')); ?>">
                    </div>

                    <div class="form-group">
                        <label for="description"><?php echo htmlspecialchars(t('contracts.detail.description')); ?></label>
                        <textarea id="description" placeholder="<?php echo htmlspecialchars(t('contracts.edit.description_ph')); ?>"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="supplierId"><?php echo htmlspecialchars(t('contracts.detail.supplier')); ?></label>
                            <select id="supplierId">
                                <option value="">-- <?php echo htmlspecialchars(t('contracts.edit.select_supplier')); ?> --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="ownerId"><?php echo htmlspecialchars(t('contracts.detail.owner')); ?></label>
                            <select id="ownerId">
                                <option value="">-- <?php echo htmlspecialchars(t('contracts.edit.select_owner')); ?> --</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-section"><?php echo htmlspecialchars(t('contracts.detail.section_dates')); ?></div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="contractStart"><?php echo htmlspecialchars(t('contracts.detail.start_date')); ?></label>
                            <input type="date" id="contractStart">
                        </div>
                        <div class="form-group">
                            <label for="contractEnd"><?php echo htmlspecialchars(t('contracts.detail.end_date')); ?></label>
                            <input type="date" id="contractEnd">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="noticePeriod"><?php echo htmlspecialchars(t('contracts.edit.notice_period_days')); ?></label>
                            <input type="number" id="noticePeriod" min="0" placeholder="<?php echo htmlspecialchars(t('contracts.edit.notice_period_ph')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="noticeDate"><?php echo htmlspecialchars(t('contracts.detail.notice_date')); ?></label>
                            <input type="date" id="noticeDate">
                        </div>
                    </div>

                    <div class="form-section"><?php echo htmlspecialchars(t('contracts.detail.section_financial')); ?></div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="contractValue"><?php echo htmlspecialchars(t('contracts.detail.contract_value')); ?></label>
                            <input type="number" id="contractValue" step="0.01" min="0" placeholder="<?php echo htmlspecialchars(t('contracts.edit.contract_value_ph')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="currency"><?php echo htmlspecialchars(t('contracts.edit.currency')); ?></label>
                            <select id="currency">
                                <option value="">-- <?php echo htmlspecialchars(t('contracts.edit.none')); ?> --</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="paymentScheduleId"><?php echo htmlspecialchars(t('contracts.detail.payment_schedule')); ?></label>
                            <select id="paymentScheduleId">
                                <option value="">-- <?php echo htmlspecialchars(t('contracts.edit.none')); ?> --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="costCentre"><?php echo htmlspecialchars(t('contracts.detail.cost_centre')); ?></label>
                            <input type="text" id="costCentre">
                        </div>
                    </div>

                    <div class="form-section"><?php echo htmlspecialchars(t('contracts.edit.section_documents')); ?></div>
                    <div class="form-group">
                        <label for="dmsLink"><?php echo htmlspecialchars(t('contracts.edit.dms_link_contract')); ?></label>
                        <input type="url" id="dmsLink" placeholder="https://...">
                    </div>

                    <div class="form-section"><?php echo htmlspecialchars(t('contracts.detail.section_terms')); ?></div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="termsStatus"><?php echo htmlspecialchars(t('contracts.detail.terms')); ?></label>
                            <select id="termsStatus">
                                <option value="">-- <?php echo htmlspecialchars(t('contracts.edit.none')); ?> --</option>
                                <option value="received"><?php echo htmlspecialchars(t('contracts.terms_status.received')); ?></option>
                                <option value="reviewed"><?php echo htmlspecialchars(t('contracts.terms_status.reviewed')); ?></option>
                                <option value="agreed"><?php echo htmlspecialchars(t('contracts.terms_status.agreed')); ?></option>
                            </select>
                        </div>
                        <div></div>
                    </div>
                    <div class="form-row">
                        <label class="toggle-row">
                            <span class="toggle-switch">
                                <input type="checkbox" id="personalDataTransferred">
                                <span class="toggle-slider"></span>
                            </span>
                            <?php echo htmlspecialchars(t('contracts.detail.personal_data_transferred')); ?>
                        </label>
                        <label class="toggle-row">
                            <span class="toggle-switch">
                                <input type="checkbox" id="dpiaRequired">
                                <span class="toggle-slider"></span>
                            </span>
                            <?php echo htmlspecialchars(t('contracts.detail.dpia_required')); ?>
                        </label>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="dpiaCompletedDate"><?php echo htmlspecialchars(t('contracts.detail.dpia_completed_date')); ?></label>
                            <input type="date" id="dpiaCompletedDate">
                        </div>
                        <div class="form-group">
                            <label for="dpiaDmsLink"><?php echo htmlspecialchars(t('contracts.edit.dms_link_dpia')); ?></label>
                            <input type="url" id="dpiaDmsLink" placeholder="https://...">
                        </div>
                    </div>

                    <div class="form-section" style="margin-top: 20px;"><?php echo htmlspecialchars(t('contracts.detail.terms_detail')); ?></div>
                    <div id="contractTermsSection" style="display: none;">
                        <div class="terms-tabs" id="termsTabs"></div>
                        <div id="termsPanels"></div>
                    </div>
                    <div id="contractTermsEmpty" class="terms-empty">
                        <?php echo htmlspecialchars(t('contracts.edit.no_term_tabs')); ?> <a href="settings/"><?php echo htmlspecialchars(t('contracts.edit.configure_in_settings')); ?></a>.
                    </div>
                </form>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" form="contractForm" class="btn btn-primary" id="saveBtn"><?php echo htmlspecialchars(t('common.save')); ?></button>
            <a href="index.php" class="btn btn-secondary"><?php echo htmlspecialchars(t('common.cancel')); ?></a>
        </div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/contracts/';
        const TICKETS_API = '../api/tickets/';
        let contractId = <?php echo json_encode($contract_id); ?>;

        const currencies = [
            { code: 'GBP', name: 'British Pound (GBP)' },
            { code: 'USD', name: 'US Dollar (USD)' },
            { code: 'EUR', name: 'Euro (EUR)' },
            { code: 'AUD', name: 'Australian Dollar (AUD)' },
            { code: 'CAD', name: 'Canadian Dollar (CAD)' },
            { code: 'CHF', name: 'Swiss Franc (CHF)' },
            { code: 'CNY', name: 'Chinese Yuan (CNY)' },
            { code: 'DKK', name: 'Danish Krone (DKK)' },
            { code: 'HKD', name: 'Hong Kong Dollar (HKD)' },
            { code: 'INR', name: 'Indian Rupee (INR)' },
            { code: 'JPY', name: 'Japanese Yen (JPY)' },
            { code: 'KRW', name: 'South Korean Won (KRW)' },
            { code: 'MXN', name: 'Mexican Peso (MXN)' },
            { code: 'NOK', name: 'Norwegian Krone (NOK)' },
            { code: 'NZD', name: 'New Zealand Dollar (NZD)' },
            { code: 'PLN', name: 'Polish Zloty (PLN)' },
            { code: 'SEK', name: 'Swedish Krona (SEK)' },
            { code: 'SGD', name: 'Singapore Dollar (SGD)' },
            { code: 'ZAR', name: 'South African Rand (ZAR)' }
        ];

        let termTabs = [];
        let termEditorIds = [];

        document.addEventListener('DOMContentLoaded', async function() {
            loadStats();
            populateCurrencies();
            await Promise.all([
                loadSuppliers(),
                loadAnalysts(),
                loadContractStatuses(),
                loadPaymentSchedules(),
                loadContractTermTabs()
            ]);
            if (contractId) await loadContract();

            buildTermEditors();
            initTermEditors(() => {
                if (contractId) loadContractTermValues();
            });
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

        function populateCurrencies() {
            const select = document.getElementById('currency');
            select.innerHTML = '<option value="">-- ' + escapeHtml(window.t('contracts.edit.none')) + ' --</option>' +
                currencies.map(c => `<option value="${c.code}">${c.name}</option>`).join('');
        }

        async function loadSuppliers() {
            try {
                const response = await fetch(API_BASE + 'get_suppliers.php');
                const data = await response.json();
                if (data.success) {
                    const select = document.getElementById('supplierId');
                    select.innerHTML = '<option value="">-- ' + escapeHtml(window.t('contracts.edit.select_supplier')) + ' --</option>' +
                        data.suppliers.filter(s => s.is_active).map(s =>
                            `<option value="${s.id}">${escapeHtml(s.legal_name)}</option>`
                        ).join('');
                }
            } catch (error) { console.error('Error loading suppliers:', error); }
        }

        async function loadAnalysts() {
            try {
                const response = await fetch(TICKETS_API + 'get_analysts.php');
                const data = await response.json();
                if (data.success) {
                    const select = document.getElementById('ownerId');
                    select.innerHTML = '<option value="">-- ' + escapeHtml(window.t('contracts.edit.select_owner')) + ' --</option>' +
                        data.analysts.filter(a => a.is_active).map(a =>
                            `<option value="${a.id}">${escapeHtml(a.full_name)}</option>`
                        ).join('');
                }
            } catch (error) { console.error('Error loading analysts:', error); }
        }

        async function loadContractStatuses() {
            try {
                const response = await fetch(API_BASE + 'get_contract_statuses.php');
                const data = await response.json();
                if (data.success) {
                    const select = document.getElementById('contractStatusId');
                    select.innerHTML = '<option value="">-- ' + escapeHtml(window.t('contracts.edit.none')) + ' --</option>' +
                        data.contract_statuses.filter(s => s.is_active).map(s =>
                            `<option value="${s.id}">${escapeHtml(s.name)}</option>`
                        ).join('');
                }
            } catch (error) { console.error('Error loading contract statuses:', error); }
        }

        async function loadPaymentSchedules() {
            try {
                const response = await fetch(API_BASE + 'get_payment_schedules.php');
                const data = await response.json();
                if (data.success) {
                    const select = document.getElementById('paymentScheduleId');
                    select.innerHTML = '<option value="">-- ' + escapeHtml(window.t('contracts.edit.none')) + ' --</option>' +
                        data.payment_schedules.filter(p => p.is_active).map(p =>
                            `<option value="${p.id}">${escapeHtml(p.name)}</option>`
                        ).join('');
                }
            } catch (error) { console.error('Error loading payment schedules:', error); }
        }

        async function loadContract() {
            try {
                const response = await fetch(API_BASE + 'get_contract.php?id=' + contractId);
                const data = await response.json();
                if (data.success) {
                    const c = data.contract;
                    document.getElementById('contractNumber').value = c.contract_number;
                    document.getElementById('title').value = c.title;
                    document.getElementById('description').value = c.description || '';
                    document.getElementById('supplierId').value = c.supplier_id || '';
                    document.getElementById('ownerId').value = c.contract_owner_id || '';
                    document.getElementById('contractStatusId').value = c.contract_status_id || '';
                    document.getElementById('contractStart').value = c.contract_start || '';
                    document.getElementById('contractEnd').value = c.contract_end || '';
                    document.getElementById('noticePeriod').value = c.notice_period_days || '';
                    document.getElementById('noticeDate').value = c.notice_date || '';
                    document.getElementById('contractValue').value = c.contract_value || '';
                    document.getElementById('currency').value = c.currency || '';
                    document.getElementById('paymentScheduleId').value = c.payment_schedule_id || '';
                    document.getElementById('costCentre').value = c.cost_centre || '';
                    document.getElementById('dmsLink').value = c.dms_link || '';
                    document.getElementById('termsStatus').value = c.terms_status || '';
                    document.getElementById('personalDataTransferred').checked = !!c.personal_data_transferred;
                    document.getElementById('dpiaRequired').checked = !!c.dpia_required;
                    document.getElementById('dpiaCompletedDate').value = c.dpia_completed_date || '';
                    document.getElementById('dpiaDmsLink').value = c.dpia_dms_link || '';
                }
            } catch (error) { console.error('Error loading contract:', error); }
        }

        document.getElementById('contractForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = window.t('common.saving');

            const payload = {
                contract_number: document.getElementById('contractNumber').value.trim(),
                title: document.getElementById('title').value.trim(),
                description: document.getElementById('description').value.trim(),
                supplier_id: document.getElementById('supplierId').value || null,
                contract_owner_id: document.getElementById('ownerId').value || null,
                contract_status_id: document.getElementById('contractStatusId').value || null,
                contract_start: document.getElementById('contractStart').value || null,
                contract_end: document.getElementById('contractEnd').value || null,
                notice_period_days: document.getElementById('noticePeriod').value || null,
                notice_date: document.getElementById('noticeDate').value || null,
                contract_value: document.getElementById('contractValue').value || null,
                currency: document.getElementById('currency').value || null,
                payment_schedule_id: document.getElementById('paymentScheduleId').value || null,
                cost_centre: document.getElementById('costCentre').value.trim(),
                dms_link: document.getElementById('dmsLink').value.trim(),
                terms_status: document.getElementById('termsStatus').value || null,
                personal_data_transferred: document.getElementById('personalDataTransferred').checked ? 1 : 0,
                dpia_required: document.getElementById('dpiaRequired').checked ? 1 : 0,
                dpia_completed_date: document.getElementById('dpiaCompletedDate').value || null,
                dpia_dms_link: document.getElementById('dpiaDmsLink').value.trim(),
                is_active: 1
            };
            if (contractId) payload.id = parseInt(contractId);

            try {
                const response = await fetch(API_BASE + 'save_contract.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    const savedId = contractId || data.id;

                    // Save terms if any editors exist
                    if (termEditorIds.length > 0) {
                        const terms = termTabs.map(tab => ({
                            term_tab_id: tab.id,
                            content: tinymce.get('termEditor_' + tab.id)?.getContent() || ''
                        }));
                        await fetch(API_BASE + 'save_contract_terms.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ contract_id: parseInt(savedId), terms })
                        });
                    }

                    if (contractId) {
                        showToast(window.t('contracts.edit.toast_saved'), 'success');
                    } else {
                        window.location.href = 'view.php?id=' + savedId;
                    }
                } else {
                    showToast(window.t('contracts.detail.error_prefix') + ' ' + data.error, 'error');
                }
            } catch (error) {
                showToast(window.t('contracts.edit.toast_save_failed'), 'error');
            }

            saveBtn.disabled = false;
            saveBtn.textContent = window.t('common.save');
        });

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Contract Terms
        async function loadContractTermTabs() {
            try {
                const response = await fetch(API_BASE + 'get_contract_term_tabs.php');
                const data = await response.json();
                if (data.success) {
                    termTabs = data.contract_term_tabs.filter(t => t.is_active);
                }
            } catch (error) { console.error('Error loading contract term tabs:', error); }
        }

        function buildTermEditors() {
            if (termTabs.length === 0) {
                document.getElementById('contractTermsSection').style.display = 'none';
                document.getElementById('contractTermsEmpty').style.display = '';
                return;
            }

            document.getElementById('contractTermsSection').style.display = '';
            document.getElementById('contractTermsEmpty').style.display = 'none';

            const tabsContainer = document.getElementById('termsTabs');
            const panelsContainer = document.getElementById('termsPanels');
            termEditorIds = [];

            tabsContainer.innerHTML = termTabs.map((tab, i) =>
                `<button type="button" class="terms-tab ${i === 0 ? 'active' : ''}" data-tab-id="${tab.id}" onclick="switchTermTab(${tab.id})">${escapeHtml(tab.name)}</button>`
            ).join('');

            panelsContainer.innerHTML = termTabs.map((tab, i) => {
                const editorId = 'termEditor_' + tab.id;
                termEditorIds.push(editorId);
                return `<div class="terms-panel ${i === 0 ? 'active' : ''}" id="termPanel_${tab.id}"><textarea id="${editorId}"></textarea></div>`;
            }).join('');
        }

        function switchTermTab(tabId) {
            document.querySelectorAll('.terms-tab').forEach(btn => btn.classList.remove('active'));
            document.querySelector('.terms-tab[data-tab-id="' + tabId + '"]').classList.add('active');
            document.querySelectorAll('.terms-panel').forEach(p => p.classList.remove('active'));
            document.getElementById('termPanel_' + tabId).classList.add('active');
        }

        function initTermEditors(callback) {
            if (termEditorIds.length === 0) { if (callback) callback(); return; }

            let initialized = 0;
            const total = termEditorIds.length;

            const tinyDark = (document.documentElement.getAttribute('data-theme-mode')||'light')==='dark';
            termEditorIds.forEach(id => {
                tinymce.init({
                    selector: '#' + id,
                    license_key: 'gpl',
                    height: 300,
                    menubar: false,
                    skin: tinyDark ? 'oxide-dark' : 'oxide',
                    content_css: tinyDark ? 'dark' : 'default',
                    plugins: ['advlist', 'autolink', 'lists', 'link', 'table', 'wordcount'],
                    toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link table | removeformat',
                    content_style: 'body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; color: ' + (tinyDark ? '#e6e8eb' : '#333') + '; }',
                    setup: function(editor) {
                        editor.on('init', function() {
                            initialized++;
                            if (initialized === total && callback) callback();
                        });
                    }
                });
            });
        }

        async function loadContractTermValues() {
            if (!contractId) return;
            try {
                const response = await fetch(API_BASE + 'get_contract_terms.php?contract_id=' + contractId);
                const data = await response.json();
                if (data.success) {
                    data.contract_terms.forEach(tv => {
                        const editor = tinymce.get('termEditor_' + tv.term_tab_id);
                        if (editor) editor.setContent(tv.content || '');
                    });
                }
            } catch (error) { console.error('Error loading contract term values:', error); }
        }

    </script>
</body>
</html>
