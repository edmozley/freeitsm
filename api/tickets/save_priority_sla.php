<?php
/**
 * API: Save the SLA fields on a ticket_priorities row
 * (sla_response_minutes, sla_resolution_minutes, sla_calendar_id).
 *
 * POST JSON: { id, sla_response_minutes, sla_resolution_minutes, sla_calendar_id }
 *
 * Doesn't touch the other ticket_priorities columns (name, colour, etc.) — that's
 * a different endpoint. Pass nulls for minutes to clear targets; pass null
 * sla_calendar_id to detach the calendar.
 *
 * A shared priority needs access to every company, a company's own needs access to that company.
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
// GH #217: refused while the sla feature is switched off for the install.
requireModuleOnJson(connectToDatabase(), 'sla');
requireCapabilityJson(Cap::TICKETS_SLA);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if (!$id) throw new Exception('Priority id required');

    $response   = isset($data['sla_response_minutes'])   && $data['sla_response_minutes']   !== '' ? max(0, (int)$data['sla_response_minutes'])   : null;
    $resolution = isset($data['sla_resolution_minutes']) && $data['sla_resolution_minutes'] !== '' ? max(0, (int)$data['sla_resolution_minutes']) : null;
    $calendarId = isset($data['sla_calendar_id'])        && $data['sla_calendar_id']        !== '' ? (int)$data['sla_calendar_id']                : null;

    $conn = connectToDatabase();

    $owner = $conn->prepare("SELECT " . ticketPriorityTenantCol($conn) . " AS tenant_id FROM ticket_priorities WHERE id = ?");
    $owner->execute([$id]);
    $row = $owner->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception(t('tickets.settings.priorities.not_found'));
    ticketPriorityRequireAccess(ticketPriorityScopeContext($conn, (int)$_SESSION['analyst_id']), $row['tenant_id'] === null ? null : (int)$row['tenant_id']);

    // A shared priority runs on shared calendars only; a company's, on the ones that company can use.
    if ($calendarId !== null && !in_array($calendarId, slaCalendarOptionIds($conn, $row['tenant_id'] === null ? null : (int)$row['tenant_id']), true)) {
        throw new Exception(t($row['tenant_id'] === null ? 'tickets.settings.sla_calendars.not_for_shared' : 'tickets.settings.sla_calendars.not_for_priority'));
    }

    $stmt = $conn->prepare("UPDATE ticket_priorities
                            SET sla_response_minutes = ?, sla_resolution_minutes = ?, sla_calendar_id = ?
                            WHERE id = ?");
    $stmt->execute([$response, $resolution, $calendarId, $id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
