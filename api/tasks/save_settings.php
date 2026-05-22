<?php
/**
 * API: Tasks — Save module settings
 * Upserts tasks_* keys into system_settings.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$settings = $input['settings'] ?? [];

if (empty($settings)) {
    echo json_encode(['success' => false, 'error' => 'No settings provided']);
    exit;
}

try {
    $conn = connectToDatabase();

    $allowed = ['calendar_span_mode'];
    // Per-key value whitelists guard against junk
    $valid = [
        'calendar_span_mode' => ['deadline', 'span', 'repeat'],
    ];

    foreach ($settings as $key => $value) {
        if (!in_array($key, $allowed, true)) continue;
        if (isset($valid[$key]) && !in_array($value, $valid[$key], true)) continue;

        $dbKey = 'tasks_' . $key;

        // UPSERT: try update first, then insert
        $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->execute([$value, $dbKey]);

        if ($stmt->rowCount() === 0) {
            $stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$dbKey, $value]);
        }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
