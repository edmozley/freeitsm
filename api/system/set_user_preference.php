<?php
/**
 * API: Set a single user preference for the current analyst
 * POST { key, value }
 * Upserts on (analyst_id, preference_key). Pass null/empty value to clear.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$key = isset($data['key']) ? trim((string)$data['key']) : '';
$value = array_key_exists('value', $data) ? $data['value'] : null;

if ($key === '' || strlen($key) > 100) {
    echo json_encode(['success' => false, 'error' => 'Invalid key']);
    exit;
}
if ($value !== null) {
    $value = (string)$value;
    // Column is TEXT (~65 KB). Cap at 60 KB to leave UTF-8 overhead room
    // and reject anything obviously oversized (a real preference will be a
    // few hundred chars; anything larger likely indicates a bug or abuse).
    if (strlen($value) > 60000) {
        echo json_encode(['success' => false, 'error' => 'Value too long']);
        exit;
    }
}

try {
    $conn = connectToDatabase();
    $stmt = $conn->prepare(
        "INSERT INTO user_preferences (analyst_id, preference_key, preference_value, updated_datetime)
         VALUES (?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value), updated_datetime = UTC_TIMESTAMP()"
    );
    $stmt->execute([(int)$_SESSION['analyst_id'], $key, $value]);

    echo json_encode(['success' => true, 'key' => $key, 'value' => $value]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
