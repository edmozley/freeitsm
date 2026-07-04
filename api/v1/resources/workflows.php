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
 * Workflow tables carry no tenant_id (install-wide, same posture as tasks
 * and forms). NOTE for key admins: workflows.fire and workflows.create are
 * powerful grants — actions run with engine privileges (create tickets,
 * send email from the ticket's mailbox), so scope those to trusted keys.
 */

require_once dirname(__DIR__, 3) . '/workflow/includes/engine.php';

// ---------------------------------------------------------------------------
// Serializers + loaders
// ---------------------------------------------------------------------------

function apiWorkflowSelect(): string {
    return "SELECT w.*, a.full_name AS created_by_name
            FROM workflows w
            LEFT JOIN analysts a ON a.id = w.created_by";
}

function apiSerializeWorkflow(array $r, bool $full): array {
    $conditions = json_decode($r['conditions'] ?: '[]', true) ?: [];
    $actions    = json_decode($r['actions']    ?: '[]', true) ?: [];
    $out = [
        'id'            => (int)$r['id'],
        'name'          => $r['name'],
        'description'   => $r['description'],
        'trigger_event' => $r['trigger_event'],
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

function apiLoadWorkflow(PDO $conn, int $workflowId): array {
    $stmt = $conn->prepare(apiWorkflowSelect() . " WHERE w.id = ?");
    $stmt->execute([$workflowId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Workflow not found.');
    }
    return $row;
}

function apiWorkflowExecutionSelect(): string {
    // COALESCE keeps orphaned executions attributed via the name snapshot
    // after their parent workflow is deleted.
    return "SELECT we.*, COALESCE(w.name, we.workflow_name) AS resolved_workflow_name
            FROM workflow_executions we
            LEFT JOIN workflows w ON w.id = we.workflow_id";
}

function apiSerializeWorkflowExecution(array $r, bool $full): array {
    $out = [
        'id'            => (int)$r['id'],
        'workflow'      => [
            'id'   => $r['workflow_id'] === null ? null : (int)$r['workflow_id'],
            'name' => $r['resolved_workflow_name'],
        ],
        'trigger_event' => $r['trigger_event'],
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

/** Validate a conditions array. Returns it normalised (list of objects). */
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

/** Validate an actions array against the engine's action catalogue. */
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

/** Validate a trigger event name against the engine's catalogue. */
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
    $whereSql = implode(' AND ', $where);

    // The module's landing-page ordering: most recently updated first.
    $stmt = $conn->prepare(
        apiWorkflowSelect() . " WHERE $whereSql ORDER BY w.updated_datetime DESC, w.id DESC"
    );
    $stmt->execute($args);
    apiRespond(array_map(function ($r) {
        return apiSerializeWorkflow($r, false);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// GET /workflows/{id}
function apiWorkflowsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond(apiSerializeWorkflow(apiLoadWorkflow($conn, $params[0]), true));
}

// POST /workflows
function apiWorkflowsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        apiError(422, 'missing_field', "'name' is required.");
    }
    $trigger    = apiValidateWorkflowTrigger(trim((string)($body['trigger_event'] ?? '')));
    $conditions = apiValidateWorkflowConditions($body['conditions'] ?? []);
    $actions    = apiValidateWorkflowActions($body['actions'] ?? []);

    $conn->prepare(
        "INSERT INTO workflows
         (name, description, trigger_event, conditions, actions, is_active, created_by, created_datetime, updated_datetime)
         VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    )->execute([
        $name,
        ($v = trim((string)($body['description'] ?? ''))) !== '' ? $v : null,
        $trigger,
        json_encode($conditions),
        json_encode($actions),
        isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1,
        (int)$apiKey['analyst_id'],
    ]);
    apiRespond(apiSerializeWorkflow(apiLoadWorkflow($conn, (int)$conn->lastInsertId()), true), 201);
}

// PATCH /workflows/{id}
function apiWorkflowsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $current = apiLoadWorkflow($conn, $params[0]);
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
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
        ? json_encode(apiValidateWorkflowActions($body['actions']))
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
             is_active = ?, updated_datetime = UTC_TIMESTAMP()
         WHERE id = ?"
    )->execute([$name, $description, $trigger, $conditionsJson, $actionsJson, $isActive, $params[0]]);

    apiRespond(apiSerializeWorkflow(apiLoadWorkflow($conn, $params[0]), true));
}

// DELETE /workflows/{id} — executions survive (the module's audit-trail
// intent) but are detached explicitly: name snapshot filled, workflow_id
// NULLed. Explicit rather than FK-reliant so grown installs that haven't
// re-run Database Verification behave identically.
function apiWorkflowsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    $workflow = apiLoadWorkflow($conn, $params[0]);
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
    apiLoadWorkflow($conn, $params[0]); // clean 404 before the engine runs
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

/** Shared list plumbing for both execution list routes. */
function apiWorkflowExecutionsRespondList(PDO $conn, array $where, array $args): void {
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
        apiWorkflowExecutionSelect() . " WHERE $whereSql
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
    apiLoadWorkflow($conn, $params[0]);
    apiWorkflowExecutionsRespondList($conn, ['we.workflow_id = ?'], [$params[0]]);
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
    apiWorkflowExecutionsRespondList($conn, $where, $args);
}

// GET /workflow-executions/{id} — full detail with payload + step log.
function apiWorkflowExecutionsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $stmt = $conn->prepare(apiWorkflowExecutionSelect() . " WHERE we.id = ?");
    $stmt->execute([$params[0]]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
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
// required flags, lookup sources, template-variable support).
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
