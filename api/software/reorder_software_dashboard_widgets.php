<?php
/**
 * API Endpoint: Reorder analyst's software dashboard widgets
 * POST: { order: [widget_id, widget_id, ...] }
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
$order = $data['order'] ?? [];

if (empty($order) || !is_array($order)) {
    echo json_encode(['success' => false, 'error' => 'order array is required']);
    exit;
}

$analyst_id = $_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("UPDATE analyst_software_dashboard_widgets SET sort_order = ? WHERE analyst_id = ? AND widget_id = ?");

    foreach ($order as $index => $widget_id) {
        $stmt->execute([$index, $analyst_id, $widget_id]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
