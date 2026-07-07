<?php
/**
 * CMDB Settings — Classes, Relationship Types, AI Integration
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/ai_settings_panel.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'cmdb'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeITSM - <?php echo htmlspecialchars(t('cmdb.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=15">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        body { background: var(--app-bg,#f5f5f5); --accent: var(--cmdb-accent); }
        .container { height: calc(100vh - 48px); overflow-y: auto; max-width: none; margin: 24px 0; padding: 0 20px; }
        /* Tabs + tab-content come from inbox.css (canonical). Only the
           module-accent colour is overridden here so the active tab + hover
           read as CMDB-magenta. */
        .tab:hover { color: var(--cmdb-accent,#be185d); }
        .tab.active { color: var(--cmdb-accent,#be185d); border-bottom-color: var(--cmdb-accent,#be185d); }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .section-header h2 { font-size: 18px; color: var(--text,#111827); margin: 0; }
        .add-btn {
            background: var(--cmdb-accent,#be185d);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 13px;
        }
        .add-btn:hover { background: var(--cmdb-accent-hover,#9d174d); }

        table { width: 100%; border-collapse: collapse; }
        thead th {
            text-align: left;
            padding: 10px 12px;
            font-size: 12px;
            text-transform: uppercase;
            color: var(--text-muted,#6b7280);
            border-bottom: 1px solid var(--border,#e5e7eb);
            font-weight: 600;
        }
        tbody td { padding: 12px; border-bottom: 1px solid var(--border-soft,#f3f4f6); font-size: 14px; color: var(--text,#1f2937); }
        tbody tr:hover { background: var(--surface-2,#fafafa); }

        .action-btn {
            background: none;
            border: 1px solid var(--border,#ddd);
            color: var(--text-muted,#666);
            cursor: pointer;
            padding: 6px;
            margin-right: 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .action-btn:hover { background: #fdf2f8; border-color: var(--cmdb-accent,#be185d); color: var(--cmdb-accent,#be185d); }
        [data-theme-mode="dark"] .action-btn:hover { background: rgba(190,24,93,0.18); }
        .action-btn.delete { color: #d13438; }
        .action-btn.delete:hover { background: #fdf3f3; border-color: #d13438; color: #a00; }
        .action-btn svg { width: 14px; height: 14px; }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: 12px;
            border-radius: 999px;
            background: var(--surface-3, #f3f4f6);
            color: var(--text, #374151);
        }
        .badge.active { background: #dcfce7; color: #166534; }
        .badge.inactive { background: #fee2e2; color: #991b1b; }
        .badge.clickable { cursor: pointer; }
        .badge.clickable:hover { background: #fce7f3; color: var(--cmdb-accent, #be185d); }
        [data-theme-mode="dark"] .badge.clickable:hover { background: rgba(190, 24, 93, 0.18); }
        .badge.type { background: #ede9fe; color: #6d28d9; font-family: 'Consolas', monospace; }

        /* Modal sizing — base modal / form / .btn / .btn-secondary CSS lives in
           inbox.css (#453, #454). Only the module accent colour is overridden
           here so primary buttons read as CMDB-magenta; .btn-test is a
           tertiary action used for the Test connection button. */
        .modal-content { width: 600px; max-width: 95vw; }
        .modal-content.wide { width: 900px; }
        .form-check { display: flex; align-items: center; gap: 8px; font-size: 14px; }
        .form-check input[type="checkbox"] { width: auto; }

        .btn-primary { background: var(--cmdb-accent,#be185d); color: white; }
        .btn-primary:hover { background: var(--cmdb-accent-hover,#9d174d); }
        .btn-test { background: #6b7280; color: white; }
        .btn-test:hover { background: #4b5563; }

        .empty-row { text-align: center; padding: 30px; color: var(--text-dim,#9ca3af); font-style: italic; }
        .key-hint { font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; color: var(--text-muted,#6b7280); }

        .test-result { margin-top: 16px; padding: 10px 14px; border-radius: 4px; font-size: 13px; display: none; }
        .test-result.success { background: var(--success-bg,#dcfce7); color: var(--success-text,#166534); display: block; }
        .test-result.error { background: var(--danger-bg,#fee2e2); color: var(--danger-text,#991b1b); display: block; }

        /* AI tab: two-column form so Custom instructions sits next to API key + Model
           and gets vertical room for a longer textarea without scrolling the page. */
        .ai-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 28px;
            margin-top: 24px;
        }
        .ai-col-right { display: flex; flex-direction: column; }
        .ai-col-right .form-group { flex: 1; display: flex; flex-direction: column; }
        .ai-col-right textarea { flex: 1; min-height: 200px; resize: vertical; }
        @media (max-width: 900px) {
            .ai-form-grid { grid-template-columns: 1fr; }
        }

        /* AI suggestion button — distinct from primary so it reads as an "extra" action */
        .btn-ai {
            background: linear-gradient(135deg, #ec4899, #be185d);
            color: white;
            border: none;
            padding: 8px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
        }
        .btn-ai:hover { background: linear-gradient(135deg, #db2777, #9d174d); }
        .btn-ai:disabled { opacity: 0.6; cursor: not-allowed; }

        /* AI Suggest modal */
        .ai-stage { display: none; }
        .ai-stage.active { display: block; }
        .ai-loading { padding: 40px; text-align: center; color: var(--text-muted,#6b7280); font-size: 14px; }
        .ai-loading .spinner-dot {
            display: inline-block; width: 8px; height: 8px; margin: 0 3px;
            background: var(--cmdb-accent,#be185d); border-radius: 50%; animation: aiblink 1.4s infinite both;
        }
        .ai-loading .spinner-dot:nth-child(2) { animation-delay: 0.2s; }
        .ai-loading .spinner-dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes aiblink { 0%, 80%, 100% { opacity: 0.3; } 40% { opacity: 1; } }

        .ai-question {
            margin-bottom: 18px;
            padding: 14px;
            background: #fdf2f8;
            border-left: 3px solid var(--cmdb-accent,#be185d);
            border-radius: 0 4px 4px 0;
        }
        [data-theme-mode="dark"] .ai-question { background: rgba(190,24,93,0.14); }
        .ai-question label { font-weight: 500; color: var(--text,#1f2937); display: block; margin-bottom: 6px; font-size: 14px; }
        .ai-question .examples { color: var(--text-muted,#6b7280); font-size: 12px; font-style: italic; margin-bottom: 8px; }
        .ai-question input { width: 100%; padding: 8px 10px; border: 1px solid var(--border,#e5e7eb); border-radius: 4px; font-size: 13px; }
        .ai-question input:focus { outline: none; border-color: var(--cmdb-accent,#be185d); }

        .ai-suggestion {
            display: flex;
            gap: 12px;
            padding: 12px;
            border: 1px solid var(--border,#e5e7eb);
            border-radius: 6px;
            margin-bottom: 8px;
            transition: background 0.15s;
        }
        .ai-suggestion:hover { background: var(--surface-2,#fafafa); }
        .ai-suggestion input[type="checkbox"] { margin-top: 4px; flex-shrink: 0; transform: scale(1.2); accent-color: var(--cmdb-accent,#be185d); }
        .ai-suggestion .sug-body { flex: 1; min-width: 0; }
        .ai-suggestion .sug-head { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 4px; }
        .ai-suggestion .sug-label { font-weight: 600; color: var(--text,#1f2937); font-size: 14px; }
        .ai-suggestion .sug-key { font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; color: var(--text-muted,#6b7280); }
        .ai-suggestion .sug-why { color: var(--text-muted,#4b5563); font-size: 13px; line-height: 1.4; margin-top: 4px; }
        .ai-suggestion .sug-meta { color: var(--text-muted,#6b7280); font-size: 12px; margin-top: 4px; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="classes" onclick="switchTab('classes')"><?php echo htmlspecialchars(t('cmdb.settings.tab_classes')); ?></button>
            <button class="tab" data-tab="relationship-types" onclick="switchTab('relationship-types')"><?php echo htmlspecialchars(t('cmdb.settings.tab_rel_types')); ?></button>
            <button class="tab" data-tab="ai" onclick="switchTab('ai')"><?php echo htmlspecialchars(t('cmdb.settings.tab_ai')); ?></button>
            <button class="tab" data-tab="left-panel" onclick="switchTab('left-panel')"><?php echo htmlspecialchars(t('common.left_panel.tab')); ?></button>
        </div>

        <!-- Classes Tab -->
        <div class="tab-content active" id="classes-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('cmdb.settings.classes_heading')); ?></h2>
                <button class="add-btn" onclick="openClassModal()"><?php echo htmlspecialchars(t('cmdb.settings.add')); ?></button>
            </div>
            <p style="color: var(--text-muted,#6b7280); font-size: 13px; margin-bottom: 16px; max-width: 720px;">
                <?php echo t('cmdb.settings.classes_intro'); ?>
            </p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('cmdb.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('cmdb.settings.col_key')); ?></th>
                        <th><?php echo htmlspecialchars(t('cmdb.settings.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('cmdb.settings.col_properties')); ?></th>
                        <th><?php echo htmlspecialchars(t('cmdb.settings.col_order')); ?></th>
                        <th><?php echo htmlspecialchars(t('cmdb.settings.col_active')); ?></th>
                        <th style="width: 130px;"><?php echo htmlspecialchars(t('cmdb.settings.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="classesTableBody">
                    <tr><td colspan="7" class="empty-row"><?php echo htmlspecialchars(t('cmdb.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Relationship Types Tab -->
        <div class="tab-content" id="relationship-types-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('cmdb.settings.rel_types_heading')); ?></h2>
                <button class="add-btn" onclick="openRelTypeModal()"><?php echo htmlspecialchars(t('cmdb.settings.add')); ?></button>
            </div>
            <p style="color: var(--text-muted,#6b7280); font-size: 13px; margin-bottom: 16px; max-width: 720px;">
                <?php echo t('cmdb.settings.rel_types_intro'); ?>
            </p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('cmdb.settings.col_verb')); ?></th>
                        <th><?php echo htmlspecialchars(t('cmdb.settings.col_inverse_verb')); ?></th>
                        <th><?php echo htmlspecialchars(t('cmdb.settings.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('cmdb.settings.col_order')); ?></th>
                        <th><?php echo htmlspecialchars(t('cmdb.settings.col_active')); ?></th>
                        <th style="width: 130px;"><?php echo htmlspecialchars(t('cmdb.settings.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="relTypesTableBody">
                    <tr><td colspan="6" class="empty-row"><?php echo htmlspecialchars(t('cmdb.settings.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- AI Integration Tab -->
        <div class="tab-content" id="ai-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('cmdb.settings.ai_heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted,#555); font-size: 14px;">
                <?php echo t('cmdb.settings.ai_intro1'); ?>
            </p>
            <p style="color: var(--text-muted,#555); font-size: 14px;">
                <?php echo htmlspecialchars(t('cmdb.settings.ai_intro2')); ?>
            </p>

            <!-- Provider / model / key — shared reusable panel (Anthropic / OpenAI / OpenRouter). -->
            <?php renderAiSettingsPanel('cmdb_ai'); ?>

            <!-- CMDB-specific custom instructions, saved separately. -->
            <form id="cmdbAiExtrasForm" onsubmit="saveCmdbAiExtras(event)" style="max-width: 640px; margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--border,#e0e0e0);">
                <div class="form-group">
                    <label for="aiCustomInstructions"><?php echo htmlspecialchars(t('cmdb.settings.ai_custom')); ?> <span style="color: var(--text-dim,#999); font-weight: normal;"><?php echo htmlspecialchars(t('cmdb.settings.ai_custom_optional')); ?></span></label>
                    <textarea id="aiCustomInstructions" maxlength="4000"
                              placeholder="<?php echo htmlspecialchars(t('cmdb.settings.ai_custom_placeholder')); ?>"></textarea>
                    <small><?php echo htmlspecialchars(t('cmdb.settings.ai_custom_help')); ?></small>
                </div>
                <div style="margin-top: 12px;">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('cmdb.settings.ai_save')); ?></button>
                </div>
            </form>
        </div>

        <!-- Left panel tab — per-analyst preference -->
        <div class="tab-content" id="left-panel-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('common.left_panel.tab')); ?></h2>
            </div>
            <p style="color: var(--text-muted,#666); margin-bottom: 20px;"><?php echo htmlspecialchars(t('cmdb.settings.left_panel_intro')); ?></p>

            <form id="leftPanelForm" autocomplete="off" onsubmit="event.preventDefault();">
                <div class="form-group">
                    <label style="display: block; margin-bottom: 10px; font-weight: 500; color: var(--text,#333);"><?php echo htmlspecialchars(t('common.left_panel.visibility')); ?></label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border,#ddd); border-radius: 6px; margin-bottom: 8px; cursor: pointer;">
                        <input type="radio" name="cmdbSidebarMode" value="always" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('common.left_panel.always')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-muted,#777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('cmdb.settings.left_panel_always_desc')); ?>
                        </span>
                    </label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border,#ddd); border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="cmdbSidebarMode" value="hover" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('common.left_panel.hover')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-muted,#777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('cmdb.settings.left_panel_hover_desc')); ?>
                        </span>
                    </label>
                </div>
            </form>
        </div>
    </div>

    <!-- Class Add/Edit Modal -->
    <div class="modal" id="classModal">
        <div class="modal-content">
            <div class="modal-header" id="classModalTitle"><?php echo htmlspecialchars(t('cmdb.settings.class_modal_add')); ?></div>
            <div class="modal-body">
                <form id="classForm" onsubmit="saveClass(event)">
                    <input type="hidden" id="classId">
                    <div class="form-group">
                        <label for="className"><?php echo htmlspecialchars(t('cmdb.settings.class_name')); ?></label>
                        <input type="text" id="className" required maxlength="150" placeholder="<?php echo htmlspecialchars(t('cmdb.settings.class_name_placeholder')); ?>">
                        <small><?php echo htmlspecialchars(t('cmdb.settings.class_name_help')); ?></small>
                    </div>
                    <div class="form-group">
                        <label for="classDescription"><?php echo htmlspecialchars(t('cmdb.settings.class_description')); ?></label>
                        <textarea id="classDescription" rows="2" maxlength="500" placeholder="<?php echo htmlspecialchars(t('cmdb.settings.class_description_placeholder')); ?>"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="classKey"><?php echo htmlspecialchars(t('cmdb.settings.class_key')); ?></label>
                        <input type="text" id="classKey" maxlength="100" placeholder="<?php echo htmlspecialchars(t('cmdb.settings.class_key_placeholder')); ?>">
                        <small class="key-hint"><?php echo htmlspecialchars(t('cmdb.settings.class_key_help')); ?></small>
                    </div>
                    <div class="form-group">
                        <label for="classDisplayOrder"><?php echo htmlspecialchars(t('cmdb.settings.class_display_order')); ?></label>
                        <input type="number" id="classDisplayOrder" value="0">
                    </div>
                    <div class="form-group">
                        <label class="toggle-label">
                            <span class="toggle-switch">
                                <input type="checkbox" id="classIsActive" checked>
                                <span class="toggle-slider"></span>
                            </span>
                            <?php echo htmlspecialchars(t('cmdb.settings.class_active')); ?>
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeClassModal()"><?php echo htmlspecialchars(t('cmdb.settings.class_cancel')); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveClass()"><?php echo htmlspecialchars(t('cmdb.settings.class_save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Properties Manager Modal (shows props for a single class) -->
    <div class="modal" id="propsModal">
        <div class="modal-content wide">
            <div class="modal-header">
                <?php echo htmlspecialchars(t('cmdb.settings.props_modal_title')); ?> <span id="propsModalClassName"></span>
            </div>
            <div class="modal-body">
                <div class="section-header">
                    <h2 style="font-size: 14px;"><?php echo htmlspecialchars(t('cmdb.settings.props_heading')); ?></h2>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn-ai" onclick="openAiSuggestModal()" title="<?php echo htmlspecialchars(t('cmdb.settings.suggest_ai_title')); ?>">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg>
                            <?php echo htmlspecialchars(t('cmdb.settings.suggest_ai')); ?>
                        </button>
                        <button class="add-btn" onclick="openPropertyModal()"><?php echo htmlspecialchars(t('cmdb.settings.add')); ?></button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('cmdb.settings.col_label')); ?></th>
                            <th><?php echo htmlspecialchars(t('cmdb.settings.col_key')); ?></th>
                            <th><?php echo htmlspecialchars(t('cmdb.settings.col_type')); ?></th>
                            <th><?php echo htmlspecialchars(t('cmdb.settings.col_target_class')); ?></th>
                            <th><?php echo htmlspecialchars(t('cmdb.settings.col_required')); ?></th>
                            <th><?php echo htmlspecialchars(t('cmdb.settings.col_order')); ?></th>
                            <th style="width: 130px;"><?php echo htmlspecialchars(t('cmdb.settings.col_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="propsTableBody">
                        <tr><td colspan="7" class="empty-row"><?php echo htmlspecialchars(t('cmdb.settings.loading')); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePropsModal()"><?php echo htmlspecialchars(t('cmdb.settings.props_done')); ?></button>
            </div>
        </div>
    </div>

    <!-- Property Add/Edit Modal -->
    <div class="modal" id="propertyModal">
        <div class="modal-content">
            <div class="modal-header" id="propertyModalTitle"><?php echo htmlspecialchars(t('cmdb.settings.prop_modal_add')); ?></div>
            <div class="modal-body">
                <form id="propertyForm" onsubmit="saveProperty(event)">
                    <input type="hidden" id="propertyId">
                    <div class="form-group">
                        <label for="propertyLabel"><?php echo htmlspecialchars(t('cmdb.settings.prop_label')); ?></label>
                        <input type="text" id="propertyLabel" required maxlength="150" placeholder="<?php echo htmlspecialchars(t('cmdb.settings.prop_label_placeholder')); ?>">
                        <small><?php echo htmlspecialchars(t('cmdb.settings.prop_label_help')); ?></small>
                    </div>
                    <div class="form-group">
                        <label for="propertyKey"><?php echo htmlspecialchars(t('cmdb.settings.prop_key')); ?></label>
                        <input type="text" id="propertyKey" maxlength="100" placeholder="<?php echo htmlspecialchars(t('cmdb.settings.prop_key_placeholder')); ?>">
                        <small class="key-hint"><?php echo htmlspecialchars(t('cmdb.settings.prop_key_help')); ?></small>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="propertyType"><?php echo htmlspecialchars(t('cmdb.settings.prop_type')); ?></label>
                            <select id="propertyType" required onchange="onPropertyTypeChange()">
                                <option value="text"><?php echo htmlspecialchars(t('cmdb.settings.prop_type_text')); ?></option>
                                <option value="number"><?php echo htmlspecialchars(t('cmdb.settings.prop_type_number')); ?></option>
                                <option value="date"><?php echo htmlspecialchars(t('cmdb.settings.prop_type_date')); ?></option>
                                <option value="boolean"><?php echo htmlspecialchars(t('cmdb.settings.prop_type_boolean')); ?></option>
                                <option value="dropdown"><?php echo htmlspecialchars(t('cmdb.settings.prop_type_dropdown')); ?></option>
                                <option value="object_ref"><?php echo htmlspecialchars(t('cmdb.settings.prop_type_object_ref')); ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="propertyDisplayOrder"><?php echo htmlspecialchars(t('cmdb.settings.prop_display_order')); ?></label>
                            <input type="number" id="propertyDisplayOrder" value="0">
                        </div>
                    </div>
                    <div class="form-group" id="targetClassGroup" style="display: none;">
                        <label for="propertyTargetClass"><?php echo htmlspecialchars(t('cmdb.settings.prop_target_class')); ?></label>
                        <select id="propertyTargetClass">
                            <option value=""><?php echo htmlspecialchars(t('cmdb.settings.prop_select')); ?></option>
                        </select>
                        <small><?php echo htmlspecialchars(t('cmdb.settings.prop_target_class_help')); ?></small>
                    </div>
                    <div class="form-group" id="dropdownOptionsGroup" style="display: none;">
                        <label><?php echo htmlspecialchars(t('cmdb.settings.prop_options')); ?></label>
                        <div id="propertyOptionsContainer"></div>
                        <small><?php echo htmlspecialchars(t('cmdb.settings.prop_options_help')); ?></small>
                    </div>
                    <div class="form-group">
                        <label class="form-check">
                            <input type="checkbox" id="propertyIsRequired"> <?php echo htmlspecialchars(t('cmdb.settings.prop_required')); ?>
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePropertyModal()"><?php echo htmlspecialchars(t('cmdb.settings.prop_cancel')); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveProperty()"><?php echo htmlspecialchars(t('cmdb.settings.prop_save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Relationship Type Add/Edit Modal -->
    <div class="modal" id="relTypeModal">
        <div class="modal-content">
            <div class="modal-header" id="relTypeModalTitle"><?php echo htmlspecialchars(t('cmdb.settings.rel_type_modal_add')); ?></div>
            <div class="modal-body">
                <form id="relTypeForm" onsubmit="saveRelType(event)">
                    <input type="hidden" id="relTypeId">
                    <div class="form-group">
                        <label for="relTypeVerb"><?php echo htmlspecialchars(t('cmdb.settings.rel_type_verb')); ?></label>
                        <input type="text" id="relTypeVerb" required maxlength="100" placeholder="<?php echo htmlspecialchars(t('cmdb.settings.rel_type_verb_placeholder')); ?>">
                        <small><?php echo htmlspecialchars(t('cmdb.settings.rel_type_verb_help')); ?></small>
                    </div>
                    <div class="form-group">
                        <label for="relTypeInverseVerb"><?php echo htmlspecialchars(t('cmdb.settings.rel_type_inverse')); ?></label>
                        <input type="text" id="relTypeInverseVerb" required maxlength="100" placeholder="<?php echo htmlspecialchars(t('cmdb.settings.rel_type_inverse_placeholder')); ?>">
                        <small><?php echo htmlspecialchars(t('cmdb.settings.rel_type_inverse_help')); ?></small>
                    </div>
                    <div class="form-group">
                        <label for="relTypeDescription"><?php echo htmlspecialchars(t('cmdb.settings.rel_type_description')); ?></label>
                        <textarea id="relTypeDescription" rows="2" maxlength="500" placeholder="<?php echo htmlspecialchars(t('cmdb.settings.rel_type_description_placeholder')); ?>"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="relTypeDisplayOrder"><?php echo htmlspecialchars(t('cmdb.settings.rel_type_display_order')); ?></label>
                        <input type="number" id="relTypeDisplayOrder" value="0">
                    </div>
                    <div class="form-group">
                        <label class="toggle-label">
                            <span class="toggle-switch">
                                <input type="checkbox" id="relTypeIsActive" checked>
                                <span class="toggle-slider"></span>
                            </span>
                            <?php echo htmlspecialchars(t('cmdb.settings.rel_type_active')); ?>
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRelTypeModal()"><?php echo htmlspecialchars(t('cmdb.settings.rel_type_cancel')); ?></button>
                <button type="button" class="btn btn-primary" onclick="saveRelType()"><?php echo htmlspecialchars(t('cmdb.settings.rel_type_save')); ?></button>
            </div>
        </div>
    </div>

    <!-- AI Suggest Properties Modal (two-stage wizard) -->
    <div class="modal" id="aiSuggestModal">
        <div class="modal-content wide">
            <div class="modal-header">
                <span style="display: inline-flex; align-items: center; gap: 6px;">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#be185d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg>
                    <?php echo htmlspecialchars(t('cmdb.ai_suggest.heading')); ?> <span id="aiSuggestClassName"></span>
                </span>
            </div>
            <div class="modal-body">
                <!-- Stage 0: loading questions -->
                <div class="ai-stage" id="aiStageLoadingQuestions">
                    <div class="ai-loading">
                        <?php echo htmlspecialchars(t('cmdb.ai_suggest.loading_questions')); ?>
                        <div style="margin-top: 10px;">
                            <span class="spinner-dot"></span><span class="spinner-dot"></span><span class="spinner-dot"></span>
                        </div>
                    </div>
                </div>

                <!-- Stage 1: questions form -->
                <div class="ai-stage" id="aiStageQuestions">
                    <p style="color: var(--text-muted,#4b5563); font-size: 13px; margin-bottom: 16px;">
                        <?php echo t('cmdb.ai_suggest.questions_intro'); ?>
                    </p>
                    <div id="aiQuestionsList"></div>
                </div>

                <!-- Stage 2: loading suggestions -->
                <div class="ai-stage" id="aiStageLoadingSuggestions">
                    <div class="ai-loading">
                        <?php echo htmlspecialchars(t('cmdb.ai_suggest.loading_suggestions')); ?>
                        <div style="margin-top: 10px;">
                            <span class="spinner-dot"></span><span class="spinner-dot"></span><span class="spinner-dot"></span>
                        </div>
                    </div>
                </div>

                <!-- Stage 3: suggestions list -->
                <div class="ai-stage" id="aiStageSuggestions">
                    <p style="color: var(--text-muted,#4b5563); font-size: 13px; margin-bottom: 16px;">
                        <?php echo t('cmdb.ai_suggest.suggestions_intro'); ?>
                    </p>
                    <div id="aiSuggestionsList"></div>
                </div>

                <!-- Result stage (shown after bulk-add finishes — stays put until OK) -->
                <div class="ai-stage" id="aiStageResult">
                    <div id="aiResultSummary" style="margin-bottom: 16px; padding: 14px; border-radius: 6px;"></div>
                    <div id="aiResultDetails"></div>
                </div>

                <!-- Error stage -->
                <div class="ai-stage" id="aiStageError">
                    <div style="padding: 30px; text-align: center;">
                        <p style="color: var(--danger-text,#b91c1c); font-size: 14px; margin-bottom: 12px;" id="aiErrorMessage"></p>
                        <button class="btn btn-secondary" onclick="closeAiSuggestModal()"><?php echo htmlspecialchars(t('cmdb.ai_suggest.close')); ?></button>
                    </div>
                </div>
            </div>
            <div class="modal-footer" id="aiSuggestActions">
                <button type="button" class="btn btn-secondary" id="aiSuggestSecondaryBtn" onclick="closeAiSuggestModal()"><?php echo htmlspecialchars(t('cmdb.ai_suggest.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="aiSuggestPrimaryBtn" onclick="aiPrimaryAction()"><?php echo htmlspecialchars(t('cmdb.ai_suggest.continue')); ?></button>
            </div>
        </div>
    </div>

    <script src="../../assets/js/ai-settings.js"></script>
    <script src="../options-editor.js?v=3"></script>
    <script src="settings.js?v=7"></script>
</body>
</html>
