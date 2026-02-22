<?php
/**
 * API Endpoint: Get all active software dashboard widgets from the library
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->query("SELECT w.id, w.title, w.description, w.chart_type, w.aggregate_property,
                                 w.app_id, w.exclude_system_components, w.display_order,
                                 COALESCE(a.display_name, '') AS app_name
                          FROM software_dashboard_widgets w
                          LEFT JOIN software_inventory_apps a ON a.id = w.app_id
                          WHERE w.is_active = 1
                          ORDER BY w.display_order ASC");
    $widgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'widgets' => $widgets]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
