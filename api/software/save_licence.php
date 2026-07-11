<?php
/**
 * API Endpoint: Save Software Licence.
 * Thin UI adapter over SoftwareService — creates or updates a licence record.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/software.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('software');

try {
    $conn  = connectToDatabase();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    // The UI already sends canonical snake_case keys (id, app_id, licence_type,
    // licence_key, quantity, cost, currency, purchase_date, renewal_date,
    // notice_period_days, status, portal_url, vendor_contact, notes).
    $res = SoftwareService::saveLicence($conn, ActorContext::fromSession($conn), $input);
    echo json_encode([
        'success' => true,
        'message' => $res['created'] ? 'Licence created' : 'Licence updated',
        'id'      => $res['id'],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
