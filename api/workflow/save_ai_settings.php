<?php
/**
 * API: Save the workflow AI settings.
 *
 * Validates provider ∈ {anthropic, openai} and that a model is set. The API
 * key is encrypted at rest (the `workflow_ai_api_key` entry in
 * ENCRYPTED_SETTING_KEYS triggers encryptValue() automatically on write).
 *
 * Incoming `api_key` that's empty or starts with asterisks ("****abcd")
 * means "leave the stored value alone" — same masked-no-change pattern
 * the other settings pages use.
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
requireModuleAccessJson('workflow');

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

    // Provider, model, verify_ssl — straight upserts.
    $upsert = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
         VALUES (?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()"
    );
    $upsert->execute(['workflow_ai_provider',   $provider]);
    $upsert->execute(['workflow_ai_model',      $model]);
    $upsert->execute(['workflow_ai_verify_ssl', $verifySsl]);

    // API key — only write if the user provided a fresh one. The masked
    // placeholder (or empty) means "keep the stored key unchanged".
    if (!isMaskedNoChangeValue($apiKey)) {
        // encryptValue() runs in functions.php's saveSystemSetting wrapper
        // but we're doing direct upserts here, so encrypt explicitly.
        $encrypted = encryptValue($apiKey);
        $upsert->execute(['workflow_ai_api_key', $encrypted]);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
