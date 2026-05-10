<?php
/**
 * Inbox - Main interface for Service Desk Ticketing System
 * Folder-style layout with departments, statuses, and reading pane
 */
session_start();
require_once '../config.php';

$current_page = 'inbox';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Inbox</title>
    <link rel="stylesheet" href="../assets/css/inbox.css?v=9">
    <script src="../assets/js/tinymce/tinymce.min.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container">
        <!-- Folder Navigation -->
        <div class="folder-container">
            <div class="folder-header">
                <h2>Folders</h2>
                <div class="folder-group-toggle" role="group" aria-label="Group folders by">
                    <button type="button" class="folder-group-btn active" data-group="department" onclick="setFolderGrouping('department')">Department</button>
                    <button type="button" class="folder-group-btn" data-group="analyst" onclick="setFolderGrouping('analyst')">Analyst</button>
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
                <h3 id="emailListTitle">All Tickets</h3>
                <div class="email-list-actions">
                    <button class="icon-btn icon-btn-new" onclick="openNewTicketModal()" title="New ticket" aria-label="New ticket">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </button>
                    <button class="icon-btn" onclick="openSearchModal()" title="Search tickets" aria-label="Search tickets">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </button>
                    <button class="icon-btn" onclick="refreshCurrentView()" title="Refresh" aria-label="Refresh">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    </button>
                </div>
            </div>
            <div class="email-list" id="emailList">
                <div class="reading-pane-empty">Select a folder to view tickets</div>
            </div>
        </div>

        <!-- Reading Pane -->
        <div class="reading-pane" id="readingPane">
            <div class="reading-pane-empty">
                Select a ticket to view details
            </div>
        </div>
    </div>

    <!-- Add Note Modal -->
    <div class="modal" id="noteModal">
        <div class="modal-content">
            <div class="modal-header">Add Note</div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Note</label>
                    <textarea class="form-textarea" id="noteText" placeholder="Enter your note here..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeNoteModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveNote()">Save Note</button>
            </div>
        </div>
    </div>

    <!-- Reply/Forward Modal -->
    <div class="modal" id="emailModal">
        <div class="modal-content">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">To</label>
                        <input type="text" class="form-input" id="emailTo" placeholder="recipient@example.com">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Cc</label>
                        <input type="text" class="form-input" id="emailCc" placeholder="cc@example.com (separate multiple with semicolons)">
                    </div>
                </div>
                <input type="hidden" id="emailSubject">
                <div class="form-group">
                    <label class="form-label">Message</label>
                    <textarea id="emailBody"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Attachments</label>
                    <div class="attachment-dropzone" id="attachmentDropzone">
                        <input type="file" id="attachmentInput" multiple style="display: none;">
                        <div class="dropzone-content">
                            <span class="dropzone-icon">📎</span>
                            <span>Drag files here or <a href="#" onclick="document.getElementById('attachmentInput').click(); return false;">browse</a></span>
                        </div>
                    </div>
                    <div class="attachment-list" id="attachmentList"></div>
                </div>
            </div>
            <div id="replyCleanupUndoBar" style="display:none; padding: 8px 0; color: #555; font-size: 13px;">
                ✨ Cleaned up — <a href="#" id="replyCleanupUndoLink" style="color: #0078d4;">Undo</a> <span id="replyCleanupUndoTimer" style="color: #999;"></span>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeEmailModal()">Cancel</button>
                <button class="btn btn-cleanup" id="replyCleanupBtn" onclick="cleanupReplyDraft()" style="display:none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg>
                    Cleanup
                </button>
                <button class="btn btn-primary" onclick="sendEmail()" id="replySendBtn">Send</button>
            </div>
        </div>
    </div>

    <!-- New Ticket Modal -->
    <div class="modal" id="newTicketModal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">Create New Ticket</div>
            <div class="modal-body">
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label class="form-label">Requester Name *</label>
                        <input type="text" class="form-input" id="newTicketFromName" placeholder="e.g., John Smith" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Requester Email *</label>
                        <input type="email" class="form-input" id="newTicketFromEmail" placeholder="e.g., john.smith@company.com" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Subject *</label>
                    <input type="text" class="form-input" id="newTicketSubject" placeholder="Brief description of the issue" required>
                </div>
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select class="form-select" id="newTicketDepartment">
                            <option value="">-- Select --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select class="form-select" id="newTicketType">
                            <option value="">-- Select --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select class="form-select" id="newTicketPriority">
                            <option value="Normal">Normal</option>
                            <option value="Low">Low</option>
                            <option value="High">High</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-textarea" id="newTicketBody" rows="8" placeholder="Detailed description of the issue..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeNewTicketModal()">Cancel</button>
                <button class="btn btn-primary" onclick="createNewTicket()">Create Ticket</button>
            </div>
        </div>
    </div>

    <!-- Search Modal (Draggable) -->
    <div class="search-modal" id="searchModal">
        <div class="search-modal-header" id="searchModalHeader">
            <span>Search Tickets</span>
            <button class="search-modal-close" onclick="closeSearchModal()">&times;</button>
        </div>
        <div class="search-modal-body">
            <div class="search-form">
                <div class="search-field">
                    <label>Ticket Number</label>
                    <input type="text" id="searchTicketNumber" placeholder="e.g., TDB-914-96769">
                </div>
                <div class="search-field">
                    <label>Email Address</label>
                    <input type="text" id="searchEmail" placeholder="e.g., user@example.com">
                </div>
                <div class="search-field">
                    <label>Subject</label>
                    <input type="text" id="searchSubject" placeholder="Search in subject...">
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

    <!-- Schedule Modal -->
    <div class="modal" id="scheduleModal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">Schedule Work</div>
            <div class="modal-body">
                <p class="schedule-ticket-info" id="scheduleTicketInfo"></p>
                <div class="form-group">
                    <label class="form-label">Date *</label>
                    <input type="date" class="form-input" id="scheduleDate" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Start Time *</label>
                    <input type="time" class="form-input" id="scheduleTime" required>
                </div>
                <div class="schedule-current" id="scheduleCurrent" style="display: none;">
                    <p>Currently scheduled: <span id="currentSchedule"></span></p>
                    <button class="btn btn-link" onclick="clearSchedule()">Clear schedule</button>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeScheduleModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveSchedule()">Save</button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>
    <script>window.API_BASE = '../api/tickets/';</script>
    <script src="../assets/js/inbox.js?v=17"></script>
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
            <div class="ai-chat-title">Ask AI</div>
            <button class="ai-chat-close" onclick="closeTicketAiChat()">&times;</button>
        </div>
        <div class="ai-chat-messages" id="ticketAiMessages">
            <div class="ai-chat-welcome">
                Ask a question about this ticket and the AI will search the knowledge base for relevant articles.
            </div>
        </div>
        <div class="ai-chat-input-area">
            <textarea id="ticketAiInput" placeholder="Ask a question..." rows="2" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();askTicketAi();}"></textarea>
            <button class="ai-chat-send" id="ticketAiSendBtn" onclick="askTicketAi()">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </button>
        </div>
    </div>
</body>
</html>
