<?php
/**
 * API: return the cached intune_devices row for a given asset_id, if any.
 * Used by the asset detail panel's InTune tab.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0;
if ($assetId <= 0) {
    echo json_encode(['success' => false, 'error' => 'asset_id required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT id, intune_id, device_name, user_principal_name, user_display_name,
                operating_system, os_version, compliance_state, management_state,
                managed_device_owner_type, device_enrollment_type, device_registration_state,
                enrolled_datetime, last_sync_datetime, model, manufacturer, serial_number,
                imei, meid, wifi_mac_address, ethernet_mac_address, azure_ad_device_id,
                is_encrypted, is_supervised, jail_broken,
                total_storage_bytes, free_storage_bytes, last_seen_local
           FROM intune_devices
          WHERE asset_id = ?
          LIMIT 1"
    );
    $stmt->execute([$assetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'device' => $row ?: null]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
