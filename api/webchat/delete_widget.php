<?php
/**
 * API Endpoint: delete a website chat widget.
 *
 * Deletes the underlying messaging_channels row; the webchat_widgets config row is
 * removed with it via the ON DELETE CASCADE FK. Past tickets/messages keep their
 * channel_id reference (the row just no longer resolves), so history is intact —
 * only new conversations on this widget stop.
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

// Web chat settings tab.
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_WEBCHAT);

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($data['id'] ?? 0);   // webchat_widgets.id
    if ($id <= 0) {
        throw new Exception('Widget ID is required');
    }

    $conn = connectToDatabase();

    $cur = $conn->prepare("SELECT channel_id FROM webchat_widgets WHERE id = ?");
    $cur->execute([$id]);
    $channelId = $cur->fetchColumn();
    if (!$channelId) {
        throw new Exception('Widget not found');
    }

    // Only a widget this analyst may administer — one belonging to a company they
    // can't reach is framed as not-found rather than confirming it exists.
    if (!analystCanAccessChannel($conn, (int) $_SESSION['analyst_id'], (int) $channelId)) {
        throw new Exception('Widget not found');
    }

    // Delete the channel spine; the webchat_widgets row cascades away with it.
    $conn->prepare("DELETE FROM messaging_channels WHERE id = ? AND channel_type = 'webchat'")
         ->execute([(int) $channelId]);

    echo json_encode(['success' => true, 'message' => 'Widget deleted']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
