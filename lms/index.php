<?php
/**
 * LMS - Learning Management System
 * Dashboard with course management, learning groups, assignments, and progress tracking
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'lms';
$path_prefix = '../';
$translationNamespaces = ['common', 'lms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('lms.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/lms.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="lms-container">
        <!-- Tabs -->
        <div class="lms-tabs">
            <button class="lms-tab active" data-tab="courses" onclick="LMS.switchTab('courses')"><?php echo htmlspecialchars(t('lms.tabs.courses')); ?></button>
            <button class="lms-tab" data-tab="groups" onclick="LMS.switchTab('groups')"><?php echo htmlspecialchars(t('lms.tabs.groups')); ?></button>
            <button class="lms-tab" data-tab="assignments" onclick="LMS.switchTab('assignments')"><?php echo htmlspecialchars(t('lms.tabs.assignments')); ?></button>
            <button class="lms-tab" data-tab="progress" onclick="LMS.switchTab('progress')"><?php echo htmlspecialchars(t('lms.tabs.progress')); ?></button>
        </div>

        <!-- Courses Tab -->
        <div class="lms-panel" id="panel-courses">
            <div class="lms-panel-header">
                <h2><?php echo htmlspecialchars(t('lms.courses.heading')); ?></h2>
                <button class="btn btn-primary" onclick="LMS.openUploadModal()"><?php echo htmlspecialchars(t('lms.courses.upload')); ?></button>
            </div>
            <table class="lms-table">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('lms.courses.col_title')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.courses.col_version')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.courses.col_uploaded')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.courses.col_status')); ?></th>
                        <th style="width: 100px;"><?php echo htmlspecialchars(t('lms.courses.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="coursesBody">
                    <tr><td colspan="5" class="lms-empty"><?php echo htmlspecialchars(t('lms.courses.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Groups Tab -->
        <div class="lms-panel" id="panel-groups" style="display:none;">
            <div class="lms-panel-header">
                <h2><?php echo htmlspecialchars(t('lms.groups.heading')); ?></h2>
                <button class="btn btn-primary" onclick="LMS.openGroupModal()"><?php echo htmlspecialchars(t('lms.groups.new')); ?></button>
            </div>
            <table class="lms-table">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('lms.groups.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.groups.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.groups.col_members')); ?></th>
                        <th style="width: 100px;"><?php echo htmlspecialchars(t('lms.groups.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="groupsBody">
                    <tr><td colspan="4" class="lms-empty"><?php echo htmlspecialchars(t('lms.groups.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Assignments Tab -->
        <div class="lms-panel" id="panel-assignments" style="display:none;">
            <div class="lms-panel-header">
                <h2><?php echo htmlspecialchars(t('lms.assignments.heading')); ?></h2>
                <button class="btn btn-primary" onclick="LMS.openAssignModal()"><?php echo htmlspecialchars(t('lms.assignments.assign')); ?></button>
            </div>
            <table class="lms-table">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('lms.assignments.col_course')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.assignments.col_group')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.assignments.col_deadline')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.assignments.col_assigned_by')); ?></th>
                        <th style="width: 100px;"><?php echo htmlspecialchars(t('lms.assignments.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="assignmentsBody">
                    <tr><td colspan="5" class="lms-empty"><?php echo htmlspecialchars(t('lms.assignments.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Progress Tab -->
        <div class="lms-panel" id="panel-progress" style="display:none;">
            <div class="lms-panel-header">
                <h2><?php echo htmlspecialchars(t('lms.progress.heading')); ?></h2>
                <div class="lms-filters">
                    <select id="filterCourse" onchange="LMS.loadProgress()">
                        <option value=""><?php echo htmlspecialchars(t('lms.progress.all_courses')); ?></option>
                    </select>
                    <select id="filterGroup" onchange="LMS.loadProgress()">
                        <option value=""><?php echo htmlspecialchars(t('lms.progress.all_groups')); ?></option>
                    </select>
                    <select id="filterStatus" onchange="LMS.loadProgress()">
                        <option value=""><?php echo htmlspecialchars(t('lms.progress.all_statuses')); ?></option>
                        <option value="not_started"><?php echo htmlspecialchars(t('lms.status.not_started')); ?></option>
                        <option value="incomplete"><?php echo htmlspecialchars(t('lms.status.incomplete')); ?></option>
                        <option value="completed"><?php echo htmlspecialchars(t('lms.status.completed')); ?></option>
                        <option value="passed"><?php echo htmlspecialchars(t('lms.status.passed')); ?></option>
                        <option value="failed"><?php echo htmlspecialchars(t('lms.status.failed')); ?></option>
                        <option value="overdue"><?php echo htmlspecialchars(t('lms.status.overdue')); ?></option>
                    </select>
                </div>
            </div>
            <table class="lms-table">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('lms.progress.col_analyst')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.progress.col_course')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.progress.col_group')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.progress.col_status')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.progress.col_score')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.progress.col_deadline')); ?></th>
                        <th><?php echo htmlspecialchars(t('lms.progress.col_last_access')); ?></th>
                        <th style="width: 60px;"></th>
                    </tr>
                </thead>
                <tbody id="progressBody">
                    <tr><td colspan="8" class="lms-empty"><?php echo htmlspecialchars(t('lms.progress.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Upload Course Modal -->
    <div class="modal" id="uploadModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header"><?php echo htmlspecialchars(t('lms.upload_modal.title')); ?></div>
            <form id="uploadForm" enctype="multipart/form-data" style="padding: 20px 24px;">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.upload_modal.field_title')); ?></label>
                    <input type="text" id="courseTitle" required>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.upload_modal.field_description')); ?></label>
                    <textarea id="courseDescription" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.upload_modal.field_package')); ?></label>
                    <input type="file" id="courseFile" accept=".zip" required>
                    <small style="color: #666;"><?php echo htmlspecialchars(t('lms.upload_modal.package_hint')); ?></small>
                </div>
                <div id="uploadProgress" style="display:none; margin-bottom: 12px;">
                    <div style="background: #e0e0e0; border-radius: 4px; overflow: hidden;">
                        <div id="uploadBar" style="height: 6px; background: #2563eb; width: 0%; transition: width 0.3s;"></div>
                    </div>
                    <small id="uploadStatus" style="color: #666;"><?php echo htmlspecialchars(t('lms.upload_modal.uploading')); ?></small>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="LMS.closeModal('uploadModal')"><?php echo htmlspecialchars(t('lms.upload_modal.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary" id="uploadBtn"><?php echo htmlspecialchars(t('lms.upload_modal.submit')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Group Modal -->
    <div class="modal" id="groupModal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header" id="groupModalTitle"><?php echo htmlspecialchars(t('lms.group_modal.title_new')); ?></div>
            <form id="groupForm" style="padding: 20px 24px;">
                <input type="hidden" id="groupId">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.group_modal.field_name')); ?></label>
                    <input type="text" id="groupName" required>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.group_modal.field_description')); ?></label>
                    <input type="text" id="groupDescription">
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.group_modal.field_members')); ?></label>
                    <div id="membersList" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px; padding: 8px;"></div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="LMS.closeModal('groupModal')"><?php echo htmlspecialchars(t('lms.group_modal.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('lms.group_modal.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Assign Course Modal -->
    <div class="modal" id="assignModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header"><?php echo htmlspecialchars(t('lms.assign_modal.title')); ?></div>
            <form id="assignForm" style="padding: 20px 24px;">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.assign_modal.field_course')); ?></label>
                    <select id="assignCourse" required></select>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.assign_modal.field_group')); ?></label>
                    <select id="assignGroup" required></select>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.assign_modal.field_deadline')); ?></label>
                    <input type="date" id="assignDeadline">
                    <small style="color: #666;"><?php echo htmlspecialchars(t('lms.assign_modal.deadline_hint')); ?></small>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="LMS.closeModal('assignModal')"><?php echo htmlspecialchars(t('lms.assign_modal.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('lms.assign_modal.submit')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Learner Data Modal -->
    <div class="modal" id="learnerDataModal">
        <div class="modal-content" style="max-width: 800px; max-height: 90vh;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center;">
                <span id="learnerDataTitle"><?php echo htmlspecialchars(t('lms.learner_modal.title')); ?></span>
                <button style="background:none;border:none;font-size:22px;cursor:pointer;color:#999;" onclick="LMS.closeModal('learnerDataModal')">&times;</button>
            </div>
            <div id="learnerDataBody" style="padding: 20px 24px; overflow-y: auto; flex: 1;"></div>
        </div>
    </div>

    <!-- Toast -->
    <script>window.API_BASE = '../api/lms/';</script>
    <script src="../assets/js/lms.js?v=2"></script>
</body>
</html>
