<?php
/**
 * Projects - the tools on a project (3.2.0 phase 2): people, scope / MoSCoW and
 * the RACI matrix. Every rule lives in includes/services/project_tools.php;
 * this only routes.
 *
 * GET  ?project_id=N&people=Q          people from People in the project's company, for the picker
 *      ?project_id=N&target_options=1  types, statuses, locations and fields for the target dialog (Assets access)
 *      ?project_id=N&target_preview=1&<rule fields>  {done, total} for a rule being edited (Assets access)
 *      ?project_id=N&target_assets=ID&show=left|done  the assets behind a target, at most 200 (Assets access)
 * POST {action, project_id, ...}
 *      member_add     {analyst_id | team_id | user_id, role_id?, notes?}
 *      member_update  {member_id, role_id?, notes?}
 *      member_remove  {member_id}
 *      item_save      {id?, title, description?, acceptance_criteria?, moscow?, stage_id?, status?}
 *      item_delete    {id}
 *      item_move      {id, moscow, order:[ids in that column]}
 *      raci_set       {item_id, member_id, letter: R|A|C|I|''}
 *      raid_save      {id?, type, title, description?, probability?, impact?, response?, response_plan?, owner_analyst_id?, status?, due_date?, ticket_id?}
 *      raid_delete    {id}
 *      raid_to_knowledge {id}   a lesson -> a draft Knowledge article (Knowledge access)
 *      raid_to_ticket    {id}   an issue -> a new ticket (Tickets access)
 *      raid_escalate     {id, note}   (3.3.0)
 *      raid_deescalate   {id}
 *      raid_action_add   {id, title, assigned_analyst_id?, due_date?}   a new project task, linked
 *      raid_action_remove {id, task_id}   unlinks; the task stays
 *      tolerances_save {time?: days|null, risk?: score|null}
 *      gate_decide    {stage_id, decision: go|go_with_conditions|stop, notes?}
 *      target_save    {id?, name, scope: filter|linked, scope_type_id?, scope_field?, scope_value?, done_field, done_op, done_value, target_date?}
 *      target_delete  {id}
 *      announce       {title, comment?, start?, end?, services:[{service_id, impact_level_id}]}  (Service Status)
 *      announce_withdraw {id}
 *      budget_line_save {id?, title, category, planned?, actual?, contract_id?, cost_centre_id?, notes?}
 *      budget_line_delete {id}
 *      budget_rate_add {rate, from?}      the project's own hourly rate (labour mode 'rate')
 *      budget_rate_delete {from}
 *      budget_currency {currency}         relabel - only when projects may choose
 *      milestone_save {id?, name?, due_date?, stage_id?, notes?, done?, done_date?}   (3.3.0)
 *      milestone_delete {id}
 *      task_dates     {task_id, start_date?, due_date?}   the Timeline moving a bar (3.3.0)
 *      task_estimate  {task_id, estimate_hours}   the Plan's estimate box (3.3.0)
 *      baseline_take  {label?}   (3.3.0 change control - includes/projects/control.php)
 *      change_save    {id?, title, description?, reason?, impact_days?, impact_cost?, impact_scope?}
 *      change_decide  {id, decision: approved|rejected, notes?}
 *      change_withdraw {id}
 *      proposal_decide {decision: approved|rejected, notes?}   (3.3.0 intake - includes/projects/intake.php; notes required to reject)
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
require_once __DIR__ . '/../../includes/services/project_tools.php';

projectApiRun(function () use ($conn, $ctx) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $project = ProjectsService::loadForActor($conn, $ctx, (int)($_GET['project_id'] ?? 0));
        if (isset($_GET['people'])) {
            // People in the project's company, matching the search - the same
            // company rule addMember() applies, so the picker never offers
            // somebody it would then refuse.
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string)$_GET['people'])) . '%';
            $sql = "SELECT u.id, COALESCE(NULLIF(u.display_name, ''), u.email) AS name, u.email, u.job_title
                      FROM users u WHERE (u.display_name LIKE ? OR u.email LIKE ? OR u.job_title LIKE ?)";
            $args = [$like, $like, $like];
            if (isMultiTenant($conn)) {
                $def = (int)getDefaultTenantId($conn);
                $sql .= " AND COALESCE(u.tenant_id, $def) = ?";
                $args[] = $project['tenant_id'] === null ? $def : (int)$project['tenant_id'];
            }
            $st = $conn->prepare($sql . " ORDER BY name LIMIT 20");
            $st->execute($args);
            projectApiOk(['people' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        }
        // Asset targets (3.2.0): everything that reads the estate needs Assets.
        if (isset($_GET['target_options']) || isset($_GET['target_preview']) || isset($_GET['target_assets'])) {
            require_once __DIR__ . '/../../includes/projects/targets.php';
            ProjectToolsService::assertAssets($conn, $ctx);
            if (isset($_GET['target_options'])) projectApiOk(['options' => projectTargetOptions($conn, $project)]);
            if (isset($_GET['target_preview'])) {
                [$done, $total] = projectTargetCount($conn, $project, $_GET);
                projectApiOk(['done' => $done, 'total' => $total]);
            }
            $t = ProjectToolsService::target($conn, (int)$project['id'], (int)$_GET['target_assets']);
            $doneOnes = ($_GET['show'] ?? 'left') === 'done';
            projectApiOk(['assets' => projectTargetAssets($conn, $project, $t, $doneOnes, 200)]);
        }
        projectApiFail('Unknown request.');
    }

    $in = projectApiBody();
    $pid = (int)($in['project_id'] ?? 0);
    switch ($in['action'] ?? '') {
        case 'member_add':
            projectApiOk(['id' => ProjectToolsService::addMember($conn, $ctx, $pid, $in)]);
        case 'member_update':
            ProjectToolsService::updateMember($conn, $ctx, $pid, (int)($in['member_id'] ?? 0), $in);
            projectApiOk();
        case 'member_remove':
            ProjectToolsService::removeMember($conn, $ctx, $pid, (int)($in['member_id'] ?? 0));
            projectApiOk();
        case 'item_save':
            projectApiOk(['id' => ProjectToolsService::saveItem($conn, $ctx, $pid, $in)]);
        case 'item_delete':
            ProjectToolsService::deleteItem($conn, $ctx, $pid, (int)($in['id'] ?? 0));
            projectApiOk();
        case 'item_move':
            ProjectToolsService::moveItem($conn, $ctx, $pid, (int)($in['id'] ?? 0), $in['moscow'] ?? null, is_array($in['order'] ?? null) ? $in['order'] : []);
            projectApiOk();
        case 'raid_save':
            projectApiOk(['id' => ProjectToolsService::saveRaid($conn, $ctx, $pid, $in)]);
        case 'raid_to_knowledge':
            projectApiOk(['article' => ProjectToolsService::lessonToKnowledge($conn, $ctx, $pid, (int)($in['id'] ?? 0))]);
        case 'raid_to_ticket':
            projectApiOk(['ticket' => ProjectToolsService::issueToTicket($conn, $ctx, $pid, (int)($in['id'] ?? 0))]);
        case 'raid_escalate':
            ProjectToolsService::escalateRaid($conn, $ctx, $pid, (int)($in['id'] ?? 0), $in['note'] ?? null);
            projectApiOk();
        case 'raid_deescalate':
            ProjectToolsService::deescalateRaid($conn, $ctx, $pid, (int)($in['id'] ?? 0));
            projectApiOk();
        case 'raid_action_add':
            projectApiOk(['task_id' => ProjectToolsService::addRaidAction($conn, $ctx, $pid, (int)($in['id'] ?? 0), $in)]);
        case 'raid_action_remove':
            ProjectToolsService::removeRaidAction($conn, $ctx, $pid, (int)($in['id'] ?? 0), (int)($in['task_id'] ?? 0));
            projectApiOk();
        case 'raid_delete':
            ProjectToolsService::deleteRaid($conn, $ctx, $pid, (int)($in['id'] ?? 0));
            projectApiOk();
        case 'tolerances_save':
            ProjectToolsService::saveTolerances($conn, $ctx, $pid, $in);
            projectApiOk();
        case 'gate_decide':
            projectApiOk(ProjectToolsService::decideGate($conn, $ctx, $pid, (int)($in['stage_id'] ?? 0), (string)($in['decision'] ?? ''), $in['notes'] ?? null));
        case 'target_save':
            projectApiOk(['id' => ProjectToolsService::saveTarget($conn, $ctx, $pid, $in)]);
        case 'target_delete':
            ProjectToolsService::deleteTarget($conn, $ctx, $pid, (int)($in['id'] ?? 0));
            projectApiOk();
        case 'announce':
            projectApiOk(['id' => ProjectToolsService::announce($conn, $ctx, $pid, $in), 'announcements' => ProjectToolsService::announcements($conn, $ctx->actorId, $pid)]);
        case 'announce_withdraw':
            ProjectToolsService::withdrawAnnouncement($conn, $ctx, $pid, (int)($in['id'] ?? 0));
            projectApiOk(['announcements' => ProjectToolsService::announcements($conn, $ctx->actorId, $pid)]);
        case 'budget_line_save':
            projectApiOk(['id' => ProjectToolsService::saveBudgetLine($conn, $ctx, $pid, $in)]);
        case 'budget_line_delete':
            ProjectToolsService::deleteBudgetLine($conn, $ctx, $pid, (int)($in['id'] ?? 0));
            projectApiOk();
        case 'budget_rate_add':
            ProjectToolsService::addProjectRate($conn, $ctx, $pid, $in['rate'] ?? null, $in['from'] ?? null);
            projectApiOk();
        case 'budget_rate_delete':
            ProjectToolsService::deleteProjectRate($conn, $ctx, $pid, (string)($in['from'] ?? ''));
            projectApiOk();
        case 'budget_currency':
            ProjectToolsService::setCurrency($conn, $ctx, $pid, (string)($in['currency'] ?? ''));
            projectApiOk();
        case 'milestone_save':
            projectApiOk(['id' => ProjectToolsService::saveMilestone($conn, $ctx, $pid, $in)]);
        case 'milestone_delete':
            ProjectToolsService::deleteMilestone($conn, $ctx, $pid, (int)($in['id'] ?? 0));
            projectApiOk();
        case 'task_dates':
            ProjectToolsService::setTaskDates($conn, $ctx, $pid, (int)($in['task_id'] ?? 0), $in);
            projectApiOk();
        case 'task_estimate':
            ProjectToolsService::setTaskEstimate($conn, $ctx, $pid, (int)($in['task_id'] ?? 0), $in['estimate_hours'] ?? null);
            projectApiOk();
        case 'proposal_decide':
            projectApiOk(ProjectsService::decideProposal($conn, $ctx, $pid, (string)($in['decision'] ?? ''), $in['notes'] ?? null));
        case 'baseline_take':
            projectApiOk(['id' => ProjectToolsService::takeBaseline($conn, $ctx, $pid, $in['label'] ?? null)]);
        case 'change_save':
            projectApiOk(['id' => ProjectToolsService::saveChangeRequest($conn, $ctx, $pid, $in)]);
        case 'change_decide':
            projectApiOk(ProjectToolsService::decideChangeRequest($conn, $ctx, $pid, (int)($in['id'] ?? 0), (string)($in['decision'] ?? ''), $in['notes'] ?? null));
        case 'change_withdraw':
            ProjectToolsService::withdrawChangeRequest($conn, $ctx, $pid, (int)($in['id'] ?? 0));
            projectApiOk();
        case 'raci_set':
            projectApiOk(['row' => (object)ProjectToolsService::setRaci($conn, $ctx, $pid, (int)($in['item_id'] ?? 0), (int)($in['member_id'] ?? 0), (string)($in['letter'] ?? ''))]);
    }
    projectApiFail('Unknown action.');
});
