<?php
/**
 * The Checklists module header - waffle menu, Templates / Settings / Help navigation and the teal styling.
 *
 * Included by every Checklists page; expects $current_page and $path_prefix. Reads the
 * analyst's checklists_sidebar_mode preference into $checklistsSidebarMode ('always' or
 * 'hover') for the left-panel CSS that follows.
 * @wiki Checklists-and-SOPs-Developer-Guide
 */
$path_prefix = $path_prefix ?? '../';
$current_module = 'checklists';
$module_title = 'Checklists';
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}
$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? 'templates';

// Read per-analyst sidebar hover setting
$checklistsSidebarMode = 'always';
if (isset($_SESSION['analyst_id'])) {
    try {
        $__pConn = connectToDatabase();
        $__pStmt = $__pConn->prepare("SELECT preference_value FROM user_preferences WHERE analyst_id = ? AND preference_key = ? LIMIT 1");
        $__pStmt->execute([(int)$_SESSION['analyst_id'], 'checklists_sidebar_mode']);
        $__pRow = $__pStmt->fetch(PDO::FETCH_ASSOC);
        if ($__pRow && $__pRow['preference_value'] === 'hover') {
            $checklistsSidebarMode = 'hover';
        }
    } catch (Throwable) {}
}

require_once $path_prefix . 'includes/waffle-menu.php';

// Guarantee $modules is an array so renderWaffleMenuPanel never throws TypeError in PHP 8
global $modules;
if (!isset($modules) || !is_array($modules)) {
    require $path_prefix . 'includes/waffle-menu.php';
}
?>
<div class="header checklists-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo BASE_URL; ?>checklists/" class="nav-btn <?php echo $current_page === 'templates' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('checklists.nav.templates') : 'Templates'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
                <rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
                <polyline points="9 14 11 16 15 12"></polyline>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('checklists.nav.templates') : 'Templates'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>checklists/settings/" class="nav-btn <?php echo $current_page === 'settings' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('checklists.nav.settings') : 'Settings'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('checklists.nav.settings') : 'Settings'); ?></span>
        </a>
        <?php /* The module shipped with no Help button and no help page, the
                 only one of 21 without either. Every other module's nav ends
                 with this. */ ?>
        <a href="<?php echo BASE_URL; ?>checklists/help.php" class="nav-btn <?php echo $current_page === 'help' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('checklists.nav.help') : 'Help'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('checklists.nav.help') : 'Help'); ?></span>
        </a>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>
<?php renderWaffleMenuJS(); ?>

<style>
.checklists-header {
    background: linear-gradient(135deg, #0d9488, #0f766e) !important;
    color: #fff !important;
}
.checklists-header .module-title { color: #fff !important; }   /* size and weight as every module (GH #303) */
.checklists-header .nav-btn { color: #fff !important; background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.25); }
.checklists-header .nav-btn.active, .checklists-header .nav-btn:hover { background: rgba(255, 255, 255, 0.3); border-color: #fff; }
.checklists-header .waffle-icon span { background-color: #fff !important; }

/* Sidebar hover mode */
.chk-layout { position: relative; }
.checklists-sidebar-hover .chk-layout .chk-sidebar {
    position: absolute;
    top: 0; left: 0; bottom: 0;
    width: 16px;
    min-width: 16px;
    z-index: 100;
    overflow: hidden;
    transition: width 0.18s ease;
    box-shadow: 2px 0 8px rgba(0, 0, 0, 0.15);
    padding: 0;
}
.checklists-sidebar-hover .chk-layout .chk-sidebar:hover {
    width: 260px;
    padding: 20px;
    overflow-y: auto;
}
.checklists-sidebar-hover .chk-layout .chk-sidebar > * {
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.12s ease 0s;
}
.checklists-sidebar-hover .chk-layout .chk-sidebar:hover > * {
    opacity: 1;
    pointer-events: auto;
    transition-delay: 0.08s;
}
.checklists-sidebar-hover .chk-layout .chk-sidebar::before {
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
.checklists-sidebar-hover .chk-layout .chk-sidebar:hover::before { opacity: 0; }
</style>
<?php if ($checklistsSidebarMode === 'hover'): ?>
<script>document.documentElement.classList.add('checklists-sidebar-hover');</script>
<?php endif; ?>
