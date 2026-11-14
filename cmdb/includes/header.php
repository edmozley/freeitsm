<?php
/**
 * CMDB Module Header Component
 *
 * Required: $current_page variable should be set before including
 */

// Path prefix for navigation links (can be overridden by including page)
$path_prefix = $path_prefix ?? '../';
$current_module = 'cmdb';
$module_title = function_exists('t') ? t('cmdb.title') : 'CMDB';

// Ensure user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

// Include the shared waffle menu component
require_once $path_prefix . 'includes/waffle-menu.php';

// GH #313: the CMDB assistant. The panel is on every CMDB page for an analyst who may
// design classes (Cap::CMDB_CLASSES) - so what it learned can be read and corrected
// even with AI switched off (the Classes tab links to it); the Assistant button only
// when the CMDB's AI is set up (aiFeatureReady, the AI-Providers rule).
$cmdbAssistPanel = false;
$cmdbAssistButton = false;
try {
    require_once __DIR__ . '/../../includes/rbac.php';
    require_once __DIR__ . '/../../includes/ai_settings.php';
    $cmdbAssistConn = connectToDatabase();
    $cmdbAssistPanel = analystHasCapability($cmdbAssistConn, (int)$_SESSION['analyst_id'], Cap::CMDB_CLASSES);
    $cmdbAssistButton = $cmdbAssistPanel && aiFeatureReady($cmdbAssistConn, 'cmdb_ai');
} catch (Throwable $e) {
    $cmdbAssistPanel = false;
}
/** A translated string, HTML-escaped, for the assistant markup below. */
$cmdbAssistT = function (string $k): string { return htmlspecialchars(function_exists('t') ? t('cmdb.assistant.' . $k) : $k, ENT_QUOTES); };
?>

<div class="header cmdb-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo BASE_URL; ?>cmdb/" class="nav-btn <?php echo $current_page === 'browse' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.browse') : 'Browse'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 22V8l10-6 10 6v14"></path>
                <path d="M2 12h20"></path>
                <path d="M2 17h20"></path>
                <line x1="12" y1="2" x2="12" y2="22"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.browse') : 'Browse'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>cmdb/audit.php" class="nav-btn <?php echo $current_page === 'audit' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.audit') : 'Data quality'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.audit') : 'Data quality'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>cmdb/settings/" class="nav-btn <?php echo $current_page === 'settings' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.settings') : 'Settings'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.settings') : 'Settings'); ?></span>
        </a>
        <a href="<?php echo BASE_URL; ?>cmdb/help.php" class="nav-btn <?php echo $current_page === 'help' ? 'active' : ''; ?>" title="<?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.help') : 'Help'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span><?php echo htmlspecialchars(function_exists('t') ? t('cmdb.nav.help') : 'Help'); ?></span>
        </a>
        <?php if ($cmdbAssistButton): ?>
        <button type="button" class="nav-btn cmdb-assist-btn" id="cmdbAssistBtn" title="<?php echo $cmdbAssistT('button_title'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10.7l-6.1 1.9L12 18.5l-1.9-5.9L4 10.7l6.1-1.9z"></path><path d="M19 3v4"></path><path d="M21 5h-4"></path></svg>
            <span><?php echo $cmdbAssistT('button'); ?></span>
        </button>
        <?php endif; ?>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>

<?php if ($cmdbAssistPanel): ?>
<!-- The CMDB assistant (GH #313) - the same slide-in panel as the Projects assistant; assets/js/cmdb-assistant.js -->
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/cmdb-assistant.css?v=2">
<div class="ai-chat-overlay" id="caOverlay"></div>
<div class="ai-chat-panel cmdb-ca" id="caPanel" role="dialog" aria-labelledby="caHeading"
     data-api="<?php echo htmlspecialchars(BASE_URL . 'api/cmdb/assistant.php', ENT_QUOTES); ?>"
     data-base="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES); ?>"
     data-setup="<?php echo htmlspecialchars(BASE_URL . 'cmdb/settings/?tab=ai', ENT_QUOTES); ?>">
    <div class="ai-chat-header">
        <div class="ai-chat-title" id="caHeading">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10.7l-6.1 1.9L12 18.5l-1.9-5.9L4 10.7l6.1-1.9z"></path></svg>
            <span><?php echo $cmdbAssistT('title'); ?><small class="ca-company" id="caCompany" hidden></small></span>
        </div>
        <button type="button" class="ca-clear" id="caClear" title="<?php echo $cmdbAssistT('clear_hint'); ?>"><?php echo $cmdbAssistT('clear'); ?></button>
        <button type="button" class="ai-chat-close" id="caClose" aria-label="<?php echo htmlspecialchars(function_exists('t') ? t('common.close') : 'Close', ENT_QUOTES); ?>">&times;</button>
    </div>
    <div class="ca-tabs" role="tablist">
        <button type="button" class="ca-tab active" data-ca-view="chat" role="tab"><?php echo $cmdbAssistT('tab_conversation'); ?></button>
        <button type="button" class="ca-tab" data-ca-view="learned" role="tab"><?php echo $cmdbAssistT('tab_learned'); ?> <span class="ca-count" id="caFactCount"></span></button>
        <span class="ca-stage" id="caStage" hidden></span>
    </div>
    <div class="ca-view" id="caChatView">
        <div class="ca-flags"><span id="caMemory" hidden><?php echo $cmdbAssistT('remembers'); ?></span></div>
        <div class="ai-chat-messages" id="caMessages" aria-live="polite"></div>
        <div class="ca-chips" id="caChips"></div>
        <div class="ai-chat-input-area">
            <textarea id="caInput" rows="2" maxlength="4000" placeholder="<?php echo $cmdbAssistT('placeholder'); ?>" aria-label="<?php echo $cmdbAssistT('placeholder'); ?>"></textarea>
            <button type="button" class="ai-chat-send" id="caSend" aria-label="<?php echo $cmdbAssistT('send'); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
            </button>
        </div>
        <p class="ca-foot"><?php echo $cmdbAssistT('foot'); ?></p>
    </div>
    <div class="ca-view" id="caLearnedView" hidden></div>
</div>
<script src="<?php echo BASE_URL; ?>assets/js/cmdb-assistant.js?v=3"></script>
<?php endif; ?>

<style>
/* Per-analyst left-panel visibility (CMDB Settings -> Left panel, and
   System -> Preferences). 'hover' collapses .browse-sidebar to a 16px
   hot-zone that expands on hover; 'always' (default) leaves it pinned.
   Mirrors the knowledge / contracts pattern. Pref key: cmdb_sidebar_mode. */
.browse-container { position: relative; }
.browse-container.sidebar-hover .browse-sidebar {
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
.browse-container.sidebar-hover .browse-sidebar:hover {
    width: 260px;
    min-width: 260px;
    overflow-y: auto;
}
.browse-container.sidebar-hover .browse-sidebar > * {
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.12s ease 0s;
}
.browse-container.sidebar-hover .browse-sidebar:hover > * {
    opacity: 1;
    pointer-events: auto;
    transition-delay: 0.08s;
}
.browse-container.sidebar-hover .browse-sidebar::before {
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
.browse-container.sidebar-hover .browse-sidebar:hover::before { opacity: 0; }
</style>
<script>
(async function() {
    try {
        const r = await fetch('<?php echo BASE_URL; ?>api/system/get_user_preference.php?key=cmdb_sidebar_mode', { credentials: 'same-origin' });
        const d = await r.json();
        const mode = (d.success && d.value === 'hover') ? 'hover' : 'always';
        document.querySelectorAll('.browse-container').forEach(el => el.classList.toggle('sidebar-hover', mode === 'hover'));
    } catch (e) { /* no-op -- default is always-visible */ }
})();
</script>
