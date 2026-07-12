<?php
/**
 * API: replay one webhook delivery — resets it to 'pending' so the cron worker
 * sends it again. Body: { id }.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/webhook_delivery.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('workflow');

$in = json_decode(file_get_contents('php://input'), true);
$id = isset($in['id']) ? (int)$in['id'] : 0;
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing id']);
    exit;
}

try {
    $conn = connectToDatabase();
    $ok = webhookReplay($conn, $id);
    // Replay re-sends the STORED body. If retention purged it there is nothing to
    // send, and re-queueing would POST an empty payload to a live endpoint — so
    // say why rather than failing vaguely.
    echo json_encode([
        'success' => $ok,
        'error'   => $ok ? null : (webhookReplayBlockedReason($conn, $id) ?? 'Delivery not found or not replayable'),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
