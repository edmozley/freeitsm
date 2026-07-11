<?php
/**
 * API: Save Reply Cleanup tone + custom instructions.
 *
 * Provider / model / API key are now owned by the shared AI panel
 * (api/system/ai/save_settings.php, ns=tickets_reply_cleanup). This endpoint
 * only persists the reply-cleanup-specific tone and custom-instructions text.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

const VALID_TONES = ['Friendly', 'Formal', 'Brief'];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $tone   = trim($input['tone']  ?? '');
    $customInstructions = (string)($input['custom_instructions'] ?? '');

    if (!in_array($tone, VALID_TONES, true)) {
        throw new Exception('Invalid tone selection');
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

    $upsert($conn, 'tickets_reply_cleanup_tone',                $tone);
    $upsert($conn, 'tickets_reply_cleanup_custom_instructions', trim($customInstructions));

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
