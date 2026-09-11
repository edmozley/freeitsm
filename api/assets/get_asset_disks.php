<?php
/**
 * API Endpoint: Get disks for a specific asset
 *
 * Returns two lists, because a machine has two kinds of disk and they answer
 * different questions:
 *   `disks`           — the lettered volumes, with size, free space and usage
 *   `physical_disks`  — the drives themselves, with model, SERIAL and interface
 *
 * The physical list is what discussion #97 asked for: the serial you quote on a
 * warranty claim or a disposal certificate. The agent has always sent it; there
 * was simply nowhere to put it until asset_physical_disks existed.
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

    $sql = "SELECT drive, label, file_system, size_bytes, free_bytes, used_percent
            FROM asset_disks
            WHERE asset_id = ?
            ORDER BY drive ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$asset_id]);
    $disks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // The physical drives. Absent until Database Verification has created the
    // table, and an install that has pulled the update but not yet verified must
    // still get its volumes rather than an "Unknown table" error where the whole
    // Storage section used to be — the same guard the asset list uses for the
    // asset_tag column.
    $physical = [];
    try {
        $p = $conn->prepare("SELECT model, serial, size_bytes, media_type, interface_type
                               FROM asset_physical_disks
                              WHERE asset_id = ?
                           ORDER BY size_bytes DESC, model ASC");
        $p->execute([$asset_id]);
        $physical = $p->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $physical = [];
    }

    echo json_encode([
        'success' => true,
        'disks' => $disks,
        'physical_disks' => $physical
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
