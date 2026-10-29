<?php
/**
 * External API: System Info / Asset Inventory Ingest
 *
 * Accepts a JSON POST with full hardware inventory from the PowerShell
 * collection script and upserts the asset record; the volumes, physical drives,
 * network adapters and software go through AssetInventoryService::agentReport(),
 * the one inventory implementation the REST API shares.
 *
 * report_version 2 (agent from 3.15.0): every section is sent, an empty list
 * means "collected, and empty", and collection_failures names the sections the
 * agent tried and could not collect. Without it, an empty section is skipped.
 *
 * Auth: Authorization header with API key (validated against apikeys table).
 *
 * @wiki Inventory-Freshness-Developer-Guide
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
    // Fetch the key's company alongside validating it. A missing tenant_id column
    // (part-migrated install) simply leaves $keyTenant null → the Default company.
    try {
        $stmt = $conn->prepare("SELECT tenant_id FROM apikeys WHERE apikey = ? AND active = 1");
        $stmt->execute([$authKey]);
        $keyRow = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmt = $conn->prepare("SELECT id FROM apikeys WHERE apikey = ? AND active = 1");
        $stmt->execute([$authKey]);
        $keyRow = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$keyRow) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid authorization key']);
        exit;
    }
    // NULL = the Default company. Scopes the hostname upsert below so two
    // companies may each legitimately hold a "LAPTOP-01".
    $keyTenant = isset($keyRow['tenant_id']) && $keyRow['tenant_id'] !== null ? (int)$keyRow['tenant_id'] : null;
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
// Validate required field
// --------------------------------------------------
if (!isset($data['hostname']) || trim($data['hostname']) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required field: hostname']);
    exit;
}

$hostname = trim($data['hostname']);

// Helper: get string value or null
// @param mixed $data  one section of the agent's decoded JSON report (untrusted)
// @param mixed $key  the field to read from it
// @param mixed $maxLen  the most characters to keep (the column length); null or 0 keeps it all
function strOrNull($data, $key, $maxLen = null) {
    $val = $data[$key] ?? null;
    if ($val === null || (is_string($val) && trim($val) === '')) return null;
    $val = is_string($val) ? trim($val) : (string)$val;
    if ($maxLen) $val = mb_substr($val, 0, $maxLen);
    return $val;
}

// Helper: get int/bigint value or null
// @param mixed $data  one section of the agent's decoded JSON report (untrusted)
// @param mixed $key  the field to read; anything not numeric comes back as null
function intOrNull($data, $key) {
    return isset($data[$key]) && is_numeric($data[$key]) ? (int)$data[$key] : null;
}

// --------------------------------------------------
// 1. Upsert asset record
// --------------------------------------------------
$isNew = false;
$hostId = null;

// Extract GPU name from gpus array (first entry)
$gpuName = null;
if (!empty($data['gpus']) && is_array($data['gpus'])) {
    $gpuName = $data['gpus'][0]['name'] ?? null;
    if ($gpuName) $gpuName = mb_substr(trim($gpuName), 0, 250);
}

// Extract BitLocker status for OS drive
$bitlockerStatus = null;
if (!empty($data['bitlocker']) && is_array($data['bitlocker'])) {
    foreach ($data['bitlocker'] as $bl) {
        if (isset($bl['drive']) && stripos($bl['drive'], 'C:') !== false) {
            $bitlockerStatus = $bl['protection_status'] ?? null;
            break;
        }
    }
    // If no C: found, use first volume
    if ($bitlockerStatus === null && !empty($data['bitlocker'][0]['protection_status'])) {
        $bitlockerStatus = $data['bitlocker'][0]['protection_status'];
    }
}

// Extract TPM version
$tpmVersion = null;
if (!empty($data['tpm']) && is_array($data['tpm'])) {
    $tpmVersion = $data['tpm']['version'] ?? null;
    if ($tpmVersion) $tpmVersion = mb_substr(trim($tpmVersion), 0, 50);
}

try {
    require_once __DIR__ . '/../../../../includes/services/assets.php';

    // Which asset is this machine? Serial first, then hostname (PR #164) - so a
    // renamed machine is recognised instead of arriving as a duplicate. Scoped
    // to this key's company (NULL-safe, so a Default-company key matches
    // NULL-tenant assets). A rename it reports is applied here, by the service.
    $agentCtx = ActorContext::system('Inventory agent');
    $reconcileRes = AssetsService::reconcileAsset(
        $conn,
        $agentCtx,
        ['service_tag' => strOrNull($data, 'service_tag', 50), 'hostname' => $hostname],
        $keyTenant,
        'inventory agent'
    );

    if ($reconcileRes['asset_id'] !== null && $reconcileRes['retired']) {
        // A retired asset still reporting: it has left the estate, so its record is
        // kept as it was when it left. Only "last seen" moves - a retired machine
        // that is still on the network is worth noticing - and nothing else is
        // written, nor a duplicate created.
        $conn->prepare("UPDATE assets SET last_seen = UTC_TIMESTAMP() WHERE id = ?")->execute([(int)$reconcileRes['asset_id']]);
        echo json_encode(['success' => true, 'asset_id' => (int)$reconcileRes['asset_id'], 'retired' => true,
                          'message' => 'This asset is retired in FreeITSM: the report was not applied.']);
        exit;
    }
    if ($reconcileRes['asset_id'] !== null) {
        $hostId = (int)$reconcileRes['asset_id'];

        // GH #200 part D: a manufacturer or model a person protected is not overwritten;
        // what the agent reports is kept beside it for the asset page to show.
        require_once __DIR__ . '/../../../../includes/services/asset_protection.php';
        $makeModel = ['manufacturer' => strOrNull($data, 'manufacturer', 50), 'model' => strOrNull($data, 'model', 50)];
        $mayWrite = AssetProtectionService::filterReported($conn, $hostId, $makeModel, 'the agent');

        // Update all fields
        $stmt = $conn->prepare("
            UPDATE assets SET
                manufacturer     = IF(?, manufacturer, ?),
                model            = IF(?, model, ?),
                memory           = ?,
                service_tag      = ?,
                operating_system = ?,
                feature_release  = ?,
                build_number     = ?,
                cpu_name         = ?,
                speed            = ?,
                bios_version     = ?,
                last_seen        = UTC_TIMESTAMP(),
                domain           = ?,
                logged_in_user   = ?,
                last_boot_utc    = ?,
                tpm_version      = ?,
                bitlocker_status = ?,
                gpu_name         = ?
            WHERE id = ?
        ");
        $stmt->execute([
            (int)!array_key_exists('manufacturer', $mayWrite), $makeModel['manufacturer'],
            (int)!array_key_exists('model', $mayWrite), $makeModel['model'],
            intOrNull($data, 'memory'),
            strOrNull($data, 'service_tag', 50),
            strOrNull($data, 'operating_system', 50),
            strOrNull($data, 'feature_release', 10),
            strOrNull($data, 'build_number', 50),
            strOrNull($data, 'cpu_name', 250),
            intOrNull($data, 'speed'),
            strOrNull($data, 'bios_version', 20),
            strOrNull($data, 'domain', 100),
            strOrNull($data, 'logged_in_user', 100),
            strOrNull($data, 'last_boot_utc'),
            $tpmVersion,
            $bitlockerStatus ? mb_substr($bitlockerStatus, 0, 20) : null,
            $gpuName,
            $hostId
        ]);
    } else {
        $isNew = true;

        // One write path for every inventory source: the tag (if the company
        // generates them), the transaction and the history row (PR #164).
        $hostId = AssetsService::createDiscoveredAsset($conn, $agentCtx, [
            'hostname'         => mb_substr($hostname, 0, 50),
            'manufacturer'     => strOrNull($data, 'manufacturer', 50),
            'model'            => strOrNull($data, 'model', 50),
            'memory'           => intOrNull($data, 'memory'),
            'service_tag'      => strOrNull($data, 'service_tag', 50),
            'operating_system' => strOrNull($data, 'operating_system', 50),
            'feature_release'  => strOrNull($data, 'feature_release', 10),
            'build_number'     => strOrNull($data, 'build_number', 50),
            'cpu_name'         => strOrNull($data, 'cpu_name', 250),
            'speed'            => intOrNull($data, 'speed'),
            'bios_version'     => strOrNull($data, 'bios_version', 20),
            'domain'           => strOrNull($data, 'domain', 100),
            'logged_in_user'   => strOrNull($data, 'logged_in_user', 100),
            'last_boot_utc'    => strOrNull($data, 'last_boot_utc'),
            'tpm_version'      => $tpmVersion,
            'bitlocker_status' => $bitlockerStatus ? mb_substr($bitlockerStatus, 0, 20) : null,
            'gpu_name'         => $gpuName,
        ], $keyTenant, 'the inventory agent', $reconcileRes['hostname_reused']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to upsert asset', 'detail' => $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to upsert asset', 'detail' => $e->getMessage()]);
    exit;
}

// --------------------------------------------------
// 2. Volumes, physical drives, network adapters and software
// --------------------------------------------------
//
// ONE implementation (GH #200 Part B, the #194 follow-up): the same service the REST
// API reports through reconciles the rows and records, per section, what this report
// came to - succeeded, empty, skipped or failed - so the asset page can say how fresh
// each list is. Device Manager arrives separately (device-manager/submit) the same way.
//
// 🔴 AN EMPTY LIST FROM AN OLD AGENT IS "I DID NOT FIND OUT", NOT "THERE IS NOTHING".
// A WMI blip once emptied 226 Device Manager rows this way. Only an agent that sends
// report_version 2 can say "collected, and empty" - AssetInventoryService::agentState()
// draws that line, and an older agent's empty section is skipped and named in
// left_unchanged, as before.
require_once __DIR__ . '/../../../../includes/services/asset_inventory.php';

$v2 = (int)($data['report_version'] ?? 1) >= 2;
$failures = is_array($data['collection_failures'] ?? null) ? $data['collection_failures'] : [];
$disks = is_array($data['disks'] ?? null) ? $data['disks'] : [];

try {
    $sections = AssetInventoryService::agentReport($conn, $hostId, [
        'disks'            => AssetInventoryService::agentState($disks['logical'] ?? null, $v2, $failures['disks'] ?? null),
        'physical_disks'   => AssetInventoryService::agentState($disks['physical'] ?? null, $v2, $failures['physical_disks'] ?? null),
        'network_adapters' => AssetInventoryService::agentState($data['network_adapters'] ?? null, $v2, $failures['network_adapters'] ?? null),
        'software'         => AssetInventoryService::agentState($data['software'] ?? null, $v2, $failures['software'] ?? null),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to sync inventory', 'detail' => $e->getMessage()]);
    exit;
}

$applied = fn(string $name, string $count) => ($sections[$name]['result'] ?? '') === 'applied' ? (int)$sections[$name][$count] : 0;
$leftUnchanged = array_keys(array_filter($sections, fn($s) => $s['result'] !== 'applied'));
$failed = array_filter($sections, fn($s) => $s['result'] === 'failed');
$software = $sections['software'] ?? [];

$response = [
    'status'             => $failed ? 'partial' : 'ok',
    'hostname'           => $hostname,
    'asset_id'           => $hostId,
    'is_new'             => $isNew,
    'disks_synced'       => $applied('disks', 'added') + $applied('disks', 'updated'),
    'physical_disks_synced' => $applied('physical_disks', 'added'),
    'left_unchanged'     => $leftUnchanged,
    'adapters_synced'    => $applied('network_adapters', 'added') + $applied('network_adapters', 'updated'),
    'software_processed' => $applied('software', 'added') + $applied('software', 'updated'),
    'software_new_links' => $applied('software', 'added'),
    'software_updated'   => $applied('software', 'updated'),
    'software_removed'   => $applied('software', 'removed'),
    // Per section: applied (with added / updated / removed), skipped or failed (with its error).
    'sections'           => $sections,
    'message'            => 'Asset inventory synchronized',
];
if ($failed) {
    $response['error'] = implode('; ', array_map(fn($n, $s) => "$n: {$s['error']}", array_keys($failed), $failed));
}
echo json_encode($response);
