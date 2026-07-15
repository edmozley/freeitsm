<?php
/**
 * API Endpoint: Get software installed on a specific asset
 * Returns list of applications with version, install date, and last seen
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$asset_id = $_GET['asset_id'] ?? '';

if (empty($asset_id)) {
    echo json_encode(['success' => false, 'error' => 'asset_id is required']);
    exit;
}

try {
    require_once '../../includes/tenancy.php';
    $conn = connectToDatabase();
    // Multi-tenancy: only serve child data for an asset in this analyst's companies.
    if (!analystCanAccessAsset($conn, (int)$_SESSION['analyst_id'], (int)$asset_id)) {
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit;
    }

    $sql = "SELECT
                a.display_name,
                a.publisher,
                d.display_version,
                d.install_date,
                d.system_component,
                DATE_FORMAT(d.last_seen, '%Y-%m-%d') as last_seen
            FROM software_inventory_detail d
            INNER JOIN software_inventory_apps a ON a.id = d.app_id
            WHERE d.host_id = ?
            ORDER BY a.display_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$asset_id]);
    $software = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'software' => $software
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
