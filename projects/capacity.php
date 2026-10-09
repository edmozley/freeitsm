<?php
/**
 * Projects - capacity (3.3.0): who is over-committed in the weeks ahead, once
 * project work and service-desk duty sit side by side. Read-only. The rules are
 * in includes/projects/capacity.php; behaviour in assets/js/projects-capacity.js.
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

$current_page = 'capacity';
$path_prefix = '../';
$translationNamespaces = ['common', 'projects'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('projects.capacity.title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=26">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=77">
    <link rel="stylesheet" href="../assets/css/projects.css?v=33">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=189">
</head>
<body data-mobile-module="projects" data-mobile-page="projects-capacity">
    <?php include 'includes/header.php'; ?>

    <div class="prj-page prj-cap-page" id="prjCapacity">
        <div class="prj-cap-head">
            <div>
                <h1><?php echo htmlspecialchars(t('projects.capacity.title')); ?></h1>
                <p class="prj-muted" id="capIntro"></p>
            </div>
            <div class="prj-seg prj-cap-weeks" role="tablist" aria-label="<?php echo htmlspecialchars(t('projects.capacity.weeks')); ?>">
                <button type="button" data-weeks="4" class="active"><?php echo htmlspecialchars(t('projects.capacity.weeks_n', ['n' => 4])); ?></button>
                <button type="button" data-weeks="8"><?php echo htmlspecialchars(t('projects.capacity.weeks_n', ['n' => 8])); ?></button>
                <button type="button" data-weeks="12"><?php echo htmlspecialchars(t('projects.capacity.weeks_n', ['n' => 12])); ?></button>
            </div>
        </div>
        <div class="prj-tiles" id="capTiles"></div>
        <div id="capBody"><div class="prj-plan-empty"><?php echo htmlspecialchars(t('projects.capacity.loading')); ?></div></div>
    </div>

    <script src="../assets/js/projects.js?v=8"></script>
    <script src="../assets/js/projects-capacity.js?v=2"></script>
    <script src="../assets/js/mobile.js?v=78"></script>
</body>
</html>
