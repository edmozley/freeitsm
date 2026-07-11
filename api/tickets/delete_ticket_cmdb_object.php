<?php
/**
 * API: Unlink a CMDB object from a ticket. Accepts either the link row id
 * (link_id), or a ticket_id + cmdb_object_id pair.
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
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $linkId   = isset($data['link_id'])  ? (int)$data['link_id']  : 0;
    $ticketId = isset($data['ticket_id'])     ? (int)$data['ticket_id'] : 0;
    $objectId = isset($data['cmdb_object_id']) ? (int)$data['cmdb_object_id'] : 0;

    $conn = connectToDatabase();

    $analystId = (int)$_SESSION['analyst_id'];
    if ($linkId > 0) {
        // Multi-tenancy: resolve the link's ticket and gate on access.
        $own = $conn->prepare("SELECT ticket_id FROM ticket_cmdb_objects WHERE id = ?");
        $own->execute([$linkId]);
        $linkTicketId = $own->fetchColumn();
        if ($linkTicketId === false || !analystCanAccessTicket($conn, $analystId, (int)$linkTicketId)) {
            throw new Exception('Ticket not found');
        }
        $stmt = $conn->prepare("DELETE FROM ticket_cmdb_objects WHERE id = ?");
        $stmt->execute([$linkId]);
    } elseif ($ticketId > 0 && $objectId > 0) {
        if (!analystCanAccessTicket($conn, $analystId, $ticketId)) {
            throw new Exception('Ticket not found');
        }
        $stmt = $conn->prepare("DELETE FROM ticket_cmdb_objects WHERE ticket_id = ? AND cmdb_object_id = ?");
        $stmt->execute([$ticketId, $objectId]);
    } else {
        throw new Exception('Pass link_id OR (ticket_id + cmdb_object_id)');
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
