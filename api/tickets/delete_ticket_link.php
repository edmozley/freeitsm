<?php
/**
 * API Endpoint: Remove a ticket-to-ticket link (issue #38). POST { link_id }.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ticket_links.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$linkId = (int)($input['link_id'] ?? 0);

try {
    $conn = connectToDatabase();
    echo json_encode(ticketLinkRemove($conn, (int)$_SESSION['analyst_id'], $linkId));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
