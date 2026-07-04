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

$in = json_decode(file_get_contents('php://input'), true);
$id = isset($in['id']) ? (int)$in['id'] : 0;
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing id']);
    exit;
}

try {
    $conn = connectToDatabase();
    $ok = webhookReplay($conn, $id);
    echo json_encode(['success' => $ok, 'error' => $ok ? null : 'Delivery not found or not replayable']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
