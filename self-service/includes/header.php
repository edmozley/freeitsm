<?php
/**
 * Self-service portal — the shared page top.
 *
 * Every authenticated portal page used to repeat this boilerplate inline, and
 * the header/nav CSS was copy-pasted verbatim into all four of them. Adding a
 * page meant a fifth copy plus editing five files to add a nav link. This is
 * that chrome, once.
 *
 * A page includes it like this, having set nothing else up itself:
 *
 *     <?php
 *     $pageTitleKey = 'self-service.dashboard.title';   // a KEY, not t(...) —
 *     $activeNav    = 'dashboard';                     // i18n isn't up yet
 *     require __DIR__ . '/includes/header.php';
 *     ?>
 *     …page content…
 *     <?php require_once __DIR__ . '/includes/footer.php'; ?>
 *
 * Optional extras a page may set BEFORE including this:
 *   $pageStyles  — a string of page-specific CSS (keep it genuinely page-specific;
 *                  anything shared belongs in assets/css/self-service.css)
 *   $bodyClass   — extra class on <body>
 *   $pageHead    — raw markup for <head> (a page needing an extra script/stylesheet,
 *                  e.g. the rich-text editor on new-ticket.php). Only ONE page
 *                  wants that, so it opts in rather than every page loading it.
 *
 * ⚠️ Load order matters: theme.css must come BEFORE self-service.css so the
 * token definitions are in scope when the portal stylesheet reads them.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/i18n.php';
I18n::initFromSession();
require_once __DIR__ . '/../../includes/theme.php';
// The logo the header draws (GH #87). login.php and register.php require this
// themselves; the six signed-in pages come through here, so it belongs here.
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/timezone.php';
// The portal had NO timezone or date-format plumbing at all: its dates were
// rendered from an unmarked `new Date(dbString)`, so they showed the browser's
// idea of the instant, not the analyst-side one, AND could not follow the
// install's chosen format. Portal users are not analysts, so Tz falls back to
// the server zone and DateFmt to the system_settings default - which is exactly
// what that level exists for.
Tz::init();
require_once __DIR__ . '/auth.php';            // redirects to login.php if not signed in

/* ⚠️ ?? — a page may need MORE than the portal's own two namespaces, and this
   used to overwrite whatever it had asked for. course.php embeds the LMS player,
   whose markup and JS speak the `lms` namespace; with the assignment
   unconditional, every one of its labels rendered as its own translation key
   ("lms.player.next") on a page that otherwise looked perfectly fine. */
$translationNamespaces = $translationNamespaces ?? ['common', 'self-service'];

/**
 * The portal's navigation, in one place. Adding a page is now a single entry
 * here plus the page itself — no more editing five files.
 *
 * 'cap' (optional) names a feature that must be switched on for the item to
 * appear; null means always shown.
 */
$portalNav = [
    // DESTINATIONS only. Raising a ticket and requesting something are ACTIONS —
    // they are primary buttons on the dashboard, not nav items, so the bar stays
    // short and the two things people actually come here to do are the most
    // prominent thing on the page they land on.
    'dashboard'   => ['href' => 'index.php',       'label' => t('self-service.nav.dashboard')],
    'tickets'     => ['href' => 'tickets.php',     'label' => t('self-service.nav.tickets')],
    // Named after the module it surfaces, so customers and analysts use one word.
    'help_centre' => ['href' => 'help-centre.php', 'label' => t('self-service.nav.help_centre')],
    // ⚠️ SHOWN ONLY TO SOMEBODY WHO ACTUALLY HAS TRAINING. On an install that
    // has never pushed a course to the portal — which is most of them — a
    // permanent Training tab leading to an empty page is a worse answer than no
    // tab at all. `cap` is resolved below.
    'training'    => ['href' => 'training.php',    'label' => t('self-service.nav.training'), 'cap' => 'has_training'],
    'help'        => ['href' => 'help.php',        'label' => t('self-service.nav.help')],
];

/**
 * Resolve the optional 'cap' on a nav item. Documented since the nav was first
 * centralised; this is the first item to use one.
 *
 * 🔑 One EXISTS query, and only for the item that asks for it. Training is the
 * only conditional entry, so an install that never pushes a course to the portal
 * pays for one indexed lookup per page and shows one fewer tab.
 *
 * ⚠️ FAILS CLOSED, and quietly. If the LMS tables are absent (a part-upgraded
 * install, or the module never used) the lookup throws and the tab is simply not
 * drawn — a portal page must not become a stack trace because a module somebody
 * has never opened is mid-migration.
 */
$portalNavCap = function (string $cap) use ($ss_user_id) {
    if ($cap !== 'has_training') return false;
    try {
        require_once __DIR__ . '/../../includes/lms_access.php';
        $learner = LmsLearner::user((int)$ss_user_id);
        if (!$learner) return false;
        $conn = connectToDatabase();
        list($reachSql, $params) = lmsAssignmentReachSql($conn, $learner);
        $st = $conn->prepare("SELECT 1 FROM lms_course_assignments ca
                               JOIN lms_courses c ON c.id = ca.course_id AND c.is_active = 1
                              WHERE $reachSql LIMIT 1");
        $st->execute($params);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
};

foreach ($portalNav as $navKey => $navItem) {
    if (!empty($navItem['cap']) && !$portalNavCap($navItem['cap'])) {
        unset($portalNav[$navKey]);
    }
}
unset($navKey, $navItem);

$activeNav  = $activeNav  ?? '';
$bodyClass  = $bodyClass  ?? '';
$pageStyles = $pageStyles ?? '';
$pageHead   = $pageHead   ?? '';
// Pages hand us a translation KEY, because i18n only comes up inside this file —
// a page can't call t() before including it.
$pageTitle  = isset($pageTitleKey) ? t($pageTitleKey) : t('self-service.portal');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>"
      data-theme="<?php echo htmlspecialchars(Theme::active()); ?>"
      data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=70">
    <link rel="stylesheet" href="../assets/css/self-service.css?v=14">
    <?php if ($pageStyles !== ''): ?>
    <style><?php echo $pageStyles; ?></style>
    <?php endif; ?>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <?php echo $pageHead; ?>
</head>
<body class="<?php echo htmlspecialchars($bodyClass); ?>">
    <div class="portal-header">
        <div class="portal-brand">
            <img src="<?php echo htmlspecialchars(brandingLogoUrl()); ?>" alt="">
            <span><?php echo htmlspecialchars(t('self-service.portal')); ?></span>
        </div>
        <nav class="portal-nav" id="portalNav">
            <?php foreach ($portalNav as $key => $item): ?>
            <a href="<?php echo htmlspecialchars($item['href']); ?>"
               class="nav-btn<?php echo $key === $activeNav ? ' active' : ''; ?>">
                <?php echo htmlspecialchars($item['label']); ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php
        /*
         * The nav's phone control. On a phone `.portal-nav` becomes a right-side
         * drawer (see the @media block in self-service.css) — the same move
         * mobile.js makes for `.header-nav` across the analyst modules, so the
         * portal and the app behave alike. Both this button and the overlay are
         * `display: none` until that breakpoint, so desktop is untouched.
         *
         * Rendered unconditionally rather than injected by script: the nav it
         * opens is server-rendered, so building the opener the same way means
         * there is no moment where the drawer exists but cannot be opened.
         */
        ?>
        <button type="button" class="ss-nav-btn" onclick="ssToggleNav()"
                aria-label="<?php echo htmlspecialchars(t('self-service.nav.menu')); ?>"
                aria-expanded="false" aria-controls="portalNav">&#9776;</button>
        <?php include __DIR__ . '/user-menu.php'; ?>
    </div>
    <div class="ss-nav-overlay" onclick="ssToggleNav()"></div>
    <script>
    /* Mirrors ssToggleMenu() in user-menu.php — one drawer idiom for the portal. */
    function ssToggleNav() {
        var open = document.body.classList.toggle('ss-nav-open');
        var btn  = document.querySelector('.ss-nav-btn');
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    </script>

    <div class="portal-layout">
