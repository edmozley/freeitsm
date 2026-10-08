<?php
/**
 * Reporting -> Report Packs -> the designer.
 *
 * A thin shell: the ribbon, toolbox, pages and properties are built by
 * assets/js/report-packs/designer.js from one configuration, and the pages are
 * drawn by the layout engine. Whether you may change the pack (owner / edit)
 * or only view and export it is decided by the server on every save - the
 * read-only mode here is a courtesy, not the control.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/branding.php';
requireModuleAccess('reporting');
I18n::initFromSession();
Tz::init();

$packId = (int)($_GET['id'] ?? 0);
$current_page = 'packs';
$path_prefix = '../../';
$translationNamespaces = ['common', 'reporting'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName()); ?> - <?php echo htmlspecialchars(t('reporting.packs.list.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=76">
    <link rel="stylesheet" href="../../assets/css/report-packs.css?v=1">
    <link rel="stylesheet" href="../../assets/css/report-packs-designer.css?v=1">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
</head>
<body class="rp-designer-body">
    <?php include '../includes/header.php'; ?>

    <div id="rpApp" class="rp-app" data-pack="<?php echo $packId; ?>">
        <div class="rp-boot"><?php echo htmlspecialchars(t('reporting.packs.list.loading')); ?></div>
    </div>

    <script>
        window.RP_API  = '../../api/reporting/packs/';
        window.RP_LOGO = <?php echo json_encode(brandingLogoUrl()); ?>;
    </script>
    <script src="../../assets/js/vendor/jspdf.umd.min.js"></script>
    <script src="../../assets/js/chart.min.js"></script>
    <script src="../../assets/js/report-packs/engine.js?v=1"></script>
    <script src="../../assets/js/report-packs/charts.js?v=1"></script>
    <script src="../../assets/js/report-packs/render-svg.js?v=1"></script>
    <script src="../../assets/js/report-packs/render-pdf.js?v=1"></script>
    <script src="../../assets/js/report-packs/runtime.js?v=1"></script>
    <script src="../../assets/js/report-packs/editor.js?v=1"></script>
    <script src="../../assets/js/report-packs/designer.js?v=2"></script>
</body>
</html>
