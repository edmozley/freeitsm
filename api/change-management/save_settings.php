<?php
/**
 * API Endpoint: Save Change Management Settings
 * Saves module settings to system_settings table
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');

$input = json_decode(file_get_contents('php://input'), true);
$settings = $input['settings'] ?? [];

if (empty($settings)) {
    echo json_encode(['success' => false, 'error' => 'No settings provided']);
    exit;
}

try {
    $conn = connectToDatabase();

    $allowed = ['field_visibility'];

    foreach ($settings as $key => $value) {
        if (!in_array($key, $allowed)) continue;

        $dbKey = 'change_' . $key;

        // JSON-encode object values
        if (is_array($value)) {
            $value = json_encode($value);
        }

        // UPSERT: try update first, then insert
        $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->execute([$value, $dbKey]);

        if ($stmt->rowCount() === 0) {
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$dbKey, $value]);
        }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
