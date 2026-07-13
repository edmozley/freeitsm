<?php
/**
 * API Endpoint: Save system settings
 *
 * A GENERIC key/value writer, used by the five settings tabs that have no endpoint of
 * their own (Assets warranty/vCenter/Intune, Tickets general, System colours/security/SSO).
 *
 * Because it is generic, it CANNOT be authorised with a guard at the top of the file —
 * one guard would have to cover five different callers with five different audiences.
 * It used to check only that you were logged in, which meant any analyst could post any
 * key and rewrite vCenter/Intune credentials, the SSO config, or the brute-force lockout
 * policy. So authorisation is done PER KEY instead: includes/settings_keys.php says who
 * owns each key, and a key nobody owns is refused outright.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/rbac.php';
require_once '../../includes/settings_keys.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['settings']) || !is_array($data['settings'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$settings = $data['settings'];

try {
    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];

    // Authorise EVERY key BEFORE writing ANY of them, so a partly-permitted post is
    // rejected whole rather than half-applied. Fail closed on anything unrecognised.
    foreach (array_keys($settings) as $key) {
        $owner = settingKeyOwner((string) $key);
        if ($owner === null) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Unknown setting: ' . $key . '. This setting is not writable here.',
            ]);
            exit;
        }
        if (!analystCanWriteSettingKey($conn, $analystId, (string) $key)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error'   => 'You do not have permission to change this setting: ' . $key,
            ]);
            exit;
        }
    }

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
