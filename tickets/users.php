<?php
/**
 * Users - View all users and their tickets
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
I18n::initFromSession();

requireModuleAccess('tickets');

$current_page = 'users';

// Namespaces the inline JS needs for translated strings (count / labels / table headers etc.)
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.users.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=37">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        .users-container {
            display: flex;
            flex: 1;
            overflow: hidden;
            gap: 1px;
            background-color: var(--border, #e0e0e0);
        }

        .users-list-container {
            width: 400px;
            min-width: 300px;
            background-color: var(--surface, #fff);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .users-list-header {
            padding: 15px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background-color: var(--surface-3, #f8f9fa);
        }

        .users-list-header h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: var(--text, #333);
        }

        .search-box {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .search-box:focus {
            outline: none;
            border-color: var(--accent, #0078d4);
            box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.1);
        }

        .users-list {
            flex: 1;
            overflow-y: auto;
        }

        .user-item {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-soft, #eee);
            cursor: pointer;
            transition: background-color 0.15s;
        }

        .user-item:hover {
            background-color: var(--surface-2, #f5f5f5);
        }

        .user-item.selected {
            background-color: var(--accent-soft, #e8f4fc);
            border-left: 3px solid var(--accent, #0078d4);
        }

        .user-name {
            font-weight: 600;
            color: var(--text, #333);
            margin-bottom: 4px;
        }

        .user-email {
            font-size: 13px;
            color: var(--text-muted, #666);
            margin-bottom: 4px;
        }

        .user-meta {
            font-size: 12px;
            color: var(--text-dim, #888);
            display: flex;
            gap: 15px;
        }

        .user-detail-container {
            flex: 1;
            background-color: var(--surface, #fff);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .user-detail-header {
            padding: 20px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background-color: var(--surface-3, #f8f9fa);
        }

        .user-detail-name {
            font-size: 20px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 0 0 5px 0;
        }

        .user-detail-email {
            font-size: 14px;
            color: var(--text-muted, #666);
        }

        .user-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding: 20px;
            border-bottom: 1px solid var(--border, #e0e0e0);
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 12px;
            color: var(--text-dim, #888);
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .info-value {
            font-size: 14px;
            color: var(--text, #333);
        }

        .tickets-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .tickets-header {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background-color: var(--surface-3, #f8f9fa);
            font-weight: 600;
            color: var(--text, #333);
        }

        .tickets-list {
            flex: 1;
            overflow-y: auto;
        }

        .ticket-row {
            display: grid;
            grid-template-columns: 130px 1fr 150px 80px 130px;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-soft, #eee);
            cursor: pointer;
            transition: background-color 0.15s;
            align-items: center;
        }

        .ticket-row:hover {
            background-color: var(--surface-2, #f5f5f5);
        }

        .ticket-row-header {
            font-weight: 600;
            background-color: var(--surface-hover, #f0f0f0);
            font-size: 12px;
            color: var(--text-muted, #666);
            text-transform: uppercase;
        }

        .ticket-row-header:hover {
            background-color: var(--surface-hover, #f0f0f0);
            cursor: default;
        }

        .ticket-number {
            color: var(--accent, #0078d4);
            font-weight: 500;
            white-space: nowrap;
        }

        .ticket-subject {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            padding-right: 15px;
        }

        .ticket-status {
            display: inline-block;
            width: 138px;          /* uniform width for every status badge */
            box-sizing: border-box;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            text-align: center;
            white-space: nowrap;   /* keep "Awaiting Response" on one line */
        }

        .ticket-priority {
            padding-left: 10px;
        }

        .ticket-priority {
            font-size: 13px;
        }

        .ticket-date {
            font-size: 13px;
            color: var(--text-muted, #666);
        }

        .empty-state {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 1;
            color: var(--text-dim, #888);
            font-size: 14px;
            padding: 40px;
            text-align: center;
        }

        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }

        .spinner {
            width: 30px;
            height: 30px;
            border: 3px solid var(--border, #f3f3f3);
            border-top: 3px solid var(--accent, #0078d4);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .user-count {
            font-size: 12px;
            color: var(--text-dim, #888);
            margin-top: 8px;
        }

        /* Compact form modal — overrides the default 900px / no-padding modal-content from inbox.css */
        #userModal .modal-content {
            padding: 20px;
            max-width: 500px;
        }

        /* Title sits flush with the modal-content padding rather than gaining its own 20px 24px on top */
        #userModal .modal-header {
            padding: 0;
            margin-bottom: 20px;
            border-bottom: none;
        }
        /* Form fields follow the palette. */
    input, select, textarea { background: var(--surface, #fff); color: var(--text, #333); }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container users-container">
        <!-- Users List -->
        <div class="users-list-container">
            <div class="users-list-header">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h3 style="margin: 0;"><?php echo htmlspecialchars(t('tickets.users.list_title')); ?></h3>
                    <button class="add-btn" onclick="openUserModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
                </div>
                <input type="text" class="search-box" id="userSearch" placeholder="<?php echo htmlspecialchars(t('tickets.users.search_placeholder')); ?>" oninput="searchUsers()">
                <div class="user-count" id="userCount"></div>
            </div>
            <div class="users-list" id="usersList">
                <div class="loading">
                    <div class="spinner"></div>
                </div>
            </div>
        </div>

        <!-- User Detail -->
        <div class="user-detail-container" id="userDetail">
            <div class="empty-state">
                <?php echo htmlspecialchars(t('tickets.users.select_user')); ?>
            </div>
        </div>
    </div>

    <!-- User Modal -->
    <div class="modal" id="userModal">
        <div class="modal-content">
            <div class="modal-header" id="userModalTitle"><?php echo htmlspecialchars(t('tickets.users.modal.add_title')); ?></div>
            <form id="userForm" autocomplete="off">
                <input type="hidden" id="userId">

                <div class="form-group">
                    <label for="userEmail"><?php echo htmlspecialchars(t('tickets.users.modal.email')); ?> *</label>
                    <input type="email" id="userEmail" required autocomplete="off" placeholder="<?php echo htmlspecialchars(t('tickets.users.modal.email_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="userDisplayName"><?php echo htmlspecialchars(t('tickets.users.modal.display_name')); ?></label>
                    <input type="text" id="userDisplayName" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('tickets.users.modal.display_name_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="userPreferredName"><?php echo htmlspecialchars(t('tickets.users.modal.preferred_name')); ?></label>
                    <input type="text" id="userPreferredName" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('tickets.users.modal.preferred_name_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="userPassword"><?php echo htmlspecialchars(t('tickets.users.modal.password')); ?></label>
                    <input type="password" id="userPassword" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(t('tickets.users.modal.password_placeholder')); ?>" minlength="8">
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.users.modal.password_help')); ?></small>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeUserModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../api/tickets/';
        let users = [];
        let selectedUserId = null;
        let searchTimeout = null;

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadUsers();
        });

        // Load users from API
        async function loadUsers(search = '') {
            try {
                const url = search ? `${API_BASE}get_users.php?search=${encodeURIComponent(search)}` : API_BASE + 'get_users.php';
                const response = await fetch(url);
                const data = await response.json();

                if (data.success) {
                    users = data.users;
                    renderUsersList();
                } else {
                    console.error('Error loading users:', data.error);
                }
            } catch (error) {
                console.error('Error loading users:', error);
            }
        }

        // Render users list
        function renderUsersList() {
            const container = document.getElementById('usersList');
            const countEl = document.getElementById('userCount');

            if (users.length === 0) {
                container.innerHTML = `<div class="empty-state">${escapeHtml(t('tickets.users.no_users'))}</div>`;
                countEl.textContent = t('tickets.users.count', { count: 0 });
                return;
            }

            countEl.textContent = t('tickets.users.count', { count: users.length });
            const unknownName = t('tickets.users.unknown_name');

            container.innerHTML = users.map(user => `
                <div class="user-item ${selectedUserId == user.id ? 'selected' : ''}" onclick="selectUser(${user.id})">
                    <div class="user-name">${escapeHtml(user.display_name || unknownName)}</div>
                    <div class="user-email">${escapeHtml(user.email || '')}</div>
                    <div class="user-meta">
                        <span>${escapeHtml(t('tickets.users.ticket_count', { count: user.ticket_count }))}</span>
                    </div>
                </div>
            `).join('');
        }

        // Search users with debounce
        function searchUsers() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const search = document.getElementById('userSearch').value;
                loadUsers(search);
            }, 300);
        }

        // Select a user and show their details
        async function selectUser(userId) {
            selectedUserId = userId;
            renderUsersList();

            const user = users.find(u => u.id == userId);
            if (!user) return;

            const unknownName = t('tickets.users.unknown_name');
            const detailContainer = document.getElementById('userDetail');
            detailContainer.innerHTML = `
                <div class="user-detail-header">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;">
                        <div>
                            <h2 class="user-detail-name">${escapeHtml(user.display_name || unknownName)}</h2>
                            <div class="user-detail-email">${escapeHtml(user.email || '')}</div>
                        </div>
                        <div style="display: flex; gap: 8px; flex-shrink: 0;">
                            <button class="btn btn-secondary" onclick="openUserModal(${user.id})">${escapeHtml(t('common.edit'))}</button>
                            <button class="btn btn-secondary" onclick="deleteUser(${user.id})">${escapeHtml(t('common.delete'))}</button>
                        </div>
                    </div>
                </div>
                <div class="user-info-grid">
                    <div class="info-item">
                        <span class="info-label">${escapeHtml(t('tickets.users.info.email'))}</span>
                        <span class="info-value">${escapeHtml(user.email || '-')}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">${escapeHtml(t('tickets.users.info.first_seen'))}</span>
                        <span class="info-value">${formatDate(user.created_at)}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">${escapeHtml(t('tickets.users.info.total_tickets'))}</span>
                        <span class="info-value">${user.ticket_count}</span>
                    </div>
                </div>
                <div class="tickets-section">
                    <div class="tickets-header">${escapeHtml(t('tickets.users.tickets_section', { count: user.ticket_count }))}</div>
                    <div class="tickets-list" id="ticketsList">
                        <div class="loading"><div class="spinner"></div></div>
                    </div>
                </div>
            `;

            // Load user's tickets
            loadUserTickets(userId);
        }

        // Load tickets for selected user
        async function loadUserTickets(userId) {
            try {
                const response = await fetch(`${API_BASE}get_user_tickets.php?user_id=${userId}`);
                const data = await response.json();

                const container = document.getElementById('ticketsList');

                if (data.success) {
                    if (data.tickets.length === 0) {
                        container.innerHTML = `<div class="empty-state">${escapeHtml(t('tickets.users.no_tickets'))}</div>`;
                        return;
                    }

                    const statusFallback = t('tickets.users.status_new_fallback');
                    container.innerHTML = `
                        <div class="ticket-row ticket-row-header">
                            <span>${escapeHtml(t('tickets.users.table.ticket_number'))}</span>
                            <span>${escapeHtml(t('tickets.users.table.subject'))}</span>
                            <span>${escapeHtml(t('tickets.users.table.status'))}</span>
                            <span>${escapeHtml(t('tickets.users.table.priority'))}</span>
                            <span>${escapeHtml(t('tickets.users.table.created'))}</span>
                        </div>
                        ${data.tickets.map(ticket => {
                            const c = ticket.status_colour || '#0078d4';
                            const statusStyle = `background-color: ${c}1f; color: ${c}; border: 1px solid ${c}33;`;
                            return `
                            <div class="ticket-row" onclick="viewTicket(${ticket.id})">
                                <span class="ticket-number">${escapeHtml(ticket.ticket_number)}</span>
                                <span class="ticket-subject">${escapeHtml(ticket.subject)}</span>
                                <span class="ticket-status" style="${statusStyle}">${escapeHtml(ticket.status || statusFallback)}</span>
                                <span class="ticket-priority">${escapeHtml(ticket.priority || '-')}</span>
                                <span class="ticket-date">${formatDate(ticket.created_datetime)}</span>
                            </div>
                        `;}).join('')}
                    `;
                } else {
                    container.innerHTML = `<div class="empty-state">${escapeHtml(t('tickets.users.error_loading_tickets'))}</div>`;
                }
            } catch (error) {
                console.error('Error loading tickets:', error);
                document.getElementById('ticketsList').innerHTML = `<div class="empty-state">${escapeHtml(t('tickets.users.error_loading_tickets'))}</div>`;
            }
        }

        // View ticket in inbox
        function viewTicket(ticketId) {
            // Navigate to inbox with ticket selected
            window.location.href = `index.php?ticket_id=${ticketId}`;
        }

        // Escape HTML for safe display
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Format date for display. Locale sourced from <html lang> so the date
        // matches the user's chosen interface language.
        const PAGE_LOCALE = document.documentElement.lang || 'en-GB';
        function formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString(PAGE_LOCALE, {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        }

        // Open the create/edit modal. Pass an id to edit; omit to create.
        function openUserModal(userId) {
            const modal = document.getElementById('userModal');
            const title = document.getElementById('userModalTitle');
            const idField = document.getElementById('userId');
            const emailField = document.getElementById('userEmail');
            const displayField = document.getElementById('userDisplayName');
            const preferredField = document.getElementById('userPreferredName');
            const passwordField = document.getElementById('userPassword');

            if (userId) {
                const user = users.find(u => u.id == userId);
                title.textContent = t('tickets.users.modal.edit_title');
                idField.value = userId;
                emailField.value = user?.email || '';
                displayField.value = user?.display_name || '';
                preferredField.value = user?.preferred_name || '';
            } else {
                title.textContent = t('tickets.users.modal.add_title');
                idField.value = '';
                emailField.value = '';
                displayField.value = '';
                preferredField.value = '';
            }
            passwordField.value = '';
            modal.classList.add('active');
            emailField.focus();
        }

        function closeUserModal() {
            document.getElementById('userModal').classList.remove('active');
        }

        document.getElementById('userForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const id = document.getElementById('userId').value;
            const payload = {
                id: id || null,
                email: document.getElementById('userEmail').value.trim(),
                display_name: document.getElementById('userDisplayName').value.trim(),
                preferred_name: document.getElementById('userPreferredName').value.trim(),
                password: document.getElementById('userPassword').value
            };

            try {
                const response = await fetch(`${API_BASE}save_user.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!data.success) {
                    showToast(data.error || 'Save failed', 'error');
                    return;
                }
                const savedId = data.id;
                closeUserModal();
                await loadUsers(document.getElementById('userSearch').value);
                if (savedId) selectUser(savedId);
            } catch (err) {
                showToast('Save failed: ' + err.message, 'error');
            }
        });

        async function deleteUser(userId) {
            const user = users.find(u => u.id == userId);
            const label = user?.display_name || user?.email || `#${userId}`;
            if (!(await showConfirm({ title: 'Confirm', message: t('tickets.users.modal.confirm_delete', { name: label }), okLabel: 'OK', okClass: 'primary' }))) return;

            try {
                const response = await fetch(`${API_BASE}delete_user.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: userId })
                });
                const data = await response.json();
                if (!data.success) {
                    showToast(data.error || 'Delete failed', 'error');
                    return;
                }
                selectedUserId = null;
                document.getElementById('userDetail').innerHTML = `<div class="empty-state">${escapeHtml(t('tickets.users.select_user'))}</div>`;
                await loadUsers(document.getElementById('userSearch').value);
            } catch (err) {
                showToast('Delete failed: ' + err.message, 'error');
            }
        }
    </script>
</body>
</html>
