<?php
/**
 * API: Watchtower Dashboard — Unified attention summary across all modules
 * GET — Returns attention items from every module in a single response
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/watchtower_queries.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $data = getWatchtowerData($conn);

    echo json_encode(array_merge(
        ['success' => true, 'generated_at' => gmdate('Y-m-d\TH:i:s\Z')],
        $data
    ));

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
