<?php
/**
 * API: List the CMDB objects linked to a ticket.
 * Returns hydrated info-card data (name, class, parent name+class, optional
 * Owner) so the reading pane can render each link without follow-up calls.
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

    $conn = connectToDatabase();

    // Multi-tenancy: don't reveal a ticket in a company this analyst can't access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        throw new Exception('Ticket not found');
    }

    // The ticket gate above doesn't cover the CIs this hydrates. A link created
    // before the same-company rule existed can still straddle two companies, so
    // scope the CI (and its parent) rather than trusting the invariant.
    $analystId = (int)$_SESSION['analyst_id'];
    [$tObj,    $aObj]    = activeTenantFilter($conn, $analystId, 'o');
    [$tParent, $aParent] = activeTenantFilter($conn, $analystId, 'p');

    $stmt = $conn->prepare(
        "SELECT tco.id AS link_id,
                o.id AS object_id, o.name, c.name AS class_name,
                o.parent_id, p.name AS parent_name, pc.name AS parent_class_name,
                tco.created_datetime
           FROM ticket_cmdb_objects tco
           JOIN cmdb_objects o ON o.id = tco.cmdb_object_id
           JOIN cmdb_classes c ON c.id = o.class_id
      LEFT JOIN cmdb_objects p ON p.id = o.parent_id" . $tParent . "
      LEFT JOIN cmdb_classes pc ON pc.id = p.class_id
          WHERE tco.ticket_id = ?" . $tObj . "
       ORDER BY c.name, o.name"
    );
    $stmt->execute(array_merge($aParent, [$ticketId], $aObj));
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
