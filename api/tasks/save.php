<?php
/**
 * API: Tasks — Create or update a task
 * POST — JSON body with task fields
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

try {
    $conn = connectToDatabase();
    $analystId = $_SESSION['analyst_id'];

    if (isset($input['id']) && $input['id']) {
        // Update existing task
        $id = (int)$input['id'];

        // Build SET clause dynamically from provided fields
        $allowed = ['title', 'description', 'status', 'priority', 'due_date',
                     'assigned_analyst_id', 'assigned_team_id', 'parent_task_id',
                     'ticket_id', 'change_id', 'board_position'];
        $sets = ['updated_datetime = UTC_TIMESTAMP()'];
        $params = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $val = $input[$field];
                if ($val === '' || $val === null) {
                    $sets[] = "`{$field}` = NULL";
                } else {
                    $sets[] = "`{$field}` = ?";
                    $params[] = $val;
                }
            }
        }

        // Handle completed_datetime based on status
        if (isset($input['status'])) {
            if ($input['status'] === 'Done') {
                $sets[] = "completed_datetime = COALESCE(completed_datetime, UTC_TIMESTAMP())";
            } else {
                $sets[] = "completed_datetime = NULL";
            }
        }

        $params[] = $id;
        $sql = "UPDATE tasks SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'Task updated', 'id' => $id]);
    } else {
        // Create new task
        $title = trim($input['title'] ?? '');
        if (!$title) {
            echo json_encode(['success' => false, 'error' => 'Title is required']);
            exit;
        }

        $status = $input['status'] ?? 'To Do';
        $priority = $input['priority'] ?? 'Medium';

        // Calculate board position (append to end of column)
        $posStmt = $conn->prepare("SELECT COALESCE(MAX(board_position), -1) + 1 FROM tasks WHERE status = ? AND parent_task_id IS NULL");
        $posStmt->execute([$status]);
        $boardPos = (int)$posStmt->fetchColumn();

        $sql = "INSERT INTO tasks (title, description, status, priority, due_date,
                    assigned_analyst_id, assigned_team_id, parent_task_id,
                    ticket_id, change_id, board_position, created_by_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $title,
            $input['description'] ?? null,
            $status,
            $priority,
            !empty($input['due_date']) ? $input['due_date'] : null,
            !empty($input['assigned_analyst_id']) ? $input['assigned_analyst_id'] : null,
            !empty($input['assigned_team_id']) ? $input['assigned_team_id'] : null,
            !empty($input['parent_task_id']) ? $input['parent_task_id'] : null,
            !empty($input['ticket_id']) ? $input['ticket_id'] : null,
            !empty($input['change_id']) ? $input['change_id'] : null,
            $boardPos,
            $analystId
        ]);

        $newId = $conn->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Task created', 'id' => (int)$newId]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
