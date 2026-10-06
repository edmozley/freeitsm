<?php
/**
 * Projects - one project (3.2.0).
 *
 * A banner in the project's own colours (icon, name, status, how it is run, the
 * health ring), then three tabs: Overview (the goal, the numbers, what is
 * happening now and coming up, recent activity), Plan (the phases / stages /
 * sprints with their tasks, drag a task between them, add tasks in place) and
 * History. Behaviour is in assets/js/projects-view.js.
 *
 * The project is loaded by the page's script through api/projects/get.php,
 * which applies the company scope - an id the analyst may not see shows the
 * not-found panel, never the project.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('projects');

$current_page = 'portfolio';
$path_prefix = '../';
$translationNamespaces = ['common', 'projects'];
$projectId = (int)($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('projects.title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=26">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=77">
    <link rel="stylesheet" href="../assets/css/projects.css?v=7">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=181">
</head>
<body data-mobile-module="projects" data-mobile-page="projects-view">
    <?php include 'includes/header.php'; ?>

    <div class="prj-page" id="prjPage" data-project-id="<?php echo $projectId; ?>">
        <a class="prj-back" href="<?php echo BASE_URL; ?>projects/">&larr; <?php echo htmlspecialchars(t('projects.view.back')); ?></a>

        <section class="prj-banner" id="prjBanner" hidden>
            <div class="prj-banner-main">
                <span class="prj-banner-icon" id="pvIcon"></span>
                <div class="prj-banner-text">
                    <div class="prj-banner-meta">
                        <span class="prj-code" id="pvCode"></span>
                        <span class="prj-pill" id="pvStatus"></span>
                        <span class="prj-chip" id="pvMethod"></span>
                        <span class="prj-chip" id="pvCompany" hidden></span>
                    </div>
                    <h1 id="pvName"></h1>
                    <p class="prj-banner-goal" id="pvGoal"></p>
                    <div class="prj-banner-facts" id="pvFacts"></div>
                </div>
                <div class="prj-banner-ring" id="pvRing"></div>
            </div>
            <div class="prj-banner-actions">
                <button type="button" class="btn prj-ghost-btn" id="pvEdit"><?php echo htmlspecialchars(t('projects.view.edit')); ?></button>
                <button type="button" class="btn prj-ghost-btn danger" id="pvDelete"><?php echo htmlspecialchars(t('projects.view.delete')); ?></button>
            </div>
        </section>

        <nav class="prj-tabs" id="prjTabs" hidden>
            <button type="button" data-tab="overview" class="active"><?php echo htmlspecialchars(t('projects.view.tab_overview')); ?></button>
            <button type="button" data-tab="plan"><?php echo htmlspecialchars(t('projects.view.tab_plan')); ?></button>
            <button type="button" data-tab="people" data-tool="people" hidden><?php echo htmlspecialchars(t('projects.tools.people')); ?></button>
            <button type="button" data-tab="scope" data-tool="scope" hidden><?php echo htmlspecialchars(t('projects.tools.scope')); ?></button>
            <button type="button" data-tab="raci" data-tool="raci" hidden><?php echo htmlspecialchars(t('projects.tools.raci')); ?></button>
            <button type="button" data-tab="connections"><?php echo htmlspecialchars(t('projects.view.tab_connections')); ?></button>
            <button type="button" data-tab="history"><?php echo htmlspecialchars(t('projects.view.tab_history')); ?></button>
        </nav>

        <section class="prj-tab-panel" data-panel="overview" id="pvOverview"></section>
        <section class="prj-tab-panel" data-panel="plan" id="pvPlan" hidden></section>
        <section class="prj-tab-panel" data-panel="people" id="pvPeople" hidden></section>
        <section class="prj-tab-panel" data-panel="scope" id="pvScope" hidden></section>
        <section class="prj-tab-panel" data-panel="raci" id="pvRaci" hidden></section>
        <section class="prj-tab-panel" data-panel="connections" id="pvConnections" hidden></section>
        <section class="prj-tab-panel" data-panel="history" id="pvHistory" hidden></section>

        <div class="prj-not-found" id="prjNotFound" hidden>
            <p><?php echo htmlspecialchars(t('projects.view.not_found')); ?></p>
            <a class="btn btn-secondary" href="<?php echo BASE_URL; ?>projects/"><?php echo htmlspecialchars(t('projects.view.back')); ?></a>
        </div>
    </div>

    <!-- Add / edit a deliverable (Scope) -->
    <div class="modal" id="prjItemModal" aria-hidden="true">
        <div class="modal-content" style="max-width:560px">
            <div class="modal-header" id="piTitle"></div>
            <div class="modal-body">
                <input type="hidden" id="piId">
                <div class="form-group"><label for="piName"><?php echo htmlspecialchars(t('projects.scope.item_title')); ?></label><input type="text" id="piName" maxlength="255" autocomplete="off"></div>
                <div class="prj-form-grid">
                    <div class="form-group"><label for="piMoscow"><?php echo htmlspecialchars(t('projects.scope.priority')); ?></label><select id="piMoscow"></select></div>
                    <div class="form-group"><label for="piStatus"><?php echo htmlspecialchars(t('projects.scope.status')); ?></label><select id="piStatus"></select></div>
                    <div class="form-group"><label for="piStage" id="piStageLabel"></label><select id="piStage"></select></div>
                </div>
                <div class="form-group"><label for="piDesc"><?php echo htmlspecialchars(t('projects.scope.description')); ?></label><textarea id="piDesc" rows="3"></textarea></div>
                <div class="form-group"><label for="piAcc"><?php echo htmlspecialchars(t('projects.scope.acceptance')); ?></label><textarea id="piAcc" rows="3" placeholder="<?php echo htmlspecialchars(t('projects.scope.acceptance_ph')); ?>"></textarea></div>
                <div class="prj-form-error" id="piError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="piDelete" style="margin-right:auto" hidden><?php echo htmlspecialchars(t('common.delete')); ?></button>
                <button type="button" class="btn btn-secondary" data-prj-close="prjItemModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="piSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Add someone to the project (People) -->
    <div class="modal" id="prjMemberModal" aria-hidden="true">
        <div class="modal-content" style="max-width:520px">
            <div class="modal-header"><?php echo htmlspecialchars(t('projects.people.add_title')); ?></div>
            <div class="modal-body">
                <div class="prj-seg" id="pmKind" role="tablist">
                    <button type="button" data-kind="analyst" class="active"><?php echo htmlspecialchars(t('projects.people.kind_analyst')); ?></button>
                    <button type="button" data-kind="team"><?php echo htmlspecialchars(t('projects.people.kind_team')); ?></button>
                    <button type="button" data-kind="person"><?php echo htmlspecialchars(t('projects.people.kind_person')); ?></button>
                </div>
                <div class="form-group" id="pmPickWrap"><label for="pmPick" id="pmPickLabel"></label><select id="pmPick"></select></div>
                <div class="form-group" id="pmPersonWrap" hidden>
                    <label for="pmPerson"><?php echo htmlspecialchars(t('projects.people.kind_person')); ?></label>
                    <input type="text" id="pmPerson" placeholder="<?php echo htmlspecialchars(t('projects.people.person_ph')); ?>" autocomplete="off">
                    <ul class="prj-conn-results" id="pmPersonResults" hidden></ul>
                    <input type="hidden" id="pmPersonId">
                </div>
                <div class="form-group"><label for="pmRole"><?php echo htmlspecialchars(t('projects.people.role')); ?></label><select id="pmRole"></select></div>
                <div class="prj-form-error" id="pmError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjMemberModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="pmSave"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
        </div>
    </div>

    <!-- Add / edit a phase, stage or sprint -->
    <div class="modal" id="prjStageModal" aria-hidden="true">
        <div class="modal-content" style="max-width:520px">
            <div class="modal-header" id="psTitle"></div>
            <div class="modal-body">
                <input type="hidden" id="psId">
                <div class="form-group">
                    <label for="psName"><?php echo htmlspecialchars(t('projects.plan.stage_name')); ?></label>
                    <input type="text" id="psName" maxlength="150" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="psGoal"><?php echo htmlspecialchars(t('projects.plan.stage_goal')); ?></label>
                    <input type="text" id="psGoal" maxlength="500" autocomplete="off">
                </div>
                <div class="prj-form-grid">
                    <div class="form-group">
                        <label for="psStart"><?php echo htmlspecialchars(t('projects.plan.stage_start')); ?></label>
                        <input type="date" id="psStart">
                    </div>
                    <div class="form-group">
                        <label for="psEnd"><?php echo htmlspecialchars(t('projects.plan.stage_end')); ?></label>
                        <input type="date" id="psEnd">
                    </div>
                    <div class="form-group">
                        <label for="psStatus"><?php echo htmlspecialchars(t('projects.plan.stage_status')); ?></label>
                        <select id="psStatus">
                            <option value="planned"><?php echo htmlspecialchars(t('projects.stage_status.planned')); ?></option>
                            <option value="active"><?php echo htmlspecialchars(t('projects.stage_status.active')); ?></option>
                            <option value="closed"><?php echo htmlspecialchars(t('projects.stage_status.closed')); ?></option>
                        </select>
                    </div>
                </div>
                <div class="prj-form-error" id="psError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="psDelete" style="margin-right:auto" hidden><?php echo htmlspecialchars(t('common.delete')); ?></button>
                <button type="button" class="btn btn-secondary" data-prj-close="prjStageModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="psSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <?php include 'includes/project_form.php'; ?>

    <script src="../assets/js/projects.js?v=5"></script>
    <script src="../assets/js/projects-tools.js?v=1"></script>
    <script src="../assets/js/projects-view.js?v=6"></script>
    <script src="../assets/js/mobile.js?v=76"></script>
</body>
</html>
