<?php
/**
 * API Endpoint: Check-in / check-out custody trail for an asset.
 *
 * Returns the chronological log of checkout (assign) and checkin (unassign)
 * events — who held the asset, when, the due-back date, and which analyst
 * actioned it. Newest first.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$assetId = $_GET['asset_id'] ?? null;
if (!$assetId) {
    echo json_encode(['success' => false, 'error' => 'Asset ID is required']);
    exit;
}

try {
    require_once '../../includes/tenancy.php';
    $conn = connectToDatabase();
    // Multi-tenancy: only serve child data for an asset in this analyst's companies.
    if (!analystCanAccessAsset($conn, (int)$_SESSION['analyst_id'], (int)$assetId)) {
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit;
    }

    // Table may not exist until DB verification has run.
    $check = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'asset_checkout_log'");
    $check->execute([DB_NAME]);
    if ((int)$check->fetchColumn() === 0) {
        echo json_encode(['success' => true, 'log' => []]);
        exit;
    }

    $sql = "SELECT cl.id, cl.action, cl.user_name, cl.expected_return_date, cl.notes, cl.action_datetime,
                   an.full_name AS analyst_name
            FROM asset_checkout_log cl
            LEFT JOIN analysts an ON an.id = cl.analyst_id
            WHERE cl.asset_id = ?
            ORDER BY cl.action_datetime DESC, cl.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$assetId]);

    echo json_encode(['success' => true, 'log' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
