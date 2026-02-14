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
    <link rel="stylesheet" href="../assets/css/inbox.css?v=3">
    <script src="../assets/js/tinymce/tinymce.min.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container">
        <!-- Folder Navigation -->
        <div class="folder-container">
            <div class="folder-header">
                <h2>Folders</h2>
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
                    <button class="new-btn" onclick="openNewTicketModal()">+ New</button>
                    <button class="search-btn" onclick="openSearchModal()">Search</button>
                    <button class="refresh-btn" onclick="refreshCurrentView()">Refresh</button>
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
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeEmailModal()">Cancel</button>
                <button class="btn btn-primary" onclick="sendEmail()">Send</button>
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
    <script src="../assets/js/inbox.js?v=7"></script>
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
</body>
</html>
