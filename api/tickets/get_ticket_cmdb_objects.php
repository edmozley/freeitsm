<?php
/**
 * API: List the CMDB objects linked to a ticket.
 * Returns hydrated info-card data (name, class, parent name+class, optional
 * Owner) so the reading pane can render each link without follow-up calls.
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
    $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
    if ($ticketId <= 0) throw new Exception('ticket_id is required');

    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT tco.id AS link_id,
                o.id AS object_id, o.name, c.name AS class_name,
                o.parent_id, p.name AS parent_name, pc.name AS parent_class_name,
                tco.created_datetime
           FROM ticket_cmdb_objects tco
           JOIN cmdb_objects o ON o.id = tco.cmdb_object_id
           JOIN cmdb_classes c ON c.id = o.class_id
      LEFT JOIN cmdb_objects p ON p.id = o.parent_id
      LEFT JOIN cmdb_classes pc ON pc.id = p.class_id
          WHERE tco.ticket_id = ?
       ORDER BY c.name, o.name"
    );
    $stmt->execute([$ticketId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['link_id'] = (int)$r['link_id'];
        $r['object_id'] = (int)$r['object_id'];
        $r['parent_id'] = $r['parent_id'] !== null ? (int)$r['parent_id'] : null;
    }

    echo json_encode(['success' => true, 'links' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
