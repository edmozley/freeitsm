<?php
/**
 * Network Mapper Module Header Component.
 *
 * Standard pattern: waffle menu + module title + tab nav + user menu. Nav has
 * one entry for now (Diagrams). Help/Versions tabs may be added later.
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'network-mapper';
$module_title = 'Network Mapper';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

require_once $path_prefix . 'includes/waffle-menu.php';
?>

<div class="header network-mapper-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo $path_prefix; ?>network-mapper/" class="nav-btn <?php echo $current_page === 'diagrams' ? 'active' : ''; ?>" title="Diagrams">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="6" cy="6" r="2.5"></circle>
                <circle cx="18" cy="6" r="2.5"></circle>
                <circle cx="12" cy="18" r="2.5"></circle>
                <line x1="7.5" y1="7.5" x2="11" y2="16"></line>
                <line x1="16.5" y1="7.5" x2="13" y2="16"></line>
                <line x1="8.5" y1="6" x2="15.5" y2="6"></line>
            </svg>
            <span>Diagrams</span>
        </a>
        <a href="<?php echo $path_prefix; ?>network-mapper/help.php" class="nav-btn <?php echo $current_page === 'help' ? 'active' : ''; ?>" title="Help">
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
