<?php
/**
 * API Endpoint: Soft-delete a dashboard widget
 * POST: { id }
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'] ?? '';

if (empty($id)) {
    echo json_encode(['success' => false, 'error' => 'id is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Remove from all analysts' dashboards first
    $conn->prepare("DELETE FROM analyst_dashboard_widgets WHERE widget_id = ?")->execute([$id]);

    // Soft delete
    $conn->prepare("UPDATE asset_dashboard_widgets SET is_active = 0 WHERE id = ?")->execute([$id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
