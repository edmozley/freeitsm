<?php
/**
 * LMS - Learning Management System
 * Dashboard with course management, learning groups, assignments, and progress tracking
 */
session_start();
require_once '../config.php';

$current_page = 'lms';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - LMS</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/lms.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="lms-container">
        <!-- Tabs -->
        <div class="lms-tabs">
            <button class="lms-tab active" data-tab="courses" onclick="LMS.switchTab('courses')">Courses</button>
            <button class="lms-tab" data-tab="groups" onclick="LMS.switchTab('groups')">Groups</button>
            <button class="lms-tab" data-tab="assignments" onclick="LMS.switchTab('assignments')">Assignments</button>
            <button class="lms-tab" data-tab="progress" onclick="LMS.switchTab('progress')">Progress</button>
        </div>

        <!-- Courses Tab -->
        <div class="lms-panel" id="panel-courses">
            <div class="lms-panel-header">
                <h2>Courses</h2>
                <button class="btn btn-primary" onclick="LMS.openUploadModal()">Upload</button>
            </div>
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>SCORM Version</th>
                        <th>Uploaded</th>
                        <th>Status</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="coursesBody">
                    <tr><td colspan="5" class="lms-empty">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Groups Tab -->
        <div class="lms-panel" id="panel-groups" style="display:none;">
            <div class="lms-panel-header">
                <h2>Learning Groups</h2>
                <button class="btn btn-primary" onclick="LMS.openGroupModal()">New</button>
            </div>
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Members</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="groupsBody">
                    <tr><td colspan="4" class="lms-empty">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Assignments Tab -->
        <div class="lms-panel" id="panel-assignments" style="display:none;">
            <div class="lms-panel-header">
                <h2>Assignments</h2>
                <button class="btn btn-primary" onclick="LMS.openAssignModal()">Assign</button>
            </div>
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Group</th>
                        <th>Deadline</th>
                        <th>Assigned By</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="assignmentsBody">
                    <tr><td colspan="5" class="lms-empty">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Progress Tab -->
        <div class="lms-panel" id="panel-progress" style="display:none;">
            <div class="lms-panel-header">
                <h2>Progress</h2>
                <div class="lms-filters">
                    <select id="filterCourse" onchange="LMS.loadProgress()">
                        <option value="">All courses</option>
                    </select>
                    <select id="filterGroup" onchange="LMS.loadProgress()">
                        <option value="">All groups</option>
                    </select>
                    <select id="filterStatus" onchange="LMS.loadProgress()">
                        <option value="">All statuses</option>
                        <option value="not_started">Not Started</option>
                        <option value="incomplete">Incomplete</option>
                        <option value="completed">Completed</option>
                        <option value="passed">Passed</option>
                        <option value="failed">Failed</option>
                        <option value="overdue">Overdue</option>
                    </select>
                </div>
            </div>
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Analyst</th>
                        <th>Course</th>
                        <th>Group</th>
                        <th>Status</th>
                        <th>Score</th>
                        <th>Deadline</th>
                        <th>Last Access</th>
                    </tr>
                </thead>
                <tbody id="progressBody">
                    <tr><td colspan="7" class="lms-empty">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Upload Course Modal -->
    <div class="modal" id="uploadModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">Upload SCORM Course</div>
            <form id="uploadForm" enctype="multipart/form-data" style="padding: 20px 24px;">
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" id="courseTitle" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="courseDescription" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label>SCORM Package (ZIP) *</label>
                    <input type="file" id="courseFile" accept=".zip" required>
                    <small style="color: #666;">Upload a SCORM 1.1, 1.2, or 2004 ZIP package</small>
                </div>
                <div id="uploadProgress" style="display:none; margin-bottom: 12px;">
                    <div style="background: #e0e0e0; border-radius: 4px; overflow: hidden;">
                        <div id="uploadBar" style="height: 6px; background: #2563eb; width: 0%; transition: width 0.3s;"></div>
                    </div>
                    <small id="uploadStatus" style="color: #666;">Uploading...</small>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="LMS.closeModal('uploadModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="uploadBtn">Upload</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Group Modal -->
    <div class="modal" id="groupModal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header" id="groupModalTitle">New Group</div>
            <form id="groupForm" style="padding: 20px 24px;">
                <input type="hidden" id="groupId">
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" id="groupName" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" id="groupDescription">
                </div>
                <div class="form-group">
                    <label>Members</label>
                    <div id="membersList" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px; padding: 8px;"></div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="LMS.closeModal('groupModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Assign Course Modal -->
    <div class="modal" id="assignModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">Assign Course</div>
            <form id="assignForm" style="padding: 20px 24px;">
                <div class="form-group">
                    <label>Course *</label>
                    <select id="assignCourse" required></select>
                </div>
                <div class="form-group">
                    <label>Group *</label>
                    <select id="assignGroup" required></select>
                </div>
                <div class="form-group">
                    <label>Deadline</label>
                    <input type="date" id="assignDeadline">
                    <small style="color: #666;">Leave blank for no deadline</small>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="LMS.closeModal('assignModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Assign</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <script>window.API_BASE = '../api/lms/';</script>
    <script src="../assets/js/lms.js"></script>
</body>
</html>
