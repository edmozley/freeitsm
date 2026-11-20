<?php
/**
 * Checklists settings - categories, suggested roles and the left-panel preference.
 *
 * Categories and roles are changed through api/checklists/save_lookup.php and
 * delete_lookup.php; only the sidebar mode posts back to this page (saved as
 * user_preferences.checklists_sidebar_mode).
 * @wiki Checklists-and-SOPs-Developer-Guide
 */
ini_set('display_errors', 0);
session_start();

$path_prefix = '../../';
$current_page = 'settings';

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/theme.php';
require_once __DIR__ . '/../../includes/timezone.php';
require_once __DIR__ . '/../../includes/settings_manifest.php';

I18n::initFromSession();
Tz::init();
requireModuleAccess('checklists');

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$conn = connectToDatabase();

// Schema: database/freeitsm.sql + includes/db_verify_schema.php only.

// Categories and roles are added, renamed and deleted through
// api/checklists/{save,delete}_lookup.php so the screen can use the house
// confirm dialogue and toasts instead of a full page round-trip. Only the
// sidebar preference still posts to itself - it is a radio group with a Save
// button and has nothing to confirm.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_sidebar_mode') {
        $mode = ($_POST['mode'] ?? '') === 'hover' ? 'hover' : 'always';
        $stmt = $conn->prepare("INSERT INTO user_preferences (analyst_id, preference_key, preference_value) VALUES (?, 'checklists_sidebar_mode', ?) ON DUPLICATE KEY UPDATE preference_value = ?");
        $stmt->execute([(int)$_SESSION['analyst_id'], $mode, $mode]);
        header('Location: ' . BASE_URL . 'checklists/settings/?tab=layout');
        exit;
    }
}

// Current sidebar preference
$sidebarMode = 'always';
try {
    $prefStmt = $conn->prepare("SELECT preference_value FROM user_preferences WHERE analyst_id = ? AND preference_key = 'checklists_sidebar_mode' LIMIT 1");
    $prefStmt->execute([(int)$_SESSION['analyst_id']]);
    $row = $prefStmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['preference_value'] === 'hover') {
        $sidebarMode = 'hover';
    }
} catch (Throwable $e) {}

// Categories, with how many templates use each. One grouped query rather than a
// COUNT per row - the original issued 1 + N queries to render a list that is
// usually short but need not be.
$categories = $conn->query(
    "SELECT c.id, c.name, COUNT(t.id) AS template_count
       FROM checklist_categories c
       LEFT JOIN checklist_templates t ON t.category = c.name
      GROUP BY c.id, c.name
      ORDER BY c.name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Suggested roles, with how many template steps name each. Same shape, and the
// default eight are seeded by Database Verification, not from this render.
$roles = $conn->query(
    "SELECT r.id, r.name, COUNT(i.id) AS step_count
       FROM checklist_roles r
       LEFT JOIN checklist_template_items i ON i.suggested_role = r.name
      GROUP BY r.id, r.name
      ORDER BY r.name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// The tabs, drawn by the shared renderSettingsTabBar() like every other settings page
// (so the page gets the Tabs / One page switch, GH #399). There is deliberately NO
// checklists/settings/manifest.php: manifests are discovered by glob and each one adds a
// module to System -> Roles. Every tab here has always been open to anyone with module
// access, so each is declared 'cap' => null - exactly today's behaviour. Gating them is
// a separate decision, and would be made by moving this list into a manifest.
$chkTabs = [
    ['id' => 'categories', 'cap' => null, 'label_key' => 'checklists.settings.tab_categories'],
    ['id' => 'roles',      'cap' => null, 'label_key' => 'checklists.settings.tab_roles'],
    ['id' => 'layout',     'cap' => null, 'label_key' => 'checklists.settings.tab_layout'],
];
$activeTab = $_GET['tab'] ?? 'categories';
if (!settingsTabVisible($chkTabs, $activeTab)) {
    $activeTab = 'categories';
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('checklists', t('checklists.nav.settings'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=29">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=97">
    <style>
        /* Full-width settings shell — the same one tickets/settings and LMS use.
         * ⚠️ Dropping max-width alone is NOT enough: inbox.css sets
         * `.container { margin: 30px auto }`, and an auto cross-axis margin
         * inside a flex column cancels the stretch, so the page keeps its old
         * gutters and reads exactly as though the cap were still there. The
         * margin has to be reset too. (reference_full_width_settings_pages) */
        html, body { height: auto !important; min-height: 100vh; overflow-y: auto !important; overflow-x: hidden; margin: 0; padding: 0; background: var(--app-bg, #f8fafc); }
        .settings-shell { display: flex; flex-direction: column; min-height: 100vh; }
        .container {
            flex: 1 1 auto; min-height: 0;
            max-width: none; width: 100%; margin: 0;
            box-sizing: border-box; padding: 24px 32px 40px;
        }
        /* The shared tab bar (inbox.css .tabs/.tab) in the module's teal. Each panel already
           holds its own .settings-card, so the shared .tab-content card look is switched off. */
        body { --accent: var(--checklists-accent, #0d9488); }
        .tab-content { background: none; padding: 0; box-shadow: none; border-radius: 0; }
        .settings-card { background: var(--surface, #fff); border: 1px solid var(--border-soft, #e2e8f0); border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .table th, .table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid var(--border-soft, #e2e8f0); font-size: var(--font-size-md, 13px); color: var(--text, #1e293b); }
        .table th { font-weight: 600; color: var(--text-muted, #64748b); background: var(--app-bg, #f8fafc); }
        .btn-teal { background: #0d9488; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; font-size: var(--font-size-md, 13px); font-weight: 600; cursor: pointer; }
        .btn-teal:hover { background: #0f766e; }
        .row-actions { display: flex; gap: 4px; justify-content: flex-end; }
        /* Row action buttons — copied from tickets/settings rather than
         * approximated, so the two screens are the same control and not two
         * things that merely resemble each other. */
        .action-btn {
            background: none;
            border: 1px solid var(--border, #ddd);
            color: var(--text-muted, #666);
            cursor: pointer;
            padding: 6px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .action-btn:hover {
            background: var(--surface-hover, #f0f0f0);
            border-color: var(--accent, #0078d4);
            color: var(--accent, #0078d4);
        }
        .action-btn.delete { color: var(--danger-accent, #d13438); }
        .action-btn.delete:hover {
            background: var(--danger-bg, #fdf3f3);
            border-color: var(--danger-accent, #d13438);
            color: var(--danger-text, #a00);
        }
        .action-btn svg { width: 16px; height: 16px; }
        /* Rename dialogue — the same shape the module's template modal uses, so
         * the two look like one product. */
        .lk-modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
        .lk-modal-backdrop.open { display: flex; }
        .lk-modal { background: var(--surface, #fff); width: 100%; max-width: 440px; border-radius: 10px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: hidden; }
        .lk-modal-header { padding: 18px 24px; border-bottom: 1px solid var(--border-soft, #e2e8f0); display: flex; justify-content: space-between; align-items: center; }
        .lk-modal-header h3 { margin: 0; font-size: var(--font-size-xl, 16px); font-weight: 700; color: var(--text, #0f172a); }
        .lk-modal-body { padding: 20px 24px; display: flex; flex-direction: column; gap: 6px; }
        .lk-modal-body label { font-size: var(--font-size-sm, 12px); font-weight: 600; color: var(--text-muted, #64748b); text-transform: uppercase; }
        .lk-modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-soft, #e2e8f0); display: flex; justify-content: flex-end; gap: 10px; background: var(--app-bg, #f8fafc); }
        .lk-name-input { width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid var(--border-soft, #cbd5e1); border-radius: 6px; font-size: var(--font-size-md, 13px); background: var(--surface, #fff); color: var(--text, #1e293b); }
        .lk-modal-x { background: none; border: none; font-size: 22px; line-height: 1; cursor: pointer; color: var(--text-muted, #64748b); }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-page="checklists-settings" data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>" class="settings-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="container">
        <div style="margin-bottom: 20px;">
            <h1 style="font-size: 22px; font-weight: 700; color: var(--text, #0f172a); margin: 0 0 6px 0;"><?php echo htmlspecialchars(t('checklists.settings.heading')); ?></h1>
            <p style="font-size: var(--font-size-md, 13px); color: var(--text-muted, #64748b); margin: 0;"><?php echo htmlspecialchars(t('checklists.settings.heading_sub')); ?></p>
        </div>

        <?php renderSettingsTabBar($chkTabs, $activeTab); ?>

        <!-- Tab 1: Categories -->
        <div id="categories-tab" class="tab-content<?php echo $activeTab === 'categories' ? ' active' : ''; ?>">
            <div class="settings-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <div>
                        <h3 style="margin: 0 0 4px 0; font-size: var(--font-size-xl, 16px); font-weight: 600; color: var(--text, #0f172a);"><?php echo htmlspecialchars(t('checklists.settings.categories')); ?></h3>
                        <p style="margin: 0; font-size: var(--font-size-md, 13px); color: var(--text-muted, #64748b);"><?php echo htmlspecialchars(t('checklists.settings.categories_sub')); ?></p>
                    </div>
                    <button type="button" class="btn-teal" onclick="lkOpenAdd('category')"><?php echo htmlspecialchars(t('checklists.settings.add')); ?></button>
                </div>

                <table class="table">
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('checklists.settings.col_category')); ?></th>
                            <th><?php echo htmlspecialchars(t('checklists.settings.col_templates')); ?></th>
                            <th style="width: 110px; text-align: right;"><?php echo htmlspecialchars(t('checklists.settings.col_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="lkCategoryList" data-kind="category">
                        <?php if (empty($categories)): ?>
                            <tr><td colspan="3" style="text-align: center; color: var(--text-muted, #64748b);"><?php echo htmlspecialchars(t('checklists.settings.categories_empty')); ?></td></tr>
                        <?php else: foreach ($categories as $c): ?>
                            <tr data-id="<?php echo (int)$c['id']; ?>" data-name="<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>">
                                <td class="lk-name" style="font-weight: 600;"><?php echo htmlspecialchars($c['name']); ?></td>
                                <td><?php echo (int)($c['template_count'] ?? 0); ?></td>
                                <td>
                                    <div class="row-actions">
                                        <button type="button" class="action-btn lk-edit" title="<?php echo htmlspecialchars(t('checklists.settings.edit')); ?>" aria-label="<?php echo htmlspecialchars(t('checklists.settings.edit')); ?>"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></button>
                                        <button type="button" class="action-btn delete lk-del" title="<?php echo htmlspecialchars(t('checklists.settings.delete')); ?>" aria-label="<?php echo htmlspecialchars(t('checklists.settings.delete')); ?>"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab 2: Suggested Roles -->
        <div id="roles-tab" class="tab-content<?php echo $activeTab === 'roles' ? ' active' : ''; ?>">
            <div class="settings-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <div>
                        <h3 style="margin: 0 0 4px 0; font-size: var(--font-size-xl, 16px); font-weight: 600; color: var(--text, #0f172a);"><?php echo htmlspecialchars(t('checklists.settings.roles')); ?></h3>
                        <p style="margin: 0; font-size: var(--font-size-md, 13px); color: var(--text-muted, #64748b);"><?php echo htmlspecialchars(t('checklists.settings.roles_sub')); ?></p>
                    </div>
                    <button type="button" class="btn-teal" onclick="lkOpenAdd('role')"><?php echo htmlspecialchars(t('checklists.settings.add')); ?></button>
                </div>

                <table class="table">
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('checklists.settings.col_role')); ?></th>
                            <th><?php echo htmlspecialchars(t('checklists.settings.col_steps_using')); ?></th>
                            <th style="width: 110px; text-align: right;"><?php echo htmlspecialchars(t('checklists.settings.col_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="lkRoleList" data-kind="role">
                        <?php if (empty($roles)): ?>
                            <tr><td colspan="3" style="text-align: center; color: var(--text-muted, #64748b);"><?php echo htmlspecialchars(t('checklists.settings.roles_empty')); ?></td></tr>
                        <?php else: foreach ($roles as $r): ?>
                            <tr data-id="<?php echo (int)$r['id']; ?>" data-name="<?php echo htmlspecialchars($r['name'], ENT_QUOTES); ?>">
                                <td class="lk-name" style="font-weight: 600;"><?php echo htmlspecialchars($r['name']); ?></td>
                                <td><?php echo (int)($r['step_count'] ?? 0); ?></td>
                                <td>
                                    <div class="row-actions">
                                        <button type="button" class="action-btn lk-edit" title="<?php echo htmlspecialchars(t('checklists.settings.edit')); ?>" aria-label="<?php echo htmlspecialchars(t('checklists.settings.edit')); ?>"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></button>
                                        <button type="button" class="action-btn delete lk-del" title="<?php echo htmlspecialchars(t('checklists.settings.delete')); ?>" aria-label="<?php echo htmlspecialchars(t('checklists.settings.delete')); ?>"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab 3: Left Panel (Hover Mode) -->
        <div id="layout-tab" class="tab-content<?php echo $activeTab === 'layout' ? ' active' : ''; ?>">
            <div class="settings-card">
                <h3 style="margin: 0 0 6px 0; font-size: var(--font-size-xl, 16px); font-weight: 600; color: var(--text, #0f172a);"><?php echo htmlspecialchars(t('checklists.settings.layout_heading')); ?></h3>
                <p style="margin: 0 0 20px 0; font-size: var(--font-size-md, 13px); color: var(--text-muted, #64748b);"><?php echo htmlspecialchars(t('checklists.settings.layout_sub')); ?></p>

                <form method="POST">
                    <input type="hidden" name="action" value="save_sidebar_mode">
                    <div style="display: flex; flex-direction: column; gap: 14px;">
                        <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                            <input type="radio" name="mode" value="always" <?php echo $sidebarMode === 'always' ? 'checked' : ''; ?> style="margin-top: 3px;">
                            <div>
                                <div style="font-size: var(--font-size-lg, 14px); font-weight: 600; color: var(--text, #0f172a);"><?php echo htmlspecialchars(t('checklists.settings.layout_pinned')); ?></div>
                                <div style="font-size: var(--font-size-sm, 12px); color: var(--text-muted, #64748b);"><?php echo htmlspecialchars(t('checklists.settings.layout_pinned_desc')); ?></div>
                            </div>
                        </label>

                        <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                            <input type="radio" name="mode" value="hover" <?php echo $sidebarMode === 'hover' ? 'checked' : ''; ?> style="margin-top: 3px;">
                            <div>
                                <div style="font-size: var(--font-size-lg, 14px); font-weight: 600; color: var(--text, #0f172a);"><?php echo htmlspecialchars(t('checklists.settings.layout_hover')); ?></div>
                                <div style="font-size: var(--font-size-sm, 12px); color: var(--text-muted, #64748b);"><?php echo htmlspecialchars(t('checklists.settings.layout_hover_desc')); ?></div>
                            </div>
                        </label>
                    </div>

                    <div style="margin-top: 24px;">
                        <button type="submit" class="btn-teal"><?php echo htmlspecialchars(t('checklists.settings.save')); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Rename dialogue, shared by both tables -->
    <div class="lk-modal-backdrop" id="lkRenameBackdrop">
        <div class="lk-modal" role="dialog" aria-modal="true" aria-labelledby="lkRenameTitle">
            <div class="lk-modal-header">
                <h3 id="lkRenameTitle"><?php echo htmlspecialchars(t('checklists.settings.rename')); ?></h3>
                <button type="button" class="lk-modal-x" onclick="lkCloseRename()" aria-label="Close">&times;</button>
            </div>
            <div class="lk-modal-body">
                <label for="lkRenameInput"><?php echo htmlspecialchars(t('checklists.settings.name')); ?></label>
                <input type="text" id="lkRenameInput" class="lk-name-input" maxlength="100">
            </div>
            <div class="lk-modal-footer">
                <button type="button" onclick="lkCloseRename()" style="padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-soft, #cbd5e1); background: var(--surface, #fff); color: var(--text, #334155); cursor: pointer; font-weight: 500; font-size: var(--font-size-md, 13px);"><?php echo htmlspecialchars(t('checklists.settings.cancel')); ?></button>
                <button type="button" class="btn-teal" id="lkRenameSave"><?php echo htmlspecialchars(t('checklists.settings.save')); ?></button>
            </div>
        </div>
    </div>

    <script>
        // ⚠️ Every settings page must define its own switchTab — the shared tab
        // bar emits onclick="switchTab('…')" and provides no handler. A page that
        // forgets it renders perfectly and its tabs silently do nothing.
        function switchTab(tab) {
            document.querySelectorAll('.tabs .tab[data-tab]').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
            document.querySelectorAll('.tab-content').forEach(p => p.classList.toggle('active', p.id === tab + '-tab'));
            if (history.replaceState) {
                history.replaceState(null, '', '?tab=' + tab);
            }
        }

        const CHK_API = '<?php echo BASE_URL; ?>api/checklists/';

        async function lkPost(file, payload) {
            try {
                const r = await fetch(CHK_API + file, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                return await r.json();
            } catch (e) {
                return { success: false, error: 'Could not reach the server' };
            }
        }

        // ── Delete and rename. Delegated, so a re-render never loses them. ──
        ['lkCategoryList', 'lkRoleList'].forEach(function (id) {
            const tbody = document.getElementById(id);
            if (!tbody) return;
            const kind = tbody.dataset.kind;

            tbody.addEventListener('click', async function (e) {
                const tr = e.target.closest('tr[data-id]');
                if (!tr) return;
                const rowId = parseInt(tr.dataset.id, 10);
                const name  = tr.dataset.name;

                // 🔴 closest(), NOT e.target.classList. These buttons hold an
                // inline <svg>, so the click lands on the svg or its <path> and
                // never on the button — a class test silently does nothing.
                if (e.target.closest('.lk-del')) {
                    const ok = await showConfirm({
                        title: 'Delete',
                        message: 'Delete "' + name + '"? Templates already using it keep the name — only the list entry goes.',
                        okLabel: 'Delete', okClass: 'danger'
                    });
                    if (!ok) return;
                    const d = await lkPost('delete_lookup.php', { kind: kind, id: rowId });
                    showToast(d.success ? 'Deleted' : (d.error || 'Could not delete'), d.success ? 'success' : 'error');
                    if (d.success) tr.remove();
                    return;
                }

                if (e.target.closest('.lk-edit')) {
                    lkOpenRename(kind, rowId, name, tr);
                }
            });
        });

        // ── Add / rename dialogue ───────────────────────────────────────────
        // One modal for both. Adding and renaming differ only in whether an id
        // goes with the name, so two dialogues would be two things to keep alike.
        let lkTarget = null;   // { kind, id|null, tr|null }

        function lkNoun(kind) { return kind === 'category' ? 'category' : 'role'; }

        function lkOpenModal(title, value) {
            document.getElementById('lkRenameTitle').textContent = title;
            const input = document.getElementById('lkRenameInput');
            input.value = value;
            document.getElementById('lkRenameBackdrop').classList.add('open');
            input.focus();
            input.select();
        }

        function lkOpenAdd(kind) {
            lkTarget = { kind: kind, id: null, tr: null };
            lkOpenModal('New ' + lkNoun(kind), '');
        }

        function lkOpenRename(kind, id, name, tr) {
            lkTarget = { kind: kind, id: id, tr: tr };
            lkOpenModal('Rename ' + lkNoun(kind), name);
        }

        function lkCloseRename() {
            document.getElementById('lkRenameBackdrop').classList.remove('open');
            lkTarget = null;
        }

        async function lkSave() {
            if (!lkTarget) return;
            const { kind, id, tr } = lkTarget;
            const next = document.getElementById('lkRenameInput').value.trim();
            if (next === '') { showToast('A name is required', 'error'); return; }
            if (tr && next === tr.dataset.name) { lkCloseRename(); return; }

            const payload = { kind: kind, name: next };
            if (id) payload.id = id;
            const d = await lkPost('save_lookup.php', payload);
            // The endpoint explains WHY it refused (duplicate, too long), so show
            // its words and leave the dialogue open to be corrected.
            if (!d.success) { showToast(d.error || 'Could not save', 'error'); return; }

            if (tr) {
                tr.querySelector('.lk-name').textContent = next;
                tr.dataset.name = next;
                lkCloseRename();
                showToast('Renamed', 'success');
            } else {
                // A new row changes the counts and the empty-state row, so the
                // honest thing is to re-read the page rather than splice a row
                // that would claim a count nobody calculated.
                lkCloseRename();
                showToast('Saved', 'success');
                location.reload();
            }
        }

        document.getElementById('lkRenameSave').addEventListener('click', lkSave);
        document.getElementById('lkRenameInput').addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter')  { ev.preventDefault(); lkSave(); }
            if (ev.key === 'Escape') { ev.preventDefault(); lkCloseRename(); }
        });
        // Click the backdrop (not the dialogue) to dismiss, as the other modals do.
        document.getElementById('lkRenameBackdrop').addEventListener('click', function (ev) {
            if (ev.target === this) lkCloseRename();
        });
    </script>
    <script src="../../assets/js/mobile.js?v=87"></script>
</body>
</html>
