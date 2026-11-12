<?php
/**
 * Assets -> Leases (3.5.0): one lease - the agreement, its schedules, the assets
 * on each and what became of them, and its documents. Behaviour in
 * assets/js/leases.js; rules in includes/leases.php.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/money.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/leases.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('assets');

$conn = connectToDatabase();
$leaseId = (int)($_GET['id'] ?? 0);
$exists = $leaseId > 0 && leaseReady($conn) && leaseCanView($conn, (int)$_SESSION['analyst_id'], $leaseId);

$current_page = 'leases';
$path_prefix = '../../';
$translationNamespaces = ['common', 'asset-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('assets', t('asset-management.nav.leases'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="../../assets/css/leases.css?v=5">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=4"></script>
    <?php echo Money::scriptTag(); ?>
    <script src="../../assets/js/money.js?v=1"></script>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="ls-wrap">
        <a class="ls-back" href="./">&larr; <?php echo htmlspecialchars(t('asset-management.leases.back')); ?></a>
        <?php if (!$exists): ?>
            <div class="ls-card"><div class="ls-empty"><h3><?php echo htmlspecialchars(t('asset-management.leases.not_found')); ?></h3></div></div>
        <?php else: ?>
            <div id="lsLease"><div class="ls-card"><div class="ls-empty"><?php echo htmlspecialchars(t('asset-management.common.loading')); ?></div></div></div>
            <?php require_once '../../includes/documents_panel.php'; renderDocumentsPanel('lease', $leaseId, '../../'); ?>
        <?php endif; ?>
    </div>

    <?php if ($exists): ?>
    <script>window.LEASES_CFG = <?php echo json_encode([
        'page'    => 'lease',
        'leaseId' => $leaseId,
        'api'     => '../../api/assets/leases/',
        'base'    => BASE_URL,
        'multi'   => isMultiTenant($conn),
        'contracts' => moduleIsEnabled($conn, 'contracts'),   // GH #217: no contract picker or link while Contracts is switched off
    ]); ?>;</script>
    <script src="../../assets/js/leases.js?v=3"></script>
    <?php endif; ?>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
