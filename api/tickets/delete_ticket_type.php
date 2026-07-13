<?php
/**
 * API Endpoint: Delete ticket type — multi-tenancy aware.
 *
 *   - Single-company / MSP-Default context → deletes a global default type
 *     (today's behaviour).
 *   - Client company context → you can only delete THAT company's own types;
 *     shared defaults are hidden (not deleted) per company. Blocked if open
 *     tickets in the company still use the type (the in-use guard, design §7).
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
requireCapabilityJson(Cap::TICKETS_TICKET_TYPES);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = !empty($data['id']) ? (int)$data['id'] : null;
    if (!$id) {
        throw new Exception('ID is required');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    $cur = $conn->prepare("SELECT tenant_id FROM ticket_types WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Ticket type not found');
    }
    $owner = ($row['tenant_id'] === null) ? null : (int)$row['tenant_id'];

    if ($isDefaultCtx) {
        if ($owner !== null) {
            throw new Exception("That's a company's own type — switch to that company to delete it.");
        }
    } else {
        if ($owner === null) {
            throw new Exception('Shared default types are managed from the MSP (default) company — here you can hide it from this company instead.');
        }
        if ($owner !== $activeId) {
            throw new Exception('That type belongs to another company.');
        }
        // In-use guard: don't pull a type out from under open tickets in this company.
        $g = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND ticket_type_id = ? AND closed_datetime IS NULL");
        $g->execute([$activeId, $id]);
        if ((int)$g->fetchColumn() > 0) {
            throw new Exception('Open tickets still use this type — reassign or close them first.');
        }
    }

    $name = $conn->query("SELECT name FROM ticket_types WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM ticket_types WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('ticket_type', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
