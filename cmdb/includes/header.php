<?php
/**
 * CMDB Module Header Component
 *
 * Required: $current_page variable should be set before including
 */

// Path prefix for navigation links (can be overridden by including page)
$path_prefix = $path_prefix ?? '../';
$current_module = 'cmdb';
$module_title = function_exists('t') ? t('cmdb.title') : 'CMDB';

// Ensure user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

// Include the shared waffle menu component
require_once $path_prefix . 'includes/waffle-menu.php';
?>

<div class="header cmdb-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo BASE_URL; ?>cmdb/" class="nav-btn <?php echo $current_page === 'browse' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.browse') : 'Browse'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 22V8l10-6 10 6v14"></path>
                <path d="M2 12h20"></path>
                <path d="M2 17h20"></path>
                <line x1="12" y1="2" x2="12" y2="22"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.browse') : 'Browse'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>cmdb/settings/" class="nav-btn <?php echo $current_page === 'settings' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.settings') : 'Settings'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.settings') : 'Settings'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>cmdb/help.php" class="nav-btn <?php echo $current_page === 'help' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.help') : 'Help'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.help') : 'Help'); ?></span>
        </a>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>

<style>
/* Per-analyst left-panel visibility (CMDB Settings -> Left panel, and
   System -> Preferences). 'hover' collapses .browse-sidebar to a 16px
   hot-zone that expands on hover; 'always' (default) leaves it pinned.
   Mirrors the knowledge / contracts pattern. Pref key: cmdb_sidebar_mode. */
.browse-container { position: relative; }
.browse-container.sidebar-hover .browse-sidebar {
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
.browse-container.sidebar-hover .browse-sidebar:hover {
    width: 260px;
    min-width: 260px;
    overflow-y: auto;
}
.browse-container.sidebar-hover .browse-sidebar > * {
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.12s ease 0s;
}
.browse-container.sidebar-hover .browse-sidebar:hover > * {
    opacity: 1;
    pointer-events: auto;
    transition-delay: 0.08s;
}
.browse-container.sidebar-hover .browse-sidebar::before {
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
.browse-container.sidebar-hover .browse-sidebar:hover::before { opacity: 0; }
</style>
<script>
(async function() {
    try {
        const r = await fetch('<?php echo BASE_URL; ?>api/system/get_user_preference.php?key=cmdb_sidebar_mode', { credentials: 'same-origin' });
        const d = await r.json();
        const mode = (d.success && d.value === 'hover') ? 'hover' : 'always';
        document.querySelectorAll('.browse-container').forEach(el => el.classList.toggle('sidebar-hover', mode === 'hover'));
    } catch (e) { /* no-op -- default is always-visible */ }
})();
</script>
