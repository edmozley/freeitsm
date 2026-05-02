<?php
/**
 * API Endpoint: Save system settings
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['settings'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$settings = $data['settings'];

try {
    $conn = connectToDatabase();

    foreach ($settings as $key => $value) {
        // For masked secret keys, treat blank or asterisk-prefixed values as
        // "leave existing untouched" so the user can submit the form without
        // re-entering the secret every time.
        if (isMaskedSettingKey($key) && isMaskedNoChangeValue($value)) {
            continue;
        }

        // Encrypt sensitive values before storing
        if (isEncryptedSettingKey($key) && $value !== '') {
            $value = encryptValue($value);
        }

        // Check if key exists
        $checkStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM system_settings WHERE setting_key = ?");
        $checkStmt->execute([$key]);
        $row = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($row['cnt'] > 0) {
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ?, updated_datetime = UTC_TIMESTAMP() WHERE setting_key = ?");
            $stmt->execute([$value, $key]);
        } else {
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP())");
            $stmt->execute([$key, $value]);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
