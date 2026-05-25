<?php
/**
 * API: Tasks — Create or update a task
 * POST — JSON body with task fields
 */
session_start(['read_and_close' => true]);
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

    // Resolve a status/priority name -> id (with default fallback)
    $resolveLookup = function($table, $name) use ($conn) {
        if ($name === null || $name === '') return null;
        $s = $conn->prepare("SELECT id FROM `$table` WHERE name = ? LIMIT 1");
        $s->execute([$name]);
        $id = $s->fetchColumn();
        if ($id) return (int)$id;
        $s = $conn->prepare("SELECT id FROM `$table` WHERE is_default = 1 LIMIT 1");
        $s->execute();
        $id = $s->fetchColumn();
        return $id ? (int)$id : null;
    };
    // Look up the is_closed flag for a status name (used to drive completed_datetime)
    $statusIsClosed = function($name) use ($conn) {
        if ($name === null || $name === '') return false;
        $s = $conn->prepare("SELECT is_closed FROM task_statuses WHERE name = ? LIMIT 1");
        $s->execute([$name]);
        return (bool)$s->fetchColumn();
    };
    // Replace a task's tag set with the given list of tag ids
    $syncTags = function($taskId, $tagIds) use ($conn) {
        $conn->prepare("DELETE FROM task_tag_map WHERE task_id = ?")->execute([$taskId]);
        if (is_array($tagIds) && $tagIds) {
            $ins = $conn->prepare("INSERT IGNORE INTO task_tag_map (task_id, tag_id) VALUES (?, ?)");
            foreach ($tagIds as $tid) {
                $tid = (int)$tid;
                if ($tid > 0) $ins->execute([$taskId, $tid]);
            }
        }
    };

    if (isset($input['id']) && $input['id']) {
        // Update existing task
        $id = (int)$input['id'];

        // Build SET clause dynamically. status/priority arrive as names; map them to *_id.
        $allowed = ['title', 'description', 'start_date', 'due_date',
                     'assigned_analyst_id', 'assigned_team_id', 'parent_task_id',
                     'ticket_id', 'change_id', 'contract_id', 'board_position'];
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

        if (array_key_exists('status', $input)) {
            $sets[] = "status_id = ?";
            $params[] = $resolveLookup('task_statuses', $input['status']);
        }
        if (array_key_exists('priority', $input)) {
            $sets[] = "priority_id = ?";
            $params[] = $resolveLookup('task_priorities', $input['priority']);
        }

        // Handle completed_datetime based on the new status's is_closed flag
        if (isset($input['status'])) {
            if ($statusIsClosed($input['status'])) {
                $sets[] = "completed_datetime = COALESCE(completed_datetime, UTC_TIMESTAMP())";
            } else {
                $sets[] = "completed_datetime = NULL";
            }
        }

        $params[] = $id;
        $sql = "UPDATE tasks SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        if (array_key_exists('tags', $input)) $syncTags($id, $input['tags']);

        echo json_encode(['success' => true, 'message' => 'Task updated', 'id' => $id]);
    } else {
        // Create new task
        $title = trim($input['title'] ?? '');
        if (!$title) {
            echo json_encode(['success' => false, 'error' => 'Title is required']);
            exit;
        }

        $statusName   = $input['status']   ?? 'To Do';
        $priorityName = $input['priority'] ?? 'Medium';
        $statusId   = $resolveLookup('task_statuses',   $statusName);
        $priorityId = $resolveLookup('task_priorities', $priorityName);

        // Calculate board position (append to end of column)
        $posStmt = $conn->prepare("SELECT COALESCE(MAX(board_position), -1) + 1 FROM tasks WHERE status_id = ? AND parent_task_id IS NULL");
        $posStmt->execute([$statusId]);
        $boardPos = (int)$posStmt->fetchColumn();

        $sql = "INSERT INTO tasks (title, description, status_id, priority_id, start_date, due_date,
                    assigned_analyst_id, assigned_team_id, parent_task_id,
                    ticket_id, change_id, contract_id, board_position, created_by_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $title,
            $input['description'] ?? null,
            $statusId,
            $priorityId,
            !empty($input['start_date']) ? $input['start_date'] : null,
            !empty($input['due_date']) ? $input['due_date'] : null,
            !empty($input['assigned_analyst_id']) ? $input['assigned_analyst_id'] : null,
            !empty($input['assigned_team_id']) ? $input['assigned_team_id'] : null,
            !empty($input['parent_task_id']) ? $input['parent_task_id'] : null,
            !empty($input['ticket_id']) ? $input['ticket_id'] : null,
            !empty($input['change_id']) ? $input['change_id'] : null,
            !empty($input['contract_id']) ? $input['contract_id'] : null,
            $boardPos,
            $analystId
        ]);

        $newId = (int)$conn->lastInsertId();
        if (isset($input['tags'])) $syncTags($newId, $input['tags']);
        echo json_encode(['success' => true, 'message' => 'Task created', 'id' => $newId]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
