<?php
/**
 * API Endpoint: test a messaging channel's connection to its provider.
 *
 * Makes a lightweight, read-only API call (no message is sent) to verify the
 * stored credentials are valid. Returns a short success detail or an error.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/messaging/messaging.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $channelId = (int) ($input['id'] ?? $_GET['id'] ?? 0);
    if ($channelId <= 0) {
        throw new Exception('Channel ID is required');
    }

    $conn = connectToDatabase();
    $channel = loadMessagingChannel($conn, $channelId);
    if (!$channel) {
        throw new Exception('Channel not found');
    }

    $provider = messagingProvider($channel);
    $detail = $provider->testConnection();

    echo json_encode(['success' => true, 'message' => $detail]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
