<?php
/**
 * Service Status Module Header Component
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'service-status';
$module_title = 'Service Status';

// Ensure user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

// Include the shared waffle menu component
require_once $path_prefix . 'includes/waffle-menu.php';
?>

<div class="header service-status-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo $module_title; ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo $path_prefix; ?>service-status/" class="nav-btn <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>" title="Status">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
            </svg>
            <span>Status</span>
        </a>
        <a href="<?php echo $path_prefix; ?>service-status/settings/" class="nav-btn <?php echo $current_page === 'settings' ? 'active' : ''; ?>" title="Settings">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span>Settings</span>
        </a>
        <a href="<?php echo $path_prefix; ?>service-status/help.php" class="nav-btn <?php echo $current_page === 'help' ? 'active' : ''; ?>" title="Help">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span>Help</span>
        </a>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>
