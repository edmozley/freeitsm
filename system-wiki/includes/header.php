<?php
/**
 * System Wiki Module Header Component
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'wiki';
$module_title = function_exists('t') ? t('system-wiki.title') : 'System Wiki';

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

<div class="header wiki-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo BASE_URL; ?>system-wiki/" class="nav-btn <?php echo $current_page === 'browse' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('system-wiki.nav_title.browse') : 'Browse Files'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('system-wiki.nav.browse') : 'Browse'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>system-wiki/search.php" class="nav-btn <?php echo $current_page === 'search' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('system-wiki.nav_title.search') : 'Search'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('system-wiki.nav.search') : 'Search'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>system-wiki/tables.php" class="nav-btn <?php echo $current_page === 'tables' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('system-wiki.nav_title.tables') : 'Database Tables'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('system-wiki.nav.tables') : 'Tables'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>system-wiki/scan.php" class="nav-btn <?php echo $current_page === 'scan' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('system-wiki.nav_title.scan') : 'Scan Management'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="23 4 23 10 17 10"></polyline>
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('system-wiki.nav.scan') : 'Scan'); ?></span>
        </a>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>

<style>
/* Per-analyst left-panel visibility (set under System -> Preferences).
   'hover' collapses .wiki-sidebar to a 16px hot-zone that expands on hover;
   'always' (default) leaves it pinned. Mirrors the knowledge / contracts
   pattern. Pref key: system_wiki_sidebar_mode. */
.wiki-container { position: relative; }
.wiki-container.sidebar-hover .wiki-sidebar {
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
.wiki-container.sidebar-hover .wiki-sidebar:hover {
    width: 280px;
    min-width: 280px;
    padding: 12px 0;
    overflow-y: auto;
}
.wiki-container.sidebar-hover .wiki-sidebar > * {
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.12s ease 0s;
}
.wiki-container.sidebar-hover .wiki-sidebar:hover > * {
    opacity: 1;
    pointer-events: auto;
    transition-delay: 0.08s;
}
.wiki-container.sidebar-hover .wiki-sidebar::before {
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
.wiki-container.sidebar-hover .wiki-sidebar:hover::before { opacity: 0; }
</style>
<script>
(async function() {
    try {
        const r = await fetch('<?php echo BASE_URL; ?>api/system/get_user_preference.php?key=system_wiki_sidebar_mode', { credentials: 'same-origin' });
        const d = await r.json();
        const mode = (d.success && d.value === 'hover') ? 'hover' : 'always';
        document.querySelectorAll('.wiki-container').forEach(el => el.classList.toggle('sidebar-hover', mode === 'hover'));
    } catch (e) { /* no-op -- default is always-visible */ }
})();
</script>
