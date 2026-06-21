<?php
/**
 * API: Get the SLA state of a single ticket.
 *
 * Thin wrapper around includes/sla.php's sla_get_state(). The same function
 * will also be used directly from the inbox list-render path (for the
 * time-to-breach column) — but for the reading pane we hit this endpoint
 * separately so the page-load isn't blocked by SLA computation for every
 * ticket that's been rendered server-side.
 *
 * GET ?ticket_id=N
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/sla.php';
require_once '../../includes/tenancy.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
if (!$ticketId) {
    echo json_encode(['success' => false, 'error' => 'ticket_id required']);
    exit;
}

try {
    $conn = connectToDatabase();
    // Multi-tenancy: don't reveal SLA state for a company this analyst can't access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }
    $state = sla_get_state($conn, $ticketId);
    echo json_encode(['success' => true, 'sla' => $state]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
