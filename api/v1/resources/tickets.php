<?php
/**
 * FreeITSM REST API v1 — tickets resource.
 *
 * Mirrors the behaviour of the internal ticket endpoints so a ticket touched
 * via the API is indistinguishable from one touched in the UI:
 *   - create mirrors api/tickets/create_ticket.php (initial email row, audit
 *     row, workflow ticket.created)
 *   - update mirrors api/tickets/assign_ticket.php (closed_datetime handling,
 *     owner sync, template emails, CSAT auto-trigger, workflow events) and
 *     additionally writes the ticket_audit rows the UI writes from JS —
 *     status audit rows use the status NAME, which the SLA engine parses.
 *   - delete/restore mirror the trash endpoints (soft delete).
 *
 * All writes are attributed to the analyst the API key acts as.
 */

require_once dirname(__DIR__, 3) . '/workflow/includes/engine.php';

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function apiTicketSelect(): string {
    return "SELECT t.*,
                   ts.name  AS status_name,  ts.is_closed AS status_is_closed,
                   tp.name  AS priority_name,
                   tt.name  AS type_name,
                   tor.name AS origin_name,
                   d.name   AS department_name,
                   a.full_name AS analyst_name,
                   u.email  AS requester_email, u.display_name AS requester_name,
                   tn.name  AS company_name
            FROM tickets t
            LEFT JOIN ticket_statuses   ts  ON ts.id  = t.status_id
            LEFT JOIN ticket_priorities tp  ON tp.id  = t.priority_id
            LEFT JOIN ticket_types      tt  ON tt.id  = t.ticket_type_id
            LEFT JOIN ticket_origins    tor ON tor.id = t.origin_id
            LEFT JOIN departments       d   ON d.id   = t.department_id
            LEFT JOIN analysts          a   ON a.id   = t.assigned_analyst_id
            LEFT JOIN users             u   ON u.id   = t.user_id
            LEFT JOIN tenants           tn  ON tn.id  = t.tenant_id";
}

function apiSerializeTicket(array $r): array {
    $rel = function ($id, $name, array $extra = []) {
        return $id === null ? null : array_merge(['id' => (int)$id, 'name' => $name], $extra);
    };
    return [
        'id'            => (int)$r['id'],
        'ticket_number' => $r['ticket_number'],
        'subject'       => $r['subject'],
        'status'        => $rel($r['status_id'], $r['status_name'], ['is_closed' => (bool)($r['status_is_closed'] ?? false)]),
        'priority'      => $rel($r['priority_id'], $r['priority_name']),
        'ticket_type'   => $rel($r['ticket_type_id'], $r['type_name']),
        'origin'        => $rel($r['origin_id'], $r['origin_name']),
        'department'    => $rel($r['department_id'], $r['department_name']),
        'assigned_analyst' => $rel($r['assigned_analyst_id'], $r['analyst_name']),
        'requester'     => $r['user_id'] === null ? null : [
            'id'    => (int)$r['user_id'],
            'email' => $r['requester_email'],
            'name'  => $r['requester_name'],
        ],
        'company'       => $rel($r['tenant_id'], $r['company_name']),
        'first_time_fix'        => $r['first_time_fix'] === null ? null : (bool)$r['first_time_fix'],
        'it_training_provided'  => $r['it_training_provided'] === null ? null : (bool)$r['it_training_provided'],
        'created_at'    => apiIsoDate($r['created_datetime']),
        'updated_at'    => apiIsoDate($r['updated_datetime']),
        'closed_at'     => apiIsoDate($r['closed_datetime']),
        'work_start_at' => apiIsoDate($r['work_start_datetime']),
        'deleted_at'    => apiIsoDate($r['deleted_datetime']),
    ];
}

/** Load one ticket (with joins) enforcing the key's company scope; 404s if not visible. */
function apiLoadTicket(PDO $conn, array $apiKey, int $ticketId): array {
    if (!apiKeyCanAccessTicket($conn, $apiKey, $ticketId)) {
        apiError(404, 'not_found', 'Ticket not found.');
    }
    $stmt = $conn->prepare(apiTicketSelect() . " WHERE t.id = ?");
    $stmt->execute([$ticketId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Ticket not found.');
    }
    return $row;
}

/** The analyst id every API write is attributed to. */
function apiActorId(array $apiKey): int {
    return (int)$apiKey['analyst_id'];
}

function apiAuditWrite(PDO $conn, int $ticketId, int $analystId, string $field, ?string $old, ?string $new): void {
    $stmt = $conn->prepare(
        "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    );
    $stmt->execute([$ticketId, $analystId, $field, $old, $new]);
}

/** Unique random ticket number, same format as the UI (XXX-###-#####). */
function apiGenerateTicketNumber(PDO $conn): string {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $letters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));
        $ticketNumber = $letters . '-' . rand(100, 999) . '-' . str_pad((string)rand(0, 99999), 5, '0', STR_PAD_LEFT);
        $check = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number = ?");
        $check->execute([$ticketNumber]);
        if (!(int)$check->fetchColumn()) {
            return $ticketNumber;
        }
    }
    throw new Exception('Failed to generate unique ticket number');
}

/** Resolve a status by name or id from body keys status / status_id. Returns [id, name, is_closed] or null. */
function apiResolveStatus(PDO $conn, array $body): ?array {
    if (isset($body['status_id']) && $body['status_id'] !== '' && $body['status_id'] !== null) {
        $stmt = $conn->prepare("SELECT id, name, is_closed FROM ticket_statuses WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$body['status_id']]);
    } elseif (isset($body['status']) && trim((string)$body['status']) !== '') {
        $stmt = $conn->prepare("SELECT id, name, is_closed FROM ticket_statuses WHERE name = ? LIMIT 1");
        $stmt->execute([trim((string)$body['status'])]);
    } else {
        return null;
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(422, 'invalid_field', 'Unknown status: ' . ($body['status'] ?? $body['status_id']));
    }
    return [(int)$row['id'], $row['name'], (int)$row['is_closed']];
}

/** Resolve a priority by name or id (priority / priority_id). Empty string/null id = clear. */
function apiResolvePriority(PDO $conn, array $body): ?array {
    if (array_key_exists('priority_id', $body)) {
        if ($body['priority_id'] === '' || $body['priority_id'] === null) {
            return [null, null]; // explicit clear
        }
        $stmt = $conn->prepare("SELECT id, name FROM ticket_priorities WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$body['priority_id']]);
    } elseif (isset($body['priority']) && trim((string)$body['priority']) !== '') {
        $stmt = $conn->prepare("SELECT id, name FROM ticket_priorities WHERE name = ? LIMIT 1");
        $stmt->execute([trim((string)$body['priority'])]);
    } else {
        return null;
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(422, 'invalid_field', 'Unknown priority: ' . ($body['priority'] ?? $body['priority_id']));
    }
    return [(int)$row['id'], $row['name']];
}

/** Validate a simple lookup id (returns the row name), 422 on unknown id. */
function apiValidateLookupId(PDO $conn, string $table, $id, string $label): ?string {
    if ($id === null || $id === '') {
        return null;
    }
    $stmt = $conn->prepare("SELECT name FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$id]);
    $name = $stmt->fetchColumn();
    if ($name === false) {
        apiError(422, 'invalid_field', "Unknown {$label} id: {$id}");
    }
    return $name;
}

// ---------------------------------------------------------------------------
// GET /tickets
// ---------------------------------------------------------------------------
function apiTicketsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where  = ['1=1'];
    $args   = [];

    // Trash: excluded unless explicitly requested.
    if (($_GET['deleted'] ?? '') === 'true') {
        $where[] = 't.deleted_datetime IS NOT NULL';
    } else {
        $where[] = 't.deleted_datetime IS NULL';
    }

    // state=open|closed|all (default all)
    $state = strtolower(trim($_GET['state'] ?? 'all'));
    if ($state === 'open')   $where[] = "(ts.is_closed IS NULL OR ts.is_closed = 0)";
    if ($state === 'closed') $where[] = "ts.is_closed = 1";

    $idFilters = [
        'status_id'           => 't.status_id',
        'priority_id'         => 't.priority_id',
        'ticket_type_id'      => 't.ticket_type_id',
        'origin_id'           => 't.origin_id',
        'department_id'       => 't.department_id',
        'assigned_analyst_id' => 't.assigned_analyst_id',
        'user_id'             => 't.user_id',
    ];
    foreach ($idFilters as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = (int)$_GET[$param];
        }
    }
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $where[] = 'ts.name = ?';
        $args[]  = trim($_GET['status']);
    }
    if (isset($_GET['priority']) && $_GET['priority'] !== '') {
        $where[] = 'tp.name = ?';
        $args[]  = trim($_GET['priority']);
    }
    if (isset($_GET['requester_email']) && $_GET['requester_email'] !== '') {
        $where[] = 'u.email = ?';
        $args[]  = strtolower(trim($_GET['requester_email']));
    }
    if (isset($_GET['unassigned']) && $_GET['unassigned'] === 'true') {
        $where[] = 't.assigned_analyst_id IS NULL';
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = '(t.subject LIKE ? OR t.ticket_number LIKE ?)';
        $like = '%' . trim($_GET['q']) . '%';
        $args[] = $like;
        $args[] = $like;
    }
    foreach ([
        'created_since'  => ['t.created_datetime', '>='],
        'created_before' => ['t.created_datetime', '<'],
        'updated_since'  => ['t.updated_datetime', '>='],
        'closed_since'   => ['t.closed_datetime',  '>='],
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
            $where[] = '(t.tenant_id = ? OR t.tenant_id IS NULL)';
        } else {
            $where[] = 't.tenant_id = ?';
        }
        $args[] = $cid;
    }

    // Key company scope (mirrors ticketTenantFilter semantics).
    [$scopeSql, $scopeArgs] = apiKeyTicketFilter($conn, $apiKey);
    $whereSql = implode(' AND ', $where) . $scopeSql;
    $args = array_merge($args, $scopeArgs);

    // Sorting: sort=field or sort=-field (descending).
    $sortable = [
        'id' => 't.id', 'created_at' => 't.created_datetime', 'updated_at' => 't.updated_datetime',
        'closed_at' => 't.closed_datetime', 'subject' => 't.subject', 'ticket_number' => 't.ticket_number',
        'priority' => 't.priority_id', 'status' => 't.status_id',
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
        "SELECT COUNT(*) FROM tickets t
         LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
         LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
         LEFT JOIN users u ON u.id = t.user_id
         WHERE $whereSql"
    );
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(apiTicketSelect() . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $stmt->execute($args);
    $tickets = array_map('apiSerializeTicket', $stmt->fetchAll(PDO::FETCH_ASSOC));

    apiRespond($tickets, 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// ---------------------------------------------------------------------------
// GET /tickets/{id}
// ---------------------------------------------------------------------------
function apiTicketsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $row = apiLoadTicket($conn, $apiKey, $params[0]);
    $ticket = apiSerializeTicket($row);

    // The request body lives on the initial email row (tickets has no body column).
    $descStmt = $conn->prepare(
        "SELECT body_content FROM emails WHERE ticket_id = ? AND is_initial = 1 ORDER BY id ASC LIMIT 1"
    );
    $descStmt->execute([$params[0]]);
    $desc = $descStmt->fetchColumn();
    $ticket['description_html'] = $desc === false ? null : $desc;

    apiRespond($ticket);
}

// ---------------------------------------------------------------------------
// POST /tickets
// ---------------------------------------------------------------------------
function apiTicketsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $subject        = trim((string)($body['subject'] ?? ''));
    $description    = trim((string)($body['description'] ?? ''));
    $requesterEmail = strtolower(trim((string)($body['requester_email'] ?? '')));
    $requesterName  = trim((string)($body['requester_name'] ?? ''));

    if ($subject === '') {
        apiError(422, 'missing_field', "'subject' is required.");
    }
    if ($requesterEmail === '' || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
        apiError(422, 'missing_field', "'requester_email' is required and must be a valid email address.");
    }
    if ($requesterName === '') {
        $requesterName = ucfirst(explode('@', $requesterEmail)[0]);
    }

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

    // Optional lookups (validated so a bad id is a 422, not an FK explosion).
    $statusRes = apiResolveStatus($conn, $body) ?? (function () use ($conn) {
        $row = $conn->query("SELECT id, name, is_closed FROM ticket_statuses WHERE name = 'Open' LIMIT 1")->fetch(PDO::FETCH_ASSOC)
            ?: $conn->query("SELECT id, name, is_closed FROM ticket_statuses WHERE is_default = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row ? [(int)$row['id'], $row['name'], (int)$row['is_closed']] : [null, null, 0];
    })();
    $priorityRes = apiResolvePriority($conn, $body);
    if ($priorityRes === null) {
        $row = $conn->query("SELECT id, name FROM ticket_priorities WHERE name = 'Normal' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $priorityRes = $row ? [(int)$row['id'], $row['name']] : [null, null];
    }

    $departmentId = isset($body['department_id']) && $body['department_id'] !== '' ? (int)$body['department_id'] : null;
    $typeId       = isset($body['ticket_type_id']) && $body['ticket_type_id'] !== '' ? (int)$body['ticket_type_id'] : null;
    $originId     = isset($body['origin_id']) && $body['origin_id'] !== '' ? (int)$body['origin_id'] : null;
    $analystId    = isset($body['assigned_analyst_id']) && $body['assigned_analyst_id'] !== '' ? (int)$body['assigned_analyst_id'] : null;
    $mailboxId    = isset($body['mailbox_id']) && $body['mailbox_id'] !== '' ? (int)$body['mailbox_id'] : null;

    apiValidateLookupId($conn, 'departments', $departmentId, 'department');
    apiValidateLookupId($conn, 'ticket_types', $typeId, 'ticket type');
    apiValidateLookupId($conn, 'ticket_origins', $originId, 'origin');
    if ($analystId !== null) {
        $aStmt = $conn->prepare("SELECT id FROM analysts WHERE id = ? AND is_active = 1");
        $aStmt->execute([$analystId]);
        if (!$aStmt->fetchColumn()) {
            apiError(422, 'invalid_field', "Unknown or inactive analyst id: {$analystId}");
        }
    }
    if ($mailboxId !== null) {
        // Same guard as the manual-create endpoint: the mailbox must be active
        // and (multi-tenant) pinned to this company or shared intake.
        $mbStmt = $conn->prepare("SELECT tenant_id FROM target_mailboxes WHERE id = ? AND is_active = 1");
        $mbStmt->execute([$mailboxId]);
        $mb = $mbStmt->fetch(PDO::FETCH_ASSOC);
        $mbOk = (bool)$mb;
        if ($mb && isMultiTenant($conn)) {
            $mbOk = ($mb['tenant_id'] === null) || ((int)$mb['tenant_id'] === $tenantId);
        }
        if (!$mbOk) {
            apiError(422, 'invalid_field', 'The selected mailbox is not available for this company.');
        }
    }

    try {
        $conn->beginTransaction();

        // Find-or-create the requester.
        $uStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $uStmt->execute([$requesterEmail]);
        $userId = $uStmt->fetchColumn();
        if ($userId === false) {
            $cStmt = $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, ?, UTC_TIMESTAMP())");
            $cStmt->execute([$requesterEmail, $requesterName]);
            $userId = $conn->lastInsertId();
        }

        $ticketNumber = apiGenerateTicketNumber($conn);

        $ins = $conn->prepare(
            "INSERT INTO tickets (
                tenant_id, ticket_number, subject, status_id, priority_id, department_id,
                ticket_type_id, origin_id, assigned_analyst_id, owner_id, user_id,
                created_datetime, updated_datetime
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $ins->execute([
            $tenantId, $ticketNumber, $subject, $statusRes[0], $priorityRes[0], $departmentId,
            $typeId, $originId, $analystId, $analystId, $userId,
        ]);
        $ticketId = (int)$conn->lastInsertId();

        // Initial "email" row so the ticket has a body and shows in the inbox.
        $bodyHtml    = nl2br(htmlspecialchars($description));
        $bodyPreview = substr(strip_tags($description), 0, 200);
        $eIns = $conn->prepare(
            "INSERT INTO emails (
                mailbox_id, subject, from_address, from_name, to_recipients, received_datetime,
                body_preview, body_content, body_type, has_attachments, importance,
                is_read, ticket_id, is_initial, direction
            ) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, 'html', 0, 'normal', 1, ?, 1, 'Manual')"
        );
        $eIns->execute([$mailboxId, $subject, $requesterEmail, $requesterName, $requesterEmail, $bodyPreview, $bodyHtml, $ticketId]);

        apiAuditWrite($conn, $ticketId, apiActorId($apiKey), 'Ticket Created', null,
            'Created via API (key: ' . $apiKey['name'] . ')');

        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    // Workflow: same dispatch as the manual-create endpoint (engine swallows
    // its own errors; wrapped so it can never break the response).
    try {
        WorkflowEngine::dispatch('ticket.created', [
            'ticket' => [
                'id'                  => $ticketId,
                'subject'             => $subject,
                'priority_id'         => $priorityRes[0],
                'status_id'           => $statusRes[0],
                'department_id'       => $departmentId,
                'type_id'             => $typeId,
                'assigned_analyst_id' => $analystId,
                'created_by'          => apiActorId($apiKey),
                'requester_email'     => $requesterEmail,
            ],
        ]);
    } catch (Exception $wfEx) {
        error_log('Workflow dispatch error in API v1 ticket create: ' . $wfEx->getMessage());
    }

    $row = apiLoadTicket($conn, $apiKey, $ticketId);
    apiRespond(apiSerializeTicket($row), 201);
}

// ---------------------------------------------------------------------------
// PATCH /tickets/{id}
// ---------------------------------------------------------------------------
function apiTicketsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $ticketId = $params[0];
    $current = apiLoadTicket($conn, $apiKey, $ticketId);
    if ($current['deleted_datetime'] !== null) {
        apiError(409, 'conflict', 'Ticket is in the trash. Restore it before updating.');
    }
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }
    $actorId = apiActorId($apiKey);

    $updates = [];
    $args    = [];
    $audits  = [];   // [field_name, old, new] written after a successful UPDATE

    // Subject
    if (array_key_exists('subject', $body)) {
        $subject = trim((string)$body['subject']);
        if ($subject === '') {
            apiError(422, 'invalid_field', "'subject' cannot be empty.");
        }
        if ($subject !== $current['subject']) {
            $updates[] = 'subject = ?';
            $args[]    = $subject;
            $audits[]  = ['Subject', $current['subject'], $subject];
        }
    }

    // Status (by name or id) — closed_datetime handling mirrors assign_ticket.php.
    $oldIsClosed = (int)($current['status_is_closed'] ?? 0);
    $newStatusId = null;
    $newIsClosed = null;
    $statusRes = apiResolveStatus($conn, $body);
    if ($statusRes !== null) {
        [$newStatusId, $newStatusName, $newIsClosed] = $statusRes;
        if ($newStatusId !== (int)$current['status_id']) {
            $updates[] = 'status_id = ?';
            $args[]    = $newStatusId;
            if ($newIsClosed && !$oldIsClosed)  $updates[] = 'closed_datetime = UTC_TIMESTAMP()';
            if (!$newIsClosed && $oldIsClosed)  $updates[] = 'closed_datetime = NULL';
            // Status audit rows carry the status NAME — the SLA engine parses them.
            $audits[] = ['Status', $current['status_name'], $newStatusName];
        } else {
            $newStatusId = null; // no actual change
        }
    }

    // Priority (by name or id; explicit null/'' clears)
    $priorityChanged = false;
    $priorityRes = apiResolvePriority($conn, $body);
    if ($priorityRes !== null) {
        [$newPriorityId, $newPriorityName] = $priorityRes;
        if ($newPriorityId !== ($current['priority_id'] !== null ? (int)$current['priority_id'] : null)) {
            $updates[] = 'priority_id = ?';
            $args[]    = $newPriorityId;
            $audits[]  = ['Priority', $current['priority_name'], $newPriorityName];
            $priorityChanged = true;
        }
    }

    // Simple lookup fields (nullable, id-based).
    foreach ([
        'department_id'  => ['departments',    'department',  'Department',  'department_name'],
        'ticket_type_id' => ['ticket_types',   'ticket type', 'Ticket Type', 'type_name'],
        'origin_id'      => ['ticket_origins', 'origin',      'Origin',      'origin_name'],
    ] as $field => [$table, $label, $auditField, $currentNameKey]) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        $newId = ($body[$field] === '' || $body[$field] === null) ? null : (int)$body[$field];
        $newName = $newId !== null ? apiValidateLookupId($conn, $table, $newId, $label) : null;
        if ($newId !== ($current[$field] !== null ? (int)$current[$field] : null)) {
            $updates[] = "$field = ?";
            $args[]    = $newId;
            $audits[]  = [$auditField, $current[$currentNameKey], $newName];
        }
    }

    // Assignment — sets both assigned_analyst_id and owner_id (kept in sync, like the UI).
    $oldAnalystId = $current['assigned_analyst_id'] !== null ? (int)$current['assigned_analyst_id'] : null;
    $newAnalystId = null;
    $analystSent  = array_key_exists('assigned_analyst_id', $body);
    if ($analystSent) {
        $newAnalystId = ($body['assigned_analyst_id'] === '' || $body['assigned_analyst_id'] === null)
            ? null : (int)$body['assigned_analyst_id'];
        $newAnalystName = null;
        if ($newAnalystId !== null) {
            $aStmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ? AND is_active = 1");
            $aStmt->execute([$newAnalystId]);
            $newAnalystName = $aStmt->fetchColumn();
            if ($newAnalystName === false) {
                apiError(422, 'invalid_field', "Unknown or inactive analyst id: {$newAnalystId}");
            }
        }
        if ($newAnalystId !== $oldAnalystId) {
            $updates[] = 'assigned_analyst_id = ?';
            $args[]    = $newAnalystId;
            $updates[] = 'owner_id = ?';
            $args[]    = $newAnalystId;
            $audits[]  = ['Owner', $current['analyst_name'], $newAnalystName];
        } else {
            $analystSent = false; // no actual change
        }
    }

    // Booleans
    foreach (['first_time_fix' => 'First Time Fix', 'it_training_provided' => 'IT Training Provided'] as $field => $auditField) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        $newVal = ($body[$field] === null || $body[$field] === '') ? null : ((int)(bool)$body[$field]);
        $oldVal = $current[$field] !== null ? (int)$current[$field] : null;
        if ($newVal !== $oldVal) {
            $updates[] = "$field = ?";
            $args[]    = $newVal;
            $audits[]  = [$auditField, $oldVal === null ? null : ($oldVal ? 'Yes' : 'No'), $newVal === null ? null : ($newVal ? 'Yes' : 'No')];
        }
    }

    // Scheduled work start (ISO 8601; null clears).
    if (array_key_exists('work_start_at', $body)) {
        $newWork = ($body['work_start_at'] === null || $body['work_start_at'] === '')
            ? null : apiParseDate((string)$body['work_start_at'], 'work_start_at');
        if ($newWork !== $current['work_start_datetime']) {
            $updates[] = 'work_start_datetime = ?';
            $args[]    = $newWork;
        }
    }

    // Move to another company — the key must be scoped to BOTH sides (the
    // API twin of move_ticket_to_company.php's dual access check).
    if (array_key_exists('company_id', $body) && $body['company_id'] !== '' && $body['company_id'] !== null) {
        $newTenantId = (int)$body['company_id'];
        $curTenantId = $current['tenant_id'] !== null ? (int)$current['tenant_id'] : getDefaultTenantId($conn);
        if ($newTenantId !== $curTenantId) {
            $target = getTenantById($conn, $newTenantId);
            if (!$target) {
                apiError(422, 'invalid_field', "Unknown company id: {$newTenantId}");
            }
            if (!apiKeyCanAccessTenant($conn, $apiKey, $newTenantId)) {
                apiError(403, 'forbidden', 'This API key is not scoped to the target company.');
            }
            $updates[] = 'tenant_id = ?';
            $args[]    = $newTenantId;
            $audits[]  = ['Company', $current['company_name'], $target['name']];
        }
    }

    if (!$updates) {
        // Nothing actually changed — return the current state (idempotent PATCH).
        apiRespond(apiSerializeTicket($current));
    }

    $updates[] = 'updated_datetime = UTC_TIMESTAMP()';
    $args[]    = $ticketId;
    $stmt = $conn->prepare('UPDATE tickets SET ' . implode(', ', $updates) . ' WHERE id = ?');
    $stmt->execute($args);

    foreach ($audits as [$field, $old, $new]) {
        apiAuditWrite($conn, $ticketId, $actorId, $field, $old, $new);
    }

    // Template emails + CSAT — same non-blocking behaviour as assign_ticket.php.
    try {
        require_once dirname(__DIR__, 3) . '/includes/template_email.php';
        if ($analystSent && $newAnalystId !== null) {
            sendTemplateEmail($conn, $ticketId, 'ticket_assigned');
        }
        if ($newIsClosed && !$oldIsClosed) {
            sendTemplateEmail($conn, $ticketId, 'ticket_closed');
            require_once dirname(__DIR__, 3) . '/includes/csat.php';
            try {
                if (csatGetSetting($conn, 'csat_mode', 'off') === 'auto') {
                    sendCsatSurvey($conn, $ticketId, $actorId);
                }
            } catch (Exception $csEx) {
                error_log('CSAT auto-trigger failed for ticket ' . $ticketId . ': ' . $csEx->getMessage());
            }
        }
    } catch (Exception $tplEx) {
        error_log('Template email error in API v1 ticket update: ' . $tplEx->getMessage());
    }

    // Workflow dispatches — canonical post-update payload, like assign_ticket.php.
    try {
        $rb = $conn->prepare(
            "SELECT t.id, t.subject, t.priority_id, t.status_id, t.department_id,
                    t.ticket_type_id AS type_id, t.assigned_analyst_id, t.owner_id,
                    t.origin_id, t.user_id AS created_by, u.email AS requester_email
             FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?"
        );
        $rb->execute([$ticketId]);
        $r = $rb->fetch(PDO::FETCH_ASSOC) ?: [];
        $payload = [
            'id'                  => $ticketId,
            'subject'             => $r['subject'] ?? null,
            'priority_id'         => isset($r['priority_id']) ? (int)$r['priority_id'] : null,
            'status_id'           => isset($r['status_id']) ? (int)$r['status_id'] : null,
            'department_id'       => isset($r['department_id']) ? (int)$r['department_id'] : null,
            'type_id'             => isset($r['type_id']) ? (int)$r['type_id'] : null,
            'assigned_analyst_id' => isset($r['assigned_analyst_id']) ? (int)$r['assigned_analyst_id'] : null,
            'owner_id'            => isset($r['owner_id']) ? (int)$r['owner_id'] : null,
            'origin_id'           => isset($r['origin_id']) ? (int)$r['origin_id'] : null,
            'created_by'          => isset($r['created_by']) ? (int)$r['created_by'] : null,
            'requester_email'     => $r['requester_email'] ?? null,
        ];
        $oldStatusId = $current['status_id'] !== null ? (int)$current['status_id'] : null;
        if ($newStatusId !== null && $newStatusId !== $oldStatusId) {
            WorkflowEngine::dispatch('ticket.status_changed', [
                'ticket' => $payload, 'old_status_id' => $oldStatusId, 'new_status_id' => $newStatusId,
            ]);
        }
        if ($priorityChanged) {
            WorkflowEngine::dispatch('ticket.priority_changed', [
                'ticket'          => $payload,
                'old_priority_id' => $current['priority_id'] !== null ? (int)$current['priority_id'] : null,
                'new_priority_id' => $payload['priority_id'],
            ]);
        }
        if ($analystSent && $newAnalystId !== null && $newAnalystId !== $oldAnalystId) {
            WorkflowEngine::dispatch('ticket.assigned', [
                'ticket' => $payload, 'analyst_id' => $newAnalystId, 'team_id' => null,
            ]);
        }
    } catch (Exception $wfEx) {
        error_log('Workflow dispatch error in API v1 ticket update: ' . $wfEx->getMessage());
    }

    apiRespond(apiSerializeTicket(apiLoadTicket($conn, $apiKey, $ticketId)));
}

// ---------------------------------------------------------------------------
// DELETE /tickets/{id}  +  POST /tickets/{id}/restore   (soft delete / trash)
// ---------------------------------------------------------------------------
function apiTicketsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    $ticket = apiLoadTicket($conn, $apiKey, $params[0]);
    if ($ticket['deleted_datetime'] !== null) {
        apiError(409, 'conflict', 'Ticket is already in the trash.');
    }
    $stmt = $conn->prepare("UPDATE tickets SET deleted_datetime = UTC_TIMESTAMP(), deleted_by = ? WHERE id = ?");
    $stmt->execute([apiActorId($apiKey), $params[0]]);
    apiRespond(['id' => $params[0], 'deleted' => true]);
}

function apiTicketsRestore(PDO $conn, array $apiKey, array $params, array $body): void {
    $ticket = apiLoadTicket($conn, $apiKey, $params[0]);
    if ($ticket['deleted_datetime'] === null) {
        apiError(409, 'conflict', 'Ticket is not in the trash.');
    }
    $stmt = $conn->prepare("UPDATE tickets SET deleted_datetime = NULL, deleted_by = NULL WHERE id = ?");
    $stmt->execute([$params[0]]);
    apiRespond(apiSerializeTicket(apiLoadTicket($conn, $apiKey, $params[0])));
}

// ---------------------------------------------------------------------------
// Notes
// ---------------------------------------------------------------------------
function apiTicketNotesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, $params[0]);
    $stmt = $conn->prepare(
        "SELECT n.id, n.note_text, n.is_internal, n.created_datetime, n.analyst_id, a.full_name AS analyst_name
         FROM ticket_notes n LEFT JOIN analysts a ON a.id = n.analyst_id
         WHERE n.ticket_id = ? ORDER BY n.created_datetime ASC, n.id ASC"
    );
    $stmt->execute([$params[0]]);
    $notes = array_map(function ($n) {
        return [
            'id'          => (int)$n['id'],
            'text'        => $n['note_text'],
            'is_internal' => (bool)$n['is_internal'],
            'analyst'     => $n['analyst_id'] === null ? null : ['id' => (int)$n['analyst_id'], 'name' => $n['analyst_name']],
            'created_at'  => apiIsoDate($n['created_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    apiRespond($notes);
}

function apiTicketNotesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, $params[0]);
    $text = trim((string)($body['text'] ?? ''));
    if ($text === '') {
        apiError(422, 'missing_field', "'text' is required.");
    }
    $isInternal = array_key_exists('is_internal', $body) ? (bool)$body['is_internal'] : true;
    $stmt = $conn->prepare("INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal) VALUES (?, ?, ?, ?)");
    $stmt->execute([$params[0], apiActorId($apiKey), $text, $isInternal ? 1 : 0]);
    $noteId = (int)$conn->lastInsertId();
    $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$params[0]]);
    apiRespond([
        'id'          => $noteId,
        'ticket_id'   => $params[0],
        'text'        => $text,
        'is_internal' => $isInternal,
    ], 201);
}

// ---------------------------------------------------------------------------
// Thread (emails / channel messages)
// ---------------------------------------------------------------------------
function apiTicketThreadList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, $params[0]);
    $stmt = $conn->prepare(
        "SELECT id, direction, is_initial, channel, subject, from_address, from_name,
                to_recipients, cc_recipients, body_preview, body_content, received_datetime
         FROM emails WHERE ticket_id = ? ORDER BY received_datetime ASC, id ASC"
    );
    $stmt->execute([$params[0]]);
    $messages = array_map(function ($m) {
        return [
            'id'          => (int)$m['id'],
            'direction'   => $m['direction'],
            'is_initial'  => (bool)$m['is_initial'],
            'channel'     => $m['channel'] ?: 'email',
            'subject'     => $m['subject'],
            'from'        => ['address' => $m['from_address'], 'name' => $m['from_name']],
            'to'          => $m['to_recipients'],
            'cc'          => $m['cc_recipients'],
            'body_preview' => $m['body_preview'],
            'body_html'   => $m['body_content'],
            'received_at' => apiIsoDate($m['received_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    apiRespond($messages);
}

// ---------------------------------------------------------------------------
// Audit log
// ---------------------------------------------------------------------------
function apiTicketAuditList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, $params[0]);
    $stmt = $conn->prepare(
        "SELECT au.id, au.field_name, au.old_value, au.new_value, au.created_datetime,
                au.analyst_id, a.full_name AS analyst_name
         FROM ticket_audit au LEFT JOIN analysts a ON a.id = au.analyst_id
         WHERE au.ticket_id = ? ORDER BY au.created_datetime ASC, au.id ASC"
    );
    $stmt->execute([$params[0]]);
    $entries = array_map(function ($e) {
        return [
            'id'         => (int)$e['id'],
            'field'      => $e['field_name'],
            'old_value'  => $e['old_value'],
            'new_value'  => $e['new_value'],
            'analyst'    => $e['analyst_id'] === null ? null : ['id' => (int)$e['analyst_id'], 'name' => $e['analyst_name']],
            'created_at' => apiIsoDate($e['created_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    apiRespond($entries);
}

// ---------------------------------------------------------------------------
// SLA (compute-on-read via includes/sla.php)
// ---------------------------------------------------------------------------
function apiTicketSlaGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, $params[0]);
    require_once dirname(__DIR__, 3) . '/includes/sla.php';
    $state = sla_get_state($conn, $params[0]);
    apiRespond($state);
}

// ---------------------------------------------------------------------------
// Time entries
// ---------------------------------------------------------------------------
function apiTicketTimeEntriesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, $params[0]);
    $stmt = $conn->prepare(
        "SELECT te.id, te.time_spent_minutes, te.entry_datetime, te.notes,
                te.analyst_id, a.full_name AS analyst_name
         FROM ticket_time_entries te LEFT JOIN analysts a ON a.id = te.analyst_id
         WHERE te.ticket_id = ? AND te.is_active = 1
         ORDER BY te.entry_datetime ASC, te.id ASC"
    );
    $stmt->execute([$params[0]]);
    $entries = array_map(function ($t) {
        return [
            'id'         => (int)$t['id'],
            'minutes'    => (int)$t['time_spent_minutes'],
            'notes'      => $t['notes'],
            'analyst'    => $t['analyst_id'] === null ? null : ['id' => (int)$t['analyst_id'], 'name' => $t['analyst_name']],
            'entry_at'   => apiIsoDate($t['entry_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    apiRespond($entries);
}

function apiTicketTimeEntriesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, $params[0]);
    $minutes = isset($body['minutes']) ? (int)$body['minutes'] : 0;
    if ($minutes <= 0) {
        apiError(422, 'missing_field', "'minutes' is required and must be a positive integer.");
    }
    $notes = trim((string)($body['notes'] ?? ''));
    $entryAt = isset($body['entry_at']) && $body['entry_at'] !== ''
        ? apiParseDate((string)$body['entry_at'], 'entry_at')
        : gmdate('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        "INSERT INTO ticket_time_entries (ticket_id, analyst_id, notes, time_spent_minutes, entry_datetime)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$params[0], apiActorId($apiKey), $notes !== '' ? $notes : null, $minutes, $entryAt]);
    apiRespond([
        'id'        => (int)$conn->lastInsertId(),
        'ticket_id' => $params[0],
        'minutes'   => $minutes,
        'entry_at'  => apiIsoDate($entryAt),
    ], 201);
}

function apiTicketTimeEntriesDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, $params[0]);
    $stmt = $conn->prepare("SELECT id FROM ticket_time_entries WHERE id = ? AND ticket_id = ? AND is_active = 1");
    $stmt->execute([$params[1], $params[0]]);
    if (!$stmt->fetchColumn()) {
        apiError(404, 'not_found', 'Time entry not found.');
    }
    // Soft delete, matching the UI (get_time_entries filters on is_active = 1).
    $conn->prepare("UPDATE ticket_time_entries SET is_active = 0 WHERE id = ?")->execute([$params[1]]);
    apiRespond(['id' => $params[1], 'deleted' => true]);
}
