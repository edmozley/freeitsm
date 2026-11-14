<?php
/**
 * API (GET): What the portal's New ticket form asks a requester (#179).
 *
 *   types  - the ticket types to choose from. Offered only when the choice
 *            matters: when at least one field the requester is asked belongs to
 *            particular types. Otherwise empty, and the form shows no picker - a
 *            question that changes nothing is not worth asking.
 *   fields - the custom fields the requester is asked for the chosen ?type_id=
 *            (fields on every type are asked whatever the type).
 *
 * Only fields marked "Ask the requester on the portal", and only for the
 * requester's own company: nothing an analyst keeps to themselves leaks here.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/ticket_fields.php';
require_once '../../includes/self_service_ticket_types.php';
require_once '../../includes/ticket_priorities.php';

header('Content-Type: application/json');
if (!isset($_SESSION['ss_user_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }

try {
    $conn = connectToDatabase();
    // GH #228: the "Raise with" company, honoured only if it was offered.
    $tenantId = selfServiceTicketTenant($conn, (int)$_SESSION['ss_user_id'],
        !empty($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : null);
    $types = selfServiceTicketTypes($conn, $tenantId);

    $typeId = isset($_GET['type_id']) && $_GET['type_id'] !== '' ? (int)$_GET['type_id'] : null;
    if ($typeId !== null && !isset($types[$typeId])) $typeId = null;   // not one they were offered

    $fields = [];
    foreach (TicketFieldsService::fieldsFor($conn, $tenantId, $typeId, 'requester') as $d) {
        $fields[] = TicketFieldsService::present($d) + ['value' => null];
    }
    echo json_encode([
        'success' => true,
        'types'   => selfServiceTicketTypeMatters($conn, $tenantId) ? array_values($types) : [],
        'fields'  => $fields,
        // The company's priorities, for the Priority picker when "Raise with" changes it.
        'priorities'          => array_map(fn(array $p): array => ['id' => $p['id'], 'name' => $p['name']], ticketPrioritiesFor($conn, $tenantId)),
        'default_priority_id' => ticketPriorityDefaultId($conn, $tenantId),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Could not load the form']);
}
