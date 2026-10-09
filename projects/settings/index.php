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
    <link rel="stylesheet" href="../../assets/css/projects.css?v=44">
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=189">
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
            // 3.3.0: priority words, the portfolio's order, what the burn-up counts.
            $pr = '<div class="prj-scale" data-k="project_priority_labels">';
            for ($i = 1; $i <= 4; $i++) $pr .= '<label class="prj-scale-step"><span class="prj-scale-n">' . $i . '</span><input type="text" maxlength="40" data-step="' . ($i - 1) . '"></label>';
            $row($tt('priority_labels'), $tt('priority_labels_desc'), $pr . '</div><div class="dflt" data-d="project_priority_labels"></div>');
            $row($tt('portfolio_sort'), $tt('portfolio_sort_desc'), '<select data-k="project_portfolio_sort"><option value="target">' . htmlspecialchars(t('projects.portfolio.sort_target')) . '</option><option value="priority">' . htmlspecialchars(t('projects.portfolio.sort_priority')) . '</option><option value="health">' . htmlspecialchars(t('projects.portfolio.sort_health')) . '</option><option value="name">' . htmlspecialchars(t('projects.portfolio.sort_name')) . '</option></select><div class="dflt" data-d="project_portfolio_sort"></div>');
            $row($tt('burnup_measure'), $tt('burnup_measure_desc'), '<select data-k="project_burnup_measure"><option value="tasks">' . $tt('measure_tasks') . '</option><option value="hours">' . $tt('measure_hours') . '</option></select><div class="dflt" data-d="project_burnup_measure"></div>');
            // 3.3.0 change control.
            $row($tt('baseline_auto'), $tt('baseline_auto_desc'), '<select data-k="project_baseline_auto"><option value="stage">' . $tt('baseline_auto_stage') . '</option><option value="start">' . $tt('baseline_auto_start') . '</option><option value="off">' . $tt('baseline_auto_off') . '</option></select><div class="dflt" data-d="project_baseline_auto"></div>');
            $row($tt('change_approver'), $tt('change_approver_desc'), '<select data-k="project_change_approver"><option value="owner">' . $tt('approver_owner') . '</option><option value="team">' . $tt('approver_team') . '</option><option value="managers">' . $tt('approver_managers') . '</option></select><div class="dflt" data-d="project_change_approver"></div>');
            $row($tt('change_self'), $tt('change_self_desc'), '<select data-k="project_change_self"><option value="1">' . htmlspecialchars(t('common.yes')) . '</option><option value="0">' . htmlspecialchars(t('common.no')) . '</option></select><div class="dflt" data-d="project_change_self"></div>');
            // 3.3.0 the Toolbox.
            $row($tt('toolbox'), $tt('toolbox_desc'), '<select data-k="project_toolbox"><option value="1">' . htmlspecialchars(t('common.yes')) . '</option><option value="0">' . htmlspecialchars(t('common.no')) . '</option></select><div class="dflt" data-d="project_toolbox"></div>');
            // 3.3.0 the AI assistant's memory.
            $row($tt('assistant_memory'), $tt('assistant_memory_desc'), '<select data-k="project_assistant_memory"><option value="person">' . $tt('memory_person') . '</option><option value="project">' . $tt('memory_project') . '</option></select><div class="dflt" data-d="project_assistant_memory"></div>');
            // 3.3.0 reminders.
            $row($tt('overdue_digest'), $tt('overdue_digest_desc'), '<select data-k="project_overdue_digest"><option value="weekly">' . $tt('digest_weekly') . '</option><option value="daily">' . $tt('digest_daily') . '</option><option value="off">' . $tt('digest_off') . '</option></select><div class="dflt" data-d="project_overdue_digest"></div>');
            $row($tt('nudge_days'), $tt('nudge_days_desc'), '<input type="number" min="0" max="30" data-k="project_nudge_days"><div class="dflt" data-d="project_nudge_days"></div>');
            // 3.3.0 members-only projects.
            $row($tt('default_visibility'), $tt('default_visibility_desc'), '<select data-k="project_default_visibility"><option value="everyone">' . htmlspecialchars(t('projects.visibility.everyone')) . '</option><option value="members">' . htmlspecialchars(t('projects.visibility.members')) . '</option></select><div class="dflt" data-d="project_default_visibility"></div>');
            // 3.3.0 gate checklists.
            $row($tt('gate_checklist'), $tt('gate_checklist_desc'), '<select data-k="project_gate_checklist"><option value="block">' . $tt('gatecheck_block') . '</option><option value="warn">' . $tt('gatecheck_warn') . '</option></select><div class="dflt" data-d="project_gate_checklist"></div>');
            // 3.3.0 benefits.
            $row($tt('benefit_review_months'), $tt('benefit_review_months_desc'), '<input type="number" min="0" max="24" data-k="project_benefit_review_months"><div class="dflt" data-d="project_benefit_review_months"></div>');
            $row($tt('benefit_notify'), $tt('benefit_notify_desc'), '<select data-k="project_benefit_notify"><option value="both">' . $tt('bnotify_both') . '</option><option value="owner">' . $tt('bnotify_owner') . '</option></select><div class="dflt" data-d="project_benefit_notify"></div>');
            // 3.3.0 intake and approval.
            $row($tt('proposal_approval'), $tt('proposal_approval_desc'), '<select data-k="project_proposal_approval"><option value="forms">' . $tt('proposal_forms') . '</option><option value="all">' . $tt('proposal_all') . '</option><option value="off">' . $tt('proposal_off') . '</option></select><div class="dflt" data-d="project_proposal_approval"></div>');
            $row($tt('proposal_approver'), $tt('proposal_approver_desc'), '<select data-k="project_proposal_approver"><option value="managers">' . $tt('approver2_managers') . '</option><option value="person">' . $tt('approver2_person') . '</option></select><div class="dflt" data-d="project_proposal_approver"></div>');
            $people = '<option value="0">' . $tt('nobody') . '</option>';
            try { foreach (connectToDatabase()->query("SELECT id, full_name FROM analysts WHERE is_active = 1 ORDER BY full_name") as $an) $people .= '<option value="' . (int)$an['id'] . '">' . htmlspecialchars($an['full_name']) . '</option>'; } catch (Throwable $e) { /* the list stays empty */ }
            $row($tt('proposal_person'), $tt('proposal_person_desc'), '<select data-k="project_proposal_approver_id">' . $people . '</select><div class="dflt" data-d="project_proposal_approver_id"></div>');
            $row($tt('proposal_on_approve'), $tt('proposal_on_approve_desc'), '<select data-k="project_proposal_on_approve"><option value="proposed">' . $tt('onapprove_proposed') . '</option><option value="active">' . $tt('onapprove_active') . '</option></select><div class="dflt" data-d="project_proposal_on_approve"></div>');
            $row($tt('change_apply'), $tt('change_apply_desc'), '<select data-k="project_change_apply"><option value="plan">' . $tt('apply_plan') . '</option><option value="baseline">' . $tt('apply_baseline') . '</option></select><div class="dflt" data-d="project_change_apply"></div>');
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
            // 3.3.0: what missed dates do to health - nothing, amber or red.
            $effect = fn(string $k) => '<select data-k="' . $k . '"><option value="off">' . $tt('effect_off') . '</option><option value="amber">' . $tt('effect_amber') . '</option><option value="red">' . $tt('effect_red') . '</option></select><div class="dflt" data-d="' . $k . '"></div>';
            $row($tt('health_milestones'), $tt('health_milestones_desc'), $effect('project_health_milestones'));
            $row($tt('health_raid_late'), $tt('health_raid_late_desc'), $effect('project_health_raid_late'));
            // Capacity (3.3.0) - the Capacity page reads these.
            echo '<h3 class="prj-set-sub">' . $tt('capacity_title') . '</h3><p class="prj-muted">' . $tt('capacity_intro') . '</p>';
            $row($tt('capacity_hours'), $tt('capacity_hours_desc'), '<input type="number" min="1" max="80" step="0.5" data-k="project_capacity_hours"><div class="dflt" data-d="project_capacity_hours"></div>');
            $row($tt('capacity_amber'), $tt('capacity_amber_desc'), '<input type="number" min="50" max="100" data-k="project_capacity_amber"><div class="dflt" data-d="project_capacity_amber"></div>');
            $row($tt('capacity_projects'), $tt('capacity_projects_desc'), '<input type="number" min="2" max="20" data-k="project_capacity_projects"><div class="dflt" data-d="project_capacity_projects"></div>');
            $days = '<div class="prj-days" data-k="project_capacity_days">';
            for ($i = 1; $i <= 7; $i++) $days .= '<label class="prj-check"><input type="checkbox" data-day="' . $i . '"> ' . $tt('day_' . $i) . '</label>';
            $row($tt('capacity_days'), $tt('capacity_days_desc'), $days . '</div><div class="dflt" data-d="project_capacity_days"></div>');
            $row($tt('capacity_desk'), $tt('capacity_desk_desc'), '<select data-k="project_capacity_desk"><option value="1">' . htmlspecialchars(t('common.yes')) . '</option><option value="0">' . htmlspecialchars(t('common.no')) . '</option></select><div class="dflt" data-d="project_capacity_desk"></div>');
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
            // 3.3.0: the forecast.
            $row($tt('cost_basis'), $tt('cost_basis_desc'), '<select data-k="project_cost_basis"><option value="actual">' . $tt('basis_actual') . '</option><option value="forecast">' . $tt('basis_forecast') . '</option></select><div class="dflt" data-d="project_cost_basis"></div>');
            $row($tt('forecast_labour'), $tt('forecast_labour_desc'), '<select data-k="project_forecast_labour"><option value="1">' . htmlspecialchars(t('common.yes')) . '</option><option value="0">' . htmlspecialchars(t('common.no')) . '</option></select><div class="dflt" data-d="project_forecast_labour"></div>');
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

    <script src="../../assets/js/projects.js?v=10"></script>
    <script src="../../assets/js/projects-settings.js?v=16"></script>
    <script src="../../assets/js/ai-settings.js?v=2"></script>
    <script src="../../assets/js/mobile.js?v=78"></script>
</body>
</html>
