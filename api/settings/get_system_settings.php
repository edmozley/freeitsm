<?php
/**
 * API Endpoint: Get system settings
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT setting_key, setting_value FROM system_settings";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert to key-value object, decrypting sensitive values, then masking
    // any true secrets so plaintext credentials never leave the server.
    $settings = [];
    foreach ($rows as $row) {
        $value = $row['setting_value'];
        if (isEncryptedSettingKey($row['setting_key'])) {
            $value = decryptValue($value);
        }
        if (isMaskedSettingKey($row['setting_key'])) {
            $value = maskSecret($value);
        }
        $settings[$row['setting_key']] = $value;
    }

    echo json_encode(['success' => true, 'settings' => $settings]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
