<?php
/**
 * FreeITSM REST API v1 — tasks resource (kanban Tasks module).
 *
 * Mirrors the module's internal endpoints so a task touched via the API is
 * indistinguishable from one touched in the UI:
 *   - create mirrors api/tasks/save.php: only title is required, defaults
 *     To Do / Medium (falling back to the is_default rows), board_position
 *     appends to the end of the target status column.
 *   - status changes drive completed_datetime exactly like the UI
 *     (COALESCE-stamped on entering a closed status, cleared on reopening),
 *     and a PATCH that moves an open task into a closed status fires the
 *     task.completed workflow event with save.php's exact payload.
 *   - POST /tasks/{id}/move mirrors reorder.php: status + position change
 *     with the column re-packed — and, like the UI's drag, it does NOT fire
 *     the workflow event (that asymmetry is the product's, preserved for
 *     parity).
 *   - comments are create-only (no edit/delete exists in the product);
 *     DELETE is a hard delete that cascades subtasks/comments/tags.
 *
 * Tasks belong to a company (tenant_id, GH #83) and every read here is scoped to
 * the key's companies - see apiTasksList. A task can also link to a ticket, and
 * the API validates ticket links against the key's company scope and only
 * reveals linked-ticket details the key could read directly.
 *
 * Tasks keep a history (#181: task_events, includes/services/task_history.php),
 * written by TasksService for every write made here; it is shown in the task
 * window's History tab and the evidence report, and is not served by this API.
 * A key whose analyst has Read only access to Tasks is refused every write, by
 * the service (#181 Part G).
 */

// Task WRITES (create/update/move/delete/comment) are delegated to TasksService
// (includes/services/tasks.php), which pulls in the workflow engine (for the
// task.completed dispatch) + tenancy helpers (for ticket-link scope). The read
// handlers + serializers stay here.
require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/tasks.php';
require_once __DIR__ . '/task_related.php';   // GH #328: `related` - counts and links, as on tickets
require_once dirname(__DIR__, 3) . '/includes/timezone.php';   // naive_today_sql() — due_date is a bare date (GH #126)

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/** The SELECT for tasks, with status, priority, analyst, team and creator names. */
function apiTaskSelect(): string {
    return "SELECT t.*,
                   ts.name AS status_name, ts.is_closed AS status_is_closed, ts.colour AS status_colour,
                   tp.name AS priority_name, tp.colour AS priority_colour,
                   a.full_name AS analyst_name,
                   tm.name AS team_name,
                   cb.full_name AS created_by_name
            FROM tasks t
            LEFT JOIN task_statuses   ts ON ts.id = t.status_id
            LEFT JOIN task_priorities tp ON tp.id = t.priority_id
            LEFT JOIN analysts        a  ON a.id  = t.assigned_analyst_id
            LEFT JOIN teams           tm ON tm.id = t.assigned_team_id
            LEFT JOIN analysts        cb ON cb.id = t.created_by_id";
}

/** Contractors (3.3.0): the supplier and the person there, by name - includes/task_contractors.php. */
function apiTaskContractor(PDO $conn, int $taskId): ?array {
    require_once __DIR__ . '/../../../includes/task_contractors.php';
    return tasksContractors($conn, [$taskId])[$taskId] ?? null;
}

/**
 * Shapes a task row for the API, with its tags and subtask progress (total and done)
 * read alongside.
 *
 * @param array $r  the tasks row as apiTaskSelect() loads it, with the lookup and people names
 */
function apiSerializeTask(PDO $conn, array $r): array {
    $rel = function ($id, $name, array $extra = []) {
        return $id === null ? null : array_merge(['id' => (int)$id, 'name' => $name], $extra);
    };

    $tagsStmt = $conn->prepare(
        "SELECT tg.name FROM task_tag_map m JOIN task_tags tg ON tg.id = m.tag_id
         WHERE m.task_id = ? ORDER BY tg.display_order, tg.name"
    );
    $tagsStmt->execute([(int)$r['id']]);

    $subStmt = $conn->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN ts.is_closed = 1 THEN 1 ELSE 0 END) AS done
         FROM tasks s LEFT JOIN task_statuses ts ON ts.id = s.status_id
         WHERE s.parent_task_id = ?"
    );
    $subStmt->execute([(int)$r['id']]);
    $sub = $subStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'done' => 0];

    return [
        'id'          => (int)$r['id'],
        'title'       => $r['title'],
        'description' => $r['description'],
        'status'      => $rel($r['status_id'], $r['status_name'], [
            'is_closed' => (bool)($r['status_is_closed'] ?? false),
            'colour'    => $r['status_colour'] ?? null,
        ]),
        'priority'    => $rel($r['priority_id'], $r['priority_name'], ['colour' => $r['priority_colour'] ?? null]),
        // ⚠️ `assigned_analyst` STILL MEANS ONE PERSON: the owner. Collaborators
        // (GH #89) are a separate field rather than a widening of this one, so a
        // client written before they existed keeps reading exactly what it read
        // before. Shown as "Involved" in the interface; the API says
        // "collaborators" because an API field name is never translated, and the
        // cognate of that word is a slur in nine of the languages we ship.
        'assigned_analyst' => $rel($r['assigned_analyst_id'], $r['analyst_name']),
        'assigned_team'    => $rel($r['assigned_team_id'], $r['team_name']),
        // Contractors (3.3.0): a supplier doing the work, and the person there. The
        // assignee above stays the person here who chases them.
        'contractor'         => ($ctr = apiTaskContractor($conn, (int)$r['id'])) ? ['id' => $ctr['supplier_id'], 'name' => $ctr['supplier_name']] : null,
        'contractor_contact' => $ctr && $ctr['contact_id'] ? ['id' => $ctr['contact_id'], 'name' => $ctr['contact_name']] : null,
        'collaborators'    => array_map(function ($c) {
            return [
                'id'           => $c['analyst_id'],
                'name'         => $c['analyst_name'],
                'is_completed' => $c['is_completed'],
                'completed_at' => $c['completed_datetime'],
            ];
        }, TasksService::collaboratorsFor($conn, (int)$r['id'])),
        'start_date'  => $r['start_date'],
        'due_date'    => $r['due_date'],
        'estimate_hours' => isset($r['estimate_hours']) && $r['estimate_hours'] !== null ? (float)$r['estimate_hours'] : null,
        'parent_task_id' => $r['parent_task_id'] !== null ? (int)$r['parent_task_id'] : null,
        'ticket_id'   => $r['ticket_id'] !== null ? (int)$r['ticket_id'] : null,
        'change_id'   => $r['change_id'] !== null ? (int)$r['change_id'] : null,
        'contract_id' => $r['contract_id'] !== null ? (int)$r['contract_id'] : null,
        'board_position' => (int)$r['board_position'],
        'tags'        => $tagsStmt->fetchAll(PDO::FETCH_COLUMN),
        'subtasks'    => ['total' => (int)$sub['total'], 'done' => (int)($sub['done'] ?? 0)],
        'created_by'  => $rel($r['created_by_id'], $r['created_by_name']),
        'created_at'  => apiIsoDate($r['created_datetime']),
        'updated_at'  => apiIsoDate($r['updated_datetime']),
        'completed_at' => apiIsoDate($r['completed_datetime']),
    ];
}

/**
 * Loads one task row, or answers 404 and stops - also for a task in a company the key
 * cannot reach, when the key is given.
 */
function apiLoadTask(PDO $conn, int $taskId, ?array $apiKey = null): array {
    $stmt = $conn->prepare(apiTaskSelect() . " WHERE t.id = ?");
    $stmt->execute([$taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Task not found.');
    }

    // 🔒 Company scope, at the REST choke point — every by-id read goes through
    // here, including the ones that re-load a task straight after writing it.
    // 404 rather than 403: a key must not learn that another company's task id
    // exists. $apiKey is optional only so the two post-write re-loads, which have
    // already been through the service's own gate, need not pass it twice.
    if ($apiKey !== null && !apiKeyCanAccessTenantRow($conn, $apiKey, 'tasks', $taskId)) {
        apiError(404, 'not_found', 'Task not found.');
    }
    return $row;
}

// ---------------------------------------------------------------------------
// GET /tasks
// ---------------------------------------------------------------------------
function apiTasksList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];

    // Top-level tasks by default (the board's view); parent_task_id=N lists a
    // task's subtasks instead.
    if (isset($_GET['parent_task_id']) && $_GET['parent_task_id'] !== '') {
        $where[] = 't.parent_task_id = ?';
        $args[]  = (int)$_GET['parent_task_id'];
    } else {
        $where[] = 't.parent_task_id IS NULL';
    }

    $state = strtolower(trim($_GET['state'] ?? 'all'));
    if ($state === 'open')   $where[] = "(ts.is_closed IS NULL OR ts.is_closed = 0)";
    if ($state === 'closed') $where[] = "ts.is_closed = 1";

    foreach ([
        'status_id'           => 't.status_id',
        'priority_id'         => 't.priority_id',
        'assigned_analyst_id' => 't.assigned_analyst_id',
        'assigned_team_id'    => 't.assigned_team_id',
        'ticket_id'           => 't.ticket_id',
        'change_id'           => 't.change_id',
        'contract_id'         => 't.contract_id',
    ] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = (int)$_GET[$param];
        }
    }
    foreach (['status' => 'ts.name', 'priority' => 'tp.name'] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = trim($_GET[$param]);
        }
    }
    /**
     * Collaborators (GH #89). TWO new filters, and neither touches
     * `assigned_analyst_id`, which keeps meaning the owner alone:
     *
     *   collaborator_id=N      tasks N is helping with, but does not own
     *   involved_analyst_id=N  owner OR collaborator — what "My tasks" means in
     *                          the interface, available here so a client can ask
     *                          the same question the module asks
     *
     * ⚠️ EXISTS rather than a JOIN. A join to a one-to-many table would return a
     * task once per collaborator, so a task with three of them would be three
     * rows of a page — and `total` would count it three times, giving a paginated
     * client a page count that never reconciles.
     */
    if (isset($_GET['collaborator_id']) && $_GET['collaborator_id'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM task_collaborators tc WHERE tc.task_id = t.id AND tc.analyst_id = ?)';
        $args[]  = (int)$_GET['collaborator_id'];
    }
    if (isset($_GET['involved_analyst_id']) && $_GET['involved_analyst_id'] !== '') {
        $where[] = '(t.assigned_analyst_id = ?
                     OR EXISTS (SELECT 1 FROM task_collaborators tc WHERE tc.task_id = t.id AND tc.analyst_id = ?))';
        $args[]  = (int)$_GET['involved_analyst_id'];
        $args[]  = (int)$_GET['involved_analyst_id'];
    }

    // ⚠️ `unassigned` DELIBERATELY STILL MEANS "no owner", even when the task has
    // collaborators. assigned_analyst_id is nullable, so a task really can end up
    // with two people helping and nobody accountable — and that is exactly what
    // this filter exists to surface. Treating it as assigned because somebody is
    // helping would let work vanish from the queue that exists to catch it.
    if (($_GET['unassigned'] ?? '') === 'true') {
        $where[] = 't.assigned_analyst_id IS NULL';
    }
    if (isset($_GET['tag']) && trim($_GET['tag']) !== '') {
        $where[] = 't.id IN (SELECT m.task_id FROM task_tag_map m JOIN task_tags tg ON tg.id = m.tag_id WHERE tg.name = ?)';
        $args[]  = trim($_GET['tag']);
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = '(t.title LIKE ? OR t.description LIKE ?)';
        $like = '%' . trim($_GET['q']) . '%';
        array_push($args, $like, $like);
    }
    foreach (['due_before' => ['t.due_date', '<='], 'due_after' => ['t.due_date', '>=']] as $param => [$col, $op]) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col $op ?";
            $args[]  = apiParseDateOnly($_GET[$param], $param);
        }
    }
    if (($_GET['overdue'] ?? '') === 'true') {
        // ⚠️ naive_today_sql(), not CURDATE() — due_date is a bare date (GH #126).
        $where[] = "t.due_date < " . naive_today_sql() . " AND (ts.is_closed IS NULL OR ts.is_closed = 0)";
    }

    $sortable = [
        'created_at' => 't.created_datetime', 'updated_at' => 't.updated_datetime',
        'due_date' => 't.due_date', 'board_position' => 't.board_position',
        'title' => 't.title', 'id' => 't.id', 'completed_at' => 't.completed_datetime',
    ];
    $sortParam = trim($_GET['sort'] ?? 'board_position');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC') . ', t.created_datetime DESC';

    [$page, $perPage, $offset] = apiPagination();

    // 🔒 Company scope. Tasks became tenant-scoped alongside GH #83; before that
    // this resource had nothing to filter on, so a key restricted to one company
    // still listed every company's tasks. apiKeyTenantFilter is the generic the
    // tickets/problems resources use — NULL tenant_id belongs to Default.
    //
    // Applied to the COUNT as well as the page: a correct page beside a total that
    // counts the whole install still tells a client how much it cannot see.
    [$scopeSql, $scopeArgs] = apiKeyTenantFilter($conn, $apiKey, 't');
    $whereSql = implode(' AND ', $where) . $scopeSql;
    $args     = array_merge($args, $scopeArgs);

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) FROM tasks t
         LEFT JOIN task_statuses ts ON ts.id = t.status_id
         LEFT JOIN task_priorities tp ON tp.id = t.priority_id
         WHERE $whereSql"
    );
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(apiTaskSelect() . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $stmt->execute($args);
    $tasks = array_map(function ($r) use ($conn) {
        return apiSerializeTask($conn, $r);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    // GH #328: opt-in on the list, as on tickets - a page of 100 would otherwise run 100 sets of counts.
    if (in_array('related', array_map('trim', explode(',', (string)($_GET['include'] ?? ''))), true)) {
        foreach ($tasks as &$tk) $tk['related'] = apiTaskRelated($conn, (int)$tk['id'], $apiKey);
        unset($tk);
    }

    apiRespond($tasks, 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// ---------------------------------------------------------------------------
// GET /tasks/{id}
// ---------------------------------------------------------------------------
function apiTasksGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $r = apiLoadTask($conn, $params[0], $apiKey);
    $task = apiSerializeTask($conn, $r);

    // Parent summary
    $task['parent'] = null;
    if ($r['parent_task_id'] !== null) {
        $p = $conn->prepare("SELECT id, title FROM tasks WHERE id = ?");
        $p->execute([(int)$r['parent_task_id']]);
        $pr = $p->fetch(PDO::FETCH_ASSOC);
        if ($pr) {
            $task['parent'] = ['id' => (int)$pr['id'], 'title' => $pr['title']];
        }
    }

    // Subtask list (ordered like the UI)
    $s = $conn->prepare(
        "SELECT s.id, s.title, s.board_position, ts.name AS status, ts.is_closed
         FROM tasks s LEFT JOIN task_statuses ts ON ts.id = s.status_id
         WHERE s.parent_task_id = ? ORDER BY s.board_position ASC, s.created_datetime ASC"
    );
    $s->execute([$params[0]]);
    $task['subtask_list'] = array_map(function ($x) {
        return [
            'id'             => (int)$x['id'],
            'title'          => $x['title'],
            'status'         => $x['status'],
            'is_closed'      => (bool)$x['is_closed'],
            'board_position' => (int)$x['board_position'],
        ];
    }, $s->fetchAll(PDO::FETCH_ASSOC));

    // Linked ticket/change summaries — ticket details only when the key's
    // company scope could read that ticket directly (tighter than the UI).
    $task['linked_ticket'] = null;
    if ($r['ticket_id'] !== null && apiKeyCanAccessTicket($conn, $apiKey, (int)$r['ticket_id'])) {
        $t = $conn->prepare("SELECT id, ticket_number, subject FROM tickets WHERE id = ?");
        $t->execute([(int)$r['ticket_id']]);
        $tr = $t->fetch(PDO::FETCH_ASSOC);
        if ($tr) {
            $task['linked_ticket'] = ['id' => (int)$tr['id'], 'ticket_number' => $tr['ticket_number'], 'subject' => $tr['subject']];
        }
    }
    $task['linked_change'] = null;
    // Changes are company-scoped too, so gate this the same way as the ticket
    // above — it was reading a change's title by id with no check.
    if ($r['change_id'] !== null
        && apiKeyCanAccessTenantRow($conn, $apiKey, 'changes', (int)$r['change_id'])) {
        try {
            $c = $conn->prepare("SELECT id, title FROM changes WHERE id = ?");
            $c->execute([(int)$r['change_id']]);
            $cr = $c->fetch(PDO::FETCH_ASSOC);
            if ($cr) {
                $task['linked_change'] = ['id' => (int)$cr['id'], 'title' => $cr['title']];
            }
        } catch (Exception $e) { /* change module absent */ }
    }

    // Comments (ascending, like the UI)
    $cm = $conn->prepare(
        "SELECT c.id, c.comment, c.created_datetime, c.analyst_id, a.full_name AS analyst_name
         FROM task_comments c LEFT JOIN analysts a ON a.id = c.analyst_id
         WHERE c.task_id = ? ORDER BY c.created_datetime ASC, c.id ASC"
    );
    $cm->execute([$params[0]]);
    $task['comments'] = array_map(function ($c) {
        return [
            'id'         => (int)$c['id'],
            'text'       => $c['comment'],
            'analyst'    => $c['analyst_id'] === null ? null : ['id' => (int)$c['analyst_id'], 'name' => $c['analyst_name']],
            'created_at' => apiIsoDate($c['created_datetime']),
        ];
    }, $cm->fetchAll(PDO::FETCH_ASSOC));

    $task['related'] = apiTaskRelated($conn, (int)$params[0], $apiKey);   // GH #328
    apiRespond($task);
}

// ---------------------------------------------------------------------------
// POST /tasks
// ---------------------------------------------------------------------------
function apiTasksCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = TasksService::saveTask($conn, ActorContext::fromApiKey($apiKey), $body);
        apiRespond(apiSerializeTask($conn, apiLoadTask($conn, $res['id'])), 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// PATCH /tasks/{id}
// ---------------------------------------------------------------------------
function apiTasksUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = TasksService::saveTask($conn, ActorContext::fromApiKey($apiKey), array_merge($body, ['id' => (int)$params[0]]));
        apiRespond(apiSerializeTask($conn, apiLoadTask($conn, $res['id'])));
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// POST /tasks/{id}/move — kanban move (mirrors reorder.php: NO workflow event)
// ---------------------------------------------------------------------------
function apiTasksMove(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        TasksService::moveTask($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body);
        apiRespond(apiSerializeTask($conn, apiLoadTask($conn, $params[0])));
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// DELETE /tasks/{id} — hard delete. Children removed explicitly, not via FK
// cascade: installs grown via Database Verify were missing the parent and
// comments cascade FKs, which orphaned subtasks/comments (same fix as the
// UI's delete.php).
// ---------------------------------------------------------------------------
function apiTasksDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = TasksService::deleteTask($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
        apiRespond(['id' => $params[0], 'deleted' => true, 'subtasks_deleted' => $res['subtasks_deleted']]);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// Comments — create-only, like the UI
// ---------------------------------------------------------------------------
function apiTaskCommentsList(PDO $conn, array $apiKey, array $params, array $body): void {
    // Child read: comments are addressed by task id, so they need the task's gate.
    apiLoadTask($conn, $params[0], $apiKey);
    $stmt = $conn->prepare(
        "SELECT c.id, c.comment, c.created_datetime, c.analyst_id, a.full_name AS analyst_name
         FROM task_comments c LEFT JOIN analysts a ON a.id = c.analyst_id
         WHERE c.task_id = ? ORDER BY c.created_datetime ASC, c.id ASC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($c) {
        return [
            'id'         => (int)$c['id'],
            'text'       => $c['comment'],
            'analyst'    => $c['analyst_id'] === null ? null : ['id' => (int)$c['analyst_id'], 'name' => $c['analyst_name']],
            'created_at' => apiIsoDate($c['created_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

/** POST /tasks/{id}/comments - adds a comment to the task through TasksService. */
function apiTaskCommentsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $commentId = TasksService::createComment($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], (string)($body['text'] ?? ''));
        apiRespond(['id' => $commentId, 'task_id' => $params[0], 'text' => trim((string)($body['text'] ?? ''))], 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// Forms a task needs (GH #190 phase 5)
// ---------------------------------------------------------------------------

/** GET /tasks/{id}/forms - each form the task needs, its state, and its answers where the key's analyst may read them. */
function apiTaskFormsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTask($conn, $params[0], $apiKey);           // the task's gate, company scope included
    require_once __DIR__ . '/../../../includes/services/task_forms.php';
    if (!TaskForms::columnsReady($conn)) apiRespond([]);
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
    }, TaskForms::forTask($conn, (int)$params[0], $actor, true)));
}

/**
 * POST /tasks/{id}/forms/{task_form_id}/submissions - submit the form for this occurrence.
 *
 * Body: { data: {field_id: value}, client_reference?, revision? }. The same validation as the
 * Forms page; the submission is linked to the task in the same transaction. A retry with the same
 * client_reference returns the submission already made (200, "replayed": true). `revision`, when
 * sent, makes the submit conditional on nobody having saved since (409 otherwise); without it the
 * current revision is used - an integration that is the only writer need not track it.
 */
function apiTaskFormSubmit(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTask($conn, $params[0], $apiKey);
    require_once __DIR__ . '/../../../includes/services/task_forms.php';
    try {
        if (!TaskForms::enabled($conn) || !TaskForms::columnsReady($conn)) {
            throw new ServiceError('validation', 'disabled', 'Forms on tasks are switched off (Tasks -> Settings -> Checklists).');
        }
        $row = TaskForms::row($conn, (int)$params[1]);
        if ((int)$row['task_id'] !== (int)$params[0]) throw new ServiceError('not_found', 'not_found', 'That form is not on this task.');
        $revision = isset($body['revision']) ? (int)$body['revision'] : (int)$row['revision'];
        $res = TaskForms::submit($conn, ActorContext::fromApiKey($apiKey), (int)$row['id'], $revision,
                                 (string)($body['client_reference'] ?? ''), (array)($body['data'] ?? []));
        apiRespond(['task_id' => (int)$params[0], 'task_form_id' => (int)$row['id'], 'submission_id' => $res['submission_id'],
                    'revision' => $res['revision'], 'replayed' => $res['replayed']], $res['replayed'] ? 200 : 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// Reference lookups
// ---------------------------------------------------------------------------
function apiTaskStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, name, is_closed, is_default, colour, is_active, display_order
         FROM task_statuses ORDER BY display_order, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($s) {
        return [
            'id'            => (int)$s['id'],
            'name'          => $s['name'],
            'is_closed'     => (bool)$s['is_closed'],
            'is_default'    => (bool)$s['is_default'],
            'colour'        => $s['colour'],
            'is_active'     => (bool)$s['is_active'],
            'display_order' => (int)$s['display_order'],
        ];
    }, $rows));
}

/** GET /task-priorities - the task priorities, in display order. */
function apiTaskPrioritiesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, name, is_default, colour, is_active FROM task_priorities ORDER BY display_order, name"
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

/** GET /task-tags - the task tags, with how many tasks carry each. */
function apiTaskTagsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT t.id, t.name, t.colour,
                (SELECT COUNT(*) FROM task_tag_map m WHERE m.tag_id = t.id) AS task_count
         FROM task_tags t ORDER BY t.display_order, t.name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($t) {
        return [
            'id'         => (int)$t['id'],
            'name'       => $t['name'],
            'colour'     => $t['colour'],
            'task_count' => (int)$t['task_count'],
        ];
    }, $rows));
}
