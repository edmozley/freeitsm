<?php
/**
 * API Endpoint: move a change to another company (tenant).
 *
 * The Change Management twin of api/tickets/move_ticket_to_company.php. Gated
 * both ways — the analyst must be able to access the change as it currently
 * sits AND the company they're moving it into. Writes a change_audit entry
 * recording the change of company. No-op surface on a single-company install.
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
requireModuleAccessJson('changes');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $changeId = (int)($input['change_id'] ?? $input['id'] ?? 0);
    $targetId = (int)($input['tenant_id'] ?? 0);
    $analystId = (int)$_SESSION['analyst_id'];

    if ($changeId <= 0) throw new Exception('Change ID is required');
    if ($targetId <= 0) throw new Exception('A target company is required');

    $conn = connectToDatabase();

    // Must be able to see the change where it currently sits…
    if (!analystCanAccessChange($conn, $analystId, $changeId)) {
        throw new Exception('Change not found');
    }
    // …and must have access to the company it's being moved into.
    if (!analystCanAccessTenant($conn, $analystId, $targetId)) {
        throw new Exception('You do not have access to that company.');
    }

    $target = getTenantById($conn, $targetId);
    if (!$target) {
        throw new Exception('That company does not exist.');
    }

    // Current company (for the audit trail). NULL = the Default company.
    $cur = $conn->prepare("SELECT tenant_id FROM changes WHERE id = ?");
    $cur->execute([$changeId]);
    $oldTenantId = $cur->fetchColumn();
    $oldTenantId = ($oldTenantId === false || $oldTenantId === null) ? getDefaultTenantId($conn) : (int)$oldTenantId;

    if ($oldTenantId === $targetId) {
        echo json_encode(['success' => true, 'message' => 'Change is already in that company.', 'company_name' => $target['name']]);
        exit;
    }

    $oldTenant = getTenantById($conn, $oldTenantId);
    $oldName = $oldTenant['name'] ?? 'Unknown';

    $conn->prepare("UPDATE changes SET tenant_id = ?, modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([$targetId, $changeId]);

    // Audit (server-side so old/new company names are recorded accurately).
    try {
        $conn->prepare(
            "INSERT INTO change_audit (change_id, analyst_id, action_type, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, 'field_change', 'Company', ?, ?, UTC_TIMESTAMP())"
        )->execute([$changeId, $analystId, $oldName, $target['name']]);
    } catch (Exception $e) { /* audit is best-effort */ }

    echo json_encode(['success' => true, 'message' => 'Change moved to ' . $target['name'], 'company_name' => $target['name']]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
