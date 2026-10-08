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
/**
 * 🔑 Mark this request as a PORTAL render, before theme.php is asked anything.
 *
 * The portal and the app share a PHPSESSID, so "is there an analyst_id in the
 * session?" cannot answer "whose palette is this page for?". This can, and it
 * is set in the one file every signed-in portal page goes through.
 */
if (!defined('FREEITSM_SELF_SERVICE')) {
    define('FREEITSM_SELF_SERVICE', true);
}

require_once __DIR__ . '/../../includes/theme.php';
require_once __DIR__ . '/../../includes/self_service_settings.php';
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
    // ⚠️ SHOWN ONLY TO A MANAGER - someone who manages at least one person
    // (discussion #62). The page and every endpoint behind it re-check through
    // portalTicketAccess(); a hidden tab is not a permission.
    'team_tickets' => ['href' => 'tickets.php?view=team', 'label' => t('self-service.nav.team_tickets'), 'cap' => 'is_manager'],
    // Named after the module it surfaces, so customers and analysts use one word.
    'help_centre' => ['href' => 'help-centre.php', 'label' => t('self-service.nav.help_centre')],
    // ⚠️ SHOWN ONLY TO SOMEBODY WHO ACTUALLY HAS TRAINING. On an install that
    // has never pushed a course to the portal — which is most of them — a
    // permanent Training tab leading to an empty page is a worse answer than no
    // tab at all. `cap` is resolved below.
    'training'    => ['href' => 'training.php',    'label' => t('self-service.nav.training'), 'cap' => 'has_training'],
    // ⚠️ SHOWN ONLY WHEN THE ADMINISTRATOR HAS TURNED IT ON *AND* THE PERSON
    // ACTUALLY HAS KIT — the same judgement Training makes just above, for the
    // same reason: a permanent tab leading to "you have no equipment" is a
    // worse answer than no tab. The page re-checks the setting itself, because
    // a hidden link is not a permission.
    'equipment'   => ['href' => 'my-equipment.php', 'label' => t('self-service.nav.equipment'), 'cap' => 'has_equipment'],
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
    if ($cap === 'is_manager') {
        try {
            require_once __DIR__ . '/../../includes/managers.php';
            return managerHasTeam(connectToDatabase(), (int)$ss_user_id);
        } catch (Throwable $e) {
            return false;   // fails closed and quietly, like the others
        }
    }
    if ($cap === 'has_equipment') {
        try {
            $conn = connectToDatabase();
            // The switch first: it is one cached array and settles most installs
            // without touching the assets tables at all.
            if (!selfServicePortalSettings($conn)['show_my_assets']) return false;
            // Then whether there is anything to show. Mirrors the endpoint's own
            // rule (an INNER JOIN, because users_assets has orphan rows on real
            // installs) so the tab cannot appear over an empty page.
            $st = $conn->prepare(
                "SELECT 1 FROM users_assets ua JOIN assets a ON a.id = ua.asset_id
                  WHERE ua.user_id = ? LIMIT 1"
            );
            $st->execute([(int)$ss_user_id]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;   // fails closed and quietly, like the training one
        }
    }
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

/**
 * Portal appearance (System → Self-service portal).
 *
 * 🔑 Every one of these is emitted ONLY if it has been set, so an install that
 * has never opened that screen renders exactly the markup it rendered before.
 * That is the whole reason the defaults are empty rather than "the current
 * colour": an empty value means "do nothing", which cannot regress anybody.
 */
$ssAppearance = ['logo_path' => '', 'header_colour' => '', 'table_header_colour' => '',
                 'background_pattern' => '', 'allow_self_close' => false, 'show_my_assets' => false];
try {
    if (function_exists('connectToDatabase')) {
        $ssAppearance = selfServicePortalSettings(connectToDatabase());
    }
} catch (Throwable $e) {
    // The portal must render even if the settings cannot be read.
}
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
    <link rel="stylesheet" href="../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=76">
    <link rel="stylesheet" href="../assets/css/self-service.css?v=22">
<?php if ($ssAppearance['background_pattern'] !== ''): ?>
    <!-- Only fetched when a pattern is actually in use. -->
    <link rel="stylesheet" href="../assets/css/self-service-patterns.css?v=4">
<?php endif; ?>
<?php if (!empty($needsFormLogic)): ?>
    <!-- Form blocks (notes). In the HEAD rather than beside form-logic.js in the
         footer: a stylesheet in the body risks a flash of unstyled content, and
         $needsFormLogic is set by the page long before this include runs. -->
    <link rel="stylesheet" href="../assets/css/form-shared.css?v=6">
<?php endif; ?>
    <?php if ($pageStyles !== ''): ?>
    <style><?php echo $pageStyles; ?></style>
    <?php endif; ?>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <?php echo $pageHead; ?>
</head>
<?php
// The colours are written as CSS CUSTOM PROPERTIES on <body> rather than inline
// on each element: one declaration, and every rule that already reads the token
// picks it up - including rules added later, which an inline style could not.
$ssVars = '';
if ($ssAppearance['header_colour'] !== '')       $ssVars .= '--ss-header-bg:' . $ssAppearance['header_colour'] . ';';
if ($ssAppearance['table_header_colour'] !== '') $ssVars .= '--ss-table-header-bg:' . $ssAppearance['table_header_colour'] . ';';
$ssBodyClass = trim($bodyClass . ($ssAppearance['background_pattern'] !== '' ? ' ss-pat-' . $ssAppearance['background_pattern'] : ''));

// Resolved BEFORE <body>: the header's class depends on it, the img is inside
// it, and <body> needs to know too.
$ssLogoUrl = selfServicePortalLogoUrl($conn ?? connectToDatabase());
// A custom logo shown on the PAGE is not shown in the bar as well: repeating
// it twice on one screen is clutter, and falling back to the bundled FreeITSM
// mark would put our branding beside theirs.
$ssLogoOnPage = ($ssLogoUrl !== '' && ($ssAppearance['logo_position'] ?? 'header') === 'page');
// ⚠️ The logo band sits ABOVE .portal-layout, and the app-shell pages (New
// Ticket, My Tickets, a course) size themselves as "the window minus the
// chrome". Without this class they subtracted the 48px bar only, came out
// taller than the window by the band, and - the shell being scroll-locked -
// their bottom could not be reached at all. See --ss-chrome in self-service.css.
if ($ssLogoOnPage) {
    $ssBodyClass = trim($ssBodyClass . ' ss-page-logo');
}
?>
<body class="<?php echo htmlspecialchars($ssBodyClass); ?>"<?php echo $ssVars !== '' ? ' style="' . htmlspecialchars($ssVars, ENT_QUOTES) . '"' : ''; ?>>
    <div class="portal-header">
        <div class="portal-brand<?php echo $ssLogoUrl !== '' && !$ssLogoOnPage ? ' has-custom-logo' : ''; ?>">
            <?php /* A portal-specific logo if one is set, otherwise the shared
                     one from System → Branding. Empty means "use the main one",
                     so nothing changes for an install that has not set it. */ ?>
            <?php if (!$ssLogoOnPage): ?>
            <img src="<?php echo htmlspecialchars($ssLogoUrl !== '' ? $ssLogoUrl : brandingLogoUrl()); ?>" alt="">
            <?php endif; ?>
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
        <?php /* ONE right-hand group - bell, phone menu button, account - so the
                 bar keeps its three parts (brand | nav | this) and
                 `space-between` still centres the nav. The bell as a fourth item
                 either floated mid-gap or, pushed right with an auto margin,
                 dragged the nav to the left. */ ?>
        <div class="portal-right">
        <?php
        /*
         * The bell (discussion #62) - the SAME bell as the analyst app
         * (includes/notification_bell.php), pointed at the portal's endpoint.
         *
         * Every MANAGER has it, whatever System -> Managers says about new
         * tickets: the bell belongs to being a manager, and the settings only
         * decide what is sent to it - new team tickets today, other things later
         * (Ed). Anyone else only once they have a notification. A permanently
         * empty bell in front of every requester would be clutter that invites a
         * question.
         */
        $ssShowBell = false;
        try {
            require_once __DIR__ . '/../../includes/managers.php';
            $ssConnBell = connectToDatabase();
            $hasAny = $ssConnBell->prepare("SELECT 1 FROM portal_notifications WHERE user_id = ? LIMIT 1");
            $hasAny->execute([(int)$ss_user_id]);
            $ssShowBell = (bool)$hasAny->fetchColumn() || $portalNavCap('is_manager');
        } catch (Throwable $e) {
            $ssShowBell = false;   // before Database Verification: no table, no bell
        }
        if ($ssShowBell) {
            require_once __DIR__ . '/../../includes/notification_bell.php';
            notificationBellRender('../', [
                'list'       => '../api/self-service/notifications.php?action=list',
                'markRead'   => '../api/self-service/notifications.php?action=mark_read',
                'clear'      => '../api/self-service/notifications.php?action=clear',
                'linkPrefix' => '../',
                'seenKey'    => 'ssNbSeenUnread',
            ]);
        }
        ?>
        <button type="button" class="ss-nav-btn" onclick="ssToggleNav()"
                aria-label="<?php echo htmlspecialchars(t('self-service.nav.menu')); ?>"
                aria-expanded="false" aria-controls="portalNav">&#9776;</button>
        <?php include __DIR__ . '/user-menu.php'; ?>
        </div>
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

    <?php if ($ssLogoOnPage): ?>
    <?php /* Across the top of the page rather than in the bar. Its own band so
             it lines up with the page gutter below it, whatever the page is. */ ?>
    <div class="portal-page-logo">
        <img src="<?php echo htmlspecialchars($ssLogoUrl); ?>" alt="">
    </div>
    <?php endif; ?>

    <div class="portal-layout">
