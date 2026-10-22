<?php
/**
 * Change Management — Full-screen table view
 *
 * Thin page over the shared data-table engine (assets/js/data-table.js +
 * assets/css/data-table.css). Inline-editable for the low-risk list fields
 * (priority, impact, type, assignee) via api/change-management/update_field.php;
 * status + the detailed fields stay in the full form (click a row to open it).
 * The change-specific columns + saves live in assets/js/change-table.js.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
requireModuleAccess('changes');

$current_page = 'table';
$path_prefix = '../';
$translationNamespaces = ['common', 'change-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('changes', t('change-management.nav.table'))); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="../assets/css/change-management.css?v=6">
    <link rel="stylesheet" href="../assets/css/data-table.css?v=5">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=195">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
</head>
<?php $cmLevel = analystModuleLevel(connectToDatabase(), (int)($_SESSION['analyst_id'] ?? 0), 'changes'); ?>
<body>
    <?php include 'includes/header.php'; ?>
    <?php /* The action row (#185): New change was at the foot of the list's
             sidebar and missing from the Calendar and Table views. Read only
             access (#180) gets the row without New. */
          require_once __DIR__ . '/../includes/action_row.php';
          renderActionRow(t('change-management.nav.changes'),
              $cmLevel !== 'read' ? [actionRowNew(BASE_URL . 'change-management/new/', t('change-management.sidebar.new_change_tip'), true)] : [],
              ['accent' => 'cm']); ?>

    <div class="dt-page">
        <?php include '../includes/data-table-skeleton.php'; ?>
    </div>

    <script src="../assets/js/data-table.js?v=7"></script>
    <script src="../assets/js/change-table.js?v=6"></script>
    <script src="../assets/js/mobile.js?v=82"></script>
</body>
</html>
