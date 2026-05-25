<?php
/**
 * API: List tickets that reference a CMDB object.
 * Powers the Activity panel on the object detail page. Two buckets:
 *   - open:   tickets whose status is NOT closed (any non-closed lookup row)
 *   - closed: closed tickets, capped to the most recent 20 so the panel
 *             doesn't grow unbounded for objects with long histories
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();

    $base =
        "SELECT t.id, t.ticket_number, t.subject,
                ts.name AS status, ts.colour AS status_colour, ts.is_closed AS status_is_closed,
                tp.name AS priority, tp.colour AS priority_colour,
                t.created_datetime, t.updated_datetime, t.closed_datetime,
                a.full_name AS assigned_to,
                d.name AS department_name
           FROM ticket_cmdb_objects tco
           JOIN tickets t ON t.id = tco.ticket_id
      LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
      LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
      LEFT JOIN analysts a ON a.id = t.assigned_analyst_id
      LEFT JOIN departments d ON d.id = t.department_id
          WHERE tco.cmdb_object_id = ?";

    $openStmt = $conn->prepare($base . " AND COALESCE(ts.is_closed, 0) = 0 ORDER BY t.updated_datetime DESC");
    $openStmt->execute([$id]);
    $open = $openStmt->fetchAll(PDO::FETCH_ASSOC);

    $closedStmt = $conn->prepare($base . " AND COALESCE(ts.is_closed, 0) = 1 ORDER BY t.closed_datetime DESC LIMIT 20");
    $closedStmt->execute([$id]);
    $closed = $closedStmt->fetchAll(PDO::FETCH_ASSOC);

    // Total closed count (so the UI can show "showing 20 of N")
    $totalClosedStmt = $conn->prepare(
        "SELECT COUNT(*) FROM ticket_cmdb_objects tco
           JOIN tickets t ON t.id = tco.ticket_id
      LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
          WHERE tco.cmdb_object_id = ? AND COALESCE(ts.is_closed, 0) = 1"
    );
    $totalClosedStmt->execute([$id]);
    $totalClosed = (int)$totalClosedStmt->fetchColumn();

    $coerce = function (&$rows) {
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['status_is_closed'] = $r['status_is_closed'] !== null ? (int)$r['status_is_closed'] : 0;
        }
    };
    $coerce($open);
    $coerce($closed);

    echo json_encode([
        'success'           => true,
        'open'              => $open,
        'closed'            => $closed,
        'total_closed'      => $totalClosed,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
