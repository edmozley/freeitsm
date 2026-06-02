<?php
/**
 * System Module Header Component
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'system';
$module_title = function_exists('t') ? t('system.title') : 'System';

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

<div class="header system-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo $module_title; ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo BASE_URL; ?>system/encryption/" class="nav-btn <?php echo $current_page === 'encryption' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(t('system.nav.encryption')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
            </svg>
            <span><?php echo htmlspecialchars(t('system.nav.encryption')); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>system/modules/" class="nav-btn <?php echo $current_page === 'modules' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(t('system.landing.modules_title')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
            <span><?php echo htmlspecialchars(t('system.nav.modules')); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>system/db-verify/" class="nav-btn <?php echo $current_page === 'db-verify' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(t('system.landing.db_verify_title')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
            </svg>
            <span><?php echo htmlspecialchars(t('system.nav.db_verify')); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>system/colours/" class="nav-btn <?php echo $current_page === 'colours' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(t('system.nav.colours')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="13.5" cy="6.5" r="2.5"></circle>
                <circle cx="17.5" cy="10.5" r="2.5"></circle>
                <circle cx="8.5" cy="7.5" r="2.5"></circle>
                <circle cx="6.5" cy="12.5" r="2.5"></circle>
                <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"></path>
            </svg>
            <span><?php echo htmlspecialchars(t('system.nav.colours')); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>system/branding/" class="nav-btn <?php echo $current_page === 'branding' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(t('system.nav.branding')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 19l7-7 3 3-7 7-3-3z"></path>
                <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"></path>
                <path d="M2 2l7.586 7.586"></path>
                <circle cx="11" cy="11" r="2"></circle>
            </svg>
            <span><?php echo htmlspecialchars(t('system.nav.branding')); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>system/security/" class="nav-btn <?php echo $current_page === 'security' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(t('system.nav.security')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
            <span><?php echo htmlspecialchars(t('system.nav.security')); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>system/preferences/" class="nav-btn <?php echo $current_page === 'preferences' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(t('system.nav.preferences')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span><?php echo htmlspecialchars(t('system.nav.preferences')); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>system/demo-data/" class="nav-btn <?php echo $current_page === 'demo-data' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(t('system.nav.demo_data')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="7 10 12 15 17 10"></polyline>
                <line x1="12" y1="15" x2="12" y2="3"></line>
            </svg>
            <span><?php echo htmlspecialchars(t('system.nav.demo_data')); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>system/debug-tools/" class="nav-btn <?php echo $current_page === 'debug-tools' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(t('system.nav.debug_tools')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M8 2v4"></path>
                <path d="M16 2v4"></path>
                <rect x="3" y="6" width="18" height="15" rx="2"></rect>
                <path d="M3 13h18"></path>
                <path d="M9 17l2 2 4-4"></path>
            </svg>
            <span><?php echo htmlspecialchars(t('system.nav.debug_tools')); ?></span>
        </a>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>
