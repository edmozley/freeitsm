<?php
/**
 * API Endpoint: Get (or reset) the current analyst's calendar subscription URL.
 *
 *   GET                -> returns the analyst's feed URL, creating a token if none exists.
 *   POST action=reset  -> rotates the token (revokes the old URL), returns the new URL.
 *
 * The token is a per-analyst capability stored in user_preferences
 * (preference_key = 'calendar_feed_token'). It authorises the read-only .ics feed
 * served by feed.php (which can't use the session, as a phone has no login cookie).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$analystId = (int)$_SESSION['analyst_id'];
$reset = ($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'reset');

try {
    $conn = connectToDatabase();

    $token = null;
    if (!$reset) {
        $stmt = $conn->prepare(
            "SELECT preference_value FROM user_preferences
             WHERE analyst_id = ? AND preference_key = 'calendar_feed_token' LIMIT 1"
        );
        $stmt->execute([$analystId]);
        $token = $stmt->fetchColumn() ?: null;
    }

    if (!$token) {
        $token = bin2hex(random_bytes(24)); // 48 hex chars
        $stmt = $conn->prepare(
            "INSERT INTO user_preferences (analyst_id, preference_key, preference_value, updated_datetime)
             VALUES (?, 'calendar_feed_token', ?, NOW())
             ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value), updated_datetime = NOW()"
        );
        $stmt->execute([$analystId, $token]);
    }

    // Build the absolute feed URL from this request's host + this script's folder.
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme    = $https ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/calendar/get_feed_url.php')), '/');
    $path      = $scriptDir . '/feed.php?token=' . $token;

    echo json_encode([
        'success' => true,
        'url'     => $scheme . '://' . $host . $path,   // https/http — for copy + Android
        'webcal'  => 'webcal://' . $host . $path,       // for iOS tap/QR subscribe
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
