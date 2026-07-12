<?php
/**
 * Process Mapper - Visual flowchart builder
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('process-mapper');

$current_page = 'process-mapper';
$path_prefix = '../';

// Namespaces the JS layer needs translated for this page
$translationNamespaces = ['common', 'process-mapper'];

// Stage 2: pull the step-type palette from process_step_types so the toolbar,
// the detail-panel <select> and the right-click "Create new" submenu are all
// driven by what the user configured in Settings. Active types feed the
// toolbar + context menu; the <select> also includes inactive types so an
// existing step's stored type never disappears from its dropdown.
$pm_types_active = [];
$pm_types_all    = [];
$pm_shape_dims   = include __DIR__ . '/includes/shapes.php';
try {
    $pm_conn = connectToDatabase();
    $pm_stmt = $pm_conn->query("SELECT id, name, slug, shape, color, display_order, is_active, is_builtin
                                FROM process_step_types
                                ORDER BY display_order, name");
    $pm_types_all = $pm_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pm_types_all as &$t) {
        $t['id']            = (int)$t['id'];
        $t['display_order'] = (int)$t['display_order'];
        $t['is_active']     = (int)$t['is_active'];
        $t['is_builtin']    = (int)$t['is_builtin'];
    }
    unset($t);
    $pm_types_active = array_values(array_filter($pm_types_all, fn($t) => $t['is_active']));
} catch (Exception $e) {
    // Soft-fail: empty palette is recoverable — the editor still loads existing
    // diagrams (each step renders with its stored slug -> 'rounded' fallback).
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active('process-mapper')); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode('process-mapper')); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('process-mapper.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=20">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/process-mapper.css?v=11">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="pm-layout">
        <!-- Sidebar: process list -->
        <div class="pm-sidebar" id="pmSidebar">
            <div class="pm-sidebar-header">
                <button class="btn btn-primary btn-full" onclick="PM.createProcess()"><?php echo htmlspecialchars(t('process-mapper.sidebar.new_process')); ?></button>
            </div>
            <div class="pm-sidebar-search">
                <input type="text" id="processSearch" placeholder="<?php echo htmlspecialchars(t('process-mapper.sidebar.search_placeholder')); ?>" oninput="PM.filterProcesses(this.value)">
            </div>
            <div class="pm-process-list" id="processList">
                <div class="pm-empty"><?php echo htmlspecialchars(t('process-mapper.sidebar.no_processes_yet')); ?></div>
            </div>
        </div>

        <!-- Canvas area -->
        <div class="pm-canvas-wrap" id="canvasWrap">
            <!-- Toolbar -->
            <div class="pm-toolbar" id="pmToolbar">
                <div class="pm-toolbar-left">
                    <?php foreach ($pm_types_active as $pm_t): ?>
                    <button class="pm-tool-btn" data-type="<?php echo htmlspecialchars($pm_t['slug']); ?>" title="<?php echo htmlspecialchars($pm_t['name']); ?>">
                        <span class="pm-shape-preview pm-tool-shape" data-shape="<?php echo htmlspecialchars($pm_t['shape']); ?>" style="background: <?php echo htmlspecialchars($pm_t['color']); ?>;"></span>
                        <span><?php echo htmlspecialchars($pm_t['name']); ?></span>
                    </button>
                    <?php endforeach; ?>
                    <div class="pm-tool-sep"></div>
                    <button class="pm-tool-btn" id="connectBtn" title="<?php echo htmlspecialchars(t('process-mapper.toolbar.connect')); ?>">
                        <svg width="18" height="18" viewBox="0 0 18 18"><line x1="3" y1="15" x2="15" y2="3" stroke="currentColor" stroke-width="1.5"/><polyline points="10,3 15,3 15,8" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                        <span><?php echo htmlspecialchars(t('process-mapper.toolbar.connect')); ?></span>
                    </button>
                    <button class="pm-tool-btn" onclick="PM.addGroup()" title="<?php echo htmlspecialchars(t('process-mapper.toolbar.group')); ?>">
                        <svg width="18" height="18" viewBox="0 0 18 18"><rect x="1" y="1" width="16" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="1.5" stroke-dasharray="3 2"/></svg>
                        <span><?php echo htmlspecialchars(t('process-mapper.toolbar.group')); ?></span>
                    </button>
                    <button class="pm-tool-btn" onclick="PM.addLane()" title="<?php echo htmlspecialchars(t('process-mapper.toolbar.lane')); ?>">
                        <svg width="18" height="18" viewBox="0 0 18 18"><rect x="1" y="3" width="16" height="4" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="1" y="11" width="16" height="4" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                        <span><?php echo htmlspecialchars(t('process-mapper.toolbar.lane')); ?></span>
                    </button>
                    <div class="pm-tool-sep"></div>
                    <button class="pm-tool-btn" onclick="PM.openExportModal()" title="<?php echo htmlspecialchars(t('process-mapper.toolbar.export')); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                        <span><?php echo htmlspecialchars(t('process-mapper.toolbar.export')); ?></span>
                    </button>
                </div>
                <div class="pm-toolbar-right">
                    <button class="pm-tool-btn" onclick="PM.deleteSelected()" title="<?php echo htmlspecialchars(t('common.delete')); ?> (Del)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </button>
                    <div class="pm-tool-sep"></div>
                    <span class="pm-status" id="pmStatus" aria-live="polite"></span>
                    <label class="pm-autosave-toggle" title="<?php echo htmlspecialchars(t('process-mapper.autosave.tooltip')); ?>">
                        <input type="checkbox" id="pmAutosaveToggle" onchange="PM.toggleAutosave(this.checked)">
                        <span class="pm-autosave-switch"></span>
                        <span class="pm-autosave-label"><?php echo htmlspecialchars(t('process-mapper.autosave.label')); ?></span>
                    </label>
                    <button class="pm-tool-btn" onclick="PM.save()" title="<?php echo htmlspecialchars(t('process-mapper.toolbar.save')); ?> (Ctrl+S)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        <span><?php echo htmlspecialchars(t('process-mapper.toolbar.save')); ?></span>
                    </button>
                </div>
            </div>

            <!-- Canvas with dot grid -->
            <div class="pm-canvas" id="pmCanvas" tabindex="0">
                <svg class="pm-connectors-svg" id="connectorsSvg"></svg>
                <!-- Steps are rendered here as absolutely positioned divs -->
                <div class="pm-canvas-empty" id="canvasEmpty">
                    <p>Select a process from the sidebar or create a new one</p>
                </div>
            </div>
        </div>

        <!-- Detail panel (slides in) -->
        <div class="pm-detail-panel" id="detailPanel">
            <div class="pm-detail-header">
                <h3 id="detailTitle"><?php echo htmlspecialchars(t('process-mapper.detail.step_title')); ?></h3>
                <button class="pm-detail-close" onclick="PM.closeDetail()">&times;</button>
            </div>
            <!-- Step detail body -->
            <div class="pm-detail-body" id="detailBodyStep">
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.label')); ?></label>
                    <input type="text" class="form-input" id="detailLabel" oninput="PM.updateStepFromDetail()">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.type')); ?></label>
                    <select class="form-input" id="detailType" onchange="PM.updateStepFromDetail()">
                        <?php foreach ($pm_types_all as $pm_t): ?>
                        <option value="<?php echo htmlspecialchars($pm_t['slug']); ?>"<?php echo $pm_t['is_active'] ? '' : ' data-inactive="1"'; ?>><?php echo htmlspecialchars($pm_t['name']) . ($pm_t['is_active'] ? '' : ' *'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.colour')); ?></label>
                    <input type="color" class="form-input" id="detailColor" value="#0078d4" onchange="PM.updateStepFromDetail()" style="height: 36px; padding: 2px;">
                    <div class="pm-gradient-row">
                        <label class="pm-gradient-toggle">
                            <input type="checkbox" id="detailGradient" onchange="PM.updateStepFromDetail()">
                            <span><?php echo htmlspecialchars(t('process-mapper.detail.gradient')); ?></span>
                        </label>
                        <input type="color" class="form-input pm-gradient-color2" id="detailColor2" value="#003a6b" onchange="PM.updateStepFromDetail()">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.description')); ?></label>
                    <textarea class="form-input" id="detailDescription" rows="5" oninput="PM.updateStepFromDetail()" placeholder="<?php echo htmlspecialchars(t('process-mapper.detail.step_description_placeholder')); ?>"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.url')); ?></label>
                    <input type="url" class="form-input" id="detailUrl" oninput="PM.updateStepFromDetail()" placeholder="https://…">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.position')); ?></label>
                    <div style="display: flex; gap: 8px;">
                        <input type="number" class="form-input" id="detailX" style="width: 50%;" onchange="PM.updateStepFromDetail()">
                        <input type="number" class="form-input" id="detailY" style="width: 50%;" onchange="PM.updateStepFromDetail()">
                    </div>
                </div>
                <hr style="margin: 16px 0; border: none; border-top: 1px solid #e0e0e0;">
                <h4 style="margin-bottom: 12px; font-size: 13px; color: #666;"><?php echo htmlspecialchars(t('process-mapper.detail.connectors')); ?></h4>
                <div id="detailConnectors"></div>
            </div>
            <!-- Lane detail body (shown when a lane is selected) -->
            <div class="pm-detail-body" id="detailBodyLane" style="display: none;">
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.label')); ?></label>
                    <input type="text" class="form-input" id="detailLaneLabel" oninput="PM.updateLaneFromDetail()" placeholder="<?php echo htmlspecialchars(t('process-mapper.detail.lane_label_placeholder')); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.colour')); ?></label>
                    <input type="color" class="form-input" id="detailLaneColor" value="#f5f7fa" onchange="PM.updateLaneFromDetail()" style="height: 36px; padding: 2px;">
                    <div class="pm-gradient-row">
                        <label class="pm-gradient-toggle">
                            <input type="checkbox" id="detailLaneGradient" onchange="PM.updateLaneFromDetail()">
                            <span><?php echo htmlspecialchars(t('process-mapper.detail.gradient')); ?></span>
                        </label>
                        <input type="color" class="form-input pm-gradient-color2" id="detailLaneColor2" value="#cfd8dc" onchange="PM.updateLaneFromDetail()">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.height')); ?></label>
                    <input type="number" class="form-input" id="detailLaneHeight" min="80" onchange="PM.updateLaneFromDetail()">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.order')); ?></label>
                    <input type="number" class="form-input" id="detailLaneOrder" min="0" onchange="PM.updateLaneFromDetail()">
                </div>
                <p style="font-size: 12px; color: #888; line-height: 1.5; margin-top: 8px;">
                    <?php echo htmlspecialchars(t('process-mapper.detail.lane_hint')); ?>
                </p>
            </div>
            <!-- Group detail body (shown when a group is selected) -->
            <div class="pm-detail-body" id="detailBodyGroup" style="display: none;">
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.label')); ?></label>
                    <input type="text" class="form-input" id="detailGroupLabel" oninput="PM.updateGroupFromDetail()" placeholder="<?php echo htmlspecialchars(t('process-mapper.detail.group_label_placeholder')); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.colour')); ?></label>
                    <input type="color" class="form-input" id="detailGroupColor" value="#e3f2fd" onchange="PM.updateGroupFromDetail()" style="height: 36px; padding: 2px;">
                    <div class="pm-gradient-row">
                        <label class="pm-gradient-toggle">
                            <input type="checkbox" id="detailGroupGradient" onchange="PM.updateGroupFromDetail()">
                            <span><?php echo htmlspecialchars(t('process-mapper.detail.gradient')); ?></span>
                        </label>
                        <input type="color" class="form-input pm-gradient-color2" id="detailGroupColor2" value="#b3d9f7" onchange="PM.updateGroupFromDetail()">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.position')); ?></label>
                    <div style="display: flex; gap: 8px;">
                        <input type="number" class="form-input" id="detailGroupX" style="width: 50%;" onchange="PM.updateGroupFromDetail()">
                        <input type="number" class="form-input" id="detailGroupY" style="width: 50%;" onchange="PM.updateGroupFromDetail()">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.detail.size')); ?></label>
                    <div style="display: flex; gap: 8px;">
                        <input type="number" class="form-input" id="detailGroupW" style="width: 50%;" onchange="PM.updateGroupFromDetail()" min="80">
                        <input type="number" class="form-input" id="detailGroupH" style="width: 50%;" onchange="PM.updateGroupFromDetail()" min="60">
                    </div>
                </div>
            </div>
            <!-- Annotation (sticky note) detail body -->
            <div class="pm-detail-body" id="detailBodyAnnotation" style="display: none;">
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.annotation.text_label')); ?></label>
                    <textarea class="form-input" id="detailAnnText" rows="6" oninput="PM.updateAnnotationFromDetail()" placeholder="<?php echo htmlspecialchars(t('process-mapper.annotation.text_placeholder')); ?>"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.annotation.colour')); ?></label>
                    <input type="color" class="form-input" id="detailAnnColor" value="#fff59d" onchange="PM.updateAnnotationFromDetail()" style="height: 36px; padding: 2px;">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.annotation.position')); ?></label>
                    <div style="display: flex; gap: 8px;">
                        <input type="number" class="form-input" id="detailAnnX" style="width: 50%;" onchange="PM.updateAnnotationFromDetail()">
                        <input type="number" class="form-input" id="detailAnnY" style="width: 50%;" onchange="PM.updateAnnotationFromDetail()">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('process-mapper.annotation.size')); ?></label>
                    <div style="display: flex; gap: 8px;">
                        <input type="number" class="form-input" id="detailAnnW" style="width: 50%;" onchange="PM.updateAnnotationFromDetail()" min="80">
                        <input type="number" class="form-input" id="detailAnnH" style="width: 50%;" onchange="PM.updateAnnotationFromDetail()" min="60">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right-click context menu (on steps) -->
    <div class="pm-context-menu" id="pmContextMenu" style="display: none;">

        <!-- Edit label (alias for double-click inline rename) -->
        <div class="pm-ctx-item" data-action="edit-label">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            <span><?php echo htmlspecialchars(t('process-mapper.context.edit_label')); ?></span>
        </div>
        <!-- Add note (drops a sticky-note annotation near the step) -->
        <div class="pm-ctx-item" data-action="add-note">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><polyline points="14 3 14 8 19 8"/></svg>
            <span><?php echo htmlspecialchars(t('process-mapper.context.add_note')); ?></span>
        </div>
        <!-- Link to URL -->
        <div class="pm-ctx-item" data-action="link-url">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            <span><?php echo htmlspecialchars(t('process-mapper.context.link_url')); ?></span>
        </div>

        <div class="pm-ctx-separator"></div>

        <!-- Create new -> submenu of type primitives -->
        <div class="pm-ctx-item pm-ctx-parent">
            <span class="pm-ctx-text"><?php echo htmlspecialchars(t('process-mapper.context.create_new')); ?></span>
            <svg class="pm-ctx-arrow" width="12" height="12" viewBox="0 0 12 12"><path d="M4 2.5L8 6l-4 3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <div class="pm-ctx-submenu">
                <?php foreach ($pm_types_active as $pm_t): ?>
                <div class="pm-ctx-item" data-create-type="<?php echo htmlspecialchars($pm_t['slug']); ?>">
                    <span class="pm-shape-preview pm-ctx-shape" data-shape="<?php echo htmlspecialchars($pm_t['shape']); ?>" style="background: <?php echo htmlspecialchars($pm_t['color']); ?>;"></span>
                    <span><?php echo htmlspecialchars($pm_t['name']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Change to -> submenu re-uses the same type primitives -->
        <div class="pm-ctx-item pm-ctx-parent">
            <span class="pm-ctx-text"><?php echo htmlspecialchars(t('process-mapper.context.change_to')); ?></span>
            <svg class="pm-ctx-arrow" width="12" height="12" viewBox="0 0 12 12"><path d="M4 2.5L8 6l-4 3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <div class="pm-ctx-submenu">
                <?php foreach ($pm_types_active as $pm_t): ?>
                <div class="pm-ctx-item" data-change-type="<?php echo htmlspecialchars($pm_t['slug']); ?>">
                    <span class="pm-shape-preview pm-ctx-shape" data-shape="<?php echo htmlspecialchars($pm_t['shape']); ?>" style="background: <?php echo htmlspecialchars($pm_t['color']); ?>;"></span>
                    <span><?php echo htmlspecialchars($pm_t['name']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Connect to (click-to-connect mode) -->
        <div class="pm-ctx-item" data-action="connect-to">
            <svg width="16" height="16" viewBox="0 0 18 18"><line x1="3" y1="15" x2="15" y2="3" stroke="currentColor" stroke-width="1.5"/><polyline points="10,3 15,3 15,8" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
            <span><?php echo htmlspecialchars(t('process-mapper.context.connect_to')); ?></span>
        </div>
        <!-- Reverse connection (always a submenu; populated dynamically by JS) -->
        <div class="pm-ctx-item pm-ctx-parent" id="pmCtxReverseConn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
            <span class="pm-ctx-text"><?php echo htmlspecialchars(t('process-mapper.context.reverse_connection_pick')); ?></span>
            <svg class="pm-ctx-arrow" width="12" height="12" viewBox="0 0 12 12"><path d="M4 2.5L8 6l-4 3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <div class="pm-ctx-submenu" id="pmCtxReverseSubmenu"></div>
        </div>
        <!-- Delete all connections -->
        <div class="pm-ctx-item" id="pmCtxDeleteAllConn" data-action="delete-all-conn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="19" x2="19" y2="5"/><line x1="5" y1="5" x2="19" y2="19"/></svg>
            <span><?php echo htmlspecialchars(t('process-mapper.context.delete_all_connections')); ?></span>
        </div>

        <div class="pm-ctx-separator"></div>

        <!-- Cut / Copy / Paste -->
        <div class="pm-ctx-item" data-action="cut">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>
            <span><?php echo htmlspecialchars(t('process-mapper.context.cut')); ?></span>
        </div>
        <div class="pm-ctx-item" data-action="copy">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            <span><?php echo htmlspecialchars(t('process-mapper.context.copy')); ?></span>
        </div>
        <div class="pm-ctx-item pm-ctx-hidden" id="pmCtxPaste" data-action="paste">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
            <span><?php echo htmlspecialchars(t('process-mapper.context.paste')); ?></span>
        </div>
        <!-- Duplicate -->
        <div class="pm-ctx-item" data-action="duplicate">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>
            <span><?php echo htmlspecialchars(t('process-mapper.context.duplicate')); ?></span>
        </div>
        <!-- Copy formatting -->
        <div class="pm-ctx-item" data-action="copy-format">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2h6a1 1 0 0 1 1 1v2H8V3a1 1 0 0 1 1-1z"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
            <span><?php echo htmlspecialchars(t('process-mapper.context.copy_format')); ?></span>
        </div>
        <!-- Apply formatting (hidden until something is copied) -->
        <div class="pm-ctx-item pm-ctx-hidden" id="pmCtxApplyFormat" data-action="apply-format">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l3 3"/><path d="M3 21l3-9 12-12 6 6-12 12-9 3z"/></svg>
            <span><?php echo htmlspecialchars(t('process-mapper.context.apply_format')); ?></span>
        </div>

        <div class="pm-ctx-separator"></div>

        <!-- Delete (with confirm) -->
        <div class="pm-ctx-item pm-ctx-danger" data-action="delete">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            <span><?php echo htmlspecialchars(t('process-mapper.context.delete')); ?></span>
        </div>
    </div>

    <!-- Toast -->
    <!-- Export modal — three formats: PNG image, PDF document, Mermaid markup -->
    <div class="pm-modal-overlay" id="exportModal" style="display: none;" onclick="if (event.target === this) PM.closeExportModal()">
        <div class="pm-modal">
            <div class="pm-modal-header">
                <h3><?php echo htmlspecialchars(t('process-mapper.export_modal.title')); ?></h3>
                <button class="pm-modal-close" onclick="PM.closeExportModal()" title="<?php echo htmlspecialchars(t('common.close')); ?>">&times;</button>
            </div>
            <div class="pm-modal-body">
                <!-- Format chooser — click one to either download (PNG/PDF) or reveal the Mermaid markup -->
                <div class="pm-export-options">
                    <button type="button" class="pm-export-opt" onclick="PM.exportToPng()">
                        <span class="pm-export-icon">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/></svg>
                        </span>
                        <span class="pm-export-name"><?php echo htmlspecialchars(t('process-mapper.export_modal.png_label')); ?></span>
                        <span class="pm-export-sub"><?php echo htmlspecialchars(t('process-mapper.export_modal.png_sub')); ?></span>
                    </button>
                    <button type="button" class="pm-export-opt" onclick="PM.exportToPdf()">
                        <span class="pm-export-icon">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 13h6M9 17h4"/></svg>
                        </span>
                        <span class="pm-export-name"><?php echo htmlspecialchars(t('process-mapper.export_modal.pdf_label')); ?></span>
                        <span class="pm-export-sub"><?php echo htmlspecialchars(t('process-mapper.export_modal.pdf_sub')); ?></span>
                    </button>
                    <button type="button" class="pm-export-opt" onclick="PM.showMermaid()">
                        <span class="pm-export-icon">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                        </span>
                        <span class="pm-export-name"><?php echo htmlspecialchars(t('process-mapper.export_modal.mermaid_label')); ?></span>
                        <span class="pm-export-sub"><?php echo htmlspecialchars(t('process-mapper.export_modal.mermaid_sub')); ?></span>
                    </button>
                </div>

                <!-- Mermaid markup (revealed when the Mermaid option is picked) -->
                <div id="pmExportMermaid" style="display: none;">
                    <p class="pm-modal-hint"><?php echo t('process-mapper.export_modal.hint'); /* contains HTML so deliberately not escaped */ ?></p>
                    <textarea readonly id="exportText" class="pm-modal-textarea" spellcheck="false"></textarea>
                    <div class="pm-modal-actions">
                        <button class="pm-modal-btn pm-modal-btn-primary" id="exportCopyBtn" onclick="PM.copyExport()"><?php echo htmlspecialchars(t('common.copy')); ?></button>
                        <button class="pm-modal-btn" onclick="PM.closeExportModal()"><?php echo htmlspecialchars(t('common.close')); ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cloud shape clip-path (objectBoundingBox so it scales with the element).
         Required by [data-shape="cloud"] CSS rule for any step or preview using
         the cloud shape. -->
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
        <defs>
            <clipPath id="pmShapeCloud" clipPathUnits="objectBoundingBox">
                <ellipse cx="0.30" cy="0.62" rx="0.27" ry="0.33"/>
                <ellipse cx="0.50" cy="0.42" rx="0.30" ry="0.40"/>
                <ellipse cx="0.70" cy="0.60" rx="0.28" ry="0.34"/>
                <ellipse cx="0.50" cy="0.73" rx="0.40" ry="0.26"/>
            </clipPath>
        </defs>
    </svg>

    <script>
        window.API_BASE = '../api/process-mapper/';
        // Shape registry — drives default canvas sizes when adding a new step.
        // Mirrors process-mapper/includes/shapes.php so the JS layer doesn't
        // need a round-trip just to know how big a "diamond" should be.
        window.SHAPE_SIZES = <?php echo json_encode($pm_shape_dims); ?>;
        // Full type list (active + inactive) so the detail-panel <select> can
        // still resolve an existing step's stored type when it has been
        // deactivated. Keyed by slug for O(1) lookup in the editor.
        window.STEP_TYPES = <?php echo json_encode($pm_types_all); ?>;
        window.STEP_TYPES_BY_SLUG = {};
        for (const t of window.STEP_TYPES) window.STEP_TYPES_BY_SLUG[t.slug] = t;
    </script>
    <!-- Vendored locally so PNG/PDF export works offline and doesn't depend on a
         CDN at print time. Same versions used by Network Mapper (#257). -->
    <script src="../assets/js/vendor/html2canvas.min.js"></script>
    <script src="../assets/js/vendor/jspdf.umd.min.js"></script>
    <script src="../assets/js/process-mapper.js?v=11"></script>
</body>
</html>
