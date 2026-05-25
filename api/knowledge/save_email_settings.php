<?php
/**
 * API Endpoint: Save knowledge email settings
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$settings = $input['settings'] ?? null;

if (!$settings) {
    echo json_encode(['success' => false, 'error' => 'Settings required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Settings to save (prefix with knowledge_email_)
    $settingsToSave = [
        'knowledge_email_method' => $settings['email_method'] ?? 'disabled',
        'knowledge_email_smtp_host' => $settings['smtp_host'] ?? '',
        'knowledge_email_smtp_port' => $settings['smtp_port'] ?? '587',
        'knowledge_email_smtp_encryption' => $settings['smtp_encryption'] ?? 'tls',
        'knowledge_email_smtp_auth' => $settings['smtp_auth'] ?? 'yes',
        'knowledge_email_smtp_username' => $settings['smtp_username'] ?? '',
        'knowledge_email_smtp_from_email' => $settings['smtp_from_email'] ?? '',
        'knowledge_email_smtp_from_name' => $settings['smtp_from_name'] ?? '',
        'knowledge_email_mailbox_id' => $settings['mailbox_id'] ?? ''
    ];

    // Only update password if provided (not empty)
    if (!empty($settings['smtp_password'])) {
        $settingsToSave['knowledge_email_smtp_password'] = $settings['smtp_password'];
    }

    // AI API key (saved separately, not prefixed with knowledge_email_) - encrypted at rest
    if (isset($settings['ai_api_key']) && !empty($settings['ai_api_key'])) {
        $settingsToSave['knowledge_ai_api_key'] = encryptValue($settings['ai_api_key']);
    }

    // OpenAI API key for embeddings - encrypted at rest
    if (isset($settings['openai_api_key']) && !empty($settings['openai_api_key'])) {
        $settingsToSave['knowledge_openai_api_key'] = encryptValue($settings['openai_api_key']);
    }

    // Recycle bin retention days
    if (isset($settings['recycle_bin_days'])) {
        $days = max(0, min(999, (int)$settings['recycle_bin_days']));
        $settingsToSave['knowledge_recycle_bin_days'] = (string)$days;
    }

    // Use UPDATE/INSERT upsert pattern
    foreach ($settingsToSave as $key => $value) {
        // Try to update first
        $updateSql = "UPDATE system_settings SET setting_value = ?, updated_datetime = UTC_TIMESTAMP() WHERE setting_key = ?";
        $stmt = $conn->prepare($updateSql);
        $stmt->execute([$value, $key]);

        // If no rows affected, insert
        if ($stmt->rowCount() === 0) {
            $insertSql = "INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP())";
            $stmt = $conn->prepare($insertSql);
            $stmt->execute([$key, $value]);
        }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
