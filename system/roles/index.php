<?php
/**
 * System — Roles (RBAC Layer 2).
 *
 * Create roles that grant specific modules' SETTINGS to non-administrators, and
 * assign those roles to analysts and teams. System administrators don't need a
 * role — they bypass the whole layer — so this page is about delegating slices
 * of administration to people who aren't full admins. See docs/design/rbac.md.
 *
 * Admin-only: the System header bounces non-admins, and every write API is
 * behind admin_api_guard.php.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/rbac.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$current_page = 'roles';
$path_prefix = '../../';
$translationNamespaces = ['common'];

$conn = connectToDatabase();

// Picker data, embedded rather than fetched — the page is admin-only and these
// lists are small. Analysts flagged is_admin are shown as already-covered.
$analysts = $conn->query("SELECT id, full_name, username, is_admin FROM analysts WHERE is_active = 1 ORDER BY full_name, username")->fetchAll(PDO::FETCH_ASSOC);
$teams    = $conn->query("SELECT id, name FROM teams WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$capabilityGroups = capGroups();   // generated from the registry — add a Cap:: constant, the tick-box appears
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.landing.roles_title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        /* Pin the shared accent trio to the System blue-grey (as the other System
           pages do): --on-accent must be pinned too, because in dark mode the
           System accent is a LIGHT colour and text on an accent fill goes dark. */
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        .settings-shell { display: flex; flex-direction: column; height: 100vh; }
        .roles-scroll { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: 24px 32px 48px; }
        .roles-scroll > h1 { font-size: 1.5rem; margin: 0 0 6px; }
        .roles-intro { color: var(--text-muted, #555); font-size: 14px; line-height: 1.6; max-width: 760px; margin: 0 0 20px; }
        .roles-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .roles-head h2 { margin: 0; font-size: 1.05rem; }

        table.roles-table { width: 100%; border-collapse: collapse; }
        .roles-table th, .roles-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-soft, #ececf1); font-size: 13.5px; vertical-align: middle; }
        .roles-table th { color: var(--text, #1f2330); font-weight: 600; background: var(--surface-2, #f9fafb); }
        .roles-table td { color: var(--text-muted, #4b5563); }
        .roles-chip { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11.5px; background: var(--surface-3, #eef2f7); color: var(--text-muted, #6b7280); }
        .roles-empty { padding: 40px; text-align: center; color: var(--text-muted, #6b7280); }

        /* Capability + assignment pickers inside the modal */
        .rl-modal-body { padding: 18px 22px; max-height: 70vh; overflow-y: auto; }
        .rl-section { margin-top: 18px; }
        .rl-section > h4 { margin: 0 0 4px; font-size: 13px; }
        .rl-section > p { margin: 0 0 10px; font-size: 12px; color: var(--text-muted, #6b7280); line-height: 1.5; }
        .rl-cap-group { border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 12px 14px; margin-bottom: 10px; background: var(--surface, #fff); }
        .rl-cap-group h5 { margin: 0 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-dim, #9aa); }
        .rl-check { display: flex; align-items: flex-start; gap: 9px; padding: 5px 0; font-size: 13.5px; color: var(--text, #374151); line-height: 1.45; }
        .rl-check input { margin-top: 2px; flex-shrink: 0; }
        /* The umbrella ("manage everything in this module") reads as the headline choice. */
        .rl-cap-umbrella { font-weight: 600; padding-bottom: 7px; margin-bottom: 3px; border-bottom: 1px dashed var(--border, #e5e7eb); }
        /* Sensitive = reaches credentials, email or money. Badge it; granting it should make you pause. */
        .rl-cap-sensitive { display: inline-block; margin-left: 5px; padding: 1px 6px; border-radius: 9px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; vertical-align: 1px;
            background: var(--danger-bg, #fee2e2); color: var(--danger-text, #991b1b); }
        .rl-picker { max-height: 200px; overflow-y: auto; border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 8px 12px; background: var(--surface, #fff); }
        .rl-picker .rl-check .rl-admin-note { color: var(--text-dim, #9aa); font-size: 11.5px; }
    </style>
</head>
<body>
    <div class="settings-shell">
    <?php include '../includes/header.php'; ?>

    <div class="roles-scroll">
        <h1><?php echo htmlspecialchars(t('system.landing.roles_title')); ?></h1>
        <p class="roles-intro">
            Roles let you hand a slice of administration to someone who isn't a full System administrator —
            for example, letting a training lead manage the LMS without giving them the run of System.
            A role grants one or more <strong>settings capabilities</strong>; assign it to analysts or whole teams.
            System administrators already have everything, so they don't need a role.
        </p>

        <div class="roles-head">
            <h2>Roles</h2>
            <button class="btn btn-primary" onclick="Roles.openCreate()">Add</button>
        </div>

        <table class="roles-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Grants</th>
                    <th>Assigned to</th>
                    <th>Status</th>
                    <th style="width: 110px;">Actions</th>
                </tr>
            </thead>
            <tbody id="rolesBody">
                <tr><td colspan="5" class="roles-empty">Loading…</td></tr>
            </tbody>
        </table>
    </div>
    </div><!-- /.settings-shell -->

    <!-- Create modal (name only; capabilities/assignments set on edit) -->
    <div class="modal" id="createModal">
        <div class="modal-content" style="max-width: 460px;">
            <div class="modal-header">Add role</div>
            <form id="createForm" style="padding: 20px 24px;">
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" id="cName" required placeholder="e.g. LMS Manager">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" id="cDescription" placeholder="What is this role for?">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="Roles.close('createModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit modal (identity + capabilities + assignments, saved as a unit) -->
    <div class="modal" id="editModal">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header" id="editTitle">Edit role</div>
            <form id="editForm">
                <input type="hidden" id="eId">
                <div class="rl-modal-body">
                    <div class="form-group">
                        <label>Name *</label>
                        <input type="text" id="eName" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" id="eDescription">
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" id="eActive" checked> Active</label>
                    </div>

                    <div class="rl-section">
                        <h4>Grants</h4>
                        <p>The settings this role lets its holders manage. Tick what they should be able to configure.</p>
                        <div id="capGroups"></div>
                    </div>

                    <div class="rl-section">
                        <h4>Analysts</h4>
                        <p>People who hold this role directly.</p>
                        <div class="rl-picker" id="analystPicker"></div>
                    </div>

                    <div class="rl-section">
                        <h4>Teams</h4>
                        <p>Every member of a chosen team inherits this role.</p>
                        <div class="rl-picker" id="teamPicker"></div>
                    </div>
                </div>
                <div class="modal-actions" style="padding: 14px 22px;">
                    <button type="button" class="btn btn-secondary" onclick="Roles.close('editModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.RBAC_CAPS     = <?php echo json_encode($capabilityGroups, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        window.RBAC_ANALYSTS = <?php echo json_encode(array_map(fn($a) => ['id' => (int)$a['id'], 'name' => $a['full_name'] ?: $a['username'], 'is_admin' => (int)$a['is_admin']], $analysts), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        window.RBAC_TEAMS    = <?php echo json_encode(array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], $teams), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="../../assets/js/toast.js"></script>
    <script src="../../assets/js/confirm.js"></script>
    <script src="../../assets/js/system-roles.js?v=1"></script>
</body>
</html>
