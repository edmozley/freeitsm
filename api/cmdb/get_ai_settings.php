<?php
/**
 * API: Get CMDB AI integration settings.
 * Returns the masked API key, model, and custom instructions.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT setting_key, setting_value FROM system_settings
          WHERE setting_key IN ('cmdb_ai_api_key', 'cmdb_ai_model', 'cmdb_ai_custom_instructions')"
    );
    $stmt->execute();

    $values = ['cmdb_ai_api_key' => '', 'cmdb_ai_model' => '', 'cmdb_ai_custom_instructions' => ''];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['setting_key'];
        $val = $row['setting_value'];
        if (isEncryptedSettingKey($key) && $val !== '' && $val !== null) {
            $val = decryptValue($val);
        }
        $values[$key] = $val ?? '';
    }

    $apiKey = $values['cmdb_ai_api_key'];
    echo json_encode([
        'success'             => true,
        'api_key_masked'      => $apiKey !== '' ? maskSecret($apiKey) : '',
        'has_api_key'         => $apiKey !== '',
        'model'               => $values['cmdb_ai_model'] ?: 'claude-haiku-4-5-20251001',
        'custom_instructions' => $values['cmdb_ai_custom_instructions'],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
