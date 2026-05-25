<?php
/**
 * API Endpoint: Get knowledge email settings
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

try {
    $conn = connectToDatabase();

    // Get all knowledge email settings
    $sql = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'knowledge_email_%'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert to settings object with cleaned keys
    // Remove 'knowledge_email_' prefix, then rename 'method' to 'email_method' to match JavaScript expectations
    $settings = [];
    foreach ($rows as $row) {
        $key = str_replace('knowledge_email_', '', $row['setting_key']);
        // Method needs to be returned as email_method to match JavaScript expectations
        if ($key === 'method') {
            $key = 'email_method';
        }
        $settings[$key] = $row['setting_value'];
    }

    // Also check if AI API key is set (return masked indicator, not the actual key)
    $aiSql = "SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_ai_api_key'";
    $aiStmt = $conn->prepare($aiSql);
    $aiStmt->execute();
    $aiRow = $aiStmt->fetch(PDO::FETCH_ASSOC);
    if ($aiRow && !empty($aiRow['setting_value'])) {
        $decrypted = decryptValue($aiRow['setting_value']);
        $settings['ai_api_key'] = '****' . substr($decrypted, -4);
    }

    // Check if OpenAI API key is set
    $openaiSql = "SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_openai_api_key'";
    $openaiStmt = $conn->prepare($openaiSql);
    $openaiStmt->execute();
    $openaiRow = $openaiStmt->fetch(PDO::FETCH_ASSOC);
    if ($openaiRow && !empty($openaiRow['setting_value'])) {
        $decrypted = decryptValue($openaiRow['setting_value']);
        $settings['openai_api_key'] = '****' . substr($decrypted, -4);
    }

    // Get recycle bin retention setting
    $recycleSql = "SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_recycle_bin_days'";
    $recycleStmt = $conn->prepare($recycleSql);
    $recycleStmt->execute();
    $recycleRow = $recycleStmt->fetch(PDO::FETCH_ASSOC);
    $settings['recycle_bin_days'] = $recycleRow ? (int)$recycleRow['setting_value'] : 30;

    echo json_encode(['success' => true, 'settings' => $settings]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
