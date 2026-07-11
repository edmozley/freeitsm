<?php
/**
 * API Endpoint: move a ticket to another company (tenant).
 *
 * The fix for "sticky" wrong-company filing: a misrouted ticket can be re-homed to
 * the correct company. Gated both ways — the analyst must be able to access the
 * ticket as it stands AND the company they're moving it into. Writes a ticket_audit
 * entry recording the change. No-op surface on a single-company install.
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
    $input = json_decode(file_get_contents('php://input'), true);
    $ticketId = (int)($input['ticket_id'] ?? 0);
    $targetId = (int)($input['tenant_id'] ?? 0);
    $analystId = (int)$_SESSION['analyst_id'];

    if ($ticketId <= 0) throw new Exception('Ticket ID is required');
    if ($targetId <= 0) throw new Exception('A target company is required');

    $conn = connectToDatabase();

    // Must be able to see the ticket where it currently sits…
    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) {
        throw new Exception('Ticket not found');
    }
    // …and must have access to the company it's being moved into.
    if (!analystCanAccessTenant($conn, $analystId, $targetId)) {
        throw new Exception('You do not have access to that company.');
    }

    $target = getTenantById($conn, $targetId);
    if (!$target) {
        throw new Exception('That company does not exist.');
    }

    // Current company (for the audit trail). NULL = the Default company (triage).
    $cur = $conn->prepare("SELECT tenant_id FROM tickets WHERE id = ?");
    $cur->execute([$ticketId]);
    $oldTenantId = $cur->fetchColumn();
    $oldTenantId = ($oldTenantId === false || $oldTenantId === null) ? getDefaultTenantId($conn) : (int)$oldTenantId;

    if ($oldTenantId === $targetId) {
        echo json_encode(['success' => true, 'message' => 'Ticket is already in that company.', 'company_name' => $target['name']]);
        exit;
    }

    $oldTenant = getTenantById($conn, $oldTenantId);
    $oldName = $oldTenant['name'] ?? 'Unknown';

    $conn->prepare("UPDATE tickets SET tenant_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([$targetId, $ticketId]);

    // Audit (server-side so old/new company names are recorded accurately).
    try {
        $conn->prepare(
            "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, 'Company', ?, ?, UTC_TIMESTAMP())"
        )->execute([$ticketId, $analystId, $oldName, $target['name']]);
    } catch (Exception $e) { /* audit is best-effort */ }

    echo json_encode(['success' => true, 'message' => 'Ticket moved to ' . $target['name'], 'company_name' => $target['name']]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
