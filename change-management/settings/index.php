<?php
/**
 * Change Management Settings - Configure module behaviour
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

require_once '../../includes/settings_manifest.php';
requireModuleAccess('changes');

// RBAC Layer 2: only the tabs this analyst may see are rendered — a tab they lack is
// never emitted, so there is nothing to un-hide. Administrators hold everything.
$settingsManifest = settingsManifestFor('changes');
$visibleTabs      = settingsVisibleTabs(connectToDatabase(), (int) $_SESSION['analyst_id'], $settingsManifest);
$activeTabId      = settingsFirstTabId($visibleTabs);

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'change-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('changes', t('change-management.nav.settings'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <style>
        .container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: none;
            margin: 0;
            padding: 16px 30px 24px;
        }

        /* Teal theme for tabs */
        .tab:hover { color: var(--cm-accent, #00897b); }
        .tab.active { color: var(--cm-accent, #00897b); border-bottom-color: var(--cm-accent, #00897b); }

        .section-header h2 {
            margin: 0 0 8px;
            font-size: 18px;
            color: var(--text, #333);
        }

        /* Form fields tab: section cards + draggable field rows. */
        .field-toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .field-toolbar .field-save-status {
            font-size: 12px;
            color: var(--success-accent, #16a34a);
            opacity: 0;
            transition: opacity 0.2s;
        }
        .field-toolbar .field-save-status.visible {
            opacity: 1;
        }
        .section-card {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 6px;
            margin-bottom: 12px;
            box-shadow: 0 1px 2px var(--shadow, rgba(0, 0, 0, 0.03));
        }
        .section-card.drop-target-section {
            border-color: var(--cm-accent, #00897b);
            box-shadow: 0 0 0 2px rgba(0, 137, 123, 0.15);
        }
        .section-card.dragging {
            opacity: 0.4;
        }
        .section-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-soft, #f0f0f0);
            background: var(--surface-2, #fafafa);
            border-radius: 6px 6px 0 0;
        }
        .section-card-header .drag-handle {
            cursor: grab;
            color: var(--text-faint, #999);
            font-size: 14px;
            user-select: none;
            padding: 4px;
        }
        .section-card-header .drag-handle:active { cursor: grabbing; }
        .section-name-input {
            flex: 1;
            font-size: 15px;
            font-weight: 600;
            color: var(--cm-accent, #00897b);
            border: 1px solid transparent;
            background: transparent;
            padding: 6px 10px;
            border-radius: 4px;
        }
        .section-name-input:hover {
            border-color: var(--border, #e0e0e0);
            background: var(--surface, #fff);
        }
        .section-name-input:focus {
            outline: none;
            border-color: var(--cm-accent, #00897b);
            background: var(--surface, #fff);
            box-shadow: 0 0 0 2px rgba(0, 137, 123, 0.1);
        }
        .section-delete-btn {
            background: none;
            border: 1px solid transparent;
            color: var(--text-faint, #999);
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 16px;
            line-height: 1;
        }
        .section-delete-btn:hover {
            color: var(--danger-text, #c62828);
            border-color: var(--danger-bg, #fce4e4);
            background: var(--danger-bg, #fff5f5);
        }
        .section-fields {
            padding: 4px 14px 8px;
            min-height: 36px;
        }
        .section-fields:empty::after {
            content: 'Drop fields here';
            display: block;
            padding: 16px;
            text-align: center;
            color: var(--text-faint, #aaa);
            font-style: italic;
            font-size: 13px;
        }
        .field-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 6px;
            border-bottom: 1px solid var(--border-soft, #f3f3f3);
        }
        .field-row:last-child {
            border-bottom: none;
        }
        .field-row.dragging {
            opacity: 0.4;
        }
        .field-row.drop-target-field {
            border-top: 2px solid var(--cm-accent, #00897b);
        }
        .field-row .drag-handle {
            cursor: grab;
            color: var(--text-faint, #bbb);
            font-size: 13px;
            user-select: none;
            padding: 2px 4px;
        }
        .field-row .drag-handle:active { cursor: grabbing; }
        .field-row-label {
            flex: 1;
            font-size: 14px;
            color: var(--text, #333);
        }
        .unplaced-fields {
            margin-top: 20px;
            border: 1px dashed var(--warning-border, #e0a800);
            background: var(--warning-bg, #fffbe6);
            border-radius: 6px;
            padding: 10px 14px;
        }
        .unplaced-fields h4 {
            margin: 0 0 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--warning-text, #856404);
        }
        .unplaced-fields p.hint {
            margin: 0 0 8px;
            font-size: 12px;
            color: var(--warning-text, #856404);
        }
        .add-section-btn {
            background: var(--cm-accent, #00897b);
            color: var(--cm-on-accent, white);
            border: none;
            padding: 8px 14px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
        }
        .add-section-btn:hover {
            background: var(--cm-accent-hover, #00695c);
        }

        /* Toggle switch — base styles in inbox.css; just pin the accent. */
        body { --accent: var(--cm-accent, #00897b); }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border, #e0e0e0);
        }

        .btn {
            padding: 10px 20px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .btn-primary { background: var(--cm-accent, #00897b); color: var(--cm-on-accent, white); }
        .btn-primary:hover { background: var(--cm-accent-hover, #00695c); }
        .btn-secondary { background: var(--border, #e0e0e0); color: var(--text, #333); }
        .btn-secondary:hover { background: var(--surface-hover, #bdbdbd); }

        /* Lookup tab tables */
        .lookup-table { width: 100%; border-collapse: collapse; }
        .lookup-table th, .lookup-table td { padding: 10px 8px; text-align: left; border-bottom: 1px solid var(--border-soft, #f0f0f0); font-size: 14px; }
        .lookup-table th { font-weight: 600; color: var(--text-muted, #666); background: var(--surface-2, #fafafa); }
        .approval-rules { max-width: 760px; }
        .ar-group { border: 1px solid var(--border, #e0e0e0); border-radius: 8px; padding: 14px 16px; margin: 0 0 14px 0; }
        .ar-group legend { font-weight: 600; padding: 0 6px; color: var(--text, #333); }
        .ar-choice { display: block; padding: 8px 10px; border: 1px solid var(--border-soft, #eee); border-radius: 6px; margin-bottom: 8px; cursor: pointer; }
        .ar-help { display: block; font-size: 12px; color: var(--text-muted, #777); margin-top: 4px; }
        /* Risk policy tab (#246) */
        .rp-rows { display: flex; flex-direction: column; gap: 8px; margin: 10px 0; }
        .rp-row { display: flex; align-items: flex-start; gap: 8px; }
        .rp-n { width: 20px; padding-top: 9px; text-align: right; color: var(--text-dim, #888); font-weight: 600; flex-shrink: 0; }
        .rp-label { width: 200px; flex-shrink: 0; }
        .rp-criteria { flex: 1; min-width: 0; resize: vertical; font-family: inherit; font-size: 13px; }
        .rp-colour { width: 44px; height: 36px; padding: 2px; border: 1px solid var(--border, #ddd); border-radius: 6px; background: var(--surface, #fff); }
        .rp-remove { border: none; background: none; font-size: 20px; line-height: 1; cursor: pointer; color: var(--text-muted, #777); padding: 6px; }
        .rp-remove:hover { color: var(--danger-text, #c62828); }
        .rp-warn { margin-top: 10px; }
        .rp-matrix-scroll { overflow-x: auto; margin-top: 10px; }
        .rp-matrix { border-collapse: separate; border-spacing: 4px; }
        .rp-matrix th { font-size: 12px; font-weight: 600; color: var(--text-muted, #666); padding: 2px 6px; text-align: center; max-width: 120px; }
        .rp-matrix th[scope="row"] { text-align: right; }
        .rp-cell { min-width: 110px; padding: 6px; border: 2px solid var(--border, #ddd); border-radius: 6px; background: var(--surface, #fff); color: var(--text, #333); }
        .rp-cell.is-empty { border-style: dashed; border-color: var(--danger-text, #c62828); }
        .rp-history { border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        .rp-history td { padding: 4px 12px 4px 0; color: var(--text-muted, #666); }
        .rp-history tr.is-current td { color: var(--text, #333); font-weight: 600; }
        .rp-actions { display: flex; gap: 10px; }
        .cm-risk-older { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 6px 0; padding: 8px 10px; border-radius: 6px; background: var(--surface-2, #f5f5f7); color: var(--text-muted, #555); font-size: 13px; }
        .cm-risk-older[hidden] { display: none; }
        @media (max-width: 700px) { .rp-row { flex-wrap: wrap; } .rp-label { width: calc(100% - 70px); } .rp-criteria { flex-basis: 100%; margin-left: 28px; } }
        .ar-choice .ar-help { margin-left: 22px; }
        .ar-sub { font-size: 13px; font-weight: 500; margin: 12px 0 6px; color: var(--text, #333); }
        .ar-ticks { display: flex; flex-wrap: wrap; gap: 6px 18px; }
        .ar-ticks-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); margin-top: 8px; }
        .ar-warning { margin-top: 8px; padding: 10px 12px; border-radius: 6px; background: var(--warning-bg, #fff8e1); border: 1px solid var(--warning-border, #ffcc80); color: var(--warning-text, #8a5a00); font-size: 13px; }
        .ar-enforced { display: none; font-size: 12px; color: var(--warning-text, #8a5a00); margin-top: 6px; }
        .approval-rules.is-enforce .ar-record-only select { opacity: .5; pointer-events: none; }
        .approval-rules.is-enforce .ar-record-only .ar-enforced { display: block; }
        .ar-actions { margin-top: 6px; }
        .tpl-body { padding: 16px 20px; max-height: 70vh; overflow-y: auto; }
        .tpl-body textarea, .tpl-body input[type=text] { width: 100%; box-sizing: border-box; }
        .tpl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0 12px; }
        .tpl-version { font-size: 13px; padding: 6px 0; border-bottom: 1px solid var(--border-soft, #eee); display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .tpl-version input { flex: 1; min-width: 200px; }
        .stage-unset { display: inline-block; padding: 2px 8px; border-radius: 10px; background: var(--warning-bg, #fff8e1); border: 1px solid var(--warning-border, #ffcc80); color: var(--warning-text, #8a5a00); font-size: 11px; font-weight: 600; cursor: help; }
        .badge-yes { display: inline-block; padding: 2px 8px; border-radius: 10px; background: var(--cm-accent-soft, #e0f2f1); color: var(--cm-accent-hover, #00695c); font-size: 11px; font-weight: 600; }
        .badge-no  { color: var(--text-faint, #999); }
        /* Active/Inactive uses the shared .status-badge / .status-active / .status-inactive classes from inbox.css (canonical green/red). */
        .swatch { display: inline-block; width: 18px; height: 18px; border-radius: 3px; vertical-align: middle; border: 1px solid var(--border, #ddd); margin-right: 6px; }
        .action-btn { background: none; border: none; cursor: pointer; padding: 4px; color: var(--text-muted, #666); display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; }
        /* Force the Actions column to size to its content (width: 1%) and
           never wrap the icon buttons. width:1% + white-space:nowrap is the
           classic trick to collapse a table cell to exactly its content
           width regardless of how wide the table is. */
        .lookup-table td:last-child,
        .lookup-table th:last-child {
            white-space: nowrap;
            width: 1%;
        }
        .action-btn:hover { color: var(--cm-accent, #00897b); }
        .action-btn.delete:hover { color: var(--danger-text, #c62828); }
        .add-btn { background: var(--cm-accent, #00897b); color: var(--cm-on-accent, white); padding: 8px 16px; border: none; border-radius: 4px; font-size: 13px; cursor: pointer; }
        .add-btn:hover { background: var(--cm-accent-hover, #00695c); }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }

        /* Modal sizing — base modal / form CSS lives in inbox.css. */
        .modal-content { padding: 20px; max-width: 500px; }
        .modal-header { padding: 0; border-bottom: none; margin-bottom: 20px; font-size: 20px; font-weight: 600; color: var(--text, #333); }
        .modal-actions { margin-top: 20px; }
    </style>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/mobile.css?v=196">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
</head>
<body data-mobile-page="settings">
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <?php renderSettingsTabBar($visibleTabs, $activeTabId); ?>

        <?php if (settingsTabVisible($visibleTabs, 'fields')): ?>
        <!-- Form Fields Tab -->
        <div class="tab-content<?php echo $activeTabId === 'fields' ? ' active' : ''; ?>" id="fields-tab" data-capability="<?php echo capKey(Cap::CHANGES_FIELDS); ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('change-management.settings.fields_heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('change-management.settings.fields_intro')); ?></p>

            <div class="field-toolbar">
                <button type="button" class="add-section-btn" onclick="addSection()"><?php echo htmlspecialchars(t('change-management.settings.add_section')); ?></button>
                <span class="field-save-status" id="fieldSaveStatus"><?php echo htmlspecialchars(t('change-management.settings.saved')); ?></span>
            </div>

            <div id="fieldSettings"></div>
        </div>

        <!-- Statuses Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'statuses')): ?>
        <div class="tab-content<?php echo $activeTabId === 'statuses' ? ' active' : ''; ?>" id="statuses-tab" data-capability="<?php echo capKey(Cap::CHANGES_STATUSES); ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('change-management.settings.statuses_heading')); ?></h2>
                <button class="add-btn" onclick="openLookupModal('status')"><?php echo htmlspecialchars(t('change-management.settings.add')); ?></button>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo t('change-management.settings.statuses_intro'); ?></p>
            <table class="lookup-table">
                <thead><tr><th><?php echo htmlspecialchars(t('change-management.settings.col_name')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_stage')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_colour')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_closed')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_default')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_order')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_status')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_actions')); ?></th></tr></thead>
                <tbody id="statuses-list"><tr><td colspan="8" style="text-align:center;"><?php echo htmlspecialchars(t('change-management.settings.loading')); ?></td></tr></tbody>
            </table>
        </div>

        <!-- Priorities Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'priorities')): ?>
        <div class="tab-content<?php echo $activeTabId === 'priorities' ? ' active' : ''; ?>" id="priorities-tab" data-capability="<?php echo capKey(Cap::CHANGES_PRIORITIES); ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('change-management.settings.priorities_heading')); ?></h2>
                <button class="add-btn" onclick="openLookupModal('priority')"><?php echo htmlspecialchars(t('change-management.settings.add')); ?></button>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('change-management.settings.priorities_intro')); ?></p>
            <table class="lookup-table">
                <thead><tr><th><?php echo htmlspecialchars(t('change-management.settings.col_name')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_colour')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_default')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_order')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_status')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_actions')); ?></th></tr></thead>
                <tbody id="priorities-list"><tr><td colspan="6" style="text-align:center;"><?php echo htmlspecialchars(t('change-management.settings.loading')); ?></td></tr></tbody>
            </table>
        </div>

        <!-- Types Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'types')): ?>
        <div class="tab-content<?php echo $activeTabId === 'types' ? ' active' : ''; ?>" id="types-tab" data-capability="<?php echo capKey(Cap::CHANGES_TYPES); ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('change-management.settings.types_heading')); ?></h2>
                <button class="add-btn" onclick="openLookupModal('type')"><?php echo htmlspecialchars(t('change-management.settings.add')); ?></button>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('change-management.settings.types_intro')); ?></p>
            <table class="lookup-table">
                <thead><tr><th><?php echo htmlspecialchars(t('change-management.settings.col_name')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_route')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_colour')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_default')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_order')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_status')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_actions')); ?></th></tr></thead>
                <tbody id="types-list"><tr><td colspan="7" style="text-align:center;"><?php echo htmlspecialchars(t('change-management.settings.loading')); ?></td></tr></tbody>
            </table>
        </div>

        <!-- Impacts Tab -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'impacts')): ?>
        <div class="tab-content<?php echo $activeTabId === 'impacts' ? ' active' : ''; ?>" id="impacts-tab" data-capability="<?php echo capKey(Cap::CHANGES_IMPACTS); ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('change-management.settings.impacts_heading')); ?></h2>
                <button class="add-btn" onclick="openLookupModal('impact')"><?php echo htmlspecialchars(t('change-management.settings.add')); ?></button>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('change-management.settings.impacts_intro')); ?></p>
            <table class="lookup-table">
                <thead><tr><th><?php echo htmlspecialchars(t('change-management.settings.col_name')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_colour')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_default')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_order')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_status')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_actions')); ?></th></tr></thead>
                <tbody id="impacts-list"><tr><td colspan="6" style="text-align:center;"><?php echo htmlspecialchars(t('change-management.settings.loading')); ?></td></tr></tbody>
            </table>
        </div>

        <!-- Left panel tab — per-analyst preference -->
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'risk-policy')): ?>
        <!-- Risk policy (#246) - get_risk_policy / save_risk_policy, drawn by the "Risk policy" script below. -->
        <div class="tab-content<?php echo $activeTabId === 'risk-policy' ? ' active' : ''; ?>" id="risk-policy-tab" data-capability="<?php echo capKey(Cap::CHANGES_RISK_POLICY); ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('change-management.risk_policy.heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('change-management.risk_policy.intro')); ?></p>

            <fieldset class="ar-group">
                <legend><?php echo htmlspecialchars(t('change-management.risk_policy.in_use')); ?></legend>
                <div id="rpInUse"></div>
                <table class="rp-history" id="rpHistory"></table>
            </fieldset>

            <form id="riskPolicyForm" autocomplete="off" onsubmit="event.preventDefault(); saveRiskPolicy();">
                <fieldset class="ar-group">
                    <legend><?php echo htmlspecialchars(t('change-management.risk_policy.name')); ?></legend>
                    <input type="text" id="rpName" class="form-input" maxlength="100" style="max-width: 420px;">
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.risk_policy.name_help')); ?></span>
                </fieldset>

                <?php foreach (['likelihood', 'impact'] as $axis): ?>
                <fieldset class="ar-group">
                    <legend><?php echo htmlspecialchars(t('change-management.risk_policy.levels_' . $axis)); ?></legend>
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.risk_policy.levels_help')); ?></span>
                    <div class="rp-rows" id="rp_<?php echo $axis; ?>"></div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="rpAdd('<?php echo $axis; ?>')"><?php echo htmlspecialchars(t('change-management.risk_policy.add')); ?></button>
                </fieldset>
                <?php endforeach; ?>

                <fieldset class="ar-group">
                    <legend><?php echo htmlspecialchars(t('change-management.risk_policy.results')); ?></legend>
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.risk_policy.results_help')); ?></span>
                    <div class="rp-rows" id="rp_results"></div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="rpAdd('results')"><?php echo htmlspecialchars(t('change-management.risk_policy.add')); ?></button>
                    <span class="ar-help rp-warn"><?php echo htmlspecialchars(t('change-management.risk_policy.results_workflows')); ?></span>
                </fieldset>

                <fieldset class="ar-group">
                    <legend><?php echo htmlspecialchars(t('change-management.risk_policy.matrix')); ?></legend>
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.risk_policy.matrix_help')); ?></span>
                    <div class="rp-matrix-scroll"><table class="rp-matrix" id="rpMatrix"></table></div>
                </fieldset>

                <div class="rp-actions">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('change-management.settings.modal_save')); ?></button>
                    <button type="button" class="btn btn-secondary" id="rpBuiltinBtn" onclick="useBuiltinRiskPolicy()"><?php echo htmlspecialchars(t('change-management.risk_policy.use_builtin')); ?></button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'templates')): ?>
        <!-- Standard-change templates (#180 §8) - get_templates / save_template / template_action. -->
        <div class="tab-content<?php echo $activeTabId === 'templates' ? ' active' : ''; ?>" id="templates-tab" data-capability="<?php echo capKey(Cap::CHANGES_TEMPLATES); ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('change-management.templates.heading')); ?></h2>
                <button class="add-btn" onclick="openTemplateEditor(null)"><?php echo htmlspecialchars(t('change-management.templates.new')); ?></button>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 12px;"><?php echo htmlspecialchars(t('change-management.templates.intro')); ?></p>
            <div id="tplOffNote" class="ar-warning" style="display:none; margin-bottom: 12px;"><?php echo htmlspecialchars(t('change-management.templates.off_note')); ?></div>
            <table class="lookup-table">
                <thead><tr><th><?php echo htmlspecialchars(t('change-management.templates.col_name')); ?></th><th><?php echo htmlspecialchars(t('change-management.templates.col_state')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_actions')); ?></th></tr></thead>
                <tbody id="templates-list"><tr><td colspan="3" style="text-align:center;"><?php echo htmlspecialchars(t('change-management.settings.loading')); ?></td></tr></tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php if (settingsTabVisible($visibleTabs, 'approvals')): ?>
        <!-- Approval rules (#180) - api/change-management/get|save_approval_settings.php.
             Every choice here is a dropdown or a tick; the defaults are today's behaviour. -->
        <div class="tab-content<?php echo $activeTabId === 'approvals' ? ' active' : ''; ?>" id="approvals-tab" data-capability="<?php echo capKey(Cap::CHANGES_APPROVALS); ?>">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('change-management.approval_settings.heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('change-management.approval_settings.intro')); ?></p>

            <form id="approvalRulesForm" class="approval-rules" autocomplete="off" onsubmit="event.preventDefault(); saveApprovalRules();">
                <fieldset class="ar-group">
                    <legend><?php echo htmlspecialchars(t('change-management.approval_settings.mode')); ?></legend>
                    <label class="ar-choice"><input type="radio" name="arMode" value="record" onchange="syncApprovalRules()">
                        <strong><?php echo htmlspecialchars(t('change-management.approval_settings.mode_record')); ?></strong>
                        <span class="ar-help"><?php echo htmlspecialchars(t('change-management.approval_settings.mode_record_help')); ?></span></label>
                    <label class="ar-choice"><input type="radio" name="arMode" value="enforce" onchange="syncApprovalRules()">
                        <strong><?php echo htmlspecialchars(t('change-management.approval_settings.mode_enforce')); ?></strong>
                        <span class="ar-help"><?php echo htmlspecialchars(t('change-management.approval_settings.mode_enforce_help')); ?></span></label>
                    <div id="arEnforceBlockers" class="ar-warning" style="display:none;"></div>
                </fieldset>

                <fieldset class="ar-group">
                    <legend><?php echo htmlspecialchars(t('change-management.approval_settings.own')); ?></legend>
                    <select id="arOwnAllowed">
                        <option value="0"><?php echo htmlspecialchars(t('change-management.approval_settings.own_not_allowed')); ?></option>
                        <option value="1"><?php echo htmlspecialchars(t('change-management.approval_settings.own_allowed')); ?></option>
                    </select>
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.approval_settings.own_help')); ?></span>
                    <div class="ar-sub"><?php echo htmlspecialchars(t('change-management.approval_settings.own_people')); ?></div>
                    <div class="ar-ticks">
                        <?php foreach (['requester', 'assignee', 'creator'] as $who): ?>
                        <label><input type="checkbox" name="arOwnPeople" value="<?php echo $who; ?>"> <?php echo htmlspecialchars(t('change-management.approval_settings.own_' . $who)); ?></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="ar-group">
                    <legend><?php echo htmlspecialchars(t('change-management.approval_settings.override')); ?></legend>
                    <select id="arOverride">
                        <option value="0"><?php echo htmlspecialchars(t('change-management.approval_settings.override_off')); ?></option>
                        <option value="1"><?php echo htmlspecialchars(t('change-management.approval_settings.override_on')); ?></option>
                    </select>
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.approval_settings.override_help')); ?></span>
                </fieldset>

                <fieldset class="ar-group">
                    <legend><?php echo htmlspecialchars(t('change-management.approval_settings.material')); ?></legend>
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.approval_settings.material_help')); ?></span>
                    <div class="ar-ticks ar-ticks-grid">
                        <?php require_once __DIR__ . '/../../includes/changes/approvals.php'; foreach (array_keys(CHANGE_MATERIAL_CHOICES) as $mk): ?>
                        <label><input type="checkbox" name="arMaterial" value="<?php echo $mk; ?>"> <?php echo htmlspecialchars(t('change-management.approval_settings.field_' . $mk)); ?></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="ar-group ar-record-only">
                    <legend><?php echo htmlspecialchars(t('change-management.approval_settings.edit_after')); ?></legend>
                    <select id="arEditAfter">
                        <option value="keep"><?php echo htmlspecialchars(t('change-management.approval_settings.edit_after_keep')); ?></option>
                        <option value="withdraw"><?php echo htmlspecialchars(t('change-management.approval_settings.edit_after_withdraw')); ?></option>
                    </select>
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.approval_settings.edit_after_help')); ?></span>
                    <span class="ar-enforced"><?php echo htmlspecialchars(t('change-management.approval_settings.enforce_fixes_this')); ?></span>
                </fieldset>

                <fieldset class="ar-group ar-record-only">
                    <legend><?php echo htmlspecialchars(t('change-management.approval_settings.stale')); ?></legend>
                    <select id="arStaleVote">
                        <option value="record"><?php echo htmlspecialchars(t('change-management.approval_settings.stale_record')); ?></option>
                        <option value="refuse"><?php echo htmlspecialchars(t('change-management.approval_settings.stale_refuse')); ?></option>
                    </select>
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.approval_settings.stale_help')); ?></span>
                    <span class="ar-enforced"><?php echo htmlspecialchars(t('change-management.approval_settings.enforce_fixes_this')); ?></span>
                </fieldset>

                <fieldset class="ar-group ar-record-only">
                    <legend><?php echo htmlspecialchars(t('change-management.approval_settings.delete')); ?></legend>
                    <select id="arDeleteSubmitted">
                        <option value="allowed"><?php echo htmlspecialchars(t('change-management.approval_settings.delete_allowed')); ?></option>
                        <option value="refuse"><?php echo htmlspecialchars(t('change-management.approval_settings.delete_refuse')); ?></option>
                    </select>
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.approval_settings.delete_help')); ?></span>
                    <span class="ar-enforced"><?php echo htmlspecialchars(t('change-management.approval_settings.enforce_fixes_this')); ?></span>
                </fieldset>

                <fieldset class="ar-group">
                    <legend><?php echo htmlspecialchars(t('change-management.approval_settings.reject')); ?></legend>
                    <select id="arRejectStatus"></select>
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.approval_settings.reject_help')); ?></span>
                </fieldset>

                <fieldset class="ar-group">
                    <legend><?php echo htmlspecialchars(t('change-management.approval_settings.cancel')); ?></legend>
                    <select id="arCancelStatus"></select>
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.approval_settings.cancel_help')); ?></span>
                </fieldset>

                <fieldset class="ar-group">
                    <legend><?php echo htmlspecialchars(t('change-management.approval_settings.templates')); ?></legend>
                    <select id="arTemplates">
                        <option value="0"><?php echo htmlspecialchars(t('change-management.approval_settings.templates_off')); ?></option>
                        <option value="1"><?php echo htmlspecialchars(t('change-management.approval_settings.templates_on')); ?></option>
                    </select>
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.approval_settings.templates_help')); ?></span>
                </fieldset>

                <fieldset class="ar-group">
                    <legend><?php echo htmlspecialchars(t('change-management.approval_settings.choose')); ?></legend>
                    <select id="arChooseApprovers">
                        <option value="anyone"><?php echo htmlspecialchars(t('change-management.approval_settings.choose_anyone')); ?></option>
                        <option value="permission"><?php echo htmlspecialchars(t('change-management.approval_settings.choose_permission')); ?></option>
                    </select>
                    <span class="ar-help"><?php echo htmlspecialchars(t('change-management.approval_settings.choose_help')); ?></span>
                </fieldset>

                <div class="ar-actions">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('change-management.settings.modal_save')); ?></button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Left panel — a per-analyst display preference, so it declares no capability. -->
        <div class="tab-content<?php echo $activeTabId === 'left-panel' ? ' active' : ''; ?>" id="left-panel-tab" data-capability="none">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('common.left_panel.tab')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('change-management.settings.left_panel_intro')); ?></p>

            <form id="leftPanelForm" autocomplete="off" onsubmit="event.preventDefault();">
                <div class="form-group">
                    <label style="display: block; margin-bottom: 10px; font-weight: 500; color: var(--text, #333);"><?php echo htmlspecialchars(t('common.left_panel.visibility')); ?></label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; margin-bottom: 8px; cursor: pointer;">
                        <input type="radio" name="cmSidebarMode" value="always" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('common.left_panel.always')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-muted, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('change-management.settings.left_panel_always_desc')); ?>
                        </span>
                    </label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="cmSidebarMode" value="hover" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('common.left_panel.hover')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-muted, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('change-management.settings.left_panel_hover_desc')); ?>
                        </span>
                    </label>
                </div>
            </form>
        </div>
    </div>

    <!-- Lookup edit modal (shared by all four tabs) -->
    <div class="modal" id="lookupModal">
        <div class="modal-content">
            <div class="modal-header" id="lookupModalTitle"><?php echo htmlspecialchars(t('change-management.settings.modal_add_item')); ?></div>
            <form id="lookupForm">
                <input type="hidden" id="lookupItemKind">
                <input type="hidden" id="lookupItemId">

                <div class="form-group">
                    <label for="lookupItemName"><?php echo htmlspecialchars(t('change-management.settings.modal_name')); ?></label>
                    <input type="text" id="lookupItemName" required>
                </div>

                <div class="form-group">
                    <label for="lookupItemColour"><?php echo htmlspecialchars(t('change-management.settings.modal_colour')); ?></label>
                    <input type="color" id="lookupItemColour" value="#2563eb" style="width: 60px; height: 32px; padding: 2px;">
                    <span class="help"><?php echo htmlspecialchars(t('change-management.settings.modal_colour_help')); ?></span>
                </div>

                <div class="form-group" id="lookupItemStageGroup" style="display: none;">
                    <label for="lookupItemStage"><?php echo htmlspecialchars(t('change-management.settings.modal_stage')); ?></label>
                    <select id="lookupItemStage" onchange="syncClosedToStage()">
                        <option value=""><?php echo htmlspecialchars(t('change-management.stage.none')); ?></option>
                        <?php require_once __DIR__ . '/../../includes/changes/approvals.php'; foreach (CHANGE_STAGES as $st): ?>
                        <option value="<?php echo $st; ?>"><?php echo htmlspecialchars(t('change-management.stage.' . $st)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="help"><?php echo htmlspecialchars(t('change-management.settings.modal_stage_help')); ?></span>
                </div>

                <div class="form-group" id="lookupItemRouteGroup" style="display: none;">
                    <label for="lookupItemRoute"><?php echo htmlspecialchars(t('change-management.settings.modal_route')); ?></label>
                    <select id="lookupItemRoute">
                        <option value="review"><?php echo htmlspecialchars(t('change-management.settings.route_review')); ?></option>
                        <option value="emergency"><?php echo htmlspecialchars(t('change-management.settings.route_emergency')); ?></option>
                    </select>
                    <span class="help"><?php echo htmlspecialchars(t('change-management.settings.modal_route_help')); ?></span>
                </div>

                <div class="form-group" id="lookupItemClosedGroup" style="display: none;">
                    <label><input type="checkbox" id="lookupItemClosed"> <?php echo htmlspecialchars(t('change-management.settings.modal_closed')); ?></label>
                    <span class="help"><?php echo htmlspecialchars(t('change-management.settings.modal_closed_help')); ?></span>
                </div>

                <div class="form-group">
                    <label><input type="checkbox" id="lookupItemDefault"> <?php echo htmlspecialchars(t('change-management.settings.modal_default')); ?></label>
                    <span class="help"><?php echo htmlspecialchars(t('change-management.settings.modal_default_help')); ?></span>
                </div>

                <div class="form-group">
                    <label for="lookupItemOrder"><?php echo htmlspecialchars(t('change-management.settings.modal_order')); ?></label>
                    <input type="number" id="lookupItemOrder" value="0">
                </div>

                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="lookupItemActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('change-management.settings.modal_active')); ?>
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeLookupModal()"><?php echo htmlspecialchars(t('change-management.settings.modal_cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('change-management.settings.modal_save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Template editor (#180 §8). A .modal-content, so mobile.css LAYER 3 makes it a sheet. -->
    <div class="modal" id="templateModal">
        <div class="modal-content" style="max-width: 820px;">
            <div class="modal-header" id="tplTitle"></div>
            <div class="tpl-body">
                <input type="hidden" id="tplId">
                <div class="form-group"><label for="tplName"><?php echo htmlspecialchars(t('change-management.templates.name')); ?></label><input type="text" id="tplName"></div>
                <div class="form-group"><label for="tplScopeUse"><?php echo htmlspecialchars(t('change-management.templates.scope_use')); ?></label><textarea id="tplScopeUse" rows="2"></textarea></div>
                <div class="form-group"><label for="tplScopeNot"><?php echo htmlspecialchars(t('change-management.templates.scope_not')); ?></label><textarea id="tplScopeNot" rows="2"></textarea></div>
                <div class="tpl-grid">
                    <div class="form-group"><label for="tplType"><?php echo htmlspecialchars(t('change-management.approval_settings.field_change_type_id')); ?></label><select id="tplType"></select></div>
                    <div class="form-group"><label for="tplPriority"><?php echo htmlspecialchars(t('change-management.approval_settings.field_priority_id')); ?></label><select id="tplPriority"></select></div>
                    <div class="form-group"><label for="tplImpact"><?php echo htmlspecialchars(t('change-management.approval_settings.field_impact_id')); ?></label><select id="tplImpact"></select></div>
                    <div class="form-group"><label for="tplRiskL"><?php echo htmlspecialchars(t('change-management.approval_settings.field_risk_likelihood')); ?></label><select id="tplRiskL"></select></div>
                    <div class="form-group"><label for="tplRiskI"><?php echo htmlspecialchars(t('change-management.approval_settings.field_risk_impact_score')); ?></label><select id="tplRiskI"></select></div>
                </div>
                <div class="cm-risk-older" id="tplRiskOlder" hidden><span id="tplRiskOlderText"></span>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="tplReassessRisk()"><?php echo htmlspecialchars(t('change-management.risk_policy.reassess')); ?></button></div>
                <?php foreach (['description', 'reason_for_change', 'risk_evaluation', 'test_plan', 'rollback_plan'] as $lf): ?>
                <div class="form-group"><label for="tpl_<?php echo $lf; ?>"><?php echo htmlspecialchars(t('change-management.revisions.field.' . $lf)); ?></label><textarea id="tpl_<?php echo $lf; ?>" rows="3"></textarea></div>
                <?php endforeach; ?>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('change-management.templates.open_fields')); ?></label>
                    <span class="help"><?php echo htmlspecialchars(t('change-management.templates.open_help')); ?></span>
                    <div class="ar-ticks ar-ticks-grid" id="tplOpen"></div>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('change-management.templates.teams')); ?></label>
                    <span class="help"><?php echo htmlspecialchars(t('change-management.templates.teams_help')); ?></span>
                    <div class="ar-ticks" id="tplTeams"></div>
                </div>
                <div class="form-group" id="tplVersionsGroup">
                    <label><?php echo htmlspecialchars(t('change-management.templates.versions')); ?></label>
                    <div id="tplVersions"></div>
                </div>
                <span class="help"><?php echo htmlspecialchars(t('change-management.templates.submit_help')); ?></span>
            </div>
            <div class="modal-actions" style="padding: 12px 20px;">
                <button type="button" class="btn btn-secondary" onclick="closeTemplateEditor()"><?php echo htmlspecialchars(t('change-management.templates.close')); ?></button>
                <button type="button" class="btn btn-secondary" id="tplSaveBtn" onclick="saveTemplate(false)"><?php echo htmlspecialchars(t('change-management.templates.save')); ?></button>
                <button type="button" class="btn btn-primary" id="tplSubmitBtn" onclick="saveTemplate(true)"><?php echo htmlspecialchars(t('change-management.templates.submit')); ?></button>
            </div>
        </div>
    </div>

    <!-- Toast notification -->

    <?php require_once '../../includes/changes/risk_policy.php'; echo ChangeRiskPolicy::jsBootstrap(connectToDatabase()); ?>
    <script src="<?php echo BASE_URL; ?>assets/js/change-risk.js?v=1"></script>
    <script>
        const API_BASE = '../../api/change-management/';

        // Layout state — loaded from get_field_layout.php; mutated locally
        // on every UI action and then auto-saved to save_field_layout.php.
        // Shape:
        //   sections: [{ id, name, display_order }]
        //   fields:   [{ key, label, section_id, display_order, is_visible }]
        //   unplaced: [{ key, label }]  // catalogue keys with no layout row
        // Section ids < 0 are tempIds for locally-created sections that
        // haven't been saved yet — the API resolves them on save and
        // returns real ids in the response.
        let layout = { sections: [], fields: [], unplaced: [] };
        let nextTempSectionId = -1;

        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
            if (tab === 'left-panel') loadSidebarMode();
        }

        // --- Left panel preference ------------------------------------
        // 'always' vs 'hover', stored per-analyst via user_preferences.
        // header.php reads the same key on every change page and toggles
        // .sidebar-hover on .changes-container. Also editable under
        // System → Preferences.
        const SIDEBAR_MODE_KEY = 'change_management_sidebar_mode';
        let sidebarModeLoaded = false;
        async function loadSidebarMode() {
            if (sidebarModeLoaded) return;
            sidebarModeLoaded = true;
            try {
                const r = await fetch('../../api/system/get_user_preference.php?key=' + encodeURIComponent(SIDEBAR_MODE_KEY), { credentials: 'same-origin' });
                const d = await r.json();
                const mode = (d.success && (d.value === 'always' || d.value === 'hover')) ? d.value : 'always';
                document.querySelectorAll('input[name="cmSidebarMode"]').forEach(i => { i.checked = (i.value === mode); });
            } catch (e) {
                const first = document.querySelector('input[name="cmSidebarMode"][value="always"]');
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
                if (d.success) showToast(window.t('change-management.toast.saved'), 'success');
            } catch (e) { /* no-op */ }
        }

        // ============================================================
        // Risk policy (#246) - get|save_risk_policy.php
        //
        // The editor holds the policy as plain arrays (RP) and redraws from them, so adding
        // or removing a level re-shapes the matrix without losing the cells already chosen.
        // Saving always makes the NEXT version; nothing already rated changes.
        // ============================================================
        let RP = null;          // { name, likelihood: [{label, criteria}], impact: [...], results: [{label, colour}], matrix: [[idx]] }
        let RP_LIMITS = {};
        const RPT = (k, p) => window.t('change-management.risk_policy.' + k, p);

        async function loadRiskPolicy() {
            try {
                const d = await fetch(API_BASE + 'get_risk_policy.php').then(r => r.json());
                if (!d.success) { showToast(d.error || window.t('change-management.toast.error_loading'), 'error'); return; }
                RP_LIMITS = d.limits || {};
                const p = d.current.content;
                const builtin = !!p.builtin;
                // Start from the policy in use. The built-in becomes editable words in the reader's language.
                const lv = (axis) => (p[axis] || []).map((x, n) => ({
                    label: builtin ? ChangeRisk.levelLabel(p, axis, n + 1) : x.label,
                    criteria: builtin ? ChangeRisk.criteria(p, axis, n + 1) : (x.criteria || ''),
                }));
                RP = {
                    name: builtin ? '' : (p.name || ''),
                    likelihood: lv('likelihood'), impact: lv('impact'),
                    results: (p.results || []).map(r => ({ label: builtin ? ChangeRisk.resultName(r) : r.label, colour: r.colour })),
                    matrix: (p.matrix || []).map(row => row.slice()),
                };
                const cur = builtin ? RPT('builtin_name') : RPT('version_label', { name: p.name || '', n: d.current.version_no });
                document.getElementById('rpInUse').innerHTML = `<strong>${escapeHtml(cur)}</strong>`
                    + (d.ready ? '' : `<div class="ar-warning">${escapeHtml(RPT('not_ready'))}</div>`);
                const rows = (d.versions || []).map(v => `<tr${v.id === d.current.version_id ? ' class="is-current"' : ''}>
                        <td>${escapeHtml(RPT('version_label', { name: v.name, n: v.version_no }))}</td><td>${escapeHtml(v.size)}</td>
                        <td>${escapeHtml(v.created_by || '')} ${v.created_datetime ? escapeHtml(typeof formatDateTime === 'function' ? formatDateTime(v.created_datetime) : v.created_datetime) : ''}</td>
                        <td>${escapeHtml(RPT('changes_count', { n: v.changes }))}</td></tr>`).join('');
                document.getElementById('rpHistory').innerHTML = rows + `<tr${d.current.version_id === null ? ' class="is-current"' : ''}><td>${escapeHtml(RPT('builtin_name'))}</td><td>5 x 5</td><td></td><td>${escapeHtml(RPT('changes_count', { n: d.builtin_count }))}</td></tr>`;
                document.getElementById('rpBuiltinBtn').style.display = builtin ? 'none' : '';
                drawRiskPolicy();
            } catch (e) { showToast(window.t('change-management.toast.error_loading'), 'error'); }
        }

        /** Draws the level and result rows and the matrix from RP. */
        function drawRiskPolicy() {
            document.getElementById('rpName').value = RP.name;
            ['likelihood', 'impact'].forEach(axis => {
                document.getElementById('rp_' + axis).innerHTML = RP[axis].map((lv, n) => `
                    <div class="rp-row">
                        <span class="rp-n">${n + 1}</span>
                        <input type="text" class="form-input rp-label" maxlength="${RP_LIMITS.label_max || 50}" value="${escapeHtml(lv.label)}"
                               aria-label="${escapeHtml(RPT('label_n', { n: n + 1 }))}" oninput="RP['${axis}'][${n}].label = this.value; drawRiskMatrix()">
                        <textarea class="form-input rp-criteria" rows="2" maxlength="${RP_LIMITS.criteria_max || 1000}" placeholder="${escapeHtml(RPT('criteria'))}"
                                  aria-label="${escapeHtml(RPT('criteria_n', { n: n + 1 }))}" oninput="RP['${axis}'][${n}].criteria = this.value">${escapeHtml(lv.criteria)}</textarea>
                        <button type="button" class="rp-remove" aria-label="${escapeHtml(RPT('remove_n', { n: n + 1 }))}" onclick="rpRemove('${axis}', ${n})">&times;</button>
                    </div>`).join('');
            });
            document.getElementById('rp_results').innerHTML = RP.results.map((r, n) => `
                <div class="rp-row">
                    <span class="rp-n">${n + 1}</span>
                    <input type="text" class="form-input rp-label" maxlength="${RP_LIMITS.label_max || 50}" value="${escapeHtml(r.label)}"
                           aria-label="${escapeHtml(RPT('label_n', { n: n + 1 }))}" oninput="RP.results[${n}].label = this.value; drawRiskMatrix()">
                    <input type="color" class="rp-colour" value="${escapeHtml(r.colour)}" aria-label="${escapeHtml(RPT('colour_n', { n: n + 1 }))}"
                           oninput="RP.results[${n}].colour = this.value; drawRiskMatrix()">
                    <button type="button" class="rp-remove" aria-label="${escapeHtml(RPT('remove_n', { n: n + 1 }))}" onclick="rpRemove('results', ${n})">&times;</button>
                </div>`).join('');
            drawRiskMatrix();
        }

        /** The matrix: rows likelihood (highest at the top, as on a change), columns impact, a result per cell. */
        function drawRiskMatrix() {
            const L = RP.likelihood.length, I = RP.impact.length;
            let html = `<tr><th></th>${RP.impact.map((lv, i) => `<th scope="col">${escapeHtml(lv.label || String(i + 1))}</th>`).join('')}</tr>`;
            for (let l = L - 1; l >= 0; l--) {
                html += `<tr><th scope="row">${escapeHtml(RP.likelihood[l].label || String(l + 1))}</th>`;
                for (let i = 0; i < I; i++) {
                    const v = (RP.matrix[l] || [])[i];
                    const r = v === undefined || v === null ? null : RP.results[v];
                    const style = r ? `background:${r.colour}22;border-color:${r.colour};` : '';
                    html += `<td><select class="rp-cell${r ? '' : ' is-empty'}" style="${style}"
                                aria-label="${escapeHtml(RPT('cell', { l: RP.likelihood[l].label, i: RP.impact[i].label }))}"
                                onchange="rpSetCell(${l}, ${i}, this.value)">
                                <option value="">-</option>${RP.results.map((x, n) => `<option value="${n}"${v === n ? ' selected' : ''}>${escapeHtml(x.label)}</option>`).join('')}
                             </select></td>`;
                }
                html += '</tr>';
            }
            document.getElementById('rpMatrix').innerHTML = html;
        }

        function rpSetCell(l, i, v) {
            if (!RP.matrix[l]) RP.matrix[l] = [];
            RP.matrix[l][i] = v === '' ? null : parseInt(v, 10);
            drawRiskMatrix();
        }

        /** Adds a level or a result after the last (highest) one. A new level's matrix cells start empty: the policy decides them. */
        function rpAdd(part) {
            const max = part === 'results' ? (RP_LIMITS.max_results || 8) : (RP_LIMITS.max_levels || 10);
            if (RP[part].length >= max) { showToast(RPT('too_many', { n: max }), 'error'); return; }
            if (part === 'results') RP.results.push({ label: '', colour: '#64748b' });
            else {
                RP[part].push({ label: '', criteria: '' });
                if (part === 'likelihood') RP.matrix.push(new Array(RP.impact.length).fill(null));
                else RP.matrix.forEach(row => row.push(null));
            }
            drawRiskPolicy();
        }

        /** Removes a level (and its row or column of the matrix) or a result (and the cells that used it). */
        function rpRemove(part, n) {
            const min = part === 'results' ? (RP_LIMITS.min_results || 2) : (RP_LIMITS.min_levels || 2);
            if (RP[part].length <= min) { showToast(RPT('too_few', { n: min }), 'error'); return; }
            RP[part].splice(n, 1);
            if (part === 'likelihood') RP.matrix.splice(n, 1);
            else if (part === 'impact') RP.matrix.forEach(row => row.splice(n, 1));
            else RP.matrix = RP.matrix.map(row => row.map(v => v === n ? null : (v !== null && v > n ? v - 1 : v)));
            drawRiskPolicy();
        }

        async function saveRiskPolicy() {
            RP.name = document.getElementById('rpName').value.trim();
            if (!confirm(RPT('confirm_save'))) return;
            try {
                const d = await fetch(API_BASE + 'save_risk_policy.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save', policy: RP })
                }).then(r => r.json());
                if (d.success) { showToast(RPT('saved'), 'success'); setTimeout(() => location.reload(), 600); }
                else showToast(d.error || window.t('change-management.toast.error_loading'), 'error');
            } catch (e) { showToast(window.t('change-management.toast.error_loading'), 'error'); }
        }

        async function useBuiltinRiskPolicy() {
            if (!confirm(RPT('confirm_builtin'))) return;
            try {
                const d = await fetch(API_BASE + 'save_risk_policy.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'builtin' })
                }).then(r => r.json());
                if (d.success) { showToast(RPT('saved'), 'success'); setTimeout(() => location.reload(), 600); }
                else showToast(d.error || window.t('change-management.toast.error_loading'), 'error');
            } catch (e) { showToast(window.t('change-management.toast.error_loading'), 'error'); }
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadSettings();
            loadLookups();
            if (document.getElementById('approvalRulesForm')) loadApprovalRules();
            if (document.getElementById('riskPolicyForm')) loadRiskPolicy();
            if (document.getElementById('templates-list')) loadTemplates();
        });

        // ============================================================
        // Approval rules (#180) - get|save_approval_settings.php
        // ============================================================
        async function loadApprovalRules() {
            try {
                const res = await fetch(API_BASE + 'get_approval_settings.php');
                const data = await res.json();
                if (!data.success) { showToast(data.error || window.t('change-management.toast.save_failed'), 'error'); return; }
                const s = data.settings;
                document.querySelectorAll('input[name="arMode"]').forEach(i => { i.checked = (i.value === s.mode); });
                document.getElementById('arOwnAllowed').value = s.own_allowed ? '1' : '0';
                document.querySelectorAll('input[name="arOwnPeople"]').forEach(i => { i.checked = s.own_people.includes(i.value); });
                document.getElementById('arOverride').value = s.override ? '1' : '0';
                document.querySelectorAll('input[name="arMaterial"]').forEach(i => { i.checked = s.material.includes(i.value); });
                document.getElementById('arEditAfter').value = s.edit_after;
                document.getElementById('arStaleVote').value = s.stale_vote;
                document.getElementById('arDeleteSubmitted').value = s.delete_submitted;
                document.getElementById('arChooseApprovers').value = s.choose_approvers;
                document.getElementById('arTemplates').value = s.templates_enabled ? '1' : '0';
                const rej = document.getElementById('arRejectStatus');
                rej.innerHTML = `<option value="">${escapeHtml(window.t('change-management.approval_settings.reject_default'))}</option>`
                    + data.statuses.filter(st => st.is_active && (st.stage === 'draft' || st.stage === 'stopped'))
                        .map(st => `<option value="${st.id}">${escapeHtml(st.name)}</option>`).join('');
                rej.value = s.reject_status_id ? String(s.reject_status_id) : '';
                const can = document.getElementById('arCancelStatus');
                can.innerHTML = `<option value="">${escapeHtml(window.t('change-management.approval_settings.cancel_default'))}</option>`
                    + data.statuses.filter(st => st.is_active && st.stage === 'stopped')
                        .map(st => `<option value="${st.id}">${escapeHtml(st.name)}</option>`).join('');
                can.value = s.cancel_status_id ? String(s.cancel_status_id) : '';
                const blockers = data.enforce_blockers || [];
                const box = document.getElementById('arEnforceBlockers');
                box.style.display = blockers.length ? '' : 'none';
                box.textContent = blockers.length ? window.t('change-management.approval_settings.enforce_blocked', { statuses: blockers.join(', ') }) : '';
                syncApprovalRules();
            } catch (e) { console.error(e); }
        }

        // ============================================================
        // Standard-change templates (#180 §8)
        // ============================================================
        let TPL = { templates: [], teams: [], fields: [], can_write: false, can_approve: false, me: 0 };
        const TT = (k, p) => window.t('change-management.templates.' + k, p);

        async function loadTemplates() {
            try {
                const res = await fetch(API_BASE + 'get_templates.php');
                const data = await res.json();
                if (!data.success) return;
                TPL = data;
                document.getElementById('tplOffNote').style.display = data.enabled ? 'none' : '';
                const tbody = document.getElementById('templates-list');
                if (!data.templates.length) {
                    tbody.innerHTML = `<tr><td colspan="3" style="text-align:center;">${escapeHtml(TT('none'))}</td></tr>`;
                    return;
                }
                tbody.innerHTML = data.templates.map(t => {
                    const approved = t.versions.find(v => v.state === 'approved');
                    const waiting = t.versions.find(v => v.state === 'draft');
                    let state = t.retired ? TT('state_retired')
                        : approved ? TT('state_approved', { n: approved.version_no, name: approved.approved_by_name || '' })
                        : TT('state_draft_only');
                    if (waiting && !t.retired) state += ' · ' + TT('state_awaiting', { n: waiting.version_no });
                    return `<tr>
                        <td><strong>${escapeHtml(t.name)}</strong>${t.scope_use ? `<div style="font-size:12px;color:var(--text-muted,#777);">${escapeHtml(t.scope_use)}</div>` : ''}</td>
                        <td>${escapeHtml(state)}</td>
                        <td><button class="action-btn" onclick="openTemplateEditor(${t.id})" title="${escapeHtml(window.t('change-management.settings.edit'))}">&#9998;</button>
                            ${data.can_write && !t.retired ? `<button class="action-btn delete" onclick="retireTemplate(${t.id})" title="${escapeHtml(TT('retire'))}">&#10005;</button>` : ''}</td>
                    </tr>`;
                }).join('');
            } catch (e) { console.error(e); }
        }

        function fillSelect(id, rows, value) {
            const sel = document.getElementById(id);
            sel.innerHTML = '<option value=""></option>' + rows.filter(r => r.is_active).map(r => `<option value="${r.id}">${escapeHtml(r.name)}</option>`).join('');
            sel.value = value == null ? '' : String(value);
        }

        function openTemplateEditor(id) {
            const t = id ? TPL.templates.find(x => x.id === id) : null;
            const v = t ? t.values : {};
            document.getElementById('tplTitle').textContent = t ? t.name : TT('new');
            document.getElementById('tplId').value = t ? t.id : '';
            document.getElementById('tplName').value = t ? t.name : '';
            document.getElementById('tplScopeUse').value = t ? (t.scope_use || '') : '';
            document.getElementById('tplScopeNot').value = t ? (t.scope_not || '') : '';
            fillSelect('tplType', lookupCache.type, v.change_type_id);
            fillSelect('tplPriority', lookupCache.priority, v.priority_id);
            fillSelect('tplImpact', lookupCache.impact, v.impact_id);
            // Ratings on the scale they were given on; unrated on the policy in use (#246).
            const rated = !!(v.risk_likelihood || v.risk_impact_score);
            tplRisk = { version: rated ? (t.risk_policy_version_id || 0) : ChangeRisk.currentId(), reassess: false };
            ChangeRisk.fillSelects(tplRisk.version, document.getElementById('tplRiskL'), document.getElementById('tplRiskI'));
            document.getElementById('tplRiskL').value = v.risk_likelihood || '';
            document.getElementById('tplRiskI').value = v.risk_impact_score || '';
            tplShowRiskScale();
            ['description', 'reason_for_change', 'risk_evaluation', 'test_plan', 'rollback_plan'].forEach(f => {
                document.getElementById('tpl_' + f).value = v[f] || '';
            });
            const open = t ? t.open_fields : ['description'];
            document.getElementById('tplOpen').innerHTML = TPL.fields.map(f =>
                `<label><input type="checkbox" name="tplOpen" value="${f}" ${open.includes(f) ? 'checked' : ''}> ${escapeHtml(window.t('change-management.approval_settings.field_' + f) !== 'change-management.approval_settings.field_' + f ? window.t('change-management.approval_settings.field_' + f) : f)}</label>`).join('');
            const teams = t ? t.team_ids : [];
            document.getElementById('tplTeams').innerHTML = TPL.teams.map(tm =>
                `<label><input type="checkbox" name="tplTeam" value="${tm.id}" ${teams.includes(parseInt(tm.id)) ? 'checked' : ''}> ${escapeHtml(tm.name)}</label>`).join('');
            const vers = t ? t.versions : [];
            document.getElementById('tplVersionsGroup').style.display = vers.length ? '' : 'none';
            document.getElementById('tplVersions').innerHTML = vers.map(ver => {
                const mayApprove = ver.state === 'draft' && TPL.can_approve;
                return `<div class="tpl-version"><strong>v${ver.version_no}</strong> ${escapeHtml(ver.state)}
                    ${ver.approved_by_name ? ' · ' + escapeHtml(ver.approved_by_name) + (ver.approval_note ? ': ' + escapeHtml(ver.approval_note) : '') : ''}
                    ${ver.created_by_name ? ' · ' + escapeHtml(ver.created_by_name) : ''}
                    ${mayApprove ? `<input type="text" id="tplNote${ver.id}" placeholder="${escapeHtml(TT('approve_note'))}"><button type="button" class="btn btn-primary btn-sm" onclick="approveTemplateVersion(${ver.id})">${escapeHtml(TT('approve'))}</button>` : ''}
                </div>`;
            }).join('');
            const ro = !TPL.can_write;
            document.getElementById('tplSaveBtn').style.display = ro ? 'none' : '';
            document.getElementById('tplSubmitBtn').style.display = ro ? 'none' : '';
            document.getElementById('templateModal').classList.add('active');
        }

        function closeTemplateEditor() { document.getElementById('templateModal').classList.remove('active'); }

        // The scale a template's ratings are on (#246) - as on a change: kept until Re-assess.
        let tplRisk = { version: 0, reassess: false };
        function tplShowRiskScale() {
            const older = tplRisk.version !== (ChangeRisk.currentId() || 0);
            const p = ChangeRisk.policy(tplRisk.version);
            document.getElementById('tplRiskOlder').hidden = !older;
            if (older) document.getElementById('tplRiskOlderText').textContent = window.t('change-management.risk_policy.older_note_template', {
                name: ChangeRisk.isBuiltin(p) ? window.t('change-management.risk_policy.builtin_name') : (p.name || '') });
        }
        function tplReassessRisk() {
            tplRisk = { version: ChangeRisk.currentId(), reassess: true };
            document.getElementById('tplRiskL').value = ''; document.getElementById('tplRiskI').value = '';
            ChangeRisk.fillSelects(tplRisk.version, document.getElementById('tplRiskL'), document.getElementById('tplRiskI'));
            tplShowRiskScale();
        }

        async function saveTemplate(submit) {
            const values = {
                change_type_id: document.getElementById('tplType').value, priority_id: document.getElementById('tplPriority').value,
                impact_id: document.getElementById('tplImpact').value, risk_likelihood: document.getElementById('tplRiskL').value,
                risk_impact_score: document.getElementById('tplRiskI').value
            };
            ['description', 'reason_for_change', 'risk_evaluation', 'test_plan', 'rollback_plan'].forEach(f => { values[f] = document.getElementById('tpl_' + f).value; });
            const payload = {
                id: document.getElementById('tplId').value || null,
                name: document.getElementById('tplName').value,
                scope_use: document.getElementById('tplScopeUse').value,
                scope_not: document.getElementById('tplScopeNot').value,
                values,
                open_fields: [...document.querySelectorAll('input[name="tplOpen"]:checked')].map(i => i.value),
                team_ids: [...document.querySelectorAll('input[name="tplTeam"]:checked')].map(i => parseInt(i.value)),
                risk_reassess: tplRisk.reassess || undefined,
                submit
            };
            try {
                const res = await fetch(API_BASE + 'save_template.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
                const data = await res.json();
                if (data.success) { closeTemplateEditor(); showToast(TT(submit ? 'submitted' : 'saved'), 'success'); loadTemplates(); }
                else showToast(data.error || window.t('change-management.toast.save_failed'), 'error');
            } catch (e) { showToast(window.t('change-management.toast.save_failed'), 'error'); }
        }

        async function approveTemplateVersion(versionId) {
            const note = (document.getElementById('tplNote' + versionId) || {}).value || '';
            const res = await fetch(API_BASE + 'template_action.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'approve', version_id: versionId, note }) });
            const data = await res.json();
            if (data.success) { closeTemplateEditor(); showToast(TT('approved'), 'success'); loadTemplates(); }
            else showToast(data.error || window.t('change-management.toast.save_failed'), 'error');
        }

        async function retireTemplate(id) {
            const t = TPL.templates.find(x => x.id === id);
            if (!(await showConfirm({ title: TT('retire'), message: TT('retire_confirm', { name: t ? t.name : '' }), okLabel: TT('retire'), okClass: 'danger' }))) return;
            const res = await fetch(API_BASE + 'template_action.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'retire', template_id: id }) });
            const data = await res.json();
            if (data.success) { showToast(TT('retired'), 'success'); loadTemplates(); }
            else showToast(data.error || window.t('change-management.toast.save_failed'), 'error');
        }

        // In Enforce mode the three Record-only choices are fixed (each one, the other
        // way, would be a way round the approval), so they are shown but not offered.
        function syncApprovalRules() {
            const mode = (document.querySelector('input[name="arMode"]:checked') || {}).value || 'record';
            document.getElementById('approvalRulesForm').classList.toggle('is-enforce', mode === 'enforce');
        }

        async function saveApprovalRules() {
            const ticks = name => [...document.querySelectorAll('input[name="' + name + '"]:checked')].map(i => i.value);
            const payload = {
                mode: (document.querySelector('input[name="arMode"]:checked') || {}).value || 'record',
                own_allowed: document.getElementById('arOwnAllowed').value === '1',
                own_people: ticks('arOwnPeople'),
                override: document.getElementById('arOverride').value === '1',
                material: ticks('arMaterial'),
                edit_after: document.getElementById('arEditAfter').value,
                stale_vote: document.getElementById('arStaleVote').value,
                delete_submitted: document.getElementById('arDeleteSubmitted').value,
                reject_status_id: document.getElementById('arRejectStatus').value,
                cancel_status_id: document.getElementById('arCancelStatus').value,
                choose_approvers: document.getElementById('arChooseApprovers').value,
                templates_enabled: document.getElementById('arTemplates').value === '1'
            };
            try {
                const res = await fetch(API_BASE + 'save_approval_settings.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) { showToast(window.t('change-management.toast.saved'), 'success'); loadApprovalRules(); }
                else showToast(data.error || window.t('change-management.toast.save_failed'), 'error');
            } catch (e) { showToast(window.t('change-management.toast.save_failed'), 'error'); }
        }

        // ============================================================
        // Lookup tabs (Statuses / Priorities / Types / Impacts)
        // ============================================================

        // Per-kind metadata: API filenames, response key, table id, column count, fields shown.
        const LOOKUP_KINDS = {
            'status':   { get: 'get_change_statuses.php',   save: 'save_change_status.php',   del: 'delete_change_status.php',   listKey: 'statuses',   tableId: 'statuses-list',   colspan: 8, hasClosed: true,  hasStage: true, label: window.t('change-management.settings.kind_status')   },
            'priority': { get: 'get_change_priorities.php', save: 'save_change_priority.php', del: 'delete_change_priority.php', listKey: 'priorities', tableId: 'priorities-list', colspan: 6, hasClosed: false, label: window.t('change-management.settings.kind_priority') },
            'type':     { get: 'get_change_types.php',      save: 'save_change_type.php',     del: 'delete_change_type.php',     listKey: 'types',      tableId: 'types-list',      colspan: 7, hasClosed: false, hasRoute: true, label: window.t('change-management.settings.kind_type')     },
            'impact':   { get: 'get_change_impacts.php',    save: 'save_change_impact.php',   del: 'delete_change_impact.php',   listKey: 'impacts',    tableId: 'impacts-list',    colspan: 6, hasClosed: false, label: window.t('change-management.settings.kind_impact')   }
        };

        const lookupCache = { status: [], priority: [], type: [], impact: [] };

        async function loadLookups() {
            for (const kind of Object.keys(LOOKUP_KINDS)) {
                await loadLookup(kind);
            }
        }

        async function loadLookup(kind) {
            const cfg = LOOKUP_KINDS[kind];
            try {
                const res = await fetch(API_BASE + cfg.get);
                const data = await res.json();
                if (data.success) {
                    lookupCache[kind] = data[cfg.listKey] || [];
                    renderLookup(kind);
                }
            } catch (e) {
                console.error(e);
            }
        }

        function renderLookup(kind) {
            const cfg = LOOKUP_KINDS[kind];
            const rows = lookupCache[kind];
            const tbody = document.getElementById(cfg.tableId);
            if (!rows || rows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="${cfg.colspan}" style="text-align:center;">${window.t('change-management.settings.no_items')}</td></tr>`;
                return;
            }
            tbody.innerHTML = rows.map(r => {
                const safeName = escapeHtml(r.name).replace(/'/g, "\\'");
                const swatch = r.colour
                    ? `<span class="swatch" style="background:${escapeHtml(r.colour)};"></span><code style="font-size:12px;">${escapeHtml(r.colour)}</code>`
                    : '<span class="badge-no">—</span>';
                // A status with no stage is shown loudly: approvals cannot be enforced
                // until every active status has one (includes/changes/approvals.php).
                const stageCol = cfg.hasStage
                    ? (r.stage
                        ? `<td>${escapeHtml(window.t('change-management.stage.' + r.stage))}</td>`
                        : `<td><span class="stage-unset" title="${escapeHtml(window.t('change-management.settings.stage_unset_marker'))}">${escapeHtml(window.t('change-management.stage.none'))}</span></td>`)
                    : '';
                const routeCol = cfg.hasRoute
                    ? `<td>${escapeHtml(window.t('change-management.settings.route_' + (r.approval_route || 'review')))}</td>`
                    : '';
                const closedCol = cfg.hasClosed
                    ? `<td>${r.is_closed ? `<span class="badge-yes">${window.t('change-management.settings.yes')}</span>` : `<span class="badge-no">${window.t('change-management.settings.no')}</span>`}</td>`
                    : '';
                return `
                <tr>
                    <td><strong>${escapeHtml(r.name)}</strong></td>
                    ${stageCol}
                    ${routeCol}
                    <td>${swatch}</td>
                    ${closedCol}
                    <td>${r.is_default ? `<span class="badge-yes">${window.t('change-management.settings.yes')}</span>` : `<span class="badge-no">${window.t('change-management.settings.no')}</span>`}</td>
                    <td>${r.display_order}</td>
                    <td><span class="status-badge status-${r.is_active ? 'active' : 'inactive'}">${r.is_active ? window.t('change-management.settings.active') : window.t('change-management.settings.inactive')}</span></td>
                    <td>
                        <button class="action-btn" onclick="editLookup('${kind}', ${r.id})" title="${window.t('change-management.settings.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteLookup('${kind}', ${r.id}, '${safeName}')" title="${window.t('change-management.settings.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        function escapeHtml(text) {
            if (text == null) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function openLookupModal(kind) {
            const cfg = LOOKUP_KINDS[kind];
            document.getElementById('lookupModalTitle').textContent = window.t('change-management.settings.modal_add', { kind: cfg.label });
            document.getElementById('lookupItemKind').value = kind;
            document.getElementById('lookupItemId').value = '';
            document.getElementById('lookupItemName').value = '';
            document.getElementById('lookupItemColour').value = '#2563eb';
            document.getElementById('lookupItemClosed').checked = false;
            document.getElementById('lookupItemDefault').checked = false;
            document.getElementById('lookupItemOrder').value = '0';
            document.getElementById('lookupItemActive').checked = true;
            document.getElementById('lookupItemStage').value = '';
            document.getElementById('lookupItemStageGroup').style.display = cfg.hasStage ? '' : 'none';
            document.getElementById('lookupItemRoute').value = 'review';
            document.getElementById('lookupItemRouteGroup').style.display = cfg.hasRoute ? '' : 'none';
            document.getElementById('lookupItemClosedGroup').style.display = cfg.hasClosed ? '' : 'none';
            syncClosedToStage();
            document.getElementById('lookupModal').classList.add('active');
        }

        function editLookup(kind, id) {
            const cfg = LOOKUP_KINDS[kind];
            const item = (lookupCache[kind] || []).find(r => r.id == id);
            if (!item) return;
            document.getElementById('lookupModalTitle').textContent = window.t('change-management.settings.modal_edit', { kind: cfg.label });
            document.getElementById('lookupItemKind').value = kind;
            document.getElementById('lookupItemId').value = item.id;
            document.getElementById('lookupItemName').value = item.name;
            document.getElementById('lookupItemColour').value = item.colour || '#2563eb';
            document.getElementById('lookupItemClosed').checked = !!item.is_closed;
            document.getElementById('lookupItemDefault').checked = !!item.is_default;
            document.getElementById('lookupItemOrder').value = item.display_order;
            document.getElementById('lookupItemActive').checked = !!item.is_active;
            document.getElementById('lookupItemStage').value = item.stage || '';
            document.getElementById('lookupItemStageGroup').style.display = cfg.hasStage ? '' : 'none';
            document.getElementById('lookupItemRoute').value = item.approval_route || 'review';
            document.getElementById('lookupItemRouteGroup').style.display = cfg.hasRoute ? '' : 'none';
            document.getElementById('lookupItemClosedGroup').style.display = cfg.hasClosed ? '' : 'none';
            syncClosedToStage();
            document.getElementById('lookupModal').classList.add('active');
        }

        // Closed follows the stage (Done and Stopped are closed, the rest open), so the
        // tick box is only offered for a status that has no stage yet. The server
        // applies the same rule, so this is comfort, not the control.
        function syncClosedToStage() {
            const kind = document.getElementById('lookupItemKind').value;
            if (!LOOKUP_KINDS[kind] || !LOOKUP_KINDS[kind].hasStage) return;
            const stage = document.getElementById('lookupItemStage').value;
            const closed = document.getElementById('lookupItemClosed');
            if (stage) closed.checked = (stage === 'done' || stage === 'stopped');
            document.getElementById('lookupItemClosedGroup').style.display = stage ? 'none' : '';
        }

        function closeLookupModal() {
            document.getElementById('lookupModal').classList.remove('active');
        }

        async function deleteLookup(kind, id, name) {
            if (!(await showConfirm({ title: window.t('change-management.settings.delete'), message: window.t('change-management.settings.delete_confirm', { name }), okLabel: window.t('change-management.settings.delete'), okClass: 'danger' }))) return;
            const cfg = LOOKUP_KINDS[kind];
            try {
                const res = await fetch(API_BASE + cfg.del, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id: id})
                });
                const data = await res.json();
                if (data.success) {
                    showToast(window.t('change-management.toast.deleted'), 'success');
                    loadLookup(kind);
                } else {
                    showToast(data.error || window.t('change-management.toast.delete_failed'), 'error');
                }
            } catch (e) {
                showToast(window.t('change-management.toast.delete_failed'), 'error');
            }
        }

        document.getElementById('lookupForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const kind = document.getElementById('lookupItemKind').value;
            const cfg = LOOKUP_KINDS[kind];
            const payload = {
                id: document.getElementById('lookupItemId').value || null,
                name: document.getElementById('lookupItemName').value,
                colour: document.getElementById('lookupItemColour').value,
                is_default: document.getElementById('lookupItemDefault').checked ? 1 : 0,
                display_order: parseInt(document.getElementById('lookupItemOrder').value || '0', 10),
                is_active: document.getElementById('lookupItemActive').checked ? 1 : 0
            };
            if (cfg.hasClosed) payload.is_closed = document.getElementById('lookupItemClosed').checked ? 1 : 0;
            if (cfg.hasStage) payload.stage = document.getElementById('lookupItemStage').value;
            if (cfg.hasRoute) payload.approval_route = document.getElementById('lookupItemRoute').value;

            try {
                const res = await fetch(API_BASE + cfg.save, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    closeLookupModal();
                    showToast(window.t('change-management.toast.saved'), 'success');
                    loadLookup(kind);
                } else {
                    showToast(data.error || window.t('change-management.toast.save_failed'), 'error');
                }
            } catch (e) {
                showToast(window.t('change-management.toast.save_failed'), 'error');
            }
        });

        // ============================================================
        // Form fields tab — load / render / auto-save
        // ============================================================
        async function loadSettings() {
            try {
                const res = await fetch(API_BASE + 'get_field_layout.php');
                const data = await res.json();
                if (data.success) {
                    layout.sections = data.sections || [];
                    layout.fields   = data.fields   || [];
                    layout.unplaced = data.unplaced || [];
                }
            } catch (e) {
                console.error('Failed to load field layout:', e);
            }
            renderFieldSettings();
        }

        function renderFieldSettings() {
            const container = document.getElementById('fieldSettings');
            const sections = [...layout.sections].sort((a, b) => a.display_order - b.display_order);

            let html = '';
            sections.forEach(section => {
                const fieldsInSection = layout.fields
                    .filter(f => f.section_id === section.id)
                    .sort((a, b) => a.display_order - b.display_order);

                html += `
                    <div class="section-card" data-section-id="${section.id}" draggable="true">
                        <div class="section-card-header">
                            <span class="drag-handle" title="${window.t('change-management.settings.rename_section')}">⋮⋮</span>
                            <input type="text" class="section-name-input"
                                   value="${escapeAttr(section.name)}"
                                   data-section-id="${section.id}"
                                   onblur="renameSection(${section.id}, this.value)"
                                   onkeydown="if (event.key === 'Enter') this.blur();">
                            <button type="button" class="section-delete-btn"
                                    title="${window.t('change-management.settings.delete_section')}"
                                    onclick="deleteSection(${section.id})">&times;</button>
                        </div>
                        <div class="section-fields" data-section-id="${section.id}">
                            ${fieldsInSection.map(f => renderFieldRow(f)).join('')}
                        </div>
                    </div>
                `;
            });

            // Unplaced fields — catalogue entries that have no layout row yet
            // (e.g. a newly-added field key or fields orphaned by a deleted
            // section). The admin needs to drag them into a section before
            // they appear on the change form.
            if (layout.unplaced.length > 0) {
                html += `
                    <div class="unplaced-fields">
                        <h4>${window.t('change-management.settings.unplaced_heading')}</h4>
                        <p class="hint">${window.t('change-management.settings.unplaced_intro')}</p>
                        ${layout.unplaced.map(f => renderFieldRow({
                            key: f.key, label: f.label,
                            section_id: null, display_order: 0, is_visible: true
                        }, true)).join('')}
                    </div>
                `;
            }

            container.innerHTML = html;
            wireDragAndDrop();
        }

        function renderFieldRow(field, isUnplaced) {
            return `
                <div class="field-row" data-field-key="${field.key}" draggable="true">
                    <span class="drag-handle" title="${window.t('change-management.settings.drag_reorder')}">⋮⋮</span>
                    <span class="field-row-label">${escapeHtml(field.label)}</span>
                    ${isUnplaced ? '' : `
                        <label class="toggle-switch" title="${window.t('change-management.settings.toggle_field')}">
                            <input type="checkbox" ${field.is_visible ? 'checked' : ''}
                                   onchange="toggleFieldVisibility('${field.key}', this.checked)">
                            <span class="toggle-slider"></span>
                        </label>
                    `}
                </div>
            `;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : text;
            return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        function escapeAttr(text) {
            return escapeHtml(text).replace(/"/g, '&quot;');
        }

        // ----- State mutations (each triggers an auto-save) -----

        function addSection() {
            const id = nextTempSectionId--;
            const maxOrder = layout.sections.reduce((m, s) => Math.max(m, s.display_order), 0);
            layout.sections.push({ id, name: window.t('change-management.settings.new_section'), display_order: maxOrder + 10 });
            renderFieldSettings();
            // Focus the new name input so the user can rename immediately
            requestAnimationFrame(() => {
                const input = document.querySelector(`input.section-name-input[data-section-id="${id}"]`);
                if (input) { input.focus(); input.select(); }
            });
            scheduleAutoSave();
        }

        function renameSection(sectionId, newName) {
            const section = layout.sections.find(s => s.id === sectionId);
            if (!section) return;
            const trimmed = (newName || '').trim();
            if (!trimmed || trimmed === section.name) return;
            section.name = trimmed;
            scheduleAutoSave();
        }

        async function deleteSection(sectionId) {
            const section = layout.sections.find(s => s.id === sectionId);
            if (!section) return;
            const fieldsInSection = layout.fields.filter(f => f.section_id === sectionId);
            if (fieldsInSection.length > 0) {
                // Pick the string by count rather than passing English words in as
                // placeholder values - see lang/en/change-management.php.
                const n = fieldsInSection.length;
                const msg = window.t(n === 1
                    ? 'change-management.settings.delete_section_fields_one'
                    : 'change-management.settings.delete_section_fields_many', {
                    name: section.name,
                    count: n
                });
                if (!(await showConfirm({ title: window.t('change-management.settings.delete_section'), message: msg, okLabel: window.t('change-management.settings.delete'), okClass: 'danger' }))) return;
            } else if (!(await showConfirm({ title: window.t('change-management.settings.delete'), message: window.t('change-management.settings.delete_section_confirm', { name: section.name }), okLabel: window.t('change-management.settings.delete'), okClass: 'danger' }))) return;
            // Locally: remove the section and the fields-in-section. The
            // fields then re-surface as "unplaced" after the server save.
            layout.sections = layout.sections.filter(s => s.id !== sectionId);
            const orphaned = layout.fields.filter(f => f.section_id === sectionId);
            layout.fields = layout.fields.filter(f => f.section_id !== sectionId);
            orphaned.forEach(f => layout.unplaced.push({ key: f.key, label: f.label }));
            renderFieldSettings();
            scheduleAutoSave();
        }

        function toggleFieldVisibility(fieldKey, isVisible) {
            const field = layout.fields.find(f => f.key === fieldKey);
            if (!field) return;
            field.is_visible = !!isVisible;
            scheduleAutoSave();
        }

        // ----- Drag-and-drop wiring -----
        //
        // Two interactions:
        //   - Drag a .section-card by its header (drag handle) to reorder sections
        //   - Drag a .field-row by its handle to reorder within a section
        //     or move it between sections (including from / to "Unplaced")
        //
        // We use HTML5 native drag-and-drop. The dragged item carries its
        // type + identifier on dataTransfer; drop targets accept based on type.
        let draggedSectionId = null;
        let draggedFieldKey  = null;

        function wireDragAndDrop() {
            // Section drag
            document.querySelectorAll('.section-card').forEach(card => {
                card.addEventListener('dragstart', e => {
                    // Don't initiate section drag if the user is dragging a field row
                    if (e.target.closest('.field-row')) {
                        e.stopPropagation();
                        return;
                    }
                    draggedSectionId = parseInt(card.dataset.sectionId, 10);
                    draggedFieldKey = null;
                    card.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', `section:${draggedSectionId}`);
                });
                card.addEventListener('dragend', () => {
                    card.classList.remove('dragging');
                    document.querySelectorAll('.section-card').forEach(c =>
                        c.classList.remove('drop-target-section'));
                });
                card.addEventListener('dragover', e => {
                    if (draggedSectionId == null) return;
                    if (parseInt(card.dataset.sectionId, 10) === draggedSectionId) return;
                    e.preventDefault();
                    document.querySelectorAll('.section-card').forEach(c =>
                        c.classList.remove('drop-target-section'));
                    card.classList.add('drop-target-section');
                });
                card.addEventListener('drop', e => {
                    if (draggedSectionId == null) return;
                    e.preventDefault();
                    e.stopPropagation();
                    const targetId = parseInt(card.dataset.sectionId, 10);
                    if (targetId === draggedSectionId) return;
                    reorderSection(draggedSectionId, targetId);
                });
            });

            // Field-row drag
            document.querySelectorAll('.field-row').forEach(row => {
                row.addEventListener('dragstart', e => {
                    e.stopPropagation(); // Don't bubble up to section drag
                    draggedFieldKey = row.dataset.fieldKey;
                    draggedSectionId = null;
                    row.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', `field:${draggedFieldKey}`);
                });
                row.addEventListener('dragend', () => {
                    row.classList.remove('dragging');
                    document.querySelectorAll('.field-row').forEach(r =>
                        r.classList.remove('drop-target-field'));
                });
                row.addEventListener('dragover', e => {
                    if (draggedFieldKey == null) return;
                    if (row.dataset.fieldKey === draggedFieldKey) return;
                    e.preventDefault();
                    document.querySelectorAll('.field-row').forEach(r =>
                        r.classList.remove('drop-target-field'));
                    row.classList.add('drop-target-field');
                });
                row.addEventListener('drop', e => {
                    if (draggedFieldKey == null) return;
                    e.preventDefault();
                    e.stopPropagation();
                    const targetKey = row.dataset.fieldKey;
                    if (targetKey === draggedFieldKey) return;
                    moveFieldBeforeField(draggedFieldKey, targetKey);
                });
            });

            // Drop a field on an empty section's body (so empty sections can
            // receive fields). Each section-fields container accepts drops.
            document.querySelectorAll('.section-fields').forEach(zone => {
                zone.addEventListener('dragover', e => {
                    if (draggedFieldKey == null) return;
                    e.preventDefault();
                });
                zone.addEventListener('drop', e => {
                    if (draggedFieldKey == null) return;
                    // Ignore if the drop landed on a child field-row — that
                    // row's own drop handler covers it.
                    if (e.target.closest('.field-row')) return;
                    e.preventDefault();
                    e.stopPropagation();
                    const sectionId = parseInt(zone.dataset.sectionId, 10);
                    moveFieldToSectionEnd(draggedFieldKey, sectionId);
                });
            });
        }

        function reorderSection(draggedId, targetId) {
            const sections = [...layout.sections].sort((a, b) => a.display_order - b.display_order);
            const dragged = sections.find(s => s.id === draggedId);
            const targetIdx = sections.findIndex(s => s.id === targetId);
            if (!dragged || targetIdx < 0) return;
            const without = sections.filter(s => s.id !== draggedId);
            without.splice(targetIdx, 0, dragged);
            without.forEach((s, i) => { s.display_order = (i + 1) * 10; });
            layout.sections = without;
            renderFieldSettings();
            scheduleAutoSave();
        }

        function moveFieldBeforeField(draggedKey, targetKey) {
            const dragged = layout.fields.find(f => f.key === draggedKey)
                          || promoteUnplacedToField(draggedKey);
            const target  = layout.fields.find(f => f.key === targetKey);
            if (!dragged || !target) return;
            dragged.section_id = target.section_id;
            // Insert dragged just before target by sliding the orders
            // around. Re-pack the whole section to keep numbers tidy.
            const inSection = layout.fields
                .filter(f => f.section_id === target.section_id && f.key !== draggedKey)
                .sort((a, b) => a.display_order - b.display_order);
            const targetIdx = inSection.findIndex(f => f.key === targetKey);
            inSection.splice(targetIdx, 0, dragged);
            inSection.forEach((f, i) => { f.display_order = (i + 1) * 10; });
            renderFieldSettings();
            scheduleAutoSave();
        }

        function moveFieldToSectionEnd(draggedKey, sectionId) {
            const dragged = layout.fields.find(f => f.key === draggedKey)
                          || promoteUnplacedToField(draggedKey);
            if (!dragged) return;
            dragged.section_id = sectionId;
            const inSection = layout.fields
                .filter(f => f.section_id === sectionId)
                .sort((a, b) => a.display_order - b.display_order);
            const maxOrder = inSection.length > 0
                ? Math.max(...inSection.map(f => f.display_order))
                : 0;
            dragged.display_order = maxOrder + 10;
            renderFieldSettings();
            scheduleAutoSave();
        }

        // Helper: when a field is dragged FROM the Unplaced list, it needs
        // to be promoted into layout.fields so the save endpoint sees it.
        function promoteUnplacedToField(fieldKey) {
            const idx = layout.unplaced.findIndex(f => f.key === fieldKey);
            if (idx < 0) return null;
            const meta = layout.unplaced.splice(idx, 1)[0];
            const newField = {
                key: meta.key,
                label: meta.label,
                section_id: 0,        // placeholder — caller will overwrite
                display_order: 0,
                is_visible: true,
            };
            layout.fields.push(newField);
            return newField;
        }

        // ----- Auto-save -----
        let autoSaveTimer = null;
        function scheduleAutoSave() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(saveLayout, 350);
        }

        async function saveLayout() {
            try {
                const payload = {
                    sections: layout.sections.map(s => ({
                        id: s.id,
                        name: s.name,
                        display_order: s.display_order,
                    })),
                    fields: layout.fields.map(f => ({
                        key: f.key,
                        section_id: f.section_id,
                        display_order: f.display_order,
                        is_visible: f.is_visible,
                    })),
                };
                const res = await fetch(API_BASE + 'save_field_layout.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!data.success) {
                    showToast(window.t('change-management.settings.save_failed', { message: data.error || window.t('change-management.settings.unknown_error') }), 'error');
                    return;
                }
                // Replace local state with the server's authoritative copy.
                // This swaps any negative tempIds for real ones and ensures
                // unplaced is in sync with what's actually in the DB.
                const placedKeys = new Set((data.fields || []).map(f => f.key));
                layout.sections = data.sections || [];
                layout.fields = data.fields || [];
                layout.unplaced = layout.unplaced.filter(u => !placedKeys.has(u.key));
                // Plus any catalogue keys that fell out of layout.fields
                // (e.g. their section was deleted) should appear in unplaced.
                // The server already knows the catalogue — easier to just
                // re-fetch the full layout to refresh `unplaced`.
                fetch(API_BASE + 'get_field_layout.php')
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            layout.unplaced = d.unplaced || [];
                            renderFieldSettings();
                        }
                    });
                showSaveStatus();
            } catch (e) {
                console.error('Auto-save error:', e);
                showToast(window.t('change-management.settings.save_failed', { message: e.message }), 'error');
            }
        }

        function showSaveStatus() {
            const el = document.getElementById('fieldSaveStatus');
            if (!el) return;
            el.classList.add('visible');
            clearTimeout(showSaveStatus._t);
            showSaveStatus._t = setTimeout(() => el.classList.remove('visible'), 1500);
        }

    </script>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=83"></script>
</body>
</html>
