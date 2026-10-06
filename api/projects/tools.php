<?php
/**
 * Projects - the tools on a project (3.2.0 phase 2): people, scope / MoSCoW and
 * the RACI matrix. Every rule lives in includes/services/project_tools.php;
 * this only routes.
 *
 * GET  ?project_id=N&people=Q          people from People in the project's company, for the picker
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
 *      tolerances_save {time?: days|null, risk?: score|null}
 *      gate_decide    {stage_id, decision: go|go_with_conditions|stop, notes?}
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
        case 'raid_delete':
            ProjectToolsService::deleteRaid($conn, $ctx, $pid, (int)($in['id'] ?? 0));
            projectApiOk();
        case 'tolerances_save':
            ProjectToolsService::saveTolerances($conn, $ctx, $pid, $in);
            projectApiOk();
        case 'gate_decide':
            projectApiOk(ProjectToolsService::decideGate($conn, $ctx, $pid, (int)($in['stage_id'] ?? 0), (string)($in['decision'] ?? ''), $in['notes'] ?? null));
        case 'raci_set':
            projectApiOk(['row' => (object)ProjectToolsService::setRaci($conn, $ctx, $pid, (int)($in['item_id'] ?? 0), (int)($in['member_id'] ?? 0), (string)($in['letter'] ?? ''))]);
    }
    projectApiFail('Unknown action.');
});
