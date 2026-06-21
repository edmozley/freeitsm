<?php
/**
 * Delete Ticket Origin API — multi-tenancy aware (mirrors delete_ticket_type.php).
 *
 *   - Single-company / MSP-Default context → deletes a global default origin
 *     (blocked if any ticket uses it — today's behaviour).
 *   - Client company context → only that company's own origins; shared defaults
 *     are hidden (not deleted). Blocked while open tickets in the company use it.
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
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) {
        throw new Exception('Origin ID is required');
    }
    $id = (int)$input['id'];

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    $cur = $conn->prepare("SELECT tenant_id FROM ticket_origins WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Ticket origin not found');
    }
    $owner = ($row['tenant_id'] === null) ? null : (int)$row['tenant_id'];

    if ($isDefaultCtx) {
        if ($owner !== null) {
            throw new Exception("That's a company's own origin — switch to that company to delete it.");
        }
        // Today's behaviour: block if any ticket uses this global origin.
        $check = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE origin_id = ?");
        $check->execute([$id]);
        if ((int)$check->fetchColumn() > 0) {
            throw new Exception('Cannot delete: this origin is assigned to tickets. Set it inactive instead.');
        }
    } else {
        if ($owner === null) {
            throw new Exception('Shared default origins are managed from the MSP (default) company — here you can hide it from this company instead.');
        }
        if ($owner !== $activeId) {
            throw new Exception('That origin belongs to another company.');
        }
        $check = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND origin_id = ? AND closed_datetime IS NULL");
        $check->execute([$activeId, $id]);
        if ((int)$check->fetchColumn() > 0) {
            throw new Exception('Open tickets still use this origin — reassign or close them first.');
        }
    }

    $stmt = $conn->prepare("DELETE FROM ticket_origins WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Ticket origin deleted successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
