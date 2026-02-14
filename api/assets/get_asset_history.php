<?php
/**
 * API Endpoint: Get asset change history
 * Returns all history entries for an asset
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$assetId = $_GET['asset_id'] ?? null;

if (!$assetId) {
    echo json_encode(['success' => false, 'error' => 'Asset ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT
                ah.id,
                ah.asset_id,
                ah.field_name,
                ah.old_value,
                ah.new_value,
                ah.created_datetime,
                a.full_name as analyst_name
            FROM asset_history ah
            LEFT JOIN analysts a ON ah.analyst_id = a.id
            WHERE ah.asset_id = ?
            ORDER BY ah.created_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$assetId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'history' => $history
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
