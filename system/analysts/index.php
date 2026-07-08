<?php
/**
 * System - Analysts
 *
 * Analyst account management, promoted out of Tickets → Settings. Analysts are
 * the app's users — they work across every module — so their accounts belong at
 * the install level, not inside one module's settings.
 *
 * This page owns: analyst CRUD, password reset, single sign-on provider
 * assignment, per-analyst team membership, and (multi-company installs only)
 * per-company access grants. The endpoints stay under api/tickets/ — moving the
 * UI here doesn't require moving the API.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$current_page = 'analysts';
$path_prefix = '../../';
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('tickets.settings.headings.analysts')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=18">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        /* Full-width scrolling shell — the canonical settings pattern
           (tickets/settings): a plain flex wrapper pins the header and lets the
           scroll region below fill the screen. Own class (NOT inbox.css's
           .container, whose max-width:1200px + margin:auto would centre and cap
           the page) so it fills the full width. */
        .settings-shell { display: flex; flex-direction: column; height: 100vh; }
        .settings-scroll { flex: 1 1 auto; min-height: 0; overflow-y: auto; width: 100%; margin: 0; box-sizing: border-box; padding: 30px 24px 24px; }
        .page-title { font-size: 22px; font-weight: 600; color: var(--text-strong, #333); margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: var(--text-muted, #888); margin: 0 0 24px 0; line-height: 1.5; }
        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .section-header h2 { font-size: 16px; font-weight: 600; color: var(--text-strong, #333); margin: 0; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead th { text-align: left; color: var(--text-muted, #888); font-weight: 600; font-size: 12px; padding: 8px 10px; border-bottom: 1px solid var(--border-soft, #eee); }
        tbody td { padding: 10px; border-bottom: 1px solid var(--border-faint, #f2f2f2); color: var(--text, #444); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        .status-badge.status-active { background: #e8f5e9; color: #2e7d32; }
        .status-badge.status-inactive { background: #f0f0f0; color: #999; }

        /* Compact table action icons — canonical tickets/settings style
           (inbox.css's bare .action-btn is a big block button, so scope a
           compact override here or the icons balloon). */
        .settings-scroll .action-btn {
            background: none;
            border: 1px solid var(--border, #ddd);
            color: var(--text-muted, #666);
            cursor: pointer;
            padding: 6px;
            margin-right: 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .settings-scroll .action-btn:hover { background: var(--surface-hover, #f0f0f0); border-color: var(--accent, #0078d4); color: var(--accent, #0078d4); }
        .settings-scroll .action-btn.delete { color: var(--danger-accent, #d13438); }
        .settings-scroll .action-btn.delete:hover { background: var(--danger-bg, #fdf3f3); border-color: var(--danger-accent, #d13438); color: var(--danger-text, #a00); }
        .settings-scroll .action-btn svg { width: 16px; height: 16px; }

        /* Canonical settings-modal overrides (match tickets/settings). */
        .modal-content { padding: 20px; max-width: 500px; }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 20px; color: var(--text, #333); padding: 0; border-bottom: none; }
    </style>
</head>
<body>
    <div class="settings-shell">
    <?php include '../includes/header.php'; ?>

    <div class="settings-scroll">
        <h1 class="page-title"><?php echo htmlspecialchars(t('tickets.settings.headings.analysts')); ?></h1>
        <p class="page-subtitle"><?php echo t('tickets.settings.intros.analysts'); ?></p>

        <div class="section-header">
            <div></div>
            <button class="add-btn" onclick="openAnalystModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
        </div>
        <table>
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.username')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.full_name')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.email')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.teams')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.last_login')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                </tr>
            </thead>
            <tbody id="analysts-list">
                <tr><td colspan="7" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
            </tbody>
        </table>
    </div>
    </div><!-- /.settings-shell -->

    <!-- Analyst add/edit modal -->
    <div class="modal" id="analystModal">
        <div class="modal-content">
            <div class="modal-header" id="analystModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.add_title')); ?></div>
            <form id="analystForm">
                <input type="hidden" id="analystId">

                <div class="form-group">
                    <label for="analystUsername"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.username')); ?> *</label>
                    <input type="text" id="analystUsername" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.analyst.username_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="analystFullName"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.full_name')); ?> *</label>
                    <input type="text" id="analystFullName" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.analyst.full_name_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="analystEmail"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.email')); ?></label>
                    <input type="email" id="analystEmail" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.analyst.email_placeholder')); ?>">
                </div>

                <div class="form-group" id="analystPasswordGroup">
                    <label for="analystPassword"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.password')); ?> *</label>
                    <input type="password" id="analystPassword" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.analyst.password_placeholder')); ?>">
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.password_help')); ?></small>
                </div>

                <div class="form-group">
                    <label for="analystAuthProvider"><?php echo htmlspecialchars(t('tickets.settings.analyst_extra.signin_method')); ?></label>
                    <select id="analystAuthProvider">
                        <option value=""><?php echo t('tickets.settings.analyst_extra.signin_local'); ?></option>
                        <?php
                        // Single sign-on providers — assigning one makes this analyst an SSO user
                        // (strict isolation: they can only sign in via the chosen provider).
                        try {
                            $apConn = connectToDatabase();
                            foreach ($apConn->query("SELECT id, display_name FROM auth_providers ORDER BY sort_order, display_name") as $ap) {
                                echo '<option value="' . (int)$ap['id'] . '">' . htmlspecialchars($ap['display_name']) . '</option>';
                            }
                        } catch (Exception $e) { /* table may not exist yet */ }
                        ?>
                    </select>
                    <small style="color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('tickets.settings.analyst_extra.signin_help')); ?></small>
                </div>

                <!-- Multi-tenancy: company access. Hidden on a single-company install
                     (shown by JS only when more than one company exists). -->
                <div class="form-group" id="analystAccessGroup" style="display: none;">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="analystAllAccess" checked onchange="syncAnalystAccess()">
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('tickets.settings.analyst_extra.access_all')); ?>
                    </label>
                    <small style="color: var(--text-muted, #666); display: block; margin-top: 4px;"><?php echo htmlspecialchars(t('tickets.settings.analyst_extra.access_all_help')); ?></small>
                    <div id="analystCompanyList" style="display: none; margin-top: 8px; max-height: 180px; overflow-y: auto; border: 1px solid #eee; border-radius: 6px; padding: 8px;"></div>
                    <div id="analystTeamAccessNote" style="display: none; margin-top: 8px; font-size: 12px; color: var(--text-muted, #666); line-height: 1.5;"></div>
                </div>

                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="analystActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('tickets.settings.modals.analyst.active')); ?>
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAnalystModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Password Reset Modal -->
    <div class="modal" id="passwordResetModal">
        <div class="modal-content">
            <div class="modal-header"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.title')); ?></div>
            <form id="passwordResetForm">
                <input type="hidden" id="resetAnalystId">

                <p style="margin-bottom: 20px;"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.resetting_for')); ?> <strong id="resetAnalystName"></strong></p>

                <div class="form-group">
                    <label for="newPassword"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.new_password')); ?> *</label>
                    <input type="password" id="newPassword" required minlength="6" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.new_password_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="confirmPassword"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.confirm_password')); ?> *</label>
                    <input type="password" id="confirmPassword" required minlength="6" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.confirm_password_placeholder')); ?>">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closePasswordResetModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.submit')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Team Assignment Modal (analyst → teams) -->
    <div class="modal" id="teamAssignmentModal">
        <div class="modal-content">
            <div class="modal-header" id="teamAssignmentTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.team_assignment.title')); ?></div>
            <form id="teamAssignmentForm">
                <input type="hidden" id="assignmentEntityType">
                <input type="hidden" id="assignmentEntityId">

                <p style="margin-bottom: 15px; color: var(--text-muted, #666);" id="teamAssignmentDesc"><?php echo htmlspecialchars(t('tickets.settings.modals.team_assignment.description')); ?></p>

                <div id="teamAssignmentList" style="max-height: 300px; overflow-y: auto; border: 1px solid var(--border, #ddd); border-radius: 4px;">
                    <div style="padding: 15px; text-align: center; color: var(--text-faint, #999);"><?php echo htmlspecialchars(t('tickets.settings.modals.team_assignment.loading')); ?></div>
                </div>

                <div class="modal-actions" style="margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeTeamAssignmentModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/tickets/';
        const t = (window.t) || ((k) => k);
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const d = document.createElement('div');
            d.textContent = String(text);
            return d.innerHTML;
        }

        let analysts = [];
        let teams = [];                 // for the team-assignment picker
        let analystTeams = {};          // cache: analyst id -> teams

        // Map a company (tenant) id to its name for the effective-access chips.
        // Reads the companies cache populated by loadAnalystCompanies() on load.
        function companyName(id) {
            const c = (analystCompaniesCache || []).find(x => Number(x.id) === Number(id));
            return c ? c.name : ('#' + id);
        }

        // Populate the teams global (no render) so the assignment picker has data.
        async function loadTeams() {
            try {
                const r = await fetch(API_BASE + 'get_teams.php');
                const d = await r.json();
                teams = d.success ? d.teams : [];
            } catch (e) { teams = []; }
        }

        async function loadAnalysts() {
            try {
                const response = await fetch(API_BASE + 'get_analysts.php');
                const data = await response.json();
                if (data.success) {
                    analysts = data.analysts;
                    renderAnalysts(analysts);
                } else {
                    document.getElementById('analysts-list').innerHTML =
                        '<tr><td colspan="7" style="text-align: center; color: red;">Error: ' + data.error + '</td></tr>';
                }
            } catch (error) {
                console.error('Error loading analysts:', error);
                document.getElementById('analysts-list').innerHTML =
                    '<tr><td colspan="7" style="text-align: center; color: red;">Failed to load analysts.</td></tr>';
            }
        }

        async function renderAnalysts(analystsList) {
            const tbody = document.getElementById('analysts-list');
            if (analystsList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No analysts found.</td></tr>';
                return;
            }

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
                    : '<span style="color: var(--text-faint, #999);">None</span>';

                const safeName = escapeHtml(a.full_name).replace(/'/g, "\\'");
                const safeUsername = escapeHtml(a.username).replace(/'/g, "\\'");

                // Multi-tenancy: show EFFECTIVE company access — the analyst's own
                // grants unioned with any they inherit from a team. All-access via
                // their own flag shows nothing (unrestricted, as before).
                let accessChip = '';
                if (!a.can_access_all_tenants) {
                    const direct = (a.tenant_ids || []).map(Number);
                    const viaTeam = (a.team_tenant_ids || []).map(Number);
                    if (a.team_all_access) {
                        accessChip = `<span class="status-badge" style="background:#fff3e0; color:#e65100; margin-left:6px;" title="All companies — via team membership">All companies (via team)</span>`;
                    } else {
                        const eff = Array.from(new Set([...direct, ...viaTeam]));
                        if (eff.length) {
                            const names = eff.map(companyName).join(', ');
                            const viaOnly = viaTeam.filter(id => !direct.includes(id)).length;
                            const suffix = viaOnly ? ` (${viaOnly} via team)` : '';
                            accessChip = `<span class="status-badge" style="background:#fff3e0; color:#e65100; margin-left:6px;" title="${escapeHtml(names)}">${eff.length} compan${eff.length === 1 ? 'y' : 'ies'}${suffix}</span>`;
                        } else {
                            accessChip = `<span class="status-badge" style="background:#ffebee; color:#c62828; margin-left:6px;" title="This analyst has no company access — they won't see any tickets on a multi-company install">no companies</span>`;
                        }
                    }
                }

                return `
                    <tr>
                        <td><strong>${escapeHtml(a.username)}</strong></td>
                        <td>${escapeHtml(a.full_name)}${accessChip}</td>
                        <td>${escapeHtml(a.email || '')}</td>
                        <td>${teamsText}</td>
                        <td>${statusBadge}</td>
                        <td>${lastLogin}</td>
                        <td>
                            <button class="action-btn" onclick="editAnalyst(${a.id})" title="${t('common.edit')}">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </button>
                            <button class="action-btn" onclick="openTeamAssignment('analyst', ${a.id}, '${safeName}')" title="${t('tickets.settings.tooltips.assign_teams')}">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </button>
                            <button class="action-btn" onclick="openPasswordResetModal(${a.id}, '${safeName}')" title="${t('tickets.settings.tooltips.reset_password')}">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                            </button>
                            <button class="action-btn delete" onclick="deleteAnalyst(${a.id}, '${safeUsername}')" title="${t('common.delete')}">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // Multi-tenancy: companies for the analyst access control (cached, active only).
        let analystCompaniesCache = null;
        async function loadAnalystCompanies() {
            if (analystCompaniesCache !== null) return analystCompaniesCache;
            try {
                const r = await fetch('../../api/system/get_tenants.php');
                const d = await r.json();
                analystCompaniesCache = (d.success ? d.companies : []).filter(c => c.is_active);
            } catch (e) { analystCompaniesCache = []; }
            return analystCompaniesCache;
        }
        function syncAnalystAccess() {
            const all = document.getElementById('analystAllAccess').checked;
            document.getElementById('analystCompanyList').style.display = all ? 'none' : '';
        }
        function renderAnalystCompanyList(companies, grantedIds) {
            const granted = new Set((grantedIds || []).map(Number));
            document.getElementById('analystCompanyList').innerHTML = companies.map(c => `
                <label style="display:flex; align-items:center; gap:8px; padding:4px 2px; font-size:13px; cursor:pointer;">
                    <input type="checkbox" class="analyst-company-cb" value="${c.id}" ${granted.has(Number(c.id)) ? 'checked' : ''}>
                    ${escapeHtml(c.name)}
                </label>`).join('');
        }

        function openAnalystModal(analyst = null) {
            document.getElementById('analystModalTitle').textContent = analyst ? t('tickets.settings.modals.analyst.edit_title') : t('tickets.settings.modals.analyst.add_title');
            document.getElementById('analystId').value = analyst ? analyst.id : '';
            document.getElementById('analystUsername').value = analyst ? analyst.username : '';
            document.getElementById('analystFullName').value = analyst ? analyst.full_name : '';
            document.getElementById('analystEmail').value = analyst ? (analyst.email || '') : '';
            document.getElementById('analystPassword').value = '';
            document.getElementById('analystActive').checked = analyst ? analyst.is_active : true;
            document.getElementById('analystAuthProvider').value = (analyst && analyst.auth_provider_id) ? String(analyst.auth_provider_id) : '';

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

            // Multi-tenancy company access — shown only when more than one company exists.
            const accessGroup = document.getElementById('analystAccessGroup');
            loadAnalystCompanies().then(companies => {
                if (companies.length > 1) {
                    accessGroup.style.display = '';
                    document.getElementById('analystAllAccess').checked = analyst ? !!analyst.can_access_all_tenants : true;
                    renderAnalystCompanyList(companies, analyst ? (analyst.tenant_ids || []) : []);
                    syncAnalystAccess();

                    // Read-only note: company access inherited via team membership,
                    // which can't be edited here (it's set on System → Teams). Makes
                    // clear that unticking a box won't remove team-granted access.
                    const note = document.getElementById('analystTeamAccessNote');
                    const direct = new Set((analyst && analyst.tenant_ids ? analyst.tenant_ids : []).map(Number));
                    const viaOnly = (analyst && analyst.team_tenant_ids ? analyst.team_tenant_ids : []).map(Number).filter(id => !direct.has(id));
                    if (analyst && analyst.team_all_access) {
                        note.style.display = '';
                        note.innerHTML = 'This analyst is in a team with access to <strong>all companies</strong>, so they can reach every company regardless of the selection above.';
                    } else if (viaOnly.length) {
                        note.style.display = '';
                        note.innerHTML = 'Also reachable via team membership: <strong>' + viaOnly.map(id => escapeHtml(companyName(id))).join(', ') + '</strong> — manage that on System → Teams.';
                    } else {
                        note.style.display = 'none';
                        note.innerHTML = '';
                    }
                } else {
                    accessGroup.style.display = 'none';
                }
            });

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
                showToast('Analyst not found.', 'error');
            }
        }

        async function deleteAnalyst(id, username) {
            const ok = await showConfirm({
                title: 'Delete analyst',
                message: `Are you sure you want to delete the analyst "${username}"?`,
                okLabel: 'Delete',
                okClass: 'danger'
            });
            if (!ok) return;

            try {
                const response = await fetch(API_BASE + 'delete_analyst.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Analyst deleted', 'success');
                    loadAnalysts();
                } else {
                    showToast('Error deleting analyst: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to delete analyst', 'error');
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

        // Analyst → teams assignment picker
        async function openTeamAssignment(entityType, entityId, entityName) {
            document.getElementById('assignmentEntityType').value = entityType;
            document.getElementById('assignmentEntityId').value = entityId;
            document.getElementById('teamAssignmentTitle').textContent = `Assign Teams to "${entityName}"`;
            document.getElementById('teamAssignmentDesc').textContent = 'Select which teams this analyst belongs to:';

            const listContainer = document.getElementById('teamAssignmentList');
            listContainer.innerHTML = '<div style="padding: 15px; text-align: center; color: var(--text-faint, #999);">Loading teams...</div>';

            let currentTeamIds = [];
            try {
                const response = await fetch(`${API_BASE}get_analyst_teams.php?analyst_id=${entityId}`);
                const data = await response.json();
                if (data.success) currentTeamIds = data.teams.map(t => t.id);
            } catch (e) {
                console.error('Error loading current assignments:', e);
            }

            const activeTeams = teams.filter(t => t.is_active);
            if (activeTeams.length === 0) {
                listContainer.innerHTML = '<div style="padding: 15px; text-align: center; color: var(--text-faint, #999);">No active teams available. Create teams first.</div>';
            } else {
                listContainer.innerHTML = activeTeams.map(team => `
                    <label style="display: flex; align-items: center; padding: 12px 15px; border-bottom: 1px solid var(--border-soft, #eee); cursor: pointer; transition: background 0.2s;"
                           onmouseover="this.style.background='var(--surface-hover, #f5f5f5)'" onmouseout="this.style.background=''">
                        <input type="checkbox" name="team_ids" value="${team.id}" ${currentTeamIds.includes(team.id) ? 'checked' : ''}
                               style="margin-right: 12px; width: 18px; height: 18px;">
                        <div>
                            <strong>${escapeHtml(team.name)}</strong>
                            ${team.description ? `<div style="font-size: 12px; color: var(--text-muted, #666); margin-top: 2px;">${escapeHtml(team.description)}</div>` : ''}
                        </div>
                    </label>
                `).join('');
            }

            document.getElementById('teamAssignmentModal').classList.add('active');
        }

        function closeTeamAssignmentModal() {
            document.getElementById('teamAssignmentModal').classList.remove('active');
        }

        document.getElementById('teamAssignmentForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const entityId = document.getElementById('assignmentEntityId').value;
            const checkboxes = document.querySelectorAll('#teamAssignmentList input[name="team_ids"]:checked');
            const teamIds = Array.from(checkboxes).map(cb => parseInt(cb.value));

            try {
                const response = await fetch(API_BASE + 'save_analyst_teams.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ analyst_id: entityId, team_ids: teamIds })
                });
                const data = await response.json();
                if (data.success) {
                    closeTeamAssignmentModal();
                    showToast('Saved', 'success');
                    delete analystTeams[entityId];
                    loadAnalysts();
                } else {
                    showToast('Error saving team assignments: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to save team assignments', 'error');
            }
        });

        // Analyst form submission
        document.getElementById('analystForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = {
                id: document.getElementById('analystId').value || null,
                username: document.getElementById('analystUsername').value,
                full_name: document.getElementById('analystFullName').value,
                email: document.getElementById('analystEmail').value || null,
                password: document.getElementById('analystPassword').value || null,
                is_active: document.getElementById('analystActive').checked,
                auth_provider_id: document.getElementById('analystAuthProvider').value || null
            };

            // Multi-tenancy: send company access only when the control is shown.
            const accessGroup = document.getElementById('analystAccessGroup');
            if (accessGroup.style.display !== 'none') {
                const allAccess = document.getElementById('analystAllAccess').checked;
                formData.can_access_all_tenants = allAccess ? 1 : 0;
                formData.tenant_ids = allAccess ? [] :
                    Array.from(document.querySelectorAll('.analyst-company-cb:checked')).map(cb => parseInt(cb.value, 10));
            }

            try {
                const response = await fetch(API_BASE + 'save_analyst.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();
                if (data.success) {
                    closeAnalystModal();
                    showToast('Analyst saved', 'success');
                    loadAnalysts();
                } else {
                    showToast('Error saving analyst: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to save analyst', 'error');
            }
        });

        // Password reset form submission
        document.getElementById('passwordResetForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;

            if (newPassword !== confirmPassword) {
                showToast('Passwords do not match.', 'error');
                return;
            }
            if (newPassword.length < 6) {
                showToast('Password must be at least 6 characters.', 'error');
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
                    showToast('Password reset successfully.', 'success');
                } else {
                    showToast('Error resetting password: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to reset password', 'error');
            }
        });

        document.addEventListener('DOMContentLoaded', async function() {
            loadTeams();                  // populate teams global for the assignment picker
            await loadAnalystCompanies(); // company names for the effective-access chips
            loadAnalysts();
        });
    </script>
</body>
</html>
