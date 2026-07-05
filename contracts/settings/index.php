<?php
/**
 * Contracts Module - Settings
 */
session_start();
require_once '../../config.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'contracts'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('contracts.settings.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js"></script>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .container { height: calc(100vh - 48px); overflow-y: auto; max-width: none; }

        /* Amber theme for Contracts tabs */
        .tab:hover { color: #f59e0b; }
        .tab.active { color: #f59e0b; border-bottom-color: #f59e0b; }

        .tab-content .action-btn {
            background: none;
            border: 1px solid #ddd;
            color: #666;
            cursor: pointer;
            padding: 6px;
            margin-right: 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .tab-content .action-btn:hover { background: #f0f0f0; border-color: #f59e0b; color: #f59e0b; }
        .tab-content .action-btn.delete { color: #d13438; }
        .tab-content .action-btn.delete:hover { background: #fdf3f3; border-color: #d13438; color: #a00; }
        .tab-content .action-btn svg { width: 16px; height: 16px; }

        /* Active/Inactive badges use the shared .status-badge / .status-active
           / .status-inactive classes from inbox.css (canonical shape + colour). */

        /* Module accent — drives the shared toggle, focus rings and button
           colours defined in inbox.css. Modal form styling is all there too. */
        body { --accent: #f59e0b; }

        .modal-content { padding: 20px; max-width: 500px; }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 20px; color: #333; padding: 0; border-bottom: none; }
        .modal-actions { margin-top: 20px; }

        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; transition: background-color 0.15s; }
        .btn-primary { background-color: #f59e0b; color: white; }
        .btn-primary:hover { background-color: #d97706; }

        .rfp-ai-ssl-warning {
            margin-top: 10px;
            padding: 10px 14px;
            background: #fdecea;
            border: 1px solid #f5c6cb;
            border-left: 4px solid #d13438;
            border-radius: 4px;
            font-size: 13px;
            line-height: 1.5;
            color: #5a1c1c;
            max-width: 640px;
        }
        .rfp-ai-ssl-warning strong { color: #b71c1c; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="supplier-types" onclick="switchTab('supplier-types')"><?php echo htmlspecialchars(t('contracts.settings.tab_supplier_types')); ?></button>
            <button class="tab" data-tab="supplier-statuses" onclick="switchTab('supplier-statuses')"><?php echo htmlspecialchars(t('contracts.settings.tab_supplier_statuses')); ?></button>
            <button class="tab" data-tab="contract-statuses" onclick="switchTab('contract-statuses')"><?php echo htmlspecialchars(t('contracts.settings.tab_contract_statuses')); ?></button>
            <button class="tab" data-tab="payment-schedules" onclick="switchTab('payment-schedules')"><?php echo htmlspecialchars(t('contracts.settings.tab_payment_schedules')); ?></button>
            <button class="tab" data-tab="contract-term-tabs" onclick="switchTab('contract-term-tabs')"><?php echo htmlspecialchars(t('contracts.settings.tab_contract_terms')); ?></button>
            <button class="tab" data-tab="rfp-departments" onclick="switchTab('rfp-departments')"><?php echo htmlspecialchars(t('contracts.settings.tab_rfp_departments')); ?></button>
            <button class="tab" data-tab="rfp-ai" onclick="switchTab('rfp-ai')"><?php echo htmlspecialchars(t('contracts.settings.tab_rfp_ai')); ?></button>
            <button class="tab" data-tab="left-panel" onclick="switchTab('left-panel')"><?php echo htmlspecialchars(t('contracts.settings.tab_left_panel')); ?></button>
        </div>

        <!-- Supplier types Tab -->
        <div class="tab-content active" id="supplier-types-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('contracts.settings.tab_supplier_types')); ?></h2>
                <button class="add-btn" onclick="openAddModal('supplier-type')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_order')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_status')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="supplier-types-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Supplier statuses Tab -->
        <div class="tab-content" id="supplier-statuses-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('contracts.settings.tab_supplier_statuses')); ?></h2>
                <button class="add-btn" onclick="openAddModal('supplier-status')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_order')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_status')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="supplier-statuses-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Contract statuses Tab -->
        <div class="tab-content" id="contract-statuses-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('contracts.settings.tab_contract_statuses')); ?></h2>
                <button class="add-btn" onclick="openAddModal('contract-status')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_order')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_status')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="contract-statuses-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Payment schedules Tab -->
        <div class="tab-content" id="payment-schedules-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('contracts.settings.tab_payment_schedules')); ?></h2>
                <button class="add-btn" onclick="openAddModal('payment-schedule')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_order')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_status')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="payment-schedules-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>
        <!-- Contract terms Tab -->
        <div class="tab-content" id="contract-term-tabs-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('contracts.settings.tab_contract_terms')); ?></h2>
                <button class="add-btn" onclick="openAddModal('contract-term-tab')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_order')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_status')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="contract-term-tabs-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- RFP departments Tab -->
        <div class="tab-content" id="rfp-departments-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('contracts.settings.tab_rfp_departments')); ?></h2>
                <button class="add-btn" onclick="openAddRfpDept()"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="color:#888; font-size:13px; margin: 0 0 16px 0;">
                <?php echo htmlspecialchars(t('contracts.settings.rfp_dept_intro')); ?>
            </p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_colour')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_order')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_status')); ?></th>
                        <th><?php echo htmlspecialchars(t('contracts.settings.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="rfp-departments-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- RFP AI Tab -->
        <div class="tab-content" id="rfp-ai-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('contracts.settings.tab_rfp_ai')); ?></h2>
            </div>
            <p style="color:#888; font-size:13px; margin: 0 0 20px 0; max-width: 720px;">
                <?php echo t('contracts.settings.ai_intro'); ?>
            </p>

            <div style="max-width: 640px;">
                <form id="aiSettingsForm" autocomplete="off">
                    <div class="form-group">
                        <label for="aiProvider"><?php echo htmlspecialchars(t('contracts.settings.ai_provider')); ?></label>
                        <select id="aiProvider">
                            <option value="anthropic">Anthropic (Claude)</option>
                            <option value="openai">OpenAI (GPT)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="aiModel"><?php echo htmlspecialchars(t('contracts.settings.ai_model')); ?></label>
                        <input type="text" id="aiModel" list="aiModelOptions" placeholder="<?php echo htmlspecialchars(t('contracts.settings.ai_model_ph')); ?>">
                        <datalist id="aiModelOptions"></datalist>
                        <div style="font-size:12px; color:#888; margin-top:4px;" id="aiModelHelp">
                            <?php echo htmlspecialchars(t('contracts.settings.ai_model_help')); ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="aiApiKey"><?php echo htmlspecialchars(t('contracts.settings.ai_api_key')); ?></label>
                        <input type="text" id="aiApiKey" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('contracts.settings.ai_api_key_ph_none')); ?>">
                        <div style="font-size:12px; color:#888; margin-top:4px;">
                            <?php echo t('contracts.settings.ai_api_key_help'); ?>
                            <?php echo htmlspecialchars(t('contracts.settings.ai_anthropic_keys')); ?> <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener" style="color:#f59e0b;">console.anthropic.com</a>.
                            <?php echo htmlspecialchars(t('contracts.settings.ai_openai_keys')); ?> <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener" style="color:#f59e0b;">platform.openai.com</a>.
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="toggle-label">
                            <span class="toggle-switch">
                                <input type="checkbox" id="aiVerifySsl" checked onchange="updateAiSslWarning()">
                                <span class="toggle-slider"></span>
                            </span>
                            <?php echo htmlspecialchars(t('contracts.settings.ai_verify_ssl')); ?>
                        </label>
                        <div style="font-size:12px; color:#888; margin-top:4px;">
                            <?php echo htmlspecialchars(t('contracts.settings.ai_verify_ssl_help')); ?>
                        </div>
                        <div id="aiVerifySslWarning" class="rfp-ai-ssl-warning" style="display:none;">
                            <?php echo t('contracts.settings.ai_ssl_warning'); ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="aiDefaultStyleGuide"><?php echo htmlspecialchars(t('contracts.settings.ai_style_guide')); ?></label>
                        <textarea id="aiDefaultStyleGuide" rows="6" placeholder="<?php echo htmlspecialchars(t('contracts.settings.ai_style_guide_ph')); ?>"></textarea>
                        <div style="font-size:12px; color:#888; margin-top:4px;">
                            <?php echo htmlspecialchars(t('contracts.settings.ai_style_guide_help')); ?>
                        </div>
                    </div>

                    <div style="display:flex; gap:8px; align-items:center; margin-top: 20px;">
                        <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                        <button type="button" class="btn" id="aiTestBtn" onclick="testAiConnection()" style="background:white; border:1px solid #ddd; color:#333;"><?php echo htmlspecialchars(t('contracts.settings.ai_test')); ?></button>
                        <span id="aiTestStatus" style="font-size:13px; margin-left:8px;"></span>
                    </div>
                </form>
            </div>
        </div>

        <!-- Left panel tab — per-analyst preference -->
        <div class="tab-content" id="left-panel-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('contracts.settings.tab_left_panel')); ?></h2>
            </div>
            <p style="color: #666; margin-bottom: 20px;"><?php echo htmlspecialchars(t('contracts.settings.left_panel_intro')); ?></p>

            <form id="leftPanelForm" autocomplete="off" onsubmit="event.preventDefault();">
                <div class="form-group">
                    <label style="display: block; margin-bottom: 10px; font-weight: 500; color: #333;"><?php echo htmlspecialchars(t('contracts.settings.left_panel_visibility')); ?></label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid #ddd; border-radius: 6px; margin-bottom: 8px; cursor: pointer;">
                        <input type="radio" name="contractsSidebarMode" value="always" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('contracts.settings.left_panel_always')); ?></strong>
                        <span style="display: block; font-size: 12px; color: #777; margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('contracts.settings.left_panel_always_desc')); ?>
                        </span>
                    </label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid #ddd; border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="contractsSidebarMode" value="hover" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('contracts.settings.left_panel_hover')); ?></strong>
                        <span style="display: block; font-size: 12px; color: #777; margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('contracts.settings.left_panel_hover_desc')); ?>
                        </span>
                    </label>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit/Add Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle"><?php echo htmlspecialchars(t('contracts.settings.modal_add_item')); ?></div>
            <form id="editForm" autocomplete="off">
                <input type="hidden" id="itemId">
                <input type="hidden" id="itemType">
                <div class="form-group">
                    <label for="itemName"><?php echo htmlspecialchars(t('contracts.settings.col_name')); ?></label>
                    <input type="text" id="itemName" required>
                </div>
                <div class="form-group">
                    <label for="itemDescription"><?php echo htmlspecialchars(t('contracts.settings.col_description')); ?></label>
                    <textarea id="itemDescription"></textarea>
                </div>
                <div class="form-group">
                    <label for="itemOrder"><?php echo htmlspecialchars(t('contracts.settings.display_order')); ?></label>
                    <input type="number" id="itemOrder" value="0" min="0">
                </div>
                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="itemActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('contracts.status.active')); ?>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- RFP Department Modal -->
    <div class="modal" id="rfpDeptModal">
        <div class="modal-content">
            <div class="modal-header" id="rfpDeptModalTitle"><?php echo htmlspecialchars(t('contracts.settings.modal_add_rfp_dept')); ?></div>
            <form id="rfpDeptForm" autocomplete="off">
                <input type="hidden" id="rfpDeptId">
                <div class="form-group">
                    <label for="rfpDeptName"><?php echo htmlspecialchars(t('contracts.settings.col_name')); ?></label>
                    <input type="text" id="rfpDeptName" required maxlength="100" placeholder="<?php echo htmlspecialchars(t('contracts.settings.rfp_dept_name_ph')); ?>">
                </div>
                <div class="form-group">
                    <label for="rfpDeptColour"><?php echo htmlspecialchars(t('contracts.settings.col_colour')); ?></label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="color" id="rfpDeptColour" value="#6c757d" style="width:60px; height:36px; padding:0; cursor:pointer;">
                        <span id="rfpDeptColourHex" style="font-family:monospace; font-size:13px; color:#666;">#6c757d</span>
                    </div>
                </div>
                <div class="form-group">
                    <label for="rfpDeptOrder"><?php echo htmlspecialchars(t('contracts.settings.display_order')); ?></label>
                    <input type="number" id="rfpDeptOrder" value="0" min="0">
                </div>
                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="rfpDeptActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('contracts.status.active')); ?>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeRfpDeptModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/contracts/';
        let allItems = { 'supplier-type': [], 'supplier-status': [], 'contract-status': [], 'payment-schedule': [], 'contract-term-tab': [] };

        const endpoints = {
            'supplier-type': {
                get: API_BASE + 'get_supplier_types.php',
                save: API_BASE + 'save_supplier_type.php',
                delete: API_BASE + 'delete_supplier_type.php',
                key: 'supplier_types',
                listId: 'supplier-types-list',
                label: window.t('contracts.settings.label_supplier_type')
            },
            'supplier-status': {
                get: API_BASE + 'get_supplier_statuses.php',
                save: API_BASE + 'save_supplier_status.php',
                delete: API_BASE + 'delete_supplier_status.php',
                key: 'supplier_statuses',
                listId: 'supplier-statuses-list',
                label: window.t('contracts.settings.label_supplier_status')
            },
            'contract-status': {
                get: API_BASE + 'get_contract_statuses.php',
                save: API_BASE + 'save_contract_status.php',
                delete: API_BASE + 'delete_contract_status.php',
                key: 'contract_statuses',
                listId: 'contract-statuses-list',
                label: window.t('contracts.settings.label_contract_status')
            },
            'payment-schedule': {
                get: API_BASE + 'get_payment_schedules.php',
                save: API_BASE + 'save_payment_schedule.php',
                delete: API_BASE + 'delete_payment_schedule.php',
                key: 'payment_schedules',
                listId: 'payment-schedules-list',
                label: window.t('contracts.settings.label_payment_schedule')
            },
            'contract-term-tab': {
                get: API_BASE + 'get_contract_term_tabs.php',
                save: API_BASE + 'save_contract_term_tab.php',
                delete: API_BASE + 'delete_contract_term_tab.php',
                key: 'contract_term_tabs',
                listId: 'contract-term-tabs-list',
                label: window.t('contracts.settings.label_contract_term')
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            loadItems('supplier-type');
            loadItems('supplier-status');
            loadItems('contract-status');
            loadItems('payment-schedule');
            loadItems('contract-term-tab');
            loadRfpDepartments();
            loadAiSettings();
        });

        // ============================================================
        // RFP Departments — separate flow because the schema differs
        // (colour + sort_order, no description)
        // ============================================================
        const RFP_DEPT_API = '../../api/rfp-builder/';
        let rfpDepartments = [];

        async function loadRfpDepartments() {
            try {
                const response = await fetch(RFP_DEPT_API + 'get_rfp_departments.php');
                const data = await response.json();
                if (data.success) {
                    rfpDepartments = data.rfp_departments;
                    renderRfpDepartments(rfpDepartments);
                } else {
                    document.getElementById('rfp-departments-list').innerHTML =
                        '<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">' + escapeHtml(window.t('contracts.settings.error_prefix')) + ' ' + escapeHtml(data.error) + '</td></tr>';
                }
            } catch (error) {
                document.getElementById('rfp-departments-list').innerHTML =
                    '<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">' + escapeHtml(window.t('contracts.settings.rfp_dept_load_failed')) + '</td></tr>';
            }
        }

        function renderRfpDepartments(items) {
            const tbody = document.getElementById('rfp-departments-list');
            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#999;">' + escapeHtml(window.t('contracts.settings.rfp_dept_empty')) + '</td></tr>';
                return;
            }
            tbody.innerHTML = items.map(item => `
                <tr>
                    <td><strong>${escapeHtml(item.name)}</strong></td>
                    <td>
                        <span style="display:inline-flex; align-items:center; gap:8px;">
                            <span style="width:18px; height:18px; border-radius:4px; border:1px solid #ddd; background:${escapeHtml(item.colour)};"></span>
                            <span style="font-family:monospace; font-size:12px; color:#666;">${escapeHtml(item.colour)}</span>
                        </span>
                    </td>
                    <td>${item.sort_order}</td>
                    <td><span class="status-badge status-${item.is_active ? 'active' : 'inactive'}">${item.is_active ? escapeHtml(window.t('contracts.status.active')) : escapeHtml(window.t('contracts.status.inactive'))}</span></td>
                    <td>
                        <button class="action-btn" onclick="editRfpDept(${item.id})" title="${escapeHtml(window.t('common.edit'))}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteRfpDept(${item.id}, ${JSON.stringify(item.name)})" title="${escapeHtml(window.t('common.delete'))}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function openAddRfpDept() {
            document.getElementById('rfpDeptModalTitle').textContent = window.t('contracts.settings.modal_add_rfp_dept');
            document.getElementById('rfpDeptId').value = '';
            document.getElementById('rfpDeptName').value = '';
            document.getElementById('rfpDeptColour').value = '#6c757d';
            document.getElementById('rfpDeptColourHex').textContent = '#6c757d';
            document.getElementById('rfpDeptOrder').value = '0';
            document.getElementById('rfpDeptActive').checked = true;
            document.getElementById('rfpDeptModal').classList.add('active');
            setTimeout(() => document.getElementById('rfpDeptName').focus(), 50);
        }

        function editRfpDept(id) {
            const item = rfpDepartments.find(d => d.id == id);
            if (!item) return;
            document.getElementById('rfpDeptModalTitle').textContent = window.t('contracts.settings.modal_edit_rfp_dept');
            document.getElementById('rfpDeptId').value = item.id;
            document.getElementById('rfpDeptName').value = item.name;
            document.getElementById('rfpDeptColour').value = item.colour;
            document.getElementById('rfpDeptColourHex').textContent = item.colour;
            document.getElementById('rfpDeptOrder').value = item.sort_order;
            document.getElementById('rfpDeptActive').checked = item.is_active;
            document.getElementById('rfpDeptModal').classList.add('active');
            setTimeout(() => document.getElementById('rfpDeptName').focus(), 50);
        }

        async function deleteRfpDept(id, name) {
            if (!(await showConfirm({ title: window.t('common.delete'), message: window.t('contracts.settings.rfp_dept_delete_confirm', { name: name }), okLabel: window.t('common.delete'), okClass: 'danger' }))) return;
            try {
                const response = await fetch(RFP_DEPT_API + 'delete_rfp_department.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id})
                });
                const data = await response.json();
                if (data.success) {
                    showToast(window.t('contracts.settings.toast_dept_deleted'), 'success');
                    loadRfpDepartments();
                } else {
                    showToast(window.t('contracts.settings.error_prefix') + ' ' + data.error, 'error');
                }
            } catch (error) {
                showToast(window.t('contracts.settings.toast_dept_delete_failed'), 'error');
            }
        }

        function closeRfpDeptModal() {
            document.getElementById('rfpDeptModal').classList.remove('active');
        }

        document.getElementById('rfpDeptColour').addEventListener('input', function() {
            document.getElementById('rfpDeptColourHex').textContent = this.value;
        });

        document.getElementById('rfpDeptForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const id = document.getElementById('rfpDeptId').value;
            const payload = {
                name: document.getElementById('rfpDeptName').value.trim(),
                colour: document.getElementById('rfpDeptColour').value,
                sort_order: parseInt(document.getElementById('rfpDeptOrder').value) || 0,
                is_active: document.getElementById('rfpDeptActive').checked ? 1 : 0
            };
            if (id) payload.id = parseInt(id);

            try {
                const response = await fetch(RFP_DEPT_API + 'save_rfp_department.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    closeRfpDeptModal();
                    showToast(window.t('contracts.settings.toast_dept_saved'), 'success');
                    loadRfpDepartments();
                } else {
                    showToast(window.t('contracts.settings.error_prefix') + ' ' + data.error, 'error');
                }
            } catch (error) {
                showToast(window.t('contracts.settings.toast_dept_save_failed'), 'error');
            }
        });

        // Click-outside-to-close for the RFP department modal (matches existing pattern)
        let rfpDeptModalMouseDownTarget = null;
        document.getElementById('rfpDeptModal').addEventListener('mousedown', function(e) {
            rfpDeptModalMouseDownTarget = e.target;
        });
        document.getElementById('rfpDeptModal').addEventListener('click', function(e) {
            if (e.target === this && rfpDeptModalMouseDownTarget === this) closeRfpDeptModal();
        });

        // ============================================================
        // RFP AI settings (provider, model, encrypted API key, test)
        // ============================================================
        const RFP_AI_API = '../../api/rfp-builder/';
        const RFP_AI_MODEL_OPTIONS = {
            anthropic: [
                { id: 'claude-opus-4-7',           label: 'Opus 4.7 — most capable' },
                { id: 'claude-sonnet-4-6',         label: 'Sonnet 4.6 — recommended for extraction (best balance)' },
                { id: 'claude-haiku-4-5-20251001', label: 'Haiku 4.5 — fastest and cheapest' },
            ],
            openai: [
                { id: 'gpt-4.1',      label: 'GPT-4.1 — most capable' },
                { id: 'gpt-4o',       label: 'GPT-4o — recommended default' },
                { id: 'gpt-4o-mini',  label: 'GPT-4o mini — fastest and cheapest' },
            ],
        };
        const RFP_AI_DEFAULT_MODEL = {
            anthropic: 'claude-sonnet-4-6',
            openai:    'gpt-4o',
        };
        let rfpAiOriginalKeyMask = '';

        function refreshAiModelOptions() {
            const provider = document.getElementById('aiProvider').value;
            const list = document.getElementById('aiModelOptions');
            const opts = RFP_AI_MODEL_OPTIONS[provider] || [];
            list.innerHTML = opts.map(m => `<option value="${m.id}">${escapeHtml(m.label)}</option>`).join('');
            const helpEl = document.getElementById('aiModelHelp');
            helpEl.textContent = window.t('contracts.settings.ai_model_help');
        }

        async function loadAiSettings() {
            try {
                const res = await fetch(RFP_AI_API + 'get_ai_settings.php');
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                const s = data.settings || {};
                document.getElementById('aiProvider').value = s.rfp_ai_provider || 'anthropic';
                refreshAiModelOptions();
                document.getElementById('aiModel').value =
                    s.rfp_ai_model || RFP_AI_DEFAULT_MODEL[document.getElementById('aiProvider').value];
                rfpAiOriginalKeyMask = s.rfp_ai_api_key || '';
                document.getElementById('aiApiKey').value = rfpAiOriginalKeyMask;
                document.getElementById('aiApiKey').placeholder = data.has_key
                    ? window.t('contracts.settings.ai_api_key_ph_stored')
                    : window.t('contracts.settings.ai_api_key_ph_none');
                // verify_ssl: default to true unless explicitly stored as "0"
                document.getElementById('aiVerifySsl').checked = s.rfp_ai_verify_ssl !== '0';
                updateAiSslWarning();
                document.getElementById('aiDefaultStyleGuide').value = s.rfp_default_style_guide || '';
            } catch (err) {
                setAiTestStatus(window.t('contracts.settings.ai_load_failed') + ' ' + err.message, 'error');
            }
        }

        function updateAiSslWarning() {
            const checked = document.getElementById('aiVerifySsl').checked;
            document.getElementById('aiVerifySslWarning').style.display = checked ? 'none' : '';
        }

        function setAiTestStatus(msg, kind) {
            const el = document.getElementById('aiTestStatus');
            el.textContent = msg;
            if (kind === 'success') el.style.color = '#065f46';
            else if (kind === 'error') el.style.color = '#d13438';
            else if (kind === 'busy') el.style.color = '#b45309';
            else el.style.color = '#555';
        }

        document.getElementById('aiProvider').addEventListener('change', function() {
            refreshAiModelOptions();
            // If the model is empty or doesn't match the new provider's options, reset to that provider's default.
            const modelEl = document.getElementById('aiModel');
            const provider = this.value;
            const known = (RFP_AI_MODEL_OPTIONS[provider] || []).map(m => m.id);
            if (!modelEl.value || !known.includes(modelEl.value)) {
                modelEl.value = RFP_AI_DEFAULT_MODEL[provider];
            }
        });

        document.getElementById('aiSettingsForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const payload = {
                provider:   document.getElementById('aiProvider').value,
                model:      document.getElementById('aiModel').value.trim(),
                api_key:    document.getElementById('aiApiKey').value,
                verify_ssl: document.getElementById('aiVerifySsl').checked ? '1' : '0',
                default_style_guide: document.getElementById('aiDefaultStyleGuide').value,
            };
            try {
                const res = await fetch(RFP_AI_API + 'save_ai_settings.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                setAiTestStatus(window.t('contracts.settings.ai_saved'), 'success');
                await loadAiSettings();
            } catch (err) {
                setAiTestStatus(window.t('contracts.settings.ai_save_failed') + ' ' + err.message, 'error');
            }
        });

        async function testAiConnection() {
            const btn = document.getElementById('aiTestBtn');
            const payload = {
                provider:   document.getElementById('aiProvider').value,
                model:      document.getElementById('aiModel').value.trim(),
                api_key:    document.getElementById('aiApiKey').value,
                verify_ssl: document.getElementById('aiVerifySsl').checked ? '1' : '0',
            };
            if (!payload.model) {
                setAiTestStatus(window.t('contracts.settings.ai_pick_model'), 'error');
                return;
            }
            btn.disabled = true;
            setAiTestStatus(window.t('contracts.settings.ai_testing'), 'busy');
            try {
                const res = await fetch(RFP_AI_API + 'test_ai_connection.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                const tokens = (data.tokens_in != null && data.tokens_out != null)
                    ? ` — ${window.t('contracts.settings.ai_tokens', { in: data.tokens_in, out: data.tokens_out })}`
                    : '';
                setAiTestStatus(
                    `${window.t('contracts.settings.ai_ok')} — ${data.provider} · ${data.model} · ${data.latency_ms}ms${tokens}`,
                    'success'
                );
            } catch (err) {
                setAiTestStatus(window.t('contracts.settings.ai_test_failed') + ' ' + err.message, 'error');
            } finally {
                btn.disabled = false;
            }
        }

        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
            if (tab === 'left-panel') loadSidebarMode();
        }

        // --- Left panel preference ------------------------------------
        // Same pattern as knowledge / process-mapper: 'always' vs 'hover',
        // stored per-analyst via user_preferences. Header.php reads the same
        // key on every contracts page and toggles .sidebar-hover on the
        // .contracts-layout container.
        const SIDEBAR_MODE_KEY = 'contracts_sidebar_mode';
        let sidebarModeLoaded = false;
        async function loadSidebarMode() {
            if (sidebarModeLoaded) return;
            sidebarModeLoaded = true;
            try {
                const r = await fetch('../../api/system/get_user_preference.php?key=' + encodeURIComponent(SIDEBAR_MODE_KEY), { credentials: 'same-origin' });
                const d = await r.json();
                const mode = (d.success && (d.value === 'always' || d.value === 'hover')) ? d.value : 'always';
                document.querySelectorAll('input[name="contractsSidebarMode"]').forEach(i => { i.checked = (i.value === mode); });
            } catch (e) {
                const first = document.querySelector('input[name="contractsSidebarMode"][value="always"]');
                if (first) first.checked = true;
            }
        }

        async function saveSidebarMode(value) {
            if (value !== 'always' && value !== 'hover') return;
            try {
                const r = await fetch('../../api/system/set_user_preference.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ key: SIDEBAR_MODE_KEY, value: value })
                });
                const d = await r.json();
                if (d.success) showToast(window.t('common.saved'), 'success');
            } catch (e) { /* no-op */ }
        }

        async function loadItems(type) {
            const ep = endpoints[type];
            try {
                const response = await fetch(ep.get);
                const data = await response.json();
                if (data.success) {
                    allItems[type] = data[ep.key];
                    renderItems(type, data[ep.key]);
                } else {
                    document.getElementById(ep.listId).innerHTML =
                        '<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">' + escapeHtml(window.t('contracts.settings.error_prefix')) + ' ' + escapeHtml(data.error) + '</td></tr>';
                }
            } catch (error) {
                console.error('Error loading ' + type + ':', error);
                document.getElementById(ep.listId).innerHTML =
                    '<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">' + escapeHtml(window.t('contracts.settings.load_failed')) + '</td></tr>';
            }
        }

        function renderItems(type, items) {
            const ep = endpoints[type];
            const tbody = document.getElementById(ep.listId);

            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#999;">' + escapeHtml(window.t('contracts.settings.items_empty')) + '</td></tr>';
                return;
            }

            tbody.innerHTML = items.map(item => `
                <tr>
                    <td><strong>${escapeHtml(item.name)}</strong></td>
                    <td>${escapeHtml(item.description || '-')}</td>
                    <td>${item.display_order}</td>
                    <td><span class="status-badge status-${item.is_active ? 'active' : 'inactive'}">${item.is_active ? escapeHtml(window.t('contracts.status.active')) : escapeHtml(window.t('contracts.status.inactive'))}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('${type}', ${item.id})" title="${escapeHtml(window.t('common.edit'))}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('${type}', ${item.id}, '${escapeHtml(item.name)}')" title="${escapeHtml(window.t('common.delete'))}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function openAddModal(type) {
            const ep = endpoints[type];
            document.getElementById('modalTitle').textContent = window.t('contracts.settings.modal_add', { label: ep.label });
            document.getElementById('itemId').value = '';
            document.getElementById('itemType').value = type;
            document.getElementById('itemName').value = '';
            document.getElementById('itemDescription').value = '';
            document.getElementById('itemOrder').value = '0';
            document.getElementById('itemActive').checked = true;
            document.getElementById('editModal').classList.add('active');
        }

        function editItem(type, id) {
            const ep = endpoints[type];
            const item = allItems[type].find(i => i.id == id);
            if (!item) return;

            document.getElementById('modalTitle').textContent = window.t('contracts.settings.modal_edit', { label: ep.label });
            document.getElementById('itemId').value = item.id;
            document.getElementById('itemType').value = type;
            document.getElementById('itemName').value = item.name;
            document.getElementById('itemDescription').value = item.description || '';
            document.getElementById('itemOrder').value = item.display_order || 0;
            document.getElementById('itemActive').checked = item.is_active;
            document.getElementById('editModal').classList.add('active');
        }

        async function deleteItem(type, id, name) {
            const ep = endpoints[type];
            if (!(await showConfirm({ title: window.t('common.delete'), message: window.t('contracts.settings.item_delete_confirm', { name: name, label: ep.label.toLowerCase() }), okLabel: window.t('common.delete'), okClass: 'danger' }))) return;

            try {
                const response = await fetch(ep.delete, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(window.t('contracts.settings.toast_deleted'), 'success');
                    loadItems(type);
                } else {
                    showToast(window.t('contracts.settings.error_prefix') + ' ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error deleting:', error);
                showToast(window.t('contracts.settings.toast_delete_failed'), 'error');
            }
        }

        function closeModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const type = document.getElementById('itemType').value;
            const ep = endpoints[type];
            const id = document.getElementById('itemId').value;

            const payload = {
                name: document.getElementById('itemName').value.trim(),
                description: document.getElementById('itemDescription').value.trim(),
                display_order: parseInt(document.getElementById('itemOrder').value) || 0,
                is_active: document.getElementById('itemActive').checked ? 1 : 0
            };
            if (id) payload.id = parseInt(id);

            try {
                const response = await fetch(ep.save, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    closeModal();
                    showToast(window.t('common.saved'), 'success');
                    loadItems(type);
                } else {
                    showToast(window.t('contracts.settings.error_prefix') + ' ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error saving:', error);
                showToast(window.t('contracts.settings.toast_save_failed'), 'error');
            }
        });

        let modalMouseDownTarget = null;
        document.getElementById('editModal').addEventListener('mousedown', function(e) {
            modalMouseDownTarget = e.target;
        });
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this && modalMouseDownTarget === this) closeModal();
        });

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
