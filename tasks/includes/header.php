<?php
/**
 * Tasks Module Header Component
 *
 * Required: $current_page variable should be set before including
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'tasks';
$module_title = function_exists('t') ? t('tasks.title') : 'Tasks';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

// Translated nav label, falling back to the literal if i18n isn't loaded
$navLabel = function ($key, $fallback) {
    return function_exists('t') ? t('tasks.nav.' . $key) : $fallback;
};

require_once $path_prefix . 'includes/waffle-menu.php';
?>

<div class="header tasks-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo $path_prefix; ?>tasks/" class="nav-btn <?php echo $current_page === 'board' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($navLabel('board', 'Board')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="7" height="7"></rect>
                <rect x="14" y="3" width="7" height="7"></rect>
                <rect x="3" y="14" width="7" height="7"></rect>
                <rect x="14" y="14" width="7" height="7"></rect>
            </svg>
            <span><?php echo htmlspecialchars($navLabel('board', 'Board')); ?></span>
        </a>
        <a href="<?php echo $path_prefix; ?>tasks/calendar/" class="nav-btn <?php echo $current_page === 'calendar' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($navLabel('calendar', 'Calendar')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
            <span><?php echo htmlspecialchars($navLabel('calendar', 'Calendar')); ?></span>
        </a>
        <a href="<?php echo $path_prefix; ?>tasks/timeline/" class="nav-btn <?php echo $current_page === 'timeline' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($navLabel('timeline', 'Timeline')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="3" y1="6" x2="14" y2="6"></line>
                <line x1="8" y1="12" x2="21" y2="12"></line>
                <line x1="5" y1="18" x2="16" y2="18"></line>
            </svg>
            <span><?php echo htmlspecialchars($navLabel('timeline', 'Timeline')); ?></span>
        </a>
        <a href="<?php echo $path_prefix; ?>tasks/settings/" class="nav-btn <?php echo $current_page === 'settings' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($navLabel('settings', 'Settings')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span><?php echo htmlspecialchars($navLabel('settings', 'Settings')); ?></span>
        </a>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>
