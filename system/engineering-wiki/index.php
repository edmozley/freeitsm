<?php
/**
 * System - Engineering wiki.
 *
 * How FreeITSM is built, for developers: hand-written Handbook pages and the
 * scanner-generated Code map, shown in a small browser - tabs, back/forward, an
 * address line, a split view, and a right-click menu on every link. The pages are
 * Markdown files in engineering-wiki/, read through api/system/engineering-wiki/.
 * Administrators only, like the rest of System.
 *
 * @see includes/engineering_wiki/store.php
 * @see assets/js/engineering-wiki.js
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
Tz::init();

$current_page = 'engineering-wiki';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'auth/login.php');
    exit;
}

// Every string the script shows, in the reader's language.
$ewT = [];
foreach (['back', 'forward', 'reload', 'sidebar', 'search_placeholder', 'address_hint', 'new_tab', 'close_tab',
          'open', 'open_new_tab', 'open_beside', 'copy_link', 'link_copied', 'close_others', 'move_beside',
          'outline', 'generated', 'handbook', 'code_map', 'updated', 'loading', 'not_found', 'no_results',
          'results_for', 'clear', 'empty_folder', 'nothing_yet', 'rescan', 'rescan_hint', 'scanning', 'scan_done', 'scan_failed'] as $k) {
    $ewT[$k] = t('system.engineering_wiki.' . $k);
}
$startPage = 'handbook/start';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.engineering_wiki.heading'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=93">
    <link rel="stylesheet" href="../../assets/css/engineering-wiki.css?v=5">
    <style>
        /* System module accent (blue-grey), as on every System page. */
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
    </style>
</head>
<body data-mobile-module="system" data-mobile-page="engineering-wiki">
    <?php include '../includes/header.php'; ?>

    <div class="ew-app" id="ewApp">
        <div class="ew-toolbar">
            <button class="ew-btn" id="ewSidebarBtn" title="<?php echo htmlspecialchars($ewT['sidebar']); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"></rect><line x1="9" y1="4" x2="9" y2="20"></line></svg></button>
            <button class="ew-btn" id="ewBack" title="<?php echo htmlspecialchars($ewT['back']); ?>" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg></button>
            <button class="ew-btn" id="ewForward" title="<?php echo htmlspecialchars($ewT['forward']); ?>" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></button>
            <button class="ew-btn" id="ewReload" title="<?php echo htmlspecialchars($ewT['reload']); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg></button>
            <label class="ew-address" title="<?php echo htmlspecialchars($ewT['address_hint']); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                <input type="text" id="ewAddress" spellcheck="false" autocomplete="off">
            </label>
            <button class="ew-rescan" id="ewRescan" title="<?php echo htmlspecialchars($ewT['rescan_hint']); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><polyline points="8 11 10 13 14 9"></polyline></svg><span id="ewRescanText"><?php echo htmlspecialchars($ewT['rescan']); ?></span></button>
            <input type="search" class="ew-search" id="ewSearch" placeholder="<?php echo htmlspecialchars($ewT['search_placeholder']); ?>" autocomplete="off">
        </div>
        <div class="ew-body">
            <nav class="ew-sidebar" id="ewSidebar"></nav>
            <div class="ew-panes" id="ewPanes"></div>
        </div>
    </div>

    <script>
        window.EW_CONFIG = {
            api: '../../api/system/engineering-wiki/',
            start: <?php echo json_encode($startPage); ?>,
            open: <?php echo json_encode((string)($_GET['page'] ?? '')); ?>,
            t: <?php echo json_encode($ewT, JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <script src="../../assets/js/engineering-wiki.js?v=6"></script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
