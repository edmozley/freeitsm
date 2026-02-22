<?php
/**
 * API Endpoint: Get analyst's saved software dashboard widgets
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analyst_id = $_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT asdw.id, asdw.widget_id, asdw.sort_order,
                                   w.title, w.description, w.chart_type, w.aggregate_property,
                                   w.app_id, w.exclude_system_components,
                                   COALESCE(a.display_name, '') AS app_name
                            FROM analyst_software_dashboard_widgets asdw
                            INNER JOIN software_dashboard_widgets w ON w.id = asdw.widget_id
                            LEFT JOIN software_inventory_apps a ON a.id = w.app_id
                            WHERE asdw.analyst_id = ? AND w.is_active = 1
                            ORDER BY asdw.sort_order ASC");
    $stmt->execute([$analyst_id]);
    $widgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'widgets' => $widgets]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
