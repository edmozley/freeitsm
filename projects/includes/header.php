<?php
/**
 * Projects module header - the waffle, the module title, the nav, the avatar.
 *
 * Every URL here is built from BASE_URL, never a relative "../", so a page at
 * any depth links correctly. Mirrors domains/includes/header.php.
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'projects';
$module_title = t('projects.title');

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

require_once $path_prefix . 'includes/waffle-menu.php';

$prjNav = [
    'portfolio' => ['projects/',         t('projects.nav.portfolio'), '<rect x="3" y="4" width="10" height="4" rx="1"></rect><rect x="7" y="10" width="11" height="4" rx="1"></rect><rect x="11" y="16" width="10" height="4" rx="1"></rect>'],
    'help'      => ['projects/help.php', t('projects.nav.help'),      '<circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line>'],
];
?>

<div class="header projects-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <?php foreach ($prjNav as $key => [$href, $label, $icon]): ?>
        <a href="<?php echo BASE_URL . $href; ?>" class="nav-btn <?php echo $current_page === $key ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($label); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $icon; ?></svg>
            <span><?php echo htmlspecialchars($label); ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>
<script>window.PRJ_API = <?php echo json_encode(BASE_URL . 'api/projects/'); ?>; window.PRJ_BASE = <?php echo json_encode(BASE_URL); ?>; window.PRJ_ME = <?php echo (int)($_SESSION['analyst_id'] ?? 0); ?>;</script>
