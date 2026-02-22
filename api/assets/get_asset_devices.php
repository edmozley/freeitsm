<?php
/**
 * API Endpoint: Get devices for an asset
 * GET: ?asset_id=123
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$assetId = $_GET['asset_id'] ?? '';

if (empty($assetId)) {
    echo json_encode(['success' => false, 'error' => 'asset_id is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("
        SELECT id, device_class, device_name, status, manufacturer, driver_version, driver_date
        FROM asset_devices
        WHERE asset_id = ?
        ORDER BY device_class, device_name
    ");
    $stmt->execute([$assetId]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'devices' => $devices]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
