<?php
/**
 * Change Management Module Header Component
 *
 * Required: $current_page variable should be set before including
 */

// Path prefix for navigation links (can be overridden by including page)
$path_prefix = $path_prefix ?? '../';
$current_module = 'changes';
$module_title = function_exists('t') ? t('change-management.title') : 'Change Management';

// Ensure user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

// Include the shared waffle menu component. __DIR__ here is
// change-management/includes/ — so __DIR__/../../includes/waffle-menu.php
// resolves to the shared /includes/waffle-menu.php regardless of which
// entry script included us (e.g. /change-management/new/index.php).
require_once __DIR__ . '/../../includes/waffle-menu.php';
?>

<div class="header changes-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo BASE_URL; ?>change-management/" class="nav-btn <?php echo $current_page === 'changes' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('change-management.nav.changes') : 'Changes'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="16 3 21 3 21 8"></polyline>
                <line x1="4" y1="20" x2="21" y2="3"></line>
                <polyline points="21 16 21 21 16 21"></polyline>
                <line x1="15" y1="15" x2="21" y2="21"></line>
                <line x1="4" y1="4" x2="9" y2="9"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('change-management.nav.changes') : 'Changes'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>change-management/calendar.php" class="nav-btn <?php echo $current_page === 'calendar' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('change-management.nav.calendar') : 'Calendar'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('change-management.nav.calendar') : 'Calendar'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>change-management/approvals.php" class="nav-btn <?php echo $current_page === 'approvals' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('change-management.nav.approvals') : 'Approvals'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('change-management.nav.approvals') : 'Approvals'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>change-management/table.php" class="nav-btn <?php echo $current_page === 'table' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('change-management.nav.table') : 'Table'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                <line x1="3" y1="9" x2="21" y2="9"></line>
                <line x1="3" y1="15" x2="21" y2="15"></line>
                <line x1="9" y1="3" x2="9" y2="21"></line>
                <line x1="15" y1="3" x2="15" y2="21"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('change-management.nav.table') : 'Table'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>change-management/settings/" class="nav-btn <?php echo $current_page === 'settings' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('change-management.nav.settings') : 'Settings'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('change-management.nav.settings') : 'Settings'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>change-management/help.php" class="nav-btn <?php echo $current_page === 'help' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('change-management.nav.help') : 'Help'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('change-management.nav.help') : 'Help'); ?></span>
        </a>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>

<style>
/* Per-analyst left-panel visibility (Change Management Settings -> Left panel,
   and System -> Preferences). 'hover' collapses .changes-sidebar to a 16px
   hot-zone that expands on hover; 'always' (default) leaves it pinned.
   Mirrors the knowledge / contracts pattern. Pref key: change_management_sidebar_mode. */
.changes-container { position: relative; }
.changes-container.sidebar-hover .changes-sidebar {
    position: absolute;
    top: 0; left: 0; bottom: 0;
    width: 16px;
    min-width: 16px;
    z-index: 10;
    overflow: hidden;
    transition: width 0.18s ease;
    box-shadow: 2px 0 8px rgba(0, 0, 0, 0.12);
    padding: 0;
}
.changes-container.sidebar-hover .changes-sidebar:hover {
    width: 280px;
    min-width: 280px;
    padding: 20px;
    overflow-y: auto;
}
.changes-container.sidebar-hover .changes-sidebar > * {
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.12s ease 0s;
}
.changes-container.sidebar-hover .changes-sidebar:hover > * {
    opacity: 1;
    pointer-events: auto;
    transition-delay: 0.08s;
}
.changes-container.sidebar-hover .changes-sidebar::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 6px;
    transform: translateY(-50%);
    width: 3px;
    height: 36px;
    border-radius: 2px;
    background: #bbb;
    transition: opacity 0.18s;
    pointer-events: none;
}
.changes-container.sidebar-hover .changes-sidebar:hover::before { opacity: 0; }
</style>
<script>
(async function() {
    try {
        const r = await fetch('<?php echo BASE_URL; ?>api/system/get_user_preference.php?key=change_management_sidebar_mode', { credentials: 'same-origin' });
        const d = await r.json();
        const mode = (d.success && d.value === 'hover') ? 'hover' : 'always';
        document.querySelectorAll('.changes-container').forEach(el => el.classList.toggle('sidebar-hover', mode === 'hover'));
    } catch (e) { /* no-op -- default is always-visible */ }
})();
</script>
