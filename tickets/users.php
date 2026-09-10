<?php
/**
 * Users - View all users and their tickets
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('tickets');

$current_page = 'users';

// Namespaces the inline JS needs for translated strings (count / labels / table headers etc.)
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.users.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=69">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
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

        /* ⚠️ NO per-modal .modal-content override here any more.
           Both modals on this page use the canonical three-pane layout —
           modal-header + modal-body + modal-footer — which inbox.css already
           styles, including neutralising any page-level padding. This page held
           the ONLY `#xModal .modal-content` override in the codebase; the group
           modal drew attention to it by sitting beside it at the inherited 900px
           and looking like a different product. Set the width inline on the
           element, the way the other 40 pages that use this layout do. */

        /* Form fields follow the palette. */
    input, select, textarea { background: var(--surface, #fff); color: var(--text, #333); }

        /* ---- People / Groups ------------------------------------------------
           The left pane holds two lists now, so its heading becomes a tab strip.
           Same two-pane page either way: pick something on the left, read it on
           the right. */
        .pane-tabs { display: flex; gap: 4px; }

        .pane-tab {
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            border-radius: 0;
            padding: 6px 10px;
            font-size: 15px;
            font-weight: 600;
            color: var(--text-muted, #666);
            cursor: pointer;
        }

        .pane-tab.active {
            color: var(--accent, #0078d4);
            border-bottom-color: var(--accent, #0078d4);
        }

        .group-item {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-soft, #eee);
            cursor: pointer;
            transition: background-color 0.15s;
        }

        .group-item:hover { background-color: var(--surface-2, #f5f5f5); }

        .group-item.selected {
            background-color: var(--accent-soft, #e8f4fc);
            border-left: 3px solid var(--accent, #0078d4);
        }

        .group-name { font-weight: 600; color: var(--text, #333); margin-bottom: 4px; }
        .group-desc { font-size: 13px; color: var(--text-muted, #666); margin-bottom: 4px; }
        .group-meta { font-size: 12px; color: var(--text-dim, #888); display: flex; gap: 15px; }

        .member-row {
            display: grid;
            grid-template-columns: 1fr 130px 160px 90px;
            gap: 10px;
            align-items: center;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-soft, #eee);
        }

        .member-row-header {
            font-weight: 600;
            background-color: var(--surface-hover, #f0f0f0);
            font-size: 12px;
            color: var(--text-muted, #666);
            text-transform: uppercase;
        }

        .member-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .member-secondary { font-size: 12px; color: var(--text-dim, #888); }

        .member-kind {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            background-color: var(--surface-3, #f8f9fa);
            border: 1px solid var(--border, #e0e0e0);
            color: var(--text-muted, #666);
        }

        /* An expired membership is still a ROW — the clock is applied when access
           is checked, not when it lapses — so it stays on screen and says so.
           --danger-text, not --danger: the latter does not exist in theme.css. */
        .member-expired { color: var(--danger-text, #c0392b); }

        .member-add {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            flex-wrap: wrap;
            padding: 15px 20px;
            border-bottom: 1px solid var(--border, #e0e0e0);
            background-color: var(--surface-3, #f8f9fa);
        }

        .member-add-search { position: relative; flex: 1; min-width: 220px; }

        .member-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 20;
            display: none;
            max-height: 260px;
            overflow-y: auto;
            background-color: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-top: none;
            box-shadow: var(--shadow, 0 2px 8px rgba(0,0,0,0.12));
        }

        .member-results.open { display: block; }

        .member-result {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-soft, #eee);
        }

        .member-result:hover { background-color: var(--surface-2, #f5f5f5); }
        .member-result-name { font-weight: 500; color: var(--text, #333); }
        .member-result-meta { font-size: 12px; color: var(--text-dim, #888); }

        .member-add-until { display: flex; flex-direction: column; gap: 4px; }
        .member-add-until label { font-size: 12px; color: var(--text-muted, #666); }

        /* Members this analyst's company filter removed. Said out loud rather
           than silently dropped — an access list you cannot see all of is worth
           knowing about. */
        .hidden-note {
            padding: 10px 20px;
            font-size: 13px;
            color: var(--text-muted, #666);
            background-color: var(--surface-3, #f8f9fa);
            border-bottom: 1px solid var(--border, #e0e0e0);
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container users-container">
        <!-- Users List -->
        <div class="users-list-container">
            <div class="users-list-header">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <div class="pane-tabs" role="tablist">
                        <button type="button" class="pane-tab active" id="tabPeople" role="tab" aria-selected="true"
                                onclick="setPaneTab('people')"><?php echo htmlspecialchars(t('tickets.users.tabs.people')); ?></button>
                        <button type="button" class="pane-tab" id="tabGroups" role="tab" aria-selected="false"
                                onclick="setPaneTab('groups')"><?php echo htmlspecialchars(t('tickets.users.tabs.groups')); ?></button>
                    </div>
                    <?php /* One Add button for both lists — it adds whatever the active tab is showing.
                             Group management is administrator-only (see api/tickets/user_groups.php), so
                             on the Groups tab it is hidden for everyone else rather than offered and refused. */ ?>
                    <button class="add-btn" id="paneAddBtn" onclick="paneAdd()"><?php echo htmlspecialchars(t('common.add')); ?></button>
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
        <div class="modal-content" style="max-width: 560px;">
            <div class="modal-header" id="userModalTitle"><?php echo htmlspecialchars(t('tickets.users.modal.add_title')); ?></div>
            <form id="userForm" autocomplete="off">
                <div class="modal-body">
                <input type="hidden" id="userId">

                <div class="form-group">
                    <label for="userEmail"><?php echo htmlspecialchars(t('tickets.users.modal.email')); ?></label>
                    <?php /* No longer required: staff who sign in through a directory may have
                             no mailbox at all (GitHub #47), and the sign-in name identifies them. */ ?>
                    <input type="email" id="userEmail" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('tickets.users.modal.email_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="userDisplayName"><?php echo htmlspecialchars(t('tickets.users.modal.display_name')); ?></label>
                    <input type="text" id="userDisplayName" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('tickets.users.modal.display_name_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="userPreferredName"><?php echo htmlspecialchars(t('tickets.users.modal.preferred_name')); ?></label>
                    <input type="text" id="userPreferredName" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('tickets.users.modal.preferred_name_placeholder')); ?>">
                </div>

                <!-- Multi-tenancy: only shown when more than one company exists (populated by JS). -->
                <div class="form-group" id="userCompanyGroup" style="display: none;">
                    <label for="userCompany"><?php echo htmlspecialchars(t('tickets.users.modal.company')); ?></label>
                    <select id="userCompany"></select>
                    <small style="color: var(--text-muted, #666); display: block; margin-top: 4px;"><?php echo htmlspecialchars(t('tickets.users.modal.company_help')); ?></small>
                </div>

                <div class="form-group">
                    <label for="userPassword"><?php echo htmlspecialchars(t('tickets.users.modal.password')); ?></label>
                    <input type="password" id="userPassword" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(t('tickets.users.modal.password_placeholder')); ?>" minlength="8">
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.users.modal.password_help')); ?></small>
                </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeUserModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Group Modal -->
    <div class="modal" id="groupModal">
        <div class="modal-content" style="max-width: 560px;">
            <div class="modal-header" id="groupModalTitle"><?php echo htmlspecialchars(t('tickets.users.groups.modal.add_title')); ?></div>
            <form id="groupForm" autocomplete="off">
                <div class="modal-body">
                <input type="hidden" id="groupId">

                <div class="form-group">
                    <label for="groupNameField"><?php echo htmlspecialchars(t('tickets.users.groups.modal.name')); ?></label>
                    <input type="text" id="groupNameField" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('tickets.users.groups.modal.name_placeholder')); ?>" maxlength="100" required>
                </div>

                <div class="form-group">
                    <label for="groupDescField"><?php echo htmlspecialchars(t('tickets.users.groups.modal.description')); ?></label>
                    <input type="text" id="groupDescField" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('tickets.users.groups.modal.description_placeholder')); ?>" maxlength="500">
                    <small style="color: var(--text-muted, #666); display: block; margin-top: 4px;"><?php echo htmlspecialchars(t('tickets.users.groups.modal.description_help')); ?></small>
                </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeGroupModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
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

        // ---- People / Groups -------------------------------------------------
        // Which list the left pane is showing. The right pane follows it: a
        // person shows their tickets, a group shows its members.
        let paneTab = 'people';
        let groups = [];
        let selectedGroupId = null;
        // Whether this analyst may CHANGE groups. Answered by the server on every
        // load rather than guessed from the session — the page only uses it to
        // decide what to draw, and every write is re-checked server-side.
        let canManageGroups = false;
        let memberSearchTimeout = null;

        // Companies, for the modal's picker. Loaded once; stays empty (and the
        // picker stays hidden) on a single-company install.
        let userCompanies = [];

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadUsers();
            loadUserCompanies();
            // Loaded up front rather than when the tab is first opened: the answer
            // carries `can_manage`, which decides whether the Add button is drawn
            // at all, and a button that appears a moment later reads as a glitch.
            loadGroups();
        });

        // Only the companies THIS analyst can reach — the picker must never offer
        // to file someone somewhere they can't see. (save_user.php re-checks; the
        // dropdown is a convenience, not the guard.)
        async function loadUserCompanies() {
            try {
                const r = await fetch('../api/system/get_tenants.php?accessible=1');
                const d = await r.json();
                userCompanies = d.success ? d.companies : [];
            } catch (e) {
                userCompanies = [];
            }
        }

        // The hide-unless-more-than-one idiom: at N=1 multi-tenancy is invisible.
        //
        // The blank option means different things in the two modes, so it says so:
        // on a NEW person it means "work it out from their email address" (the
        // payload omits the field and the server infers), while on an EXISTING one
        // it means "no company", a deliberate choice that is sent and stored.
        function populateUserCompanies(selectedTenantId, isNew) {
            const group  = document.getElementById('userCompanyGroup');
            const select = document.getElementById('userCompany');

            if (userCompanies.length < 2) { group.style.display = 'none'; select.innerHTML = ''; return; }

            const blankLabel = isNew
                ? t('tickets.users.modal.company_auto')
                : t('tickets.users.modal.company_none');
            let html = `<option value="">${escapeHtml(blankLabel)}</option>`;
            userCompanies.forEach(c => {
                // Hide retired companies unless this person is already filed there.
                if (!c.is_active && c.id != selectedTenantId) return;
                html += `<option value="${c.id}">${escapeHtml(c.name)}</option>`;
            });
            select.innerHTML = html;
            select.value = (selectedTenantId === null || selectedTenantId === undefined) ? '' : String(selectedTenantId);
            group.style.display = '';
        }

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
                    <div class="user-email">${escapeHtml(user.email || user.username || '')}</div>
                    <div class="user-meta">
                        <span>${escapeHtml(t('tickets.users.ticket_count', { count: user.ticket_count }))}</span>
                    </div>
                </div>
            `).join('');
        }

        // Search whichever list is showing. People are searched on the server
        // (there can be thousands); groups are filtered here, because an install
        // has a handful and a round trip per keystroke would be silly.
        function searchUsers() {
            if (paneTab === 'groups') {
                renderGroupsList();
                return;
            }
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
                            <div class="user-detail-email">${escapeHtml(user.email || user.username || '')}</div>
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
                    ${user.username ? `<div class="info-item">
                        <span class="info-label">${escapeHtml(t('tickets.users.info.username'))}</span>
                        <span class="info-value">${escapeHtml(user.username)}</span>
                    </div>` : ''}
                    <div class="info-item">
                        <span class="info-label">${escapeHtml(t('tickets.users.info.first_seen'))}</span>
                        <span class="info-value">${formatDate(user.created_at)}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">${escapeHtml(t('tickets.users.info.total_tickets'))}</span>
                        <span class="info-value">${user.ticket_count}</span>
                    </div>
                    ${userCompanies.length < 2 ? '' : `
                    <div class="info-item">
                        <span class="info-label">${escapeHtml(t('tickets.users.info.company'))}</span>
                        <span class="info-value">${escapeHtml(user.tenant_name || t('tickets.users.info.company_none'))}</span>
                    </div>`}
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
            return fmtDate(date);
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
                populateUserCompanies(user?.tenant_id ?? null, false);
            } else {
                title.textContent = t('tickets.users.modal.add_title');
                idField.value = '';
                emailField.value = '';
                displayField.value = '';
                preferredField.value = '';
                // New person: no company chosen. Leaving it blank lets the server
                // work one out from their email domain.
                populateUserCompanies(null, true);
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

            // Only send a company when the picker is actually in play; otherwise a
            // single-company install would post an empty string on every save and
            // clear the company it can't even see. On a NEW person, a blank choice
            // is omitted entirely so the server infers it from their email domain.
            if (userCompanies.length >= 2) {
                const companyValue = document.getElementById('userCompany').value;
                if (companyValue || id) {
                    payload.tenant_id = companyValue || null;
                }
            }

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

        // =====================================================================
        // GROUPS
        //
        // A group is a named bag of analysts and portal users. Knowledge already
        // grants folder access to one; this is where they get made. See the
        // header of api/tickets/user_groups.php for why writes are admin-only.
        // =====================================================================

        const GROUPS_API = API_BASE + 'user_groups.php';

        function setPaneTab(tab) {
            paneTab = tab;
            document.getElementById('tabPeople').classList.toggle('active', tab === 'people');
            document.getElementById('tabGroups').classList.toggle('active', tab === 'groups');
            document.getElementById('tabPeople').setAttribute('aria-selected', tab === 'people');
            document.getElementById('tabGroups').setAttribute('aria-selected', tab === 'groups');

            const search = document.getElementById('userSearch');
            search.value = '';
            search.placeholder = tab === 'groups'
                ? t('tickets.users.groups.search_placeholder')
                : t('tickets.users.search_placeholder');

            // Hidden rather than disabled on the Groups tab for a non-admin: a
            // greyed button invites a click and then explains a refusal, which is
            // a worse answer than not offering it.
            const addBtn = document.getElementById('paneAddBtn');
            addBtn.style.display = (tab === 'groups' && !canManageGroups) ? 'none' : '';

            const detail = document.getElementById('userDetail');
            if (tab === 'groups') {
                selectedUserId = null;
                renderGroupsList();
                detail.innerHTML = `<div class="empty-state">${escapeHtml(t('tickets.users.groups.select_group'))}</div>`;
            } else {
                selectedGroupId = null;
                loadUsers();
                detail.innerHTML = `<div class="empty-state">${escapeHtml(t('tickets.users.select_user'))}</div>`;
            }
        }

        // The Add button belongs to whichever list is showing.
        function paneAdd() {
            if (paneTab === 'groups') openGroupModal();
            else openUserModal();
        }

        async function loadGroups() {
            try {
                const r = await fetch(`${GROUPS_API}?action=list`);
                const d = await r.json();
                if (!d.success) { console.error('Error loading groups:', d.error); return; }
                groups = d.groups || [];
                canManageGroups = !!d.can_manage;
                if (paneTab === 'groups') renderGroupsList();
            } catch (e) {
                console.error('Error loading groups:', e);
            }
        }

        function renderGroupsList() {
            const container = document.getElementById('usersList');
            const countEl = document.getElementById('userCount');
            const term = document.getElementById('userSearch').value.trim().toLowerCase();

            const shown = term
                ? groups.filter(g => (g.name || '').toLowerCase().includes(term)
                                  || (g.description || '').toLowerCase().includes(term))
                : groups;

            countEl.textContent = t('tickets.users.groups.count', { count: shown.length });

            if (shown.length === 0) {
                container.innerHTML = `<div class="empty-state">${escapeHtml(t('tickets.users.groups.none'))}</div>`;
                return;
            }

            container.innerHTML = shown.map(g => `
                <div class="group-item ${selectedGroupId == g.id ? 'selected' : ''}" onclick="selectGroup(${g.id})">
                    <div class="group-name">${escapeHtml(g.name)}</div>
                    ${g.description ? `<div class="group-desc">${escapeHtml(g.description)}</div>` : ''}
                    <div class="group-meta">
                        <span>${escapeHtml(t('tickets.users.groups.member_count', { count: g.member_count }))}</span>
                        ${g.expired_count ? `<span class="member-expired">${escapeHtml(t('tickets.users.groups.expired_count', { count: g.expired_count }))}</span>` : ''}
                    </div>
                </div>
            `).join('');
        }

        async function selectGroup(groupId) {
            selectedGroupId = groupId;
            renderGroupsList();

            const detail = document.getElementById('userDetail');
            detail.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

            try {
                const r = await fetch(`${GROUPS_API}?action=get&id=${encodeURIComponent(groupId)}`);
                const d = await r.json();
                if (!d.success) {
                    detail.innerHTML = `<div class="empty-state">${escapeHtml(d.error || t('tickets.users.groups.load_failed'))}</div>`;
                    return;
                }
                renderGroupDetail(d);
            } catch (e) {
                detail.innerHTML = `<div class="empty-state">${escapeHtml(t('tickets.users.groups.load_failed'))}</div>`;
            }
        }

        function renderGroupDetail(data) {
            const g = data.group;
            const members = data.members || [];
            const canManage = !!data.can_manage;

            const kindLabels = {
                analyst: t('tickets.users.groups.kind_analyst'),
                user:    t('tickets.users.groups.kind_user')
            };

            const rows = members.map(m => {
                // An expired row is shown, struck through in words rather than
                // removed: the membership still exists, it has simply stopped
                // counting. Deleting it on expiry would erase the record of who
                // was given access and when.
                // ⚠️ fmtNaiveDate on `expires_on`, NOT formatDate on `expires_at`.
                // The server has already converted the instant back to the day it
                // was picked on; running that through a zone conversion again is
                // what made "until the 30th" read as "until the 1st". Measured —
                // the two disagreed by a day for eight months of the year.
                const until = m.expires_on
                    ? `<span class="${m.is_expired ? 'member-expired' : ''}">${escapeHtml(
                        m.is_expired
                            ? t('tickets.users.groups.expired_on', { date: fmtNaiveDate(m.expires_on) })
                            : t('tickets.users.groups.expires_on', { date: fmtNaiveDate(m.expires_on) })
                      )}</span>`
                    : `<span style="color: var(--text-dim, #888);">${escapeHtml(t('tickets.users.groups.no_expiry'))}</span>`;

                return `
                    <div class="member-row">
                        <div class="member-name">
                            <div>${escapeHtml(m.name || t('tickets.users.unknown_name'))}</div>
                            ${m.secondary ? `<div class="member-secondary">${escapeHtml(m.secondary)}</div>` : ''}
                        </div>
                        <div><span class="member-kind">${escapeHtml(kindLabels[m.member_type] || m.member_type)}</span></div>
                        <div>${until}</div>
                        <div>${canManage ? `<button class="btn btn-secondary" onclick="removeMember('${escapeHtml(m.member_type)}', ${m.member_id})">${escapeHtml(t('common.remove'))}</button>` : ''}</div>
                    </div>
                `;
            }).join('');

            document.getElementById('userDetail').innerHTML = `
                <div class="user-detail-header">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;">
                        <div>
                            <h2 class="user-detail-name">${escapeHtml(g.name)}</h2>
                            <div class="user-detail-email">${escapeHtml(g.description || t('tickets.users.groups.no_description'))}</div>
                        </div>
                        ${canManage ? `<div style="display: flex; gap: 8px; flex-shrink: 0;">
                            <button class="btn btn-secondary" onclick="openGroupModal(${g.id})">${escapeHtml(t('common.edit'))}</button>
                            <button class="btn btn-secondary" onclick="deleteGroup(${g.id})">${escapeHtml(t('common.delete'))}</button>
                        </div>` : ''}
                    </div>
                </div>

                ${canManage ? `
                <div class="member-add">
                    <div class="member-add-search">
                        <input type="text" class="search-box" id="memberSearch" autocomplete="off"
                               placeholder="${escapeHtml(t('tickets.users.groups.add_placeholder'))}"
                               oninput="searchMembers()">
                        <div class="member-results" id="memberResults"></div>
                    </div>
                    <div class="member-add-until">
                        <label for="memberUntil">${escapeHtml(t('tickets.users.groups.access_until'))}</label>
                        <input type="date" id="memberUntil">
                    </div>
                </div>
                <div class="hidden-note" style="background: none; border: none; padding-top: 8px; padding-bottom: 0;">
                    ${escapeHtml(t('tickets.users.groups.access_until_help'))}
                </div>` : ''}

                ${data.hidden_count ? `<div class="hidden-note">${escapeHtml(t('tickets.users.groups.hidden_members', { count: data.hidden_count }))}</div>` : ''}

                <div class="tickets-section">
                    <div class="tickets-header">${escapeHtml(t('tickets.users.groups.members_section', { count: members.length }))}</div>
                    <div class="tickets-list">
                        ${members.length === 0
                            ? `<div class="empty-state">${escapeHtml(t('tickets.users.groups.no_members'))}</div>`
                            : `<div class="member-row member-row-header">
                                   <span>${escapeHtml(t('tickets.users.groups.table.name'))}</span>
                                   <span>${escapeHtml(t('tickets.users.groups.table.kind'))}</span>
                                   <span>${escapeHtml(t('tickets.users.groups.table.access'))}</span>
                                   <span></span>
                               </div>${rows}`}
                    </div>
                </div>
            `;
        }

        // ---- The member picker ----------------------------------------------

        function searchMembers() {
            clearTimeout(memberSearchTimeout);
            memberSearchTimeout = setTimeout(async () => {
                const box = document.getElementById('memberSearch');
                const results = document.getElementById('memberResults');
                if (!box || !results) return;

                const q = box.value.trim();
                if (q.length < 2) { results.classList.remove('open'); results.innerHTML = ''; return; }

                try {
                    const r = await fetch(`${GROUPS_API}?action=search&q=${encodeURIComponent(q)}`);
                    const d = await r.json();
                    if (!d.success || !d.results.length) {
                        results.innerHTML = `<div class="member-result" style="cursor: default;">${escapeHtml(t('tickets.users.groups.no_matches'))}</div>`;
                        results.classList.add('open');
                        return;
                    }
                    const kindLabels = {
                        analyst: t('tickets.users.groups.kind_analyst'),
                        user:    t('tickets.users.groups.kind_user')
                    };
                    results.innerHTML = d.results.map(p => `
                        <div class="member-result" onclick="addMember('${escapeHtml(p.member_type)}', ${p.member_id})">
                            <div class="member-result-name">${escapeHtml(p.name)}</div>
                            <div class="member-result-meta">${escapeHtml(kindLabels[p.member_type] || p.member_type)}${p.secondary ? ' · ' + escapeHtml(p.secondary) : ''}</div>
                        </div>
                    `).join('');
                    results.classList.add('open');
                } catch (e) {
                    results.classList.remove('open');
                }
            }, 300);
        }

        async function addMember(memberType, memberId) {
            const untilEl = document.getElementById('memberUntil');
            // Read it now, because the redraw below destroys the box it lives in.
            const keepUntil = untilEl ? untilEl.value : '';
            try {
                const r = await fetch(GROUPS_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'add_member',
                        id: selectedGroupId,
                        member_type: memberType,
                        member_id: memberId,
                        expires_on: keepUntil
                    })
                });
                const d = await r.json();
                if (!d.success) { showToast(d.error || t('tickets.users.groups.add_failed'), 'error'); return; }

                await selectGroup(selectedGroupId);
                await loadGroups();

                // ⚠️ PUT THE DATE BACK. selectGroup() redraws the whole detail pane,
                // so the search box and the date box are new elements — clearing the
                // old ones would have done nothing, and the date would silently
                // empty itself between one person and the next.
                //
                // 🔑 That is not cosmetic. Adding three contractors for the same
                // fortnight is the ordinary case, and a date box that resets after
                // the first one is exactly how the other two get PERMANENT access to
                // whatever this group opens. Caught by driving the real page: the
                // comment here used to claim the date was kept, and it was not.
                const boxAfter = document.getElementById('memberSearch');
                const untilAfter = document.getElementById('memberUntil');
                if (untilAfter) untilAfter.value = keepUntil;
                if (boxAfter) boxAfter.focus();
            } catch (e) {
                showToast(t('tickets.users.groups.add_failed'), 'error');
            }
        }

        async function removeMember(memberType, memberId) {
            if (!(await showConfirm({
                title: 'Confirm',
                message: t('tickets.users.groups.confirm_remove'),
                okLabel: 'OK',
                okClass: 'primary'
            }))) return;

            try {
                const r = await fetch(GROUPS_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'remove_member', id: selectedGroupId, member_type: memberType, member_id: memberId })
                });
                const d = await r.json();
                if (!d.success) { showToast(d.error || t('tickets.users.groups.remove_failed'), 'error'); return; }
                await selectGroup(selectedGroupId);
                await loadGroups();
            } catch (e) {
                showToast(t('tickets.users.groups.remove_failed'), 'error');
            }
        }

        // ---- The group form --------------------------------------------------

        function openGroupModal(groupId) {
            const modal = document.getElementById('groupModal');
            const title = document.getElementById('groupModalTitle');
            const idField = document.getElementById('groupId');
            const nameField = document.getElementById('groupNameField');
            const descField = document.getElementById('groupDescField');

            if (groupId) {
                const g = groups.find(x => x.id == groupId);
                title.textContent = t('tickets.users.groups.modal.edit_title');
                idField.value = groupId;
                nameField.value = g?.name || '';
                descField.value = g?.description || '';
            } else {
                title.textContent = t('tickets.users.groups.modal.add_title');
                idField.value = '';
                nameField.value = '';
                descField.value = '';
            }
            modal.classList.add('active');
            nameField.focus();
        }

        function closeGroupModal() {
            document.getElementById('groupModal').classList.remove('active');
        }

        document.getElementById('groupForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const id = document.getElementById('groupId').value;

            try {
                const response = await fetch(GROUPS_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: id ? 'update' : 'create',
                        id: id || null,
                        name: document.getElementById('groupNameField').value.trim(),
                        description: document.getElementById('groupDescField').value.trim()
                    })
                });
                const data = await response.json();
                if (!data.success) { showToast(data.error || 'Save failed', 'error'); return; }

                closeGroupModal();
                await loadGroups();
                selectGroup(id ? Number(id) : data.id);
            } catch (err) {
                showToast('Save failed: ' + err.message, 'error');
            }
        });

        async function deleteGroup(groupId) {
            const g = groups.find(x => x.id == groupId);
            const label = g?.name || `#${groupId}`;
            if (!(await showConfirm({
                title: 'Confirm',
                message: t('tickets.users.groups.confirm_delete', { name: label }),
                okLabel: 'OK',
                okClass: 'primary'
            }))) return;

            try {
                const response = await fetch(GROUPS_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', id: groupId })
                });
                const data = await response.json();
                if (!data.success) { showToast(data.error || 'Delete failed', 'error'); return; }

                selectedGroupId = null;
                document.getElementById('userDetail').innerHTML =
                    `<div class="empty-state">${escapeHtml(t('tickets.users.groups.select_group'))}</div>`;
                await loadGroups();
            } catch (err) {
                showToast('Delete failed: ' + err.message, 'error');
            }
        }

        // Close the member picker when the pointer goes elsewhere. Without this it
        // stays open over the member list underneath it.
        document.addEventListener('click', function(e) {
            const results = document.getElementById('memberResults');
            if (!results || !results.classList.contains('open')) return;
            if (e.target.closest('.member-add-search')) return;
            results.classList.remove('open');
        });
    </script>
</body>
</html>
