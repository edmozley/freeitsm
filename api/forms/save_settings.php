<?php
/**
 * API Endpoint: Save forms module settings
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('forms');

$input = json_decode(file_get_contents('php://input'), true);
$settings = $input['settings'] ?? null;

if (!$settings) {
    echo json_encode(['success' => false, 'error' => 'Settings required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $allowed = ['logo_alignment'];
    $validAlignments = ['left', 'center', 'right'];

    foreach ($allowed as $key) {
        if (!isset($settings[$key])) continue;

        $value = $settings[$key];
        if ($key === 'logo_alignment' && !in_array($value, $validAlignments)) {
            $value = 'center';
        }

        $dbKey = 'forms_' . $key;

        $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ?, updated_datetime = UTC_TIMESTAMP() WHERE setting_key = ?");
        $stmt->execute([$value, $dbKey]);

        if ($stmt->rowCount() === 0) {
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP())");
            $stmt->execute([$dbKey, $value]);
        }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
