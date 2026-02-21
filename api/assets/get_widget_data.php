<?php
/**
 * API Endpoint: Get aggregated data for a dashboard widget
 * Params: widget_id (required), status_id (optional filter)
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
$status_id = $_GET['status_id'] ?? '';

if (empty($widget_id)) {
    echo json_encode(['success' => false, 'error' => 'widget_id is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get widget definition
    $wStmt = $conn->prepare("SELECT aggregate_property, is_status_filterable, default_status_id FROM asset_dashboard_widgets WHERE id = ?");
    $wStmt->execute([$widget_id]);
    $widget = $wStmt->fetch(PDO::FETCH_ASSOC);

    if (!$widget) {
        echo json_encode(['success' => false, 'error' => 'Widget not found']);
        exit;
    }

    $prop = $widget['aggregate_property'];
    $params = [];
    $where = '';

    // Apply status filter
    if (!empty($status_id) && $widget['is_status_filterable']) {
        $where = ' WHERE a.asset_status_id = ?';
        $params[] = $status_id;
    } elseif (!$widget['is_status_filterable'] && $widget['default_status_id']) {
        $where = ' WHERE a.asset_status_id = ?';
        $params[] = $widget['default_status_id'];
    }

    // Build aggregation query based on property type
    if ($prop === 'asset_type_id') {
        $sql = "SELECT COALESCE(aty.name, 'Unassigned') AS label, COUNT(*) AS value
                FROM assets a
                LEFT JOIN asset_types aty ON aty.id = a.asset_type_id
                {$where}
                GROUP BY aty.name
                ORDER BY value DESC";
    } elseif ($prop === 'asset_status_id') {
        $sql = "SELECT COALESCE(ast.name, 'Unassigned') AS label, COUNT(*) AS value
                FROM assets a
                LEFT JOIN asset_status_types ast ON ast.id = a.asset_status_id
                {$where}
                GROUP BY ast.name
                ORDER BY value DESC";
    } elseif ($prop === 'memory') {
        $sql = "SELECT
                    CASE
                        WHEN a.memory IS NULL OR a.memory = 0 THEN 'Unknown'
                        WHEN a.memory < 4294967296 THEN '< 4 GB'
                        WHEN a.memory < 8589934592 THEN '4 GB'
                        WHEN a.memory < 17179869184 THEN '8 GB'
                        WHEN a.memory < 34359738368 THEN '16 GB'
                        WHEN a.memory < 68719476736 THEN '32 GB'
                        ELSE '64 GB+'
                    END AS label,
                    COUNT(*) AS value
                FROM assets a
                {$where}
                GROUP BY label
                ORDER BY
                    CASE label
                        WHEN 'Unknown' THEN 0
                        WHEN '< 4 GB' THEN 1
                        WHEN '4 GB' THEN 2
                        WHEN '8 GB' THEN 3
                        WHEN '16 GB' THEN 4
                        WHEN '32 GB' THEN 5
                        WHEN '64 GB+' THEN 6
                    END";
    } else {
        // Simple GROUP BY on the column
        $allowedColumns = [
            'operating_system', 'manufacturer', 'model', 'feature_release',
            'domain', 'cpu_name', 'gpu_name', 'tpm_version',
            'bitlocker_status', 'bios_version'
        ];

        if (!in_array($prop, $allowedColumns)) {
            echo json_encode(['success' => false, 'error' => 'Invalid aggregate property']);
            exit;
        }

        $sql = "SELECT COALESCE(a.{$prop}, 'Unknown') AS label, COUNT(*) AS value
                FROM assets a
                {$where}
                GROUP BY a.{$prop}
                ORDER BY value DESC";
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'labels' => array_column($data, 'label'),
        'values' => array_map('intval', array_column($data, 'value'))
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
