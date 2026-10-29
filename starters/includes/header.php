<?php
/**
 * Starters & leavers module header - the waffle, the module title, the nav, the avatar.
 *
 * Every URL here is built from BASE_URL, never a relative "../", so a page at
 * any depth links correctly. Mirrors projects/includes/header.php.
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'starters';
$module_title = t('starters.title');

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

require_once $path_prefix . 'includes/waffle-menu.php';

$jmlNav = [
    'cases'    => ['starters/',          t('starters.nav.cases'),    '<circle cx="8" cy="7" r="4"></circle><path d="M2 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"></path><path d="M16 11h6"></path><path d="M19 8l3 3-3 3"></path>'],
    'settings' => ['starters/settings/', t('starters.nav.settings'), '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>'],
    'help'     => ['starters/help.php',  t('starters.nav.help'),     '<circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line>'],
];
?>

<div class="header starters-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <?php foreach ($jmlNav as $key => [$href, $label, $icon]): ?>
        <a href="<?php echo BASE_URL . $href; ?>" class="nav-btn <?php echo $current_page === $key ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($label); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $icon; ?></svg>
            <span><?php echo htmlspecialchars($label); ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>
<script>window.JML_API = <?php echo json_encode(BASE_URL . 'api/starters/'); ?>; window.JML_BASE = <?php echo json_encode(BASE_URL); ?>; window.JML_ME = <?php echo (int)($_SESSION['analyst_id'] ?? 0); ?>;</script>
