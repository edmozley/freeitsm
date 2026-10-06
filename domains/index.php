<?php
/**
 * Domains — the register (#154).
 *
 * Every domain the analyst's company holds (or every company they can see, in
 * the "All companies" view), with how long each has left, how well protected it
 * is, and a sidebar of the questions people actually ask: what needs attention,
 * what expires this month, what is unlocked. Behaviour is in
 * assets/js/domains-register.js; shared rendering in assets/js/domains.js.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('domains');

$current_page = 'register';
$path_prefix = '../';
$translationNamespaces = ['common', 'domains'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('domains.title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=25">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=76">
    <link rel="stylesheet" href="../assets/css/domains.css?v=7">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=175">
</head>
<body data-mobile-module="domains" data-mobile-page="domains-register">
    <?php include 'includes/header.php'; ?>

    <div class="dom-layout">
        <aside class="dom-sidebar">
            <h3><?php echo htmlspecialchars(t('domains.list.search')); ?></h3>
            <input type="text" id="domSearch" placeholder="<?php echo htmlspecialchars(t('domains.list.search_placeholder')); ?>" autocomplete="off">

            <h3><?php echo htmlspecialchars(t('domains.list.views')); ?></h3>
            <div class="dom-quick" id="domViews"></div>

            <h3><?php echo htmlspecialchars(t('domains.list.filters')); ?></h3>
            <select id="fCompany" style="display:none"></select>
            <select id="fStatus"></select>
            <select id="fPurpose"></select>
            <select id="fOwner"></select>
            <select id="fRegistrar"></select>
            <select id="fTag"></select>
            <button type="button" class="dom-btn" id="domClearFilters" style="width:100%;justify-content:center;margin-top:4px"><?php echo htmlspecialchars(t('domains.list.clear')); ?></button>
        </aside>

        <main class="dom-main">
            <div class="dom-stats" id="domStats"></div>

            <div class="dom-toolbar">
                <button type="button" class="dom-btn primary" id="btnAdd">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <?php echo htmlspecialchars(t('domains.list.add')); ?>
                </button>
                <button type="button" class="dom-btn" id="btnAddMany"><?php echo htmlspecialchars(t('domains.list.add_many')); ?></button>
                <button type="button" class="dom-btn" id="btnImport"><?php echo htmlspecialchars(t('domains.list.import')); ?></button>
                <span class="spacer"></span>
                <span id="domCount" class="dom-sub" style="font-size:13px"></span>
            </div>

            <div class="dom-selbar" id="domSelBar">
                <strong id="domSelCount"></strong>
                <button type="button" class="dom-btn" id="btnBulkEdit"><?php echo htmlspecialchars(t('domains.list.bulk_edit')); ?></button>
                <button type="button" class="dom-btn" id="btnBulkRefresh"><?php echo htmlspecialchars(t('domains.list.refresh_selected')); ?></button>
                <button type="button" class="dom-btn danger" id="btnBulkDelete"><?php echo htmlspecialchars(t('common.delete')); ?></button>
                <span class="spacer" style="flex:1"></span>
                <button type="button" class="dom-btn" id="btnClearSel"><?php echo htmlspecialchars(t('domains.list.clear_selection')); ?></button>
            </div>

            <div class="dom-card">
                <div style="overflow-x:auto">
                    <table class="dom-table" id="domTable">
                        <thead><tr id="domHead"></tr></thead>
                        <tbody id="domBody"><tr><td class="dom-empty" colspan="10"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr></tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Add one -->
    <div class="modal" id="mAdd">
        <div class="modal-content" style="max-width:620px">
            <div class="modal-header"><?php echo htmlspecialchars(t('domains.add.title')); ?></div>
            <div class="modal-body">
                <div class="dom-form-grid">
                    <div class="form-group full">
                        <label for="aName"><?php echo htmlspecialchars(t('domains.field.domain_name')); ?></label>
                        <input type="text" id="aName" placeholder="example.com" autocomplete="off">
                        <div class="dom-hint"><?php echo htmlspecialchars(t('domains.add.name_hint')); ?></div>
                    </div>
                    <div class="form-group full" id="aCompanyWrap" style="display:none">
                        <label for="aCompany"><?php echo htmlspecialchars(t('domains.field.company')); ?></label>
                        <select id="aCompany"></select>
                    </div>
                    <div class="form-group"><label for="aPurpose"><?php echo htmlspecialchars(t('domains.field.purpose')); ?></label><select id="aPurpose"></select></div>
                    <div class="form-group"><label for="aStatus"><?php echo htmlspecialchars(t('domains.field.status')); ?></label><select id="aStatus"></select></div>
                    <div class="form-group"><label for="aOwner"><?php echo htmlspecialchars(t('domains.field.owner')); ?></label><select id="aOwner"></select></div>
                    <div class="form-group"><label for="aRenewal"><?php echo htmlspecialchars(t('domains.field.renewal_mode')); ?></label><select id="aRenewal"></select></div>
                    <div class="form-group full"><label for="aTags"><?php echo htmlspecialchars(t('domains.field.tags')); ?></label><input type="text" id="aTags" placeholder="<?php echo htmlspecialchars(t('domains.field.tags_hint')); ?>"></div>
                </div>
                <div class="dom-hint" id="aLookupNote"><?php echo htmlspecialchars(t('domains.add.lookup_note')); ?></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close="mAdd"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="aSave"><?php echo htmlspecialchars(t('domains.list.add')); ?></button>
            </div>
        </div>
    </div>

    <!-- Add many -->
    <div class="modal" id="mMany">
        <div class="modal-content" style="max-width:680px">
            <div class="modal-header"><?php echo htmlspecialchars(t('domains.many.title')); ?></div>
            <div class="modal-body">
                <div id="manyForm">
                    <div class="form-group">
                        <label for="mText"><?php echo htmlspecialchars(t('domains.many.label')); ?></label>
                        <textarea id="mText" rows="9" placeholder="example.com&#10;example.co.uk&#10;example-shop.com"></textarea>
                        <div class="dom-hint"><?php echo htmlspecialchars(t('domains.many.hint')); ?></div>
                    </div>
                    <div class="dom-form-grid">
                        <div class="dom-section-title"><?php echo htmlspecialchars(t('domains.many.defaults')); ?></div>
                        <div class="form-group full" id="mCompanyWrap" style="display:none"><label for="mCompany"><?php echo htmlspecialchars(t('domains.field.company')); ?></label><select id="mCompany"></select></div>
                        <div class="form-group"><label for="mPurpose"><?php echo htmlspecialchars(t('domains.field.purpose')); ?></label><select id="mPurpose"></select></div>
                        <div class="form-group"><label for="mStatus"><?php echo htmlspecialchars(t('domains.field.status')); ?></label><select id="mStatus"></select></div>
                        <div class="form-group"><label for="mOwner"><?php echo htmlspecialchars(t('domains.field.owner')); ?></label><select id="mOwner"></select></div>
                        <div class="form-group"><label for="mTags"><?php echo htmlspecialchars(t('domains.field.tags')); ?></label><input type="text" id="mTags"></div>
                    </div>
                </div>
                <div id="manyProgress" style="display:none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="mCancel" data-close="mMany"><?php echo htmlspecialchars(t('common.close')); ?></button>
                <button type="button" class="btn btn-primary" id="mSave"><?php echo htmlspecialchars(t('domains.list.add')); ?></button>
            </div>
        </div>
    </div>

    <!-- Import CSV -->
    <div class="modal" id="mImport">
        <div class="modal-content" style="max-width:820px">
            <div class="modal-header"><?php echo htmlspecialchars(t('domains.import.title')); ?></div>
            <div class="modal-body">
                <div id="impStep1">
                    <p class="dom-hint" style="font-size:13px"><?php echo htmlspecialchars(t('domains.import.intro')); ?></p>
                    <input type="file" id="impFile" accept=".csv,text/csv">
                    <div class="form-group" id="impCompanyWrap" style="display:none;margin-top:14px"><label for="impCompany"><?php echo htmlspecialchars(t('domains.field.company')); ?></label><select id="impCompany"></select></div>
                </div>
                <div id="impStep2" style="display:none">
                    <p class="dom-hint" style="font-size:13px"><?php echo htmlspecialchars(t('domains.import.map_intro')); ?></p>
                    <div id="impMap" class="dom-form-grid"></div>
                    <div id="impPreview" style="overflow-x:auto;margin-top:10px"></div>
                </div>
                <div id="impProgress" style="display:none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close="mImport"><?php echo htmlspecialchars(t('common.close')); ?></button>
                <button type="button" class="btn btn-primary" id="impGo" style="display:none"><?php echo htmlspecialchars(t('domains.import.go')); ?></button>
            </div>
        </div>
    </div>

    <!-- Bulk edit -->
    <div class="modal" id="mBulk">
        <div class="modal-content" style="max-width:560px">
            <div class="modal-header"><?php echo htmlspecialchars(t('domains.bulk.title')); ?></div>
            <div class="modal-body">
                <p class="dom-hint" style="font-size:13px"><?php echo htmlspecialchars(t('domains.bulk.intro')); ?></p>
                <div class="dom-form-grid">
                    <div class="form-group"><label for="bStatus"><?php echo htmlspecialchars(t('domains.field.status')); ?></label><select id="bStatus"></select></div>
                    <div class="form-group"><label for="bPurpose"><?php echo htmlspecialchars(t('domains.field.purpose')); ?></label><select id="bPurpose"></select></div>
                    <div class="form-group"><label for="bOwner"><?php echo htmlspecialchars(t('domains.field.owner')); ?></label><select id="bOwner"></select></div>
                    <div class="form-group"><label for="bRenewal"><?php echo htmlspecialchars(t('domains.field.renewal_mode')); ?></label><select id="bRenewal"></select></div>
                    <div class="form-group"><label for="bRegistrar"><?php echo htmlspecialchars(t('domains.field.registrar')); ?></label><select id="bRegistrar"></select></div>
                    <div class="form-group"><label for="bMonitoring"><?php echo htmlspecialchars(t('domains.field.monitoring_enabled')); ?></label>
                        <select id="bMonitoring"><option value=""><?php echo htmlspecialchars(t('domains.bulk.no_change')); ?></option><option value="1"><?php echo htmlspecialchars(t('common.yes')); ?></option><option value="0"><?php echo htmlspecialchars(t('common.no')); ?></option></select></div>
                    <div class="form-group full"><label for="bTags"><?php echo htmlspecialchars(t('domains.field.tags')); ?></label><input type="text" id="bTags" placeholder="<?php echo htmlspecialchars(t('domains.bulk.tags_hint')); ?>"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close="mBulk"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="bSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <script src="../assets/js/domains.js?v=1"></script>
    <script src="../assets/js/domains-register.js?v=3"></script>
    <script src="../assets/js/mobile.js?v=72"></script>
</body>
</html>
