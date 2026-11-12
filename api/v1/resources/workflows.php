<?php
/**
 * FreeITSM REST API v1 — workflows resource (definitions + executions).
 *
 * Mirrors the module's internal endpoints (api/workflow/*):
 *   - definitions are single rows with conditions/actions as JSON — the API
 *     accepts and returns them as real JSON arrays, validated against the
 *     engine's own catalogues (WorkflowEngine::availableTriggers/Actions)
 *     exactly like save.php, so nothing unexecutable can be stored;
 *   - POST /workflows/{id}/fire is the editor's "Test fire" button —
 *     WorkflowEngine::manualFire() with a synthetic payload. Like the UI,
 *     manual fires do NOT bump run_count / last_run_* (test runs don't
 *     pollute production counters) but DO write an execution row;
 *   - executions are the engine's audit trail and are read-only over the
 *     API — only the engine writes them.
 *
 * Deliberate improvements over the internal endpoints, documented:
 *   - unknown condition operators are a 422 (save.php stores them; the
 *     engine then silently fails the condition at run time — machines
 *     deserve the error at write time);
 *   - zero actions is still allowed (parity with save.php's draft-friendly
 *     behaviour) but conditions/actions entries must be objects;
 *   - deleting a workflow keeps its executions (the module's intent) but
 *     detaches them cleanly: workflow_id goes NULL and the workflow_name
 *     snapshot preserves attribution — no more dangling ids.
 *
 * Companies (GH #209). A workflow is SHARED (`company` null: it runs for every
 * company) or one company's own - the Knowledge convention, so NULL is NOT the
 * Default company here and apiKeyTenantFilter() must not be used (see
 * includes/workflow_tenancy.php). A key sees the shared workflows and those of
 * the companies in its scope; creating, changing, deleting or firing a shared
 * one needs a key that reaches every company, because it acts on all of them.
 * A run belongs to the company of the event it ran for, and is listed only for
 * a key that reaches it; a run with no company known only for an every-company
 * key. All of it is a no-op on a single-company install and before Database
 * Verification has added the columns.
 *
 * NOTE for key admins: workflows.fire and workflows.create are
 * powerful grants — actions run with engine privileges (create tickets,
 * send email from the ticket's mailbox), so scope those to trusted keys.
 */

require_once dirname(__DIR__, 3) . '/workflow/includes/engine.php';
require_once dirname(__DIR__, 3) . '/includes/workflow_tenancy.php';

// ---------------------------------------------------------------------------
// Serializers + loaders
// ---------------------------------------------------------------------------

/**
 * The SELECT for workflows, with the creator's name and, once the column exists, the company's.
 */
function apiWorkflowSelect(PDO $conn): string {
    if (!workflowTenancyReady($conn)) {
        return "SELECT w.*, NULL AS tenant_id, a.full_name AS created_by_name, NULL AS tenant_name
            FROM workflows w
            LEFT JOIN analysts a ON a.id = w.created_by";
    }
    return "SELECT w.*, a.full_name AS created_by_name, tn.name AS tenant_name
            FROM workflows w
            LEFT JOIN analysts a ON a.id = w.created_by
            LEFT JOIN tenants tn ON tn.id = w.tenant_id";
}

/** Does this key reach every company? (Always, on a single-company install.) */
function apiKeyReachesEveryCompany(PDO $conn, array $apiKey): bool {
    return !workflowTenancyActive($conn) || $apiKey['company_scope'] === null;
}

/**
 * May this key create, change, delete or fire a workflow for this company? null = shared.
 *
 * @param ?int $tenantId  the workflow's company; null = shared
 */
function apiKeyCanWriteWorkflowFor(PDO $conn, array $apiKey, ?int $tenantId): bool {
    if (apiKeyReachesEveryCompany($conn, $apiKey)) return true;
    return $tenantId !== null && apiKeyCanAccessTenant($conn, $apiKey, $tenantId);
}

/** 403 unless the key may write a workflow for this company. */
function apiRequireWorkflowWrite(PDO $conn, array $apiKey, ?int $tenantId): void {
    if (apiKeyCanWriteWorkflowFor($conn, $apiKey, $tenantId)) return;
    apiError(403, 'forbidden', $tenantId === null
        ? 'This workflow runs for every company, so only a key that reaches every company can change or fire it.'
        : 'Your API key does not have access to that company.');
}

/**
 * The company a request names for a workflow: absent = $default; null / '' / 'shared' = shared; else an existing company id.
 *
 * @param array $body  the request body
 * @param ?int $default  what an absent company_id means
 */
function apiWorkflowCompanyFromBody(PDO $conn, array $body, ?int $default): ?int {
    if (!array_key_exists('company_id', $body)) return $default;
    [$ok, $tenant, $err] = workflowParseCompany($conn, $body['company_id']);
    if (!$ok) apiError(422, 'invalid_field', "'company_id': " . $err . '.');
    return $tenant;
}

/**
 * Runs this key may read: those whose event's company is in its scope.
 *
 * @param string $qualified  the already-qualified column, e.g. 'we.tenant_id'
 * @return array [sqlFragment, params]
 */
function apiWorkflowExecutionFilter(PDO $conn, array $apiKey, string $qualified): array {
    if (apiKeyReachesEveryCompany($conn, $apiKey)) return ['', []];
    $ids = array_values(array_map('intval', $apiKey['company_scope']));
    if (!$ids) return [' AND 1 = 0', []];
    return [" AND $qualified IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids];
}

/**
 * Shapes a workflow row for the API - trigger, active flag, people, dates, last run and
 * run count, plus the full conditions and actions when $full (only their counts
 * otherwise).
 *
 * @param array $r  the workflows row as apiWorkflowSelect() loads it (conditions and actions still JSON text)
 * @param bool $full  true to include the conditions and actions in full; false gives only their counts
 */
function apiSerializeWorkflow(array $r, bool $full): array {
    $conditions = json_decode($r['conditions'] ?: '[]', true) ?: [];
    $actions    = json_decode($r['actions']    ?: '[]', true) ?: [];
    $out = [
        'id'            => (int)$r['id'],
        'name'          => $r['name'],
        'description'   => $r['description'],
        'trigger_event' => $r['trigger_event'],
        // GH #209. null = shared: runs for every company.
        'company'       => ($r['tenant_id'] ?? null) === null ? null : ['id' => (int)$r['tenant_id'], 'name' => $r['tenant_name'] ?? null],
        'is_active'     => (bool)$r['is_active'],
        'created_by'    => $r['created_by'] === null ? null : ['id' => (int)$r['created_by'], 'name' => $r['created_by_name']],
        'created_at'    => apiIsoDate($r['created_datetime']),
        'updated_at'    => apiIsoDate($r['updated_datetime']),
        'last_run'      => $r['last_run_datetime'] === null ? null : [
            'at'     => apiIsoDate($r['last_run_datetime']),
            'status' => $r['last_run_status'],
        ],
        'run_count'     => (int)$r['run_count'],
    ];
    if ($full) {
        $out['conditions'] = $conditions;
        $out['actions']    = $actions;
    } else {
        // The list stays light, like the module's landing page — counts
        // instead of the full rule bodies.
        $out['conditions_count'] = count($conditions);
        $out['actions_count']    = count($actions);
    }
    return $out;
}

/**
 * Loads one workflow row, or answers 404 and stops.
 *
 * @param int $workflowId  the workflows id
 */
function apiLoadWorkflow(PDO $conn, int $workflowId, ?array $apiKey = null): array {
    $stmt = $conn->prepare(apiWorkflowSelect($conn) . " WHERE w.id = ?");
    $stmt->execute([$workflowId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    // GH #209: another company's workflow is not found, not forbidden.
    if ($row && $apiKey !== null && $row['tenant_id'] !== null && !apiKeyReachesEveryCompany($conn, $apiKey)
        && !apiKeyCanAccessTenant($conn, $apiKey, (int)$row['tenant_id'])) {
        $row = false;
    }
    if (!$row) {
        apiError(404, 'not_found', 'Workflow not found.');
    }
    return $row;
}

/**
 * The SELECT for workflow executions, keeping the workflow's name from the snapshot when
 * the workflow has since been deleted.
 */
function apiWorkflowExecutionSelect(PDO $conn): string {
    // COALESCE keeps orphaned executions attributed via the name snapshot
    // after their parent workflow is deleted.
    if (!workflowTenancyReady($conn)) {
        return "SELECT we.*, NULL AS tenant_id, NULL AS tenant_name, COALESCE(w.name, we.workflow_name) AS resolved_workflow_name
            FROM workflow_executions we
            LEFT JOIN workflows w ON w.id = we.workflow_id";
    }
    return "SELECT we.*, tn.name AS tenant_name, COALESCE(w.name, we.workflow_name) AS resolved_workflow_name
            FROM workflow_executions we
            LEFT JOIN workflows w ON w.id = we.workflow_id
            LEFT JOIN tenants tn ON tn.id = we.tenant_id";
}

/**
 * Shapes a workflow execution for the API - workflow, trigger, status, times and error,
 * plus the trigger payload and step log when $full.
 *
 * @param array $r  the workflow_executions row as apiWorkflowExecutionSelect() loads it, with resolved_workflow_name
 * @param bool $full  true to add the decoded trigger_payload and step_log
 */
function apiSerializeWorkflowExecution(array $r, bool $full): array {
    $out = [
        'id'            => (int)$r['id'],
        'workflow'      => [
            'id'   => $r['workflow_id'] === null ? null : (int)$r['workflow_id'],
            'name' => $r['resolved_workflow_name'],
        ],
        'trigger_event' => $r['trigger_event'],
        // GH #209. The company the event belonged to; null = none known.
        'company'       => ($r['tenant_id'] ?? null) === null ? null : ['id' => (int)$r['tenant_id'], 'name' => $r['tenant_name'] ?? null],
        'status'        => $r['status'],
        'started_at'    => apiIsoDate($r['started_datetime']),
        'finished_at'   => apiIsoDate($r['finished_datetime']),
        'error_message' => $r['error_message'],
    ];
    if ($full) {
        $out['trigger_payload'] = json_decode($r['trigger_payload'] ?: 'null', true);
        $out['step_log']        = json_decode($r['step_log'] ?: '[]', true) ?: [];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Validation — the engine's catalogues are the contract
// ---------------------------------------------------------------------------

/**
 * Validate a conditions array. Returns it normalised (list of objects).
 *
 * @param mixed $conditions  the caller's conditions (untrusted) - a list of {field, op, value}
 */
function apiValidateWorkflowConditions($conditions): array {
    if (!is_array($conditions)) {
        apiError(422, 'invalid_field', "'conditions' must be an array.");
    }
    $operators = array_keys(WorkflowEngine::availableOperators());
    $out = [];
    foreach (array_values($conditions) as $i => $c) {
        if (!is_array($c)) {
            apiError(422, 'invalid_field', "conditions[{$i}] must be an object with 'field', 'op' and 'value'.");
        }
        $field = trim((string)($c['field'] ?? ''));
        $op    = trim((string)($c['op'] ?? ''));
        if ($field === '') {
            apiError(422, 'invalid_field', "conditions[{$i}]: 'field' is required.");
        }
        if (!in_array($op, $operators, true)) {
            apiError(422, 'invalid_field', "conditions[{$i}]: unknown operator '{$op}'. See GET /workflow-triggers.");
        }
        $out[] = ['field' => $field, 'op' => $op, 'value' => $c['value'] ?? null];
    }
    return $out;
}

/**
 * Validate an actions array against the engine's action catalogue.
 *
 * @param mixed $actions  the caller's actions (untrusted) - a list of {type, args}
 */
function apiValidateWorkflowActions($actions): array {
    if (!is_array($actions)) {
        apiError(422, 'invalid_field', "'actions' must be an array.");
    }
    $catalogue = WorkflowEngine::availableActions();
    $out = [];
    foreach (array_values($actions) as $i => $a) {
        if (!is_array($a)) {
            apiError(422, 'invalid_field', "actions[{$i}] must be an object with 'type' and 'args'.");
        }
        $type = trim((string)($a['type'] ?? ''));
        if (!isset($catalogue[$type])) {
            apiError(422, 'invalid_field', "actions[{$i}]: unknown action type '{$type}'. See GET /workflow-actions.");
        }
        $args = $a['args'] ?? [];
        if (!is_array($args)) {
            apiError(422, 'invalid_field', "actions[{$i}]: 'args' must be an object.");
        }
        $out[] = ['type' => $type, 'args' => $args];
    }
    return $out;
}

/**
 * Validate a trigger event name against the engine's catalogue.
 *
 * @param string $trigger  the requested trigger event name
 */
function apiValidateWorkflowTrigger(string $trigger): string {
    if (!array_key_exists($trigger, WorkflowEngine::availableTriggers())) {
        apiError(422, 'invalid_field', "Unknown trigger event: '{$trigger}'. See GET /workflow-triggers.");
    }
    return $trigger;
}

// ---------------------------------------------------------------------------
// Workflows
// ---------------------------------------------------------------------------

// GET /workflows
function apiWorkflowsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];
    if (isset($_GET['trigger_event']) && trim($_GET['trigger_event']) !== '') {
        $where[] = 'w.trigger_event = ?';
        $args[]  = trim($_GET['trigger_event']);
    }
    if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
        $where[] = 'w.is_active = ?';
        $args[]  = $_GET['is_active'] === 'true' ? 1 : 0;
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = '(w.name LIKE ? OR w.description LIKE ?)';
        $q = '%' . trim($_GET['q']) . '%';
        $args[] = $q;
        $args[] = $q;
    }
    // GH #209. The shared workflows and the key's companies' own; ?company=
    // narrows to one company's or, with 'shared', the shared ones.
    if (workflowTenancyActive($conn)) {
        if (!apiKeyReachesEveryCompany($conn, $apiKey)) {
            $ids = array_values(array_map('intval', $apiKey['company_scope']));
            $where[] = $ids ? '(w.tenant_id IS NULL OR w.tenant_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))' : 'w.tenant_id IS NULL';
            $args = array_merge($args, $ids);
        }
        if (isset($_GET['company']) && $_GET['company'] !== '') {
            if ($_GET['company'] === 'shared') {
                $where[] = 'w.tenant_id IS NULL';
            } else {
                $companyId = (int)$_GET['company'];
                if (!apiKeyCanAccessTenant($conn, $apiKey, $companyId)) {
                    apiError(403, 'forbidden', 'Your API key does not have access to that company.');
                }
                $where[] = 'w.tenant_id = ?';
                $args[]  = $companyId;
            }
        }
    }
    $whereSql = implode(' AND ', $where);

    // The module's landing-page ordering: most recently updated first.
    $stmt = $conn->prepare(
        apiWorkflowSelect($conn) . " WHERE $whereSql ORDER BY w.updated_datetime DESC, w.id DESC"
    );
    $stmt->execute($args);
    apiRespond(array_map(function ($r) {
        return apiSerializeWorkflow($r, false);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// GET /workflows/{id}
function apiWorkflowsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond(apiSerializeWorkflow(apiLoadWorkflow($conn, $params[0], $apiKey), true));
}

// POST /workflows
function apiWorkflowsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        apiError(422, 'missing_field', "'name' is required.");
    }
    $trigger    = apiValidateWorkflowTrigger(trim((string)($body['trigger_event'] ?? '')));
    $conditions = apiValidateWorkflowConditions($body['conditions'] ?? []);
    require_once __DIR__ . '/../../../includes/webhook_delivery.php';
    $actions    = webhookEncryptActions(apiValidateWorkflowActions($body['actions'] ?? []));   // at rest, as the browser save does

    // GH #209. No company_id: shared for a key that reaches every company (as
    // before), otherwise the key's own default company - a scoped key can
    // never make a workflow that runs for companies it cannot see.
    $tenancyOn = workflowTenancyActive($conn);
    $tenant = null;
    if ($tenancyOn) {
        $tenant = apiWorkflowCompanyFromBody($conn, $body, apiKeyReachesEveryCompany($conn, $apiKey) ? null : apiKeyDefaultTenantId($conn, $apiKey));
        apiRequireWorkflowWrite($conn, $apiKey, $tenant);
    }
    $tCol = $tenancyOn ? ', tenant_id' : '';
    $conn->prepare(
        "INSERT INTO workflows
         (name, description, trigger_event, conditions, actions, is_active, created_by, created_datetime, updated_datetime$tCol)
         VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()" . ($tenancyOn ? ', ?' : '') . ")"
    )->execute(array_merge([
        $name,
        ($v = trim((string)($body['description'] ?? ''))) !== '' ? $v : null,
        $trigger,
        json_encode($conditions),
        json_encode($actions),
        isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1,
        (int)$apiKey['analyst_id'],
    ], $tenancyOn ? [$tenant] : []));
    apiRespond(apiSerializeWorkflow(apiLoadWorkflow($conn, (int)$conn->lastInsertId()), true), 201);
}

// PATCH /workflows/{id}
function apiWorkflowsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $current = apiLoadWorkflow($conn, $params[0], $apiKey);
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }
    // GH #209: the right to the workflow as it is, and to where it is moving.
    $tenancyOn = workflowTenancyActive($conn);
    $curTenant = $current['tenant_id'] === null ? null : (int)$current['tenant_id'];
    $tenant = $curTenant;
    if ($tenancyOn) {
        apiRequireWorkflowWrite($conn, $apiKey, $curTenant);
        $tenant = apiWorkflowCompanyFromBody($conn, $body, $curTenant);
        apiRequireWorkflowWrite($conn, $apiKey, $tenant);
    }

    $name = array_key_exists('name', $body) ? trim((string)$body['name']) : $current['name'];
    if ($name === '') {
        apiError(422, 'invalid_field', "'name' cannot be empty.");
    }
    $trigger = array_key_exists('trigger_event', $body)
        ? apiValidateWorkflowTrigger(trim((string)$body['trigger_event']))
        : $current['trigger_event'];
    $conditionsJson = array_key_exists('conditions', $body)
        ? json_encode(apiValidateWorkflowConditions($body['conditions']))
        : $current['conditions'];
    $actionsJson = array_key_exists('actions', $body)
        ? json_encode((function ($a) { require_once __DIR__ . '/../../../includes/webhook_delivery.php'; return webhookEncryptActions($a); })(apiValidateWorkflowActions($body['actions'])))
        : $current['actions'];
    $description = array_key_exists('description', $body)
        ? (($v = trim((string)$body['description'])) !== '' ? $v : null)
        : $current['description'];
    $isActive = array_key_exists('is_active', $body)
        ? (int)(bool)$body['is_active']
        : (int)$current['is_active'];

    $conn->prepare(
        "UPDATE workflows
         SET name = ?, description = ?, trigger_event = ?, conditions = ?, actions = ?,
             is_active = ?, updated_datetime = UTC_TIMESTAMP()" . ($tenancyOn ? ', tenant_id = ?' : '') . "
         WHERE id = ?"
    )->execute(array_merge([$name, $description, $trigger, $conditionsJson, $actionsJson, $isActive], $tenancyOn ? [$tenant] : [], [$params[0]]));

    apiRespond(apiSerializeWorkflow(apiLoadWorkflow($conn, $params[0]), true));
}

// DELETE /workflows/{id} — executions survive (the module's audit-trail
// intent) but are detached explicitly: name snapshot filled, workflow_id
// NULLed. Explicit rather than FK-reliant so grown installs that haven't
// re-run Database Verification behave identically.
function apiWorkflowsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    $workflow = apiLoadWorkflow($conn, $params[0], $apiKey);
    if (workflowTenancyActive($conn)) apiRequireWorkflowWrite($conn, $apiKey, $workflow['tenant_id'] === null ? null : (int)$workflow['tenant_id']);
    $conn->beginTransaction();
    try {
        $conn->prepare(
            "UPDATE workflow_executions
             SET workflow_name = COALESCE(workflow_name, ?), workflow_id = NULL
             WHERE workflow_id = ?"
        )->execute([$workflow['name'], $params[0]]);
        $conn->prepare("DELETE FROM workflows WHERE id = ?")->execute([$params[0]]);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    apiRespond(['id' => $params[0], 'deleted' => true]);
}

// POST /workflows/{id}/fire — manual test fire with a synthetic payload.
function apiWorkflowsFire(PDO $conn, array $apiKey, array $params, array $body): void {
    $wf = apiLoadWorkflow($conn, $params[0], $apiKey); // clean 404 before the engine runs
    // GH #209: firing runs the actions, so the same right as changing it.
    if (workflowTenancyActive($conn)) apiRequireWorkflowWrite($conn, $apiKey, $wf['tenant_id'] === null ? null : (int)$wf['tenant_id']);
    $payload = (isset($body['payload']) && is_array($body['payload'])) ? $body['payload'] : [];
    $result = WorkflowEngine::manualFire($params[0], $payload);
    apiRespond([
        'execution_id'  => $result['execution_id'],
        'workflow_id'   => $result['workflow_id'],
        'status'        => $result['status'],
        'step_log'      => $result['step_log'],
        'error_message' => $result['error_message'],
    ]);
}

// ---------------------------------------------------------------------------
// Executions (read-only — only the engine writes these)
// ---------------------------------------------------------------------------

/**
 * Shared list plumbing for both execution list routes.
 *
 * @param array $where  SQL conditions on workflow_executions (alias we) from the route, e.g. ['1=1'] or a workflow_id filter;
 *    the query-string filters are added to it
 * @param array $args  the bound values for the ? placeholders in $where, in order
 */
function apiWorkflowExecutionsRespondList(PDO $conn, array $where, array $args, ?array $apiKey = null): void {
    // GH #209: only runs for the key's companies.
    if ($apiKey !== null) {
        [$eSql, $eArgs] = apiWorkflowExecutionFilter($conn, $apiKey, 'we.tenant_id');
        if ($eSql !== '') { $where[] = substr($eSql, strlen(' AND ')); $args = array_merge($args, $eArgs); }
    }
    if (isset($_GET['status']) && trim($_GET['status']) !== '') {
        $where[] = 'we.status = ?';
        $args[]  = trim($_GET['status']);
    }
    if (isset($_GET['trigger_event']) && trim($_GET['trigger_event']) !== '') {
        $where[] = 'we.trigger_event = ?';
        $args[]  = trim($_GET['trigger_event']);
    }
    if (isset($_GET['started_since']) && $_GET['started_since'] !== '') {
        $where[] = 'we.started_datetime >= ?';
        $args[]  = apiParseDate($_GET['started_since'], 'started_since');
    }

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM workflow_executions we WHERE $whereSql");
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(
        apiWorkflowExecutionSelect($conn) . " WHERE $whereSql
         ORDER BY we.started_datetime DESC, we.id DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($args);
    apiRespond(array_map(function ($r) {
        return apiSerializeWorkflowExecution($r, false);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// GET /workflows/{id}/executions
function apiWorkflowExecutionsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadWorkflow($conn, $params[0], $apiKey);
    apiWorkflowExecutionsRespondList($conn, ['we.workflow_id = ?'], [$params[0]], $apiKey);
}

// GET /workflow-executions — install-wide, including orphaned runs whose
// workflow has been deleted (filter with ?workflow_id or ?orphaned=true).
function apiWorkflowExecutionsListAll(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];
    if (isset($_GET['workflow_id']) && $_GET['workflow_id'] !== '') {
        $where[] = 'we.workflow_id = ?';
        $args[]  = (int)$_GET['workflow_id'];
    }
    if (isset($_GET['orphaned']) && $_GET['orphaned'] === 'true') {
        $where[] = 'we.workflow_id IS NULL';
    }
    apiWorkflowExecutionsRespondList($conn, $where, $args, $apiKey);
}

// GET /workflow-executions/{id} — full detail with payload + step log.
function apiWorkflowExecutionsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    [$eSql, $eArgs] = apiWorkflowExecutionFilter($conn, $apiKey, 'we.tenant_id');
    $stmt = $conn->prepare(apiWorkflowExecutionSelect($conn) . " WHERE we.id = ?$eSql");
    $stmt->execute(array_merge([$params[0]], $eArgs));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    // GH #209: a run for another company is not found.
    if (!$row) {
        apiError(404, 'not_found', 'Execution not found.');
    }
    apiRespond(apiSerializeWorkflowExecution($row, true));
}

// ---------------------------------------------------------------------------
// Reference lookups — the engine's catalogues, so API clients can build
// valid rules without guessing
// ---------------------------------------------------------------------------

// GET /workflow-triggers — every trigger with its condition fields, each
// field's type and the operators valid for it.
function apiWorkflowTriggersList(PDO $conn, array $apiKey, array $params, array $body): void {
    $out = [];
    foreach (WorkflowEngine::availableTriggers() as $key => $label) {
        $fields = [];
        foreach (WorkflowEngine::availableFields($key) as $path) {
            $type = WorkflowEngine::fieldType($path);
            $fields[] = [
                'path'      => $path,
                'type'      => $type,
                'operators' => WorkflowEngine::operatorsForFieldType($type),
            ];
        }
        $out[] = ['key' => $key, 'label' => $label, 'fields' => $fields];
    }
    apiRespond($out);
}

// GET /workflow-actions — every action type with its args spec (labels,
// required flags, lookup sources, template-variable support, fixed choices).
// TRAP: a client that builds actions from this needs options (a select's choices -
// without them it cannot know what to send) and new_default (GH #268: the value a
// NEWLY added action starts with, which can differ from default). Both were missing
// until 5.1.0. The engine never fills a missing arg in at run time - send it.
function apiWorkflowActionsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $out = [];
    foreach (WorkflowEngine::availableActions() as $key => $spec) {
        $args = [];
        foreach ($spec['args'] as $argName => $argSpec) {
            $args[] = [
                'name'          => $argName,
                'type'          => $argSpec['type'] ?? 'text',
                'label'         => $argSpec['label'] ?? $argName,
                'required'      => (bool)($argSpec['required'] ?? false),
                'default'       => $argSpec['default'] ?? null,
                'supports_vars' => (bool)($argSpec['supports_vars'] ?? false),
                'lookup'        => $argSpec['lookup'] ?? null,
                'new_default'   => $argSpec['new_default'] ?? null,
                'options'       => isset($argSpec['options']) && is_array($argSpec['options']) ? array_values($argSpec['options']) : null,
                'show_when'     => $argSpec['show_when'] ?? null,
            ];
        }
        $out[] = [
            'key'         => $key,
            'label'       => $spec['label'],
            'description' => $spec['description'] ?? null,
            'args'        => $args,
        ];
    }
    apiRespond($out);
}
