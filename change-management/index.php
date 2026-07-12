<?php
/**
 * Change Management - Create, view and manage IT changes
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/theme.php';
require_once __DIR__ . '/../includes/timezone.php';
requireModuleAccess('changes');
I18n::initFromSession();
Tz::init();

$current_page = 'changes';
$path_prefix = '../';
$translationNamespaces = ['common', 'change-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('change-management.page.changes')); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=20">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/change-management.css?v=7">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo BASE_URL; ?>assets/js/tz.js?v=1"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/i18n.js?v=2"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/tinymce/tinymce.min.js"></script>
</head>
<body data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="changes-container">
        <!-- Sidebar with search and status filters -->
        <div class="changes-sidebar">
            <div class="sidebar-section">
                <button class="search-btn" onclick="openSearchModal()"><?php echo htmlspecialchars(t('change-management.sidebar.search')); ?></button>
            </div>
            <div class="sidebar-section">
                <h3><?php echo htmlspecialchars(t('change-management.sidebar.status')); ?></h3>
                <div class="status-filter-list" id="statusFilterList">
                    <!-- The "All" row stays in HTML (special — doesn't map to a row in change_statuses).
                         Real statuses are appended by JS from get_change_statuses.php so adding /
                         renaming / deactivating one in Settings reflects here automatically. -->
                    <div class="status-filter active" data-status="all" onclick="filterByStatus('all')">
                        <span><?php echo htmlspecialchars(t('change-management.sidebar.all')); ?></span>
                        <span class="filter-count" id="countAll">0</span>
                    </div>
                </div>
            </div>
            <div class="sidebar-section">
                <a class="btn btn-primary btn-full" href="new/"><?php echo htmlspecialchars(t('change-management.sidebar.new_change')); ?></a>
            </div>
        </div>

        <!-- Main content area -->
        <div class="changes-main">
            <!-- Change list view -->
            <div id="changeListView">
                <div class="change-list-header">
                    <h2><?php echo htmlspecialchars(t('change-management.list.heading')); ?></h2>
                    <div class="change-count" id="changeCount"></div>
                </div>
                <div class="change-list" id="changeList">
                    <div class="loading"><div class="spinner"></div></div>
                </div>
            </div>

            <!-- Change detail view -->
            <div id="changeDetailView" style="display: none;">
                <div class="change-detail-content" id="changeDetailContent"></div>
            </div>

            <!-- Change editor view. Lays out as a vertical flex column when
                 visible: editor-header pins at top, editor-form scrolls in
                 the middle, editor-footer pins at bottom. JS toggles
                 body.cm-editor-open to switch changes-main into this mode. -->
            <div id="changeEditorView" style="display: none;">
                <div class="editor-header">
                    <h2 id="editorTitle"><?php echo htmlspecialchars(t('change-management.editor.new')); ?></h2>
                </div>
                <div class="editor-form">
                    <input type="hidden" id="editChangeId" value="">
                    <!--
                        The form is grouped into sections via .cm-form-section wrappers.
                        Each wrapper carries data-section-id matching change_field_sections.id
                        and a data-section-key for the seed-section that lived here. JS
                        rebuilds the form at render-time:
                          - looks up the matching DB section by id, renames the heading
                          - reorders the sections to match change_field_sections.display_order
                          - hides any section whose visible-fields count is zero
                          - migrates individual fields between sections if a field was
                            re-homed in Form fields settings
                        Individual field blocks carry data-field-key so they're addressable.
                        See the v1 limitation note at the top of refreshFormLayout() in JS.
                    -->

                    <div class="cm-form-section" data-section-id="1" data-section-key="general">
                        <h3 class="form-section-title"><?php echo htmlspecialchars(t('change-management.editor.section_general')); ?></h3>

                        <div class="cm-field-wrap" data-field-key="title">
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.title_label')); ?></label>
                                <input type="text" class="form-input" id="changeTitle" placeholder="<?php echo htmlspecialchars(t('change-management.editor.title_placeholder')); ?>">
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="change_type">
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.change_type')); ?></label>
                                <select class="form-input" id="changeType">
                                    <option value="Standard"><?php echo htmlspecialchars(t('change-management.editor.type_standard')); ?></option>
                                    <option value="Normal" selected><?php echo htmlspecialchars(t('change-management.editor.type_normal')); ?></option>
                                    <option value="Emergency"><?php echo htmlspecialchars(t('change-management.editor.type_emergency')); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="status">
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.status')); ?></label>
                                <!-- Options populated from change_statuses (active rows only) on page load. -->
                                <select class="form-input" id="changeStatus"></select>
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="priority">
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.priority')); ?></label>
                                <select class="form-input" id="changePriority">
                                    <option value="Low"><?php echo htmlspecialchars(t('change-management.editor.priority_low')); ?></option>
                                    <option value="Medium" selected><?php echo htmlspecialchars(t('change-management.editor.priority_medium')); ?></option>
                                    <option value="High"><?php echo htmlspecialchars(t('change-management.editor.priority_high')); ?></option>
                                    <option value="Critical"><?php echo htmlspecialchars(t('change-management.editor.priority_critical')); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="impact">
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.impact')); ?></label>
                                <select class="form-input" id="changeImpact">
                                    <option value="Low"><?php echo htmlspecialchars(t('change-management.editor.impact_low')); ?></option>
                                    <option value="Medium" selected><?php echo htmlspecialchars(t('change-management.editor.impact_medium')); ?></option>
                                    <option value="High"><?php echo htmlspecialchars(t('change-management.editor.impact_high')); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="category">
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.category')); ?></label>
                                <input type="text" class="form-input" id="changeCategory" placeholder="<?php echo htmlspecialchars(t('change-management.editor.category_placeholder')); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="cm-form-section" data-section-id="2" data-section-key="people">
                        <h3 class="form-section-title"><?php echo htmlspecialchars(t('change-management.editor.section_people')); ?></h3>

                        <div class="cm-field-wrap" data-field-key="requester">
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.requester')); ?></label>
                                <select class="form-input" id="changeRequester">
                                    <option value=""><?php echo htmlspecialchars(t('change-management.editor.select')); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="assigned_to">
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.assigned_to')); ?></label>
                                <select class="form-input" id="changeAssignedTo">
                                    <option value=""><?php echo htmlspecialchars(t('change-management.editor.select')); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="approver">
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.approver')); ?></label>
                                <select class="form-input" id="changeApprover">
                                    <option value=""><?php echo htmlspecialchars(t('change-management.editor.select')); ?></option>
                                </select>
                            </div>
                        </div>

                        <!-- CAB review. Visibility tied to data-field-key="cab"; the
                             collapsible config sub-section travels with the parent. -->
                        <div class="cm-field-wrap" data-field-key="cab">
                            <div class="form-group">
                                <label class="form-label" style="display: flex; align-items: center; gap: 10px;">
                                    <input type="checkbox" id="cabRequired" onchange="toggleCabConfig()"> <?php echo htmlspecialchars(t('change-management.editor.require_cab')); ?>
                                </label>
                            </div>
                            <div id="cabConfigSection" style="display: none;">
                                <div class="form-group">
                                    <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.approval_type')); ?></label>
                                    <select class="form-input" id="cabApprovalType">
                                        <option value="all"><?php echo htmlspecialchars(t('change-management.editor.approval_all')); ?></option>
                                        <option value="majority"><?php echo htmlspecialchars(t('change-management.editor.approval_majority')); ?></option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.cab_members')); ?></label>
                                    <div class="cab-member-picker">
                                        <div style="display: flex; gap: 8px;">
                                            <select class="form-input" id="cabMemberSelect" style="flex: 1;">
                                                <option value=""><?php echo htmlspecialchars(t('change-management.editor.select_analyst')); ?></option>
                                            </select>
                                            <button type="button" class="btn btn-secondary" onclick="addCabMember()"><?php echo htmlspecialchars(t('change-management.editor.add')); ?></button>
                                        </div>
                                        <div class="cab-members-list" id="cabMembersList"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="cm-form-section" data-section-id="3" data-section-key="schedule">
                        <h3 class="form-section-title"><?php echo htmlspecialchars(t('change-management.editor.section_schedule')); ?></h3>

                        <div class="cm-field-wrap" data-field-key="work_start">
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.work_start')); ?></label>
                                <input type="datetime-local" class="form-input" id="changeWorkStart">
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="work_end">
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.work_end')); ?></label>
                                <input type="datetime-local" class="form-input" id="changeWorkEnd">
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="outage_start">
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.outage_start')); ?></label>
                                <input type="datetime-local" class="form-input" id="changeOutageStart">
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="outage_end">
                            <div class="form-group">
                                <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.outage_end')); ?></label>
                                <input type="datetime-local" class="form-input" id="changeOutageEnd">
                            </div>
                        </div>
                    </div>

                    <div class="cm-form-section" data-section-id="4" data-section-key="details">
                        <h3 class="form-section-title"><?php echo htmlspecialchars(t('change-management.editor.section_details')); ?></h3>

                        <div class="cm-field-wrap" data-field-key="risk">
                            <div class="form-row risk-scoring-row">
                                <div class="form-group">
                                    <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.risk_likelihood')); ?></label>
                                    <select class="form-input" id="riskLikelihood" onchange="updateRiskScore()">
                                        <option value=""><?php echo htmlspecialchars(t('change-management.editor.not_assessed')); ?></option>
                                        <option value="1"><?php echo htmlspecialchars(t('change-management.editor.rl_1')); ?></option>
                                        <option value="2"><?php echo htmlspecialchars(t('change-management.editor.rl_2')); ?></option>
                                        <option value="3"><?php echo htmlspecialchars(t('change-management.editor.rl_3')); ?></option>
                                        <option value="4"><?php echo htmlspecialchars(t('change-management.editor.rl_4')); ?></option>
                                        <option value="5"><?php echo htmlspecialchars(t('change-management.editor.rl_5')); ?></option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.risk_impact')); ?></label>
                                    <select class="form-input" id="riskImpactScore" onchange="updateRiskScore()">
                                        <option value=""><?php echo htmlspecialchars(t('change-management.editor.not_assessed')); ?></option>
                                        <option value="1"><?php echo htmlspecialchars(t('change-management.editor.rl_1')); ?></option>
                                        <option value="2"><?php echo htmlspecialchars(t('change-management.editor.rl_2')); ?></option>
                                        <option value="3"><?php echo htmlspecialchars(t('change-management.editor.rl_3')); ?></option>
                                        <option value="4"><?php echo htmlspecialchars(t('change-management.editor.rl_4')); ?></option>
                                        <option value="5"><?php echo htmlspecialchars(t('change-management.editor.rl_5')); ?></option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.risk_score')); ?></label>
                                    <div class="risk-score-display" id="riskScoreDisplay">-</div>
                                </div>
                            </div>
                        </div>

                        <!--
                            Rich-text tabbed widget. v1 limitation: this widget
                            stays anchored to the Details section and is NOT
                            moved by Form fields settings. The six individual
                            tabs (description, reason, risk, testplan, rollback,
                            pir) ARE hidden/shown based on each field's
                            is_visible flag — if all six are hidden the whole
                            widget hides. Their section_id is currently ignored
                            for placement (always rendered here).
                        -->
                        <div class="cm-rich-text-widget" id="cmRichTextWidget">
                            <div class="rich-text-tabs" id="richTextTabs">
                                <button class="rich-text-tab active" data-field-key="description" onclick="switchTab('description')"><?php echo htmlspecialchars(t('change-management.editor.tab_description')); ?></button>
                                <button class="rich-text-tab" data-field-key="reason" onclick="switchTab('reason')"><?php echo htmlspecialchars(t('change-management.editor.tab_reason')); ?></button>
                                <button class="rich-text-tab" data-field-key="risk" onclick="switchTab('risk')"><?php echo htmlspecialchars(t('change-management.editor.tab_risk')); ?></button>
                                <button class="rich-text-tab" data-field-key="testplan" onclick="switchTab('testplan')"><?php echo htmlspecialchars(t('change-management.editor.tab_testplan')); ?></button>
                                <button class="rich-text-tab" data-field-key="rollback" onclick="switchTab('rollback')"><?php echo htmlspecialchars(t('change-management.editor.tab_rollback')); ?></button>
                                <button class="rich-text-tab" data-field-key="pir" onclick="switchTab('pir')"><?php echo htmlspecialchars(t('change-management.editor.tab_pir')); ?></button>
                            </div>

                            <div class="rich-text-panel active" id="panel-description" data-field-key="description">
                                <textarea id="editorDescription"></textarea>
                            </div>
                            <div class="rich-text-panel" id="panel-reason" data-field-key="reason">
                                <textarea id="editorReason"></textarea>
                            </div>
                            <div class="rich-text-panel" id="panel-risk" data-field-key="risk">
                                <textarea id="editorRisk"></textarea>
                            </div>
                            <div class="rich-text-panel" id="panel-testplan" data-field-key="testplan">
                                <textarea id="editorTestplan"></textarea>
                            </div>
                            <div class="rich-text-panel" id="panel-rollback" data-field-key="rollback">
                                <textarea id="editorRollback"></textarea>
                            </div>
                            <div class="rich-text-panel" id="panel-pir" data-field-key="pir">
                                <textarea id="editorPir"></textarea>
                            </div>
                        </div>

                        <!-- PIR structured fields appear under the pir field-wrap
                             but only render when status = Completed/Failed. The
                             outer wrap follows the pir field's visibility; the
                             inner .pir-structured follows the status state. -->
                        <div class="cm-field-wrap" data-field-key="pir">
                            <div class="pir-structured" id="pirStructuredFields" style="display: none;">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.was_successful')); ?></label>
                                        <div style="display: flex; gap: 15px; margin-top: 5px;">
                                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                                <input type="radio" name="pirWasSuccessful" value="1"> <?php echo htmlspecialchars(t('change-management.editor.yes')); ?>
                                            </label>
                                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                                <input type="radio" name="pirWasSuccessful" value="0"> <?php echo htmlspecialchars(t('change-management.editor.no')); ?>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.actual_start')); ?></label>
                                        <input type="datetime-local" class="form-input" id="pirActualStart">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.actual_end')); ?></label>
                                        <input type="datetime-local" class="form-input" id="pirActualEnd">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.lessons')); ?></label>
                                    <textarea class="form-input" id="pirLessonsLearned" rows="3" placeholder="<?php echo htmlspecialchars(t('change-management.editor.lessons_placeholder')); ?>"></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label"><?php echo htmlspecialchars(t('change-management.editor.follow_up')); ?></label>
                                    <textarea class="form-input" id="pirFollowUp" rows="3" placeholder="<?php echo htmlspecialchars(t('change-management.editor.follow_up_placeholder')); ?>"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="cm-form-section" data-section-id="5" data-section-key="attachments">
                        <h3 class="form-section-title"><?php echo htmlspecialchars(t('change-management.editor.section_attachments')); ?></h3>

                        <div class="cm-field-wrap" data-field-key="attachments">
                            <div class="attachment-list" id="editorAttachmentList"></div>

                            <div class="file-upload-area" id="fileUploadArea">
                                <div class="upload-icon">&#128206;</div>
                                <p><?php echo htmlspecialchars(t('change-management.editor.upload_prompt')); ?></p>
                                <input type="file" id="fileInput" multiple style="display:none;">
                            </div>
                        </div>
                    </div>

                    <div class="editor-actions"></div>
                </div>
                <div class="editor-footer">
                    <button class="btn btn-secondary" onclick="cancelEdit()"><?php echo htmlspecialchars(t('change-management.editor.cancel')); ?></button>
                    <button class="btn btn-primary" onclick="saveChange()"><?php echo htmlspecialchars(t('change-management.editor.save')); ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Right-click context menu for change cards. Positioned in JS at cursor.
         Reuses the tickets menu's CSS classes (inbox.css is loaded above). Each
         submenu is (re)populated at open time from the active lookups so newly
         added entries + the current-value tick appear without a page refresh. -->
    <div class="ticket-context-menu" id="changeContextMenu" role="menu">
        <div class="ticket-context-menu-header" id="changeContextMenuHeader"></div>
        <!-- Status submenu parent — populated from active change_statuses. -->
        <div class="ticket-context-menu-item ticket-context-menu-parent" role="menuitem" tabindex="0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span><?php echo htmlspecialchars(t('change-management.context.set_status')); ?></span>
            <svg class="ctx-sub-arrow" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            <div class="ticket-context-submenu" id="ctxChangeStatusSubmenu" role="menu"></div>
        </div>
        <!-- Priority submenu parent — populated from active change_priorities. -->
        <div class="ticket-context-menu-item ticket-context-menu-parent" role="menuitem" tabindex="0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
            <span><?php echo htmlspecialchars(t('change-management.context.set_priority')); ?></span>
            <svg class="ctx-sub-arrow" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            <div class="ticket-context-submenu" id="ctxChangePrioritySubmenu" role="menu"></div>
        </div>
        <!-- Type submenu parent — populated from active change_types. -->
        <div class="ticket-context-menu-item ticket-context-menu-parent" role="menuitem" tabindex="0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            <span><?php echo htmlspecialchars(t('change-management.context.set_type')); ?></span>
            <svg class="ctx-sub-arrow" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            <div class="ticket-context-submenu" id="ctxChangeTypeSubmenu" role="menu"></div>
        </div>
        <!-- Impact submenu parent — populated from active change_impacts. -->
        <div class="ticket-context-menu-item ticket-context-menu-parent" role="menuitem" tabindex="0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            <span><?php echo htmlspecialchars(t('change-management.context.set_impact')); ?></span>
            <svg class="ctx-sub-arrow" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            <div class="ticket-context-submenu" id="ctxChangeImpactSubmenu" role="menu"></div>
        </div>
        <!-- Move-to-company submenu parent. Multi-company installs only; hidden at N=1. -->
        <div class="ticket-context-menu-item ticket-context-menu-parent" id="ctxChangeCompanyParent" role="menuitem" tabindex="0" style="display:none;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16"/><path d="M19 21V9a2 2 0 0 0-2-2h-2"/><path d="M9 7h2"/><path d="M9 11h2"/><path d="M9 15h2"/></svg>
            <span><?php echo htmlspecialchars(t('change-management.context.move_company')); ?></span>
            <svg class="ctx-sub-arrow" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            <div class="ticket-context-submenu" id="ctxChangeCompanySubmenu" role="menu"></div>
        </div>
    </div>

    <!-- Toast -->
    <!-- Search Modal (Draggable) -->
    <div class="search-modal" id="searchModal">
        <div class="search-modal-header" id="searchModalHeader">
            <span><?php echo htmlspecialchars(t('change-management.search.heading')); ?></span>
            <button class="search-modal-close" onclick="closeSearchModal()">&times;</button>
        </div>
        <div class="search-modal-body">
            <div class="search-form">
                <div class="search-field">
                    <label><?php echo htmlspecialchars(t('change-management.search.change_number')); ?></label>
                    <input type="text" id="searchChangeNumber" placeholder="<?php echo htmlspecialchars(t('change-management.search.change_number_placeholder')); ?>">
                </div>
                <div class="search-field">
                    <label><?php echo htmlspecialchars(t('change-management.search.title')); ?></label>
                    <input type="text" id="searchChangeTitle" placeholder="<?php echo htmlspecialchars(t('change-management.search.title_placeholder')); ?>">
                </div>
                <div class="search-actions">
                    <button class="btn btn-primary" onclick="performSearch()"><?php echo htmlspecialchars(t('change-management.search.go')); ?></button>
                    <button class="btn btn-secondary" onclick="clearSearch()"><?php echo htmlspecialchars(t('change-management.search.clear')); ?></button>
                </div>
            </div>
            <div class="search-results" id="searchResults">
                <div class="search-results-empty"><?php echo htmlspecialchars(t('change-management.search.empty')); ?></div>
            </div>
        </div>
    </div>

    <!-- Delete confirmation modal container -->
    <div id="deleteModal"></div>

    <!-- Share Email Modal -->
    <div class="modal" id="shareEmailModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><?php echo htmlspecialchars(t('change-management.share_modal.heading')); ?></h3>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('change-management.share_modal.recipient')); ?></label>
                    <input type="email" class="form-input" id="shareEmailTo" placeholder="<?php echo htmlspecialchars(t('change-management.share_modal.recipient_placeholder')); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('change-management.share_modal.message')); ?></label>
                    <textarea class="form-input" id="shareEmailMessage" rows="3" placeholder="<?php echo htmlspecialchars(t('change-management.share_modal.message_placeholder')); ?>"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('change-management.share_modal.include')); ?></label>
                    <div style="display: flex; gap: 20px; margin-top: 8px;">
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" id="shareIncludeLink" checked> <?php echo htmlspecialchars(t('change-management.share_modal.include_link')); ?>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" id="shareIncludePdf" checked> <?php echo htmlspecialchars(t('change-management.share_modal.include_pdf')); ?>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeShareEmailModal()"><?php echo htmlspecialchars(t('change-management.share_modal.cancel')); ?></button>
                <button class="btn btn-primary" onclick="sendShareEmail()"><?php echo htmlspecialchars(t('change-management.share_modal.send')); ?></button>
            </div>
        </div>
    </div>

    <!-- Link incident picker modal. Searches list_linkable_tickets.php
         (company-scoped, excludes already-linked); clicking a result links it. -->
    <div class="modal" id="linkIncidentModal">
        <div class="modal-content" style="max-width: 560px;">
            <div class="modal-header">
                <h3><?php echo htmlspecialchars(t('change-management.detail.link_incident')); ?></h3>
            </div>
            <div class="modal-body">
                <input type="text" class="form-input" id="linkIncidentSearch" placeholder="<?php echo htmlspecialchars(t('change-management.detail.link_search_placeholder')); ?>" oninput="linkIncidentSearchDebounced()">
                <div id="linkIncidentList" class="link-incident-list"><div class="link-incident-empty"><?php echo htmlspecialchars(t('change-management.detail.loading')); ?></div></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeLinkIncidentModal()"><?php echo htmlspecialchars(t('change-management.editor.cancel')); ?></button>
            </div>
        </div>
    </div>

    <!-- html2pdf for PDF generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        window.API_BASE = '<?php echo BASE_URL; ?>api/change-management/';
        <?php if (!empty($openCreateOnLoad)): ?>
        // Bootstrap from /change-management/new/ — tells the JS to open
        // the editor in create mode as soon as the page is ready.
        window.openCreateOnLoad = true;
        <?php endif; ?>
    </script>
    <script src="<?php echo BASE_URL; ?>assets/js/change-management.js?v=15"></script>
</body>
</html>
