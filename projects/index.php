<?php
/**
 * Projects - the portfolio (3.2.0).
 *
 * Every project the analyst's company runs (or every company they can see, in
 * the "All companies" view) as a wall of cards: each project's own colour and
 * icon, a health ring with its progress inside, its target date and what is
 * happening now. Behaviour is in assets/js/projects-portfolio.js; the shared
 * renderers and the project dialog in assets/js/projects.js.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('projects');

$current_page = 'portfolio';
$path_prefix = '../';
$translationNamespaces = ['common', 'projects'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('projects.title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=26">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=77">
    <link rel="stylesheet" href="../assets/css/projects.css?v=2">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=180">
</head>
<body data-mobile-module="projects" data-mobile-page="projects-portfolio">
    <?php include 'includes/header.php'; ?>

    <div class="prj-layout">
        <aside class="prj-sidebar">
            <h3><?php echo htmlspecialchars(t('projects.portfolio.search')); ?></h3>
            <input type="text" id="prjSearch" placeholder="<?php echo htmlspecialchars(t('projects.portfolio.search_ph')); ?>" autocomplete="off">

            <h3><?php echo htmlspecialchars(t('projects.portfolio.views')); ?></h3>
            <ul class="prj-views" id="prjViews">
                <li data-view="live" class="active"><span><?php echo htmlspecialchars(t('projects.portfolio.view_live')); ?></span><b data-count="live"></b></li>
                <li data-view="mine"><span><?php echo htmlspecialchars(t('projects.portfolio.view_mine')); ?></span><b data-count="mine"></b></li>
                <li data-view="at_risk"><span><?php echo htmlspecialchars(t('projects.portfolio.view_at_risk')); ?></span><b data-count="at_risk"></b></li>
                <li data-view="proposed"><span><?php echo htmlspecialchars(t('projects.portfolio.view_proposed')); ?></span><b data-count="proposed"></b></li>
                <li data-view="on_hold"><span><?php echo htmlspecialchars(t('projects.portfolio.view_on_hold')); ?></span><b data-count="on_hold"></b></li>
                <li data-view="finished"><span><?php echo htmlspecialchars(t('projects.portfolio.view_closed')); ?></span><b data-count="finished"></b></li>
                <li data-view="all"><span><?php echo htmlspecialchars(t('projects.portfolio.view_all')); ?></span><b data-count="all"></b></li>
            </ul>
        </aside>

        <main class="prj-main">
            <div class="prj-tiles" id="prjTiles">
                <div class="prj-tile"><span class="prj-tile-num" data-tile="live">-</span><span class="prj-tile-label"><?php echo htmlspecialchars(t('projects.portfolio.tile_live')); ?></span></div>
                <div class="prj-tile warn"><span class="prj-tile-num" data-tile="at_risk">-</span><span class="prj-tile-label"><?php echo htmlspecialchars(t('projects.portfolio.tile_at_risk')); ?></span></div>
                <div class="prj-tile"><span class="prj-tile-num" data-tile="due">-</span><span class="prj-tile-label"><?php echo htmlspecialchars(t('projects.portfolio.tile_due')); ?></span></div>
                <div class="prj-tile bad"><span class="prj-tile-num" data-tile="overdue">-</span><span class="prj-tile-label"><?php echo htmlspecialchars(t('projects.portfolio.tile_overdue')); ?></span></div>
            </div>

            <div class="prj-toolbar">
                <button type="button" class="btn btn-primary prj-btn" id="prjNew">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <?php echo htmlspecialchars(t('projects.portfolio.new')); ?>
                </button>
                <span class="prj-count" id="prjCount"></span>
            </div>

            <div class="prj-grid" id="prjGrid" aria-live="polite"></div>

            <div class="prj-empty" id="prjEmpty" hidden>
                <div class="prj-empty-art" aria-hidden="true">
                    <span></span><span></span><span></span>
                </div>
                <h2><?php echo htmlspecialchars(t('projects.portfolio.empty_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('projects.portfolio.empty_body')); ?></p>
                <button type="button" class="btn btn-primary prj-btn" id="prjEmptyNew"><?php echo htmlspecialchars(t('projects.portfolio.empty_cta')); ?></button>
            </div>
            <p class="prj-filtered-empty" id="prjFilteredEmpty" hidden><?php echo htmlspecialchars(t('projects.portfolio.empty_filtered')); ?></p>
        </main>
    </div>

    <?php include 'includes/project_form.php'; ?>

    <script src="../assets/js/projects.js?v=2"></script>
    <script src="../assets/js/projects-portfolio.js?v=1"></script>
    <script src="../assets/js/mobile.js?v=75"></script>
</body>
</html>
