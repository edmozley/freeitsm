<?php
/**
 * API: Tasks — List tasks with filters
 * GET — Returns filtered task list with subtask counts
 * Also supports ?analysts=1 and ?teams=1 for dropdown data
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Return analysts for dropdowns
    if (isset($_GET['analysts'])) {
        $stmt = $conn->query("SELECT id, full_name AS name FROM analysts WHERE is_active = 1 ORDER BY full_name");
        echo json_encode(['success' => true, 'analysts' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // Return teams for dropdowns
    if (isset($_GET['teams'])) {
        $stmt = $conn->query("SELECT id, name FROM teams WHERE is_active = 1 ORDER BY display_order, name");
        echo json_encode(['success' => true, 'teams' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $filter = $_GET['filter'] ?? 'my';
    $analystId = $_SESSION['analyst_id'];

    /**
     * Subtasks are normally left out: a subtask is the same kind of record as a
     * top-level task, told apart only by parent_task_id, so without this the
     * board and the table would show every subtask again as a card of its own.
     *
     * ?subtasks= opts in, and the DEFAULT IS UNCHANGED on purpose. This endpoint
     * feeds the board, the table, the timeline and the calendar, and only the
     * calendar asked for subtasks (discussion #90).
     *
     *   (absent)  parent tasks only, as before
     *   both      parent tasks and subtasks together
     *   only      subtasks alone
     */
    $subtaskScope = $_GET['subtasks'] ?? '';
    $where  = [];
    if ($subtaskScope === 'only') {
        $where[] = 't.parent_task_id IS NOT NULL';
    } elseif ($subtaskScope !== 'both') {
        $where[] = 't.parent_task_id IS NULL';
    }
    $params = [];

    /**
     * "My tasks" and the analyst picker both mean OWNER **OR** COLLABORATOR
     * (GH #89). Somebody put on a task needs it to appear where they look for
     * their work, or being added to it achieves nothing.
     *
     * 🔴 THIS IS ONE OF FOUR PLACES THAT CAN HIDE A TASK — the others are
     * api/v1/resources/tasks.php twice, and this file's own analyst branch below.
     * Everything else that touches assignment is a display join, which can leave
     * a name off a card but cannot make the card disappear. A task missing from
     * ONE list is indistinguishable, to the person looking, from the collaborator
     * never having been saved, so all four move together or none of them should.
     *
     * ⚠️ EXISTS, not a JOIN. A join to a one-to-many table returns the task once
     * per collaborator, so a task with three of them would appear three times on
     * the board — and the duplicate rows would then be counted by anything
     * counting rows. EXISTS asks the same question and returns each task once.
     */
    $ownerOrCollaborator = '(t.assigned_analyst_id = ?
                             OR EXISTS (SELECT 1 FROM task_collaborators tc
                                         WHERE tc.task_id = t.id AND tc.analyst_id = ?))';

    if ($filter === 'my') {
        $where[]  = $ownerOrCollaborator;
        $params[] = $analystId;
        $params[] = $analystId;
    } elseif ($filter === 'team' && isset($_GET['team_id'])) {
        $where[] = 't.assigned_team_id = ?';
        $params[] = (int)$_GET['team_id'];
    } elseif ($filter === 'analyst' && isset($_GET['analyst_id'])) {
        // The same question asked about somebody else, so it gets the same answer.
        $where[]  = $ownerOrCollaborator;
        $params[] = (int)$_GET['analyst_id'];
        $params[] = (int)$_GET['analyst_id'];
    } elseif ($filter === 'contract' && isset($_GET['contract_id'])) {
        $where[] = 't.contract_id = ?';
        $params[] = (int)$_GET['contract_id'];
    }
    // filter === 'all' has no extra conditions

    // Exclude cancelled/done if requested — driven by the is_closed flag now
    if (isset($_GET['active_only'])) {
        $where[] = "(ts.is_closed = 0 OR ts.id IS NULL)";
    }

    // ⚠️ $where used to be guaranteed non-empty by the parent_task_id condition
    // that always sat in it. With ?subtasks=both that condition is gone, and
    // filter=all adds none either, so an empty $where is now reachable and would
    // build "WHERE  AND t.tenant_id = ?".
    $whereSql = $where ? implode(' AND ', $where) : '1=1';

    // Company scope (GH #83 groundwork). A task is scoped DATA, so the Default
    // company also owns any task whose tenant_id is NULL — which is every task
    // created before this existed. Returns ['', []] on a single-company install.
    //
    // Only the parent query needs this: the subtask, tag and comment lookups below
    // all key off ids drawn from this result, so they inherit the scope.
    [$tenantSql, $tenantParams] = activeTenantFilter($conn, (int) $analystId, 't');
    $params = array_merge($params, $tenantParams);

    $sql = "SELECT t.id, t.title, t.description,
                   ts.name AS status, ts.is_closed AS status_is_closed, ts.colour AS status_colour,
                   tp.name AS priority, tp.colour AS priority_colour,
                   t.start_date, t.due_date,
                   t.assigned_analyst_id, t.assigned_team_id,
                   t.ticket_id, t.change_id, t.contract_id, t.board_position,
                   -- Needed by any caller that opts into subtasks, to tell the two
                   -- apart and to name the parent a subtask belongs to (#90).
                   t.parent_task_id, pt.title AS parent_title,
                   -- So the calendar can mark an occurrence of a repeating task (#94).
                   t.recurrence_id, t.recurrence_master_id,
                   t.created_by_id, t.created_datetime, t.updated_datetime,
                   t.completed_datetime,
                   a.full_name AS analyst_name,
                   tm.name AS team_name
            FROM tasks t
            LEFT JOIN task_statuses   ts ON ts.id = t.status_id
            LEFT JOIN task_priorities tp ON tp.id = t.priority_id
            LEFT JOIN analysts a ON t.assigned_analyst_id = a.id
            LEFT JOIN teams tm ON t.assigned_team_id = tm.id
            LEFT JOIN tasks pt ON pt.id = t.parent_task_id
            WHERE {$whereSql}{$tenantSql}
            ORDER BY t.board_position ASC, t.created_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get subtask counts for all parent tasks
    $taskIds = array_column($tasks, 'id');
    $subtaskCounts = [];
    if (!empty($taskIds)) {
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $conn->prepare(
            "SELECT parent_task_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN ts.is_closed = 1 THEN 1 ELSE 0 END) AS done
             FROM tasks
             LEFT JOIN task_statuses ts ON ts.id = tasks.status_id
             WHERE parent_task_id IN ({$placeholders})
             GROUP BY parent_task_id"
        );
        $stmt->execute($taskIds);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $subtaskCounts[$row['parent_task_id']] = [
                'total' => (int)$row['total'],
                'done'  => (int)$row['done']
            ];
        }
    }

    // Get tags for all tasks
    $tagsByTask = [];
    if (!empty($taskIds)) {
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $conn->prepare(
            "SELECT m.task_id, tg.id, tg.name, tg.colour
             FROM task_tag_map m
             JOIN task_tags tg ON tg.id = m.tag_id
             WHERE m.task_id IN ({$placeholders})
             ORDER BY tg.display_order, tg.name"
        );
        $stmt->execute($taskIds);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tagsByTask[$row['task_id']][] = [
                'id'     => (int)$row['id'],
                'name'   => $row['name'],
                'colour' => $row['colour']
            ];
        }
    }

    // Who else is on each task ("Involved", GH #89). One query for the whole
    // board rather than one per card — see TasksService::collaboratorsForMany().
    require_once '../../includes/services/tasks.php';
    $collaboratorsByTask = TasksService::collaboratorsForMany($conn, $taskIds);

    // Attach subtask counts, tags and collaborators
    foreach ($tasks as &$task) {
        $task['subtasks']      = $subtaskCounts[$task['id']] ?? ['total' => 0, 'done' => 0];
        $task['tags']          = $tagsByTask[$task['id']] ?? [];
        $task['collaborators'] = $collaboratorsByTask[$task['id']] ?? [];
        // ⭐ Ed's call: one list, with the ones you don't own marked. Worked out
        // HERE rather than in the browser, because the browser would have to know
        // which analyst the list was filtered for — and gets it wrong the moment
        // you point the analyst dropdown at somebody else.
        $viewedAs = ($filter === 'analyst' && isset($_GET['analyst_id']))
            ? (int)$_GET['analyst_id']
            : (int)$analystId;
        $task['viewer_is_owner'] = (int)$task['assigned_analyst_id'] === $viewedAs;
        $task['viewer_is_collaborator'] = false;
        foreach ($task['collaborators'] as $c) {
            if ($c['analyst_id'] === $viewedAs) {
                $task['viewer_is_collaborator'] = true;
                break;
            }
        }
    }
    unset($task);

    // Status counts for sidebar.
    // ⚠️ Scoped too. An aggregate is the easiest place to leak a company: the list
    // above can be filtered perfectly while the counts beside it still total the
    // whole install, and a number is quite enough to tell you another company has
    // 40 open tasks. Same filter, same params.
    $countSql = "SELECT ts.name AS status, COUNT(*) AS cnt
                 FROM tasks t
                 LEFT JOIN task_statuses ts ON ts.id = t.status_id
                 WHERE t.parent_task_id IS NULL{$tenantSql}
                 GROUP BY ts.name";
    $statusCounts = [];
    $stmt = $conn->prepare($countSql);
    $stmt->execute($tenantParams);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $statusCounts[$row['status']] = (int)$row['cnt'];
    }

    echo json_encode([
        'success'       => true,
        'tasks'         => $tasks,
        'status_counts' => $statusCounts
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
