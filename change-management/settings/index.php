<?php
/**
 * Change Management Settings - Configure module behaviour
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'change-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('change-management.page.settings')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=20">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
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
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="fields" onclick="switchTab('fields')"><?php echo htmlspecialchars(t('change-management.settings.tab_fields')); ?></button>
            <button class="tab" data-tab="statuses" onclick="switchTab('statuses')"><?php echo htmlspecialchars(t('change-management.settings.tab_statuses')); ?></button>
            <button class="tab" data-tab="priorities" onclick="switchTab('priorities')"><?php echo htmlspecialchars(t('change-management.settings.tab_priorities')); ?></button>
            <button class="tab" data-tab="types" onclick="switchTab('types')"><?php echo htmlspecialchars(t('change-management.settings.tab_types')); ?></button>
            <button class="tab" data-tab="impacts" onclick="switchTab('impacts')"><?php echo htmlspecialchars(t('change-management.settings.tab_impacts')); ?></button>
            <button class="tab" data-tab="left-panel" onclick="switchTab('left-panel')"><?php echo htmlspecialchars(t('common.left_panel.tab')); ?></button>
        </div>

        <!-- Form Fields Tab -->
        <div class="tab-content active" id="fields-tab">
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
        <div class="tab-content" id="statuses-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('change-management.settings.statuses_heading')); ?></h2>
                <button class="add-btn" onclick="openLookupModal('status')"><?php echo htmlspecialchars(t('change-management.settings.add')); ?></button>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo t('change-management.settings.statuses_intro'); ?></p>
            <table class="lookup-table">
                <thead><tr><th><?php echo htmlspecialchars(t('change-management.settings.col_name')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_colour')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_closed')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_default')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_order')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_status')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_actions')); ?></th></tr></thead>
                <tbody id="statuses-list"><tr><td colspan="7" style="text-align:center;"><?php echo htmlspecialchars(t('change-management.settings.loading')); ?></td></tr></tbody>
            </table>
        </div>

        <!-- Priorities Tab -->
        <div class="tab-content" id="priorities-tab">
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
        <div class="tab-content" id="types-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('change-management.settings.types_heading')); ?></h2>
                <button class="add-btn" onclick="openLookupModal('type')"><?php echo htmlspecialchars(t('change-management.settings.add')); ?></button>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('change-management.settings.types_intro')); ?></p>
            <table class="lookup-table">
                <thead><tr><th><?php echo htmlspecialchars(t('change-management.settings.col_name')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_colour')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_default')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_order')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_status')); ?></th><th><?php echo htmlspecialchars(t('change-management.settings.col_actions')); ?></th></tr></thead>
                <tbody id="types-list"><tr><td colspan="6" style="text-align:center;"><?php echo htmlspecialchars(t('change-management.settings.loading')); ?></td></tr></tbody>
            </table>
        </div>

        <!-- Impacts Tab -->
        <div class="tab-content" id="impacts-tab">
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
        <div class="tab-content" id="left-panel-tab">
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

    <!-- Toast notification -->

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

        document.addEventListener('DOMContentLoaded', function() {
            loadSettings();
            loadLookups();
        });

        // ============================================================
        // Lookup tabs (Statuses / Priorities / Types / Impacts)
        // ============================================================

        // Per-kind metadata: API filenames, response key, table id, column count, fields shown.
        const LOOKUP_KINDS = {
            'status':   { get: 'get_change_statuses.php',   save: 'save_change_status.php',   del: 'delete_change_status.php',   listKey: 'statuses',   tableId: 'statuses-list',   colspan: 7, hasClosed: true,  label: window.t('change-management.settings.kind_status')   },
            'priority': { get: 'get_change_priorities.php', save: 'save_change_priority.php', del: 'delete_change_priority.php', listKey: 'priorities', tableId: 'priorities-list', colspan: 6, hasClosed: false, label: window.t('change-management.settings.kind_priority') },
            'type':     { get: 'get_change_types.php',      save: 'save_change_type.php',     del: 'delete_change_type.php',     listKey: 'types',      tableId: 'types-list',      colspan: 6, hasClosed: false, label: window.t('change-management.settings.kind_type')     },
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
                const closedCol = cfg.hasClosed
                    ? `<td>${r.is_closed ? `<span class="badge-yes">${window.t('change-management.settings.yes')}</span>` : `<span class="badge-no">${window.t('change-management.settings.no')}</span>`}</td>`
                    : '';
                return `
                <tr>
                    <td><strong>${escapeHtml(r.name)}</strong></td>
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
            return div.innerHTML;
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
            document.getElementById('lookupItemClosedGroup').style.display = cfg.hasClosed ? '' : 'none';
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
            document.getElementById('lookupItemClosedGroup').style.display = cfg.hasClosed ? '' : 'none';
            document.getElementById('lookupModal').classList.add('active');
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
            return div.innerHTML;
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
                const msg = window.t('change-management.settings.delete_section_fields', {
                    name: section.name,
                    count: fieldsInSection.length,
                    plural: fieldsInSection.length === 1 ? '' : 's',
                    them: fieldsInSection.length === 1 ? 'it' : 'them'
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
</body>
</html>
