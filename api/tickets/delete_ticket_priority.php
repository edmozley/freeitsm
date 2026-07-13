<?php
/**
 * API Endpoint: Delete ticket priority
 * Refuses if the priority is in use by any ticket, or if it's the default.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_PRIORITIES);   // settings tab — see docs/design/rbac.md

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if (!$id) {
        throw new Exception('Priority ID is required');
    }

    $conn = connectToDatabase();

    $isDefault = (int) $conn->query("SELECT is_default FROM ticket_priorities WHERE id = " . (int)$id)->fetchColumn();
    if ($isDefault === 1) {
        throw new Exception('Cannot delete the default priority. Set another priority as default first.');
    }

    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE priority_id = ?");
    $checkStmt->execute([$id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        throw new Exception("Cannot delete: this priority is used by $count ticket(s). Reassign them or set the priority to inactive instead.");
    }

    $name = $conn->query("SELECT name FROM ticket_priorities WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM ticket_priorities WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('ticket_priority', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
