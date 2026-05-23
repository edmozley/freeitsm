<?php
/**
 * Workflows Module Header Component
 *
 * Assumes the parent page has already loaded includes/i18n.php and called
 * I18n::initFromSession(). If not, t() falls back to returning the keys
 * verbatim — no fatal, just untranslated.
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'workflow';
$module_title = function_exists('t') ? t('workflow.title') : 'Workflows';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

require_once $path_prefix . 'includes/waffle-menu.php';
?>

<div class="header workflow-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo $path_prefix; ?>workflow/" class="nav-btn <?php echo $current_page === 'workflow' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('workflow.nav.workflows') : 'Workflows'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('workflow.nav.workflows') : 'Workflows'); ?></span>
        </a>
        <a href="<?php echo $path_prefix; ?>workflow/help.php" class="nav-btn <?php echo $current_page === 'help' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('workflow.nav.help') : 'Help'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('workflow.nav.help') : 'Help'); ?></span>
        </a>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>
