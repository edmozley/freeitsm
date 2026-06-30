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
require_once '../../includes/i18n.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$current_page = 'table';
$path_prefix = '../../';
$translationNamespaces = ['common', 'calendar'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('calendar.title') . ' ' . t('calendar.nav.table')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <link rel="stylesheet" href="../../assets/css/itsm_calendar.css?v=3">
    <link rel="stylesheet" href="../../assets/css/data-table.css?v=2">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
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
