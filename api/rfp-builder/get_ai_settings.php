<?php
/**
 * Return the RFP Builder AI settings (provider, model, masked api key).
 * The api key is decrypted then masked before sending — plaintext never
 * leaves the server.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

const RFP_AI_KEYS = ['rfp_ai_provider', 'rfp_ai_api_key', 'rfp_ai_model', 'rfp_ai_verify_ssl'];

try {
    $conn = connectToDatabase();

    $placeholders = implode(',', array_fill(0, count(RFP_AI_KEYS), '?'));
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute(RFP_AI_KEYS);

    $values = [
        'rfp_ai_provider'   => '',
        'rfp_ai_api_key'    => '',
        'rfp_ai_model'      => '',
        'rfp_ai_verify_ssl' => '',
    ];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = $row['setting_value'];
        if (isEncryptedSettingKey($row['setting_key'])) {
            $value = decryptValue($value);
        }
        if (isMaskedSettingKey($row['setting_key'])) {
            $value = maskSecret($value);
        }
        $values[$row['setting_key']] = $value;
    }

    echo json_encode([
        'success'  => true,
        'settings' => $values,
        'has_key'  => $values['rfp_ai_api_key'] !== '',
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
