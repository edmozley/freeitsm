<?php
/**
 * People module header — the waffle, the module title, the nav, the avatar.
 * URLs are built from BASE_URL, so the same header works at any depth.
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'people';
$module_title = t('people.title');

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

// Suppliers are Contracts' records (#153 step 3): the tab is for analysts who can
// open Contracts. Worked out before the waffle, which reuses common names.
$pplShowSuppliers = analystCanAccessModule($conn ?? connectToDatabase(), (int)$_SESSION['analyst_id'], 'contracts');

require_once $path_prefix . 'includes/waffle-menu.php';

$peopleNav = [
    'people'    => ['people/',               t('people.nav.people'),    '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>'],
    'org'       => ['people/org.php',        t('people.nav.org'),       '<rect x="9" y="2" width="6" height="5" rx="1"></rect><rect x="2" y="17" width="6" height="5" rx="1"></rect><rect x="16" y="17" width="6" height="5" rx="1"></rect><path d="M12 7v5"></path><path d="M5 17v-3h14v3"></path>'],
    'companies' => ['people/companies.php',  t('people.nav.companies'), '<path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-4"></path><path d="M9 9v.01"></path><path d="M9 12v.01"></path><path d="M9 15v.01"></path><path d="M9 18v.01"></path>'],
    'suppliers' => ['people/suppliers.php',  t('people.nav.suppliers'), '<rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle>'],
    'help'      => ['people/help.php',       t('people.nav.help'),      '<circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line>'],
];
if (!$pplShowSuppliers) unset($peopleNav['suppliers']);
?>

<div class="header people-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <?php foreach ($peopleNav as $key => [$href, $label, $icon]): ?>
        <a href="<?php echo BASE_URL . $href; ?>" class="nav-btn <?php echo $current_page === $key ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($label); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $icon; ?></svg>
            <span><?php echo htmlspecialchars($label); ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>
