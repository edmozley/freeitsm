<?php
/**
 * Starters & leavers -> Settings (4.6.0).
 *
 * One tab, Templates, behind its own capability (starters/settings/manifest.php).
 * Templates are read from and saved to api/starters/templates.php, which checks
 * the same capability. Behaviour: assets/js/starters-settings.js.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

require_once '../../includes/settings_manifest.php';
requireModuleAccess('starters');

$settingsManifest = settingsManifestFor('starters');
$visibleTabs      = settingsVisibleTabs(connectToDatabase(), (int) $_SESSION['analyst_id'], $settingsManifest);
$activeTabId      = settingsFirstTabId($visibleTabs);

$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'starters'];
$tt = fn(string $k) => htmlspecialchars(t('starters.settings.' . $k));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('starters', t('starters.nav.settings'))); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=28">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=85">
    <link rel="stylesheet" href="../../assets/css/starters.css?v=4">
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="starters" data-mobile-page="settings">
    <?php include '../includes/header.php'; ?>
    <div class="container jml-settings">
        <?php if (!$visibleTabs): ?>
            <div class="jml-panel"><h3><?php echo $tt('no_tabs_title'); ?></h3><p class="jml-muted"><?php echo $tt('no_tabs_body'); ?></p></div>
        <?php else: renderSettingsTabBar($visibleTabs, $activeTabId); endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'templates')): ?>
        <div class="tab-content active" id="templates-tab" data-capability="<?php echo capKey(Cap::STARTERS_TEMPLATES); ?>" data-settings-tab="templates">
            <div class="jml-panel-h"><h2><?php echo $tt('templates_title'); ?></h2><span class="grow"></span>
                <button type="button" class="jml-btn primary" id="jmlTplNew"><?php echo $tt('new'); ?></button></div>
            <p class="jml-muted"><?php echo $tt('templates_intro'); ?></p>
            <div id="jmlTplList"></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="modal jml-modal jml-tpl-modal" id="jmlTplModal" role="dialog" aria-modal="true" aria-labelledby="jmlTplTitle">
        <div class="modal-content">
            <div class="modal-header" id="jmlTplTitle"><?php echo $tt('edit_title'); ?></div>
            <div class="modal-body">
                <div class="jml-row">
                    <div class="jml-field"><label for="jmlTplName"><?php echo $tt('name'); ?></label><input type="text" id="jmlTplName" maxlength="150" autocomplete="off" data-lpignore="true" data-1p-ignore data-bwignore placeholder="<?php echo $tt('name_ph'); ?>"></div>
                    <div class="jml-field"><label for="jmlTplKind"><?php echo $tt('kind'); ?></label>
                        <select id="jmlTplKind"><?php foreach (['starter', 'mover', 'leaver'] as $k): ?><option value="<?php echo $k; ?>"><?php echo htmlspecialchars(t('starters.kinds.' . $k)); ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="jml-field"><label for="jmlTplDesc"><?php echo $tt('description'); ?></label><input type="text" id="jmlTplDesc" maxlength="1000" autocomplete="off" data-lpignore="true" data-1p-ignore data-bwignore></div>
                <div class="jml-field"><label class="jml-switch"><input type="checkbox" id="jmlTplActive"> <?php echo $tt('active'); ?> - <span class="jml-dim"><?php echo $tt('active_desc'); ?></span></label></div>

                <?php /* The questions (optional): a form from Forms. Its answers pick the
                         template, decide which steps apply and fill in their words. */ ?>
                <div class="jml-field" id="jmlTplFormField">
                    <label for="jmlTplForm"><?php echo $tt('form'); ?></label>
                    <select id="jmlTplForm"></select>
                    <div class="jml-hint"><?php echo $tt('form_hint'); ?></div>
                </div>
                <div id="jmlTplFormBits" hidden>
                    <div class="jml-field">
                        <div class="lbl"><?php echo $tt('match'); ?></div>
                        <div class="jml-rule" id="jmlTplMatch"></div>
                        <div class="jml-hint"><?php echo $tt('match_hint'); ?></div>
                    </div>
                </div>

                <div class="jml-field">
                    <div class="lbl"><?php echo $tt('steps'); ?></div>
                    <div class="jml-chips" id="jmlTplChips"></div>
                    <table class="jml-steps-edit" id="jmlTplStepsTable">
                        <thead><tr>
                            <th><?php echo $tt('step_title'); ?></th>
                            <th><?php echo $tt('step_team'); ?></th>
                            <th><?php echo $tt('step_analyst'); ?></th>
                            <th title="<?php echo $tt('step_days_tip'); ?>"><?php echo $tt('step_days'); ?></th>
                            <th><?php echo $tt('step_checklist'); ?></th>
                            <th></th>
                        </tr></thead>
                    </table>
                    <button type="button" class="jml-btn small" id="jmlTplAddStep" style="margin-top:8px"><?php echo $tt('step_add'); ?></button>
                    <div class="jml-hint"><?php echo $tt('step_days_tip'); ?></div>
                </div>
                <div class="jml-error" id="jmlTplError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn jml-btn danger" id="jmlTplDelete" style="margin-right:auto"><?php echo $tt('delete'); ?></button>
                <button type="button" class="btn btn-secondary" data-close><?php echo $tt('cancel'); ?></button>
                <button type="button" class="btn btn-primary jml-btn primary" id="jmlTplSave"><?php echo $tt('save'); ?></button>
            </div>
        </div>
    </div>

    <script src="../../assets/js/starters.js?v=2"></script>
    <script src="../../assets/js/starters-settings.js?v=5"></script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
