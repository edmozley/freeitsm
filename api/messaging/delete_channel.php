<?php
/**
 * API Endpoint: delete a messaging channel. Past tickets/messages keep their
 * channel_id reference (the row just no longer resolves), so history is intact —
 * only new inbound/outbound on this channel stops.
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

// Messaging settings tab.
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_MESSAGING);

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Channel ID is required');
    }

    $conn = connectToDatabase();
    $conn->prepare("DELETE FROM messaging_channels WHERE id = ?")->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Channel deleted']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
