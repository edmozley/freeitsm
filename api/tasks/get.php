<?php
/**
 * API: Tasks — Get single task with subtasks and comments
 * GET ?id=N
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/entity_links.php';

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

    // 🔒 Company scope. Framed as not-found so the existence of another company's
    // task is not confirmed. This gate also covers the comments and subtasks read
    // further down, since they are fetched by this task's id.
    if (!analystCanAccessTask($conn, (int) $_SESSION['analyst_id'], $id)) {
        echo json_encode(['success' => false, 'error' => 'Task not found']);
        exit;
    }

    // Get the task
    $stmt = $conn->prepare(
        "SELECT t.*,
                ts.name AS status, ts.is_closed AS status_is_closed, ts.colour AS status_colour,
                tp.name AS priority, tp.colour AS priority_colour,
                a.full_name AS analyst_name, tm.name AS team_name,
                ca.full_name AS created_by_name,
                tk.ticket_number, tk.subject AS ticket_subject,
                ch.title AS change_title
         FROM tasks t
         LEFT JOIN task_statuses   ts ON ts.id = t.status_id
         LEFT JOIN task_priorities tp ON tp.id = t.priority_id
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

    // Deep links for the records this task points at (GH #91). Built HERE, from
    // the one resolver, rather than assembled in JavaScript — a second copy of
    // the record→URL map in the client is precisely the drift entity_links.php
    // exists to end. NULL when there is no link, which the client renders as
    // plain text rather than a dead anchor.
    $task['ticket_url'] = $task['ticket_id'] ? entityLink('ticket', (int) $task['ticket_id']) : null;
    $task['change_url'] = $task['change_id'] ? entityLink('change', (int) $task['change_id']) : null;

    // Who else is on this task ("Involved", GH #89), and whether the per-person
    // tick is switched on — the panel needs both before it can draw the section.
    require_once '../../includes/services/tasks.php';
    $task['collaborators'] = TasksService::collaboratorsFor($conn, (int)$id);
    $task['collaborator_completion'] = TasksService::collaboratorCompletionEnabled($conn);

    // Get parent task info if this is a subtask
    if ($task['parent_task_id']) {
        $stmt = $conn->prepare("SELECT id, title FROM tasks WHERE id = ?");
        $stmt->execute([$task['parent_task_id']]);
        $task['parent_task'] = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Get subtasks with assignee names
    $stmt = $conn->prepare(
        "SELECT t.id, t.title,
                ts.name AS status, ts.is_closed AS status_is_closed,
                tp.name AS priority, tp.colour AS priority_colour,
                t.due_date,
                t.assigned_analyst_id, t.board_position, t.completed_datetime,
                a.full_name AS analyst_name
         FROM tasks t
         LEFT JOIN task_statuses   ts ON ts.id = t.status_id
         LEFT JOIN task_priorities tp ON tp.id = t.priority_id
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

    // Get tags
    $stmt = $conn->prepare(
        "SELECT tg.id, tg.name, tg.colour
         FROM task_tag_map m
         JOIN task_tags tg ON tg.id = m.tag_id
         WHERE m.task_id = ?
         ORDER BY tg.display_order, tg.name"
    );
    $stmt->execute([$id]);
    $task['tags'] = array_map(function ($tg) {
        $tg['id'] = (int)$tg['id'];
        return $tg;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Recurrence (#94). Three separate things the panel needs: the rule itself,
    // where this occurrence sits in the series, and how to reach the original.
    $task['recurrence'] = null;
    $task['recurrence_master'] = null;
    $task['recurrence_position'] = null;
    if (!empty($task['recurrence_id'])) {
        try {
            $stmt = $conn->prepare("SELECT * FROM task_recurrences WHERE id = ?");
            $stmt->execute([(int)$task['recurrence_id']]);
            $task['recurrence'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            // The task the series started from, so an occurrence can point a
            // reader back to it. Null when this IS the master.
            if (!empty($task['recurrence_master_id']) && (int)$task['recurrence_master_id'] !== (int)$task['id']) {
                $stmt = $conn->prepare("SELECT id, title, due_date FROM tasks WHERE id = ?");
                $stmt->execute([(int)$task['recurrence_master_id']]);
                $task['recurrence_master'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            // "3 of 12" - counted by date so it reads the way a person would
            // count, not by insertion order.
            $stmt = $conn->prepare(
                "SELECT COUNT(*) FROM tasks
                  WHERE recurrence_id = ? AND parent_task_id IS NULL
                    AND (due_date < ? OR (due_date = ? AND id <= ?) OR (due_date IS NULL AND id <= ?))"
            );
            $stmt->execute([(int)$task['recurrence_id'], $task['due_date'], $task['due_date'], (int)$task['id'], (int)$task['id']]);
            $task['recurrence_position'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // An install part-way through an upgrade still gets its task.
            $task['recurrence'] = null;
        }
    }

    echo json_encode(['success' => true, 'task' => $task]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
