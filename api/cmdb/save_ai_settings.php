<?php
/**
 * API: Save CMDB AI custom instructions.
 *
 * Provider / model / API key are now owned by the shared AI panel
 * (api/system/ai/save_settings.php, ns=cmdb_ai). This endpoint only persists
 * the CMDB-specific custom-instructions text appended to the system prompt.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('cmdb');

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $customInstructions = (string)($input['custom_instructions'] ?? '');

    if (mb_strlen($customInstructions) > 4000) {
        throw new Exception('Custom instructions are too long (max 4000 characters)');
    }

    $conn = connectToDatabase();
    $stmt = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value)
              VALUES ('cmdb_ai_custom_instructions', :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([':v' => trim($customInstructions)]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
