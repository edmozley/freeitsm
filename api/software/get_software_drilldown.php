<?php
/**
 * API Endpoint: Get drill-down data for a software dashboard chart click
 * Params: widget_id (required), plus one of: version+app_id, app_id alone, or publisher
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
$app_id = $_GET['app_id'] ?? '';
$version = $_GET['version'] ?? '';
$publisher = $_GET['publisher'] ?? '';

if (empty($widget_id)) {
    echo json_encode(['success' => false, 'error' => 'widget_id is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    if (!empty($publisher)) {
        // Publisher drill-down: show applications by this publisher
        $stmt = $conn->prepare("SELECT a.display_name AS app_name, COUNT(DISTINCT d.host_id) AS install_count
                                FROM software_inventory_apps a
                                INNER JOIN software_inventory_detail d ON d.app_id = a.id
                                WHERE a.publisher = ?
                                GROUP BY a.id, a.display_name
                                ORDER BY install_count DESC");
        $stmt->execute([$publisher]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'type' => 'publisher', 'rows' => $rows]);

    } elseif (!empty($app_id) && !empty($version)) {
        // Version drill-down: machines with this specific version
        $stmt = $conn->prepare("SELECT h.hostname, d.display_version, d.install_date,
                                       DATE_FORMAT(d.last_seen, '%Y-%m-%d') AS last_seen
                                FROM software_inventory_detail d
                                INNER JOIN assets h ON h.id = d.host_id
                                WHERE d.app_id = ? AND COALESCE(d.display_version, 'Unknown') = ?
                                ORDER BY h.hostname ASC");
        $stmt->execute([$app_id, $version]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'type' => 'machines', 'rows' => $rows]);

    } elseif (!empty($app_id)) {
        // App drill-down: all machines with this app
        $stmt = $conn->prepare("SELECT h.hostname, d.display_version, d.install_date,
                                       DATE_FORMAT(d.last_seen, '%Y-%m-%d') AS last_seen
                                FROM software_inventory_detail d
                                INNER JOIN assets h ON h.id = d.host_id
                                WHERE d.app_id = ?
                                ORDER BY h.hostname ASC");
        $stmt->execute([$app_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'type' => 'machines', 'rows' => $rows]);

    } else {
        echo json_encode(['success' => false, 'error' => 'Provide app_id, version, or publisher parameter']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
