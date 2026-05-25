<?php
/**
 * API: Get a single user preference for the current analyst
 * GET ?key=preference_key
 * Returns: { success, key, value }  (value is null if not set)
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$key = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
if ($key === '' || strlen($key) > 100) {
    echo json_encode(['success' => false, 'error' => 'Invalid key']);
    exit;
}

try {
    $conn = connectToDatabase();
    $stmt = $conn->prepare(
        "SELECT preference_value FROM user_preferences WHERE analyst_id = ? AND preference_key = ? LIMIT 1"
    );
    $stmt->execute([(int)$_SESSION['analyst_id'], $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'key' => $key,
        'value' => $row ? $row['preference_value'] : null
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
