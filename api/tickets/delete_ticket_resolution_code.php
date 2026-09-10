<?php
/**
 * API Endpoint: delete a resolution code (#1540).
 *
 * ⚠️ Same rule as categories: retiring (is_active = 0) is the normal way to
 * remove one, so a closed ticket keeps the code it was closed with. Deleting is
 * only allowed while no ticket points at it. The foreign key would RESTRICT this
 * anyway; the check exists to say WHY in words first.
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
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_CATEGORIES);

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = !empty($data['id']) ? (int) $data['id'] : null;
    if (!$id) throw new Exception('ID is required');

    $conn      = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    $cur = $conn->prepare("SELECT name, tenant_id FROM ticket_resolution_codes WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Resolution code not found');

    $owner = $row['tenant_id'] === null ? null : (int) $row['tenant_id'];
    if ($isDefaultCtx) {
        if ($owner !== null) throw new Exception("That's a company's own resolution code — switch to that company to delete it.");
    } else {
        if ($owner === null)      throw new Exception('Shared default resolution codes are managed from the MSP (default) company — retire it there, or hide it from this company.');
        if ($owner !== $activeId) throw new Exception('That resolution code belongs to another company.');
    }

    $u = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE resolution_code_id = ?");
    $u->execute([$id]);
    $inUse = (int) $u->fetchColumn();
    if ($inUse > 0) {
        throw new Exception(
            $inUse . ($inUse === 1 ? ' ticket was' : ' tickets were') . ' closed with this code. '
            . 'Switch it off instead — it stays on those tickets and stops being offered on new ones.'
        );
    }

    $conn->prepare("DELETE FROM ticket_resolution_codes WHERE id = ?")->execute([$id]);

    wf_emit('ticket_resolution_code', 'deleted', $id, $row['name']);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
