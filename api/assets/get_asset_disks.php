<?php
/**
 * API Endpoint: Get disks for a specific asset
 * Returns list of logical drives with size, free space, and usage percentage
 */
session_start();
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
    $conn = connectToDatabase();

    $sql = "SELECT drive, label, file_system, size_bytes, free_bytes, used_percent
            FROM asset_disks
            WHERE asset_id = ?
            ORDER BY drive ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$asset_id]);
    $disks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'disks' => $disks
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
