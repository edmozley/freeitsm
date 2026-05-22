<?php
/**
 * API: Tasks — List tasks with filters
 * GET — Returns filtered task list with subtask counts
 * Also supports ?analysts=1 and ?teams=1 for dropdown data
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

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

    $where = ['t.parent_task_id IS NULL'];
    $params = [];

    if ($filter === 'my') {
        $where[] = 't.assigned_analyst_id = ?';
        $params[] = $analystId;
    } elseif ($filter === 'team' && isset($_GET['team_id'])) {
        $where[] = 't.assigned_team_id = ?';
        $params[] = (int)$_GET['team_id'];
    } elseif ($filter === 'analyst' && isset($_GET['analyst_id'])) {
        $where[] = 't.assigned_analyst_id = ?';
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

    $whereSql = implode(' AND ', $where);

    $sql = "SELECT t.id, t.title,
                   ts.name AS status, ts.is_closed AS status_is_closed, ts.colour AS status_colour,
                   tp.name AS priority, tp.colour AS priority_colour,
                   t.start_date, t.due_date,
                   t.assigned_analyst_id, t.assigned_team_id,
                   t.ticket_id, t.change_id, t.contract_id, t.board_position,
                   t.created_by_id, t.created_datetime, t.updated_datetime,
                   t.completed_datetime,
                   a.full_name AS analyst_name,
                   tm.name AS team_name
            FROM tasks t
            LEFT JOIN task_statuses   ts ON ts.id = t.status_id
            LEFT JOIN task_priorities tp ON tp.id = t.priority_id
            LEFT JOIN analysts a ON t.assigned_analyst_id = a.id
            LEFT JOIN teams tm ON t.assigned_team_id = tm.id
            WHERE {$whereSql}
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

    // Attach subtask counts
    foreach ($tasks as &$task) {
        $task['subtasks'] = $subtaskCounts[$task['id']] ?? ['total' => 0, 'done' => 0];
    }

    // Status counts for sidebar
    $countSql = "SELECT ts.name AS status, COUNT(*) AS cnt
                 FROM tasks t
                 LEFT JOIN task_statuses ts ON ts.id = t.status_id
                 WHERE t.parent_task_id IS NULL
                 GROUP BY ts.name";
    $statusCounts = [];
    $stmt = $conn->query($countSql);
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
