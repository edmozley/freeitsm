<?php
/**
 * Admin Settings - Manage Departments, Ticket Types, and Exchange Integration
 */
session_start();
require_once '../../config.php';

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';

// Check for OAuth success message
$oauthSuccess = isset($_GET['oauth']) && $_GET['oauth'] === 'success';
$oauthMailboxId = $_GET['mailbox_id'] ?? null;

$current_page = 'settings';
$path_prefix = '../../';  // Two levels up from tickets/settings/
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Settings</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script src="../../assets/js/toast.js"></script>
    <style>
        /* Page-specific overrides for settings page */
        body {
            overflow: auto;
            height: auto;
        }

        /* Settings page uses .action-btn for table buttons */
        .tab-content .action-btn {
            background: none;
            border: 1px solid #ddd;
            color: #666;
            cursor: pointer;
            padding: 6px;
            margin-right: 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .tab-content .action-btn:hover {
            background: #f0f0f0;
            border-color: #0078d4;
            color: #0078d4;
        }

        .tab-content .action-btn.delete {
            color: #d13438;
        }

        .tab-content .action-btn.delete:hover {
            background: #fdf3f3;
            border-color: #d13438;
            color: #a00;
        }

        .tab-content .action-btn svg {
            width: 16px;
            height: 16px;
        }

        /* Exchange status boxes */
        .exchange-status {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .exchange-status.authenticated {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .exchange-status.not-authenticated {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }

        .exchange-status .status-icon {
            font-size: 24px;
        }

        /* Exchange result messages */
        .exchange-result {
            padding: 20px;
            border-radius: 8px;
            display: none;
        }

        .exchange-result.success {
            display: block;
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .exchange-result.error {
            display: block;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .exchange-result.info {
            display: block;
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        .exchange-result pre {
            background: rgba(0, 0, 0, 0.05);
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
            margin-top: 10px;
            font-size: 12px;
        }

        /* Modal content override for settings modals */
        .modal-content {
            padding: 30px;
            max-width: 500px;
        }

        .modal-header {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #333;
            padding: 0;
            border-bottom: none;
        }
        /* Toggle switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
            vertical-align: middle;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #ccc;
            border-radius: 24px;
            transition: background 0.2s;
        }
        .toggle-slider::before {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            left: 3px;
            bottom: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform 0.2s;
        }
        .toggle-switch input:checked + .toggle-slider {
            background: #0078d4;
        }
        .toggle-switch input:checked + .toggle-slider::before {
            transform: translateX(20px);
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="departments" onclick="switchTab('departments')">Departments</button>
            <button class="tab" data-tab="teams" onclick="switchTab('teams')">Teams</button>
            <button class="tab" data-tab="ticket-types" onclick="switchTab('ticket-types')">Ticket Types</button>
            <button class="tab" data-tab="ticket-origins" onclick="switchTab('ticket-origins')">Ticket Origins</button>
            <button class="tab" data-tab="mailboxes" onclick="switchTab('mailboxes')">Mailboxes</button>
            <button class="tab" data-tab="analysts" onclick="switchTab('analysts')">Analysts</button>
            <button class="tab" data-tab="general" onclick="switchTab('general')">General</button>
        </div>

        <!-- Departments Tab -->
        <div class="tab-content active" id="departments-tab">
            <div class="section-header">
                <h2>Departments</h2>
                <button class="add-btn" onclick="openAddModal('department')">Add</button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Teams</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="departments-list">
                    <tr><td colspan="6" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Teams Tab -->
        <div class="tab-content" id="teams-tab">
            <div class="section-header">
                <h2>Teams</h2>
                <button class="add-btn" onclick="openAddModal('team')">Add</button>
            </div>
            <p style="margin-bottom: 20px; color: #666;">Teams determine which departments analysts can access. Assign departments to teams, then assign analysts to teams to control their access.</p>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Departments</th>
                        <th>Analysts</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="teams-list">
                    <tr><td colspan="7" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Ticket Types Tab -->
        <div class="tab-content" id="ticket-types-tab">
            <div class="section-header">
                <h2>Ticket Types</h2>
                <button class="add-btn" onclick="openAddModal('ticket-type')">Add</button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="ticket-types-list">
                    <tr><td colspan="5" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Ticket Origins Tab -->
        <div class="tab-content" id="ticket-origins-tab">
            <div class="section-header">
                <h2>Ticket Origins</h2>
                <button class="add-btn" onclick="openAddModal('ticket-origin')">Add</button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="ticket-origins-list">
                    <tr><td colspan="5" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Mailboxes Tab -->
        <div class="tab-content" id="mailboxes-tab">
            <div class="section-header">
                <h2>Mailboxes</h2>
                <div>
                    <button class="btn btn-primary" onclick="checkAllMailboxes()" style="margin-right: 10px;">Check All</button>
                    <button class="add-btn" onclick="openMailboxModal()">Add</button>
                </div>
            </div>

            <?php if ($oauthSuccess && $oauthMailboxId): ?>
            <div class="exchange-status authenticated" id="oauth-success-msg">
                <span class="status-icon">&#10003;</span>
                <div>
                    <strong>Authentication Successful!</strong><br>
                    Mailbox is now connected and ready to check for emails.
                </div>
            </div>
            <?php endif; ?>

            <div id="mailboxesResult" class="exchange-result"></div>

            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Mailbox</th>
                        <th>Status</th>
                        <th>Last Checked</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="mailboxes-list">
                    <tr><td colspan="5" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Analysts Tab -->
        <div class="tab-content" id="analysts-tab">
            <div class="section-header">
                <h2>Analysts</h2>
                <button class="add-btn" onclick="openAnalystModal()">Add</button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Teams</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="analysts-list">
                    <tr><td colspan="7" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- General Tab -->
        <div class="tab-content" id="general-tab">
            <div class="section-header">
                <h2>General Settings</h2>
            </div>
            <form id="generalSettingsForm" style="max-width: 600px;">
                <div class="form-group">
                    <label for="systemName">System Name</label>
                    <input type="text" id="systemName" placeholder="e.g., Service Desk Ticketing System">
                    <small style="color: #666;">This name appears in the header and page titles.</small>
                </div>

                <div class="form-group">
                    <label for="systemTimezone">Timezone</label>
                    <select id="systemTimezone" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        <option value="">Loading...</option>
                    </select>
                    <small style="color: #666;">Used for displaying dates and times throughout the system.</small>
                </div>

                <div class="modal-actions" style="justify-content: flex-start; margin-top: 30px;">
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>

        </div>
    </div>

    <!-- Modal for Add/Edit -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle">Add Item</div>
            <form id="editForm">
                <input type="hidden" id="itemId">
                <input type="hidden" id="itemType">

                <div class="form-group">
                    <label for="itemName">Name</label>
                    <input type="text" id="itemName" required>
                </div>

                <div class="form-group">
                    <label for="itemDescription">Description</label>
                    <textarea id="itemDescription"></textarea>
                </div>

                <div class="form-group">
                    <label for="itemOrder">Display Order</label>
                    <input type="number" id="itemOrder" value="0">
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="itemActive" checked> Active
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mailbox Modal -->
    <div class="modal" id="mailboxModal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header" id="mailboxModalTitle">Add Mailbox</div>
            <form id="mailboxForm" autocomplete="off" style="overflow-y: auto; flex: 1; padding: 20px 24px;">
                <input type="hidden" id="mailboxId">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label for="mailboxName">Display Name *</label>
                        <input type="text" id="mailboxName" required placeholder="e.g., Service Desk">
                    </div>

                    <div class="form-group">
                        <label for="mailboxEmail">Target Mailbox *</label>
                        <input type="email" id="mailboxEmail" required placeholder="e.g., servicedesk@company.com">
                    </div>

                    <div class="form-group">
                        <label for="mailboxTenantId">Azure Tenant ID *</label>
                        <input type="text" id="mailboxTenantId" required placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    </div>

                    <div class="form-group">
                        <label for="mailboxClientId">Azure Client ID *</label>
                        <input type="text" id="mailboxClientId" required placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label for="mailboxClientSecret">Azure Client Secret *</label>
                        <input type="password" id="mailboxClientSecret" placeholder="Leave blank to keep existing (when editing)">
                        <small style="color: #666;">Required for new mailboxes. Leave blank when editing to keep existing secret.</small>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label for="mailboxRedirectUri">OAuth Redirect URI *</label>
                        <input type="url" id="mailboxRedirectUri" required placeholder="https://yoursite.com/oauth_callback.php">
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label for="mailboxScopes">OAuth Scopes</label>
                        <input type="text" id="mailboxScopes" value="openid email offline_access Mail.Read Mail.ReadWrite Mail.Send">
                    </div>

                    <div class="form-group">
                        <label for="mailboxImapServer">IMAP Server</label>
                        <input type="text" id="mailboxImapServer" value="outlook.office365.com">
                    </div>

                    <div class="form-group">
                        <label for="mailboxImapPort">IMAP Port</label>
                        <input type="number" id="mailboxImapPort" value="993">
                    </div>

                    <div class="form-group">
                        <label for="mailboxFolder">Email Folder</label>
                        <input type="text" id="mailboxFolder" value="INBOX">
                    </div>

                    <div class="form-group">
                        <label for="mailboxMaxEmails">Max Emails per Check</label>
                        <input type="number" id="mailboxMaxEmails" value="10" min="1" max="50">
                    </div>

                    <div class="form-group">
                        <label for="mailboxRejectedAction">Rejected Emails</label>
                        <select id="mailboxRejectedAction">
                            <option value="delete">Delete permanently</option>
                            <option value="move_to_deleted">Move to Deleted Items</option>
                            <option value="mark_read">Mark as read</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="mailboxImportedAction">Imported Emails</label>
                        <select id="mailboxImportedAction" onchange="toggleImportedFolder()">
                            <option value="delete">Delete permanently</option>
                            <option value="move_to_folder">Move to folder</option>
                        </select>
                    </div>

                    <div class="form-group" id="importedFolderGroup" style="display: none; grid-column: span 2;">
                        <label for="mailboxImportedFolder">Move to Folder</label>
                        <input type="text" id="mailboxImportedFolder" placeholder="e.g., Processed">
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            Active
                            <label class="toggle-switch" style="margin: 0;">
                                <input type="checkbox" id="mailboxActive" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </label>
                    </div>
                </div>

                <div style="grid-column: span 2; margin-top: 10px; border-top: 1px solid #e0e0e0; padding-top: 15px;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;">Email Whitelist</label>
                    <small style="color: #666; display: block; margin-bottom: 10px;">If empty, all senders are allowed. Add domains or email addresses to restrict which emails are imported.</small>

                    <div style="display: flex; gap: 8px; margin-bottom: 10px;">
                        <select id="whitelistType" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;">
                            <option value="domain">Domain</option>
                            <option value="email">Email</option>
                        </select>
                        <input type="text" id="whitelistValue" placeholder="e.g. company.com or user@example.com" style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;" onkeydown="if(event.key==='Enter'){event.preventDefault();addWhitelistEntry();}">
                        <button type="button" class="btn btn-primary" onclick="addWhitelistEntry()" style="padding: 8px 12px;">Add</button>
                    </div>

                    <div id="whitelistEntries" style="display: flex; flex-wrap: wrap; gap: 6px;"></div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeMailboxModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Activity Log Modal -->
    <div class="modal" id="activityModal">
        <div class="modal-content" style="max-width: 850px;">
            <div class="modal-header" id="activityModalTitle">Mailbox Activity</div>

            <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                <input type="text" id="activitySearch" placeholder="Search by sender, name, or subject..." style="flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;" oninput="debounceActivitySearch()">
            </div>

            <div style="max-height: 450px; overflow-y: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>From</th>
                            <th>Subject</th>
                            <th>Action</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody id="activityList">
                        <tr><td colspan="5" style="text-align: center;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>

            <div id="activityPagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px; font-size: 13px; color: #666;"></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeActivityModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Analyst Modal -->
    <div class="modal" id="analystModal">
        <div class="modal-content">
            <div class="modal-header" id="analystModalTitle">Add Analyst</div>
            <form id="analystForm">
                <input type="hidden" id="analystId">

                <div class="form-group">
                    <label for="analystUsername">Username *</label>
                    <input type="text" id="analystUsername" required placeholder="e.g., jsmith">
                </div>

                <div class="form-group">
                    <label for="analystFullName">Full Name *</label>
                    <input type="text" id="analystFullName" required placeholder="e.g., John Smith">
                </div>

                <div class="form-group">
                    <label for="analystEmail">Email</label>
                    <input type="email" id="analystEmail" placeholder="e.g., jsmith@company.com">
                </div>

                <div class="form-group" id="analystPasswordGroup">
                    <label for="analystPassword">Password *</label>
                    <input type="password" id="analystPassword" placeholder="Enter password">
                    <small style="color: #666;">Required for new analysts.</small>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="analystActive" checked> Active
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAnalystModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Password Reset Modal -->
    <div class="modal" id="passwordResetModal">
        <div class="modal-content">
            <div class="modal-header">Reset Password</div>
            <form id="passwordResetForm">
                <input type="hidden" id="resetAnalystId">

                <p style="margin-bottom: 20px;">Resetting password for: <strong id="resetAnalystName"></strong></p>

                <div class="form-group">
                    <label for="newPassword">New Password *</label>
                    <input type="password" id="newPassword" required minlength="6" placeholder="Enter new password">
                </div>

                <div class="form-group">
                    <label for="confirmPassword">Confirm Password *</label>
                    <input type="password" id="confirmPassword" required minlength="6" placeholder="Confirm new password">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closePasswordResetModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Reset Password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Team Assignment Modal -->
    <div class="modal" id="teamAssignmentModal">
        <div class="modal-content">
            <div class="modal-header" id="teamAssignmentTitle">Assign Teams</div>
            <form id="teamAssignmentForm">
                <input type="hidden" id="assignmentEntityType">
                <input type="hidden" id="assignmentEntityId">

                <p style="margin-bottom: 15px; color: #666;" id="teamAssignmentDesc">Select the teams to assign:</p>

                <div id="teamAssignmentList" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
                    <div style="padding: 15px; text-align: center; color: #999;">Loading teams...</div>
                </div>

                <div class="modal-actions" style="margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeTeamAssignmentModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/tickets/';
        const API_SETTINGS = '../../api/settings/';
        let currentTab = 'departments';

        let mailboxes = [];
        let whitelistEntries = [];
        let teams = [];
        let departmentTeams = {}; // Cache for department->teams mapping
        let analystTeams = {}; // Cache for analyst->teams mapping

        // Load data on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadTeams().then(() => {
                loadDepartments();
                loadAnalysts();
            });
            loadTicketTypes();
            loadTicketOrigins();
            loadMailboxes();

            // Auto-switch to mailboxes tab if OAuth success
            <?php if ($oauthSuccess && $oauthMailboxId): ?>
            switchTab('mailboxes');
            <?php endif; ?>
        });

        // Switch tabs
        function switchTab(tab) {
            currentTab = tab;

            // Update tab buttons
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');

            // Update tab content
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        // Load departments
        async function loadDepartments() {
            try {
                const response = await fetch(API_BASE + 'get_departments.php');
                const data = await response.json();

                if (data.success) {
                    renderDepartments(data.departments);
                } else {
                    alert('Error loading departments: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Load ticket types
        async function loadTicketTypes() {
            try {
                const response = await fetch(API_BASE + 'get_ticket_types.php');
                const data = await response.json();

                if (data.success) {
                    renderTicketTypes(data.ticket_types);
                } else {
                    alert('Error loading ticket types: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Load ticket origins
        async function loadTicketOrigins() {
            try {
                const response = await fetch(API_BASE + 'get_ticket_origins.php');
                const data = await response.json();

                if (data.success) {
                    renderTicketOrigins(data.origins);
                } else {
                    alert('Error loading ticket origins: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Load teams
        async function loadTeams() {
            try {
                const response = await fetch(API_BASE + 'get_teams.php');
                const data = await response.json();

                if (data.success) {
                    teams = data.teams;
                    renderTeams(teams);
                    return teams;
                } else {
                    console.error('Error loading teams:', data.error);
                    document.getElementById('teams-list').innerHTML =
                        '<tr><td colspan="7" style="text-align: center; color: red;">Error: ' + data.error + '</td></tr>';
                    return [];
                }
            } catch (error) {
                console.error('Error loading teams:', error);
                document.getElementById('teams-list').innerHTML =
                    '<tr><td colspan="7" style="text-align: center; color: red;">Failed to load teams.</td></tr>';
                return [];
            }
        }

        // Render departments
        async function renderDepartments(departments) {
            const tbody = document.getElementById('departments-list');

            if (departments.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No departments found</td></tr>';
                return;
            }

            // Load team assignments for all departments
            for (const dept of departments) {
                if (!departmentTeams[dept.id]) {
                    try {
                        const response = await fetch(`${API_BASE}get_department_teams.php?department_id=${dept.id}`);
                        const data = await response.json();
                        departmentTeams[dept.id] = data.success ? data.teams : [];
                    } catch (e) {
                        departmentTeams[dept.id] = [];
                    }
                }
            }

            tbody.innerHTML = departments.map(dept => {
                const deptTeams = departmentTeams[dept.id] || [];
                const teamsText = deptTeams.length > 0
                    ? deptTeams.map(t => `<span class="status-badge" style="background: #e3f2fd; color: #1565c0; margin-right: 4px;">${escapeHtml(t.name)}</span>`).join('')
                    : '<span style="color: #999;">None</span>';

                return `
                <tr>
                    <td><strong>${escapeHtml(dept.name)}</strong></td>
                    <td>${escapeHtml(dept.description || '')}</td>
                    <td>${teamsText}</td>
                    <td>${dept.display_order}</td>
                    <td><span class="status-badge status-${dept.is_active ? 'active' : 'inactive'}">${dept.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('department', ${dept.id})" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn" onclick="openTeamAssignment('department', ${dept.id}, '${escapeHtml(dept.name).replace(/'/g, "\\'")}')" title="Assign Teams">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('department', ${dept.id}, '${escapeHtml(dept.name)}')" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `}).join('');
        }

        // Render ticket types
        function renderTicketTypes(types) {
            const tbody = document.getElementById('ticket-types-list');

            if (types.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No ticket types found</td></tr>';
                return;
            }

            tbody.innerHTML = types.map(type => `
                <tr>
                    <td><strong>${escapeHtml(type.name)}</strong></td>
                    <td>${escapeHtml(type.description || '')}</td>
                    <td>${type.display_order}</td>
                    <td><span class="status-badge status-${type.is_active ? 'active' : 'inactive'}">${type.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('ticket-type', ${type.id})" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('ticket-type', ${type.id}, '${escapeHtml(type.name)}')" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Render ticket origins
        function renderTicketOrigins(origins) {
            const tbody = document.getElementById('ticket-origins-list');

            if (origins.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No ticket origins found</td></tr>';
                return;
            }

            tbody.innerHTML = origins.map(origin => `
                <tr>
                    <td><strong>${escapeHtml(origin.name)}</strong></td>
                    <td>${escapeHtml(origin.description || '')}</td>
                    <td>${origin.display_order}</td>
                    <td><span class="status-badge status-${origin.is_active ? 'active' : 'inactive'}">${origin.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('ticket-origin', ${origin.id})" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('ticket-origin', ${origin.id}, '${escapeHtml(origin.name)}')" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Render teams
        async function renderTeams(teamsList) {
            const tbody = document.getElementById('teams-list');

            if (teamsList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No teams found. Click "Add" to create your first team.</td></tr>';
                return;
            }

            // For each team, we need to get department and analyst counts
            const teamsWithCounts = await Promise.all(teamsList.map(async (team) => {
                let deptCount = 0;
                let analystCount = 0;

                try {
                    // Get departments linked to this team
                    const deptResponse = await fetch(`${API_BASE}get_team_departments.php?team_id=${team.id}`);
                    const deptData = await deptResponse.json();
                    deptCount = deptData.success ? deptData.departments.length : 0;
                } catch (e) { }

                try {
                    // Get analysts linked to this team
                    const analystResponse = await fetch(`${API_BASE}get_team_analysts.php?team_id=${team.id}`);
                    const analystData = await analystResponse.json();
                    analystCount = analystData.success ? analystData.analysts.length : 0;
                } catch (e) { }

                return { ...team, deptCount, analystCount };
            }));

            tbody.innerHTML = teamsWithCounts.map(team => {
                const safeName = escapeHtml(team.name).replace(/'/g, "\\'");

                return `
                <tr>
                    <td><strong>${escapeHtml(team.name)}</strong></td>
                    <td>${escapeHtml(team.description || '')}</td>
                    <td>${team.deptCount} department(s)</td>
                    <td>${team.analystCount} analyst(s)</td>
                    <td>${team.display_order}</td>
                    <td><span class="status-badge status-${team.is_active ? 'active' : 'inactive'}">${team.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('team', ${team.id})" title="Edit">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('team', ${team.id}, '${safeName}')" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `}).join('');
        }

        // Open add modal
        function openAddModal(type) {
            const titles = {
                'department': 'Add Department',
                'ticket-type': 'Add Ticket Type',
                'ticket-origin': 'Add Ticket Origin',
                'team': 'Add Team'
            };
            document.getElementById('modalTitle').textContent = titles[type] || 'Add Item';
            document.getElementById('itemType').value = type;
            document.getElementById('itemId').value = '';
            document.getElementById('itemName').value = '';
            document.getElementById('itemDescription').value = '';
            document.getElementById('itemOrder').value = '0';
            document.getElementById('itemActive').checked = true;
            document.getElementById('editModal').classList.add('active');
        }

        // Edit item
        async function editItem(type, id) {
            const endpoints = {
                'department': API_BASE + 'get_departments.php',
                'ticket-type': API_BASE + 'get_ticket_types.php',
                'ticket-origin': API_BASE + 'get_ticket_origins.php',
                'team': API_BASE + 'get_teams.php'
            };
            const titles = {
                'department': 'Edit Department',
                'ticket-type': 'Edit Ticket Type',
                'ticket-origin': 'Edit Ticket Origin',
                'team': 'Edit Team'
            };
            const endpoint = endpoints[type];

            try {
                const response = await fetch(endpoint);
                const data = await response.json();

                if (data.success) {
                    let items;
                    if (type === 'department') items = data.departments;
                    else if (type === 'ticket-type') items = data.ticket_types;
                    else if (type === 'ticket-origin') items = data.origins;
                    else if (type === 'team') items = data.teams;

                    const item = items.find(i => i.id == id);

                    if (item) {
                        document.getElementById('modalTitle').textContent = titles[type] || 'Edit Item';
                        document.getElementById('itemType').value = type;
                        document.getElementById('itemId').value = item.id;
                        document.getElementById('itemName').value = item.name;
                        document.getElementById('itemDescription').value = item.description || '';
                        document.getElementById('itemOrder').value = item.display_order;
                        document.getElementById('itemActive').checked = item.is_active;
                        document.getElementById('editModal').classList.add('active');
                    }
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Delete item
        async function deleteItem(type, id, name) {
            if (!confirm(`Are you sure you want to delete "${name}"?`)) {
                return;
            }

            const endpoints = {
                'department': API_BASE + 'delete_department.php',
                'ticket-type': API_BASE + 'delete_ticket_type.php',
                'ticket-origin': API_BASE + 'delete_ticket_origin.php',
                'team': API_BASE + 'delete_team.php'
            };
            const endpoint = endpoints[type];

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();

                if (data.success) {
                    if (type === 'department') {
                        loadDepartments();
                    } else if (type === 'ticket-type') {
                        loadTicketTypes();
                    } else if (type === 'ticket-origin') {
                        loadTicketOrigins();
                    } else if (type === 'team') {
                        loadTeams().then(() => {
                            loadDepartments();
                            loadAnalysts();
                        });
                    }
                } else {
                    alert('Error deleting item: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to delete item');
            }
        }

        // Close modal
        function closeModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        // Open team assignment modal
        async function openTeamAssignment(entityType, entityId, entityName) {
            document.getElementById('assignmentEntityType').value = entityType;
            document.getElementById('assignmentEntityId').value = entityId;

            if (entityType === 'department') {
                document.getElementById('teamAssignmentTitle').textContent = `Assign Teams to "${entityName}"`;
                document.getElementById('teamAssignmentDesc').textContent = 'Select which teams should have access to this department:';
            } else if (entityType === 'analyst') {
                document.getElementById('teamAssignmentTitle').textContent = `Assign Teams to "${entityName}"`;
                document.getElementById('teamAssignmentDesc').textContent = 'Select which teams this analyst belongs to:';
            }

            const listContainer = document.getElementById('teamAssignmentList');
            listContainer.innerHTML = '<div style="padding: 15px; text-align: center; color: #999;">Loading teams...</div>';

            // Get current assignments
            let currentTeamIds = [];
            try {
                const endpoint = entityType === 'department'
                    ? `${API_BASE}get_department_teams.php?department_id=${entityId}`
                    : `${API_BASE}get_analyst_teams.php?analyst_id=${entityId}`;
                const response = await fetch(endpoint);
                const data = await response.json();
                if (data.success) {
                    currentTeamIds = data.teams.map(t => t.id);
                }
            } catch (e) {
                console.error('Error loading current assignments:', e);
            }

            // Render checkboxes for all active teams
            const activeTeams = teams.filter(t => t.is_active);
            if (activeTeams.length === 0) {
                listContainer.innerHTML = '<div style="padding: 15px; text-align: center; color: #999;">No active teams available. Create teams first.</div>';
            } else {
                listContainer.innerHTML = activeTeams.map(team => `
                    <label style="display: flex; align-items: center; padding: 12px 15px; border-bottom: 1px solid #eee; cursor: pointer; transition: background 0.2s;"
                           onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='white'">
                        <input type="checkbox" name="team_ids" value="${team.id}" ${currentTeamIds.includes(team.id) ? 'checked' : ''}
                               style="margin-right: 12px; width: 18px; height: 18px;">
                        <div>
                            <strong>${escapeHtml(team.name)}</strong>
                            ${team.description ? `<div style="font-size: 12px; color: #666; margin-top: 2px;">${escapeHtml(team.description)}</div>` : ''}
                        </div>
                    </label>
                `).join('');
            }

            document.getElementById('teamAssignmentModal').classList.add('active');
        }

        // Close team assignment modal
        function closeTeamAssignmentModal() {
            document.getElementById('teamAssignmentModal').classList.remove('active');
        }

        // Team assignment form submission
        document.getElementById('teamAssignmentForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const entityType = document.getElementById('assignmentEntityType').value;
            const entityId = document.getElementById('assignmentEntityId').value;

            // Get selected team IDs
            const checkboxes = document.querySelectorAll('#teamAssignmentList input[name="team_ids"]:checked');
            const teamIds = Array.from(checkboxes).map(cb => parseInt(cb.value));

            const endpoint = entityType === 'department'
                ? API_BASE + 'save_department_teams.php'
                : API_BASE + 'save_analyst_teams.php';

            const payload = entityType === 'department'
                ? { department_id: entityId, team_ids: teamIds }
                : { analyst_id: entityId, team_ids: teamIds };

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();

                if (data.success) {
                    closeTeamAssignmentModal();
                    // Clear cache and reload
                    if (entityType === 'department') {
                        delete departmentTeams[entityId];
                        loadDepartments();
                    } else {
                        delete analystTeams[entityId];
                        loadAnalysts();
                    }
                    // Also reload teams to update counts
                    loadTeams();
                } else {
                    alert('Error saving team assignments: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to save team assignments');
            }
        });

        // Handle form submission
        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const type = document.getElementById('itemType').value;
            const id = document.getElementById('itemId').value;
            const endpoints = {
                'department': API_BASE + 'save_department.php',
                'ticket-type': API_BASE + 'save_ticket_type.php',
                'ticket-origin': API_BASE + 'save_ticket_origin.php',
                'team': API_BASE + 'save_team.php'
            };
            const endpoint = endpoints[type];

            const formData = {
                id: id || null,
                name: document.getElementById('itemName').value,
                description: document.getElementById('itemDescription').value,
                display_order: parseInt(document.getElementById('itemOrder').value),
                is_active: document.getElementById('itemActive').checked ? 1 : 0
            };

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();

                if (data.success) {
                    closeModal();
                    if (type === 'department') {
                        loadDepartments();
                    } else if (type === 'ticket-type') {
                        loadTicketTypes();
                    } else if (type === 'ticket-origin') {
                        loadTicketOrigins();
                    } else if (type === 'team') {
                        loadTeams().then(() => {
                            loadDepartments();
                            loadAnalysts();
                        });
                    }
                } else {
                    alert('Error saving: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to save');
            }
        });

        // Utility function
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Mailbox Functions
        async function loadMailboxes() {
            try {
                const response = await fetch(API_BASE + 'get_mailboxes.php');
                const data = await response.json();
                console.log('Mailboxes loaded:', data);

                if (data.success) {
                    mailboxes = data.mailboxes;
                    console.log('Mailboxes array:', mailboxes);
                    renderMailboxes(mailboxes);
                } else {
                    console.error('Error loading mailboxes:', data.error);
                    document.getElementById('mailboxes-list').innerHTML =
                        '<tr><td colspan="5" style="text-align: center; color: red;">Error: ' + data.error + '</td></tr>';
                }
            } catch (error) {
                console.error('Error loading mailboxes:', error);
                document.getElementById('mailboxes-list').innerHTML =
                    '<tr><td colspan="5" style="text-align: center; color: red;">Failed to load mailboxes. Check console for details.</td></tr>';
            }
        }

        function renderMailboxes(mailboxes) {
            const tbody = document.getElementById('mailboxes-list');

            if (mailboxes.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No mailboxes configured. Click "Add Mailbox" to get started.</td></tr>';
                return;
            }

            tbody.innerHTML = mailboxes.map(mb => {
                const statusBadge = mb.is_authenticated
                    ? '<span class="status-badge status-active">Authenticated</span>'
                    : '<span class="status-badge status-inactive">Not Authenticated</span>';

                const activeBadge = mb.is_active
                    ? ''
                    : ' <span class="status-badge status-inactive">Inactive</span>';

                const lastChecked = mb.last_checked_datetime
                    ? new Date(mb.last_checked_datetime).toLocaleString()
                    : 'Never';

                let actions = `<button class="action-btn" onclick="editMailbox(${mb.id})" title="Edit">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                </button>`;

                actions += `<button class="action-btn" onclick="openActivityModal(${mb.id})" title="Activity">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </button>`;

                if (mb.is_authenticated) {
                    actions += `<button class="action-btn" onclick="checkMailboxEmails(${mb.id})" title="Check Emails">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </button>`;
                    actions += `<button class="action-btn" onclick="logoutMailbox(${mb.id})" title="Logout">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    </button>`;
                } else {
                    actions += `<button class="action-btn" onclick="authenticateMailbox(${mb.id})" title="Authenticate">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                    </button>`;
                }

                const safeName = escapeHtml(mb.name).replace(/'/g, "\\'");
                actions += `<button class="action-btn delete" onclick="deleteMailbox(${mb.id}, '${safeName}')" title="Delete">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </button>`;

                return `
                    <tr>
                        <td><strong>${escapeHtml(mb.name)}</strong>${activeBadge}</td>
                        <td>${escapeHtml(mb.target_mailbox)}</td>
                        <td>${statusBadge}</td>
                        <td>${lastChecked}</td>
                        <td>${actions}</td>
                    </tr>
                `;
            }).join('');
        }

        async function openMailboxModal(mailbox = null) {
            document.getElementById('mailboxModalTitle').textContent = mailbox ? 'Edit Mailbox' : 'Add Mailbox';
            document.getElementById('mailboxId').value = mailbox ? mailbox.id : '';
            document.getElementById('mailboxName').value = mailbox ? mailbox.name : '';
            document.getElementById('mailboxEmail').value = mailbox ? mailbox.target_mailbox : '';
            document.getElementById('mailboxTenantId').value = mailbox ? mailbox.azure_tenant_id : '';
            document.getElementById('mailboxClientId').value = mailbox ? mailbox.azure_client_id : '';
            document.getElementById('mailboxClientSecret').value = '';
            document.getElementById('mailboxRedirectUri').value = mailbox ? mailbox.oauth_redirect_uri : 'https://your-server.com/oauth_callback.php';
            document.getElementById('mailboxScopes').value = mailbox ? mailbox.oauth_scopes : 'openid email offline_access Mail.Read Mail.ReadWrite Mail.Send';
            document.getElementById('mailboxImapServer').value = mailbox ? mailbox.imap_server : 'outlook.office365.com';
            document.getElementById('mailboxImapPort').value = mailbox ? mailbox.imap_port : 993;
            document.getElementById('mailboxFolder').value = mailbox ? mailbox.email_folder : 'INBOX';
            document.getElementById('mailboxMaxEmails').value = mailbox ? mailbox.max_emails_per_check : 10;
            document.getElementById('mailboxRejectedAction').value = mailbox ? (mailbox.rejected_action || 'delete') : 'delete';
            document.getElementById('mailboxImportedAction').value = mailbox ? (mailbox.imported_action || 'delete') : 'delete';
            document.getElementById('mailboxImportedFolder').value = mailbox ? (mailbox.imported_folder || '') : '';
            toggleImportedFolder();
            document.getElementById('mailboxActive').checked = mailbox ? mailbox.is_active : true;

            // Load whitelist
            whitelistEntries = [];
            if (mailbox && mailbox.id) {
                try {
                    const res = await fetch(API_BASE + 'get_mailbox_whitelist.php?mailbox_id=' + mailbox.id);
                    const data = await res.json();
                    if (data.success) {
                        whitelistEntries = data.entries.map(e => ({ entry_type: e.entry_type, entry_value: e.entry_value }));
                    }
                } catch (err) {
                    console.error('Failed to load whitelist:', err);
                }
            }
            renderWhitelistEntries();

            document.getElementById('mailboxModal').classList.add('active');
        }

        function closeMailboxModal() {
            document.getElementById('mailboxModal').classList.remove('active');
        }

        function toggleImportedFolder() {
            const action = document.getElementById('mailboxImportedAction').value;
            document.getElementById('importedFolderGroup').style.display = action === 'move_to_folder' ? '' : 'none';
        }

        async function editMailbox(id) {
            const mailbox = mailboxes.find(m => m.id == id);
            if (mailbox) {
                openMailboxModal(mailbox);
            } else {
                alert('Mailbox not found. ID: ' + id);
            }
        }

        async function deleteMailbox(id, name) {
            if (!confirm(`Are you sure you want to delete the mailbox "${name}"?`)) {
                return;
            }

            try {
                const response = await fetch(API_BASE + 'delete_mailbox.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();

                if (data.success) {
                    loadMailboxes();
                } else {
                    alert('Error deleting mailbox: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to delete mailbox');
            }
        }

        function authenticateMailbox(id) {
            const mailbox = mailboxes.find(m => m.id == id);
            if (!mailbox) {
                alert('Mailbox not found. ID: ' + id);
                return;
            }

            // Build OAuth URL with mailbox-specific settings
            const state = 'mailbox_' + id + '_' + Math.random().toString(36).substring(2, 18);
            const params = new URLSearchParams({
                client_id: mailbox.azure_client_id,
                response_type: 'code',
                redirect_uri: mailbox.oauth_redirect_uri,
                response_mode: 'query',
                scope: mailbox.oauth_scopes,
                state: state
            });

            const authUrl = 'https://login.microsoftonline.com/' + mailbox.azure_tenant_id + '/oauth2/v2.0/authorize?' + params.toString();
            window.location.href = authUrl;
        }

        async function logoutMailbox(id) {
            if (!confirm('This will remove authentication for this mailbox. You will need to re-authenticate. Continue?')) {
                return;
            }

            try {
                const response = await fetch(API_BASE + 'mailbox_logout.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mailbox_id: id })
                });
                const data = await response.json();

                if (data.success) {
                    loadMailboxes();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to logout mailbox');
            }
        }

        async function checkMailboxEmails(id) {
            const result = document.getElementById('mailboxesResult');
            const mailbox = mailboxes.find(m => m.id == id);

            result.className = 'exchange-result info';
            result.innerHTML = `<span class="spinner"></span> Checking emails for ${escapeHtml(mailbox?.name || 'mailbox')}...`;

            try {
                const response = await fetch(API_BASE + 'check_mailbox_email.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mailbox_id: id })
                });
                const data = await response.json();

                if (data.success) {
                    result.className = 'exchange-result success';
                    result.innerHTML = `
                        <strong>&#10003; Success!</strong>
                        <p>${data.message}</p>
                        ${data.details ? '<pre>' + JSON.stringify(data.details, null, 2) + '</pre>' : ''}
                    `;
                    loadMailboxes(); // Refresh to update last checked time
                } else {
                    result.className = 'exchange-result error';
                    result.innerHTML = `
                        <strong>&#10007; Error</strong>
                        <p>${data.error || data.message}</p>
                    `;
                }
            } catch (error) {
                result.className = 'exchange-result error';
                result.innerHTML = `
                    <strong>&#10007; Connection Error</strong>
                    <p>Failed to connect to the server: ${error.message}</p>
                `;
            }
        }

        async function checkAllMailboxes() {
            const result = document.getElementById('mailboxesResult');
            const authenticatedMailboxes = mailboxes.filter(m => m.is_authenticated && m.is_active);

            if (authenticatedMailboxes.length === 0) {
                result.className = 'exchange-result error';
                result.innerHTML = 'No authenticated and active mailboxes to check.';
                return;
            }

            result.className = 'exchange-result info';
            result.innerHTML = `<span class="spinner"></span> Checking ${authenticatedMailboxes.length} mailbox(es)...`;

            let successCount = 0;
            let errorCount = 0;
            let totalEmails = 0;
            const results = [];

            for (const mb of authenticatedMailboxes) {
                try {
                    const response = await fetch(API_BASE + 'check_mailbox_email.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ mailbox_id: mb.id })
                    });
                    const data = await response.json();

                    if (data.success) {
                        successCount++;
                        totalEmails += data.details?.emails_saved || 0;
                        results.push(`&#10003; ${mb.name}: ${data.details?.emails_saved || 0} email(s)`);
                    } else {
                        errorCount++;
                        results.push(`&#10007; ${mb.name}: ${data.error || 'Unknown error'}`);
                    }
                } catch (error) {
                    errorCount++;
                    results.push(`&#10007; ${mb.name}: Connection error`);
                }
            }

            if (errorCount === 0) {
                result.className = 'exchange-result success';
            } else if (successCount === 0) {
                result.className = 'exchange-result error';
            } else {
                result.className = 'exchange-result info';
            }

            result.innerHTML = `
                <strong>Check Complete</strong>
                <p>${successCount} mailbox(es) checked successfully, ${totalEmails} total email(s) processed</p>
                <ul style="margin-top: 10px; padding-left: 20px;">
                    ${results.map(r => '<li>' + r + '</li>').join('')}
                </ul>
            `;

            loadMailboxes(); // Refresh to update last checked times
        }

        // Mailbox form submission
        document.getElementById('mailboxForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = {
                id: document.getElementById('mailboxId').value || null,
                name: document.getElementById('mailboxName').value,
                target_mailbox: document.getElementById('mailboxEmail').value,
                azure_tenant_id: document.getElementById('mailboxTenantId').value,
                azure_client_id: document.getElementById('mailboxClientId').value,
                azure_client_secret: document.getElementById('mailboxClientSecret').value,
                oauth_redirect_uri: document.getElementById('mailboxRedirectUri').value,
                oauth_scopes: document.getElementById('mailboxScopes').value,
                imap_server: document.getElementById('mailboxImapServer').value,
                imap_port: parseInt(document.getElementById('mailboxImapPort').value),
                email_folder: document.getElementById('mailboxFolder').value,
                max_emails_per_check: parseInt(document.getElementById('mailboxMaxEmails').value),
                rejected_action: document.getElementById('mailboxRejectedAction').value,
                imported_action: document.getElementById('mailboxImportedAction').value,
                imported_folder: document.getElementById('mailboxImportedFolder').value || null,
                is_active: document.getElementById('mailboxActive').checked
            };

            try {
                const response = await fetch(API_BASE + 'save_mailbox.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();

                if (data.success) {
                    // Save whitelist entries
                    const mailboxId = data.id || formData.id;
                    if (mailboxId) {
                        try {
                            await fetch(API_BASE + 'save_mailbox_whitelist.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ mailbox_id: mailboxId, entries: whitelistEntries })
                            });
                        } catch (wErr) {
                            console.error('Failed to save whitelist:', wErr);
                        }
                    }

                    closeMailboxModal();
                    loadMailboxes();
                } else {
                    alert('Error saving mailbox: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to save mailbox');
            }
        });

        // Whitelist Functions
        function addWhitelistEntry() {
            const type = document.getElementById('whitelistType').value;
            const value = document.getElementById('whitelistValue').value.trim().toLowerCase();

            if (!value) return;

            // Validate
            if (type === 'email' && !value.includes('@')) {
                showToast('Please enter a valid email address', 'warning');
                return;
            }
            if (type === 'domain' && value.includes('@')) {
                showToast('Enter a domain without @, e.g. company.com', 'warning');
                return;
            }

            // Check for duplicates
            if (whitelistEntries.some(e => e.entry_type === type && e.entry_value === value)) {
                showToast('Entry already exists', 'warning');
                return;
            }

            whitelistEntries.push({ entry_type: type, entry_value: value });
            renderWhitelistEntries();
            document.getElementById('whitelistValue').value = '';
        }

        function removeWhitelistEntry(index) {
            whitelistEntries.splice(index, 1);
            renderWhitelistEntries();
        }

        function renderWhitelistEntries() {
            const container = document.getElementById('whitelistEntries');
            if (whitelistEntries.length === 0) {
                container.innerHTML = '<span style="color: #999; font-size: 12px;">No whitelist entries — all senders allowed</span>';
                return;
            }

            container.innerHTML = whitelistEntries.map((e, i) => {
                const color = e.entry_type === 'domain' ? '#0078d4' : '#6c757d';
                return `<span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: ${color}15; border: 1px solid ${color}40; border-radius: 20px; font-size: 12px; color: ${color};">
                    <strong>${e.entry_type === 'domain' ? '@' : ''}${escapeHtml(e.entry_value)}</strong>
                    <button type="button" onclick="removeWhitelistEntry(${i})" style="background: none; border: none; cursor: pointer; color: ${color}; font-size: 14px; padding: 0 2px; line-height: 1;">&times;</button>
                </span>`;
            }).join('');
        }

        // Activity Log Functions
        let activityMailboxId = null;
        let activitySearchTimer = null;

        function openActivityModal(mailboxId) {
            activityMailboxId = mailboxId;
            const mb = mailboxes.find(m => m.id == mailboxId);
            document.getElementById('activityModalTitle').textContent = 'Activity — ' + (mb ? mb.name : 'Mailbox');
            document.getElementById('activitySearch').value = '';
            loadActivity(mailboxId, '', 1);
            document.getElementById('activityModal').classList.add('active');
        }

        function closeActivityModal() {
            document.getElementById('activityModal').classList.remove('active');
        }

        function debounceActivitySearch() {
            clearTimeout(activitySearchTimer);
            activitySearchTimer = setTimeout(() => {
                const search = document.getElementById('activitySearch').value;
                loadActivity(activityMailboxId, search, 1);
            }, 300);
        }

        async function loadActivity(mailboxId, search, page) {
            const tbody = document.getElementById('activityList');
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">Loading...</td></tr>';

            try {
                let url = API_BASE + 'get_mailbox_activity.php?mailbox_id=' + mailboxId + '&page=' + page;
                if (search) url += '&search=' + encodeURIComponent(search);

                const res = await fetch(url);
                const data = await res.json();

                if (!data.success) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: red;">' + escapeHtml(data.error) + '</td></tr>';
                    return;
                }

                if (data.entries.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: #999;">No activity found</td></tr>';
                    document.getElementById('activityPagination').innerHTML = '';
                    return;
                }

                tbody.innerHTML = data.entries.map(e => {
                    const dt = new Date(e.created_datetime + 'Z').toLocaleString();
                    const badge = e.action === 'imported'
                        ? '<span style="display: inline-block; padding: 2px 8px; background: #d4edda; color: #155724; border-radius: 10px; font-size: 11px;">Imported</span>'
                        : '<span style="display: inline-block; padding: 2px 8px; background: #f8d7da; color: #721c24; border-radius: 10px; font-size: 11px;">Rejected</span>';
                    const from = escapeHtml(e.from_name ? e.from_name + ' <' + e.from_address + '>' : e.from_address);
                    return `<tr>
                        <td style="white-space: nowrap;">${dt}</td>
                        <td>${from}</td>
                        <td>${escapeHtml(e.subject || '')}</td>
                        <td>${badge}</td>
                        <td>${escapeHtml(e.reason || '')}</td>
                    </tr>`;
                }).join('');

                // Pagination
                const totalPages = Math.ceil(data.total / data.per_page);
                const currentSearch = document.getElementById('activitySearch').value;
                let paginationHtml = `<span>Showing ${data.entries.length} of ${data.total} entries</span>`;

                if (totalPages > 1) {
                    paginationHtml += '<div>';
                    if (page > 1) {
                        paginationHtml += `<button class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;" onclick="loadActivity(${mailboxId}, '${currentSearch.replace(/'/g, "\\'")}', ${page - 1})">Prev</button>`;
                    }
                    paginationHtml += `<span style="margin: 0 8px;">Page ${page} of ${totalPages}</span>`;
                    if (page < totalPages) {
                        paginationHtml += `<button class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-left: 4px;" onclick="loadActivity(${mailboxId}, '${currentSearch.replace(/'/g, "\\'")}', ${page + 1})">Next</button>`;
                    }
                    paginationHtml += '</div>';
                }

                document.getElementById('activityPagination').innerHTML = paginationHtml;

            } catch (err) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: red;">Failed to load activity</td></tr>';
            }
        }

        // Analyst Functions
        let analysts = [];

        async function loadAnalysts() {
            try {
                const response = await fetch(API_BASE + 'get_analysts.php');
                const data = await response.json();

                if (data.success) {
                    analysts = data.analysts;
                    renderAnalysts(analysts);
                } else {
                    console.error('Error loading analysts:', data.error);
                    document.getElementById('analysts-list').innerHTML =
                        '<tr><td colspan="6" style="text-align: center; color: red;">Error: ' + data.error + '</td></tr>';
                }
            } catch (error) {
                console.error('Error loading analysts:', error);
                document.getElementById('analysts-list').innerHTML =
                    '<tr><td colspan="6" style="text-align: center; color: red;">Failed to load analysts.</td></tr>';
            }
        }

        async function renderAnalysts(analystsList) {
            const tbody = document.getElementById('analysts-list');

            if (analystsList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No analysts found.</td></tr>';
                return;
            }

            // Load team assignments for all analysts
            for (const analyst of analystsList) {
                if (!analystTeams[analyst.id]) {
                    try {
                        const response = await fetch(`${API_BASE}get_analyst_teams.php?analyst_id=${analyst.id}`);
                        const data = await response.json();
                        analystTeams[analyst.id] = data.success ? data.teams : [];
                    } catch (e) {
                        analystTeams[analyst.id] = [];
                    }
                }
            }

            tbody.innerHTML = analystsList.map(a => {
                const statusBadge = a.is_active
                    ? '<span class="status-badge status-active">Active</span>'
                    : '<span class="status-badge status-inactive">Inactive</span>';

                const lastLogin = a.last_login_datetime
                    ? new Date(a.last_login_datetime).toLocaleString()
                    : 'Never';

                const aTeams = analystTeams[a.id] || [];
                const teamsText = aTeams.length > 0
                    ? aTeams.map(t => `<span class="status-badge" style="background: #e8f5e9; color: #2e7d32; margin-right: 4px;">${escapeHtml(t.name)}</span>`).join('')
                    : '<span style="color: #999;">None</span>';

                const safeName = escapeHtml(a.full_name).replace(/'/g, "\\'");
                const safeUsername = escapeHtml(a.username).replace(/'/g, "\\'");

                return `
                    <tr>
                        <td><strong>${escapeHtml(a.username)}</strong></td>
                        <td>${escapeHtml(a.full_name)}</td>
                        <td>${escapeHtml(a.email || '')}</td>
                        <td>${teamsText}</td>
                        <td>${statusBadge}</td>
                        <td>${lastLogin}</td>
                        <td>
                            <button class="action-btn" onclick="editAnalyst(${a.id})" title="Edit">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </button>
                            <button class="action-btn" onclick="openTeamAssignment('analyst', ${a.id}, '${safeName}')" title="Assign Teams">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </button>
                            <button class="action-btn" onclick="openPasswordResetModal(${a.id}, '${safeName}')" title="Reset Password">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                            </button>
                            <button class="action-btn delete" onclick="deleteAnalyst(${a.id}, '${safeUsername}')" title="Delete">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function openAnalystModal(analyst = null) {
            document.getElementById('analystModalTitle').textContent = analyst ? 'Edit Analyst' : 'Add Analyst';
            document.getElementById('analystId').value = analyst ? analyst.id : '';
            document.getElementById('analystUsername').value = analyst ? analyst.username : '';
            document.getElementById('analystFullName').value = analyst ? analyst.full_name : '';
            document.getElementById('analystEmail').value = analyst ? (analyst.email || '') : '';
            document.getElementById('analystPassword').value = '';
            document.getElementById('analystActive').checked = analyst ? analyst.is_active : true;

            // Password is required only for new analysts
            const passwordInput = document.getElementById('analystPassword');
            const passwordGroup = document.getElementById('analystPasswordGroup');
            if (analyst) {
                passwordInput.removeAttribute('required');
                passwordGroup.querySelector('small').textContent = 'Leave blank to keep existing password.';
            } else {
                passwordInput.setAttribute('required', 'required');
                passwordGroup.querySelector('small').textContent = 'Required for new analysts.';
            }

            document.getElementById('analystModal').classList.add('active');
        }

        function closeAnalystModal() {
            document.getElementById('analystModal').classList.remove('active');
        }

        function editAnalyst(id) {
            const analyst = analysts.find(a => a.id == id);
            if (analyst) {
                openAnalystModal(analyst);
            } else {
                alert('Analyst not found.');
            }
        }

        async function deleteAnalyst(id, username) {
            if (!confirm(`Are you sure you want to delete the analyst "${username}"?`)) {
                return;
            }

            try {
                const response = await fetch(API_BASE + 'delete_analyst.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();

                if (data.success) {
                    loadAnalysts();
                } else {
                    alert('Error deleting analyst: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to delete analyst');
            }
        }

        function openPasswordResetModal(id, name) {
            document.getElementById('resetAnalystId').value = id;
            document.getElementById('resetAnalystName').textContent = name;
            document.getElementById('newPassword').value = '';
            document.getElementById('confirmPassword').value = '';
            document.getElementById('passwordResetModal').classList.add('active');
        }

        function closePasswordResetModal() {
            document.getElementById('passwordResetModal').classList.remove('active');
        }

        // Analyst form submission
        document.getElementById('analystForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = {
                id: document.getElementById('analystId').value || null,
                username: document.getElementById('analystUsername').value,
                full_name: document.getElementById('analystFullName').value,
                email: document.getElementById('analystEmail').value || null,
                password: document.getElementById('analystPassword').value || null,
                is_active: document.getElementById('analystActive').checked
            };

            try {
                const response = await fetch(API_BASE + 'save_analyst.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();

                if (data.success) {
                    closeAnalystModal();
                    loadAnalysts();
                } else {
                    alert('Error saving analyst: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to save analyst');
            }
        });

        // Password reset form submission
        document.getElementById('passwordResetForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;

            if (newPassword !== confirmPassword) {
                alert('Passwords do not match.');
                return;
            }

            if (newPassword.length < 6) {
                alert('Password must be at least 6 characters.');
                return;
            }

            try {
                const response = await fetch(API_BASE + 'reset_analyst_password.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: document.getElementById('resetAnalystId').value,
                        password: newPassword
                    })
                });
                const data = await response.json();

                if (data.success) {
                    closePasswordResetModal();
                    alert('Password reset successfully.');
                } else {
                    alert('Error resetting password: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to reset password');
            }
        });

        // Load analysts on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadAnalysts();
            loadGeneralSettings();
        });

        // General Settings Functions
        const timezones = [
            'UTC',
            'Europe/London',
            'Europe/Paris',
            'Europe/Berlin',
            'Europe/Amsterdam',
            'Europe/Brussels',
            'Europe/Dublin',
            'Europe/Madrid',
            'Europe/Rome',
            'Europe/Zurich',
            'Europe/Vienna',
            'Europe/Warsaw',
            'Europe/Stockholm',
            'Europe/Oslo',
            'Europe/Copenhagen',
            'Europe/Helsinki',
            'Europe/Athens',
            'Europe/Moscow',
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Los_Angeles',
            'America/Phoenix',
            'America/Toronto',
            'America/Vancouver',
            'America/Mexico_City',
            'America/Sao_Paulo',
            'America/Buenos_Aires',
            'Asia/Tokyo',
            'Asia/Shanghai',
            'Asia/Hong_Kong',
            'Asia/Singapore',
            'Asia/Seoul',
            'Asia/Bangkok',
            'Asia/Jakarta',
            'Asia/Manila',
            'Asia/Kolkata',
            'Asia/Mumbai',
            'Asia/Dubai',
            'Asia/Jerusalem',
            'Australia/Sydney',
            'Australia/Melbourne',
            'Australia/Brisbane',
            'Australia/Perth',
            'Pacific/Auckland',
            'Pacific/Fiji',
            'Africa/Cairo',
            'Africa/Johannesburg',
            'Africa/Lagos'
        ];

        function populateTimezoneDropdown(selectedTimezone = '') {
            const select = document.getElementById('systemTimezone');
            select.innerHTML = timezones.map(tz =>
                `<option value="${tz}"${tz === selectedTimezone ? ' selected' : ''}>${tz}</option>`
            ).join('');
        }

        async function loadGeneralSettings() {
            populateTimezoneDropdown();

            try {
                const response = await fetch(API_SETTINGS + 'get_system_settings.php');
                const data = await response.json();

                if (data.success) {
                    document.getElementById('systemName').value = data.settings.system_name || '';
                    populateTimezoneDropdown(data.settings.timezone || 'Europe/London');
                } else {
                    console.error('Error loading settings:', data.error);
                }
            } catch (error) {
                console.error('Error loading settings:', error);
            }
        }

        // General settings form submission
        document.getElementById('generalSettingsForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const settings = {
                system_name: document.getElementById('systemName').value,
                timezone: document.getElementById('systemTimezone').value
            };

            try {
                const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: settings })
                });
                const data = await response.json();

                if (data.success) {
                    showToast('Settings saved', 'success');
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to save settings', 'error');
            }
        });

    </script>
</body>
</html>
