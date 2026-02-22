<?php
/**
 * API Endpoint: Add a widget to analyst's software dashboard
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

    // Get next sort order
    $maxStmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM analyst_software_dashboard_widgets WHERE analyst_id = ?");
    $maxStmt->execute([$analyst_id]);
    $nextOrder = (int)$maxStmt->fetch(PDO::FETCH_ASSOC)['next_order'];

    $stmt = $conn->prepare("INSERT IGNORE INTO analyst_software_dashboard_widgets (analyst_id, widget_id, sort_order) VALUES (?, ?, ?)");
    $stmt->execute([$analyst_id, $widget_id, $nextOrder]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Widget already on dashboard']);
        exit;
    }

    echo json_encode(['success' => true, 'id' => $conn->lastInsertId()]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
