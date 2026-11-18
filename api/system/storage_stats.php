<?php
/**
 * System -> Storage usage: the file count and size of ONE folder on the list.
 *
 * GET ?key=<row key from storageInventory()>. Administrators only. Takes a key,
 * never a path: the list is rebuilt here and only a folder on it is counted, so
 * this cannot be used to look anywhere else on the server. Read only.
 */
session_start();
require_once '../../config.php';
header('Content-Type: application/json');
require_once '../../includes/admin_api_guard.php';
require_once '../../includes/storage_inventory.php';

try {
    $conn = connectToDatabase();
    $key = (string)($_GET['key'] ?? '');
    $row = null;
    foreach (storageInventory($conn) as $r) {
        if (hash_equals($r['key'], $key)) { $row = $r; break; }
    }
    if ($row === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Unknown folder']);
        exit;
    }
    session_write_close();   // counting can take seconds; don't hold the session lock meanwhile
    $s = storageFolderStats($row['path']);
    echo json_encode(['success' => true, 'key' => $row['key']] + $s);
} catch (Throwable $e) {
    error_log('storage_stats: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not count this folder']);
}
