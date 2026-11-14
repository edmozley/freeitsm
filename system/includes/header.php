<?php
/**
 * System Module Header Component
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'system';
$module_title = function_exists('t') ? t('system.title') : 'System';

// Guarantee the admin helper is available for the gate below, regardless of what
// the including page has loaded.
require_once $path_prefix . 'includes/functions.php';

// Ensure user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

// The System module is administrators-only — EXCEPT per-user Preferences, which
// every analyst manages for themselves. Non-admins are bounced to the landing.
// (Hard enforcement still lives on each System API via requireAdminJson(); this
// page gate is the UX layer so non-admins never see the admin screens.)
if (($current_page ?? '') !== 'preferences' && !sessionIsAdmin()) {
    header('Location: ' . BASE_URL);
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

// Include the shared waffle menu component
require_once $path_prefix . 'includes/waffle-menu.php';
?>
<?php /* The one System page layout (assets/css/system-page.css): loaded here so
         every System page has it without each <head> remembering, and AFTER the
         page's own <style>, so it wins a tie. tests/system-page-layout.php
         measures every page against it. */ ?>
<link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/system-page.css?v=<?php echo (int)@filemtime(__DIR__ . '/../../assets/css/system-page.css'); ?>">

<div class="header system-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <?php // System has too many areas to fit in the navbar, so navigation lives in the
              // landing-page cards (with search). The title links back there as the way home. ?>
        <a href="<?php echo BASE_URL; ?>system/" class="module-title" style="text-decoration:none;color:inherit;" title="<?php echo htmlspecialchars(t('system.landing.heading')); ?>"><?php echo $module_title; ?></a>
    </div>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>
<?php /* Arriving from a System search result - system/branding/#system-name
         (#2293). Bring the setting into view below the header and flash its card
         so the eye lands on it. Every System page includes this header, so the
         ids added to their headings work without each page doing anything. */ ?>
<style>
    .system-setting-flash { animation: systemSettingFlash 2.4s ease-out; border-radius: 10px; }
    @keyframes systemSettingFlash {
        0%, 35% { box-shadow: 0 0 0 3px var(--sys-accent, #546e7a); }
        100%    { box-shadow: 0 0 0 3px transparent; }
    }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var id = decodeURIComponent((location.hash || '').slice(1));
    if (!id) return;
    var el = document.getElementById(id);
    if (!el || !/^H[23]$/.test(el.tagName)) return;
    var box = el.closest('.settings-card, .card, .settings-section, section') || el;
    setTimeout(function () {
        box.style.scrollMarginTop = '74px';   // clear of the header
        box.scrollIntoView({ block: 'start', behavior: 'smooth' });
        box.classList.add('system-setting-flash');
    }, 60);
});
</script>
