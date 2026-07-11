<?php
/**
 * API: Save the Forms AI settings.
 *
 * Validates provider ∈ {anthropic, openai} and that a model is set.
 * The API key is encrypted at rest. An incoming `api_key` that's empty
 * or masked ("****abcd") means "leave the stored value alone" — same
 * pattern other modules use.
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
requireModuleAccessJson('forms');

try {
    $data      = json_decode(file_get_contents('php://input'), true) ?: [];
    $provider  = (string)($data['provider'] ?? '');
    $model     = trim((string)($data['model'] ?? ''));
    $apiKey    = (string)($data['api_key'] ?? '');
    $verifySsl = (isset($data['verify_ssl']) && $data['verify_ssl'] === '0') ? '0' : '1';

    if (!in_array($provider, ['anthropic', 'openai'], true)) {
        throw new Exception('Provider must be anthropic or openai');
    }
    if ($model === '') {
        throw new Exception('Model is required');
    }

    $conn = connectToDatabase();

    $upsert = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
         VALUES (?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()"
    );
    $upsert->execute(['forms_ai_provider',   $provider]);
    $upsert->execute(['forms_ai_model',      $model]);
    $upsert->execute(['forms_ai_verify_ssl', $verifySsl]);

    // Only write the API key if a fresh one was provided. Empty or masked
    // ("****abcd") means "keep the existing stored value".
    if (!isMaskedNoChangeValue($apiKey)) {
        $encrypted = encryptValue($apiKey);
        $upsert->execute(['forms_ai_api_key', $encrypted]);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
