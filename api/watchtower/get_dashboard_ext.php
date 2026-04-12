<?php
/**
 * API: Watchtower Dashboard (Extension) — API-key-authenticated endpoint
 * GET — Returns attention items from every module in a single response
 * Used by the FreeITSM Watchtower browser extension
 */
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/watchtower_queries.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --------------------------------------------------
// Retrieve the Authorization header (case-insensitive)
// --------------------------------------------------
$headers = function_exists('getallheaders') ? getallheaders() : [];

$authKey = null;
foreach ($headers as $key => $value) {
    if (strtolower($key) === 'authorization') {
        $authKey = $value;
        break;
    }
}

if (!$authKey) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authorization key missing']);
    exit;
}

// --------------------------------------------------
// Validate API key and retrieve key details
// --------------------------------------------------
try {
    $conn = connectToDatabase();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, analyst_id FROM apikeys WHERE apikey = ? AND active = 1");
    $stmt->execute([$authKey]);
    $apiKeyRow = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to validate API key']);
    exit;
}

if (!$apiKeyRow) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid authorization key']);
    exit;
}

$apikeyId = (int)$apiKeyRow['id'];

// --------------------------------------------------
// Rate limiting — 60 requests per minute per key
// --------------------------------------------------
$rateLimitMax = 60;

// Check for configurable limit in system_settings
try {
    $settingStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'api_rate_limit_per_minute'");
    $settingStmt->execute();
    $settingVal = $settingStmt->fetchColumn();
    if ($settingVal !== false && is_numeric($settingVal)) {
        $rateLimitMax = (int)$settingVal;
    }
} catch (PDOException $e) {
    // Use default if setting not found
}

$windowStart = date('Y-m-d H:i:00');

try {
    // Increment counter for current window
    $stmt = $conn->prepare(
        "INSERT INTO api_rate_limits (apikey_id, request_count, window_start)
         VALUES (?, 1, ?)
         ON DUPLICATE KEY UPDATE request_count = request_count + 1"
    );
    $stmt->execute([$apikeyId, $windowStart]);

    // Read current count
    $stmt = $conn->prepare(
        "SELECT request_count FROM api_rate_limits WHERE apikey_id = ? AND window_start = ?"
    );
    $stmt->execute([$apikeyId, $windowStart]);
    $requestCount = (int)$stmt->fetchColumn();

    // Cleanup old windows (older than 5 minutes)
    $conn->exec("DELETE FROM api_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
} catch (PDOException $e) {
    // If rate limiting fails, allow the request through
    $requestCount = 0;
}

$remaining = max(0, $rateLimitMax - $requestCount);
$resetTime = strtotime($windowStart) + 60;

// Add rate limit headers to all responses
header('X-RateLimit-Limit: ' . $rateLimitMax);
header('X-RateLimit-Remaining: ' . $remaining);
header('X-RateLimit-Reset: ' . $resetTime);

if ($requestCount > $rateLimitMax) {
    http_response_code(429);
    header('Retry-After: ' . max(1, $resetTime - time()));
    echo json_encode([
        'success' => false,
        'error'   => 'Rate limit exceeded. Try again in ' . max(1, $resetTime - time()) . ' seconds.'
    ]);
    exit;
}

// --------------------------------------------------
// Fetch and return Watchtower data
// --------------------------------------------------
try {
    $data = getWatchtowerData($conn);

    echo json_encode(array_merge(
        ['success' => true, 'generated_at' => gmdate('Y-m-d\TH:i:s\Z')],
        $data
    ));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to retrieve dashboard data']);
}
