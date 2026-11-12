<?php
/**
 * API: List tickets that reference an asset (discussion #57).
 *
 * Powers the Tickets tab on the asset detail page — the half of #57 that makes
 * the linking worth doing, because it answers "has this thing broken before?".
 *
 * Mirrors api/cmdb/get_object_tickets.php, including its two lessons: gate the
 * two modules independently rather than trusting the same-company invariant,
 * and filter deleted tickets, or the tab lists tickets sitting in the recycle
 * bin. Two buckets:
 *   - open:   tickets whose status is NOT closed
 *   - closed: capped to the most recent 20, with a total so the UI can say
 *             "showing 20 of N" for a device with a long history
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('assets');

try {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    // Two independent gates, because this endpoint straddles two modules.
    // 1. The asset itself must be reachable...
    if (!analystCanAccessAsset($conn, $analystId, $id)) {
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit;
    }
    // 2. ...and the TICKETS are scoped separately. An asset and a ticket linked
    //    to it should always share a company, but this read must not depend on
    //    that invariant holding.
    [$tSql, $tArgs] = ticketScopeFilter($conn, $analystId, 't');

    $base =
        "SELECT t.id, t.ticket_number, t.subject,
                ts.name AS status, ts.colour AS status_colour, ts.is_closed AS status_is_closed,
                tp.name AS priority, tp.colour AS priority_colour,
                t.created_datetime, t.updated_datetime, t.closed_datetime,
                a.full_name AS assigned_to,
                d.name AS department_name
           FROM ticket_assets ta
           JOIN tickets t ON t.id = ta.ticket_id
      LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
      LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
      LEFT JOIN analysts a ON a.id = t.assigned_analyst_id
      LEFT JOIN departments d ON d.id = t.department_id
          WHERE ta.asset_id = ? AND t.deleted_datetime IS NULL" . $tSql;

    $args = array_merge([$id], $tArgs);

    $openStmt = $conn->prepare($base . " AND COALESCE(ts.is_closed, 0) = 0 ORDER BY t.updated_datetime DESC");
    $openStmt->execute($args);
    $open = $openStmt->fetchAll(PDO::FETCH_ASSOC);

    $closedStmt = $conn->prepare($base . " AND COALESCE(ts.is_closed, 0) = 1 ORDER BY t.closed_datetime DESC LIMIT 20");
    $closedStmt->execute($args);
    $closed = $closedStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalClosedStmt = $conn->prepare(
        "SELECT COUNT(*) FROM ticket_assets ta
           JOIN tickets t ON t.id = ta.ticket_id
      LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
          WHERE ta.asset_id = ? AND t.deleted_datetime IS NULL" . $tSql .
        " AND COALESCE(ts.is_closed, 0) = 1"
    );
    $totalClosedStmt->execute($args);
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
        'success'      => true,
        'open'         => $open,
        'closed'       => $closed,
        'total_closed' => $totalClosed,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
