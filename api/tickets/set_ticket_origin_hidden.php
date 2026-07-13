<?php
/**
 * API: Hide / show a global ticket origin for the active company.
 * The "hide" half of the add+hide model (design §7) — mirrors
 * set_ticket_type_hidden.php. POST JSON { ticket_origin_id, hidden: true|false }
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
requireCapabilityJson(Cap::TICKETS_TICKET_ORIGINS);   // settings tab — see docs/design/rbac.md

try {
    $data     = json_decode(file_get_contents('php://input'), true);
    $originId = !empty($data['ticket_origin_id']) ? (int)$data['ticket_origin_id'] : 0;
    $hidden   = !empty($data['hidden']);
    if ($originId <= 0) {
        throw new Exception('Missing ticket origin');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!isMultiTenant($conn)) {
        throw new Exception('Hiding applies only when more than one company exists.');
    }
    $activeId  = getActiveTenantId($conn, $analystId);
    $defaultId = getDefaultTenantId($conn);
    if ($activeId === $defaultId) {
        throw new Exception('Switch to a client company to hide a shared default from it.');
    }

    $cur = $conn->prepare("SELECT tenant_id FROM ticket_origins WHERE id = ?");
    $cur->execute([$originId]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Ticket origin not found');
    }
    if ($row['tenant_id'] !== null) {
        throw new Exception('Only shared default origins are hidden per company.');
    }

    if ($hidden) {
        $g = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND origin_id = ? AND closed_datetime IS NULL");
        $g->execute([$activeId, $originId]);
        if ((int)$g->fetchColumn() > 0) {
            throw new Exception('Open tickets in this company use this origin — reassign or close them first.');
        }
        $ins = $conn->prepare("INSERT IGNORE INTO tenant_config_hidden (tenant_id, entity_type, entity_id) VALUES (?, 'ticket_origin', ?)");
        $ins->execute([$activeId, $originId]);
    } else {
        $del = $conn->prepare("DELETE FROM tenant_config_hidden WHERE tenant_id = ? AND entity_type = 'ticket_origin' AND entity_id = ?");
        $del->execute([$activeId, $originId]);
    }

    echo json_encode(['success' => true, 'hidden' => $hidden]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
