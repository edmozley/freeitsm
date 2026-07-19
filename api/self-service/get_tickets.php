<?php
/**
 * API: Get Self-Service User's Tickets
 * GET - Returns all tickets for the logged-in user, with optional status filter
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = (int)$_SESSION['ss_user_id'];
$statusFilter = $_GET['status'] ?? '';

try {
    $conn = connectToDatabase();

    // status_colour + is_closed drive the list pane's dot and its Open/Closed
    // filter; `preview` is the newest message, so the list reads like a mailbox
    // rather than a table of subjects.
    $sql = "SELECT t.id, t.ticket_number, t.subject, ts.name AS status, tp.name AS priority,
                   ts.colour AS status_colour, ts.is_closed,
                   t.created_datetime, t.updated_datetime,
                   d.name as department_name,
                   a.full_name as assigned_analyst_name,
                   (SELECT LEFT(e.body_preview, 160) FROM emails e
                     WHERE e.ticket_id = t.id
                     ORDER BY e.received_datetime DESC LIMIT 1) AS preview
            FROM tickets t
            LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
            LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
            LEFT JOIN departments d ON t.department_id = d.id
            LEFT JOIN analysts a ON t.assigned_analyst_id = a.id
            WHERE t.user_id = ? AND t.deleted_datetime IS NULL";
    $params = [$userId];

    if (!empty($statusFilter)) {
        $sql .= " AND ts.name = ?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY t.created_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'tickets' => $tickets]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
