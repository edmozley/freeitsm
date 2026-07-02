<?php
/**
 * FreeITSM REST API v1 — problems resource (Problem Management).
 *
 * Mirrors the module's internal endpoints so a problem touched via the API is
 * indistinguishable from one touched in the UI:
 *   - create/update mirror api/problem-management/save.php: per-field audit
 *     rows with the SAME field keys the UI writes ('title', 'status',
 *     'assigned_to', 'known_error' as Yes/No, …), display names for lookups,
 *     values truncated to 1000 chars, closed_datetime transitions driven by
 *     problem_statuses.is_closed, and PRB-##### numbers stamped post-insert.
 *   - incident linking mirrors link_ticket.php, including the SAME-COMPANY
 *     rule between problem and ticket; change linking mirrors link_change.php
 *     via the shared change_relations table (related_type='problem').
 *   - notes are an append-only journal (no edit/delete — same as the UI).
 *   - unlinking writes no audit row (parity with the UI).
 *
 * Problems ARE company-scoped (problems.tenant_id), so the key's company
 * scope is enforced: apiKeyTenantFilter on lists, apiKeyCanAccessProblem on
 * every by-id read/write. Invisible on single-company installs.
 */

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function apiProblemSelect(): string {
    return "SELECT p.*,
                   ps.name AS status_name, ps.is_closed AS status_is_closed,
                   pp.name AS priority_name,
                   a.full_name  AS analyst_name,
                   cb.full_name AS created_by_name,
                   tn.name AS company_name,
                   (SELECT COUNT(*) FROM problem_tickets pt WHERE pt.problem_id = p.id) AS ticket_count
            FROM problems p
            LEFT JOIN problem_statuses   ps ON ps.id = p.status_id
            LEFT JOIN problem_priorities pp ON pp.id = p.priority_id
            LEFT JOIN analysts           a  ON a.id  = p.assigned_analyst_id
            LEFT JOIN analysts           cb ON cb.id = p.created_by_id
            LEFT JOIN tenants            tn ON tn.id = p.tenant_id";
}

function apiSerializeProblem(array $r): array {
    $rel = function ($id, $name, array $extra = []) {
        return $id === null ? null : array_merge(['id' => (int)$id, 'name' => $name], $extra);
    };
    return [
        'id'             => (int)$r['id'],
        'problem_number' => $r['problem_number'],
        'title'          => $r['title'],
        'description'    => $r['description'],
        'status'         => $rel($r['status_id'], $r['status_name'], ['is_closed' => (bool)($r['status_is_closed'] ?? false)]),
        'priority'       => $rel($r['priority_id'], $r['priority_name']),
        'assigned_analyst' => $rel($r['assigned_analyst_id'], $r['analyst_name']),
        'is_known_error' => (bool)$r['is_known_error'],
        'root_cause'     => $r['root_cause'],
        'workaround'     => $r['workaround'],
        'company'        => $rel($r['tenant_id'], $r['company_name']),
        'created_by'     => $rel($r['created_by_id'], $r['created_by_name']),
        'linked_tickets_count' => (int)($r['ticket_count'] ?? 0),
        'created_at'     => apiIsoDate($r['created_datetime']),
        'updated_at'     => apiIsoDate($r['updated_datetime']),
        'closed_at'      => apiIsoDate($r['closed_datetime']),
    ];
}

/** Load one problem (with joins) enforcing the key's company scope; 404 if not visible. */
function apiLoadProblem(PDO $conn, array $apiKey, int $problemId): array {
    if (!apiKeyCanAccessProblem($conn, $apiKey, $problemId)) {
        apiError(404, 'not_found', 'Problem not found.');
    }
    $stmt = $conn->prepare(apiProblemSelect() . " WHERE p.id = ?");
    $stmt->execute([$problemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Problem not found.');
    }
    return $row;
}

function apiProblemAuditWrite(PDO $conn, int $problemId, int $analystId, string $actionType, ?string $field, ?string $old, ?string $new): void {
    $stmt = $conn->prepare(
        "INSERT INTO problem_audit (problem_id, analyst_id, action_type, field_name, old_value, new_value, created_datetime)
         VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    );
    // The UI truncates audit values to the column width.
    $stmt->execute([
        $problemId, $analystId, $actionType, $field,
        $old !== null ? mb_substr($old, 0, 1000) : null,
        $new !== null ? mb_substr($new, 0, 1000) : null,
    ]);
}

function apiProblemTouch(PDO $conn, int $problemId): void {
    $conn->prepare("UPDATE problems SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$problemId]);
}

/** Resolve a problem status by name or id (status / status_id). Returns [id, name, is_closed] or null. */
function apiResolveProblemStatus(PDO $conn, array $body): ?array {
    if (isset($body['status_id']) && $body['status_id'] !== '' && $body['status_id'] !== null) {
        $stmt = $conn->prepare("SELECT id, name, is_closed FROM problem_statuses WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$body['status_id']]);
    } elseif (isset($body['status']) && trim((string)$body['status']) !== '') {
        $stmt = $conn->prepare("SELECT id, name, is_closed FROM problem_statuses WHERE name = ? LIMIT 1");
        $stmt->execute([trim((string)$body['status'])]);
    } else {
        return null;
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(422, 'invalid_field', 'Unknown problem status: ' . ($body['status'] ?? $body['status_id']));
    }
    return [(int)$row['id'], $row['name'], (int)$row['is_closed']];
}

/** Resolve a problem priority by name or id. Explicit null/'' clears. */
function apiResolveProblemPriority(PDO $conn, array $body): ?array {
    if (array_key_exists('priority_id', $body)) {
        if ($body['priority_id'] === '' || $body['priority_id'] === null) {
            return [null, null];
        }
        $stmt = $conn->prepare("SELECT id, name FROM problem_priorities WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$body['priority_id']]);
    } elseif (isset($body['priority']) && trim((string)$body['priority']) !== '') {
        $stmt = $conn->prepare("SELECT id, name FROM problem_priorities WHERE name = ? LIMIT 1");
        $stmt->execute([trim((string)$body['priority'])]);
    } else {
        return null;
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(422, 'invalid_field', 'Unknown problem priority: ' . ($body['priority'] ?? $body['priority_id']));
    }
    return [(int)$row['id'], $row['name']];
}

/** Validate an analyst id (active); returns full_name. */
function apiResolveAnalyst(PDO $conn, int $analystId): string {
    $stmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ? AND is_active = 1");
    $stmt->execute([$analystId]);
    $name = $stmt->fetchColumn();
    if ($name === false) {
        apiError(422, 'invalid_field', "Unknown or inactive analyst id: {$analystId}");
    }
    return $name;
}

// ---------------------------------------------------------------------------
// GET /problems
// ---------------------------------------------------------------------------
function apiProblemsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];

    $state = strtolower(trim($_GET['state'] ?? 'all'));
    if ($state === 'open')   $where[] = "(ps.is_closed IS NULL OR ps.is_closed = 0)";
    if ($state === 'closed') $where[] = "ps.is_closed = 1";

    foreach ([
        'status_id'           => 'p.status_id',
        'priority_id'         => 'p.priority_id',
        'assigned_analyst_id' => 'p.assigned_analyst_id',
    ] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = (int)$_GET[$param];
        }
    }
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $where[] = 'ps.name = ?';
        $args[]  = trim($_GET['status']);
    }
    if (isset($_GET['priority']) && $_GET['priority'] !== '') {
        $where[] = 'pp.name = ?';
        $args[]  = trim($_GET['priority']);
    }
    if (isset($_GET['is_known_error']) && $_GET['is_known_error'] !== '') {
        $where[] = 'p.is_known_error = ?';
        $args[]  = $_GET['is_known_error'] === 'true' ? 1 : 0;
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = '(p.title LIKE ? OR p.problem_number LIKE ?)';
        $like = '%' . trim($_GET['q']) . '%';
        array_push($args, $like, $like);
    }
    foreach ([
        'created_since' => ['p.created_datetime', '>='],
        'updated_since' => ['p.updated_datetime', '>='],
    ] as $param => [$col, $op]) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col $op ?";
            $args[]  = apiParseDate($_GET[$param], $param);
        }
    }
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $cid = (int)$_GET['company_id'];
        if (!apiKeyCanAccessTenant($conn, $apiKey, $cid)) {
            apiError(403, 'forbidden', 'This API key is not scoped to that company.');
        }
        if ($cid === getDefaultTenantId($conn)) {
            $where[] = '(p.tenant_id = ? OR p.tenant_id IS NULL)';
        } else {
            $where[] = 'p.tenant_id = ?';
        }
        $args[] = $cid;
    }

    // Key company scope (problems are tenant-scoped, like tickets).
    [$scopeSql, $scopeArgs] = apiKeyTenantFilter($conn, $apiKey, 'p');
    $whereSql = implode(' AND ', $where) . $scopeSql;
    $args = array_merge($args, $scopeArgs);

    $sortable = [
        'id' => 'p.id', 'created_at' => 'p.created_datetime', 'updated_at' => 'p.updated_datetime',
        'closed_at' => 'p.closed_datetime', 'title' => 'p.title', 'problem_number' => 'p.problem_number',
        'priority' => 'p.priority_id', 'status' => 'p.status_id',
    ];
    $sortParam = trim($_GET['sort'] ?? '-created_at');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC');

    [$page, $perPage, $offset] = apiPagination();

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) FROM problems p
         LEFT JOIN problem_statuses ps ON ps.id = p.status_id
         LEFT JOIN problem_priorities pp ON pp.id = p.priority_id
         WHERE $whereSql"
    );
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(apiProblemSelect() . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $stmt->execute($args);
    $problems = array_map('apiSerializeProblem', $stmt->fetchAll(PDO::FETCH_ASSOC));

    apiRespond($problems, 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// ---------------------------------------------------------------------------
// GET /problems/{id} — full detail incl. linked incidents + changes
// ---------------------------------------------------------------------------
function apiProblemsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $row = apiLoadProblem($conn, $apiKey, $params[0]);
    $problem = apiSerializeProblem($row);

    $inc = $conn->prepare(
        "SELECT t.id, t.ticket_number, t.subject, ts.name AS status, pt.created_datetime AS linked_at
         FROM problem_tickets pt
         JOIN tickets t ON t.id = pt.ticket_id
         LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
         WHERE pt.problem_id = ? AND t.deleted_datetime IS NULL
         ORDER BY t.created_datetime DESC"
    );
    $inc->execute([$params[0]]);
    $problem['linked_tickets'] = array_map(function ($t) {
        return [
            'id'            => (int)$t['id'],
            'ticket_number' => $t['ticket_number'],
            'subject'       => $t['subject'],
            'status'        => $t['status'],
            'linked_at'     => apiIsoDate($t['linked_at']),
        ];
    }, $inc->fetchAll(PDO::FETCH_ASSOC));

    // Linked changes via the shared change_relations table; the change tables
    // may be absent on a minimal install, so degrade to an empty list.
    $problem['linked_changes'] = [];
    try {
        $ch = $conn->prepare(
            "SELECT c.id, c.title, cs.name AS status, cr.relation_type
             FROM change_relations cr
             JOIN changes c ON c.id = cr.change_id
             LEFT JOIN change_statuses cs ON cs.id = c.status_id
             WHERE cr.related_type = 'problem' AND cr.related_id = ?
             ORDER BY c.created_datetime DESC"
        );
        $ch->execute([$params[0]]);
        $problem['linked_changes'] = array_map(function ($c) {
            return [
                'id'            => (int)$c['id'],
                'title'         => $c['title'],
                'status'        => $c['status'],
                'relation_type' => $c['relation_type'],
            ];
        }, $ch->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { /* change module absent */ }

    apiRespond($problem);
}

// ---------------------------------------------------------------------------
// POST /problems
// ---------------------------------------------------------------------------
function apiProblemsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $title = trim((string)($body['title'] ?? ''));
    if ($title === '') {
        apiError(422, 'missing_field', "'title' is required.");
    }
    $actorId = (int)$apiKey['analyst_id'];

    // Company: explicit + in scope, else the key's default company.
    if (isset($body['company_id']) && $body['company_id'] !== '' && $body['company_id'] !== null) {
        $tenantId = (int)$body['company_id'];
        if (!getTenantById($conn, $tenantId)) {
            apiError(422, 'invalid_field', "Unknown company id: {$tenantId}");
        }
        if (!apiKeyCanAccessTenant($conn, $apiKey, $tenantId)) {
            apiError(403, 'forbidden', 'This API key is not scoped to that company.');
        }
    } else {
        $tenantId = apiKeyDefaultTenantId($conn, $apiKey);
    }

    // Status: explicit, else the module's default status (same as the UI).
    $statusRes = apiResolveProblemStatus($conn, $body);
    if ($statusRes === null) {
        $row = $conn->query(
            "SELECT id, name, is_closed FROM problem_statuses WHERE is_default = 1 ORDER BY display_order LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $statusRes = $row ? [(int)$row['id'], $row['name'], (int)$row['is_closed']] : [null, null, 0];
    }
    $priorityRes = apiResolveProblemPriority($conn, $body) ?? [null, null];

    $analystId = null;
    if (isset($body['assigned_analyst_id']) && $body['assigned_analyst_id'] !== '') {
        $analystId = (int)$body['assigned_analyst_id'];
        apiResolveAnalyst($conn, $analystId);
    }

    $description  = trim((string)($body['description'] ?? '')) ?: null;
    $rootCause    = trim((string)($body['root_cause'] ?? '')) ?: null;
    $workaround   = trim((string)($body['workaround'] ?? '')) ?: null;
    $isKnownError = !empty($body['is_known_error']) ? 1 : 0;

    $ins = $conn->prepare(
        "INSERT INTO problems (
            tenant_id, title, description, status_id, priority_id, assigned_analyst_id,
            root_cause, workaround, is_known_error, created_by_id,
            created_datetime, updated_datetime
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $ins->execute([
        $tenantId, $title, $description, $statusRes[0], $priorityRes[0], $analystId,
        $rootCause, $workaround, $isKnownError, $actorId,
    ]);
    $problemId = (int)$conn->lastInsertId();

    // PRB-##### stamped from the new id, exactly like save.php.
    $conn->prepare("UPDATE problems SET problem_number = ? WHERE id = ?")
         ->execute(['PRB-' . str_pad((string)$problemId, 5, '0', STR_PAD_LEFT), $problemId]);

    apiProblemAuditWrite($conn, $problemId, $actorId, 'created', null, null, null);

    apiRespond(apiSerializeProblem(apiLoadProblem($conn, $apiKey, $problemId)), 201);
}

// ---------------------------------------------------------------------------
// PATCH /problems/{id}
// ---------------------------------------------------------------------------
function apiProblemsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $problemId = $params[0];
    $current = apiLoadProblem($conn, $apiKey, $problemId);
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }
    $actorId = (int)$apiKey['analyst_id'];

    $updates = [];
    $args    = [];
    $audits  = [];   // [field_key, old, new] — the UI's exact audit field keys

    if (array_key_exists('title', $body)) {
        $title = trim((string)$body['title']);
        if ($title === '') {
            apiError(422, 'invalid_field', "'title' cannot be empty.");
        }
        if ($title !== $current['title']) {
            $updates[] = 'title = ?';
            $args[]    = $title;
            $audits[]  = ['title', $current['title'], $title];
        }
    }
    if (array_key_exists('description', $body)) {
        $description = trim((string)$body['description']) ?: null;
        if ($description !== $current['description']) {
            $updates[] = 'description = ?';
            $args[]    = $description;
            $audits[]  = ['description', $current['description'], $description];
        }
    }

    // Status — closed_datetime transitions mirror save.php.
    $oldIsClosed = (int)($current['status_is_closed'] ?? 0);
    $statusRes = apiResolveProblemStatus($conn, $body);
    if ($statusRes !== null && $statusRes[0] !== (int)$current['status_id']) {
        [$newStatusId, $newStatusName, $newIsClosed] = $statusRes;
        $updates[] = 'status_id = ?';
        $args[]    = $newStatusId;
        if ($newIsClosed && !$oldIsClosed) $updates[] = 'closed_datetime = UTC_TIMESTAMP()';
        if (!$newIsClosed && $oldIsClosed) $updates[] = 'closed_datetime = NULL';
        $audits[] = ['status', $current['status_name'], $newStatusName];
    }

    $priorityRes = apiResolveProblemPriority($conn, $body);
    if ($priorityRes !== null) {
        [$newPriorityId, $newPriorityName] = $priorityRes;
        if ($newPriorityId !== ($current['priority_id'] !== null ? (int)$current['priority_id'] : null)) {
            $updates[] = 'priority_id = ?';
            $args[]    = $newPriorityId;
            $audits[]  = ['priority', $current['priority_name'], $newPriorityName];
        }
    }

    if (array_key_exists('assigned_analyst_id', $body)) {
        $newAnalystId = ($body['assigned_analyst_id'] === '' || $body['assigned_analyst_id'] === null)
            ? null : (int)$body['assigned_analyst_id'];
        $newAnalystName = $newAnalystId !== null ? apiResolveAnalyst($conn, $newAnalystId) : null;
        $oldAnalystId = $current['assigned_analyst_id'] !== null ? (int)$current['assigned_analyst_id'] : null;
        if ($newAnalystId !== $oldAnalystId) {
            $updates[] = 'assigned_analyst_id = ?';
            $args[]    = $newAnalystId;
            $audits[]  = ['assigned_to', $current['analyst_name'], $newAnalystName];
        }
    }

    foreach (['root_cause', 'workaround'] as $field) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        $newVal = trim((string)$body[$field]) ?: null;
        if ($newVal !== $current[$field]) {
            $updates[] = "$field = ?";
            $args[]    = $newVal;
            $audits[]  = [$field, $current[$field], $newVal];
        }
    }

    if (array_key_exists('is_known_error', $body)) {
        $newKE = !empty($body['is_known_error']) ? 1 : 0;
        if ($newKE !== (int)$current['is_known_error']) {
            $updates[] = 'is_known_error = ?';
            $args[]    = $newKE;
            // The UI audits this as Yes/No.
            $audits[]  = ['known_error', ((int)$current['is_known_error']) ? 'Yes' : 'No', $newKE ? 'Yes' : 'No'];
        }
    }

    if (!$updates) {
        apiRespond(apiSerializeProblem($current)); // idempotent PATCH
    }

    $updates[] = 'updated_datetime = UTC_TIMESTAMP()';
    $args[]    = $problemId;
    $conn->prepare('UPDATE problems SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

    foreach ($audits as [$field, $old, $new]) {
        apiProblemAuditWrite($conn, $problemId, $actorId, 'modified', $field, $old, $new);
    }

    apiRespond(apiSerializeProblem(apiLoadProblem($conn, $apiKey, $problemId)));
}

// ---------------------------------------------------------------------------
// DELETE /problems/{id} — permanent, mirrors delete.php (cascades children,
// tidies change_relations which has no FK back to problems)
// ---------------------------------------------------------------------------
function apiProblemsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadProblem($conn, $apiKey, $params[0]);
    try {
        $conn->prepare("DELETE FROM change_relations WHERE related_type = 'problem' AND related_id = ?")
             ->execute([$params[0]]);
    } catch (Exception $e) { /* change module absent */ }
    $conn->prepare("DELETE FROM problems WHERE id = ?")->execute([$params[0]]);
    apiRespond(['id' => $params[0], 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// Notes — append-only journal
// ---------------------------------------------------------------------------
function apiProblemNotesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadProblem($conn, $apiKey, $params[0]);
    $stmt = $conn->prepare(
        "SELECT n.id, n.note, n.created_datetime, n.analyst_id, a.full_name AS analyst_name
         FROM problem_notes n LEFT JOIN analysts a ON a.id = n.analyst_id
         WHERE n.problem_id = ? ORDER BY n.created_datetime DESC, n.id DESC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($n) {
        return [
            'id'         => (int)$n['id'],
            'note'       => $n['note'],
            'analyst'    => $n['analyst_id'] === null ? null : ['id' => (int)$n['analyst_id'], 'name' => $n['analyst_name']],
            'created_at' => apiIsoDate($n['created_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function apiProblemNotesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadProblem($conn, $apiKey, $params[0]);
    $note = trim((string)($body['note'] ?? ''));
    if ($note === '') {
        apiError(422, 'missing_field', "'note' is required.");
    }
    $conn->prepare("INSERT INTO problem_notes (problem_id, analyst_id, note, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$params[0], (int)$apiKey['analyst_id'], $note]);
    apiProblemTouch($conn, $params[0]);
    apiRespond([
        'id'         => (int)$conn->lastInsertId(),
        'problem_id' => $params[0],
        'note'       => $note,
    ], 201);
}

// ---------------------------------------------------------------------------
// GET /problems/{id}/audit
// ---------------------------------------------------------------------------
function apiProblemAuditList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadProblem($conn, $apiKey, $params[0]);
    $stmt = $conn->prepare(
        "SELECT au.id, au.action_type, au.field_name, au.old_value, au.new_value, au.created_datetime,
                au.analyst_id, a.full_name AS analyst_name
         FROM problem_audit au LEFT JOIN analysts a ON a.id = au.analyst_id
         WHERE au.problem_id = ? ORDER BY au.created_datetime DESC, au.id DESC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($e) {
        return [
            'id'         => (int)$e['id'],
            'action'     => $e['action_type'],
            'field'      => $e['field_name'],
            'old_value'  => $e['old_value'],
            'new_value'  => $e['new_value'],
            'analyst'    => $e['analyst_id'] === null ? null : ['id' => (int)$e['analyst_id'], 'name' => $e['analyst_name']],
            'created_at' => apiIsoDate($e['created_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// ---------------------------------------------------------------------------
// Incident linking — POST /problems/{id}/tickets, DELETE .../{ticket_id}
// ---------------------------------------------------------------------------
function apiProblemTicketsLink(PDO $conn, array $apiKey, array $params, array $body): void {
    $problemId = $params[0];
    $problem = apiLoadProblem($conn, $apiKey, $problemId);
    $actorId = (int)$apiKey['analyst_id'];

    $ticketId = isset($body['ticket_id']) ? (int)$body['ticket_id'] : 0;
    if ($ticketId <= 0) {
        apiError(422, 'missing_field', "'ticket_id' is required.");
    }
    // The key must be able to see the ticket too (its company scope).
    if (!apiKeyCanAccessTicket($conn, $apiKey, $ticketId)) {
        apiError(404, 'not_found', 'Ticket not found.');
    }
    $tStmt = $conn->prepare("SELECT ticket_number, tenant_id FROM tickets WHERE id = ? AND deleted_datetime IS NULL");
    $tStmt->execute([$ticketId]);
    $ticket = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        apiError(404, 'not_found', 'Ticket not found.');
    }

    // Same-company rule, exactly as link_ticket.php: NULL normalises to Default.
    if (isMultiTenant($conn)) {
        $default = getDefaultTenantId($conn);
        $pTid = $problem['tenant_id'] === null ? $default : (int)$problem['tenant_id'];
        $tTid = $ticket['tenant_id'] === null ? $default : (int)$ticket['tenant_id'];
        if ($pTid !== $tTid) {
            apiError(422, 'invalid_field', 'That incident belongs to a different company than this problem.');
        }
    }

    $dup = $conn->prepare("SELECT id FROM problem_tickets WHERE problem_id = ? AND ticket_id = ?");
    $dup->execute([$problemId, $ticketId]);
    if ($dup->fetchColumn()) {
        apiError(409, 'conflict', 'This incident is already linked to this problem.');
    }

    $conn->prepare("INSERT INTO problem_tickets (problem_id, ticket_id, created_by_id, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$problemId, $ticketId, $actorId]);
    apiProblemAuditWrite($conn, $problemId, $actorId, 'modified', 'linked_incident', null, $ticket['ticket_number']);
    apiProblemTouch($conn, $problemId);

    apiRespond(['problem_id' => $problemId, 'ticket_id' => $ticketId, 'ticket_number' => $ticket['ticket_number'], 'linked' => true], 201);
}

function apiProblemTicketsUnlink(PDO $conn, array $apiKey, array $params, array $body): void {
    [$problemId, $ticketId] = $params;
    apiLoadProblem($conn, $apiKey, $problemId);
    $stmt = $conn->prepare("DELETE FROM problem_tickets WHERE problem_id = ? AND ticket_id = ?");
    $stmt->execute([$problemId, $ticketId]);
    if ($stmt->rowCount() === 0) {
        apiError(404, 'not_found', 'Link not found.');
    }
    // No audit row on unlink — parity with the UI.
    apiProblemTouch($conn, $problemId);
    apiRespond(['problem_id' => $problemId, 'ticket_id' => $ticketId, 'unlinked' => true]);
}

// ---------------------------------------------------------------------------
// Change linking — POST /problems/{id}/changes, DELETE .../{change_id}
// (shared change_relations table, related_type='problem', relation 'fixes')
// ---------------------------------------------------------------------------
function apiProblemChangesLink(PDO $conn, array $apiKey, array $params, array $body): void {
    $problemId = $params[0];
    apiLoadProblem($conn, $apiKey, $problemId);
    $actorId = (int)$apiKey['analyst_id'];

    $changeId = isset($body['change_id']) ? (int)$body['change_id'] : 0;
    if ($changeId <= 0) {
        apiError(422, 'missing_field', "'change_id' is required.");
    }
    try {
        $cStmt = $conn->prepare("SELECT id, title FROM changes WHERE id = ?");
        $cStmt->execute([$changeId]);
        $change = $cStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        apiError(422, 'invalid_field', 'Change Management is not available on this install.');
    }
    if (!$change) {
        apiError(422, 'invalid_field', "Unknown change id: {$changeId}");
    }

    $dup = $conn->prepare("SELECT id FROM change_relations WHERE change_id = ? AND related_type = 'problem' AND related_id = ?");
    $dup->execute([$changeId, $problemId]);
    if ($dup->fetchColumn()) {
        apiError(409, 'conflict', 'This change is already linked to this problem.');
    }

    $conn->prepare(
        "INSERT INTO change_relations (change_id, related_type, related_id, relation_type, created_by_id, created_datetime)
         VALUES (?, 'problem', ?, 'fixes', ?, UTC_TIMESTAMP())"
    )->execute([$changeId, $problemId, $actorId]);
    apiProblemAuditWrite($conn, $problemId, $actorId, 'modified', 'linked_change', null, 'Change #' . $changeId);
    apiProblemTouch($conn, $problemId);

    apiRespond(['problem_id' => $problemId, 'change_id' => $changeId, 'title' => $change['title'], 'linked' => true], 201);
}

function apiProblemChangesUnlink(PDO $conn, array $apiKey, array $params, array $body): void {
    [$problemId, $changeId] = $params;
    apiLoadProblem($conn, $apiKey, $problemId);
    try {
        $stmt = $conn->prepare("DELETE FROM change_relations WHERE change_id = ? AND related_type = 'problem' AND related_id = ?");
        $stmt->execute([$changeId, $problemId]);
    } catch (Exception $e) {
        apiError(422, 'invalid_field', 'Change Management is not available on this install.');
    }
    if ($stmt->rowCount() === 0) {
        apiError(404, 'not_found', 'Link not found.');
    }
    // No audit row on unlink — parity with the UI.
    apiProblemTouch($conn, $problemId);
    apiRespond(['problem_id' => $problemId, 'change_id' => $changeId, 'unlinked' => true]);
}

// ---------------------------------------------------------------------------
// Reference lookups
// ---------------------------------------------------------------------------
function apiProblemStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, name, is_closed, is_default, colour, is_active FROM problem_statuses ORDER BY display_order, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($s) {
        return [
            'id'         => (int)$s['id'],
            'name'       => $s['name'],
            'is_closed'  => (bool)$s['is_closed'],
            'is_default' => (bool)$s['is_default'],
            'colour'     => $s['colour'],
            'is_active'  => (bool)$s['is_active'],
        ];
    }, $rows));
}

function apiProblemPrioritiesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, name, is_default, colour, is_active FROM problem_priorities ORDER BY display_order, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($p) {
        return [
            'id'         => (int)$p['id'],
            'name'       => $p['name'],
            'is_default' => (bool)$p['is_default'],
            'colour'     => $p['colour'],
            'is_active'  => (bool)$p['is_active'],
        ];
    }, $rows));
}
