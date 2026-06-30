<?php
/**
 * Asset Management - Settings
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();

$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'asset-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('asset-management.settings.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=9">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
    <script src="../../assets/js/chart.min.js"></script>
    <style>
        /* Module accent — drives toggle, focus rings, button colours.
           Modal form CSS lives entirely in inbox.css. */
        body { --accent: #107c10; }

        .container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: none;
        }

        .tab-content .action-btn {
            background: none;
            border: 1px solid var(--border, #ddd);
            color: var(--text-muted, #666);
            cursor: pointer;
            padding: 6px;
            margin-right: 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .tab-content .action-btn:hover {
            background: var(--surface-hover, #f0f0f0);
            border-color: #107c10;
            color: #107c10;
        }

        .tab-content .action-btn.delete {
            color: var(--danger-accent, #d13438);
        }

        .tab-content .action-btn.delete:hover {
            background: var(--danger-bg, #fdf3f3);
            border-color: var(--danger-accent, #d13438);
            color: var(--danger-text, #a00);
        }

        .tab-content .action-btn svg {
            width: 16px;
            height: 16px;
        }

        /* Active/Inactive badges use the shared .status-badge / .status-active
           / .status-inactive classes from inbox.css (canonical shape + colour). */

        /* vCenter section styles */
        .settings-section {
            background: var(--surface, #fff);
            border-radius: 8px;
            box-shadow: var(--shadow, 0 1px 4px rgba(0, 0, 0, 0.08));
            margin-bottom: 25px;
            overflow: hidden;
        }

        .settings-section-header {
            padding: 20px 25px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background-color: var(--surface-3, #f8f9fa);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .settings-section-header svg { color: #107c10; flex-shrink: 0; }
        .settings-section-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: var(--text, #333); }
        .settings-section-body { padding: 25px; }
        .settings-description { font-size: 13px; color: var(--text-muted, #666); margin: 0 0 20px 0; line-height: 1.5; }

        .form-group { margin-bottom: 18px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: var(--text, #333); }

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .form-input:focus { outline: none; border-color: #107c10; box-shadow: 0 0 0 2px rgba(16, 124, 16, 0.1); }
        .form-hint { font-size: 12px; color: var(--text-dim, #888); margin-top: 4px; }

        .form-actions {
            display: flex; align-items: center; gap: 12px;
            margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--border-soft, #eee);
        }

        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; transition: background-color 0.15s; }
        .btn-primary { background-color: #107c10; color: white; }
        .btn-primary:hover { background-color: #0b5c0b; }
        .btn-primary:disabled { background-color: #999; cursor: not-allowed; }
        .btn-secondary { background-color: #6c757d; color: white; }
        .btn-secondary:hover { background-color: #5a6268; }
        .btn-secondary:disabled { background-color: #b0b6bb; cursor: not-allowed; }

        .intune-progress { margin-top: 18px; }
        .intune-progress-bar { background: var(--border, #e0e0e0); border-radius: 4px; height: 10px; overflow: hidden; }
        .intune-progress-fill { background: #107c10; height: 100%; width: 0; transition: width 0.3s ease-out; }
        .intune-progress-meta { font-size: 12px; color: var(--text-muted, #666); margin-top: 6px; }
        .intune-progress.intune-error .intune-progress-fill { background: #d13438; }

        .intune-ssl-warning {
            margin-top: 10px;
            padding: 10px 14px;
            background: var(--danger-bg, #fdecea);
            border: 1px solid #f5c2c0;
            border-left: 4px solid var(--danger-accent, #d13438);
            border-radius: 4px;
            font-size: 13px;
            line-height: 1.5;
            color: var(--danger-text, #5a1c1c);
        }
        .intune-ssl-warning strong { color: var(--danger-text, #b71c1c); }

        .intune-software-section { margin-top: 30px; padding-top: 25px; border-top: 1px solid var(--border-soft, #eee); }
        .intune-subsection-title { font-size: 15px; font-weight: 600; color: var(--text, #333); margin: 0 0 8px 0; }
        .intune-freshness-wrap { margin-top: 22px; padding: 14px 16px; background: var(--surface-3, #fafbfc); border: 1px solid var(--border-soft, #eee); border-radius: 6px; }
        .intune-freshness-title { font-size: 12px; color: var(--text-muted, #666); font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 8px; }
        .intune-freshness-canvas-wrap { position: relative; height: 180px; }
        .intune-jobs-list { margin-top: 18px; }
        .intune-jobs-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .intune-jobs-table th { text-align: left; padding: 8px 10px; background: var(--surface-3, #f8f9fa); color: var(--text-muted, #666); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 1px solid var(--border, #e0e0e0); }
        .intune-jobs-table td { padding: 8px 10px; border-bottom: 1px solid var(--surface-hover, #f0f0f0); color: var(--text, #333); }
        .intune-jobs-table tbody tr:hover { background: var(--surface-2, #fafafa); }
        .intune-job-status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .intune-job-status.pending { background: #fff3e0; color: #e65100; }
        .intune-job-status.running { background: #e3f2fd; color: #1565c0; }
        .intune-job-status.done    { background: #e8f5e9; color: #2e7d32; }
        .intune-job-status.error   { background: #ffebee; color: #c62828; }


        .password-wrapper { position: relative; }
        .password-wrapper .form-input { padding-right: 45px; }
        .password-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-dim, #888); font-size: 13px; padding: 4px; }
        .password-toggle:hover { color: var(--text, #333); }

        .modal-content {
            padding: 20px;
            max-width: 500px;
        }

        .modal-header {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text, #333);
            padding: 0;
            border-bottom: none;
        }

        /* Modal form CSS now lives entirely in inbox.css. */
        .modal-actions { margin-top: 20px; }

        /* ── Location tree ─────────────────────────────────────────── */
        .loc-tree {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
            padding: 8px 4px;
            max-width: 760px;
        }
        .loc-tree ul { list-style: none; margin: 0; padding: 0; }
        /* Children indent + a guide line down the branch. */
        .loc-children { margin-left: 22px; border-left: 1px solid var(--border-soft, #eee); padding-left: 4px; }
        .loc-children.collapsed { display: none; }

        .loc-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 8px;
            border-radius: 5px;
        }
        .loc-row:hover { background: var(--surface-hover, #f6f8f6); }

        .loc-caret {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text-dim, #888);
            border-radius: 3px;
            user-select: none;
            font-size: 11px;
            transition: transform 0.12s;
        }
        .loc-caret:hover { background: var(--surface-hover, #e8efe8); color: var(--text, #333); }
        .loc-caret.collapsed { transform: rotate(-90deg); }
        .loc-caret.leaf { cursor: default; visibility: hidden; }

        .loc-name { flex: 1; font-size: 14px; color: var(--text, #222); }
        .loc-name .loc-count { color: var(--text-faint, #999); font-size: 12px; margin-left: 6px; }

        .loc-actions { display: flex; gap: 4px; opacity: 0; transition: opacity 0.12s; }
        .loc-row:hover .loc-actions { opacity: 1; }

        .loc-empty { color: var(--text-faint, #999); padding: 16px 12px; }

        /* ── Suppliers tab ─────────────────────────────────────────── */
        .supplier-toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="asset-types" onclick="switchTab('asset-types')"><?php echo htmlspecialchars(t('asset-management.settings.tab_asset_types')); ?></button>
            <button class="tab" data-tab="asset-statuses" onclick="switchTab('asset-statuses')"><?php echo htmlspecialchars(t('asset-management.settings.tab_asset_statuses')); ?></button>
            <button class="tab" data-tab="locations" onclick="switchTab('locations')"><?php echo htmlspecialchars(t('asset-management.settings.tab_locations')); ?></button>
            <button class="tab" data-tab="suppliers" onclick="switchTab('suppliers')"><?php echo htmlspecialchars(t('asset-management.settings.tab_suppliers')); ?></button>
            <button class="tab" data-tab="warranty" onclick="switchTab('warranty')"><?php echo htmlspecialchars(t('asset-management.settings.tab_warranty')); ?></button>
            <button class="tab" data-tab="vcenter" onclick="switchTab('vcenter')"><?php echo htmlspecialchars(t('asset-management.settings.tab_vcenter')); ?></button>
            <button class="tab" data-tab="intune" onclick="switchTab('intune')"><?php echo htmlspecialchars(t('asset-management.settings.tab_intune')); ?></button>
            <button class="tab" data-tab="left-panel" onclick="switchTab('left-panel')"><?php echo htmlspecialchars(t('common.left_panel.tab')); ?></button>
        </div>

        <!-- Left panel tab — per-analyst preference -->
        <div class="tab-content" id="left-panel-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('common.left_panel.tab')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 20px;"><?php echo htmlspecialchars(t('asset-management.settings.left_panel_intro')); ?></p>

            <form id="leftPanelForm" autocomplete="off" onsubmit="event.preventDefault();">
                <div class="form-group">
                    <label style="display: block; margin-bottom: 10px; font-weight: 500; color: var(--text, #333);"><?php echo htmlspecialchars(t('common.left_panel.visibility')); ?></label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; margin-bottom: 8px; cursor: pointer;">
                        <input type="radio" name="assetsSidebarMode" value="always" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('common.left_panel.always')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-dim, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('asset-management.settings.left_panel_always_desc')); ?>
                        </span>
                    </label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="assetsSidebarMode" value="hover" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('common.left_panel.hover')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-dim, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('asset-management.settings.left_panel_hover_desc')); ?>
                        </span>
                    </label>
                </div>
            </form>
        </div>

        <!-- Asset Types Tab -->
        <div class="tab-content active" id="asset-types-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('asset-management.settings.tab_asset_types')); ?></h2>
                <button class="add-btn" onclick="openAddModal('asset-type')"><?php echo htmlspecialchars(t('asset-management.common.add')); ?></button>
            </div>
            <p class="settings-description" style="margin-bottom: 16px;">
                <?php echo t('asset-management.settings.asset_types_intro'); ?>
            </p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_order')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.field.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.common.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="asset-types-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;"><?php echo htmlspecialchars(t('asset-management.common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Asset Statuses Tab -->
        <div class="tab-content" id="asset-statuses-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('asset-management.settings.tab_asset_statuses')); ?></h2>
                <button class="add-btn" onclick="openAddModal('asset-status')"><?php echo htmlspecialchars(t('asset-management.common.add')); ?></button>
            </div>
            <p class="settings-description" style="margin-bottom: 16px;">
                <?php echo t('asset-management.settings.asset_statuses_intro'); ?>
            </p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_order')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.field.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('asset-management.common.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="asset-statuses-list">
                    <tr><td colspan="5" style="text-align: center; padding: 20px; color: #999;"><?php echo htmlspecialchars(t('asset-management.common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Locations Tab -->
        <div class="tab-content" id="locations-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('asset-management.settings.tab_locations')); ?></h2>
                <button class="add-btn" onclick="openAddLocation(null)"><?php echo htmlspecialchars(t('asset-management.common.add')); ?></button>
            </div>
            <p class="settings-description" style="margin-bottom: 18px;">
                <?php echo t('asset-management.settings.locations_intro'); ?>
            </p>
            <div id="locations-tree" class="loc-tree">
                <div style="color:#999; padding: 12px;"><?php echo htmlspecialchars(t('asset-management.common.loading')); ?></div>
            </div>
        </div>

        <!-- Suppliers Tab -->
        <div class="tab-content" id="suppliers-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('asset-management.settings.tab_suppliers')); ?></h2>
            </div>
            <p class="settings-description" style="margin-bottom: 16px;">
                <?php echo t('asset-management.settings.suppliers_intro'); ?>
            </p>
            <div class="supplier-toolbar">
                <input type="text" id="supplierSearch" class="form-input" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.supplier_search_placeholder')); ?>" autocomplete="off" oninput="renderSupplierList()" style="max-width: 280px;">
                <span style="flex: 1;"></span>
                <input type="text" id="supplierQuickAdd" class="form-input" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.supplier_new_placeholder')); ?>" autocomplete="off" style="max-width: 220px;">
                <button class="add-btn" onclick="quickAddSupplier()"><?php echo htmlspecialchars(t('asset-management.common.add')); ?></button>
            </div>
            <table style="margin-top: 14px;">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('asset-management.settings.col_supplier')); ?></th>
                        <th style="width: 160px;"><?php echo htmlspecialchars(t('asset-management.settings.available_for_assets')); ?></th>
                    </tr>
                </thead>
                <tbody id="suppliers-list">
                    <tr><td colspan="2" style="text-align: center; padding: 20px; color: #999;"><?php echo htmlspecialchars(t('asset-management.common.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Warranty alerts Tab -->
        <div class="tab-content" id="warranty-tab">
            <div class="settings-section">
                <div class="settings-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                    <h2><?php echo htmlspecialchars(t('asset-management.settings.warranty_heading')); ?></h2>
                </div>
                <div class="settings-section-body">
                    <p class="settings-description">
                        <?php echo t('asset-management.settings.warranty_intro'); ?>
                    </p>
                    <form id="warrantyForm" onsubmit="saveWarrantySettings(event)">
                        <div class="form-group">
                            <label class="form-label" for="warrantySurface"><?php echo htmlspecialchars(t('asset-management.settings.warranty_show_in')); ?></label>
                            <select class="form-input" id="warrantySurface" style="max-width: 340px;">
                                <option value="off"><?php echo htmlspecialchars(t('asset-management.settings.warranty_off')); ?></option>
                                <option value="dashboard"><?php echo htmlspecialchars(t('asset-management.settings.warranty_dashboard_only')); ?></option>
                                <option value="calendar"><?php echo htmlspecialchars(t('asset-management.settings.warranty_calendar_only')); ?></option>
                                <option value="both"><?php echo htmlspecialchars(t('asset-management.settings.warranty_both')); ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="warrantyDays"><?php echo htmlspecialchars(t('asset-management.settings.warranty_days_label')); ?></label>
                            <input type="number" class="form-input" id="warrantyDays" min="1" max="3650" value="30" style="max-width: 140px;">
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.warranty_days_hint')); ?></div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="warrantySaveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- vCenter Tab -->
        <div class="tab-content" id="vcenter-tab">
            <div class="settings-section">
                <div class="settings-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                        <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                        <line x1="6" y1="6" x2="6.01" y2="6"></line>
                        <line x1="6" y1="18" x2="6.01" y2="18"></line>
                    </svg>
                    <h2><?php echo htmlspecialchars(t('asset-management.settings.vcenter_heading')); ?></h2>
                </div>
                <div class="settings-section-body">
                    <p class="settings-description">
                        <?php echo htmlspecialchars(t('asset-management.settings.vcenter_intro')); ?>
                    </p>
                    <form id="vcenterForm" onsubmit="saveVcenterSettings(event)">
                        <div class="form-group">
                            <label class="form-label" for="vcenterServer"><?php echo htmlspecialchars(t('asset-management.settings.vcenter_server')); ?></label>
                            <input type="text" class="form-input" id="vcenterServer" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.vcenter_server_placeholder')); ?>">
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.vcenter_server_hint')); ?></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="vcenterUser"><?php echo htmlspecialchars(t('asset-management.settings.vcenter_user')); ?></label>
                            <input type="text" class="form-input" id="vcenterUser" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.vcenter_user_placeholder')); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="vcenterPassword"><?php echo htmlspecialchars(t('asset-management.settings.vcenter_password')); ?></label>
                            <div class="password-wrapper">
                                <input type="password" class="form-input" id="vcenterPassword" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.enter_password')); ?>">
                                <button type="button" class="password-toggle" onclick="togglePassword()"><?php echo htmlspecialchars(t('asset-management.settings.show')); ?></button>
                            </div>
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.password_keep_hint')); ?></div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="saveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- InTune Tab -->
        <div class="tab-content" id="intune-tab">
            <div class="settings-section">
                <div class="settings-section-header">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="12" rx="2" ry="2"></rect>
                        <line x1="8" y1="20" x2="16" y2="20"></line>
                        <line x1="12" y1="16" x2="12" y2="20"></line>
                    </svg>
                    <h2><?php echo htmlspecialchars(t('asset-management.settings.intune_heading')); ?></h2>
                </div>
                <div class="settings-section-body">
                    <p class="settings-description">
                        <?php echo htmlspecialchars(t('asset-management.settings.intune_intro')); ?>
                    </p>
                    <form id="intuneForm" onsubmit="saveIntuneSettings(event)">
                        <div class="form-group">
                            <label class="form-label" for="intuneTenantId"><?php echo htmlspecialchars(t('asset-management.settings.intune_tenant_id')); ?></label>
                            <input type="text" class="form-input" id="intuneTenantId" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.intune_tenant_placeholder')); ?>">
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.intune_tenant_hint')); ?></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="intuneClientId"><?php echo htmlspecialchars(t('asset-management.settings.intune_client_id')); ?></label>
                            <input type="text" class="form-input" id="intuneClientId" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.intune_client_id_placeholder')); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="intuneClientSecret"><?php echo htmlspecialchars(t('asset-management.settings.intune_client_secret')); ?></label>
                            <div class="password-wrapper">
                                <input type="password" class="form-input" id="intuneClientSecret" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.intune_secret_placeholder')); ?>">
                                <button type="button" class="password-toggle" onclick="toggleIntuneSecret()"><?php echo htmlspecialchars(t('asset-management.settings.show')); ?></button>
                            </div>
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.intune_secret_hint')); ?></div>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-label" style="display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer;">
                                <input type="checkbox" id="intuneVerifySsl" checked style="width: auto;" onchange="updateVerifySslWarning()">
                                <?php echo htmlspecialchars(t('asset-management.settings.verify_ssl')); ?>
                            </label>
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.verify_ssl_hint')); ?></div>
                            <div id="intuneVerifySslWarning" class="intune-ssl-warning" style="display: none;">
                                <?php echo t('asset-management.settings.verify_ssl_warning'); ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="intuneAppBatchSize"><?php echo htmlspecialchars(t('asset-management.settings.batch_size_label')); ?></label>
                            <input type="number" class="form-input" id="intuneAppBatchSize" min="1" max="500" value="30" style="max-width: 140px;">
                            <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.batch_size_hint')); ?></div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="intuneSaveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                            <button type="button" class="btn btn-secondary" id="intuneSyncBtn" onclick="startIntuneSync()"><?php echo htmlspecialchars(t('asset-management.settings.sync')); ?></button>
                            <span id="intuneLastSync" class="form-hint" style="margin-left: auto;"></span>
                        </div>
                        <div id="intuneSyncProgress" class="intune-progress" style="display: none;">
                            <div class="intune-progress-bar"><div class="intune-progress-fill" id="intuneProgressFill"></div></div>
                            <div class="intune-progress-meta" id="intuneProgressMeta"><?php echo htmlspecialchars(t('asset-management.settings.starting')); ?></div>
                        </div>
                    </form>

                    <div class="intune-software-section">
                        <h3 class="intune-subsection-title"><?php echo htmlspecialchars(t('asset-management.settings.software_sync_heading')); ?></h3>
                        <p class="settings-description">
                            <?php echo t('asset-management.settings.software_sync_intro'); ?>
                        </p>
                        <div class="form-actions" style="border-top: none; padding-top: 0;">
                            <button type="button" class="btn btn-secondary" id="intuneAppSyncBtn" onclick="startAppSync()"><?php echo htmlspecialchars(t('asset-management.settings.sync_software')); ?></button>
                            <span id="intuneAppEligible" class="form-hint" style="margin-left: auto;"></span>
                        </div>
                        <div id="intuneAppSyncProgress" class="intune-progress" style="display: none;">
                            <div class="intune-progress-bar"><div class="intune-progress-fill" id="intuneAppProgressFill"></div></div>
                            <div class="intune-progress-meta" id="intuneAppProgressMeta"><?php echo htmlspecialchars(t('asset-management.settings.starting')); ?></div>
                        </div>
                        <div class="intune-freshness-wrap" id="intuneFreshnessWrap" style="display: none;">
                            <div class="intune-freshness-title"><?php echo htmlspecialchars(t('asset-management.settings.inventory_freshness')); ?></div>
                            <div class="intune-freshness-canvas-wrap"><canvas id="intuneFreshnessChart"></canvas></div>
                        </div>
                        <div id="intuneAppJobsList" class="intune-jobs-list"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit/Add Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle"><?php echo htmlspecialchars(t('asset-management.settings.add_item')); ?></div>
            <form id="editForm">
                <input type="hidden" id="itemId">
                <input type="hidden" id="itemType">
                <div class="form-group">
                    <label for="itemName"><?php echo htmlspecialchars(t('asset-management.settings.col_name')); ?></label>
                    <input type="text" id="itemName" required>
                </div>
                <div class="form-group">
                    <label for="itemDescription"><?php echo htmlspecialchars(t('asset-management.settings.col_description')); ?></label>
                    <textarea id="itemDescription"></textarea>
                </div>
                <div class="form-group">
                    <label for="itemOrder"><?php echo htmlspecialchars(t('asset-management.settings.display_order')); ?></label>
                    <input type="number" id="itemOrder" value="0" min="0">
                </div>
                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="itemActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('asset-management.status.active')); ?>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()"><?php echo htmlspecialchars(t('asset-management.common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Location Add/Edit Modal -->
    <div class="modal" id="locationModal">
        <div class="modal-content">
            <div class="modal-header" id="locationModalTitle"><?php echo htmlspecialchars(t('asset-management.settings.add_location')); ?></div>
            <form id="locationForm">
                <input type="hidden" id="locationId">
                <div class="form-group">
                    <label for="locationName"><?php echo htmlspecialchars(t('asset-management.settings.col_name')); ?></label>
                    <input type="text" id="locationName" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="locationParent"><?php echo htmlspecialchars(t('asset-management.settings.parent_location')); ?></label>
                    <select id="locationParent">
                        <option value=""><?php echo htmlspecialchars(t('asset-management.settings.none_top_level')); ?></option>
                    </select>
                    <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.parent_location_hint')); ?></div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeLocationModal()"><?php echo htmlspecialchars(t('asset-management.common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/assets/';
        const API_SETTINGS = '../../api/settings/';
        let currentTab = 'asset-types';
        let allItems = { 'asset-type': [], 'asset-status': [] };

        const endpoints = {
            'asset-type': {
                get: API_BASE + 'get_asset_types.php',
                save: API_BASE + 'save_asset_type.php',
                delete: API_BASE + 'delete_asset_type.php',
                key: 'asset_types',
                listId: 'asset-types-list',
                label: window.t('asset-management.settings.label_asset_type')
            },
            'asset-status': {
                get: API_BASE + 'get_asset_status_types.php',
                save: API_BASE + 'save_asset_status_type.php',
                delete: API_BASE + 'delete_asset_status_type.php',
                key: 'asset_status_types',
                listId: 'asset-statuses-list',
                label: window.t('asset-management.settings.label_asset_status')
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            loadItems('asset-type');
            loadItems('asset-status');
            loadLocations();
            loadSuppliers();
            loadIntegrationSettings();
        });

        function switchTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
            if (tab === 'left-panel') loadSidebarMode();
        }

        // --- Left panel preference ------------------------------------
        // 'always' vs 'hover', stored per-analyst via user_preferences.
        // header.php reads the same key on every assets page and toggles
        // .sidebar-hover on .assets-container. Also editable under
        // System → Preferences.
        const SIDEBAR_MODE_KEY = 'asset_management_sidebar_mode';
        let sidebarModeLoaded = false;
        async function loadSidebarMode() {
            if (sidebarModeLoaded) return;
            sidebarModeLoaded = true;
            try {
                const r = await fetch('../../api/system/get_user_preference.php?key=' + encodeURIComponent(SIDEBAR_MODE_KEY), { credentials: 'same-origin' });
                const d = await r.json();
                const mode = (d.success && (d.value === 'always' || d.value === 'hover')) ? d.value : 'always';
                document.querySelectorAll('input[name="assetsSidebarMode"]').forEach(i => { i.checked = (i.value === mode); });
            } catch (e) {
                const first = document.querySelector('input[name="assetsSidebarMode"][value="always"]');
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
                if (d.success) showToast(window.t('asset-management.toast.saved'), 'success');
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
                        `<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">${window.t('asset-management.toast.error', { error: escapeHtml(data.error) })}</td></tr>`;
                }
            } catch (error) {
                console.error('Error loading ' + type + ':', error);
                document.getElementById(ep.listId).innerHTML =
                    `<tr><td colspan="5" style="text-align:center;padding:20px;color:#d13438;">${window.t('asset-management.settings.load_data_failed')}</td></tr>`;
            }
        }

        function renderItems(type, items) {
            const ep = endpoints[type];
            const tbody = document.getElementById(ep.listId);

            if (items.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px;color:#999;">${window.t('asset-management.settings.no_items')}</td></tr>`;
                return;
            }

            tbody.innerHTML = items.map(item => `
                <tr>
                    <td><strong>${escapeHtml(item.name)}</strong></td>
                    <td>${escapeHtml(item.description || '-')}</td>
                    <td>${item.display_order}</td>
                    <td><span class="status-badge status-${item.is_active ? 'active' : 'inactive'}">${item.is_active ? window.t('asset-management.status.active') : window.t('asset-management.status.inactive')}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('${type}', ${item.id})" title="${window.t('asset-management.common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('${type}', ${item.id}, '${escapeHtml(item.name)}')" title="${window.t('asset-management.common.delete')}">
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
            document.getElementById('modalTitle').textContent = window.t('asset-management.settings.add_kind', { kind: ep.label });
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

            document.getElementById('modalTitle').textContent = window.t('asset-management.settings.edit_kind', { kind: ep.label });
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
            if (!(await showConfirm({ title: window.t('asset-management.common.delete'), message: window.t('asset-management.settings.delete_item_confirm', { name: name, kind: ep.label.toLowerCase() }), okLabel: window.t('asset-management.common.delete'), okClass: 'danger' }))) return;

            try {
                const response = await fetch(ep.delete, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(window.t('asset-management.toast.deleted'), 'success');
                    loadItems(type);
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (error) {
                console.error('Error deleting:', error);
                showToast(window.t('asset-management.settings.delete_item_failed'), 'error');
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
                    showToast(window.t('asset-management.toast.saved'), 'success');
                    loadItems(type);
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (error) {
                console.error('Error saving:', error);
                showToast(window.t('asset-management.settings.save_item_failed'), 'error');
            }
        });

        let modalMouseDownTarget = null;
        document.getElementById('editModal').addEventListener('mousedown', function(e) {
            modalMouseDownTarget = e.target;
        });
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this && modalMouseDownTarget === this) closeModal();
        });

        // Integration settings (vCenter + InTune). Secret fields are left empty;
        // the placeholder tells the user one is already saved. The save endpoint
        // treats blank/asterisk values as "keep existing", so leaving them
        // alone preserves the stored secret.
        async function loadIntegrationSettings() {
            try {
                const response = await fetch(API_SETTINGS + 'get_system_settings.php');
                const data = await response.json();
                if (data.success && data.settings) {
                    document.getElementById('vcenterServer').value = data.settings.vcenter_server || '';
                    document.getElementById('vcenterUser').value = data.settings.vcenter_user || '';
                    const vcPwField = document.getElementById('vcenterPassword');
                    vcPwField.value = '';
                    vcPwField.placeholder = data.settings.vcenter_password
                        ? window.t('asset-management.settings.password_saved_placeholder')
                        : window.t('asset-management.settings.enter_password');

                    document.getElementById('intuneTenantId').value = data.settings.intune_tenant_id || '';
                    document.getElementById('intuneClientId').value = data.settings.intune_client_id || '';
                    const intSecField = document.getElementById('intuneClientSecret');
                    intSecField.value = '';
                    intSecField.placeholder = data.settings.intune_client_secret
                        ? window.t('asset-management.settings.secret_saved_placeholder')
                        : window.t('asset-management.settings.intune_secret_placeholder');
                    // verify_ssl: default to true unless explicitly stored as "0"
                    document.getElementById('intuneVerifySsl').checked = data.settings.intune_verify_ssl !== '0';
                    updateVerifySslWarning();
                    // batch size: default to 30 if not stored
                    const batch = parseInt(data.settings.intune_app_batch_size, 10);
                    document.getElementById('intuneAppBatchSize').value = (batch > 0 ? batch : 30);

                    // Warranty alert settings
                    document.getElementById('warrantySurface').value = data.settings.asset_warranty_surface || 'dashboard';
                    const wDays = parseInt(data.settings.asset_warranty_days, 10);
                    document.getElementById('warrantyDays').value = (wDays > 0 ? wDays : 30);
                }
            } catch (error) {
                console.error('Error loading settings:', error);
            }
        }

        async function saveWarrantySettings(e) {
            e.preventDefault();
            const btn = document.getElementById('warrantySaveBtn');
            btn.disabled = true; btn.textContent = window.t('asset-management.settings.saving');
            try {
                const res = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: {
                        asset_warranty_surface: document.getElementById('warrantySurface').value,
                        asset_warranty_days: String(Math.max(1, Math.min(3650, parseInt(document.getElementById('warrantyDays').value, 10) || 30)))
                    }})
                });
                const data = await res.json();
                if (data.success) {
                    // Resync the calendar so it immediately matches the new choice.
                    try { await fetch(API_BASE + 'sync_warranty_calendar.php', { method: 'POST' }); } catch (e) {}
                    showToast(window.t('asset-management.settings.warranty_saved'), 'success');
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (e) {
                showToast(window.t('asset-management.settings.save_settings_failed'), 'error');
            }
            btn.disabled = false; btn.textContent = window.t('asset-management.common.save');
        }

        async function saveVcenterSettings(e) {
            e.preventDefault();
            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = window.t('asset-management.settings.saving');

            try {
                const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        settings: {
                            vcenter_server: document.getElementById('vcenterServer').value.trim(),
                            vcenter_user: document.getElementById('vcenterUser').value.trim(),
                            vcenter_password: document.getElementById('vcenterPassword').value
                        }
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(window.t('asset-management.settings.settings_saved'), 'success');
                    loadIntegrationSettings();
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (error) {
                showToast(window.t('asset-management.settings.save_settings_failed'), 'error');
            }

            saveBtn.disabled = false;
            saveBtn.textContent = window.t('asset-management.common.save');
        }

        async function saveIntuneSettings(e) {
            e.preventDefault();
            const saveBtn = document.getElementById('intuneSaveBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = window.t('asset-management.settings.saving');

            try {
                const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        settings: {
                            intune_tenant_id: document.getElementById('intuneTenantId').value.trim(),
                            intune_client_id: document.getElementById('intuneClientId').value.trim(),
                            intune_client_secret: document.getElementById('intuneClientSecret').value,
                            intune_verify_ssl: document.getElementById('intuneVerifySsl').checked ? '1' : '0',
                            intune_app_batch_size: String(Math.max(1, Math.min(500, parseInt(document.getElementById('intuneAppBatchSize').value, 10) || 30)))
                        }
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(window.t('asset-management.settings.settings_saved'), 'success');
                    loadIntegrationSettings();
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (error) {
                showToast(window.t('asset-management.settings.save_settings_failed'), 'error');
            }

            saveBtn.disabled = false;
            saveBtn.textContent = window.t('asset-management.common.save');
        }

        function togglePassword() {
            const input = document.getElementById('vcenterPassword');
            const btn = input.nextElementSibling;
            if (input.type === 'password') { input.type = 'text'; btn.textContent = window.t('asset-management.settings.hide'); }
            else { input.type = 'password'; btn.textContent = window.t('asset-management.settings.show'); }
        }

        function updateVerifySslWarning() {
            const checked = document.getElementById('intuneVerifySsl').checked;
            document.getElementById('intuneVerifySslWarning').style.display = checked ? 'none' : '';
        }

        function toggleIntuneSecret() {
            const input = document.getElementById('intuneClientSecret');
            const btn = input.nextElementSibling;
            if (input.type === 'password') { input.type = 'text'; btn.textContent = window.t('asset-management.settings.hide'); }
            else { input.type = 'password'; btn.textContent = window.t('asset-management.settings.show'); }
        }

        // InTune sync
        const API_INTUNE = '../../api/intune/';
        let intunePollTimer = null;

        async function startIntuneSync() {
            const btn = document.getElementById('intuneSyncBtn');
            btn.disabled = true;
            btn.textContent = window.t('asset-management.settings.starting');
            showIntuneProgress(0, window.t('asset-management.settings.starting'), false);

            try {
                const response = await fetch(API_INTUNE + 'sync.php', { method: 'POST' });
                const data = await response.json();
                if (!data.success) {
                    showIntuneProgress(0, window.t('asset-management.toast.error', { error: data.error }), true);
                    btn.disabled = false;
                    btn.textContent = window.t('asset-management.settings.sync');
                    return;
                }
                pollIntuneStatus(data.id);
            } catch (e) {
                showIntuneProgress(0, window.t('asset-management.settings.sync_start_error'), true);
                btn.disabled = false;
                btn.textContent = window.t('asset-management.settings.sync');
            }
        }

        function pollIntuneStatus(jobId) {
            clearTimeout(intunePollTimer);
            const tick = async () => {
                try {
                    const response = await fetch(API_INTUNE + 'sync_status.php?id=' + encodeURIComponent(jobId));
                    const data = await response.json();
                    if (!data.success || !data.job) {
                        showIntuneProgress(0, window.t('asset-management.settings.status_unavailable'), true);
                        resetIntuneSyncButton();
                        return;
                    }
                    const job = data.job;
                    showIntuneProgress(job.percent, job.message || job.status, job.status === 'error');

                    if (job.status === 'running') {
                        intunePollTimer = setTimeout(tick, 1500);
                    } else {
                        resetIntuneSyncButton();
                        loadIntuneLastSync();
                    }
                } catch (e) {
                    showIntuneProgress(0, window.t('asset-management.settings.status_poll_error'), true);
                    resetIntuneSyncButton();
                }
            };
            tick();
        }

        function showIntuneProgress(percent, message, isError) {
            const wrap = document.getElementById('intuneSyncProgress');
            const fill = document.getElementById('intuneProgressFill');
            const meta = document.getElementById('intuneProgressMeta');
            wrap.style.display = '';
            wrap.classList.toggle('intune-error', !!isError);
            fill.style.width = (Math.max(0, Math.min(100, percent || 0))) + '%';
            meta.textContent = message || '';
        }

        function resetIntuneSyncButton() {
            const btn = document.getElementById('intuneSyncBtn');
            btn.disabled = false;
            btn.textContent = window.t('asset-management.settings.sync');
        }

        async function loadIntuneLastSync() {
            try {
                const response = await fetch(API_INTUNE + 'sync_status.php');
                const data = await response.json();
                const last = document.getElementById('intuneLastSync');
                if (data.success && data.job) {
                    const job = data.job;
                    if (job.status === 'running') {
                        last.textContent = '';
                        pollIntuneStatus(job.id);
                        return;
                    }
                    const when = job.finished_datetime || job.started_datetime;
                    const date = when ? new Date(when + 'Z').toLocaleString('en-GB') : '';
                    last.textContent = window.t('asset-management.settings.last_sync', { date: date, status: job.status });
                } else {
                    last.textContent = '';
                }
            } catch (e) {
                document.getElementById('intuneLastSync').textContent = '';
            }
        }

        // Pull last-sync info on first load
        document.addEventListener('DOMContentLoaded', loadIntuneLastSync);

        // ─── Software (app) sync ────────────────────────────────────────────
        let appSyncPollTimer = null;

        async function startAppSync() {
            const btn = document.getElementById('intuneAppSyncBtn');
            btn.disabled = true;
            btn.textContent = window.t('asset-management.settings.starting');
            showAppSyncProgress(0, window.t('asset-management.settings.starting'), false);

            try {
                const response = await fetch(API_INTUNE + 'create_app_sync_job.php', { method: 'POST' });
                const data = await response.json();
                if (!data.success) {
                    showAppSyncProgress(0, window.t('asset-management.toast.error', { error: data.error }), true);
                    resetAppSyncButton();
                    return;
                }
                const queuedMsg = data.reused ? window.t('asset-management.settings.resuming_job') : window.t('asset-management.settings.job_queued');
                showAppSyncProgress(0, window.t('asset-management.settings.job_for_assets', { msg: queuedMsg, count: data.asset_count }), false);
                pollAppSyncStatus(data.id);
            } catch (e) {
                showAppSyncProgress(0, window.t('asset-management.settings.app_sync_start_error'), true);
                resetAppSyncButton();
            }
        }

        function pollAppSyncStatus(jobId) {
            clearTimeout(appSyncPollTimer);
            const tick = async () => {
                try {
                    const response = await fetch(API_INTUNE + 'app_sync_job_status.php?id=' + encodeURIComponent(jobId));
                    const data = await response.json();
                    if (!data.success || !data.job) {
                        showAppSyncProgress(0, window.t('asset-management.settings.status_unavailable'), true);
                        resetAppSyncButton();
                        return;
                    }
                    const job = data.job;
                    const r = job.rollup || {};
                    const summary = window.t('asset-management.settings.sync_summary_done', { processed: job.processed, total: job.total }) +
                                    (job.failed > 0 ? window.t('asset-management.settings.sync_summary_failed', { failed: job.failed }) : '') +
                                    ((r.obsolete || 0) > 0 ? window.t('asset-management.settings.sync_summary_obsolete', { obsolete: r.obsolete }) : '');
                    const message = job.message ? `${job.message} (${summary})` : summary;
                    showAppSyncProgress(job.percent, message, job.status === 'error');

                    if (job.status === 'pending' || job.status === 'running') {
                        appSyncPollTimer = setTimeout(tick, 2000);
                    } else {
                        resetAppSyncButton();
                        loadAppSyncJobs();
                        loadIntuneFreshness();
                    }
                } catch (e) {
                    showAppSyncProgress(0, window.t('asset-management.settings.status_poll_error'), true);
                    resetAppSyncButton();
                }
            };
            tick();
        }

        function showAppSyncProgress(percent, message, isError) {
            const wrap = document.getElementById('intuneAppSyncProgress');
            const fill = document.getElementById('intuneAppProgressFill');
            const meta = document.getElementById('intuneAppProgressMeta');
            wrap.style.display = '';
            wrap.classList.toggle('intune-error', !!isError);
            fill.style.width = (Math.max(0, Math.min(100, percent || 0))) + '%';
            meta.textContent = message || '';
        }

        function resetAppSyncButton() {
            const btn = document.getElementById('intuneAppSyncBtn');
            btn.disabled = false;
            btn.textContent = window.t('asset-management.settings.sync_software');
        }

        async function loadAppSyncJobs() {
            try {
                const response = await fetch(API_INTUNE + 'list_app_sync_jobs.php');
                const data = await response.json();
                const list = document.getElementById('intuneAppJobsList');
                const eligible = document.getElementById('intuneAppEligible');

                if (!data.success) {
                    list.innerHTML = '';
                    eligible.textContent = '';
                    return;
                }

                eligible.textContent = data.eligible_assets > 0
                    ? window.t('asset-management.settings.eligible_for_sync', { count: data.eligible_assets })
                    : window.t('asset-management.settings.no_eligible_assets');

                if (!data.jobs || data.jobs.length === 0) {
                    list.innerHTML = `<div class="form-hint" style="margin-top: 12px;">${window.t('asset-management.settings.no_app_sync_jobs')}</div>`;
                    return;
                }

                // If the latest job is still mid-flight, resume polling
                const latest = data.jobs[0];
                if (latest && (latest.status === 'pending' || latest.status === 'running')) {
                    pollAppSyncStatus(latest.id);
                }

                list.innerHTML = `
                    <table class="intune-jobs-table">
                        <thead>
                            <tr><th>${window.t('asset-management.settings.job_col_job')}</th><th>${window.t('asset-management.field.status')}</th><th>${window.t('asset-management.settings.job_col_started')}</th><th>${window.t('asset-management.settings.job_col_finished')}</th><th>${window.t('asset-management.settings.job_col_result')}</th></tr>
                        </thead>
                        <tbody>
                            ${data.jobs.map(j => `
                                <tr>
                                    <td>#${j.id}</td>
                                    <td><span class="intune-job-status ${escapeHtml(j.status)}">${escapeHtml(j.status)}</span></td>
                                    <td>${j.started_datetime ? new Date(j.started_datetime + 'Z').toLocaleString('en-GB') : '-'}</td>
                                    <td>${j.finished_datetime ? new Date(j.finished_datetime + 'Z').toLocaleString('en-GB') : '-'}</td>
                                    <td>${j.processed}/${j.total}${j.failed > 0 ? ` ${window.t('asset-management.settings.failed_count', { failed: j.failed })}` : ''}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>`;
            } catch (e) {
                console.error('Error loading app sync jobs:', e);
            }
        }

        document.addEventListener('DOMContentLoaded', loadAppSyncJobs);
        document.addEventListener('DOMContentLoaded', loadIntuneFreshness);

        // ─── Inventory freshness chart ──────────────────────────────────────
        let intuneFreshnessChart = null;

        async function loadIntuneFreshness() {
            try {
                const response = await fetch(API_INTUNE + 'app_sync_freshness.php');
                const data = await response.json();
                if (!data.success) return;

                const wrap = document.getElementById('intuneFreshnessWrap');
                const buckets = data.buckets || {};
                const labels = ['<1d', '1d', '2d', '3d', '4d', '5d', '6d', '7+d', 'never'];
                const values = labels.map(k => buckets[k] || 0);
                const total = values.reduce((s, n) => s + n, 0);

                // Hide chart entirely when there's nothing to show (e.g. no
                // Intune-eligible assets — no point rendering an empty chart).
                if (total === 0) {
                    wrap.style.display = 'none';
                    return;
                }
                wrap.style.display = '';

                // Fresh = green, ageing = amber gradient, never = red
                const colours = ['#107c10', '#3fa83f', '#76c043', '#a8c93a', '#d4c537',
                                 '#e6a82e', '#e07a26', '#d65420', '#d13438'];

                const ctx = document.getElementById('intuneFreshnessChart').getContext('2d');
                if (intuneFreshnessChart) {
                    intuneFreshnessChart.data.datasets[0].data = values;
                    intuneFreshnessChart.update();
                    return;
                }

                intuneFreshnessChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: window.t('asset-management.settings.assets_label'),
                            data: values,
                            backgroundColor: colours,
                            borderWidth: 0,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => window.t('asset-management.settings.asset_count', { count: ctx.parsed.y }),
                                },
                            },
                        },
                        scales: {
                            x: { grid: { display: false } },
                            y: { beginAtZero: true, ticks: { precision: 0 } },
                        },
                    },
                });
            } catch (e) {
                console.error('Error loading freshness chart:', e);
            }
        }

        // ─── Locations (arbitrary-depth tree) ───────────────────────────────
        let allLocations = [];
        const collapsedLocations = new Set();

        async function loadLocations() {
            const tree = document.getElementById('locations-tree');
            try {
                const res = await fetch(API_BASE + 'get_asset_locations.php');
                const data = await res.json();
                if (!data.success) {
                    tree.innerHTML = `<div class="loc-empty" style="color:#d13438;">${window.t('asset-management.toast.error', { error: escapeHtml(data.error) })}</div>`;
                    return;
                }
                allLocations = data.locations || [];
                renderLocationTree();
            } catch (e) {
                console.error('Error loading locations:', e);
                tree.innerHTML = `<div class="loc-empty" style="color:#d13438;">${window.t('asset-management.settings.locations_load_failed')}</div>`;
            }
        }

        function locationChildren(parentId) {
            return allLocations.filter(l => l.parent_id === parentId);
        }

        function renderLocationTree() {
            const tree = document.getElementById('locations-tree');
            if (allLocations.length === 0) {
                tree.innerHTML = `<div class="loc-empty">${window.t('asset-management.settings.no_locations')}</div>`;
                return;
            }
            const roots = locationChildren(null);
            tree.innerHTML = '<ul>' + roots.map(r => renderLocationNode(r)).join('') + '</ul>';
        }

        function renderLocationNode(loc) {
            const kids = locationChildren(loc.id);
            const hasKids = kids.length > 0;
            const collapsed = collapsedLocations.has(loc.id);
            const caretClass = hasKids ? (collapsed ? 'collapsed' : '') : 'leaf';
            const count = hasKids ? `<span class="loc-count">${kids.length}</span>` : '';
            const row = `
                <div class="loc-row">
                    <span class="loc-caret ${caretClass}" onclick="toggleLocation(${loc.id})">&#9662;</span>
                    <span class="loc-name">${escapeHtml(loc.name)}${count}</span>
                    <span class="loc-actions">
                        <button class="action-btn" title="${window.t('asset-management.settings.add_sublocation')}" onclick="openAddLocation(${loc.id})">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </button>
                        <button class="action-btn" title="${window.t('asset-management.common.edit')}" onclick="editLocation(${loc.id})">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" title="${window.t('asset-management.common.delete')}" onclick="deleteLocation(${loc.id})">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </span>
                </div>`;
            const childrenHtml = hasKids
                ? `<div class="loc-children ${collapsed ? 'collapsed' : ''}"><ul>${kids.map(k => renderLocationNode(k)).join('')}</ul></div>`
                : '';
            return `<li class="loc-node">${row}${childrenHtml}</li>`;
        }

        function toggleLocation(id) {
            if (collapsedLocations.has(id)) collapsedLocations.delete(id);
            else collapsedLocations.add(id);
            renderLocationTree();
        }

        // Indented <option>s for the parent select. When editing, exclude the
        // node itself and its whole subtree (a node can't sit under itself).
        function buildParentOptions(excludeId) {
            const exclude = new Set();
            if (excludeId != null) {
                const stack = [excludeId];
                while (stack.length) {
                    const cur = stack.pop();
                    exclude.add(cur);
                    locationChildren(cur).forEach(c => stack.push(c.id));
                }
            }
            const opts = [`<option value="">${window.t('asset-management.settings.none_top_level')}</option>`];
            const walk = (parentId, depth) => {
                locationChildren(parentId).forEach(loc => {
                    if (!exclude.has(loc.id)) {
                        opts.push(`<option value="${loc.id}">${'   '.repeat(depth)}${escapeHtml(loc.name)}</option>`);
                        walk(loc.id, depth + 1);
                    }
                });
            };
            walk(null, 0);
            return opts.join('');
        }

        function openAddLocation(parentId) {
            document.getElementById('locationModalTitle').textContent = window.t('asset-management.settings.add_location');
            document.getElementById('locationId').value = '';
            document.getElementById('locationName').value = '';
            const sel = document.getElementById('locationParent');
            sel.innerHTML = buildParentOptions(null);
            sel.value = parentId != null ? String(parentId) : '';
            document.getElementById('locationModal').classList.add('active');
            setTimeout(() => document.getElementById('locationName').focus(), 50);
        }

        function editLocation(id) {
            const loc = allLocations.find(l => l.id === id);
            if (!loc) return;
            document.getElementById('locationModalTitle').textContent = window.t('asset-management.settings.edit_location');
            document.getElementById('locationId').value = loc.id;
            document.getElementById('locationName').value = loc.name;
            const sel = document.getElementById('locationParent');
            sel.innerHTML = buildParentOptions(loc.id);
            sel.value = loc.parent_id != null ? String(loc.parent_id) : '';
            document.getElementById('locationModal').classList.add('active');
            setTimeout(() => document.getElementById('locationName').focus(), 50);
        }

        function closeLocationModal() {
            document.getElementById('locationModal').classList.remove('active');
        }

        async function deleteLocation(id) {
            const loc = allLocations.find(l => l.id === id);
            if (!loc) return;
            if (locationChildren(id).length > 0) {
                showToast(window.t('asset-management.settings.location_has_children'), 'error');
                return;
            }
            if (!(await showConfirm({ title: window.t('asset-management.common.delete'), message: window.t('asset-management.settings.delete_location_confirm', { name: loc.name }), okLabel: window.t('asset-management.common.delete'), okClass: 'danger' }))) return;
            try {
                const res = await fetch(API_BASE + 'delete_asset_location.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (data.success) { showToast(window.t('asset-management.toast.deleted'), 'success'); loadLocations(); }
                else showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
            } catch (e) { showToast(window.t('asset-management.settings.delete_location_failed'), 'error'); }
        }

        document.getElementById('locationForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const id = document.getElementById('locationId').value;
            const payload = {
                name: document.getElementById('locationName').value.trim(),
                parent_id: document.getElementById('locationParent').value || null
            };
            if (!payload.name) { showToast(window.t('asset-management.settings.name_required'), 'error'); return; }
            if (id) payload.id = parseInt(id);
            try {
                const res = await fetch(API_BASE + 'save_asset_location.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) { closeLocationModal(); showToast(window.t('asset-management.toast.saved'), 'success'); loadLocations(); }
                else showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
            } catch (e) { showToast(window.t('asset-management.settings.save_location_failed'), 'error'); }
        });

        let locationMouseDownTarget = null;
        document.getElementById('locationModal').addEventListener('mousedown', function(e) { locationMouseDownTarget = e.target; });
        document.getElementById('locationModal').addEventListener('click', function(e) {
            if (e.target === this && locationMouseDownTarget === this) closeLocationModal();
        });

        // ─── Suppliers (shared registry, flagged for assets) ────────────────
        let allSuppliers = [];

        async function loadSuppliers() {
            const tbody = document.getElementById('suppliers-list');
            try {
                const res = await fetch(API_BASE + 'search_suppliers.php');
                const data = await res.json();
                if (!data.success) {
                    tbody.innerHTML = `<tr><td colspan="2" style="text-align:center;padding:20px;color:#d13438;">${window.t('asset-management.toast.error', { error: escapeHtml(data.error) })}</td></tr>`;
                    return;
                }
                allSuppliers = data.suppliers || [];
                renderSupplierList();
            } catch (e) {
                console.error('Error loading suppliers:', e);
                tbody.innerHTML = `<tr><td colspan="2" style="text-align:center;padding:20px;color:#d13438;">${window.t('asset-management.settings.suppliers_load_failed')}</td></tr>`;
            }
        }

        function renderSupplierList() {
            const tbody = document.getElementById('suppliers-list');
            const term = (document.getElementById('supplierSearch').value || '').trim().toLowerCase();
            const rows = allSuppliers.filter(s =>
                !term || (s.name || '').toLowerCase().includes(term) || (s.legal_name || '').toLowerCase().includes(term)
            );
            if (rows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="2" style="text-align:center;padding:20px;color:#999;">${
                    allSuppliers.length === 0 ? window.t('asset-management.settings.no_suppliers') : window.t('asset-management.settings.no_supplier_match')}</td></tr>`;
                return;
            }
            tbody.innerHTML = rows.map(s => {
                const alt = (s.trading_name && s.legal_name && s.trading_name !== s.legal_name)
                    ? ` <span style="color:#999;font-size:12px;">(${escapeHtml(s.legal_name)})</span>` : '';
                const inactive = !s.is_active ? ` <span class="status-badge status-inactive">${window.t('asset-management.status.inactive')}</span>` : '';
                return `
                    <tr>
                        <td><strong>${escapeHtml(s.name)}</strong>${alt}${inactive}</td>
                        <td>
                            <label class="toggle-label" style="margin:0;">
                                <span class="toggle-switch">
                                    <input type="checkbox" ${s.supplies_assets ? 'checked' : ''} onchange="toggleSupplierAssets(${s.id}, this.checked)">
                                    <span class="toggle-slider"></span>
                                </span>
                            </label>
                        </td>
                    </tr>`;
            }).join('');
        }

        async function toggleSupplierAssets(id, checked) {
            try {
                const res = await fetch(API_BASE + 'toggle_supplier_assets.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, supplies_assets: checked ? 1 : 0 })
                });
                const data = await res.json();
                if (data.success) {
                    const s = allSuppliers.find(x => x.id === id);
                    if (s) s.supplies_assets = checked ? 1 : 0;
                    showToast(checked ? window.t('asset-management.settings.supplier_enabled') : window.t('asset-management.settings.supplier_disabled'), 'success');
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                    renderSupplierList();
                }
            } catch (e) {
                showToast(window.t('asset-management.settings.update_supplier_failed'), 'error');
                renderSupplierList();
            }
        }

        async function quickAddSupplier() {
            const input = document.getElementById('supplierQuickAdd');
            const name = input.value.trim();
            if (!name) { showToast(window.t('asset-management.settings.enter_supplier_name'), 'error'); return; }
            try {
                const res = await fetch(API_BASE + 'quick_add_supplier.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name })
                });
                const data = await res.json();
                if (data.success) {
                    input.value = '';
                    showToast(data.existing ? window.t('asset-management.settings.supplier_existed') : window.t('asset-management.settings.supplier_added'), 'success');
                    loadSuppliers();
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (e) {
                showToast(window.t('asset-management.settings.add_supplier_failed'), 'error');
            }
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
