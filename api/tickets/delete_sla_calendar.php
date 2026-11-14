<?php
/**
 * API: Delete a business calendar (hard delete; cascade clears hours + holidays).
 *
 * Refuses to delete a calendar that's still referenced by a ticket_priorities row
 * (would leave priorities with a dangling SLA target).
 * A shared calendar needs access to every company, a company's own needs access to that company.
 *
 * POST JSON: { id }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/i18n.php';
require_once '../../includes/ticket_priorities.php';
I18n::initFromSession();

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_SLA);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if (!$id) throw new Exception('Calendar id required');

    $conn = connectToDatabase();

    $cur = $conn->prepare("SELECT tenant_id FROM sla_calendars WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception(t('tickets.settings.sla_calendars.not_found'));
    tenantScopeRequireAccess(ticketPriorityScopeContext($conn, (int)$_SESSION['analyst_id']), $row['tenant_id'] === null ? null : (int)$row['tenant_id'], 'tickets.settings.sla_calendars');

    // Block delete if any priority references this calendar
    $check = $conn->prepare("SELECT COUNT(*) FROM ticket_priorities WHERE sla_calendar_id = ?");
    $check->execute([$id]);
    $refCount = (int)$check->fetchColumn();
    if ($refCount > 0) {
        throw new Exception("Calendar is in use by $refCount priority/priorities. Reassign them first.");
    }

    // ON DELETE CASCADE on the FK handles hours + holidays
    $stmt = $conn->prepare("DELETE FROM sla_calendars WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
