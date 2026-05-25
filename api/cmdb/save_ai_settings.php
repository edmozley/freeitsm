<?php
/**
 * API: Save CMDB AI integration settings.
 * Encrypts the key. Honours masked-no-change so analysts can edit other fields
 * without re-entering the key.
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

const VALID_MODELS = [
    'claude-haiku-4-5-20251001',
    'claude-sonnet-4-6',
    'claude-opus-4-7',
];

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $rawKey = $input['api_key'] ?? '';
    $model = trim($input['model'] ?? '');
    $customInstructions = (string)($input['custom_instructions'] ?? '');

    if (!in_array($model, VALID_MODELS, true)) {
        throw new Exception('Invalid model selection');
    }
    if (mb_strlen($customInstructions) > 4000) {
        throw new Exception('Custom instructions are too long (max 4000 characters)');
    }

    $conn = connectToDatabase();

    $upsert = function (PDO $conn, string $key, string $value): void {
        $stmt = $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value)
                  VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([':k' => $key, ':v' => $value]);
    };

    if (!isMaskedNoChangeValue($rawKey)) {
        $encrypted = encryptValue(trim($rawKey));
        $upsert($conn, 'cmdb_ai_api_key', $encrypted);
    }

    $upsert($conn, 'cmdb_ai_model',               $model);
    $upsert($conn, 'cmdb_ai_custom_instructions', trim($customInstructions));

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
