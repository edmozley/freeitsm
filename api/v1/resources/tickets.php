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
 *
 * The WRITES (create / update / delete / restore / notes / time entries) are
 * delegated to TicketsService — the same rules the UI's api/tickets/*.php
 * endpoints now call, gated by ctx->companyScope. The API passes writeAudit=true
 * so its server-side audit trail is byte-identical; the UI update endpoints pass
 * writeAudit=false (they audit client-side). The reads, serialisers, list filter
 * and SLA/thread views below are API-only and stay here. (TicketsService
 * requires the workflow engine for the ticket.* events.)
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/tickets.php';

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * The SELECT for tickets, with status, priority, type, origin, department, analyst,
 * requester and company names.
 */
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

/**
 * Shapes a ticket row for the API - number, subject, status (with is_closed), priority,
 * type, origin, department, analyst, requester and the rest of the record.
 *
 * @param array $r  the tickets row as apiTicketSelect() loads it, with the lookup, analyst, requester and company names
 */
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
        // Reported exactly as stored — NULL means nobody set one, and the API says
        // so rather than inventing the hour the UI would have drawn. A client that
        // wants to render a block applies its own default knowingly; one that just
        // wants to know what was scheduled gets the truth.
        'work_end_at'   => apiIsoDate($r['work_end_datetime'] ?? null),
        'work_all_day'  => (bool)($r['work_all_day'] ?? 0),
        // Read-only (#933). Reported only while it is still in the future, which is
        // the same definition of "asleep" the inbox uses — an elapsed snooze is a
        // ticket that has already come back, and reporting it would say otherwise.
        'snoozed_until' => (!empty($r['snoozed_until']) && strtotime($r['snoozed_until'] . ' UTC') > time())
                            ? apiIsoDate($r['snoozed_until']) : null,
        // 'normal' or 'confidential' (discussion #62). 'normal' before Database
        // Verification has added the column, which is what every ticket then is.
        'sensitivity'   => $r['sensitivity'] ?? 'normal',
        'workspace'     => (bool)($r['is_workspace'] ?? false),
        'deleted_at'    => apiIsoDate($r['deleted_datetime']),
    ];
}

/**
 * A ticket's custom fields (#179) for the API: {field_key: value} for every
 * field the ticket has, null when unanswered. Typed - a number is a number, a
 * yes/no is true/false, a date is "2026-11-03", a date and time is
 * "2026-11-03T09:00:00", a time is "09:00".
 *
 * @param array $row  the ticket row (id, tenant_id, ticket_type_id)
 */
function apiTicketCustomFields(PDO $conn, array $row): object {
    $defs = TicketFieldsService::fieldsForTicket($conn, $row);
    $values = TicketFieldsService::values($conn, (int)$row['id'], $defs);
    $out = [];
    foreach ($defs as $key => $d) {
        $v = $values[$key] ?? null;
        if ($v !== null && $d['type'] === 'date') {
            $mode = $d['config']['date_mode'] ?? 'date';
            $v = $mode === 'time' ? substr($v, 11, 5) : ($mode === 'datetime' ? str_replace(' ', 'T', substr($v, 0, 19)) : substr($v, 0, 10));
        }
        $out[$key] = $v;
    }
    return (object)$out;
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

// The actor-id helper, ticket audit-write, ticket-number generator, and the
// status/priority/lookup resolvers now live in TicketsService
// (includes/services/tickets.php) — the single home shared with the UI's
// api/tickets/*.php endpoints.

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
    $ticket['custom_fields'] = apiTicketCustomFields($conn, $row);

    apiRespond($ticket);
}

// ---------------------------------------------------------------------------
// POST /tickets
// ---------------------------------------------------------------------------
function apiTicketsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    // Subject + requester_email (business rules) then company (transport/auth)
    // are validated here to preserve the original error ordering; the shared
    // insert / initial-email / audit / workflow lives in TicketsService.
    $subject = trim((string)($body['subject'] ?? ''));
    if ($subject === '') {
        apiError(422, 'missing_field', "'subject' is required.");
    }
    $requesterEmail = strtolower(trim((string)($body['requester_email'] ?? '')));
    if ($requesterEmail === '' || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
        apiError(422, 'missing_field', "'requester_email' is required and must be a valid email address.");
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

    // 🔴 The requester gets the same company check as the ticket. The service
    // takes user_id on trust ("the caller must have authorised this id"), and
    // this adapter never did - so a key limited to company A could name any
    // requester by id, and the 201 handed back their name and email; looping
    // over ids listed every company's contacts. And an address nobody has yet
    // created the requester in the company its domain maps to, unchecked - the
    // hole POST /users had. Reported privately, October 2026.
    if (isset($body['user_id']) && (int)$body['user_id'] > 0) {
        if (!apiKeyCanAccessTenantRow($conn, $apiKey, 'users', (int)$body['user_id'])) {
            apiError(422, 'invalid_field', 'That requester no longer exists.');   // same words as a missing id
        }
    } else {
        $known = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $known->execute([$requesterEmail]);
        if ($known->fetchColumn() === false) {
            $newTenant = resolveTenantForNewUser($conn, $requesterEmail);
            if ($newTenant !== null && !apiKeyCanAccessTenant($conn, $apiKey, (int)$newTenant)) {
                apiError(403, 'forbidden', "This address belongs to a company this API key cannot reach, so the requester cannot be created.");
            }
        }
    }

    // Custom fields (#179). Always passed, so the fields required when a ticket
    // is logged are enforced for the API exactly as for the New ticket form - an
    // integration that leaves one out gets a 422 naming it, not a ticket missing it.
    $body['custom_fields'] = $body['custom_fields'] ?? [];
    try {
        $ticketId = TicketsService::createTicket($conn, ActorContext::fromApiKey($apiKey), $tenantId, $body, null,
            'Created via API (key: ' . $apiKey['name'] . ')');
    } catch (ServiceError $e) { apiFailFromService($e); }
    $row = apiLoadTicket($conn, $apiKey, $ticketId);
    apiRespond(apiSerializeTicket($row) + ['custom_fields' => apiTicketCustomFields($conn, $row)], 201);
}

// ---------------------------------------------------------------------------
// PATCH /tickets/{id}
// ---------------------------------------------------------------------------
function apiTicketsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        TicketsService::updateTicket($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body, true);
    } catch (ServiceError $e) { apiFailFromService($e); }
    $row = apiLoadTicket($conn, $apiKey, $params[0]);
    apiRespond(apiSerializeTicket($row) + ['custom_fields' => apiTicketCustomFields($conn, $row)]);
}

// ---------------------------------------------------------------------------
// DELETE /tickets/{id}  +  POST /tickets/{id}/restore   (soft delete / trash)
// ---------------------------------------------------------------------------
function apiTicketsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        TicketsService::deleteTicket($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], false);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(['id' => $params[0], 'deleted' => true]);
}

/** POST /tickets/{id}/restore - restores a deleted ticket through TicketsService and returns it. */
function apiTicketsRestore(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        TicketsService::restoreTicket($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], false);
    } catch (ServiceError $e) { apiFailFromService($e); }
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

/**
 * POST /tickets/{id}/notes - adds a note to the ticket through TicketsService. Notes are
 * internal unless is_internal is false.
 */
function apiTicketNotesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $noteId = TicketsService::createNote($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond([
        'id'          => $noteId,
        'ticket_id'   => $params[0],
        'text'        => trim((string)($body['text'] ?? '')),
        'is_internal' => array_key_exists('is_internal', $body) ? (bool)$body['is_internal'] : true,
    ], 201);
}

// ---------------------------------------------------------------------------
// Watchers (GH #277) - analysts told about a ticket as if it were theirs
// ---------------------------------------------------------------------------

/** One watcher as the API returns it. */
function apiTicketWatcherShape(array $w): array {
    return [
        'analyst'    => ['id' => $w['analyst_id'], 'name' => $w['name']],
        'added_by'   => $w['added_by'] !== null ? ['id' => $w['added_by'], 'name' => $w['added_by_name']] : null,
        'added_at'   => apiIsoDate($w['added_at']),
    ];
}

/** GET /tickets/{id}/watchers */
function apiTicketWatchersList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, (int)$params[0]);
    require_once __DIR__ . '/../../../includes/services/ticket_watchers.php';
    apiRespond(array_map('apiTicketWatcherShape', TicketWatchers::list($conn, (int)$params[0])));
}

/**
 * POST /tickets/{id}/watchers - { "analyst_id": 7 }; omitted = the key's own analyst.
 * 201 when added, 200 when they already watched. The analyst must be able to see the ticket.
 */
function apiTicketWatchersAdd(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, (int)$params[0]);
    require_once __DIR__ . '/../../../includes/services/ticket_watchers.php';
    $who = isset($body['analyst_id']) ? (int)$body['analyst_id'] : (int)$apiKey['analyst_id'];
    try {
        // The key's ticket_watchers.create permission is the write access here.
        $r = TicketWatchers::add($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $who, true);
    } catch (ServiceError $e) { apiFailFromService($e); }
    $mine = array_values(array_filter(TicketWatchers::list($conn, (int)$params[0]), fn($w) => $w['analyst_id'] === $who));
    apiRespond($mine ? apiTicketWatcherShape($mine[0]) : null, $r['added'] ? 201 : 200);
}

/** DELETE /tickets/{id}/watchers/{analyst_id} - 404 when they were not watching. */
function apiTicketWatchersRemove(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, (int)$params[0]);
    require_once __DIR__ . '/../../../includes/services/ticket_watchers.php';
    try {
        $r = TicketWatchers::remove($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], (int)$params[1], true);
    } catch (ServiceError $e) { apiFailFromService($e); }
    if (!$r['removed']) apiError(404, 'not_found', 'That analyst is not watching this ticket.');
    apiRespond(['ticket_id' => (int)$params[0], 'analyst_id' => (int)$params[1], 'removed' => true]);
}

// ---------------------------------------------------------------------------
// Forms a ticket needs (GH #272) - the ticket twin of /tasks/{id}/forms
// ---------------------------------------------------------------------------

/** GET /tickets/{id}/forms - each form the ticket needs, its state, and its answers where the key's analyst may read them. */
function apiTicketFormsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, (int)$params[0]);
    require_once __DIR__ . '/../../../includes/services/task_forms.php';
    if (!TicketForms::columnsReady($conn)) apiRespond([]);
    $actor = (int)($apiKey['analyst_id'] ?? 0);
    apiRespond(array_map(function ($f) {
        $out = [
            'id'            => $f['id'],
            'form_id'       => $f['form_id'],
            'title'         => $f['title'],
            'required'      => $f['is_required'],
            'requirement'   => $f['is_required'] ? $f['requirement_mode'] : 'optional',
            'submission_id' => $f['submission_id'],
            'submitted_by'  => $f['submitted_by'],
            'submitted_at'  => $f['submitted_utc'] ? apiIsoDate($f['submitted_utc']) : null,
            'has_draft'     => $f['has_draft'],
            'revision'      => $f['revision'],
        ];
        // The one answers rule, as in the app: absent rather than empty when not allowed.
        if (array_key_exists('answers', $f)) {
            $out['answers'] = array_map(fn($a) => ['field_id' => $a['field_id'], 'question' => $a['label'], 'answer' => $a['value'],
                                                   'exception' => !empty($a['exception'])], $f['answers']);
        }
        return $out;
    }, TicketForms::forTicket($conn, (int)$params[0], $actor, true)));
}

/**
 * POST /tickets/{id}/forms/{ticket_form_id}/submissions - submit the form for this ticket.
 * Body: { data: {field_id: value}, client_reference?, revision? } - the same contract as the task one.
 */
function apiTicketFormSubmit(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, (int)$params[0]);
    require_once __DIR__ . '/../../../includes/services/task_forms.php';
    try {
        if (!TicketForms::enabled($conn) || !TicketForms::columnsReady($conn)) {
            throw new ServiceError('validation', 'disabled', 'Forms on tickets are switched off (Tickets -> Settings -> Checklists).');
        }
        $row = TicketForms::row($conn, (int)$params[1]);
        if ((int)$row['ticket_id'] !== (int)$params[0]) throw new ServiceError('not_found', 'not_found', 'That form is not on this ticket.');
        $revision = isset($body['revision']) ? (int)$body['revision'] : (int)$row['revision'];
        $res = TicketForms::submit($conn, ActorContext::fromApiKey($apiKey), (int)$row['id'], $revision,
                                   (string)($body['client_reference'] ?? ''), (array)($body['data'] ?? []));
        apiRespond(['ticket_id' => (int)$params[0], 'ticket_form_id' => (int)$row['id'], 'submission_id' => $res['submission_id'],
                    'revision' => $res['revision'], 'replayed' => $res['replayed']], $res['replayed'] ? 200 : 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
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
/**
 * Refuse if this ticket's company has switched time tracking off FOR THE API
 * (discussion #72).
 *
 * ⚠️ Separate from the UI switch on purpose. Hiding the panel is about interface
 * clutter; silently emptying an endpoint breaks integrations belonging to people
 * who changed nothing — so an install can tidy its screens without breaking a
 * billing export, or switch both off, and the two are its own decision.
 *
 * A refusal rather than an empty list: an empty list says "this ticket has no
 * time", which is a different and untrue statement.
 */
function apiRequireTimeTracking(PDO $conn, int $ticketId): void {
    require_once dirname(__DIR__, 3) . '/includes/tenant_settings.php';
    if (!timeTrackingApiOn($conn, ticketTenantId($conn, $ticketId))) {
        apiError(404, 'not_found', 'Time tracking is not enabled for this ticket\'s company.');
    }
}

/**
 * GET /tickets/{id}/time-entries - the ticket's active time entries, oldest first.
 * Refused when time tracking is off for the ticket.
 */
function apiTicketTimeEntriesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTicket($conn, $apiKey, $params[0]);
    apiRequireTimeTracking($conn, (int) $params[0]);
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

/**
 * POST /tickets/{id}/time-entries - logs time on the ticket through TicketsService,
 * returning the minutes and time as stored.
 */
function apiTicketTimeEntriesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRequireTimeTracking($conn, (int) $params[0]);
    try {
        $entryId = TicketsService::createTimeEntry($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    // Re-read the stored minutes + datetime so the response is byte-identical.
    $e = $conn->prepare("SELECT time_spent_minutes, entry_datetime FROM ticket_time_entries WHERE id = ?");
    $e->execute([$entryId]);
    $row = $e->fetch(PDO::FETCH_ASSOC) ?: [];
    apiRespond([
        'id'        => $entryId,
        'ticket_id' => $params[0],
        'minutes'   => (int)($row['time_spent_minutes'] ?? 0),
        'entry_at'  => apiIsoDate($row['entry_datetime'] ?? null),
    ], 201);
}

/** DELETE /tickets/{id}/time-entries/{entryId} - deletes a time entry through TicketsService. */
function apiTicketTimeEntriesDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRequireTimeTracking($conn, (int) $params[0]);
    try {
        TicketsService::deleteTimeEntry($conn, ActorContext::fromApiKey($apiKey), (int)$params[1], (int)$params[0], false);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(['id' => $params[1], 'deleted' => true]);
}
