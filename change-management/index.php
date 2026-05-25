<?php
/**
 * Change Management - Create, view and manage IT changes
 */
session_start();
require_once __DIR__ . '/../config.php';

$current_page = 'changes';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Change Management</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/change-management.css?v=3">
    <script src="<?php echo BASE_URL; ?>assets/js/tinymce/tinymce.min.js"></script>
</head>
<body data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="changes-container">
        <!-- Sidebar with search and status filters -->
        <div class="changes-sidebar">
            <div class="sidebar-section">
                <button class="search-btn" onclick="openSearchModal()">Search</button>
            </div>
            <div class="sidebar-section">
                <h3>Status</h3>
                <div class="status-filter-list" id="statusFilterList">
                    <!-- The "All" row stays in HTML (special — doesn't map to a row in change_statuses).
                         Real statuses are appended by JS from get_change_statuses.php so adding /
                         renaming / deactivating one in Settings reflects here automatically. -->
                    <div class="status-filter active" data-status="all" onclick="filterByStatus('all')">
                        <span>All</span>
                        <span class="filter-count" id="countAll">0</span>
                    </div>
                </div>
            </div>
            <div class="sidebar-section">
                <a class="btn btn-primary btn-full" href="new/">+ New change</a>
            </div>
        </div>

        <!-- Main content area -->
        <div class="changes-main">
            <!-- Change list view -->
            <div id="changeListView">
                <div class="change-list-header">
                    <h2>Changes</h2>
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
                    <h2 id="editorTitle">New change</h2>
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
                        <h3 class="form-section-title">General information</h3>

                        <div class="cm-field-wrap" data-field-key="title">
                            <div class="form-group">
                                <label class="form-label">Title *</label>
                                <input type="text" class="form-input" id="changeTitle" placeholder="Enter change title...">
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="change_type">
                            <div class="form-group">
                                <label class="form-label">Change type</label>
                                <select class="form-input" id="changeType">
                                    <option value="Standard">Standard</option>
                                    <option value="Normal" selected>Normal</option>
                                    <option value="Emergency">Emergency</option>
                                </select>
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="status">
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <!-- Options populated from change_statuses (active rows only) on page load. -->
                                <select class="form-input" id="changeStatus"></select>
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="priority">
                            <div class="form-group">
                                <label class="form-label">Priority</label>
                                <select class="form-input" id="changePriority">
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                    <option value="Critical">Critical</option>
                                </select>
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="impact">
                            <div class="form-group">
                                <label class="form-label">Impact</label>
                                <select class="form-input" id="changeImpact">
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="category">
                            <div class="form-group">
                                <label class="form-label">Category</label>
                                <input type="text" class="form-input" id="changeCategory" placeholder="e.g. Network, Server, Software...">
                            </div>
                        </div>
                    </div>

                    <div class="cm-form-section" data-section-id="2" data-section-key="people">
                        <h3 class="form-section-title">People</h3>

                        <div class="cm-field-wrap" data-field-key="requester">
                            <div class="form-group">
                                <label class="form-label">Requester</label>
                                <select class="form-input" id="changeRequester">
                                    <option value="">-- Select --</option>
                                </select>
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="assigned_to">
                            <div class="form-group">
                                <label class="form-label">Assigned to</label>
                                <select class="form-input" id="changeAssignedTo">
                                    <option value="">-- Select --</option>
                                </select>
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="approver">
                            <div class="form-group">
                                <label class="form-label">Approver</label>
                                <select class="form-input" id="changeApprover">
                                    <option value="">-- Select --</option>
                                </select>
                            </div>
                        </div>

                        <!-- CAB review. Visibility tied to data-field-key="cab"; the
                             collapsible config sub-section travels with the parent. -->
                        <div class="cm-field-wrap" data-field-key="cab">
                            <div class="form-group">
                                <label class="form-label" style="display: flex; align-items: center; gap: 10px;">
                                    <input type="checkbox" id="cabRequired" onchange="toggleCabConfig()"> Require CAB review
                                </label>
                            </div>
                            <div id="cabConfigSection" style="display: none;">
                                <div class="form-group">
                                    <label class="form-label">Approval type</label>
                                    <select class="form-input" id="cabApprovalType">
                                        <option value="all">All must approve</option>
                                        <option value="majority">Majority</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">CAB members</label>
                                    <div class="cab-member-picker">
                                        <div style="display: flex; gap: 8px;">
                                            <select class="form-input" id="cabMemberSelect" style="flex: 1;">
                                                <option value="">-- Select analyst --</option>
                                            </select>
                                            <button type="button" class="btn btn-secondary" onclick="addCabMember()">Add</button>
                                        </div>
                                        <div class="cab-members-list" id="cabMembersList"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="cm-form-section" data-section-id="3" data-section-key="schedule">
                        <h3 class="form-section-title">Schedule</h3>

                        <div class="cm-field-wrap" data-field-key="work_start">
                            <div class="form-group">
                                <label class="form-label">Work start</label>
                                <input type="datetime-local" class="form-input" id="changeWorkStart">
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="work_end">
                            <div class="form-group">
                                <label class="form-label">Work end</label>
                                <input type="datetime-local" class="form-input" id="changeWorkEnd">
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="outage_start">
                            <div class="form-group">
                                <label class="form-label">Outage start (optional)</label>
                                <input type="datetime-local" class="form-input" id="changeOutageStart">
                            </div>
                        </div>

                        <div class="cm-field-wrap" data-field-key="outage_end">
                            <div class="form-group">
                                <label class="form-label">Outage end (optional)</label>
                                <input type="datetime-local" class="form-input" id="changeOutageEnd">
                            </div>
                        </div>
                    </div>

                    <div class="cm-form-section" data-section-id="4" data-section-key="details">
                        <h3 class="form-section-title">Details</h3>

                        <div class="cm-field-wrap" data-field-key="risk">
                            <div class="form-row risk-scoring-row">
                                <div class="form-group">
                                    <label class="form-label">Risk likelihood</label>
                                    <select class="form-input" id="riskLikelihood" onchange="updateRiskScore()">
                                        <option value="">Not assessed</option>
                                        <option value="1">1 - Very low</option>
                                        <option value="2">2 - Low</option>
                                        <option value="3">3 - Medium</option>
                                        <option value="4">4 - High</option>
                                        <option value="5">5 - Very high</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Risk impact</label>
                                    <select class="form-input" id="riskImpactScore" onchange="updateRiskScore()">
                                        <option value="">Not assessed</option>
                                        <option value="1">1 - Very low</option>
                                        <option value="2">2 - Low</option>
                                        <option value="3">3 - Medium</option>
                                        <option value="4">4 - High</option>
                                        <option value="5">5 - Very high</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Risk score</label>
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
                                <button class="rich-text-tab active" data-field-key="description" onclick="switchTab('description')">Description</button>
                                <button class="rich-text-tab" data-field-key="reason" onclick="switchTab('reason')">Reason for change</button>
                                <button class="rich-text-tab" data-field-key="risk" onclick="switchTab('risk')">Risk evaluation</button>
                                <button class="rich-text-tab" data-field-key="testplan" onclick="switchTab('testplan')">Test plan</button>
                                <button class="rich-text-tab" data-field-key="rollback" onclick="switchTab('rollback')">Rollback plan</button>
                                <button class="rich-text-tab" data-field-key="pir" onclick="switchTab('pir')">Post-implementation review</button>
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
                                        <label class="form-label">Was successful?</label>
                                        <div style="display: flex; gap: 15px; margin-top: 5px;">
                                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                                <input type="radio" name="pirWasSuccessful" value="1"> Yes
                                            </label>
                                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                                <input type="radio" name="pirWasSuccessful" value="0"> No
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Actual start</label>
                                        <input type="datetime-local" class="form-input" id="pirActualStart">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Actual end</label>
                                        <input type="datetime-local" class="form-input" id="pirActualEnd">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Lessons learned</label>
                                    <textarea class="form-input" id="pirLessonsLearned" rows="3" placeholder="What went well? What could be improved?"></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Follow-up actions</label>
                                    <textarea class="form-input" id="pirFollowUp" rows="3" placeholder="Any actions arising from this change?"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="cm-form-section" data-section-id="5" data-section-key="attachments">
                        <h3 class="form-section-title">Attachments</h3>

                        <div class="cm-field-wrap" data-field-key="attachments">
                            <div class="attachment-list" id="editorAttachmentList"></div>

                            <div class="file-upload-area" id="fileUploadArea">
                                <div class="upload-icon">&#128206;</div>
                                <p>Drag and drop files here, or click to browse</p>
                                <input type="file" id="fileInput" multiple style="display:none;">
                            </div>
                        </div>
                    </div>

                    <div class="editor-actions"></div>
                </div>
                <div class="editor-footer">
                    <button class="btn btn-secondary" onclick="cancelEdit()">Cancel</button>
                    <button class="btn btn-primary" onclick="saveChange()">Save</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <!-- Search Modal (Draggable) -->
    <div class="search-modal" id="searchModal">
        <div class="search-modal-header" id="searchModalHeader">
            <span>Search Changes</span>
            <button class="search-modal-close" onclick="closeSearchModal()">&times;</button>
        </div>
        <div class="search-modal-body">
            <div class="search-form">
                <div class="search-field">
                    <label>Change Number</label>
                    <input type="text" id="searchChangeNumber" placeholder="e.g., CHG-0001 or 1">
                </div>
                <div class="search-field">
                    <label>Title</label>
                    <input type="text" id="searchChangeTitle" placeholder="Search in title...">
                </div>
                <div class="search-actions">
                    <button class="btn btn-primary" onclick="performSearch()">Search</button>
                    <button class="btn btn-secondary" onclick="clearSearch()">Clear</button>
                </div>
            </div>
            <div class="search-results" id="searchResults">
                <div class="search-results-empty">Enter search criteria above</div>
            </div>
        </div>
    </div>

    <!-- Delete confirmation modal container -->
    <div id="deleteModal"></div>

    <!-- Share Email Modal -->
    <div class="modal" id="shareEmailModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Share Change via Email</h3>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Recipient Email *</label>
                    <input type="email" class="form-input" id="shareEmailTo" placeholder="recipient@example.com">
                </div>
                <div class="form-group">
                    <label class="form-label">Message (optional)</label>
                    <textarea class="form-input" id="shareEmailMessage" rows="3" placeholder="Add a personal message..."></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Include:</label>
                    <div style="display: flex; gap: 20px; margin-top: 8px;">
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" id="shareIncludeLink" checked> Link to change
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" id="shareIncludePdf" checked> PDF attachment
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeShareEmailModal()">Cancel</button>
                <button class="btn btn-primary" onclick="sendShareEmail()">Send</button>
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
    <script src="<?php echo BASE_URL; ?>assets/js/change-management.js?v=9"></script>
</body>
</html>
