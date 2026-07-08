<?php
/**
 * System - Teams
 *
 * Team management, promoted out of Tickets → Settings so it lives alongside the
 * other cross-module concerns. Teams are consumed by tickets, tasks, contracts
 * and the workflow engine, so they belong at the install level rather than
 * buried in one module's settings.
 *
 * This page owns team identity (name/description/order/active) and, via the
 * per-row "Manage departments" / "Manage members" pickers, the team side of the
 * department_teams and analyst_teams links. Those same links are also editable
 * from the other side (analyst→teams on System → Analysts, department→teams on
 * Tickets → Settings → Departments — departments are a ticket concept and stay
 * there); every side writes the same join tables.
 *
 * Teams are global (no tenant scoping) by design, so no company filter is
 * needed even on a multi-company install. The endpoints still live under
 * api/tickets/ (shared with the Departments team-picker) — moving the UI
 * doesn't require moving the API.
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

$current_page = 'teams';
$path_prefix = '../../';
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('tickets.settings.headings.teams')); ?></title>
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
        <h1 class="page-title"><?php echo htmlspecialchars(t('tickets.settings.headings.teams')); ?></h1>
        <p class="page-subtitle"><?php echo t('tickets.settings.intros.teams'); ?></p>

        <div class="section-header">
            <div></div>
            <button class="add-btn" onclick="openTeamModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
        </div>
        <table>
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.description')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.departments')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.analysts')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                    <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                </tr>
            </thead>
            <tbody id="teams-list">
                <tr><td colspan="7" style="text-align: center;"><?php echo htmlspecialchars(t('tickets.settings.loading')); ?></td></tr>
            </tbody>
        </table>
    </div>
    </div><!-- /.settings-shell -->

    <!-- Team add/edit modal -->
    <div class="modal" id="teamModal">
        <div class="modal-content">
            <div class="modal-header" id="teamModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.lookup.add.team')); ?></div>
            <form id="teamForm">
                <input type="hidden" id="teamId">
                <div class="form-group">
                    <label for="teamName"><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?> *</label>
                    <input type="text" id="teamName" required>
                </div>
                <div class="form-group">
                    <label for="teamDescription"><?php echo htmlspecialchars(t('tickets.settings.columns.description')); ?></label>
                    <input type="text" id="teamDescription">
                </div>
                <div class="form-group">
                    <label for="teamOrder"><?php echo htmlspecialchars(t('tickets.settings.modals.lookup.display_order_label')); ?></label>
                    <input type="number" id="teamOrder" value="0">
                </div>
                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="teamActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('tickets.settings.modals.lookup.active_label')); ?>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeTeamModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Team → departments / members assignment modal (shared by both pickers) -->
    <div class="modal" id="teamAssignModal">
        <div class="modal-content">
            <div class="modal-header" id="teamAssignTitle"></div>
            <form id="teamAssignForm">
                <input type="hidden" id="assignTeamId">
                <input type="hidden" id="assignKind">
                <p style="margin-bottom: 15px; color: var(--text-muted, #666);" id="teamAssignDesc"></p>
                <div id="teamAssignList" style="max-height: 320px; overflow-y: auto; border: 1px solid var(--border, #ddd); border-radius: 4px;">
                    <div style="padding: 15px; text-align: center; color: var(--text-faint, #999);">Loading…</div>
                </div>
                <div class="modal-actions" style="margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeTeamAssign()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Endpoints stay under api/tickets/ — shared with the Departments team-picker.
        const API_BASE = '../../api/tickets/';
        const t = (window.t) || ((k) => k);
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const d = document.createElement('div');
            d.textContent = String(text);
            return d.innerHTML;
        }

        let teams = [];

        async function loadTeams() {
            const tbody = document.getElementById('teams-list');
            try {
                const response = await fetch(API_BASE + 'get_teams.php');
                const data = await response.json();
                if (data.success) {
                    teams = data.teams;
                    renderTeams(teams);
                } else {
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: red;">Error: ' + data.error + '</td></tr>';
                }
            } catch (error) {
                console.error('Error loading teams:', error);
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: red;">Failed to load teams.</td></tr>';
            }
        }

        async function renderTeams(teamsList) {
            const tbody = document.getElementById('teams-list');
            if (teamsList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No teams found. Click "Add" to create your first team.</td></tr>';
                return;
            }

            // Per-team department/analyst counts, edited in place via the
            // "Manage departments" / "Manage members" row actions.
            const teamsWithCounts = await Promise.all(teamsList.map(async (team) => {
                let deptCount = 0, analystCount = 0;
                try {
                    const r = await fetch(`${API_BASE}get_team_departments.php?team_id=${team.id}`);
                    const d = await r.json();
                    deptCount = d.success ? d.departments.length : 0;
                } catch (e) { }
                try {
                    const r = await fetch(`${API_BASE}get_team_analysts.php?team_id=${team.id}`);
                    const d = await r.json();
                    analystCount = d.success ? d.analysts.length : 0;
                } catch (e) { }
                return { ...team, deptCount, analystCount };
            }));

            tbody.innerHTML = teamsWithCounts.map(team => `
                <tr>
                    <td><strong>${escapeHtml(team.name)}</strong></td>
                    <td>${escapeHtml(team.description || '')}</td>
                    <td>${team.deptCount} department(s)</td>
                    <td>${team.analystCount} analyst(s)</td>
                    <td>${team.display_order}</td>
                    <td><span class="status-badge status-${team.is_active ? 'active' : 'inactive'}">${team.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editTeam(${team.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn" onclick="openTeamAssign('departments', ${team.id}, '${escapeHtml(team.name).replace(/'/g, "\\'")}')" title="Manage departments">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path><path d="M9 7h1M9 11h1M14 7h1M14 11h1M9 15h6"></path></svg>
                        </button>
                        <button class="action-btn" onclick="openTeamAssign('analysts', ${team.id}, '${escapeHtml(team.name).replace(/'/g, "\\'")}')" title="Manage members">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteTeam(${team.id}, '${escapeHtml(team.name).replace(/'/g, "\\'")}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function openTeamModal(team = null) {
            document.getElementById('teamModalTitle').textContent = team
                ? t('tickets.settings.modals.lookup.edit.team')
                : t('tickets.settings.modals.lookup.add.team');
            document.getElementById('teamId').value = team ? team.id : '';
            document.getElementById('teamName').value = team ? team.name : '';
            document.getElementById('teamDescription').value = team ? (team.description || '') : '';
            document.getElementById('teamOrder').value = team ? (team.display_order || 0) : 0;
            document.getElementById('teamActive').checked = team ? !!Number(team.is_active) : true;
            document.getElementById('teamModal').classList.add('active');
        }

        function closeTeamModal() {
            document.getElementById('teamModal').classList.remove('active');
        }

        // Team → departments / members picker. kind = 'departments' | 'analysts'.
        // Writes the team-keyed side of department_teams / analyst_teams; the
        // department and analyst pages edit the same links from their side.
        async function openTeamAssign(kind, teamId, teamName) {
            const isDept = kind === 'departments';
            document.getElementById('assignTeamId').value = teamId;
            document.getElementById('assignKind').value = kind;
            document.getElementById('teamAssignTitle').textContent = isDept
                ? `Departments for "${teamName}"` : `Members of "${teamName}"`;
            document.getElementById('teamAssignDesc').textContent = isDept
                ? 'Select which departments this team serves:'
                : 'Select which analysts belong to this team:';

            const list = document.getElementById('teamAssignList');
            list.innerHTML = '<div style="padding:15px;text-align:center;color:var(--text-faint,#999);">Loading…</div>';

            // All items + this team's current assignments (both fetched fresh).
            let items = [], currentIds = [];
            try {
                const r = await fetch(API_BASE + (isDept ? 'get_departments.php' : 'get_analysts.php'));
                const d = await r.json();
                items = d.success ? (isDept ? d.departments : d.analysts) : [];
            } catch (e) { }
            try {
                const r = await fetch(`${API_BASE}${isDept ? 'get_team_departments.php' : 'get_team_analysts.php'}?team_id=${teamId}`);
                const d = await r.json();
                const cur = d.success ? (isDept ? d.departments : d.analysts) : [];
                currentIds = cur.map(x => x.id);
            } catch (e) { }

            if (items.length === 0) {
                list.innerHTML = `<div style="padding:15px;text-align:center;color:var(--text-faint,#999);">No ${isDept ? 'departments' : 'analysts'} available.</div>`;
            } else {
                // Render ALL items (not just active) so an existing link to an
                // inactive department/analyst isn't silently dropped on save.
                list.innerHTML = items.map(it => {
                    const label = isDept ? it.name : (it.full_name || it.username);
                    const inactive = !Number(it.is_active);
                    const checked = currentIds.includes(it.id) ? 'checked' : '';
                    return `
                        <label style="display:flex; align-items:center; padding:12px 15px; border-bottom:1px solid var(--border-soft,#eee); cursor:pointer; transition:background 0.2s;"
                               onmouseover="this.style.background='var(--surface-hover,#f5f5f5)'" onmouseout="this.style.background=''">
                            <input type="checkbox" name="assign_ids" value="${it.id}" ${checked} style="margin-right:12px; width:18px; height:18px;">
                            <div><strong>${escapeHtml(label)}</strong>${inactive ? ' <span style="color:var(--text-faint,#999); font-size:12px;">(inactive)</span>' : ''}</div>
                        </label>`;
                }).join('');
            }
            document.getElementById('teamAssignModal').classList.add('active');
        }

        function closeTeamAssign() {
            document.getElementById('teamAssignModal').classList.remove('active');
        }

        document.getElementById('teamAssignForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const teamId = document.getElementById('assignTeamId').value;
            const isDept = document.getElementById('assignKind').value === 'departments';
            const ids = Array.from(document.querySelectorAll('#teamAssignList input[name="assign_ids"]:checked')).map(cb => parseInt(cb.value));
            const endpoint = isDept ? 'save_team_departments.php' : 'save_team_analysts.php';
            const payload = isDept ? { team_id: teamId, department_ids: ids } : { team_id: teamId, analyst_ids: ids };
            try {
                const r = await fetch(API_BASE + endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const d = await r.json();
                if (d.success) {
                    closeTeamAssign();
                    showToast('Saved', 'success');
                    loadTeams(); // refresh the counts
                } else {
                    showToast('Error saving: ' + d.error, 'error');
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Failed to save', 'error');
            }
        });

        function editTeam(id) {
            const team = teams.find(t => t.id == id);
            if (team) openTeamModal(team);
            else showToast('Team not found.', 'error');
        }

        async function deleteTeam(id, name) {
            const ok = await showConfirm({
                title: 'Delete team',
                message: `Are you sure you want to delete the team "${name}"?`,
                okLabel: 'Delete',
                okClass: 'danger'
            });
            if (!ok) return;
            try {
                const response = await fetch(API_BASE + 'delete_team.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Team deleted', 'success');
                    loadTeams();
                } else {
                    showToast('Error deleting team: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to delete team', 'error');
            }
        }

        document.getElementById('teamForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = {
                id: document.getElementById('teamId').value || null,
                name: document.getElementById('teamName').value,
                description: document.getElementById('teamDescription').value,
                display_order: parseInt(document.getElementById('teamOrder').value) || 0,
                is_active: document.getElementById('teamActive').checked ? 1 : 0
            };
            try {
                const response = await fetch(API_BASE + 'save_team.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();
                if (data.success) {
                    closeTeamModal();
                    showToast('Saved', 'success');
                    loadTeams();
                } else {
                    showToast('Error saving: ' + data.error, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Failed to save', 'error');
            }
        });

        document.addEventListener('DOMContentLoaded', loadTeams);
    </script>
</body>
</html>
