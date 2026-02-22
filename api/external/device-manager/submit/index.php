<?php
/**
 * External API: Device Manager Ingest
 *
 * Accepts a JSON POST with device manager data from the PowerShell
 * collection script and syncs the asset_devices table (delete + reinsert).
 *
 * Auth: Authorization header with API key (validated against apikeys table).
 */
header('Content-Type: application/json');

// --------------------------------------------------
// Database connection
// --------------------------------------------------
require_once '../../../../config.php';
require_once '../../../../includes/functions.php';

try {
    $conn = connectToDatabase();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// --------------------------------------------------
// Validate Authorization header
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
    echo json_encode(['error' => 'Authorization key missing']);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM apikeys WHERE apikey = ? AND active = 1");
    $stmt->execute([$authKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['cnt'] === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid authorization key']);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to validate API key']);
    exit;
}

// --------------------------------------------------
// Parse JSON payload (with UTF-8 normalization)
// --------------------------------------------------
$input = file_get_contents('php://input');

if (!mb_check_encoding($input, 'UTF-8')) {
    $input = @mb_convert_encoding($input, 'UTF-8', 'UTF-16LE, UTF-16, Windows-1252, ISO-8859-1, ASCII');
}

$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

// --------------------------------------------------
// Validate required fields
// --------------------------------------------------
if (!isset($data['hostname']) || trim($data['hostname']) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required field: hostname']);
    exit;
}

$hostname = trim($data['hostname']);

// --------------------------------------------------
// Look up asset by hostname
// --------------------------------------------------
try {
    $stmt = $conn->prepare("SELECT id FROM assets WHERE hostname = ?");
    $stmt->execute([$hostname]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$asset) {
        http_response_code(404);
        echo json_encode(['error' => 'Asset not found for hostname: ' . $hostname]);
        exit;
    }

    $assetId = (int)$asset['id'];
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to look up asset']);
    exit;
}

// --------------------------------------------------
// Sync devices (delete + reinsert)
// --------------------------------------------------
$devicesSynced = 0;

try {
    $conn->prepare("DELETE FROM asset_devices WHERE asset_id = ?")->execute([$assetId]);

    if (!empty($data['devices']) && is_array($data['devices'])) {
        $stmt = $conn->prepare("
            INSERT INTO asset_devices (asset_id, device_class, device_name, status, manufacturer, driver_version, driver_date)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($data['devices'] as $device) {
            $deviceName = isset($device['device_name']) ? trim($device['device_name']) : '';
            if ($deviceName === '') continue;

            $stmt->execute([
                $assetId,
                isset($device['device_class']) ? mb_substr(trim($device['device_class']), 0, 100) : null,
                mb_substr($deviceName, 0, 255),
                isset($device['status']) ? mb_substr(trim($device['status']), 0, 20) : null,
                isset($device['manufacturer']) && trim($device['manufacturer']) !== '' ? mb_substr(trim($device['manufacturer']), 0, 255) : null,
                isset($device['driver_version']) && trim($device['driver_version']) !== '' ? mb_substr(trim($device['driver_version']), 0, 50) : null,
                isset($device['driver_date']) && trim($device['driver_date']) !== '' ? $device['driver_date'] : null
            ]);
            $devicesSynced++;
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to sync devices', 'detail' => $e->getMessage()]);
    exit;
}

// --------------------------------------------------
// Success response
// --------------------------------------------------
echo json_encode([
    'status'        => 'ok',
    'hostname'      => $hostname,
    'asset_id'      => $assetId,
    'devices_synced' => $devicesSynced,
    'message'       => 'Device manager data synchronized'
]);
