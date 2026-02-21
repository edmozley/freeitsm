<?php
/**
 * API Endpoint: Get analyst's saved dashboard widgets
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

    $stmt = $conn->prepare("SELECT adw.id, adw.widget_id, adw.sort_order, adw.status_filter_id,
                                   w.title, w.description, w.chart_type, w.aggregate_property,
                                   w.is_status_filterable, w.default_status_id
                            FROM analyst_dashboard_widgets adw
                            INNER JOIN asset_dashboard_widgets w ON w.id = adw.widget_id
                            WHERE adw.analyst_id = ? AND w.is_active = 1
                            ORDER BY adw.sort_order ASC");
    $stmt->execute([$analyst_id]);
    $widgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'widgets' => $widgets]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
