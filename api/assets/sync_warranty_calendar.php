<?php
/**
 * API Endpoint: Resync asset warranty events to the calendar.
 *
 * Called after the warranty-alert setting changes so the calendar reflects the
 * new choice immediately (populate when 'calendar'/'both' is selected, clear
 * otherwise). The work lives in includes/asset_warranty_calendar.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/asset_warranty_calendar.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('assets');

try {
    $conn = connectToDatabase();
    echo json_encode(syncAssetWarrantyCalendar($conn));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
