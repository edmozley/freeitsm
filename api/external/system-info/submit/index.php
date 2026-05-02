<?php
/**
 * External API: System Info / Asset Inventory Ingest
 *
 * Accepts a JSON POST with full hardware inventory from the PowerShell
 * collection script and upserts the asset record plus related tables
 * (disks, network adapters, software inventory).
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
// Validate required field
// --------------------------------------------------
if (!isset($data['hostname']) || trim($data['hostname']) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required field: hostname']);
    exit;
}

$hostname = trim($data['hostname']);

// Helper: get string value or null
function strOrNull($data, $key, $maxLen = null) {
    $val = $data[$key] ?? null;
    if ($val === null || (is_string($val) && trim($val) === '')) return null;
    $val = is_string($val) ? trim($val) : (string)$val;
    if ($maxLen) $val = mb_substr($val, 0, $maxLen);
    return $val;
}

// Helper: get int/bigint value or null
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
    // Check if asset exists
    $stmt = $conn->prepare("SELECT id FROM assets WHERE hostname = ?");
    $stmt->execute([$hostname]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $hostId = (int)$existing['id'];

        // Update all fields
        $stmt = $conn->prepare("
            UPDATE assets SET
                manufacturer     = ?,
                model            = ?,
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
            strOrNull($data, 'manufacturer', 50),
            strOrNull($data, 'model', 50),
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

        $stmt = $conn->prepare("
            INSERT INTO assets (
                hostname, manufacturer, model, memory, service_tag,
                operating_system, feature_release, build_number, cpu_name, speed,
                bios_version, first_seen, last_seen,
                domain, logged_in_user, last_boot_utc,
                tpm_version, bitlocker_status, gpu_name
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                ?, ?, ?,
                ?, ?, ?
            )
        ");
        $stmt->execute([
            mb_substr($hostname, 0, 50),
            strOrNull($data, 'manufacturer', 50),
            strOrNull($data, 'model', 50),
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
            $gpuName
        ]);

        $hostId = (int)$conn->lastInsertId();
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to upsert asset', 'detail' => $e->getMessage()]);
    exit;
}

// --------------------------------------------------
// 2. Sync disks (delete + reinsert)
// --------------------------------------------------
$disksSynced = 0;

try {
    // Wipe all rows for this asset (any source) — the agent has authoritative
    // per-drive data, so it owns asset_disks for any host it reports for.
    $conn->prepare("DELETE FROM asset_disks WHERE asset_id = ?")->execute([$hostId]);

    if (!empty($data['disks']['logical']) && is_array($data['disks']['logical'])) {
        $stmt = $conn->prepare("
            INSERT INTO asset_disks (asset_id, drive, label, file_system, size_bytes, free_bytes, used_percent, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'agent')
        ");

        foreach ($data['disks']['logical'] as $disk) {
            $stmt->execute([
                $hostId,
                mb_substr($disk['drive'] ?? '', 0, 10),
                mb_substr($disk['label'] ?? '', 0, 100),
                mb_substr($disk['file_system'] ?? '', 0, 20),
                isset($disk['size_bytes']) && is_numeric($disk['size_bytes']) ? (int)$disk['size_bytes'] : null,
                isset($disk['free_bytes']) && is_numeric($disk['free_bytes']) ? (int)$disk['free_bytes'] : null,
                isset($disk['used_percent']) && is_numeric($disk['used_percent']) ? round((float)$disk['used_percent'], 1) : null
            ]);
            $disksSynced++;
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to sync disks', 'detail' => $e->getMessage()]);
    exit;
}

// --------------------------------------------------
// 3. Sync network adapters (delete + reinsert)
// --------------------------------------------------
$adaptersSynced = 0;

try {
    $conn->prepare("DELETE FROM asset_network_adapters WHERE asset_id = ?")->execute([$hostId]);

    if (!empty($data['network_adapters']) && is_array($data['network_adapters'])) {
        $stmt = $conn->prepare("
            INSERT INTO asset_network_adapters (asset_id, name, mac_address, ip_address, subnet_mask, gateway, dhcp_enabled)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($data['network_adapters'] as $adapter) {
            // Extract first IPv4 address from ip_addresses array
            $ipv4 = null;
            if (!empty($adapter['ip_addresses']) && is_array($adapter['ip_addresses'])) {
                foreach ($adapter['ip_addresses'] as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ipv4 = $ip;
                        break;
                    }
                }
            }

            // Extract first subnet mask
            $subnet = null;
            if (!empty($adapter['subnet_masks']) && is_array($adapter['subnet_masks'])) {
                $subnet = $adapter['subnet_masks'][0] ?? null;
            }

            // Extract first gateway
            $gateway = null;
            if (!empty($adapter['gateway']) && is_array($adapter['gateway'])) {
                $gateway = $adapter['gateway'][0] ?? null;
            }

            $stmt->execute([
                $hostId,
                mb_substr($adapter['name'] ?? '', 0, 255),
                mb_substr($adapter['mac_address'] ?? '', 0, 17),
                $ipv4 ? mb_substr($ipv4, 0, 45) : null,
                $subnet ? mb_substr($subnet, 0, 45) : null,
                $gateway ? mb_substr($gateway, 0, 45) : null,
                isset($adapter['dhcp_enabled']) ? ($adapter['dhcp_enabled'] ? 1 : 0) : null
            ]);
            $adaptersSynced++;
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to sync network adapters', 'detail' => $e->getMessage()]);
    exit;
}

// --------------------------------------------------
// 4. Process software inventory (if present)
// --------------------------------------------------
$softwareProcessed = 0;
$insertedApps = 0;
$insertedDetails = 0;
$updatedDetails = 0;
$deletedDetails = 0;

if (!empty($data['software']) && is_array($data['software'])) {
    try {
        // Load existing host/app mappings — scoped to source='agent' so the
        // delete-of-orphans below only touches our own rows. Intune-sourced
        // rows for the same host stay untouched.
        $existingAppIds = [];
        $stmt = $conn->prepare("SELECT app_id FROM software_inventory_detail WHERE host_id = ? AND source = 'agent'");
        $stmt->execute([$hostId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existingAppIds[(int)$row['app_id']] = true;
        }

        $seenAppIds = [];
        $appCache = [];

        foreach ($data['software'] as $item) {
            $displayName = isset($item['display_name']) ? trim($item['display_name']) : '';
            if ($displayName === '') continue;

            $publisher        = isset($item['publisher']) && trim($item['publisher']) !== '' ? trim($item['publisher']) : null;
            $displayVersion   = $item['display_version'] ?? null;
            $installDate      = $item['install_date'] ?? null;
            $uninstallString  = $item['uninstall_string'] ?? null;
            $installLocation  = $item['install_location'] ?? null;
            $estimatedSize    = $item['estimated_size'] ?? null;
            $systemComponent  = !empty($item['system_component']) ? 1 : 0;

            $appKey = strtolower($displayName) . '|' . strtolower($publisher ?? '');
            $appId = null;

            if (isset($appCache[$appKey])) {
                $appId = $appCache[$appKey];
            } else {
                // Look up or insert app
                $stmt = $conn->prepare("
                    SELECT id FROM software_inventory_apps
                    WHERE display_name = ? AND (publisher IS NULL OR publisher = ?)
                ");
                $stmt->execute([$displayName, $publisher]);
                $appRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($appRow) {
                    $appId = (int)$appRow['id'];
                } else {
                    $stmt = $conn->prepare("INSERT INTO software_inventory_apps (display_name, publisher) VALUES (?, ?)");
                    $stmt->execute([$displayName, $publisher]);
                    $appId = (int)$conn->lastInsertId();
                    $insertedApps++;
                }

                $appCache[$appKey] = $appId;
            }

            $seenAppIds[$appId] = true;

            // Upsert detail row — every read/write here is scoped to source='agent'
            // so the agent only ever touches its own rows. Intune-sourced rows
            // for the same (host_id, app_id) coexist as separate detail rows.
            $stmt = $conn->prepare("SELECT id FROM software_inventory_detail WHERE host_id = ? AND app_id = ? AND source = 'agent'");
            $stmt->execute([$hostId, $appId]);
            $detailRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($detailRow) {
                $stmt = $conn->prepare("
                    UPDATE software_inventory_detail SET
                        display_version = ?, install_date = ?, uninstall_string = ?,
                        install_location = ?, estimated_size = ?, system_component = ?,
                        last_seen = UTC_TIMESTAMP()
                    WHERE host_id = ? AND app_id = ? AND source = 'agent'
                ");
                $stmt->execute([$displayVersion, $installDate, $uninstallString, $installLocation, $estimatedSize, $systemComponent, $hostId, $appId]);
                $updatedDetails++;
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO software_inventory_detail
                        (host_id, app_id, display_version, install_date, uninstall_string, install_location, estimated_size, system_component, created_at, last_seen, source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'agent')
                ");
                $stmt->execute([$hostId, $appId, $displayVersion, $installDate, $uninstallString, $installLocation, $estimatedSize, $systemComponent]);
                $insertedDetails++;
            }

            $softwareProcessed++;
        }

        // Delete agent-owned mappings for software no longer present in the submit
        $toDelete = array_diff_key($existingAppIds, $seenAppIds);
        if (!empty($toDelete)) {
            $stmt = $conn->prepare("DELETE FROM software_inventory_detail WHERE host_id = ? AND app_id = ? AND source = 'agent'");
            foreach ($toDelete as $appId => $_) {
                $stmt->execute([$hostId, $appId]);
                $deletedDetails++;
            }
        }

    } catch (PDOException $e) {
        // Software processing failed but asset/disks/network already saved — report partial success
        echo json_encode([
            'status'    => 'partial',
            'hostname'  => $hostname,
            'asset_id'  => $hostId,
            'is_new'    => $isNew,
            'disks_synced'     => $disksSynced,
            'adapters_synced'  => $adaptersSynced,
            'error'     => 'Software processing failed: ' . $e->getMessage()
        ]);
        exit;
    }
}

// --------------------------------------------------
// Success response
// --------------------------------------------------
echo json_encode([
    'status'             => 'ok',
    'hostname'           => $hostname,
    'asset_id'           => $hostId,
    'is_new'             => $isNew,
    'disks_synced'       => $disksSynced,
    'adapters_synced'    => $adaptersSynced,
    'software_processed' => $softwareProcessed,
    'software_new_apps'  => $insertedApps,
    'software_new_links' => $insertedDetails,
    'software_updated'   => $updatedDetails,
    'software_removed'   => $deletedDetails,
    'message'            => 'Asset inventory synchronized'
]);
