<?php
/**
 * Projects - How it fits together (3.3.0): the interactive tutorial on a page of
 * its own, full width. The map and the walk-through are assets/js/projects-tour.js
 * (the same code Projects -> Help used to carry inline); ?walk=1 opens on the walk.
 * Linked from Help, the empty portfolio and the Toolbox.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/projects/methodologies.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('projects');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'projects'];
$tourStart = !empty($_GET['walk']) ? 'walk' : 'explore';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('projects.tutorial.title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <?php // The method filter reads the presets, so it can never disagree with them. ?>
    <script>window.PRJ_TOUR = <?php echo json_encode(['methods' => array_map(fn($m) => $m['tools'], projectMethodologies()), 'labels' => array_map(fn($k) => t('projects.method.' . $k), array_combine(array_keys(projectMethodologies()), array_keys(projectMethodologies())))], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=78">
    <link rel="stylesheet" href="../assets/css/projects.css?v=47">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=192">
</head>
<body data-mobile-module="projects" data-mobile-page="projects-tutorial">
    <?php include 'includes/header.php'; ?>

    <div class="prj-page prj-tutorial-page">
        <div class="prj-tutorial-head">
            <a class="prj-back" href="help.php">&larr; <?php echo htmlspecialchars(t('projects.tutorial.back')); ?></a>
            <h1><?php echo htmlspecialchars(t('projects.tutorial.title')); ?></h1>
            <p class="prj-muted"><?php echo htmlspecialchars(t('projects.tutorial.intro')); ?></p>
        </div>
        <div class="prj-tour prj-tour-full" id="prjTour" data-start="<?php echo $tourStart; ?>">
            <noscript><p><?php echo htmlspecialchars(t('projects.help.map.noscript')); ?></p></noscript>
        </div>
    </div>

    <script src="../assets/js/projects.js?v=10"></script>
    <script src="../assets/js/projects-tour.js?v=3"></script>
    <script src="../assets/js/mobile.js?v=78"></script>
</body>
</html>
