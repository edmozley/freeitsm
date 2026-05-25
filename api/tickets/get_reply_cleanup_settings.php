<?php
/**
 * API: Get Reply Cleanup AI settings
 * Returns the configured model + tone, plus a masked preview of the API key
 * so the analyst can tell whether one is set without leaking the key itself.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/reply_cleanup_prompt.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT setting_key, setting_value FROM system_settings
          WHERE setting_key IN ('tickets_reply_cleanup_api_key',
                                'tickets_reply_cleanup_model',
                                'tickets_reply_cleanup_tone',
                                'tickets_reply_cleanup_custom_instructions')"
    );
    $stmt->execute();

    $values = ['tickets_reply_cleanup_api_key'             => '',
               'tickets_reply_cleanup_model'               => '',
               'tickets_reply_cleanup_tone'                => '',
               'tickets_reply_cleanup_custom_instructions' => ''];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['setting_key'];
        $val = $row['setting_value'];
        if (isEncryptedSettingKey($key) && $val !== '' && $val !== null) {
            $val = decryptValue($val);
        }
        $values[$key] = $val ?? '';
    }

    $apiKey = $values['tickets_reply_cleanup_api_key'];
    $apiKeyMasked = $apiKey !== '' ? maskSecret($apiKey) : '';
    $tone = $values['tickets_reply_cleanup_tone'] ?: 'Friendly';

    // Build the prompt with placeholder values so the settings page can show
    // a faithful read-only preview of what Claude actually sees. The greeting
    // name is shown as "[requester first name]" and the tone reflects the
    // currently-saved tone selection. Custom instructions are NOT injected here
    // because the page renders them in a separate textarea.
    $promptPreview = buildReplyCleanupSystemPrompt(
        '[requester first name]',
        replyCleanupToneDescription($tone),
        ''
    );

    echo json_encode([
        'success'             => true,
        'api_key_masked'      => $apiKeyMasked,
        'has_api_key'         => $apiKey !== '',
        'model'               => $values['tickets_reply_cleanup_model'] ?: 'claude-haiku-4-5-20251001',
        'tone'                => $tone,
        'custom_instructions' => $values['tickets_reply_cleanup_custom_instructions'],
        'prompt_preview'      => $promptPreview,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
