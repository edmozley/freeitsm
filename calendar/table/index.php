<?php
/**
 * Calendar Module — Full-screen table view
 *
 * Thin page over the shared data-table engine (assets/js/data-table.js +
 * assets/css/data-table.css). The calendar-specific bits — columns, inline-edit
 * save, event loading — live in assets/js/calendar-table.js.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

requireModuleAccess('calendar');

$current_page = 'table';
$path_prefix = '../../';
$translationNamespaces = ['common', 'calendar'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('calendar.title') . ' ' . t('calendar.nav.table')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=20">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=37">
    <link rel="stylesheet" href="../../assets/css/calendar-grid.css?v=1">
    <link rel="stylesheet" href="../../assets/css/itsm_calendar.css?v=6">
    <link rel="stylesheet" href="../../assets/css/data-table.css?v=2">
    <style>body { --accent: var(--cal-accent, #ef6c00); --accent-hover: var(--cal-accent-hover, #e65100); }</style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="dt-page">
        <?php include '../../includes/data-table-skeleton.php'; ?>
    </div>

    <script src="../../assets/js/data-table.js?v=2"></script>
    <script src="../../assets/js/calendar-table.js?v=2"></script>
</body>
</html>
