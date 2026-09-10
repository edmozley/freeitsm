<?php
/**
 * API Endpoint: rebuild the software-renewal calendar events on demand (#1551).
 *
 * Called when the surface setting is saved, so the calendar matches the choice
 * immediately rather than at the next licence edit. The work lives in
 * includes/software_licence_calendar.php — the twin of
 * api/assets/sync_warranty_calendar.php, which does the same job for warranties.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/software_licence_calendar.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('software');
requireCapabilityJson(Cap::SOFTWARE_RENEWALS);

try {
    $conn = connectToDatabase();
    echo json_encode(syncSoftwareLicenceCalendar($conn));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
