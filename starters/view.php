<?php
/**
 * Starters & leavers - one case (4.6.0).
 *
 * The person, the date, how far the work has got, and the four things a case
 * pulls together from other modules: its steps (Tasks), the kit the person holds
 * (Assets), their account (Users) and its documents (the shared documents panel).
 * Drawn by assets/js/starters-view.js from api/starters/get.php.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/documents_panel.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('starters');

$current_page = 'cases';
$path_prefix = '../';
$translationNamespaces = ['common', 'starters'];
$caseId = (int)($_GET['id'] ?? 0);
$tt = fn(string $k) => htmlspecialchars(t('starters.' . $k));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('starters')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=28">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=85">
    <link rel="stylesheet" href="../assets/css/starters.css?v=4">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="starters" data-mobile-page="starters-case">
    <?php include 'includes/header.php'; ?>

    <div class="jml-shell">
        <div class="jml-shell-pad">
            <a class="jml-back" href="./"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg><?php echo $tt('view.back'); ?></a>
            <div id="jmlCase" aria-live="polite"></div>
        </div>
    </div>

    <div class="modal jml-modal" id="jmlRecoverModal" role="dialog" aria-modal="true" aria-labelledby="jmlRecoverTitle">
        <div class="modal-content" style="max-width:480px">
            <div class="modal-header" id="jmlRecoverTitle"></div>
            <div class="modal-body">
                <p class="jml-muted" id="jmlRecoverBody" style="margin-top:0"></p>
                <div class="jml-field" id="jmlRecoverStatusField">
                    <label for="jmlRecoverStatus"><?php echo $tt('view.kit_recover_status'); ?></label>
                    <select id="jmlRecoverStatus"></select>
                </div>
                <div class="jml-error" id="jmlRecoverError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close><?php echo $tt('new.cancel'); ?></button>
                <button type="button" class="btn btn-primary jml-btn primary" id="jmlRecoverGo"><?php echo $tt('view.kit_recover'); ?></button>
            </div>
        </div>
    </div>

    <script>window.JML_CASE_ID = <?php echo $caseId; ?>;</script>
    <script src="../assets/js/starters.js?v=2"></script>
    <script src="../assets/js/starters-view.js?v=3"></script>
    <?php documentsPanelAssets('../'); ?>
    <script src="../assets/js/mobile.js?v=83"></script>
</body>
</html>
