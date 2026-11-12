<?php
/**
 * Assets -> Leases (3.5.0): every lease agreement, and Due back - what has to go
 * back to the lessor and when. Behaviour in assets/js/leases.js; rules in
 * includes/leases.php.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/money.php';
require_once '../../includes/tenancy.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('assets');

$conn = connectToDatabase();
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
        <div class="ls-top">
            <div class="ls-tabs" role="tablist">
                <button type="button" data-ls-tab="leases" class="active"><?php echo htmlspecialchars(t('asset-management.leases.tab_leases')); ?></button>
                <button type="button" data-ls-tab="due"><?php echo htmlspecialchars(t('asset-management.leases.tab_due')); ?></button>
            </div>
            <span class="ls-spacer"></span>
            <input type="search" id="lsSearch" data-ls-only="leases" placeholder="<?php echo htmlspecialchars(t('asset-management.leases.search_ph')); ?>" autocomplete="off">
            <button type="button" class="btn btn-primary" id="lsAdd" data-ls-only="leases"><?php echo htmlspecialchars(t('asset-management.common.add')); ?></button>
            <span class="ls-dim" id="lsDueCount" data-ls-only="due" hidden></span>
            <select id="lsDays" data-ls-only="due" hidden title="<?php echo htmlspecialchars(t('asset-management.leases.window_title')); ?>">
                <?php foreach ([30, 60, 90, 180, 365] as $d): ?>
                <option value="<?php echo $d; ?>"<?php echo $d === 90 ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('asset-management.leases.window_days', ['n' => $d])); ?></option>
                <?php endforeach; ?>
                <option value="0"><?php echo htmlspecialchars(t('asset-management.leases.window_all')); ?></option>
            </select>
            <a class="btn btn-secondary" id="lsExport" data-ls-only="due" hidden href="#"><?php echo htmlspecialchars(t('asset-management.leases.export')); ?></a>
        </div>

        <div id="lsListView"><div id="lsListBody"><div class="ls-empty"><?php echo htmlspecialchars(t('asset-management.common.loading')); ?></div></div></div>
        <div id="lsDueView" hidden>
            <p class="ls-dim" style="margin:0 0 10px"><?php echo htmlspecialchars(t('asset-management.leases.due_intro')); ?></p>
            <div id="lsDueBody"></div>
        </div>
    </div>

    <script>window.LEASES_CFG = <?php echo json_encode([
        'page'  => 'list',
        'api'   => '../../api/assets/leases/',
        'base'  => BASE_URL,
        'multi' => isMultiTenant($conn),
        'contracts' => moduleIsEnabled($conn, 'contracts'),   // GH #217: no contract picker or link while Contracts is switched off
    ]); ?>;</script>
    <script src="../../assets/js/leases.js?v=3"></script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
