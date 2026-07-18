<?php
/**
 * API: Link a CMDB object to a ticket.
 * Idempotent — returns success even if the link already exists (the unique
 * key catches dupes; we surface a friendly message rather than a SQL error).
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
    $ticketId = isset($data['ticket_id']) ? (int)$data['ticket_id'] : 0;
    $objectId = isset($data['cmdb_object_id']) ? (int)$data['cmdb_object_id'] : 0;
    if ($ticketId <= 0 || $objectId <= 0) {
        throw new Exception('ticket_id and cmdb_object_id are required');
    }

    $conn = connectToDatabase();

    // Multi-tenancy: only link CMDB objects to a ticket this analyst can access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        throw new Exception('Ticket not found');
    }

    // Verify both rows exist before inserting (cleaner errors than FK failures)
    $check = $conn->prepare("SELECT 1 FROM tickets WHERE id = ?");
    $check->execute([$ticketId]);
    if (!$check->fetchColumn()) throw new Exception('Ticket not found');

    $check = $conn->prepare("SELECT 1 FROM cmdb_objects WHERE id = ?");
    $check->execute([$objectId]);
    if (!$check->fetchColumn()) throw new Exception('CMDB object not found');

    // ...and the CI side, which was unchecked: the ticket gate above says nothing
    // about the object, so any CI id could be attached to a ticket the analyst
    // legitimately owns — pulling another company's CI name into their reading
    // pane. Framed as not-found, like every other CI gate.
    if (!analystCanAccessCmdbObject($conn, (int)$_SESSION['analyst_id'], $objectId)) {
        throw new Exception('CMDB object not found');
    }

    // Both ends reachable is still not enough for an all-access analyst, who can
    // reach both companies. A ticket and the CI it references must belong to the
    // same company, or the link itself becomes the leak.
    if (isMultiTenant($conn)) {
        $tt = $conn->prepare("SELECT tenant_id FROM tickets WHERE id = ?");
        $tt->execute([$ticketId]);
        $ticketTenant = $tt->fetchColumn();
        $ot = $conn->prepare("SELECT tenant_id FROM cmdb_objects WHERE id = ?");
        $ot->execute([$objectId]);
        $objectTenant = $ot->fetchColumn();
        $norm = fn($v) => ($v === null || $v === false) ? getDefaultTenantId($conn) : (int)$v;
        if ($norm($ticketTenant) !== $norm($objectTenant)) {
            throw new Exception('That configuration item belongs to a different company');
        }
    }

    try {
        $ins = $conn->prepare(
            "INSERT INTO ticket_cmdb_objects (ticket_id, cmdb_object_id, created_datetime, created_by_analyst_id)
             VALUES (?, ?, UTC_TIMESTAMP(), ?)"
        );
        $ins->execute([$ticketId, $objectId, (int)$_SESSION['analyst_id']]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId(), 'already_linked' => false]);
    } catch (PDOException $pe) {
        if ($pe->errorInfo[1] == 1062) {
            // Already linked — surface as success with a flag so the UI can be quiet
            echo json_encode(['success' => true, 'already_linked' => true]);
            exit;
        }
        throw $pe;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
