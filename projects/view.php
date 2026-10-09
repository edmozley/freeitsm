<?php
/**
 * Projects - one project (3.2.0).
 *
 * A banner in the project's own colours (icon, name, status, how it is run, the
 * health ring), then the tabs: Overview (the goal, the numbers, what is
 * happening now and coming up, milestones, recent activity), Plan (the phases /
 * stages / sprints with their tasks, drag a task between them, add tasks in
 * place), Timeline (the plan as a Gantt chart - projects-timeline.js), a tab per
 * tool, and History. Behaviour is in assets/js/projects-view.js.
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
require_once '../includes/documents_panel.php';   // 3.3.0: documents on projects
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
    <link rel="stylesheet" href="../assets/css/projects.css?v=42">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=189">
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
                        <span class="prj-chip" id="pvPrivate" hidden></span>
                        <span id="pvPriority" hidden></span>
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
                <button type="button" class="btn prj-ghost-btn" id="pvTemplate" title="<?php echo htmlspecialchars(t('projects.templates.button_title')); ?>" hidden><?php echo htmlspecialchars(t('projects.templates.button')); ?></button>
                <button type="button" class="btn prj-ghost-btn danger" id="pvDelete"><?php echo htmlspecialchars(t('projects.view.delete')); ?></button>
            </div>
        </section>

        <nav class="prj-tabs" id="prjTabs" hidden>
            <button type="button" data-tab="overview" class="active"><?php echo htmlspecialchars(t('projects.view.tab_overview')); ?></button>
            <button type="button" data-tab="plan"><?php echo htmlspecialchars(t('projects.view.tab_plan')); ?></button>
            <button type="button" data-tab="timeline"><?php echo htmlspecialchars(t('projects.view.tab_timeline')); ?></button>
            <button type="button" data-tab="people" data-tool="people" hidden><?php echo htmlspecialchars(t('projects.tools.people')); ?></button>
            <button type="button" data-tab="scope" data-tool="scope" hidden><?php echo htmlspecialchars(t('projects.tools.scope')); ?></button>
            <button type="button" data-tab="raci" data-tool="raci" hidden><?php echo htmlspecialchars(t('projects.tools.raci')); ?></button>
            <button type="button" data-tab="raid" data-tool="raid" hidden><?php echo htmlspecialchars(t('projects.tools.raid')); ?></button>
            <button type="button" data-tab="gates" data-tool="gates" hidden><?php echo htmlspecialchars(t('projects.tools.gates')); ?></button>
            <button type="button" data-tab="budget" data-tool="budget" hidden><?php echo htmlspecialchars(t('projects.tools.budget')); ?></button>
            <button type="button" data-tab="control" data-tool="control" hidden><?php echo htmlspecialchars(t('projects.tools.control')); ?></button>
            <button type="button" data-tab="benefits" data-tool="benefits" hidden><?php echo htmlspecialchars(t('projects.tools.benefits')); ?></button>
            <button type="button" data-tab="documents"><?php echo htmlspecialchars(t('projects.view.tab_documents')); ?></button>
            <button type="button" data-tab="reports"><?php echo htmlspecialchars(t('projects.reports.tab')); ?></button>
            <button type="button" data-tab="connections"><?php echo htmlspecialchars(t('projects.view.tab_connections')); ?></button>
            <button type="button" data-tab="history"><?php echo htmlspecialchars(t('projects.view.tab_history')); ?></button>
        </nav>

        <section class="prj-tab-panel" data-panel="overview" id="pvOverview"></section>
        <section class="prj-tab-panel" data-panel="plan" id="pvPlan" hidden></section>
        <section class="prj-tab-panel" data-panel="timeline" id="pvTimeline" hidden></section>
        <section class="prj-tab-panel" data-panel="people" id="pvPeople" hidden></section>
        <section class="prj-tab-panel" data-panel="scope" id="pvScope" hidden></section>
        <section class="prj-tab-panel" data-panel="raci" id="pvRaci" hidden></section>
        <section class="prj-tab-panel" data-panel="raid" id="pvRaid" hidden></section>
        <section class="prj-tab-panel" data-panel="gates" id="pvGates" hidden></section>
        <section class="prj-tab-panel" data-panel="budget" id="pvBudget" hidden></section>
        <section class="prj-tab-panel" data-panel="control" id="pvControl" hidden></section>
        <section class="prj-tab-panel" data-panel="benefits" id="pvBenefits" hidden></section>
        <section class="prj-tab-panel" data-panel="documents" id="pvDocuments" hidden><p class="prj-muted" style="margin-top:0"><?php echo htmlspecialchars(t('projects.view.documents_intro')); ?></p><div class="prj-panel"><div id="pvDocumentsPanel"></div></div></section>
        <section class="prj-tab-panel" data-panel="reports" id="pvReports" hidden></section>
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

    <!-- Add / edit a RAID entry -->
    <div class="modal" id="prjRaidModal" aria-hidden="true">
        <div class="modal-content" style="max-width:600px">
            <div class="modal-header" id="prTitle"></div>
            <div class="modal-body">
                <input type="hidden" id="prId">
                <div class="prj-seg" id="prType" role="tablist">
                    <button type="button" data-rtype="risk"><?php echo htmlspecialchars(t('projects.raid.risk')); ?></button>
                    <button type="button" data-rtype="assumption"><?php echo htmlspecialchars(t('projects.raid.assumption')); ?></button>
                    <button type="button" data-rtype="issue"><?php echo htmlspecialchars(t('projects.raid.issue')); ?></button>
                    <button type="button" data-rtype="dependency"><?php echo htmlspecialchars(t('projects.raid.dependency')); ?></button>
                    <button type="button" data-rtype="decision"><?php echo htmlspecialchars(t('projects.raid.decision')); ?></button>
                    <button type="button" data-rtype="lesson"><?php echo htmlspecialchars(t('projects.raid.lesson')); ?></button>
                </div>
                <div class="form-group"><label for="prName"><?php echo htmlspecialchars(t('projects.raid.title')); ?></label><input type="text" id="prName" maxlength="255" autocomplete="off"></div>
                <div class="form-group"><label for="prDesc"><?php echo htmlspecialchars(t('projects.raid.description')); ?></label><textarea id="prDesc" rows="3"></textarea></div>
                <div class="prj-raid-action" id="prKb" data-for="lesson"></div>
                <div class="prj-form-grid">
                    <div class="form-group" data-for="risk"><label for="prProb"><?php echo htmlspecialchars(t('projects.raid.probability')); ?></label><select id="prProb"></select></div>
                    <div class="form-group" data-for="risk issue"><label for="prImpact"><?php echo htmlspecialchars(t('projects.raid.impact')); ?></label><select id="prImpact"></select></div>
                    <div class="form-group" data-for="risk"><label for="prResp"><?php echo htmlspecialchars(t('projects.raid.response')); ?></label><select id="prResp"></select></div>
                    <div class="form-group"><label for="prOwner"><?php echo htmlspecialchars(t('projects.raid.owner')); ?></label><select id="prOwner"></select></div>
                    <div class="form-group"><label for="prDue" id="prDueLabel"><?php echo htmlspecialchars(t('projects.raid.due')); ?></label><input type="date" id="prDue"></div>
                    <div class="form-group"><label for="prStatus"><?php echo htmlspecialchars(t('projects.raid.status')); ?></label><select id="prStatus"></select></div>
                </div>
                <div class="form-group" data-for="risk"><label for="prPlan"><?php echo htmlspecialchars(t('projects.raid.plan')); ?></label><textarea id="prPlan" rows="2"></textarea></div>
                <!-- The decision log (3.3.0) -->
                <div class="prj-form-grid prj-raid-decision" data-for="decision">
                    <div class="form-group"><label for="prDecidedBy"><?php echo htmlspecialchars(t('projects.raid.decided_by')); ?></label><input type="text" id="prDecidedBy" maxlength="150" list="prDecidedByList" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('projects.raid.decided_by_ph')); ?>"><datalist id="prDecidedByList"></datalist></div>
                    <div class="form-group"><label for="prDecidedDate"><?php echo htmlspecialchars(t('projects.raid.decided_date')); ?></label><input type="date" id="prDecidedDate"></div>
                </div>
                <div class="form-group" data-for="decision"><label for="prRationale"><?php echo htmlspecialchars(t('projects.raid.rationale')); ?></label><textarea id="prRationale" rows="2" placeholder="<?php echo htmlspecialchars(t('projects.raid.rationale_ph')); ?>"></textarea></div>
                <!-- Escalation and follow-up actions (3.3.0) - a saved entry only; projects-tools.js fills them -->
                <div class="prj-raid-escalation" id="prEscalation" hidden></div>
                <div class="prj-raid-actions" id="prActions" hidden></div>
                <div class="form-group" data-for="issue" style="position:relative">
                    <label for="prTicket"><?php echo htmlspecialchars(t('projects.raid.ticket')); ?></label>
                    <input type="text" id="prTicket" placeholder="<?php echo htmlspecialchars(t('projects.links.add_ph')); ?>" autocomplete="off">
                    <ul class="prj-conn-results" id="prTicketResults" hidden></ul>
                    <input type="hidden" id="prTicketId">
                    <div class="prj-raid-action" id="prRaise"></div>
                </div>
                <div class="prj-form-error" id="prError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="prDelete" style="margin-right:auto" hidden><?php echo htmlspecialchars(t('common.delete')); ?></button>
                <button type="button" class="btn btn-secondary" data-prj-close="prjRaidModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="prSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- A budget line (3.2.0) -->
    <div class="modal" id="prjBudgetModal" aria-hidden="true">
        <div class="modal-content" style="max-width:540px">
            <div class="modal-header" id="pbTitle"></div>
            <div class="modal-body">
                <input type="hidden" id="pbId">
                <div class="form-group"><label for="pbName"><?php echo htmlspecialchars(t('projects.budget.line_name')); ?></label><input type="text" id="pbName" maxlength="200" placeholder="<?php echo htmlspecialchars(t('projects.budget.line_name_ph')); ?>"></div>
                <div class="form-group"><label for="pbCategory"><?php echo htmlspecialchars(t('projects.budget.col_category')); ?></label><select id="pbCategory"></select></div>
                <div class="prj-ann-when">
                    <div class="form-group"><label for="pbPlanned"><?php echo htmlspecialchars(t('projects.budget.planned')); ?></label><input type="text" inputmode="decimal" id="pbPlanned"></div>
                    <div class="form-group"><label for="pbActual"><?php echo htmlspecialchars(t('projects.budget.actual')); ?></label><input type="text" inputmode="decimal" id="pbActual"><small class="prj-muted"><?php echo htmlspecialchars(t('projects.budget.actual_hint')); ?></small></div>
                </div>
                <!-- 3.3.0: when, and what it is now expected to cost -->
                <div class="prj-ann-when">
                    <div class="form-group"><label for="pbPlannedDate"><?php echo htmlspecialchars(t('projects.budget.planned_date')); ?></label><input type="date" id="pbPlannedDate"></div>
                    <div class="form-group"><label for="pbSpentDate"><?php echo htmlspecialchars(t('projects.budget.spent_date')); ?></label><input type="date" id="pbSpentDate"></div>
                </div>
                <div class="form-group"><label for="pbForecast"><?php echo htmlspecialchars(t('projects.budget.forecast')); ?></label><input type="text" inputmode="decimal" id="pbForecast"><small class="prj-muted"><?php echo htmlspecialchars(t('projects.budget.forecast_field_hint')); ?></small></div>
                <div class="form-group" id="pbContractWrap"><label for="pbContract"><?php echo htmlspecialchars(t('projects.budget.contract')); ?></label><select id="pbContract"></select><small class="prj-muted"><?php echo htmlspecialchars(t('projects.budget.contract_hint')); ?></small></div>
                <div class="form-group"><label for="pbCostCentre"><?php echo htmlspecialchars(t('projects.budget.cost_centre')); ?></label><select id="pbCostCentre"></select></div>
                <div class="form-group"><label for="pbNotes"><?php echo htmlspecialchars(t('projects.budget.notes')); ?></label><input type="text" id="pbNotes" maxlength="500"></div>
                <div class="prj-form-error" id="pbError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" id="pbDelete" style="margin-right:auto"><?php echo htmlspecialchars(t('common.delete')); ?></button>
                <button type="button" class="btn btn-secondary" data-prj-close="prjBudgetModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="pbSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- A report (3.2.0) - projects-reports.js -->
    <div class="modal" id="prjReportModal" aria-hidden="true">
        <div class="modal-content" style="max-width:760px">
            <div class="modal-header prj-rep-badges" id="rpHead"></div>
            <div class="modal-body">
                <div class="form-group"><label for="rpTitle"><?php echo htmlspecialchars(t('projects.reports.title')); ?></label><input type="text" id="rpTitle" maxlength="200"></div>
                <div class="prj-form-error prj-rep-note" id="rpAiNote" hidden><?php echo htmlspecialchars(t('projects.reports.ai_note')); ?></div>
                <div class="prj-rep-tabs" id="rpTabs">
                    <button type="button" data-rep-mode="edit"><?php echo htmlspecialchars(t('projects.reports.edit')); ?></button>
                    <button type="button" data-rep-mode="preview"><?php echo htmlspecialchars(t('projects.reports.preview')); ?></button>
                </div>
                <div class="form-group" id="rpEditWrap"><textarea id="rpBody" rows="16" aria-label="<?php echo htmlspecialchars(t('projects.reports.body')); ?>"></textarea><small class="prj-muted"><?php echo htmlspecialchars(t('projects.reports.body_hint')); ?></small></div>
                <div class="prj-md prj-rep-preview" id="rpPreview" hidden></div>
                <p class="prj-muted prj-rep-modal-meta" id="rpMeta"></p>
                <div class="prj-form-error" id="rpError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" id="rpDelete" style="margin-right:auto"><?php echo htmlspecialchars(t('common.delete')); ?></button>
                <button type="button" class="btn btn-secondary" id="rpCopy"><?php echo htmlspecialchars(t('projects.reports.copy')); ?></button>
                <button type="button" class="btn btn-secondary" id="rpSend" hidden><?php echo htmlspecialchars(t('projects.reports.send')); ?></button>
                <button type="button" class="btn btn-secondary" data-prj-close="prjReportModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-secondary" id="rpSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="rpApprove"><?php echo htmlspecialchars(t('projects.reports.approve')); ?></button>
            </div>
        </div>
    </div>

    <!-- Send an approved report (3.3.0) - projects-reports.js -->
    <div class="modal" id="prjSendModal" aria-hidden="true">
        <div class="modal-content" style="max-width:560px">
            <div class="modal-header"><?php echo htmlspecialchars(t('projects.reports.send_title')); ?></div>
            <div class="modal-body">
                <p class="prj-muted" id="rsWhat" style="margin-top:0"></p>
                <div class="prj-send-list" id="rsList"></div>
                <div class="form-group"><label for="rsOther"><?php echo htmlspecialchars(t('projects.reports.send_other')); ?></label><input type="text" id="rsOther" placeholder="<?php echo htmlspecialchars(t('projects.reports.send_other_ph')); ?>"></div>
                <div class="form-group"><label for="rsNote"><?php echo htmlspecialchars(t('projects.reports.send_note')); ?></label><textarea id="rsNote" rows="3" maxlength="2000"></textarea></div>
                <div class="prj-form-error" id="rsError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjSendModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="rsSend"><?php echo htmlspecialchars(t('projects.reports.send')); ?></button>
            </div>
        </div>
    </div>

    <!-- Announce disruption on Service Status (3.2.0) -->
    <div class="modal" id="prjAnnounceModal" aria-hidden="true">
        <div class="modal-content" style="max-width:560px">
            <div class="modal-header"><?php echo htmlspecialchars(t('projects.announce.heading')); ?></div>
            <div class="modal-body">
                <p class="prj-muted" id="paIntro" style="margin:0 0 14px"></p>
                <div class="form-group"><label for="paTitle"><?php echo htmlspecialchars(t('projects.announce.what')); ?></label><input type="text" id="paTitle" maxlength="255" placeholder="<?php echo htmlspecialchars(t('projects.announce.what_ph')); ?>"></div>
                <div class="prj-ann-when">
                    <div class="form-group" id="paStartWrap"><label for="paStart"><?php echo htmlspecialchars(t('projects.announce.start')); ?></label><input type="datetime-local" id="paStart"></div>
                    <div class="form-group"><label for="paEnd"><?php echo htmlspecialchars(t('projects.announce.end')); ?></label><input type="datetime-local" id="paEnd"><small class="prj-muted"><?php echo htmlspecialchars(t('projects.announce.end_hint')); ?></small></div>
                </div>
                <div class="form-group"><label for="paComment"><?php echo htmlspecialchars(t('projects.announce.comment')); ?></label><textarea id="paComment" rows="3" placeholder="<?php echo htmlspecialchars(t('projects.announce.comment_ph')); ?>"></textarea></div>
                <div class="form-group"><label><?php echo htmlspecialchars(t('projects.announce.services')); ?></label>
                    <div id="paServices"></div>
                    <button type="button" class="btn btn-secondary sm" id="paAddService"><?php echo htmlspecialchars(t('projects.announce.add_service')); ?></button>
                </div>
                <div class="prj-form-error" id="paError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjAnnounceModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="paSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Record a gate decision -->
    <div class="modal" id="prjGateModal" aria-hidden="true">
        <div class="modal-content" style="max-width:520px">
            <div class="modal-header" id="pgTitle"></div>
            <div class="modal-body">
                <input type="hidden" id="pgStage">
                <p class="prj-muted" id="pgIntro" style="margin-top:0"></p>
                <div id="pgChanges" hidden></div>
                <div class="prj-gate-choices" id="pgChoices">
                    <button type="button" data-decision="go" class="go"><?php echo htmlspecialchars(t('projects.gates.go')); ?><small><?php echo htmlspecialchars(t('projects.gates.go_hint')); ?></small></button>
                    <button type="button" data-decision="go_with_conditions" class="cond"><?php echo htmlspecialchars(t('projects.gates.go_with_conditions')); ?><small><?php echo htmlspecialchars(t('projects.gates.cond_hint')); ?></small></button>
                    <button type="button" data-decision="stop" class="stop"><?php echo htmlspecialchars(t('projects.gates.stop')); ?><small><?php echo htmlspecialchars(t('projects.gates.stop_hint')); ?></small></button>
                </div>
                <div class="form-group"><label for="pgNotes"><?php echo htmlspecialchars(t('projects.gates.notes')); ?></label><textarea id="pgNotes" rows="3"></textarea></div>
                <div class="prj-form-error" id="pgError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjGateModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="pgSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Change control (3.3.0): raise or edit a change request -->
    <div class="modal" id="prjChangeModal" aria-hidden="true">
        <div class="modal-content" style="max-width:600px">
            <div class="modal-header" id="pcTitle"></div>
            <div class="modal-body">
                <input type="hidden" id="pcId">
                <div class="form-group"><label for="pcName"><?php echo htmlspecialchars(t('projects.control.field_title')); ?></label><input type="text" id="pcName" maxlength="200" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('projects.control.field_title_ph')); ?>"></div>
                <div class="form-group"><label for="pcDesc"><?php echo htmlspecialchars(t('projects.control.field_desc')); ?></label><textarea id="pcDesc" rows="3"></textarea></div>
                <div class="form-group"><label for="pcReason"><?php echo htmlspecialchars(t('projects.control.field_reason')); ?></label><textarea id="pcReason" rows="2"></textarea></div>
                <p class="prj-muted sm" style="margin:4px 0 8px"><?php echo htmlspecialchars(t('projects.control.impact_intro')); ?></p>
                <div class="prj-form-grid">
                    <div class="form-group"><label for="pcDays"><?php echo htmlspecialchars(t('projects.control.field_days')); ?></label><input type="number" id="pcDays" step="1" min="-3650" max="3650" placeholder="0"></div>
                    <div class="form-group"><label for="pcCost" id="pcCostLabel"></label><input type="number" id="pcCost" step="0.01" placeholder="0"></div>
                </div>
                <div class="form-group"><label for="pcScope"><?php echo htmlspecialchars(t('projects.control.field_scope')); ?></label><textarea id="pcScope" rows="2" maxlength="1000" placeholder="<?php echo htmlspecialchars(t('projects.control.field_scope_ph')); ?>"></textarea></div>
                <div class="prj-form-error" id="pcError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjChangeModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="pcSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Change control (3.3.0): approve or reject -->
    <div class="modal" id="prjDecideModal" aria-hidden="true">
        <div class="modal-content" style="max-width:520px">
            <div class="modal-header" id="pdTitle"></div>
            <div class="modal-body">
                <p id="pdIntro" style="margin:0 0 14px"></p>
                <div class="form-group"><label for="pdNotes"><?php echo htmlspecialchars(t('projects.control.notes')); ?></label><textarea id="pdNotes" rows="3"></textarea></div>
                <div class="prj-form-error" id="pdError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjDecideModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="pdSave"></button>
            </div>
        </div>
    </div>

    <!-- Change control (3.3.0): take a baseline by hand -->
    <div class="modal" id="prjBaselineModal" aria-hidden="true">
        <div class="modal-content" style="max-width:480px">
            <div class="modal-header"><?php echo htmlspecialchars(t('projects.control.take_title')); ?></div>
            <div class="modal-body">
                <p class="prj-muted" style="margin-top:0"><?php echo htmlspecialchars(t('projects.control.take_intro')); ?></p>
                <div class="form-group"><label for="pbsLabel"><?php echo htmlspecialchars(t('projects.control.take_label')); ?></label><input type="text" id="pbsLabel" maxlength="150" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('projects.control.take_label_ph')); ?>"></div>
                <div class="prj-form-error" id="pbsError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjBaselineModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="pbsSave"><?php echo htmlspecialchars(t('projects.control.take')); ?></button>
            </div>
        </div>
    </div>

    <!-- A task's dependencies (3.3.0) - projects-timeline.js -->
    <div class="modal" id="prjDepModal" aria-hidden="true">
        <div class="modal-content" style="max-width:540px">
            <div class="modal-header" id="pdpTitle"></div>
            <div class="modal-body" id="pdpBody"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-prj-close="prjDepModal"><?php echo htmlspecialchars(t('common.close')); ?></button></div>
        </div>
    </div>

    <!-- Gate checklist item (3.3.0) - projects-gatecheck.js -->
    <div class="modal" id="prjGateItemModal" aria-hidden="true">
        <div class="modal-content" style="max-width:520px">
            <div class="modal-header" id="pgiTitle"></div>
            <div class="modal-body">
                <div class="form-group"><label for="pgiKind"><?php echo htmlspecialchars(t('projects.gatecheck.field_kind')); ?></label>
                    <select id="pgiKind"><option value="check"><?php echo htmlspecialchars(t('projects.gatecheck.kind_check')); ?></option><option value="document"><?php echo htmlspecialchars(t('projects.gatecheck.kind_document')); ?></option><option value="signoff"><?php echo htmlspecialchars(t('projects.gatecheck.kind_signoff')); ?></option><option value="change"><?php echo htmlspecialchars(t('projects.gatecheck.kind_change')); ?></option></select>
                    <small class="prj-muted" id="pgiHint"></small></div>
                <div class="form-group"><label for="pgiName"><?php echo htmlspecialchars(t('projects.gatecheck.field_title')); ?></label><input type="text" id="pgiName" maxlength="200" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('projects.gatecheck.field_title_ph')); ?>"></div>
                <div class="form-group" id="pgiPersonWrap"><label for="pgiPerson"><?php echo htmlspecialchars(t('projects.gatecheck.field_person')); ?></label><select id="pgiPerson"></select></div>
                <div class="form-group" id="pgiChangeWrap"><label for="pgiChange"><?php echo htmlspecialchars(t('projects.gatecheck.field_change')); ?></label><select id="pgiChange"></select></div>
                <div class="form-group"><label for="pgiNotes"><?php echo htmlspecialchars(t('projects.gatecheck.field_notes')); ?></label><input type="text" id="pgiNotes" maxlength="500"></div>
                <div class="prj-form-error" id="pgiError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjGateItemModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="pgiSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Benefits (3.3.0) - projects-benefits.js -->
    <div class="modal" id="prjBenefitModal" aria-hidden="true">
        <div class="modal-content" style="max-width:620px">
            <div class="modal-header" id="pbnTitle"></div>
            <div class="modal-body">
                <input type="hidden" id="pbnId">
                <div class="form-group"><label for="pbnName"><?php echo htmlspecialchars(t('projects.benefits.field_title')); ?></label><input type="text" id="pbnName" maxlength="200" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('projects.benefits.field_title_ph')); ?>"></div>
                <div class="form-group"><label for="pbnMeasure"><?php echo htmlspecialchars(t('projects.benefits.field_measure')); ?></label><input type="text" id="pbnMeasure" maxlength="255" placeholder="<?php echo htmlspecialchars(t('projects.benefits.field_measure_ph')); ?>"></div>
                <div class="prj-form-grid">
                    <div class="form-group"><label for="pbnUnit"><?php echo htmlspecialchars(t('projects.benefits.field_unit')); ?></label><input type="text" id="pbnUnit" maxlength="30" placeholder="<?php echo htmlspecialchars(t('projects.benefits.field_unit_ph')); ?>"></div>
                    <div class="form-group"><label for="pbnDirection"><?php echo htmlspecialchars(t('projects.benefits.field_direction')); ?></label><select id="pbnDirection"><option value="up"><?php echo htmlspecialchars(t('projects.benefits.higher_better')); ?></option><option value="down"><?php echo htmlspecialchars(t('projects.benefits.lower_better')); ?></option></select></div>
                </div>
                <div class="prj-form-grid">
                    <div class="form-group"><label for="pbnBaseline"><?php echo htmlspecialchars(t('projects.benefits.baseline')); ?></label><input type="text" inputmode="decimal" id="pbnBaseline"></div>
                    <div class="form-group"><label for="pbnTarget"><?php echo htmlspecialchars(t('projects.benefits.target')); ?></label><input type="text" inputmode="decimal" id="pbnTarget"></div>
                </div>
                <div class="prj-form-grid">
                    <div class="form-group"><label for="pbnTargetDate"><?php echo htmlspecialchars(t('projects.benefits.field_target_date')); ?></label><input type="date" id="pbnTargetDate"></div>
                    <div class="form-group"><label for="pbnOwner"><?php echo htmlspecialchars(t('projects.benefits.field_owner')); ?></label><select id="pbnOwner"></select></div>
                </div>
                <div class="prj-form-grid">
                    <div class="form-group"><label for="pbnReview"><?php echo htmlspecialchars(t('projects.benefits.field_review')); ?></label><input type="date" id="pbnReview"></div>
                    <div class="form-group"><label for="pbnEvery"><?php echo htmlspecialchars(t('projects.benefits.field_every')); ?></label><input type="number" id="pbnEvery" min="0" max="24" placeholder="<?php echo htmlspecialchars(t('projects.benefits.field_every_ph')); ?>"></div>
                </div>
                <div class="form-group"><label for="pbnNotes"><?php echo htmlspecialchars(t('projects.benefits.field_notes')); ?></label><textarea id="pbnNotes" rows="2"></textarea></div>
                <label class="prj-check" id="pbnClosedWrap"><input type="checkbox" id="pbnClosed"> <?php echo htmlspecialchars(t('projects.benefits.field_closed')); ?></label>
                <div class="prj-form-error" id="pbnError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="pbnDelete" style="margin-right:auto" hidden><?php echo htmlspecialchars(t('common.delete')); ?></button>
                <button type="button" class="btn btn-secondary" data-prj-close="prjBenefitModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="pbnSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>
    <div class="modal" id="prjBenefitMeasureModal" aria-hidden="true">
        <div class="modal-content" style="max-width:460px">
            <div class="modal-header" id="pbmTitle"></div>
            <div class="modal-body">
                <div class="prj-form-grid">
                    <div class="form-group"><label for="pbmValue" id="pbmValueLabel"></label><input type="text" inputmode="decimal" id="pbmValue"></div>
                    <div class="form-group"><label for="pbmDate"><?php echo htmlspecialchars(t('projects.benefits.col_date')); ?></label><input type="date" id="pbmDate"></div>
                </div>
                <div class="form-group"><label for="pbmNote"><?php echo htmlspecialchars(t('projects.benefits.col_note')); ?></label><input type="text" id="pbmNote" maxlength="500"></div>
                <p class="prj-muted sm" id="pbmHint" style="margin:0"></p>
                <div class="prj-form-error" id="pbmError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjBenefitMeasureModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="pbmSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Intake (3.3.0): approve or reject a proposal - projects-intake.js -->
    <div class="modal" id="prjProposalModal" aria-hidden="true">
        <div class="modal-content" style="max-width:520px">
            <div class="modal-header" id="ppTitle"></div>
            <div class="modal-body">
                <p id="ppIntro" style="margin:0 0 14px"></p>
                <div class="form-group"><label for="ppNotes" id="ppNotesLabel"></label><textarea id="ppNotes" rows="3"></textarea></div>
                <div class="prj-form-error" id="ppError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjProposalModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="ppSave"></button>
            </div>
        </div>
    </div>

    <!-- Intake (3.3.0): the proposal's figures -->
    <div class="modal" id="prjProposalEditModal" aria-hidden="true">
        <div class="modal-content" style="max-width:600px">
            <div class="modal-header"><?php echo htmlspecialchars(t('projects.intake.edit_title')); ?></div>
            <div class="modal-body">
                <div class="form-group"><label for="peCase"><?php echo htmlspecialchars(t('projects.intake.business_case')); ?></label><textarea id="peCase" rows="5"></textarea></div>
                <div class="form-group"><label for="peCost" id="peCostLabel"></label><input type="text" inputmode="decimal" id="peCost"></div>
                <div class="form-group"><label for="peBenefit"><?php echo htmlspecialchars(t('projects.intake.benefit')); ?></label><textarea id="peBenefit" rows="3" placeholder="<?php echo htmlspecialchars(t('projects.intake.benefit_ph')); ?>"></textarea></div>
                <div class="prj-form-error" id="peError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjProposalEditModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="peSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Asset targets (3.2.0): set one up -->
    <div class="modal" id="prjTargetModal" aria-hidden="true">
        <div class="modal-content" style="max-width:560px">
            <div class="modal-header" id="ptTitle"></div>
            <div class="modal-body">
                <input type="hidden" id="ptId">
                <div class="form-group"><label for="ptName"><?php echo htmlspecialchars(t('projects.targets.name')); ?></label><input type="text" id="ptName" maxlength="150" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('projects.targets.name_ph')); ?>"></div>
                <label class="prj-tg-label"><?php echo htmlspecialchars(t('projects.targets.which')); ?></label>
                <div class="prj-seg" id="ptScope" role="tablist" data-scope="filter">
                    <button type="button" data-scope="filter" class="active"><?php echo htmlspecialchars(t('projects.targets.scope_filter_btn')); ?></button>
                    <button type="button" data-scope="linked"><?php echo htmlspecialchars(t('projects.targets.scope_linked_btn')); ?></button>
                </div>
                <div class="prj-form-grid" id="ptFilter">
                    <div class="form-group"><label for="ptType"><?php echo htmlspecialchars(t('projects.targets.type')); ?></label><select id="ptType"></select></div>
                    <div class="form-group"><label for="ptSField"><?php echo htmlspecialchars(t('projects.targets.where')); ?></label><select id="ptSField"></select></div>
                    <div class="form-group"><label for="ptSValue"><?php echo htmlspecialchars(t('projects.targets.contains_label')); ?></label><input type="text" id="ptSValue" maxlength="100" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('projects.targets.contains_ph')); ?>"></div>
                </div>
                <p class="prj-muted" id="ptLinkedNote" hidden><?php echo htmlspecialchars(t('projects.targets.linked_note')); ?></p>
                <label class="prj-tg-label"><?php echo htmlspecialchars(t('projects.targets.done_when')); ?></label>
                <div class="prj-form-grid">
                    <div class="form-group"><label for="ptField"><?php echo htmlspecialchars(t('projects.targets.field_label')); ?></label><select id="ptField"></select></div>
                    <div class="form-group"><label for="ptOp"><?php echo htmlspecialchars(t('projects.targets.op_label')); ?></label><select id="ptOp"></select></div>
                    <div class="form-group"><label for="ptValue"><?php echo htmlspecialchars(t('projects.targets.value_label')); ?></label><input type="text" id="ptValue" maxlength="100" autocomplete="off"><select id="ptValueList" hidden></select></div>
                </div>
                <div class="form-group"><label for="ptDue"><?php echo htmlspecialchars(t('projects.targets.due')); ?></label><input type="date" id="ptDue"><small class="prj-muted"><?php echo htmlspecialchars(t('projects.targets.due_hint')); ?></small></div>
                <div class="prj-tg-preview none" id="ptPreview" aria-live="polite"></div>
                <div class="prj-form-error" id="ptError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="ptDelete" style="margin-right:auto" hidden><?php echo htmlspecialchars(t('common.delete')); ?></button>
                <button type="button" class="btn btn-secondary" data-prj-close="prjTargetModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="ptSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Asset targets: the assets behind one -->
    <div class="modal" id="prjTargetListModal" aria-hidden="true">
        <div class="modal-content" style="max-width:820px">
            <div class="modal-header" id="ptlTitle"></div>
            <div class="modal-body">
                <p class="prj-muted" id="ptlSub" style="margin-top:0"></p>
                <div class="prj-seg" id="ptlSeg" role="tablist"><button type="button" data-show="left"></button><button type="button" data-show="done"></button></div>
                <div id="ptlBody"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjTargetListModal"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <!-- Add someone to the project (People) -->
    <!-- Stakeholder map (3.3.0) - projects-tools.js -->
    <div class="modal" id="prjStakeModal" aria-hidden="true">
        <div class="modal-content" style="max-width:520px">
            <div class="modal-header"><?php echo htmlspecialchars(t('projects.stake.dialog')); ?>&nbsp;<span id="psName"></span></div>
            <div class="modal-body">
                <div class="prj-form-grid">
                    <div class="form-group"><label for="psPower"><?php echo htmlspecialchars(t('projects.stake.power_q')); ?></label><select id="psPower"></select></div>
                    <div class="form-group"><label for="psInterest"><?php echo htmlspecialchars(t('projects.stake.interest_q')); ?></label><select id="psInterest"></select></div>
                </div>
                <div class="form-group"><label for="psStance"><?php echo htmlspecialchars(t('projects.stake.stance')); ?></label><select id="psStance"></select></div>
                <div class="form-group"><label for="psKeep"><?php echo htmlspecialchars(t('projects.stake.keep_informed')); ?></label><input type="text" id="psKeep" maxlength="255" placeholder="<?php echo htmlspecialchars(t('projects.stake.keep_ph')); ?>"></div>
                <div class="prj-form-error" id="psError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjStakeModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="psSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

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

    <!-- Add / edit a milestone (3.3.0) - assets/js/projects-milestones.js -->
    <div class="modal" id="prjMilestoneModal" aria-hidden="true">
        <div class="modal-content" style="max-width:500px">
            <div class="modal-header" id="pmsTitle"></div>
            <div class="modal-body">
                <input type="hidden" id="pmsId">
                <div class="form-group"><label for="pmsName"><?php echo htmlspecialchars(t('projects.milestones.name')); ?></label><input type="text" id="pmsName" maxlength="150" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('projects.milestones.name_ph')); ?>"></div>
                <div class="prj-form-grid">
                    <div class="form-group"><label for="pmsDate"><?php echo htmlspecialchars(t('projects.milestones.date')); ?></label><input type="date" id="pmsDate"></div>
                    <div class="form-group"><label for="pmsStage"><?php echo htmlspecialchars(t('projects.milestones.stage')); ?></label><select id="pmsStage"></select></div>
                </div>
                <div class="form-group"><label for="pmsNotes"><?php echo htmlspecialchars(t('projects.milestones.notes')); ?></label><input type="text" id="pmsNotes" maxlength="500" autocomplete="off"></div>
                <div class="prj-ms-done-row">
                    <label class="prj-check"><input type="checkbox" id="pmsDone"> <?php echo htmlspecialchars(t('projects.milestones.done')); ?></label>
                    <div class="form-group" id="pmsDoneWrap" hidden><label for="pmsDoneDate"><?php echo htmlspecialchars(t('projects.milestones.done_on')); ?></label><input type="date" id="pmsDoneDate"></div>
                </div>
                <div class="prj-form-error" id="pmsError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" id="pmsDelete" style="margin-right:auto"><?php echo htmlspecialchars(t('common.delete')); ?></button>
                <button type="button" class="btn btn-secondary" data-prj-close="prjMilestoneModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="pmsSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
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

    <!-- Save this project as a template (assets/js/projects-templates.js) -->
    <div class="modal" id="prjTemplateModal" aria-hidden="true">
        <div class="modal-content" style="max-width:520px">
            <div class="modal-header"><?php echo htmlspecialchars(t('projects.templates.save_title')); ?></div>
            <div class="modal-body">
                <div class="form-group prj-tpl-mode" id="tpsModeWrap" hidden>
                    <label class="prj-check"><input type="radio" name="tpsMode" value="new" checked> <?php echo htmlspecialchars(t('projects.templates.mode_new')); ?></label>
                    <label class="prj-check"><input type="radio" name="tpsMode" value="replace"> <?php echo htmlspecialchars(t('projects.templates.mode_replace')); ?></label>
                    <select id="tpsReplace" hidden></select>
                </div>
                <div class="form-group"><label for="tpsName"><?php echo htmlspecialchars(t('projects.templates.name')); ?></label><input type="text" id="tpsName" maxlength="150" autocomplete="off"></div>
                <div class="form-group"><label for="tpsDesc"><?php echo htmlspecialchars(t('projects.templates.description')); ?></label><input type="text" id="tpsDesc" maxlength="500" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('projects.templates.description_ph')); ?>"></div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('projects.templates.parts')); ?></label>
                    <div class="prj-tpl-parts" id="tpsParts">
                        <?php foreach (['plan', 'scope', 'raid', 'benefits', 'tolerances', 'targets'] as $part): ?>
                        <label class="prj-check"><input type="checkbox" data-part="<?php echo $part; ?>" checked> <?php echo htmlspecialchars(t('projects.templates.part_' . $part)); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p class="prj-hint"><?php echo htmlspecialchars(t('projects.templates.never_kept')); ?></p>
                <div class="prj-form-error" id="tpsError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-prj-close="prjTemplateModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary prj-btn" id="tpsSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <?php include 'includes/project_form.php'; ?>

    <script src="../assets/js/projects.js?v=10"></script>
    <script src="../assets/js/projects-tools.js?v=10"></script>
    <script src="../assets/js/projects-targets.js?v=1"></script>
    <script src="../assets/js/projects-budget.js?v=6"></script>
    <script src="../assets/js/projects-control.js?v=1"></script>
    <script src="../assets/js/projects-intake.js?v=1"></script>
    <script src="../assets/js/projects-benefits.js?v=1"></script>
    <script src="../assets/js/projects-gatecheck.js?v=1"></script>
    <script src="../assets/js/projects-insights.js?v=1"></script>
    <script src="../assets/js/projects-toolbox.js?v=1"></script>
    <?php documentsPanelAssets('../'); ?>
    <script src="../assets/js/projects-reports.js?v=3"></script>
    <script src="../assets/js/projects-charts.js?v=9"></script>
    <script src="../assets/js/projects-milestones.js?v=1"></script>
    <script src="../assets/js/projects-timeline.js?v=2"></script>
    <script src="../assets/js/projects-view.js?v=27"></script>
    <script src="../assets/js/projects-templates.js?v=2"></script>
    <script src="../assets/js/mobile.js?v=78"></script>
</body>
</html>
