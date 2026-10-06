<?php
/**
 * API: Search tasks that could be linked to a ticket (discussion #83).
 *
 * Feeds the "Link to… → Task" picker on the reading pane. The picker offers
 * creating a task from what was typed as well as linking an existing one, and
 * these results are what stops the two being the same thing by accident: you see
 * the near matches while you type, so an existing task is linked rather than
 * duplicated.
 *
 * Tasks already attached to THIS ticket are excluded — they are already pills on
 * the strip, and offering them again just invites a no-op click.
 *
 * GET ?ticket_id=N&q=text
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
requireModuleAccessJson('tickets');

try {
    $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
    $q        = trim($_GET['q'] ?? '');

    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if ($ticketId > 0 && !analystCanAccessTicket($conn, $analystId, $ticketId)) {
        throw new Exception('Ticket not found');
    }
    // An empty search lists the most recent tasks (the phone Link to task modal
    // opens on a list, like Link to change). The desktop strip picker never
    // sends an empty q - it shows "type to search" without calling this.
    $limit = $q === '' ? 20 : 10;

    [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'tk');

    // Subtasks are excluded: a subtask belongs to its parent's work, not directly
    // to a ticket, and linking one would put a pill on the strip whose parent is
    // nowhere in sight.
    $stmt = $conn->prepare(
        "SELECT tk.id, tk.title, tk.ticket_id,
                ts.name AS status, ts.is_closed AS status_is_closed, ts.colour AS status_colour,
                t2.ticket_number AS linked_ticket_number
           FROM tasks tk
      LEFT JOIN task_statuses ts ON ts.id = tk.status_id
      LEFT JOIN tickets t2       ON t2.id = tk.ticket_id
          WHERE tk.title LIKE ?
            AND tk.parent_task_id IS NULL
            AND (tk.ticket_id IS NULL OR tk.ticket_id <> ?)" . $tSql . "
       ORDER BY (ts.is_closed = 1), tk.updated_datetime DESC
          LIMIT $limit"
    );
    $stmt->execute(array_merge(['%' . $q . '%', $ticketId], $tArgs));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['status_is_closed'] = (int)($r['status_is_closed'] ?? 0) === 1;
        // A task already attached elsewhere can still be linked here, but the
        // picker says where it is going to be taken FROM — silently moving
        // somebody else's task off their ticket would be a nasty surprise.
        $r['linked_elsewhere'] = !empty($r['ticket_id']);
        unset($r['ticket_id']);
    }
    unset($r);

    echo json_encode(['success' => true, 'results' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
