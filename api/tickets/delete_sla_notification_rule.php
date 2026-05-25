<?php
/**
 * API: Delete an SLA breach notification rule.
 * POST { id: <int> } — soft-delete not used (rules can be toggled inactive
 * instead if the admin wants to keep the config).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();
    $stmt = $conn->prepare("DELETE FROM sla_notification_rules WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
