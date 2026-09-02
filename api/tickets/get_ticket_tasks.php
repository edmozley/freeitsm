<?php
/**
 * API: List the tasks linked to a ticket (discussion #83).
 *
 * Returns what the reading pane's Links strip needs for a task pill: the title,
 * the status and its colour, whether that status closes the task, who it is
 * assigned to, and the subtask progress — #83 asked to see "task status and
 * completion progress" without leaving the ticket.
 *
 * The link is `tasks.ticket_id`, a column on the task rather than a join table,
 * so a task belongs to at most one ticket while a ticket may have any number.
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
    $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
    if ($ticketId <= 0) throw new Exception('ticket_id is required');

    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) {
        throw new Exception('Ticket not found');
    }

    // The ticket gate doesn't cover the tasks this hydrates. A task created
    // before its ticket moved company, or linked by an all-access analyst, can
    // straddle two companies — so scope the task too rather than trusting that
    // the two always agree. Same reasoning as get_ticket_assets.php.
    [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'tk');

    $stmt = $conn->prepare(
        "SELECT tk.id, tk.title, tk.due_date, tk.completed_datetime,
                ts.name AS status, ts.is_closed AS status_is_closed, ts.colour AS status_colour,
                a.full_name AS analyst_name,
                (SELECT COUNT(*) FROM tasks s WHERE s.parent_task_id = tk.id) AS subtasks_total,
                (SELECT COUNT(*) FROM tasks s
                   LEFT JOIN task_statuses sts ON sts.id = s.status_id
                  WHERE s.parent_task_id = tk.id AND sts.is_closed = 1) AS subtasks_done
           FROM tasks tk
      LEFT JOIN task_statuses ts ON ts.id = tk.status_id
      LEFT JOIN analysts a       ON a.id  = tk.assigned_analyst_id
          WHERE tk.ticket_id = ?
            AND tk.parent_task_id IS NULL" . $tSql . "
       ORDER BY (ts.is_closed = 1), tk.due_date IS NULL, tk.due_date, tk.id"
    );
    $stmt->execute(array_merge([$ticketId], $tArgs));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Who else is on each of these tasks ("Involved", GH #89). A display join,
    // not a filter — it cannot hide a task here — but leaving it out would mean a
    // task shows one name on the ticket and three in the Tasks module, which
    // reads as a bug in whichever screen you saw second.
    require_once '../../includes/services/tasks.php';
    $collaboratorsByTask = TasksService::collaboratorsForMany($conn, array_column($rows, 'id'));

    $open = 0;
    foreach ($rows as &$r) {
        $r['id']              = (int)$r['id'];
        $r['status_is_closed'] = (int)($r['status_is_closed'] ?? 0) === 1;
        $r['subtasks_total']  = (int)$r['subtasks_total'];
        $r['subtasks_done']   = (int)$r['subtasks_done'];
        $r['collaborators']   = $collaboratorsByTask[$r['id']] ?? [];
        if (!$r['status_is_closed']) $open++;
    }
    unset($r);

    // `open_count` drives the warning when someone closes a ticket that still has
    // work outstanding. Sent from here so that check costs no extra round trip.
    echo json_encode(['success' => true, 'tasks' => $rows, 'open_count' => $open]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
