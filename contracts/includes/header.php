<?php
/**
 * Contracts Module Header Component
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'contracts';
$module_title = function_exists('t') ? t('contracts.title') : 'Contracts';

// Ensure user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

// Include the shared waffle menu component
require_once $path_prefix . 'includes/waffle-menu.php';

// Read the per-analyst left-panel preference SERVER-SIDE, so the collapsed state is on
// the page from the very first paint. Fetching it in JS after load made the 260px panel
// render visible and then fly shut on every navigation — the flash Ed reported. Mirrors
// the knowledge module, which fixed the same thing this way.
$contractsSidebarMode = 'always';
if (isset($_SESSION['analyst_id'])) {
    try {
        $__prefConn = connectToDatabase();
        $__prefStmt = $__prefConn->prepare(
            "SELECT preference_value FROM user_preferences WHERE analyst_id = ? AND preference_key = ? LIMIT 1"
        );
        $__prefStmt->execute([(int)$_SESSION['analyst_id'], 'contracts_sidebar_mode']);
        $__prefRow = $__prefStmt->fetch(PDO::FETCH_ASSOC);
        if ($__prefRow && $__prefRow['preference_value'] === 'hover') {
            $contractsSidebarMode = 'hover';
        }
    } catch (Exception $e) {
        // Non-fatal — fall through with the 'always' default.
    }
}
?>

<div class="header contracts-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo $module_title; ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo BASE_URL; ?>contracts/" class="nav-btn <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('contracts.nav.contracts') : 'Contracts'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <line x1="12" y1="9" x2="8" y2="9"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('contracts.nav.contracts') : 'Contracts'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>contracts/suppliers/" class="nav-btn <?php echo $current_page === 'suppliers' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('contracts.nav.suppliers') : 'Suppliers'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <polyline points="9 22 9 12 15 12 15 22"></polyline>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('contracts.nav.suppliers') : 'Suppliers'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>contracts/contacts/" class="nav-btn <?php echo $current_page === 'contacts' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('contracts.nav.contacts') : 'Contacts'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('contracts.nav.contacts') : 'Contacts'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>contracts/rfp-builder/" class="nav-btn <?php echo $current_page === 'rfp-builder' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('contracts.nav.rfp_builder') : 'RFP Builder'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <path d="M9 13h6"></path>
                <path d="M9 17h6"></path>
                <circle cx="9" cy="9" r="0.5" fill="currentColor"></circle>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('contracts.nav.rfp_builder') : 'RFP Builder'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>contracts/settings/" class="nav-btn <?php echo $current_page === 'settings' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('contracts.nav.settings') : 'Settings'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('contracts.nav.settings') : 'Settings'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>contracts/help.php" class="nav-btn <?php echo $current_page === 'help' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('contracts.nav.help') : 'Help'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('contracts.nav.help') : 'Help'); ?></span>
        </a>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>

<style>
/* Per-analyst sidebar hover mode (Settings → Left panel). Applied to every
   contracts page that uses .contracts-layout. Mirrors the knowledge /
   process-mapper pattern. */
.contracts-layout { position: relative; }
.contracts-sidebar-hover .contracts-layout .contracts-sidebar {
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
.contracts-sidebar-hover .contracts-layout .contracts-sidebar:hover {
    width: 260px;
    padding: 20px;
    overflow-y: auto;
}
.contracts-sidebar-hover .contracts-layout .contracts-sidebar > * {
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.12s ease 0s;
}
.contracts-sidebar-hover .contracts-layout .contracts-sidebar:hover > * {
    opacity: 1;
    pointer-events: auto;
    transition-delay: 0.08s;
}
.contracts-sidebar-hover .contracts-layout .contracts-sidebar::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 6px;
    transform: translateY(-50%);
    width: 3px;
    height: 36px;
    border-radius: 2px;
    background: var(--border, #bbb);
    transition: opacity 0.18s;
    pointer-events: none;
}
.contracts-sidebar-hover .contracts-layout .contracts-sidebar:hover::before { opacity: 0; }
</style>
<?php if ($contractsSidebarMode === 'hover'): ?>
<?php /* Synchronous + server-resolved: the class lands on <html> before the parser
         reaches the sidebar, so it paints collapsed from the start — no fetch latency,
         no fly-shut animation. The CSS above keys off this ancestor class. */ ?>
<script>document.documentElement.classList.add('contracts-sidebar-hover');</script>
<?php endif; ?>
