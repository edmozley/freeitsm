<?php
/**
 * API Endpoint: Remove a widget from analyst's ticket dashboard
 * POST: { widget_id: int }
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
$widget_id = $data['widget_id'] ?? '';

if (empty($widget_id)) {
    echo json_encode(['success' => false, 'error' => 'widget_id is required']);
    exit;
}

$analyst_id = $_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("DELETE FROM analyst_ticket_dashboard_widgets WHERE analyst_id = ? AND widget_id = ?");
    $stmt->execute([$analyst_id, $widget_id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
