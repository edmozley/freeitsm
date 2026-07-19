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
require_once __DIR__ . '/auth.php';            // redirects to login.php if not signed in

$translationNamespaces = ['common', 'self-service'];

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
    'help'        => ['href' => 'help.php',        'label' => t('self-service.nav.help')],
];

$activeNav  = $activeNav  ?? '';
$bodyClass  = $bodyClass  ?? '';
$pageStyles = $pageStyles ?? '';
// Pages hand us a translation KEY, because i18n only comes up inside this file —
// a page can't call t() before including it.
$pageTitle  = isset($pageTitleKey) ? t($pageTitleKey) : t('self-service.portal');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>"
      data-theme="<?php echo htmlspecialchars(Theme::active()); ?>"
      data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/self-service.css?v=2">
    <?php if ($pageStyles !== ''): ?>
    <style><?php echo $pageStyles; ?></style>
    <?php endif; ?>
</head>
<body class="<?php echo htmlspecialchars($bodyClass); ?>">
    <div class="portal-header">
        <div class="portal-brand">
            <img src="../assets/images/CompanyLogo.png" alt="">
            <span><?php echo htmlspecialchars(t('self-service.portal')); ?></span>
        </div>
        <nav class="portal-nav">
            <?php foreach ($portalNav as $key => $item): ?>
            <a href="<?php echo htmlspecialchars($item['href']); ?>"
               class="nav-btn<?php echo $key === $activeNav ? ' active' : ''; ?>">
                <?php echo htmlspecialchars($item['label']); ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php include __DIR__ . '/user-menu.php'; ?>
    </div>

    <div class="portal-layout">
