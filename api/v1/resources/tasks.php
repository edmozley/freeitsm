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
 * Tasks have no audit trail in the product — none is invented here.
 * Task tables carry no tenant_id (install-wide), BUT a task can link to a
 * ticket, and tickets ARE company-scoped: the API validates ticket links
 * against the key's company scope and only reveals linked-ticket details the
 * key could read directly — tighter than the UI, which doesn't check.
 */

// Task WRITES (create/update/move/delete/comment) are delegated to TasksService
// (includes/services/tasks.php), which pulls in the workflow engine (for the
// task.completed dispatch) + tenancy helpers (for ticket-link scope). The read
// handlers + serializers stay here.
require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/tasks.php';

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

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
        'assigned_analyst' => $rel($r['assigned_analyst_id'], $r['analyst_name']),
        'assigned_team'    => $rel($r['assigned_team_id'], $r['team_name']),
        'start_date'  => $r['start_date'],
        'due_date'    => $r['due_date'],
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

function apiLoadTask(PDO $conn, int $taskId): array {
    $stmt = $conn->prepare(apiTaskSelect() . " WHERE t.id = ?");
    $stmt->execute([$taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
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
        $where[] = "t.due_date < CURDATE() AND (ts.is_closed IS NULL OR ts.is_closed = 0)";
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
    $whereSql = implode(' AND ', $where);

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
    $r = apiLoadTask($conn, $params[0]);
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
    apiLoadTask($conn, $params[0]);
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

function apiTaskCommentsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $commentId = TasksService::createComment($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], (string)($body['text'] ?? ''));
        apiRespond(['id' => $commentId, 'task_id' => $params[0], 'text' => trim((string)($body['text'] ?? ''))], 201);
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
