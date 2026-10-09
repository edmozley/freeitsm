<?php
/**
 * Projects -> Settings (3.2.0).
 *
 * Five tabs, five capabilities (projects/settings/manifest.php). Only the tabs
 * this analyst may use are rendered. Every value is read from and saved to
 * api/projects/settings.php, which validates it and checks the same capability
 * as its tab - the defaults shown come from the server, so the screen and the
 * server cannot disagree about "not set yet". Behaviour: assets/js/projects-settings.js.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/ai_settings_panel.php';
I18n::initFromSession();
Tz::init();

require_once '../../includes/settings_manifest.php';
requireModuleAccess('projects');

$settingsManifest = settingsManifestFor('projects');
$visibleTabs      = settingsVisibleTabs(connectToDatabase(), (int) $_SESSION['analyst_id'], $settingsManifest);
$activeTabId      = settingsFirstTabId($visibleTabs);
if (!empty($_GET['tab']) && settingsTabVisible($visibleTabs, (string) $_GET['tab'])) {
    $activeTabId = (string) $_GET['tab'];
}

$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'projects'];
$tt = fn(string $k) => htmlspecialchars(t('projects.settings.' . $k));
$row = function (string $label, string $desc, string $control) {
    echo '<div class="set-row"><div><div class="lbl">' . $label . '</div><div class="desc">' . $desc . '</div></div><div>' . $control . '</div></div>';
};
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('projects.title') . ' - ' . t('projects.nav.settings')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=26">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=77">
    <link rel="stylesheet" href="../../assets/css/projects.css?v=19">
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=185">
</head>
<body data-mobile-module="projects" data-mobile-page="settings">
    <?php include '../includes/header.php'; ?>
    <div class="container prj-settings">
        <?php if (!$visibleTabs): ?>
            <div class="prj-panel"><h3><?php echo $tt('no_tabs_title'); ?></h3><p class="prj-muted"><?php echo $tt('no_tabs_body'); ?></p></div>
        <?php else: renderSettingsTabBar($visibleTabs, $activeTabId); endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'general')): ?>
        <div class="tab-content<?php echo $activeTabId === 'general' ? ' active' : ''; ?>" id="general-tab" data-capability="<?php echo Cap::PROJECTS_GENERAL; ?>" data-settings-tab="general">
            <h2><?php echo $tt('general_title'); ?></h2>
            <p class="prj-muted"><?php echo $tt('general_intro'); ?></p>
            <?php
            $row($tt('default_method'), $tt('default_method_desc'), '<select data-k="project_default_method"></select><div class="dflt" data-d="project_default_method"></div>');
            $row($tt('create_policy'), $tt('create_policy_desc'), '<select data-k="project_create_policy"><option value="anyone">' . $tt('create_anyone') . '</option><option value="managers">' . $tt('create_managers') . '</option></select><div class="dflt" data-d="project_create_policy"></div>');
            $row($tt('change_policy'), $tt('change_policy_desc'), '<select data-k="project_change_policy"><option value="team">' . $tt('change_team') . '</option><option value="anyone">' . $tt('change_anyone') . '</option></select><div class="dflt" data-d="project_change_policy"></div>');
            $row($tt('calendar'), $tt('calendar_desc'), '<select data-k="project_calendar"><option value="all">' . $tt('calendar_all') . '</option><option value="ends">' . $tt('calendar_ends') . '</option><option value="off">' . $tt('calendar_off') . '</option></select><div class="dflt" data-d="project_calendar"></div>');
            $row($tt('disruption'), $tt('disruption_desc'), '<select data-k="project_disruption"><option value="planned">' . $tt('disruption_planned') . '</option><option value="now">' . $tt('disruption_now') . '</option><option value="off">' . $tt('disruption_off') . '</option></select><div class="dflt" data-d="project_disruption"></div>');
            ?>
            <div class="prj-set-note"><?php echo $tt('delete_note'); ?></div>
            <div class="set-actions"><button type="button" class="btn btn-primary prj-btn" data-save="general"><?php echo htmlspecialchars(t('common.save')); ?></button></div>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'health')): ?>
        <div class="tab-content<?php echo $activeTabId === 'health' ? ' active' : ''; ?>" id="health-tab" data-capability="<?php echo Cap::PROJECTS_HEALTH; ?>" data-settings-tab="health">
            <h2><?php echo $tt('health_title'); ?></h2>
            <p class="prj-muted"><?php echo $tt('health_intro'); ?></p>
            <?php
            $row($tt('amber_days'), $tt('amber_days_desc'), '<input type="number" min="1" max="120" data-k="project_amber_days"><div class="dflt" data-d="project_amber_days"></div>');
            $row($tt('amber_progress'), $tt('amber_progress_desc'), '<input type="number" min="1" max="100" data-k="project_amber_progress"><div class="dflt" data-d="project_amber_progress"></div>');
            $row($tt('red_overdue'), $tt('red_overdue_desc'), '<input type="number" min="1" max="100" data-k="project_red_overdue_pct"><div class="dflt" data-d="project_red_overdue_pct"></div>');
            $row($tt('ticket_amber'), $tt('ticket_amber_desc'), '<input type="number" min="0" max="500" data-k="project_ticket_amber"><div class="dflt" data-d="project_ticket_amber"></div>');
            ?>
            <div class="set-actions"><button type="button" class="btn btn-primary prj-btn" data-save="health"><?php echo htmlspecialchars(t('common.save')); ?></button></div>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'roles')): ?>
        <div class="tab-content<?php echo $activeTabId === 'roles' ? ' active' : ''; ?>" id="roles-tab" data-capability="<?php echo Cap::PROJECTS_ROLES; ?>">
            <div class="prj-set-head">
                <div><h2><?php echo $tt('roles_title'); ?></h2><p class="prj-muted"><?php echo $tt('roles_intro'); ?></p></div>
                <button type="button" class="btn btn-primary prj-btn" id="roleAdd"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <ul class="prj-role-list" id="roleList"></ul>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'raid')): ?>
        <div class="tab-content<?php echo $activeTabId === 'raid' ? ' active' : ''; ?>" id="raid-tab" data-capability="<?php echo Cap::PROJECTS_RAID; ?>" data-settings-tab="raid">
            <h2><?php echo $tt('raid_title'); ?></h2>
            <p class="prj-muted"><?php echo $tt('raid_intro'); ?></p>
            <?php
            // Five boxes per scale, numbered - the number is what a risk stores.
            $scale = function (string $key) {
                $h = '<div class="prj-scale" data-k="' . $key . '">';
                for ($i = 1; $i <= 5; $i++) {
                    $h .= '<label class="prj-scale-step"><span class="prj-scale-n">' . $i . '</span><input type="text" maxlength="40" data-step="' . ($i - 1) . '"></label>';
                }
                return $h . '</div><div class="dflt" data-d="' . $key . '"></div>';
            };
            $row($tt('probability_labels'), $tt('probability_labels_desc'), $scale('project_probability_labels'));
            $row($tt('impact_labels'), $tt('impact_labels_desc'), $scale('project_impact_labels'));
            ?>
            <div class="set-actions"><button type="button" class="btn btn-primary prj-btn" data-save="raid"><?php echo htmlspecialchars(t('common.save')); ?></button></div>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'budget')): ?>
        <div class="tab-content<?php echo $activeTabId === 'budget' ? ' active' : ''; ?>" id="budget-tab" data-capability="<?php echo Cap::PROJECTS_BUDGET; ?>" data-settings-tab="budget">
            <h2><?php echo $tt('budget_title'); ?></h2>
            <p class="prj-muted"><?php echo $tt('budget_intro'); ?></p>
            <?php
            $row($tt('currency'), $tt('currency_desc'), '<input type="text" maxlength="3" style="text-transform:uppercase;max-width:120px" data-k="project_currency"><div class="dflt" data-d="project_currency"></div>');
            $row($tt('currency_per_project'), $tt('currency_per_project_desc'), '<select data-k="project_currency_per_project"><option value="0">' . htmlspecialchars(t('common.no')) . '</option><option value="1">' . htmlspecialchars(t('common.yes')) . '</option></select><div class="dflt" data-d="project_currency_per_project"></div>');
            $row($tt('labour_mode'), $tt('labour_mode_desc'), '<select data-k="project_labour_mode"><option value="hours">' . $tt('labour_hours') . '</option><option value="rate">' . $tt('labour_rate') . '</option><option value="analyst">' . $tt('labour_analyst') . '</option></select><div class="dflt" data-d="project_labour_mode"></div>');
            ?>
            <div class="set-actions"><button type="button" class="btn btn-primary prj-btn" data-save="budget"><?php echo htmlspecialchars(t('common.save')); ?></button></div>

            <h3 style="margin-top:28px"><?php echo $tt('rates'); ?></h3>
            <p class="prj-muted"><?php echo $tt('rates_desc'); ?></p>
            <p class="prj-muted"><?php echo $tt('rates_private'); ?></p>
            <div class="prj-rate-add">
                <label><?php echo $tt('rate_scope'); ?> <select id="rtScope"></select></label>
                <label><?php echo $tt('rate_amount'); ?> <input type="number" min="0" step="0.01" id="rtRate"></label>
                <label><?php echo $tt('rate_from'); ?> <input type="date" id="rtFrom"></label>
                <button type="button" class="btn btn-secondary" id="rtAdd"><?php echo $tt('rate_add'); ?></button>
            </div>
            <div id="rateList"></div>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'ai')): ?>
        <div class="tab-content<?php echo $activeTabId === 'ai' ? ' active' : ''; ?>" id="ai-tab" data-capability="<?php echo Cap::PROJECTS_AI; ?>">
            <h2><?php echo $tt('ai_title'); ?></h2>
            <p class="prj-muted"><?php echo $tt('ai_intro'); ?></p>
            <p class="prj-muted" style="margin-bottom:18px"><?php echo $tt('ai_privacy'); ?></p>
            <?php renderAiSettingsPanel('projects_ai'); ?>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'templates')): ?>
        <div class="tab-content<?php echo $activeTabId === 'templates' ? ' active' : ''; ?>" id="templates-tab" data-capability="<?php echo Cap::PROJECTS_TEMPLATES; ?>">
            <h2><?php echo $tt('templates_title'); ?></h2>
            <p class="prj-muted"><?php echo $tt('templates_intro'); ?></p>
            <h3 class="prj-set-sub"><?php echo $tt('templates_builtin'); ?></h3>
            <ul class="prj-role-list" id="tplBuiltin"></ul>
            <h3 class="prj-set-sub"><?php echo $tt('templates_saved'); ?></h3>
            <ul class="prj-role-list" id="tplSaved"></ul>
            <p class="prj-muted" id="tplNone" hidden><?php echo $tt('templates_none'); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Edit a saved template -->
    <div class="modal" id="prjTplModal" aria-hidden="true">
        <div class="modal-content" style="max-width:480px">
            <div class="modal-header"><?php echo $tt('template_edit'); ?></div>
            <div class="modal-body">
                <input type="hidden" id="tmId">
                <div class="form-group"><label for="tmName"><?php echo htmlspecialchars(t('projects.templates.name')); ?></label><input type="text" id="tmName" maxlength="150"></div>
                <div class="form-group"><label for="tmDesc"><?php echo htmlspecialchars(t('projects.templates.description')); ?></label><input type="text" id="tmDesc" maxlength="500"></div>
                <label class="prj-check"><input type="checkbox" id="tmActive" checked> <?php echo $tt('template_offered'); ?></label>
                <div class="prj-form-error" id="tmError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="tmDelete" style="margin-right:auto"><?php echo htmlspecialchars(t('common.delete')); ?></button>
                <button type="button" class="btn btn-secondary" data-prj-close="prjTplModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="tmSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Add / edit a role -->
    <div class="modal" id="prjRoleModal" aria-hidden="true">
        <div class="modal-content" style="max-width:480px">
            <div class="modal-header" id="rmTitle"></div>
            <div class="modal-body">
                <input type="hidden" id="rmId">
                <div class="form-group"><label for="rmName"><?php echo $tt('role_name'); ?></label><input type="text" id="rmName" maxlength="100"></div>
                <div class="form-group"><label for="rmDesc"><?php echo $tt('role_desc'); ?></label><input type="text" id="rmDesc" maxlength="255"></div>
                <label class="prj-check"><input type="checkbox" id="rmActive" checked> <?php echo $tt('role_active'); ?></label>
                <div class="prj-form-error" id="rmError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="rmDelete" style="margin-right:auto" hidden><?php echo htmlspecialchars(t('common.delete')); ?></button>
                <button type="button" class="btn btn-secondary" data-prj-close="prjRoleModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="rmSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <script src="../../assets/js/projects.js?v=7"></script>
    <script src="../../assets/js/projects-settings.js?v=6"></script>
    <script src="../../assets/js/ai-settings.js?v=2"></script>
    <script src="../../assets/js/mobile.js?v=78"></script>
</body>
</html>
