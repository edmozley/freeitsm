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
 *
 * The WRITES (create / update / delete / notes / incident + change linking)
 * are delegated to ProblemsService — the same rules the UI's
 * api/problem-management/*.php endpoints now call, gated by ctx->companyScope.
 * The reads, serialisers, list filter, and reference lookups below are API-only
 * and stay here.
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/problems.php';

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

// The problem audit-write, updated_datetime touch, status/priority resolution,
// and analyst validation now live in ProblemsService
// (includes/services/problems.php) — the single home shared with the UI's
// api/problem-management/*.php endpoints.

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
    // Title (business rule) then company (transport/auth) are validated here to
    // preserve the original error ordering; the shared insert/status/PRB/audit
    // lives in ProblemsService. The company is auth-adjacent — resolved per
    // transport (key default / explicit company_id here) and passed in.
    $title = trim((string)($body['title'] ?? ''));
    if ($title === '') {
        apiError(422, 'missing_field', "'title' is required.");
    }
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

    try {
        $problemId = ProblemsService::createProblem($conn, ActorContext::fromApiKey($apiKey), $tenantId, $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(apiSerializeProblem(apiLoadProblem($conn, $apiKey, $problemId)), 201);
}

// ---------------------------------------------------------------------------
// PATCH /problems/{id}
// ---------------------------------------------------------------------------
function apiProblemsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        ProblemsService::updateProblem($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(apiSerializeProblem(apiLoadProblem($conn, $apiKey, $params[0])));
}

// ---------------------------------------------------------------------------
// DELETE /problems/{id} — permanent, mirrors delete.php (cascades children,
// tidies change_relations which has no FK back to problems)
// ---------------------------------------------------------------------------
function apiProblemsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        ProblemsService::deleteProblem($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
    } catch (ServiceError $e) { apiFailFromService($e); }
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
    try {
        $noteId = ProblemsService::addNote($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond([
        'id'         => $noteId,
        'problem_id' => $params[0],
        'note'       => trim((string)($body['note'] ?? '')),
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
    try {
        $res = ProblemsService::linkTicket($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond($res, 201);
}

function apiProblemTicketsUnlink(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        ProblemsService::unlinkTicket($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], (int)$params[1]);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(['problem_id' => $params[0], 'ticket_id' => $params[1], 'unlinked' => true]);
}

// ---------------------------------------------------------------------------
// Change linking — POST /problems/{id}/changes, DELETE .../{change_id}
// (shared change_relations table, related_type='problem', relation 'fixes')
// ---------------------------------------------------------------------------
function apiProblemChangesLink(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = ProblemsService::linkChange($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond($res, 201);
}

function apiProblemChangesUnlink(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        ProblemsService::unlinkChange($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], (int)$params[1]);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(['problem_id' => $params[0], 'change_id' => $params[1], 'unlinked' => true]);
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
