<?php
/**
 * API: Get CSAT settings
 * Returns the four user-facing CSAT keys from system_settings.
 * The HMAC secret is deliberately NOT returned to the client.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('csat_mode','csat_delay_minutes','csat_one_per_ticket','csat_scale')");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode([
        'success'        => true,
        'mode'           => $rows['csat_mode'] ?? 'off',
        'delay_minutes'  => isset($rows['csat_delay_minutes']) ? (int)$rows['csat_delay_minutes'] : 0,
        'one_per_ticket' => $rows['csat_one_per_ticket'] ?? '1',
        'scale'          => $rows['csat_scale'] ?? 'stars',
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
