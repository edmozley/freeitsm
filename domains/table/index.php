<?php
/**
 * Domains — full-screen table view (#154).
 *
 * Thin page over the shared data-table engine (assets/js/data-table.js +
 * assets/css/data-table.css): column picker, sort, search, per-column filters,
 * CSV export, saved views and inline editing, with no code of its own beyond
 * the column list in assets/js/domains-table.js.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/money.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('domains');

$current_page = 'table';
$path_prefix = '../../';
$translationNamespaces = ['common', 'domains'];
$dtSearchPlaceholder = t('domains.table.search');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('domains', t('domains.nav.table'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="../../assets/css/data-table.css?v=7">
    <link rel="stylesheet" href="../../assets/css/domains.css?v=7">
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <?php echo Money::scriptTag(); ?>
    <script src="../../assets/js/money.js?v=1"></script>
</head>
<body data-mobile-module="domains" data-mobile-page="domains-table">
    <?php include '../includes/header.php'; ?>
    <?php /* New in the table view opens the register's Add (?add=1) - #185. */
          require_once __DIR__ . '/../../includes/action_row.php';
          renderActionRow(t('domains.title'), [actionRowNew('../?add=1', t('domains.list.new_domain'), true)]); ?>
    <div class="dt-page">
        <?php include '../../includes/data-table-skeleton.php'; ?>
    </div>
    <script src="../../assets/js/domains.js?v=2"></script>
    <script src="../../assets/js/data-table.js?v=9"></script>
    <script src="../../assets/js/domains-table.js?v=3"></script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
