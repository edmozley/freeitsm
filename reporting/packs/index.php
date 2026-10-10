<?php
/**
 * Reporting -> Report Packs: every pack the analyst can open.
 *
 * Packs are private until shared (see includes/report_packs/access.php). This page
 * lists the ones you own and the ones shared with you, and is where you make,
 * copy, share and delete them. Designing happens in designer.php.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
requireModuleAccess('reporting');
I18n::initFromSession();
Tz::init();

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
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=78">
    <link rel="stylesheet" href="../../assets/css/report-packs.css?v=1">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=192">
</head>
<body data-mobile-module="reporting" data-mobile-page="rep-packs">
    <?php include '../includes/header.php'; ?>

    <div class="main-container rp-list-page">
        <div class="rp-list-toolbar">
            <div>
                <h1><?php echo htmlspecialchars(t('reporting.packs.list.title')); ?></h1>
                <p class="rp-list-sub"><?php echo htmlspecialchars(t('reporting.packs.list.subtitle')); ?></p>
            </div>
            <div class="rp-list-actions">
                <input type="search" id="rpSearch" class="rp-search" placeholder="<?php echo htmlspecialchars(t('reporting.packs.list.search')); ?>" oninput="RPList.render()">
                <button class="btn btn-primary" onclick="RPList.openNew()"><?php echo htmlspecialchars(t('reporting.packs.list.new')); ?></button>
            </div>
        </div>

        <div id="rpGrid" class="rp-grid" aria-live="polite">
            <div class="rp-loading"><?php echo htmlspecialchars(t('reporting.packs.list.loading')); ?></div>
        </div>
    </div>

    <!-- New / copy -->
    <div class="modal" id="rpNewModal" role="dialog" aria-modal="true" aria-labelledby="rpNewTitle">
        <div class="modal-content rp-modal-sm">
            <div class="modal-header" id="rpNewTitle"><?php echo htmlspecialchars(t('reporting.packs.list.new_title')); ?></div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="rpNewName"><?php echo htmlspecialchars(t('reporting.packs.list.name')); ?></label>
                    <input type="text" id="rpNewName" class="rp-input" maxlength="200" placeholder="<?php echo htmlspecialchars(t('reporting.packs.list.name_ph')); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="rpNewDesc"><?php echo htmlspecialchars(t('reporting.packs.list.description')); ?></label>
                    <input type="text" id="rpNewDesc" class="rp-input" maxlength="500">
                </div>
                <div class="rp-form-error" id="rpNewError" hidden></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="RPList.closeModal('rpNewModal')"><?php echo htmlspecialchars(t('reporting.packs.list.cancel')); ?></button>
                <button class="btn btn-primary" id="rpNewSave" onclick="RPList.create()"><?php echo htmlspecialchars(t('reporting.packs.list.create')); ?></button>
            </div>
        </div>
    </div>

    <!-- Share -->
    <div class="modal" id="rpShareModal" role="dialog" aria-modal="true" aria-labelledby="rpShareTitle">
        <div class="modal-content rp-modal-md">
            <div class="modal-header" id="rpShareTitle"><?php echo htmlspecialchars(t('reporting.packs.share.title')); ?></div>
            <div class="modal-body">
                <p class="rp-hint"><?php echo htmlspecialchars(t('reporting.packs.share.intro')); ?></p>
                <div class="rp-share-add">
                    <select id="rpShareType" class="rp-input rp-share-type" onchange="RPList.fillShareTargets()">
                        <option value="analyst"><?php echo htmlspecialchars(t('reporting.packs.share.type_analyst')); ?></option>
                        <option value="team"><?php echo htmlspecialchars(t('reporting.packs.share.type_team')); ?></option>
                        <option value="department"><?php echo htmlspecialchars(t('reporting.packs.share.type_department')); ?></option>
                    </select>
                    <input type="text" id="rpShareTarget" class="rp-input" list="rpShareTargets" placeholder="<?php echo htmlspecialchars(t('reporting.packs.share.pick')); ?>">
                    <datalist id="rpShareTargets"></datalist>
                    <select id="rpShareLevel" class="rp-input rp-share-level">
                        <option value="0"><?php echo htmlspecialchars(t('reporting.packs.share.can_view')); ?></option>
                        <option value="1"><?php echo htmlspecialchars(t('reporting.packs.share.can_edit')); ?></option>
                    </select>
                    <button class="btn btn-secondary" onclick="RPList.addShare()"><?php echo htmlspecialchars(t('reporting.packs.share.add')); ?></button>
                </div>
                <div class="rp-form-error" id="rpShareError" hidden></div>
                <ul class="rp-share-list" id="rpShareList"></ul>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="RPList.closeModal('rpShareModal')"><?php echo htmlspecialchars(t('reporting.packs.list.cancel')); ?></button>
                <button class="btn btn-primary" onclick="RPList.saveShares()"><?php echo htmlspecialchars(t('reporting.packs.list.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Delete -->
    <div class="modal" id="rpDeleteModal" role="dialog" aria-modal="true" aria-labelledby="rpDeleteTitle">
        <div class="modal-content rp-modal-sm">
            <div class="modal-header" id="rpDeleteTitle"><?php echo htmlspecialchars(t('reporting.packs.list.delete_title')); ?></div>
            <div class="modal-body"><p id="rpDeleteText"></p></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="RPList.closeModal('rpDeleteModal')"><?php echo htmlspecialchars(t('reporting.packs.list.cancel')); ?></button>
                <button class="btn rp-btn-danger" onclick="RPList.confirmDelete()"><?php echo htmlspecialchars(t('reporting.packs.list.delete')); ?></button>
            </div>
        </div>
    </div>

    <script>window.RP_API = '../../api/reporting/packs/';</script>
    <script src="../../assets/js/report-packs/list.js?v=1"></script>
    <script src="../../assets/js/mobile.js?v=78"></script>
</body>
</html>
