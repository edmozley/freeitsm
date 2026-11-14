<?php
/**
 * API Endpoint: Delete ticket priority
 * Refuses if the priority is in use by any ticket, or if it's the default.
 *
 * A shared priority needs access to every company, a company's own needs access to that company.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_priorities.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

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
    $ctx = ticketPriorityScopeContext($conn, (int)$_SESSION['analyst_id']);

    $cur = $conn->prepare("SELECT tenant_id, is_default FROM ticket_priorities WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception(t('tickets.settings.priorities.not_found'));
    }
    ticketPriorityRequireAccess($ctx, $row['tenant_id'] === null ? null : (int)$row['tenant_id']);

    if ((int)$row['is_default'] === 1) {
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
