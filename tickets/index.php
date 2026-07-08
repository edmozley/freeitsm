<?php
/**
 * Inbox - Main interface for Service Desk Ticketing System
 * Folder-style layout with departments, statuses, and reading pane
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'inbox';
// Module id for per-module theme/palette resolution (account-menu picker).
$theme_module = 'tickets';

// Namespaces this page needs translated for the JS bridge (inbox.js will
// gain t() calls in phase 1b; the bridge is in place ahead of time).
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active($theme_module)); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode($theme_module)); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.title')); ?> - <?php echo htmlspecialchars(t('tickets.nav.inbox')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=18">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=37">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=18">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/i18n.js?v=2"></script>
    <script src="../assets/js/tinymce/tinymce.min.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container">
        <!-- Folder Navigation -->
        <div class="folder-container">
            <div class="folder-header">
                <h2><?php echo htmlspecialchars(t('tickets.folders.title')); ?></h2>
                <div class="folder-group-toggle" role="group" aria-label="<?php echo htmlspecialchars(t('tickets.folders.group_label')); ?>">
                    <button type="button" class="folder-group-btn active" data-group="department" onclick="setFolderGrouping('department')"><?php echo htmlspecialchars(t('tickets.folders.group_department')); ?></button>
                    <button type="button" class="folder-group-btn" data-group="analyst" onclick="setFolderGrouping('analyst')"><?php echo htmlspecialchars(t('tickets.folders.group_analyst')); ?></button>
                </div>
            </div>
            <div class="folder-list" id="folderList">
                <div class="loading">
                    <div class="spinner"></div>
                </div>
            </div>
        </div>

        <!-- Email List -->
        <div class="email-list-container">
            <div class="email-list-header">
                <h3 id="emailListTitle"><?php echo htmlspecialchars(t('tickets.list.all_tickets')); ?></h3>
                <div class="email-list-actions">
                    <button class="icon-btn icon-btn-new" onclick="openNewTicketModal()" title="<?php echo htmlspecialchars(t('tickets.list.new_ticket_btn')); ?>" aria-label="<?php echo htmlspecialchars(t('tickets.list.new_ticket_btn')); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </button>
                    <button class="icon-btn" onclick="openSearchModal()" title="<?php echo htmlspecialchars(t('tickets.list.search_btn')); ?>" aria-label="<?php echo htmlspecialchars(t('tickets.list.search_btn')); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </button>
                    <button class="icon-btn" onclick="refreshCurrentView()" title="<?php echo htmlspecialchars(t('tickets.list.refresh_btn')); ?>" aria-label="<?php echo htmlspecialchars(t('tickets.list.refresh_btn')); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    </button>
                </div>
            </div>
            <div class="email-list" id="emailList">
                <div class="reading-pane-empty"><?php echo htmlspecialchars(t('tickets.list.select_folder')); ?></div>
            </div>
        </div>

        <!-- Reading Pane -->
        <div class="reading-pane" id="readingPane">
            <div class="reading-pane-empty">
                <?php echo htmlspecialchars(t('tickets.reading_pane.select_ticket')); ?>
            </div>
        </div>
    </div>

    <!-- Add Note Modal -->
    <div class="modal" id="noteModal">
        <div class="modal-content">
            <div class="modal-header"><?php echo htmlspecialchars(t('tickets.note_modal.title')); ?></div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('tickets.note_modal.note_label')); ?></label>
                    <textarea class="form-textarea" id="noteText" placeholder="<?php echo htmlspecialchars(t('tickets.note_modal.placeholder')); ?>"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeNoteModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" onclick="saveNote()"><?php echo htmlspecialchars(t('tickets.note_modal.save_btn')); ?></button>
            </div>
        </div>
    </div>

    <!-- Reply/Forward Modal -->
    <div class="modal" id="emailModal">
        <div class="modal-content">
            <div class="modal-body">
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo htmlspecialchars(t('tickets.reply_modal.to')); ?></label>
                        <input type="text" class="form-input" id="emailTo" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('tickets.reply_modal.to_placeholder')); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo htmlspecialchars(t('tickets.reply_modal.cc')); ?></label>
                        <input type="text" class="form-input" id="emailCc" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('tickets.reply_modal.cc_placeholder')); ?>">
                    </div>
                </div>
                <input type="hidden" id="emailSubject">
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('tickets.reply_modal.message')); ?></label>
                    <textarea id="emailBody"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('tickets.reply_modal.attachments')); ?></label>
                    <div class="attachment-dropzone" id="attachmentDropzone">
                        <input type="file" id="attachmentInput" multiple style="display: none;">
                        <div class="dropzone-content">
                            <span class="dropzone-icon">📎</span>
                            <span><?php echo htmlspecialchars(t('tickets.reply_modal.drop_files')); ?> <a href="#" onclick="document.getElementById('attachmentInput').click(); return false;"><?php echo htmlspecialchars(t('tickets.reply_modal.browse')); ?></a></span>
                        </div>
                    </div>
                    <div class="attachment-list" id="attachmentList"></div>
                </div>
            </div>
            <div id="replyCleanupUndoBar" style="display:none; padding: 8px 0; color: #555; font-size: 13px;">
                ✨ <?php echo htmlspecialchars(t('tickets.reply_modal.cleaned_up')); ?> — <a href="#" id="replyCleanupUndoLink" style="color: #0078d4;"><?php echo htmlspecialchars(t('tickets.reply_modal.undo')); ?></a> <span id="replyCleanupUndoTimer" style="color: #999;"></span>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeEmailModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-cleanup" id="replyCleanupBtn" onclick="cleanupReplyDraft()" style="display:none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg>
                    <?php echo htmlspecialchars(t('tickets.reply_modal.cleanup')); ?>
                </button>
                <button class="btn btn-primary" onclick="sendEmail()" id="replySendBtn"><?php echo htmlspecialchars(t('tickets.reply_modal.send')); ?></button>
            </div>
        </div>
    </div>

    <!-- New Ticket Modal -->
    <div class="modal" id="newTicketModal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header"><?php echo htmlspecialchars(t('tickets.new_ticket_modal.title')); ?></div>
            <div class="modal-body">
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo htmlspecialchars(t('tickets.new_ticket_modal.requester_name')); ?> *</label>
                        <input type="text" class="form-input" id="newTicketFromName" placeholder="<?php echo htmlspecialchars(t('tickets.new_ticket_modal.name_placeholder')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo htmlspecialchars(t('tickets.new_ticket_modal.requester_email')); ?> *</label>
                        <input type="email" class="form-input" id="newTicketFromEmail" placeholder="<?php echo htmlspecialchars(t('tickets.new_ticket_modal.email_placeholder')); ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">
                        <?php echo htmlspecialchars(t('tickets.new_ticket_modal.mailbox')); ?>
                        <span id="newTicketCompanyLabel" style="color:#888;font-weight:normal;"></span>
                    </label>
                    <select class="form-select" id="newTicketMailbox"></select>
                    <div id="newTicketMailboxHint" style="font-size:12px;color:#999;margin-top:4px;"></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('tickets.new_ticket_modal.subject')); ?> *</label>
                    <input type="text" class="form-input" id="newTicketSubject" placeholder="<?php echo htmlspecialchars(t('tickets.new_ticket_modal.subject_placeholder')); ?>" required>
                </div>
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo htmlspecialchars(t('tickets.new_ticket_modal.department')); ?></label>
                        <select class="form-select" id="newTicketDepartment">
                            <option value=""><?php echo htmlspecialchars(t('tickets.new_ticket_modal.select_placeholder')); ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo htmlspecialchars(t('tickets.new_ticket_modal.type')); ?></label>
                        <select class="form-select" id="newTicketType">
                            <option value=""><?php echo htmlspecialchars(t('tickets.new_ticket_modal.select_placeholder')); ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo htmlspecialchars(t('tickets.new_ticket_modal.priority')); ?></label>
                        <!-- Populated from the configured priorities in openNewTicketModal() (#40) -->
                        <select class="form-select" id="newTicketPriority"></select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('tickets.new_ticket_modal.description')); ?></label>
                    <textarea class="form-textarea" id="newTicketBody" rows="8" placeholder="<?php echo htmlspecialchars(t('tickets.new_ticket_modal.description_placeholder')); ?>"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeNewTicketModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" onclick="createNewTicket()"><?php echo htmlspecialchars(t('tickets.new_ticket_modal.create_btn')); ?></button>
            </div>
        </div>
    </div>

    <!-- Search Modal (Draggable) -->
    <div class="search-modal" id="searchModal">
        <div class="search-modal-header" id="searchModalHeader">
            <span><?php echo htmlspecialchars(t('tickets.search_modal.title')); ?></span>
            <button class="search-modal-close" onclick="closeSearchModal()">&times;</button>
        </div>
        <div class="search-modal-body">
            <div class="search-form">
                <div class="search-field">
                    <label><?php echo htmlspecialchars(t('tickets.search_modal.ticket_number')); ?></label>
                    <input type="text" id="searchTicketNumber" placeholder="<?php echo htmlspecialchars(t('tickets.search_modal.ticket_number_ph')); ?>">
                </div>
                <div class="search-field">
                    <label><?php echo htmlspecialchars(t('tickets.search_modal.email_address')); ?></label>
                    <input type="text" id="searchEmail" placeholder="<?php echo htmlspecialchars(t('tickets.search_modal.email_ph')); ?>">
                </div>
                <div class="search-field">
                    <label><?php echo htmlspecialchars(t('tickets.search_modal.subject')); ?></label>
                    <input type="text" id="searchSubject" placeholder="<?php echo htmlspecialchars(t('tickets.search_modal.subject_ph')); ?>">
                </div>
                <div class="search-actions">
                    <button class="btn btn-primary" onclick="performSearch()"><?php echo htmlspecialchars(t('tickets.search_modal.search_btn')); ?></button>
                    <button class="btn btn-secondary" onclick="clearSearch()"><?php echo htmlspecialchars(t('tickets.search_modal.clear_btn')); ?></button>
                </div>
            </div>
            <div class="search-results" id="searchResults">
                <div class="search-results-empty"><?php echo htmlspecialchars(t('tickets.search_modal.empty_state')); ?></div>
            </div>
        </div>
    </div>

    <!-- Schedule Modal -->
    <div class="modal" id="scheduleModal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header"><?php echo htmlspecialchars(t('tickets.schedule_modal.title')); ?></div>
            <div class="modal-body">
                <p class="schedule-ticket-info" id="scheduleTicketInfo"></p>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('tickets.schedule_modal.date')); ?> *</label>
                    <input type="date" class="form-input" id="scheduleDate" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo htmlspecialchars(t('tickets.schedule_modal.start_time')); ?> *</label>
                    <input type="time" class="form-input" id="scheduleTime" required>
                </div>
                <div class="schedule-current" id="scheduleCurrent" style="display: none;">
                    <p><?php echo htmlspecialchars(t('tickets.schedule_modal.currently_scheduled')); ?> <span id="currentSchedule"></span></p>
                    <button class="btn btn-link" onclick="clearSchedule()"><?php echo htmlspecialchars(t('tickets.schedule_modal.clear_schedule')); ?></button>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeScheduleModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="btn btn-primary" onclick="saveSchedule()"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Right-click context menu for email rows. Positioned in JS at cursor. -->
    <div class="ticket-context-menu" id="ticketContextMenu" role="menu">
        <div class="ticket-context-menu-header" id="ticketContextMenuHeader"></div>
        <button class="ticket-context-menu-item" type="button" onclick="openContextLinkCmdb()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            <span><?php echo htmlspecialchars(t('tickets.context.link_cmdb')); ?></span>
        </button>
        <button class="ticket-context-menu-item" type="button" onclick="openContextLinkProblem()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span><?php echo htmlspecialchars(t('tickets.context.link_problem')); ?></span>
        </button>
        <button class="ticket-context-menu-item" type="button" onclick="openContextLinkChange()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span><?php echo htmlspecialchars(t('tickets.context.link_change')); ?></span>
        </button>
        <button class="ticket-context-menu-item" type="button" onclick="openContextRecordTime()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span><?php echo htmlspecialchars(t('tickets.context.record_time')); ?></span>
        </button>
        <!-- Status submenu parent. Hover/focus to reveal the flyout populated
             at menu-open time from the active ticket_statuses. -->
        <div class="ticket-context-menu-item ticket-context-menu-parent" role="menuitem" tabindex="0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span><?php echo htmlspecialchars(t('tickets.context.set_status')); ?></span>
            <svg class="ctx-sub-arrow" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            <div class="ticket-context-submenu" id="ctxStatusSubmenu" role="menu">
                <!-- Populated by openTicketContextMenu(). -->
            </div>
        </div>
        <!-- Priority submenu parent. Same pattern as Set status, populated
             from the active ticket_priorities lookup at menu-open time. -->
        <div class="ticket-context-menu-item ticket-context-menu-parent" role="menuitem" tabindex="0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
            <span><?php echo htmlspecialchars(t('tickets.context.set_priority')); ?></span>
            <svg class="ctx-sub-arrow" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            <div class="ticket-context-submenu" id="ctxPrioritySubmenu" role="menu">
                <!-- Populated by openTicketContextMenu(). -->
            </div>
        </div>
        <!-- Department submenu parent. Lists the analyst's team departments
             (same source as the in-panel Department dropdown); picking one sets
             department_id. Includes a "(no department)" clear row. -->
        <div class="ticket-context-menu-item ticket-context-menu-parent" role="menuitem" tabindex="0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01"/><path d="M9 12v.01"/><path d="M9 15v.01"/><path d="M9 18v.01"/></svg>
            <span><?php echo htmlspecialchars(t('tickets.context.set_department')); ?></span>
            <svg class="ctx-sub-arrow" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            <div class="ticket-context-submenu" id="ctxDepartmentSubmenu" role="menu">
                <!-- Populated by openTicketContextMenu(). -->
            </div>
        </div>
        <!-- Type submenu parent. Lists active ticket_types (same source as the
             in-panel Type dropdown); picking one sets ticket_type_id. Includes a
             "(no type)" clear row. -->
        <div class="ticket-context-menu-item ticket-context-menu-parent" role="menuitem" tabindex="0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            <span><?php echo htmlspecialchars(t('tickets.context.set_type')); ?></span>
            <svg class="ctx-sub-arrow" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            <div class="ticket-context-submenu" id="ctxTypeSubmenu" role="menu">
                <!-- Populated by openTicketContextMenu(). -->
            </div>
        </div>
        <!-- Assign-to submenu parent. Lists active analysts; picking one sets
             both assigned_analyst_id and owner_id (mirrors drag-to-analyst-folder). -->
        <div class="ticket-context-menu-item ticket-context-menu-parent" role="menuitem" tabindex="0">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            <span><?php echo htmlspecialchars(t('tickets.context.assign_to')); ?></span>
            <svg class="ctx-sub-arrow" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            <div class="ticket-context-submenu" id="ctxAssigneeSubmenu" role="menu">
                <!-- Populated by openTicketContextMenu(). -->
            </div>
        </div>
        <!-- Move-to-company submenu parent. Multi-company installs only; hidden at N=1.
             Lists the companies the analyst can access; picking one re-homes the ticket. -->
        <div class="ticket-context-menu-item ticket-context-menu-parent" id="ctxCompanyParent" role="menuitem" tabindex="0" style="display:none;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16"/><path d="M19 21V9a2 2 0 0 0-2-2h-2"/><path d="M9 7h2"/><path d="M9 11h2"/><path d="M9 15h2"/></svg>
            <span><?php echo htmlspecialchars(t('tickets.context.move_company')); ?></span>
            <svg class="ctx-sub-arrow" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            <div class="ticket-context-submenu" id="ctxCompanySubmenu" role="menu">
                <!-- Populated by openTicketContextMenu(). -->
            </div>
        </div>
        <div style="height:1px;background:#eee;margin:4px 0;"></div>
        <button class="ticket-context-menu-item" type="button" onclick="contextMoveToTrash()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            <span><?php echo htmlspecialchars(t('tickets.context.move_trash')); ?></span>
        </button>
    </div>

    <!-- Trash folder context menu -->
    <div class="ticket-context-menu" id="trashContextMenu" role="menu">
        <button class="ticket-context-menu-item" type="button" onclick="emptyTrash()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            <span><?php echo htmlspecialchars(t('tickets.context.empty_trash')); ?></span>
        </button>
    </div>

    <!-- Context menu — Link CMDB Object modal (standalone, no ticket needs to be loaded) -->
    <div class="modal" id="ctxCmdbModal">
        <div class="modal-content" style="max-width: 520px;">
            <div class="modal-header">Link CMDB object to <span id="ctxCmdbTicketRef"></span></div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-input" id="ctxCmdbSearchInput" placeholder="Type to search any CMDB object…" autocomplete="off">
                </div>
                <div class="ctx-cmdb-results" id="ctxCmdbResults"></div>
                <div class="form-group" style="margin-top: 12px;">
                    <label class="form-label" style="font-size: 12px; color: #666;">Recently linked (this session)</label>
                    <div id="ctxCmdbSessionLog" style="font-size: 12px; color: #555;">None yet — pick from the search results above.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeContextCmdbModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Link-to-problem modal (used by the right-click menu and the reading-pane button) -->
    <div class="modal" id="linkProblemModal">
        <div class="modal-content" style="max-width: 620px;">
            <div class="modal-header">Link <span id="linkProblemTicketRef"></span> to a problem</div>
            <div class="modal-body">
                <div class="form-group">
                    <input type="text" class="form-input" id="linkProblemSearch" placeholder="Search problems by number or title…" autocomplete="off" oninput="linkProblemSearchDebounced()">
                </div>
                <div class="lp-list" id="linkProblemList"><div class="lp-empty">Loading…</div></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeLinkProblemModal()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Link-to-change modal (used by the right-click menu and the reading-pane button) -->
    <div class="modal" id="linkChangeModal">
        <div class="modal-content" style="max-width: 620px;">
            <div class="modal-header">Link <span id="linkChangeTicketRef"></span> to a change</div>
            <div class="modal-body">
                <div class="form-group">
                    <input type="text" class="form-input" id="linkChangeSearch" placeholder="Search changes by reference or title…" autocomplete="off" oninput="linkChangeSearchDebounced()">
                </div>
                <div class="lp-list" id="linkChangeList"><div class="lp-empty">Loading…</div></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeLinkChangeModal()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Context menu — Record Time modal -->
    <div class="modal" id="ctxTimeModal">
        <div class="modal-content" style="max-width: 480px;">
            <div class="modal-header">Record time on <span id="ctxTimeTicketRef"></span></div>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group" style="flex: 0 0 140px;">
                        <label class="form-label">Minutes</label>
                        <input type="number" class="form-input" id="ctxTimeMinutes" min="1" step="1" placeholder="e.g. 30">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">When</label>
                        <input type="datetime-local" class="form-input" id="ctxTimeWhen">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">What did you do? (optional)</label>
                    <textarea class="form-textarea" id="ctxTimeNotes" rows="3" placeholder="Brief description…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeContextTimeModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveContextTimeEntry()">Save</button>
            </div>
        </div>
    </div>
    <script>
        window.API_BASE = '../api/tickets/';
        window.CURRENT_ANALYST_ID = <?php echo (int)($_SESSION['analyst_id'] ?? 0); ?>;
    </script>
    <script src="../assets/js/inbox.js?v=54"></script>
    <script src="../assets/js/mobile.js?v=9"></script>
    <script>
    // Auto-check mailboxes every 60 seconds
    (function() {
        const POLL_INTERVAL = 60000;
        const btn = document.getElementById('mailCheckBtn');
        let polling = false;

        // Show the icon on the inbox page
        if (btn) btn.style.display = '';

        async function checkMailboxes() {
            if (polling) return;
            polling = true;
            if (btn) btn.classList.add('checking');

            try {
                // Get active authenticated mailboxes
                const mbRes = await fetch('../api/tickets/get_mailboxes.php');
                const mbData = await mbRes.json();
                if (!mbData.success) { polling = false; if (btn) btn.classList.remove('checking'); return; }

                const active = mbData.mailboxes.filter(m => m.is_authenticated && m.is_active);
                let totalNew = 0;

                for (const mb of active) {
                    try {
                        const res = await fetch('../api/tickets/check_mailbox_email.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ mailbox_id: mb.id })
                        });
                        const data = await res.json();
                        if (data.success) totalNew += data.details?.emails_saved || 0;
                    } catch (e) { /* skip */ }
                }

                // Refresh inbox if new emails arrived
                if (totalNew > 0 && typeof refreshCurrentView === 'function') {
                    refreshCurrentView();
                    loadFolderCounts();
                }
            } catch (e) { /* skip */ }

            polling = false;
            if (btn) btn.classList.remove('checking');
        }

        // Manual trigger
        window.triggerMailCheck = checkMailboxes;

        // Run on load then every 60s
        checkMailboxes();
        setInterval(checkMailboxes, POLL_INTERVAL);
    })();
    </script>

    <!-- AI Chat Panel -->
    <div class="ai-chat-overlay" id="ticketAiOverlay" onclick="closeTicketAiChat()"></div>
    <div class="ai-chat-panel" id="ticketAiPanel">
        <div class="ai-chat-header">
            <div class="ai-chat-title"><?php echo htmlspecialchars(t('tickets.ai_chat.title')); ?></div>
            <button class="ai-chat-close" onclick="closeTicketAiChat()">&times;</button>
        </div>
        <div class="ai-chat-messages" id="ticketAiMessages">
            <div class="ai-chat-welcome">
                <?php echo htmlspecialchars(t('tickets.ai_chat.welcome')); ?>
            </div>
        </div>
        <div class="ai-chat-input-area">
            <textarea id="ticketAiInput" placeholder="<?php echo htmlspecialchars(t('tickets.ai_chat.placeholder')); ?>" rows="2" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();askTicketAi();}"></textarea>
            <button class="ai-chat-send" id="ticketAiSendBtn" onclick="askTicketAi()">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </button>
        </div>
    </div>
</body>
</html>
