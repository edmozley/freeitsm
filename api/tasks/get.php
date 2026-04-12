<?php
/**
 * API: Tasks — Get single task with subtasks and comments
 * GET ?id=N
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing task ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get the task
    $stmt = $conn->prepare(
        "SELECT t.*, a.full_name AS analyst_name, tm.name AS team_name,
                ca.full_name AS created_by_name,
                tk.ticket_number, tk.subject AS ticket_subject,
                ch.title AS change_title
         FROM tasks t
         LEFT JOIN analysts a ON t.assigned_analyst_id = a.id
         LEFT JOIN teams tm ON t.assigned_team_id = tm.id
         LEFT JOIN analysts ca ON t.created_by_id = ca.id
         LEFT JOIN tickets tk ON t.ticket_id = tk.id
         LEFT JOIN changes ch ON t.change_id = ch.id
         WHERE t.id = ?"
    );
    $stmt->execute([$id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        echo json_encode(['success' => false, 'error' => 'Task not found']);
        exit;
    }

    // Get parent task info if this is a subtask
    if ($task['parent_task_id']) {
        $stmt = $conn->prepare("SELECT id, title FROM tasks WHERE id = ?");
        $stmt->execute([$task['parent_task_id']]);
        $task['parent_task'] = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Get subtasks with assignee names
    $stmt = $conn->prepare(
        "SELECT t.id, t.title, t.status, t.priority, t.due_date,
                t.assigned_analyst_id, t.board_position, t.completed_datetime,
                a.full_name AS analyst_name
         FROM tasks t
         LEFT JOIN analysts a ON t.assigned_analyst_id = a.id
         WHERE t.parent_task_id = ?
         ORDER BY t.board_position ASC, t.created_datetime ASC"
    );
    $stmt->execute([$id]);
    $task['subtasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get comments
    $stmt = $conn->prepare(
        "SELECT c.id, c.comment, c.created_datetime, a.full_name AS analyst_name
         FROM task_comments c
         JOIN analysts a ON c.analyst_id = a.id
         WHERE c.task_id = ?
         ORDER BY c.created_datetime ASC"
    );
    $stmt->execute([$id]);
    $task['comments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'task' => $task]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
