<?php
/**
 * Asset Management Module Header Component
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'assets';
$module_title = function_exists('t') ? t('asset-management.title') : 'Assets';

// Ensure user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

// Include the shared waffle menu component
require_once $path_prefix . 'includes/waffle-menu.php';
?>

<div class="header assets-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo BASE_URL; ?>asset-management/" class="nav-btn <?php echo $current_page === 'assets' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.assets') : 'Assets'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                <line x1="8" y1="21" x2="16" y2="21"></line>
                <line x1="12" y1="17" x2="12" y2="21"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.assets') : 'Assets'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>asset-management/table.php" class="nav-btn <?php echo $current_page === 'table' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.table') : 'Table view'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="3" y1="9" x2="21" y2="9"></line>
                <line x1="3" y1="15" x2="21" y2="15"></line>
                <line x1="9" y1="3" x2="9" y2="21"></line>
                <line x1="15" y1="3" x2="15" y2="21"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.table') : 'Table'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>asset-management/users.php" class="nav-btn <?php echo $current_page === 'users' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.users') : 'Users'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.users') : 'Users'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>asset-management/dashboard/" class="nav-btn <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.dashboard') : 'Dashboard'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="3" y1="9" x2="21" y2="9"></line>
                <line x1="9" y1="21" x2="9" y2="9"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.dashboard') : 'Dashboard'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>asset-management/servers/" class="nav-btn <?php echo $current_page === 'servers' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.servers') : 'Servers'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                <line x1="6" y1="6" x2="6.01" y2="6"></line>
                <line x1="6" y1="18" x2="6.01" y2="18"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.servers') : 'Servers'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>asset-management/leases/" class="nav-btn <?php echo $current_page === 'leases' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.leases') : 'Leases'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <circle cx="12" cy="15" r="3"></circle>
                <polyline points="12 13.5 12 15 13 16"></polyline>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.leases') : 'Leases'); ?></span>
        </a>
        <?php /* Consumables (5.8.0): the things you count - toner, paper, mice. */ ?>
        <a href="<?php echo BASE_URL; ?>asset-management/consumables/" class="nav-btn <?php echo $current_page === 'consumables' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.consumables') : 'Consumables'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 8l-9-5-9 5 9 5 9-5z"></path>
                <path d="M3 8v8l9 5 9-5V8"></path>
                <line x1="12" y1="13" x2="12" y2="21"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.consumables') : 'Consumables'); ?></span>
        </a>
        <?php
        // GH #404: Missing information - only once some asset type expects something, so an
        // install that does not use it sees no new button. One indexed LIMIT 1 lookup.
        require_once __DIR__ . '/../../includes/services/asset_completeness.php';
        $showMissing = $current_page === 'missing';
        if (!$showMissing) { try { $showMissing = AssetCompleteness::anyExpected(connectToDatabase()); } catch (Throwable $e) { $showMissing = false; } }
        if ($showMissing): ?>
        <a href="<?php echo BASE_URL; ?>asset-management/missing/" class="nav-btn <?php echo $current_page === 'missing' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.missing') : 'Missing information'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 11l3 3L22 4"></path>
                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.missing') : 'Missing information'); ?></span>
        </a>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>asset-management/settings/" class="nav-btn <?php echo $current_page === 'settings' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.settings') : 'Settings'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.settings') : 'Settings'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>asset-management/help.php" class="nav-btn <?php echo $current_page === 'help' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.help') : 'Help'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('asset-management.nav.help') : 'Help'); ?></span>
        </a>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>

<style>
/* Per-analyst left-panel visibility (Asset Management Settings -> Left panel,
   and System -> Preferences). 'hover' collapses the asset list to a 16px
   hot-zone that expands on hover; 'always' (default) leaves it pinned.
   Mirrors the knowledge / contracts pattern. Pref key: asset_management_sidebar_mode. */
.assets-container { position: relative; }
.assets-container.sidebar-hover .assets-list-container {
    position: absolute;
    top: 0; left: 0; bottom: 0;
    width: 16px;
    min-width: 16px;
    z-index: 10;
    overflow: hidden;
    transition: width 0.18s ease;
    box-shadow: 2px 0 8px var(--shadow, rgba(0, 0, 0, 0.12));
}
.assets-container.sidebar-hover .assets-list-container:hover {
    width: 400px;
    min-width: 400px;
    overflow: hidden;
}
.assets-container.sidebar-hover .assets-list-container > * {
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.12s ease 0s;
}
.assets-container.sidebar-hover .assets-list-container:hover > * {
    opacity: 1;
    pointer-events: auto;
    transition-delay: 0.08s;
}
.assets-container.sidebar-hover .assets-list-container::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 6px;
    transform: translateY(-50%);
    width: 3px;
    height: 36px;
    border-radius: 2px;
    background: var(--text-faint, #bbb);
    transition: opacity 0.18s;
    pointer-events: none;
    z-index: 1;
}
.assets-container.sidebar-hover .assets-list-container:hover::before { opacity: 0; }
</style>
<script>
(async function() {
    try {
        const r = await fetch('<?php echo BASE_URL; ?>api/system/get_user_preference.php?key=asset_management_sidebar_mode', { credentials: 'same-origin' });
        const d = await r.json();
        const mode = (d.success && d.value === 'hover') ? 'hover' : 'always';
        document.querySelectorAll('.assets-container').forEach(el => el.classList.toggle('sidebar-hover', mode === 'hover'));
    } catch (e) { /* no-op -- default is always-visible */ }
})();
</script>
