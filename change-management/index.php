<?php
/**
 * Change Management - Create, view and manage IT changes
 */
session_start();
require_once '../config.php';

$current_page = 'changes';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Change Management</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/change-management.css">
    <script src="../assets/js/tinymce/tinymce.min.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="changes-container">
        <!-- Sidebar with search and status filters -->
        <div class="changes-sidebar">
            <div class="sidebar-section">
                <h3>Search</h3>
                <div class="search-box">
                    <input type="text" id="changeSearch" placeholder="Search changes..." onkeyup="debounceSearch()">
                </div>
            </div>
            <div class="sidebar-section">
                <h3>Status</h3>
                <div class="status-filter-list" id="statusFilterList">
                    <div class="status-filter active" data-status="all" onclick="filterByStatus('all')">
                        <span>All</span>
                        <span class="filter-count" id="countAll">0</span>
                    </div>
                    <div class="status-filter" data-status="Draft" onclick="filterByStatus('Draft')">
                        <span>Draft</span>
                        <span class="filter-count" id="countDraft">0</span>
                    </div>
                    <div class="status-filter" data-status="Pending Approval" onclick="filterByStatus('Pending Approval')">
                        <span>Pending Approval</span>
                        <span class="filter-count" id="countPendingApproval">0</span>
                    </div>
                    <div class="status-filter" data-status="Approved" onclick="filterByStatus('Approved')">
                        <span>Approved</span>
                        <span class="filter-count" id="countApproved">0</span>
                    </div>
                    <div class="status-filter" data-status="In Progress" onclick="filterByStatus('In Progress')">
                        <span>In Progress</span>
                        <span class="filter-count" id="countInProgress">0</span>
                    </div>
                    <div class="status-filter" data-status="Completed" onclick="filterByStatus('Completed')">
                        <span>Completed</span>
                        <span class="filter-count" id="countCompleted">0</span>
                    </div>
                    <div class="status-filter" data-status="Failed" onclick="filterByStatus('Failed')">
                        <span>Failed</span>
                        <span class="filter-count" id="countFailed">0</span>
                    </div>
                    <div class="status-filter" data-status="Cancelled" onclick="filterByStatus('Cancelled')">
                        <span>Cancelled</span>
                        <span class="filter-count" id="countCancelled">0</span>
                    </div>
                </div>
            </div>
            <div class="sidebar-section">
                <button class="btn btn-primary btn-full" onclick="openCreateChange()">+ New Change</button>
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
                <div class="change-detail-header">
                    <button class="btn btn-secondary" onclick="backToList()">Back to List</button>
                    <div class="change-detail-actions">
                        <div class="share-dropdown">
                            <button class="btn btn-share" onclick="toggleShareDropdown()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="18" cy="5" r="3"></circle>
                                    <circle cx="6" cy="12" r="3"></circle>
                                    <circle cx="18" cy="19" r="3"></circle>
                                    <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                                    <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                                </svg>
                                Share
                            </button>
                            <div class="share-dropdown-menu" id="shareDropdownMenu">
                                <a href="#" onclick="shareChangeLink(); return false;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                    </svg>
                                    Copy Link
                                </a>
                                <a href="#" onclick="shareChangePdf(); return false;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <line x1="16" y1="13" x2="8" y2="13"></line>
                                        <line x1="16" y1="17" x2="8" y2="17"></line>
                                        <polyline points="10 9 9 9 8 9"></polyline>
                                    </svg>
                                    Export as PDF
                                </a>
                                <a href="#" onclick="shareChangeBoth(); return false;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                    </svg>
                                    Email (Link + PDF)
                                </a>
                            </div>
                        </div>
                        <button class="btn btn-primary" onclick="editCurrentChange()">Edit</button>
                        <button class="btn btn-danger" onclick="deleteCurrentChange()">Delete</button>
                    </div>
                </div>
                <div class="change-detail-content" id="changeDetailContent"></div>
            </div>

            <!-- Change editor view -->
            <div id="changeEditorView" style="display: none;">
                <div class="editor-header">
                    <h2 id="editorTitle">New Change</h2>
                </div>
                <div class="editor-form">
                    <input type="hidden" id="editChangeId" value="">

                    <h3 class="form-section-title" data-section="general" style="margin-top:0; padding-top:0; border-top:none;">General Information</h3>

                    <div class="form-group" data-field="title">
                        <label class="form-label">Title *</label>
                        <input type="text" class="form-input" id="changeTitle" placeholder="Enter change title...">
                    </div>

                    <div class="form-row" data-section="general">
                        <div class="form-group" data-field="change_type">
                            <label class="form-label">Change Type</label>
                            <select class="form-input" id="changeType">
                                <option value="Standard">Standard</option>
                                <option value="Normal" selected>Normal</option>
                                <option value="Emergency">Emergency</option>
                            </select>
                        </div>
                        <div class="form-group" data-field="status">
                            <label class="form-label">Status</label>
                            <select class="form-input" id="changeStatus">
                                <option value="Draft">Draft</option>
                                <option value="Pending Approval">Pending Approval</option>
                                <option value="Approved">Approved</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Failed">Failed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row" data-section="general">
                        <div class="form-group" data-field="priority">
                            <label class="form-label">Priority</label>
                            <select class="form-input" id="changePriority">
                                <option value="Low">Low</option>
                                <option value="Medium" selected>Medium</option>
                                <option value="High">High</option>
                                <option value="Critical">Critical</option>
                            </select>
                        </div>
                        <div class="form-group" data-field="impact">
                            <label class="form-label">Impact</label>
                            <select class="form-input" id="changeImpact">
                                <option value="Low">Low</option>
                                <option value="Medium" selected>Medium</option>
                                <option value="High">High</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" data-field="category">
                        <label class="form-label">Category</label>
                        <input type="text" class="form-input" id="changeCategory" placeholder="e.g. Network, Server, Software...">
                    </div>

                    <h3 class="form-section-title" data-section="people">People</h3>

                    <div class="form-row" data-section="people">
                        <div class="form-group" data-field="requester">
                            <label class="form-label">Requester</label>
                            <select class="form-input" id="changeRequester">
                                <option value="">-- Select --</option>
                            </select>
                        </div>
                        <div class="form-group" data-field="assigned_to">
                            <label class="form-label">Assigned To</label>
                            <select class="form-input" id="changeAssignedTo">
                                <option value="">-- Select --</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" data-field="approver">
                        <label class="form-label">Approver</label>
                        <select class="form-input" id="changeApprover">
                            <option value="">-- Select --</option>
                        </select>
                    </div>

                    <h3 class="form-section-title" data-section="schedule">Schedule</h3>

                    <div class="form-row" data-section="schedule">
                        <div class="form-group" data-field="work_start">
                            <label class="form-label">Work Start</label>
                            <input type="datetime-local" class="form-input" id="changeWorkStart">
                        </div>
                        <div class="form-group" data-field="work_end">
                            <label class="form-label">Work End</label>
                            <input type="datetime-local" class="form-input" id="changeWorkEnd">
                        </div>
                    </div>

                    <div class="form-row" data-section="schedule">
                        <div class="form-group" data-field="outage_start">
                            <label class="form-label">Outage Start (optional)</label>
                            <input type="datetime-local" class="form-input" id="changeOutageStart">
                        </div>
                        <div class="form-group" data-field="outage_end">
                            <label class="form-label">Outage End (optional)</label>
                            <input type="datetime-local" class="form-input" id="changeOutageEnd">
                        </div>
                    </div>

                    <h3 class="form-section-title" data-section="details">Details</h3>

                    <div class="rich-text-tabs" id="richTextTabs" data-section="details">
                        <button class="rich-text-tab active" data-field="description" onclick="switchTab('description')">Description</button>
                        <button class="rich-text-tab" data-field="reason" onclick="switchTab('reason')">Reason for Change</button>
                        <button class="rich-text-tab" data-field="risk" onclick="switchTab('risk')">Risk Evaluation</button>
                        <button class="rich-text-tab" data-field="testplan" onclick="switchTab('testplan')">Test Plan</button>
                        <button class="rich-text-tab" data-field="rollback" onclick="switchTab('rollback')">Rollback Plan</button>
                        <button class="rich-text-tab" data-field="pir" onclick="switchTab('pir')">Post-Implementation Review</button>
                    </div>

                    <div class="rich-text-panel active" id="panel-description" data-field="description">
                        <textarea id="editorDescription"></textarea>
                    </div>
                    <div class="rich-text-panel" id="panel-reason" data-field="reason">
                        <textarea id="editorReason"></textarea>
                    </div>
                    <div class="rich-text-panel" id="panel-risk" data-field="risk">
                        <textarea id="editorRisk"></textarea>
                    </div>
                    <div class="rich-text-panel" id="panel-testplan" data-field="testplan">
                        <textarea id="editorTestplan"></textarea>
                    </div>
                    <div class="rich-text-panel" id="panel-rollback" data-field="rollback">
                        <textarea id="editorRollback"></textarea>
                    </div>
                    <div class="rich-text-panel" id="panel-pir" data-field="pir">
                        <textarea id="editorPir"></textarea>
                    </div>

                    <h3 class="form-section-title" data-section="attachments">Attachments</h3>

                    <div data-field="attachments">
                        <div class="attachment-list" id="editorAttachmentList"></div>

                        <div class="file-upload-area" id="fileUploadArea">
                            <div class="upload-icon">&#128206;</div>
                            <p>Drag and drop files here, or click to browse</p>
                            <input type="file" id="fileInput" multiple style="display:none;">
                        </div>
                    </div>

                    <div class="editor-actions">
                        <button class="btn btn-secondary" onclick="cancelEdit()">Cancel</button>
                        <button class="btn btn-primary" onclick="saveChange()">Save</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

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
    <script>window.API_BASE = '../api/change-management/';</script>
    <script src="../assets/js/change-management.js"></script>
</body>
</html>
