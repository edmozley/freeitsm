<?php
/**
 * External API: Device Manager Ingest
 *
 * Accepts a JSON POST with device manager data from the PowerShell
 * collection script and syncs the asset_devices table through
 * AssetInventoryService::agentReport(), which also records the outcome.
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

$keyTenant = null;  // multi-tenancy: the company this agent's assets belong to
try {
    try {
        $stmt = $conn->prepare("SELECT tenant_id FROM apikeys WHERE apikey = ? AND active = 1");
        $stmt->execute([$authKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmt = $conn->prepare("SELECT id FROM apikeys WHERE apikey = ? AND active = 1");
        $stmt->execute([$authKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid authorization key']);
        exit;
    }
    if (array_key_exists('tenant_id', $row) && $row['tenant_id'] !== null) {
        $keyTenant = (int)$row['tenant_id'];   // NULL stays the Default company
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to validate API key']);
    exit;
}

// GH #217: an agent's report is refused while its module is switched off for the
// install - the device is not recorded, and nothing is queued to arrive later.
requireModuleOnJson($conn, 'assets');

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
    // Scoped to this key's company (NULL-safe match) so a device report only
    // ever attaches to that company's asset of the same hostname.
    $stmt = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND tenant_id <=> ?");
    $stmt->execute([$hostname, $keyTenant]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$asset) {
        http_response_code(404);
        echo json_encode(['error' => 'Asset not found for hostname: ' . $hostname]);
        exit;
    }

    $assetId = (int)$asset['id'];
    // A retired asset (it has left the estate) keeps the devices it left with.
    require_once __DIR__ . '/../../../../includes/asset_retirement.php';
    if (assetIsRetired($conn, $assetId)) {
        echo json_encode(['success' => true, 'asset_id' => $assetId, 'retired' => true,
                          'message' => 'This asset is retired in FreeITSM: the device list was not applied.']);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to look up asset']);
    exit;
}

// --------------------------------------------------
// Sync devices - through the one inventory implementation (GH #200 Part B)
// --------------------------------------------------
//
// 🔴 AN EMPTY LIST FROM AN OLD AGENT IS "I DID NOT FIND OUT", NOT "THERE ARE NO DEVICES".
// The wipe used to run unconditionally, so a report carrying no devices emptied the table
// - 226 rows gone on a WMI blip, silently. An agent that sends report_version 2 says
// whether it collected (an empty list is then real) or failed (collection_failures);
// anything older keeps the list, and says so in devices_left_unchanged.
require_once __DIR__ . '/../../../../includes/services/asset_inventory.php';

$v2 = (int)($data['report_version'] ?? 1) >= 2;
$failures = is_array($data['collection_failures'] ?? null) ? $data['collection_failures'] : [];

try {
    $sections = AssetInventoryService::agentReport($conn, $assetId, [
        'devices' => AssetInventoryService::agentState($data['devices'] ?? null, $v2, $failures['devices'] ?? null),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to sync devices', 'detail' => $e->getMessage()]);
    exit;
}

$devices = $sections['devices'];
$leftUnchanged = $devices['result'] !== 'applied';

echo json_encode([
    'status'        => $devices['result'] === 'failed' ? 'partial' : 'ok',
    'hostname'      => $hostname,
    'asset_id'      => $assetId,
    'devices_synced' => $leftUnchanged ? 0 : (int)$devices['added'],
    // Said out loud, so "0 devices synced" cannot be mistaken for a machine
    // that genuinely has none. The existing device list was kept.
    'devices_left_unchanged' => $leftUnchanged,
    'sections'      => $sections,
    'message'       => $devices['result'] === 'failed'
        ? 'The agent could not collect Device Manager - the existing device list was left unchanged'
        : ($leftUnchanged ? 'No devices in the report - the existing device list was left unchanged' : 'Device manager data synchronized')
]);
