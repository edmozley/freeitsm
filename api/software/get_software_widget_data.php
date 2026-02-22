<?php
/**
 * API Endpoint: Get aggregated chart data for a software dashboard widget
 * Params: widget_id (required)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$widget_id = $_GET['widget_id'] ?? '';

if (empty($widget_id)) {
    echo json_encode(['success' => false, 'error' => 'widget_id is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get widget definition
    $wStmt = $conn->prepare("SELECT w.aggregate_property, w.app_id, w.exclude_system_components,
                                    COALESCE(a.display_name, '') AS app_name
                             FROM software_dashboard_widgets w
                             LEFT JOIN software_inventory_apps a ON a.id = w.app_id
                             WHERE w.id = ?");
    $wStmt->execute([$widget_id]);
    $widget = $wStmt->fetch(PDO::FETCH_ASSOC);

    if (!$widget) {
        echo json_encode(['success' => false, 'error' => 'Widget not found']);
        exit;
    }

    $prop = $widget['aggregate_property'];
    $response = ['success' => true];

    if ($prop === 'version_distribution') {
        if (empty($widget['app_id'])) {
            echo json_encode(['success' => true, 'labels' => [], 'values' => [], 'app_id' => null, 'app_name' => '']);
            exit;
        }

        $stmt = $conn->prepare("SELECT COALESCE(d.display_version, 'Unknown') AS label, COUNT(DISTINCT d.host_id) AS value
                                FROM software_inventory_detail d
                                WHERE d.app_id = ?
                                GROUP BY d.display_version
                                ORDER BY value DESC
                                LIMIT 20");
        $stmt->execute([$widget['app_id']]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response['labels'] = array_column($data, 'label');
        $response['values'] = array_map('intval', array_column($data, 'value'));
        $response['app_id'] = (int)$widget['app_id'];
        $response['app_name'] = $widget['app_name'];

    } elseif ($prop === 'top_installed') {
        $excludeWhere = $widget['exclude_system_components'] ? ' AND d.system_component = 0' : '';

        $stmt = $conn->query("SELECT a.id AS app_id, a.display_name AS label, COUNT(DISTINCT d.host_id) AS value
                              FROM software_inventory_apps a
                              INNER JOIN software_inventory_detail d ON d.app_id = a.id
                              WHERE 1=1{$excludeWhere}
                              GROUP BY a.id, a.display_name
                              ORDER BY value DESC
                              LIMIT 20");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response['labels'] = array_column($data, 'label');
        $response['values'] = array_map('intval', array_column($data, 'value'));
        $response['app_ids'] = array_map('intval', array_column($data, 'app_id'));

    } elseif ($prop === 'publisher_distribution') {
        $excludeWhere = $widget['exclude_system_components'] ? ' AND d.system_component = 0' : '';

        $stmt = $conn->query("SELECT COALESCE(a.publisher, 'Unknown') AS label, COUNT(DISTINCT d.host_id) AS value
                              FROM software_inventory_apps a
                              INNER JOIN software_inventory_detail d ON d.app_id = a.id
                              WHERE 1=1{$excludeWhere}
                              GROUP BY a.publisher
                              ORDER BY value DESC
                              LIMIT 15");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response['labels'] = array_column($data, 'label');
        $response['values'] = array_map('intval', array_column($data, 'value'));

    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid aggregate property']);
        exit;
    }

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
