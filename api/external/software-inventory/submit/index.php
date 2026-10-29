<?php
/**
 * External API: the software inventory agent posts one machine's installed software
 * here.
 *
 * Authenticated by an API key in the Authorization header (apikeys); the key's company
 * owns the machine. The machine is matched or created through AssetsService (the same
 * reconcile rules as every inventory source), then software_inventory_apps and
 * software_inventory_detail are brought in line with the list through
 * AssetInventoryService::agentReport() - apps no longer reported are removed from this
 * machine, and an empty list is skipped. Every outcome is logged to software_inventory_log.
 *
 * @input  Hostname  the machine's name (JSON body)
 * @input  Software  the installed applications - each with Display Name, Publisher, Display Version, Install Date and so on
 * @output {status, hostname, counts of apps and detail rows inserted, updated and deleted}, or {error}
 * @wiki Asset-Reconciliation-Developer-Guide
 */
header('Content-Type: application/json');

// --------------------------------------------------
// Database connection using standard pattern
// --------------------------------------------------
require_once '../../../../config.php';
require_once '../../../../includes/functions.php';

try {
    $conn = connectToDatabase();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
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
    echo json_encode(['error' => 'Authorization key missing']);
    exit;
}

// --------------------------------------------------
// Validate Authorization key against apikeys table
// --------------------------------------------------
$keyTenant = null;  // multi-tenancy: the company this agent's assets belong to
try {
    // Validate the key and read its company in one go. Fall back gracefully if
    // the tenant_id column isn't present yet (part-migrated install).
    try {
        $stmt = $conn->prepare("SELECT tenant_id FROM apikeys WHERE apikey = ? AND active = 1");
        $stmt->execute([$authKey]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmt = $conn->prepare("SELECT id FROM apikeys WHERE apikey = ? AND active = 1");
        $stmt->execute([$authKey]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $keyExists = (bool)$result;
    if ($result && array_key_exists('tenant_id', $result) && $result['tenant_id'] !== null) {
        $keyTenant = (int)$result['tenant_id'];   // NULL stays the Default company
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to validate API key: ' . $e->getMessage()]);
    exit;
}

if (!$keyExists) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid authorization key']);
    exit;
}

// GH #217: an agent's report is refused while its module is switched off for the
// install - the device is not recorded, and nothing is queued to arrive later.
requireModuleOnJson($conn, 'software');
requireModuleOnJson($conn, 'assets');

// --------------------------------------------------
// Retrieve raw JSON payload & normalize to UTF-8
// --------------------------------------------------
$input = file_get_contents('php://input');

// If it's not valid UTF-8, try to convert from common Windows encodings / UTF-16
if (!mb_check_encoding($input, 'UTF-8')) {
    $input = @mb_convert_encoding($input, 'UTF-8', 'UTF-16LE, UTF-16, Windows-1252, ISO-8859-1, ASCII');
}

// Decode JSON
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

// --------------------------------------------------
// Validate structure: expect Hostname + Software[]
// --------------------------------------------------
if (!isset($data['Hostname']) || !isset($data['Software']) || !is_array($data['Software'])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid JSON payload. Expected { "Hostname": "...", "Software": [ ... ] }'
    ]);
    exit;
}

$hostname     = $data['Hostname'];
$softwareList = $data['Software'];

// --------------------------------------------------
// Helper function to log errors/responses
// @param mixed $hostId  the asset id the submitting machine was matched to or created as; null before that
// @param mixed $message  the response or error to log - a string, or an array stored as JSON
// --------------------------------------------------
function logApiResponse($conn, $hostId, $message) {
    try {
        $stmt = $conn->prepare("INSERT INTO software_inventory_log (host_id, api_response) VALUES (?, ?)");
        $stmt->execute([$hostId, is_array($message) ? json_encode($message) : $message]);
    } catch (PDOException $e) {
        // Ignore logging failures
    }
}

// --------------------------------------------------
// 1. Upsert host in assets table
// --------------------------------------------------
$hostId = null;

try {
    // Which asset is this machine? This agent only sends a hostname, so it
    // matches on that - but through the same rules as every other inventory
    // source (PR #164), and a new machine is written the same way. Scoped to
    // this key's company (NULL-safe match).
    require_once __DIR__ . '/../../../../includes/services/assets.php';
    $agentCtx = ActorContext::system('Software inventory agent');
    $reconcileRes = AssetsService::reconcileAsset($conn, $agentCtx, ['hostname' => $hostname], $keyTenant, 'software inventory');

    if ($reconcileRes['asset_id'] !== null) {
        // Host exists -> update last_seen
        $hostId = (int)$reconcileRes['asset_id'];

        $stmt = $conn->prepare("UPDATE assets SET last_seen = UTC_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$hostId]);
        // A retired asset (it has left the estate) keeps the software it left with:
        // seen, but not reshaped.
        if ($reconcileRes['retired']) {
            echo json_encode(['success' => true, 'asset_id' => $hostId, 'retired' => true,
                              'message' => 'This asset is retired in FreeITSM: the software list was not applied.']);
            exit;
        }
    } else {
        // Host does not exist -> create it (stamped with this key's company)
        $hostId = AssetsService::createDiscoveredAsset($conn, $agentCtx, ['hostname' => mb_substr($hostname, 0, 50)], $keyTenant, 'the software inventory agent');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to process host: ' . $e->getMessage()]);
    exit;
}

// --------------------------------------------------
// 2. Bring the machine's software in line - through the one inventory
//    implementation the system-info agent and the REST API share (GH #200 Part B)
// --------------------------------------------------
//
// The same catalogue rule as everywhere: an application is matched on display name and
// publisher, and a MANUALLY ADDED one (#1549) is adopted, never rewritten. This endpoint
// used to also rewrite a found application's name to the casing it had just been sent;
// that is gone, so two machines spelling it differently no longer take turns renaming it.
//
// 🔴 An empty list is "I did not find out" here, as on the other agent endpoints: it is
// skipped and the machine's software is kept, rather than every row being removed.
require_once __DIR__ . '/../../../../includes/services/asset_inventory.php';

$items = [];
foreach ($softwareList as $item) {
    if (!is_array($item)) continue;
    $items[] = [
        'display_name'     => $item['Display Name'] ?? null,
        'publisher'        => $item['Publisher'] ?? null,
        'display_version'  => $item['Display Version'] ?? null,
        'install_date'     => $item['Install Date'] ?? null,
        'uninstall_string' => $item['Uninstall String'] ?? null,
        'install_location' => $item['Install Location'] ?? null,
        'estimated_size'   => $item['Estimated Size'] ?? null,
        'system_component' => !empty($item['System Component']),
    ];
}

try {
    $sections = AssetInventoryService::agentReport($conn, $hostId, [
        'software' => AssetInventoryService::agentState($items, false),
    ]);
} catch (Throwable $e) {
    $errorMessage = ['error' => 'Failed to sync software', 'db_error' => $e->getMessage()];
    logApiResponse($conn, $hostId, $errorMessage);
    http_response_code(500);
    echo json_encode($errorMessage);
    exit;
}

$software = $sections['software'];
if ($software['result'] === 'failed') {
    $errorMessage = ['error' => 'Failed to sync software', 'db_error' => $software['error']];
    logApiResponse($conn, $hostId, $errorMessage);
    http_response_code(500);
    echo json_encode($errorMessage);
    exit;
}

// --------------------------------------------------
// Success response
// --------------------------------------------------
$applied = $software['result'] === 'applied';
$successPayload = [
    'status'            => 'ok',
    'hostname'          => $hostname,
    'total_software'    => count($softwareList),
    'inserted_details'  => $applied ? $software['added'] : 0,
    'updated_details'   => $applied ? $software['updated'] : 0,
    'deleted_details'   => $applied ? $software['removed'] : 0,
    'left_unchanged'    => $applied ? [] : ['software'],
    'message'           => $applied
        ? 'Software inventory synchronized successfully (normalized on display_name + publisher)'
        : 'No software in the report - the existing list was left unchanged'
];

// Log success
logApiResponse($conn, $hostId, $successPayload);

// Output the response
echo json_encode($successPayload);
