<?php
/**
 * FreeITSM REST API v1 — projects resource (3.2.0).
 *
 * Every WRITE goes through ProjectsService / ProjectToolsService, the code the
 * Projects screens use, so a project changed through the API gets the same
 * validation, history rows, workflow events, Calendar entries and alert scan as
 * one changed by a person. The reads and the serialisers live here: the API
 * shape is a public contract, not the screens' shape.
 *
 * THE KEY ACTS AS ITS ANALYST. The module's own rules apply unchanged: who may
 * create a project, who may change one (its team, or everyone - Projects ->
 * Settings), and that only the project manager, the creator or somebody who
 * manages Projects may delete one. A refusal is a 403 with the service's words.
 *
 * COMPANY SCOPE: projects are scoped data. The list is filtered to the key's
 * companies, every by-id route 404s outside them (never 403, so ids cannot be
 * probed), and a new project lands in `company_id` (checked against the key) or
 * the key's default company. Sub-resources (stages, scope items, RAID, budget
 * lines) are reached only through their project, so they inherit its scope.
 *
 * HEALTH IS WORKED OUT, never stored: `health` in a response is the health the
 * portfolio shows (set by hand, or worked out from tasks, dates, targets,
 * tickets and tolerances), with `health_mode` saying which.
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/projects.php';
require_once dirname(__DIR__, 3) . '/includes/services/project_tools.php';
require_once dirname(__DIR__, 3) . '/includes/projects/read.php';
require_once dirname(__DIR__, 3) . '/includes/projects/budget.php';

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function apiProjectSelect(PDO $conn): string {
    return "SELECT p.*, a.full_name AS owner_name, tn.name AS company_name,
                   (SELECT s.id FROM project_stages s WHERE s.project_id = p.id AND s.status = 'active' ORDER BY s.position, s.id LIMIT 1) AS active_stage_id,
                   (SELECT s.name FROM project_stages s WHERE s.project_id = p.id AND s.status = 'active' ORDER BY s.position, s.id LIMIT 1) AS active_stage_name,
                   " . projectExceptionColumns($conn) . "
              FROM projects p
         LEFT JOIN analysts a ON a.id = p.owner_analyst_id
         LEFT JOIN tenants tn ON tn.id = p.tenant_id";
}

/** Decorate rows (health, progress, exceptions, budget) the way the portfolio does. */
function apiProjectDecorate(PDO $conn, array $rows): array {
    if (!$rows) return [];
    $stats = projectTaskStats($conn, array_map(fn($r) => (int)$r['id'], $rows));
    $cfg = projectHealthConfig($conn);
    return array_map(fn($r) => projectDecorate($r, $stats[(int)$r['id']] ?? [], $cfg) + ['_budget' => $stats[(int)$r['id']]['budget'] ?? null], $rows);
}

function apiSerializeProject(PDO $conn, array $p): array {
    $rel = fn($id, $name) => $id === null ? null : ['id' => (int)$id, 'name' => $name];
    $b = $p['_budget'] ?? null;
    return [
        'id'              => (int)$p['id'],
        'code'            => projectCode((int)$p['id']),
        'name'            => $p['name'],
        'summary'         => $p['summary'],
        'goal'            => $p['goal'],
        'company'         => $rel($p['tenant_id'], $p['company_name'] ?? null),
        'methodology'     => $p['methodology'],
        'status'          => $p['status'],
        'health'          => $p['shown_health'],
        'health_mode'     => $p['health'] === 'auto' ? 'auto' : 'manual',
        'health_note'     => $p['health_note'],
        'project_manager' => $rel($p['owner_analyst_id'], $p['owner_name'] ?? null),
        'start_date'      => $p['start_date'],
        'target_end_date' => $p['target_end_date'],
        'actual_end_date' => $p['actual_end_date'],
        'colour'          => $p['colour'],
        'icon'            => $p['icon'],
        'tools'           => array_values($p['tools']),
        'progress'        => [
            'percent'       => (int)$p['progress'],
            'tasks_total'   => (int)$p['task_total'],
            'tasks_done'    => (int)$p['task_done'],
            'tasks_overdue' => (int)$p['task_overdue'],
        ],
        'active_stage'    => $rel($p['active_stage_id'] ?? null, $p['active_stage_name'] ?? null),
        'exceptions'      => array_map(fn($e) => [
            'kind'      => $e['kind'],
            'allowed'   => (int)$e['allowed'],
            'late_days' => isset($e['late']) ? (int)$e['late'] : null,
            'score'     => isset($e['score']) ? (int)$e['score'] : null,
            'over_pct'  => isset($e['over_pct']) ? (int)$e['over_pct'] : null,
        ], $p['exceptions']),
        'budget'          => [
            'currency' => projectCurrencyOf($conn, $p),
            'planned'  => $b ? round((float)$b['planned'], 2) : 0.0,
            'actual'   => $b ? round((float)$b['actual'], 2) : 0.0,
        ],
        'created_at'      => apiIsoDate($p['created_datetime']),
        'updated_at'      => apiIsoDate($p['updated_datetime']),
        'closed_at'       => apiIsoDate($p['closed_datetime']),
    ];
}

/** The by-id gate: existence + company scope, both failing as 404. */
function apiLoadProject(PDO $conn, array $apiKey, int $id): array {
    $st = $conn->prepare(apiProjectSelect($conn) . " WHERE p.id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !apiKeyCanAccessTenantRow($conn, $apiKey, 'projects', $id)) {
        apiError(404, 'not_found', 'Project not found.');
    }
    return apiProjectDecorate($conn, [$row])[0];
}

function apiProjectCtx(array $apiKey): ActorContext {
    return ActorContext::fromApiKey($apiKey);
}

/** Run a service call; a ServiceError becomes the API's error body. */
function apiProjectTry(callable $fn) {
    try {
        return $fn();
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
    return null;
}

/** Only the fields the service accepts, so a body cannot reach a column it should not. */
function apiProjectFields(array $body): array {
    return array_intersect_key($body, ProjectsService::fieldMap() + ['project_manager_id' => true]);
}

// ---------------------------------------------------------------------------
// GET /projects
// ---------------------------------------------------------------------------
function apiProjectsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args = [];
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $statuses = array_filter(array_map('trim', explode(',', (string)$_GET['status'])));
        foreach ($statuses as $s) if (!in_array($s, projectStatuses(), true)) apiError(400, 'invalid_parameter', 'Unknown status. One of: ' . implode(', ', projectStatuses()));
        $where[] = 'p.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        array_push($args, ...$statuses);
    }
    if (isset($_GET['methodology']) && $_GET['methodology'] !== '') {
        if (!isset(projectMethodologies()[$_GET['methodology']])) apiError(400, 'invalid_parameter', 'Unknown methodology. One of: ' . implode(', ', array_keys(projectMethodologies())));
        $where[] = 'p.methodology = ?'; $args[] = $_GET['methodology'];
    }
    if (isset($_GET['project_manager_id']) && $_GET['project_manager_id'] !== '') {
        $where[] = 'p.owner_analyst_id = ?'; $args[] = (int)$_GET['project_manager_id'];
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($_GET['q'])) . '%';
        $where[] = '(p.name LIKE ? OR p.summary LIKE ? OR p.goal LIKE ?)';
        array_push($args, $like, $like, $like);
    }
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $cid = (int)$_GET['company_id'];
        if (!apiKeyCanAccessTenant($conn, $apiKey, $cid)) apiError(403, 'forbidden', 'This key cannot access that company.');
        $where[] = ($cid === getDefaultTenantId($conn)) ? '(p.tenant_id = ? OR p.tenant_id IS NULL)' : 'p.tenant_id = ?';
        $args[] = $cid;
    }
    $health = null;
    if (isset($_GET['health']) && $_GET['health'] !== '') {
        $health = array_filter(array_map('trim', explode(',', (string)$_GET['health'])));
        foreach ($health as $h) if (!in_array($h, ['green', 'amber', 'red'], true)) apiError(400, 'invalid_parameter', 'Unknown health. One of: green, amber, red');
    }
    $sortable = ['name' => 'p.name', 'target_end_date' => 'p.target_end_date', 'start_date' => 'p.start_date', 'created_at' => 'p.created_datetime', 'updated_at' => 'p.updated_datetime', 'id' => 'p.id'];
    $sortParam = trim($_GET['sort'] ?? 'target_end_date');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    $orderSql = $sortable[$sortKey] . ' IS NULL, ' . $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC') . ', p.id';

    [$scopeSql, $scopeArgs] = apiKeyTenantFilter($conn, $apiKey, 'p');
    $whereSql = implode(' AND ', $where) . $scopeSql;
    $args = array_merge($args, $scopeArgs);
    [$page, $perPage, $offset] = apiPagination();

    if ($health === null) {
        $count = $conn->prepare("SELECT COUNT(*) FROM projects p WHERE $whereSql");
        $count->execute($args);
        $total = (int)$count->fetchColumn();
        $st = $conn->prepare(apiProjectSelect($conn) . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
        $st->execute($args);
        $rows = apiProjectDecorate($conn, $st->fetchAll(PDO::FETCH_ASSOC));
    } else {
        // Health is worked out, not stored: filter after decorating, then page.
        $st = $conn->prepare(apiProjectSelect($conn) . " WHERE $whereSql ORDER BY $orderSql LIMIT 2000");
        $st->execute($args);
        $all = array_values(array_filter(apiProjectDecorate($conn, $st->fetchAll(PDO::FETCH_ASSOC)), fn($p) => in_array($p['shown_health'], $health, true)));
        $total = count($all);
        $rows = array_slice($all, $offset, $perPage);
    }
    apiRespond(array_map(fn($p) => apiSerializeProject($conn, $p), $rows), 200, [
        'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int)ceil($total / max(1, $perPage)),
    ]);
}

// ---------------------------------------------------------------------------
// GET /projects/{id}
// ---------------------------------------------------------------------------
function apiProjectsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond(apiSerializeProject($conn, apiLoadProject($conn, $apiKey, (int)$params[0])));
}

// ---------------------------------------------------------------------------
// POST /projects  (optionally {template: "builtin:office_move" | "saved:12"})
// ---------------------------------------------------------------------------
function apiProjectsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    if (isset($body['company_id']) && $body['company_id'] !== '' && $body['company_id'] !== null) {
        $tenantId = (int)$body['company_id'];
        if (!getTenantById($conn, $tenantId)) apiError(422, 'invalid_field', "Unknown company id: {$tenantId}");
        if (!apiKeyCanAccessTenant($conn, $apiKey, $tenantId)) apiError(403, 'forbidden', 'This key cannot create projects for that company.');
    } else {
        $tenantId = apiKeyDefaultTenantId($conn, $apiKey);
    }
    $template = trim((string)($body['template'] ?? ''));
    $in = apiProjectFields($body);
    if (array_key_exists('project_manager_id', $in)) { $in['owner_analyst_id'] = $in['project_manager_id']; unset($in['project_manager_id']); }
    $id = apiProjectTry(function () use ($conn, $apiKey, $in, $tenantId, $template) {
        if ($template !== '') {
            require_once dirname(__DIR__, 3) . '/includes/services/project_templates.php';
            return ProjectTemplatesService::createFromTemplate($conn, apiProjectCtx($apiKey), $template, $in, $tenantId);
        }
        return ProjectsService::createProject($conn, apiProjectCtx($apiKey), $in, $tenantId);
    });
    apiRespond(apiSerializeProject($conn, apiLoadProject($conn, $apiKey, (int)$id)), 201);
}

// ---------------------------------------------------------------------------
// PATCH /projects/{id}
// ---------------------------------------------------------------------------
function apiProjectsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $id = (int)$params[0];
    apiLoadProject($conn, $apiKey, $id);
    $in = apiProjectFields($body);
    if (array_key_exists('project_manager_id', $in)) { $in['owner_analyst_id'] = $in['project_manager_id']; unset($in['project_manager_id']); }
    if (!$in) apiError(422, 'missing_field', 'No fields to update.');
    apiProjectTry(fn() => ProjectsService::updateProject($conn, apiProjectCtx($apiKey), $id, $in));
    apiRespond(apiSerializeProject($conn, apiLoadProject($conn, $apiKey, $id)));
}

// ---------------------------------------------------------------------------
// DELETE /projects/{id}  - its tasks are detached, never deleted
// ---------------------------------------------------------------------------
function apiProjectsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    $id = (int)$params[0];
    apiLoadProject($conn, $apiKey, $id);
    $r = apiProjectTry(fn() => ProjectsService::deleteProject($conn, apiProjectCtx($apiKey), $id));
    apiRespond(['id' => $params[0], 'deleted' => true, 'tasks_detached' => (int)$r['tasks_detached']]);
}

// ---------------------------------------------------------------------------
// Stages (phases / stages / sprints)
// ---------------------------------------------------------------------------
function apiSerializeProjectStage(array $s): array {
    return [
        'id'          => (int)$s['id'],
        'kind'        => $s['kind'],
        'name'        => $s['name'],
        'goal'        => $s['goal'],
        'status'      => $s['status'],
        'position'    => (int)$s['position'],
        'start_date'  => $s['start_date'],
        'end_date'    => $s['end_date'],
        'tasks_total' => isset($s['task_total']) ? (int)$s['task_total'] : 0,
        'tasks_done'  => isset($s['task_done']) ? (int)$s['task_done'] : 0,
        'gate'        => $s['gate_decision'] === null ? null : [
            'decision'   => $s['gate_decision'],
            'notes'      => $s['gate_notes'],
            'decided_by' => $s['gate_decided_by'] === null ? null : (int)$s['gate_decided_by'],
            'decided_at' => apiIsoDate($s['gate_decided_datetime']),
        ],
    ];
}

function apiProjectStages(PDO $conn, int $projectId): array {
    $s = $conn->prepare("SELECT s.*,
                                (SELECT COUNT(*) FROM tasks t WHERE t.project_stage_id = s.id AND t.parent_task_id IS NULL) AS task_total,
                                (SELECT COUNT(*) FROM tasks t JOIN task_statuses ts ON ts.id = t.status_id
                                  WHERE t.project_stage_id = s.id AND t.parent_task_id IS NULL AND ts.is_closed = 1) AS task_done
                           FROM project_stages s WHERE s.project_id = ? ORDER BY s.position, s.id");
    $s->execute([$projectId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function apiProjectStage(PDO $conn, int $projectId, int $stageId): array {
    foreach (apiProjectStages($conn, $projectId) as $s) if ((int)$s['id'] === $stageId) return $s;
    apiError(404, 'not_found', 'Stage not found.');
    return [];
}

function apiProjectStagesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $pid = (int)$params[0];
    apiLoadProject($conn, $apiKey, $pid);
    apiRespond(array_map('apiSerializeProjectStage', apiProjectStages($conn, $pid)));
}

function apiProjectStagesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $pid = (int)$params[0];
    apiLoadProject($conn, $apiKey, $pid);
    unset($body['id']);
    $id = apiProjectTry(fn() => ProjectsService::saveStage($conn, apiProjectCtx($apiKey), $pid, array_intersect_key($body, array_flip(['name', 'goal', 'start_date', 'end_date', 'status']))));
    apiRespond(apiSerializeProjectStage(apiProjectStage($conn, $pid, (int)$id)), 201);
}

function apiProjectStagesUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    [$pid, $sid] = [(int)$params[0], (int)$params[1]];
    apiLoadProject($conn, $apiKey, $pid);
    apiProjectStage($conn, $pid, $sid);
    $in = array_intersect_key($body, array_flip(['name', 'goal', 'start_date', 'end_date', 'status']));
    if (!$in) apiError(422, 'missing_field', 'No fields to update.');
    apiProjectTry(fn() => ProjectsService::saveStage($conn, apiProjectCtx($apiKey), $pid, $in + ['id' => $sid]));
    apiRespond(apiSerializeProjectStage(apiProjectStage($conn, $pid, $sid)));
}

function apiProjectStagesDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    [$pid, $sid] = [(int)$params[0], (int)$params[1]];
    apiLoadProject($conn, $apiKey, $pid);
    apiProjectStage($conn, $pid, $sid);
    apiProjectTry(fn() => ProjectsService::deleteStage($conn, apiProjectCtx($apiKey), $pid, $sid));
    apiRespond(['id' => $params[1], 'deleted' => true]);
}

/** POST /projects/{id}/stages/{sid}/gate  {decision: go|go_with_conditions|stop, notes?} */
function apiProjectStagesGate(PDO $conn, array $apiKey, array $params, array $body): void {
    [$pid, $sid] = [(int)$params[0], (int)$params[1]];
    apiLoadProject($conn, $apiKey, $pid);
    apiProjectStage($conn, $pid, $sid);
    $r = apiProjectTry(fn() => ProjectToolsService::decideGate($conn, apiProjectCtx($apiKey), $pid, $sid, (string)($body['decision'] ?? ''), $body['notes'] ?? null));
    apiRespond(['stage' => apiSerializeProjectStage(apiProjectStage($conn, $pid, $sid)), 'closed' => (bool)$r['closed'], 'next_stage' => $r['next']]);
}

// ---------------------------------------------------------------------------
// Scope items (deliverables, with MoSCoW)
// ---------------------------------------------------------------------------
function apiSerializeProjectItem(array $i): array {
    return [
        'id'                  => (int)$i['id'],
        'title'               => $i['title'],
        'description'         => $i['description'],
        'acceptance_criteria' => $i['acceptance_criteria'],
        'moscow'              => $i['moscow'],
        'status'              => $i['status'],
        'stage'               => $i['stage_id'] === null ? null : ['id' => (int)$i['stage_id'], 'name' => $i['stage_name'] ?? null],
        'position'            => (int)$i['position'],
        'created_at'          => apiIsoDate($i['created_datetime']),
        'updated_at'          => apiIsoDate($i['updated_datetime']),
    ];
}

function apiProjectItem(PDO $conn, int $pid, int $itemId): array {
    foreach (ProjectToolsService::items($conn, $pid) as $i) if ((int)$i['id'] === $itemId) return $i;
    apiError(404, 'not_found', 'Scope item not found.');
    return [];
}

const API_PROJECT_ITEM_FIELDS = ['title', 'description', 'acceptance_criteria', 'moscow', 'status', 'stage_id'];

function apiProjectItemsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $pid = (int)$params[0];
    apiLoadProject($conn, $apiKey, $pid);
    apiRespond(array_map('apiSerializeProjectItem', ProjectToolsService::items($conn, $pid)));
}

function apiProjectItemsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $pid = (int)$params[0];
    apiLoadProject($conn, $apiKey, $pid);
    $id = apiProjectTry(fn() => ProjectToolsService::saveItem($conn, apiProjectCtx($apiKey), $pid, array_intersect_key($body, array_flip(API_PROJECT_ITEM_FIELDS))));
    apiRespond(apiSerializeProjectItem(apiProjectItem($conn, $pid, (int)$id)), 201);
}

function apiProjectItemsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    [$pid, $iid] = [(int)$params[0], (int)$params[1]];
    apiLoadProject($conn, $apiKey, $pid);
    apiProjectItem($conn, $pid, $iid);
    $in = array_intersect_key($body, array_flip(API_PROJECT_ITEM_FIELDS));
    if (!$in) apiError(422, 'missing_field', 'No fields to update.');
    apiProjectTry(fn() => ProjectToolsService::saveItem($conn, apiProjectCtx($apiKey), $pid, $in + ['id' => $iid]));
    apiRespond(apiSerializeProjectItem(apiProjectItem($conn, $pid, $iid)));
}

function apiProjectItemsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    [$pid, $iid] = [(int)$params[0], (int)$params[1]];
    apiLoadProject($conn, $apiKey, $pid);
    apiProjectItem($conn, $pid, $iid);
    apiProjectTry(fn() => ProjectToolsService::deleteItem($conn, apiProjectCtx($apiKey), $pid, $iid));
    apiRespond(['id' => $params[1], 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// RAID log
// ---------------------------------------------------------------------------
function apiSerializeProjectRaid(array $r): array {
    return [
        'id'            => (int)$r['id'],
        'type'          => $r['type'],
        'title'         => $r['title'],
        'description'   => $r['description'],
        'status'        => $r['status'],
        'probability'   => $r['probability'] === null ? null : (int)$r['probability'],
        'impact'        => $r['impact'] === null ? null : (int)$r['impact'],
        'score'         => $r['score'] === null ? null : (int)$r['score'],
        'response'      => $r['response'],
        'response_plan' => $r['response_plan'],
        'owner'         => $r['owner_analyst_id'] === null ? null : ['id' => (int)$r['owner_analyst_id'], 'name' => $r['owner_name']],
        'due_date'      => $r['due_date'],
        'ticket'        => $r['ticket_id'] === null ? null : ['id' => (int)$r['ticket_id'], 'number' => $r['ticket_number'] ?? null, 'subject' => $r['ticket_subject'] ?? null],
        'raised_at'     => apiIsoDate($r['raised_datetime']),
        'updated_at'    => apiIsoDate($r['updated_datetime']),
        'closed_at'     => apiIsoDate($r['closed_datetime']),
    ];
}

function apiProjectRaidRow(PDO $conn, int $pid, int $rid): array {
    foreach (ProjectToolsService::raid($conn, $pid) as $r) if ((int)$r['id'] === $rid) return $r;
    apiError(404, 'not_found', 'RAID entry not found.');
    return [];
}

const API_PROJECT_RAID_FIELDS = ['type', 'title', 'description', 'status', 'probability', 'impact', 'response', 'response_plan', 'owner_analyst_id', 'due_date', 'ticket_id'];

function apiProjectRaidList(PDO $conn, array $apiKey, array $params, array $body): void {
    $pid = (int)$params[0];
    apiLoadProject($conn, $apiKey, $pid);
    $rows = ProjectToolsService::raid($conn, $pid);
    if (isset($_GET['type']) && $_GET['type'] !== '') $rows = array_filter($rows, fn($r) => $r['type'] === $_GET['type']);
    if (isset($_GET['status']) && $_GET['status'] !== '') $rows = array_filter($rows, fn($r) => $r['status'] === $_GET['status']);
    apiRespond(array_values(array_map('apiSerializeProjectRaid', $rows)));
}

function apiProjectRaidCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $pid = (int)$params[0];
    apiLoadProject($conn, $apiKey, $pid);
    $id = apiProjectTry(fn() => ProjectToolsService::saveRaid($conn, apiProjectCtx($apiKey), $pid, array_intersect_key($body, array_flip(API_PROJECT_RAID_FIELDS))));
    apiRespond(apiSerializeProjectRaid(apiProjectRaidRow($conn, $pid, (int)$id)), 201);
}

function apiProjectRaidUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    [$pid, $rid] = [(int)$params[0], (int)$params[1]];
    apiLoadProject($conn, $apiKey, $pid);
    apiProjectRaidRow($conn, $pid, $rid);
    $in = array_intersect_key($body, array_flip(API_PROJECT_RAID_FIELDS));
    if (!$in) apiError(422, 'missing_field', 'No fields to update.');
    apiProjectTry(fn() => ProjectToolsService::saveRaid($conn, apiProjectCtx($apiKey), $pid, $in + ['id' => $rid]));
    apiRespond(apiSerializeProjectRaid(apiProjectRaidRow($conn, $pid, $rid)));
}

function apiProjectRaidDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    [$pid, $rid] = [(int)$params[0], (int)$params[1]];
    apiLoadProject($conn, $apiKey, $pid);
    apiProjectRaidRow($conn, $pid, $rid);
    apiProjectTry(fn() => ProjectToolsService::deleteRaid($conn, apiProjectCtx($apiKey), $pid, $rid));
    apiRespond(['id' => $params[1], 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// Budget
// ---------------------------------------------------------------------------
function apiSerializeBudgetLine(array $l): array {
    return [
        'id'              => $l['id'],
        'title'           => $l['title'],
        'category'        => $l['category'],
        'planned'         => $l['planned'],
        'actual'          => $l['actual'],
        'actual_entered'  => $l['actual_typed'],
        'actual_source'   => $l['actual_source'],
        'currency_mismatch' => $l['currency_mismatch'],
        'contract'        => $l['contract'] ? ['id' => $l['contract']['id'], 'title' => $l['contract']['title'], 'number' => $l['contract']['number'],
                                               'value' => $l['contract']['value'], 'currency' => $l['contract']['currency']] : null,
        'cost_centre'     => $l['cost_centre'],
        'notes'           => $l['notes'],
    ];
}

/** GET /projects/{id}/budget - totals, labour and lines, in the project's currency. Never one person's rate. */
function apiProjectBudget(PDO $conn, array $apiKey, array $params, array $body): void {
    $p = apiLoadProject($conn, $apiKey, (int)$params[0]);
    if (!projectBudgetReady($conn)) apiError(409, 'not_ready', 'Run Database Verification to switch budgets on.');
    $d = projectBudgetDetail($conn, $p, (int)$apiKey['analyst_id']);
    apiRespond([
        'currency'    => $d['currency'],
        'planned'     => $d['planned'],
        'actual'      => $d['actual'],
        'remaining'   => $d['remaining'],
        'labour'      => [
            'mode'             => $d['labour_mode'],
            'minutes'          => $d['labour']['minutes'],
            'cost'             => $d['labour']['cost'],
            'unpriced_minutes' => $d['labour']['unpriced_minutes'],
        ],
        'lines'       => array_map('apiSerializeBudgetLine', $d['lines']),
    ]);
}

function apiProjectBudgetLine(PDO $conn, array $p, int $lineId, int $analystId): array {
    foreach (projectBudgetDetail($conn, $p, $analystId)['lines'] as $l) if ($l['id'] === $lineId) return $l;
    apiError(404, 'not_found', 'Budget line not found.');
    return [];
}

function apiProjectBudgetLinesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $pid = (int)$params[0];
    $p = apiLoadProject($conn, $apiKey, $pid);
    $in = array_intersect_key($body, array_flip(['title', 'category', 'planned', 'actual', 'contract_id', 'cost_centre_id', 'notes']));
    $id = apiProjectTry(fn() => ProjectToolsService::saveBudgetLine($conn, apiProjectCtx($apiKey), $pid, $in));
    apiRespond(apiSerializeBudgetLine(apiProjectBudgetLine($conn, $p, (int)$id, (int)$apiKey['analyst_id'])), 201);
}

function apiProjectBudgetLinesUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    [$pid, $lid] = [(int)$params[0], (int)$params[1]];
    $p = apiLoadProject($conn, $apiKey, $pid);
    $cur = apiProjectBudgetLine($conn, $p, $lid, (int)$apiKey['analyst_id']);
    $in = array_intersect_key($body, array_flip(['title', 'category', 'planned', 'actual', 'contract_id', 'cost_centre_id', 'notes']));
    if (!$in) apiError(422, 'missing_field', 'No fields to update.');
    // PATCH: what the body leaves out keeps its stored value.
    $merged = $in + [
        'title' => $cur['title'], 'category' => $cur['category'], 'planned' => $cur['planned'], 'actual' => $cur['actual_typed'],
        'contract_id' => $cur['contract']['id'] ?? null, 'cost_centre_id' => $cur['cost_centre']['id'] ?? null, 'notes' => $cur['notes'],
    ];
    apiProjectTry(fn() => ProjectToolsService::saveBudgetLine($conn, apiProjectCtx($apiKey), $pid, $merged + ['id' => $lid]));
    apiRespond(apiSerializeBudgetLine(apiProjectBudgetLine($conn, $p, $lid, (int)$apiKey['analyst_id'])));
}

function apiProjectBudgetLinesDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    [$pid, $lid] = [(int)$params[0], (int)$params[1]];
    $p = apiLoadProject($conn, $apiKey, $pid);
    apiProjectBudgetLine($conn, $p, $lid, (int)$apiKey['analyst_id']);
    apiProjectTry(fn() => ProjectToolsService::deleteBudgetLine($conn, apiProjectCtx($apiKey), $pid, $lid));
    apiRespond(['id' => $params[1], 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// Tasks, connections, history (read-only)
// ---------------------------------------------------------------------------
/** GET /projects/{id}/tasks - its top-level tasks. Change them through /tasks. */
function apiProjectTasks(PDO $conn, array $apiKey, array $params, array $body): void {
    $pid = (int)$params[0];
    apiLoadProject($conn, $apiKey, $pid);
    $t = $conn->prepare("SELECT t.id, t.title, t.project_stage_id, ts.name AS status_name, COALESCE(ts.is_closed, 0) AS is_closed,
                                t.due_date, t.assigned_analyst_id, an.full_name AS assignee_name
                           FROM tasks t
                      LEFT JOIN task_statuses ts ON ts.id = t.status_id
                      LEFT JOIN analysts an ON an.id = t.assigned_analyst_id
                          WHERE t.project_id = ? AND t.parent_task_id IS NULL
                       ORDER BY t.board_position, t.id");
    $t->execute([$pid]);
    apiRespond(array_map(fn($r) => [
        'id'       => (int)$r['id'],
        'title'    => $r['title'],
        'stage_id' => $r['project_stage_id'] === null ? null : (int)$r['project_stage_id'],
        'status'   => $r['status_name'],
        'is_done'  => (bool)(int)$r['is_closed'],
        'due_date' => $r['due_date'],
        'assignee' => $r['assigned_analyst_id'] === null ? null : ['id' => (int)$r['assigned_analyst_id'], 'name' => $r['assignee_name']],
    ], $t->fetchAll(PDO::FETCH_ASSOC)));
}

/** GET /projects/{id}/links - what it is connected to, only the kinds the key's analyst may open. */
function apiProjectLinks(PDO $conn, array $apiKey, array $params, array $body): void {
    $pid = (int)$params[0];
    apiLoadProject($conn, $apiKey, $pid);
    require_once dirname(__DIR__, 3) . '/includes/projects/links.php';
    $links = apiProjectTry(fn() => projectLinks($conn, apiProjectCtx($apiKey), $pid));
    $out = [];
    foreach ($links as $kind => $rows) {
        $out[$kind] = array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['label'], 'detail' => $r['sub'] ?? null, 'status' => $r['status'] ?? null], $rows);
    }
    apiRespond((object)$out);
}

/** GET /projects/{id}/history - newest first, paged. */
function apiProjectHistory(PDO $conn, array $apiKey, array $params, array $body): void {
    $pid = (int)$params[0];
    apiLoadProject($conn, $apiKey, $pid);
    [$page, $perPage, $offset] = apiPagination();
    $count = $conn->prepare("SELECT COUNT(*) FROM project_audit WHERE project_id = ?");
    $count->execute([$pid]);
    $total = (int)$count->fetchColumn();
    $st = $conn->prepare("SELECT h.id, h.field_name, h.old_value, h.new_value, h.source, h.created_datetime, h.analyst_id, a.full_name
                            FROM project_audit h LEFT JOIN analysts a ON a.id = h.analyst_id
                           WHERE h.project_id = ? ORDER BY h.id DESC LIMIT $perPage OFFSET $offset");
    $st->execute([$pid]);
    apiRespond(array_map(fn($r) => [
        'id' => (int)$r['id'], 'field' => $r['field_name'], 'old_value' => $r['old_value'], 'new_value' => $r['new_value'],
        'source' => $r['source'], 'analyst' => $r['analyst_id'] === null ? null : ['id' => (int)$r['analyst_id'], 'name' => $r['full_name']],
        'created_at' => apiIsoDate($r['created_datetime']),
    ], $st->fetchAll(PDO::FETCH_ASSOC)), 200, ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int)ceil($total / max(1, $perPage))]);
}
