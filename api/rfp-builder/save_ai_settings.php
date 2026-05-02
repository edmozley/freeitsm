<?php
/**
 * Save the RFP Builder AI settings (provider, model, api key).
 * The api key is encrypted at rest. Submitting an empty or masked
 * value (e.g. "****abcd") leaves the existing stored key unchanged.
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

$VALID_PROVIDERS = ['anthropic', 'openai'];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid request');
    }

    $provider  = $data['provider']   ?? '';
    $apiKey    = $data['api_key']    ?? '';
    $model     = trim($data['model'] ?? '');
    $verifySsl = isset($data['verify_ssl']) && $data['verify_ssl'] === '0' ? '0' : '1';

    if (!in_array($provider, $VALID_PROVIDERS, true)) {
        throw new Exception('Provider must be anthropic or openai');
    }
    if ($model === '') {
        throw new Exception('Model is required');
    }

    $conn = connectToDatabase();
    $writes = [
        'rfp_ai_provider'   => $provider,
        'rfp_ai_model'      => $model,
        'rfp_ai_verify_ssl' => $verifySsl,
    ];

    // Only persist a new api key if the user actually supplied one.
    if (!isMaskedNoChangeValue($apiKey)) {
        $writes['rfp_ai_api_key'] = encryptValue($apiKey);
    }

    foreach ($writes as $key => $value) {
        $check = $conn->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $check->execute([$key]);
        if ((int)$check->fetchColumn() > 0) {
            $upd = $conn->prepare("UPDATE system_settings SET setting_value = ?, updated_datetime = UTC_TIMESTAMP() WHERE setting_key = ?");
            $upd->execute([$value, $key]);
        } else {
            $ins = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP())");
            $ins->execute([$key, $value]);
        }
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
