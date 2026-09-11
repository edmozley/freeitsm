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
    // Check if asset exists — scoped to this key's company (NULL-safe match, so a
    // Default-company key matches NULL-tenant assets).
    $stmt = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND tenant_id <=> ?");
    $stmt->execute([$hostname, $keyTenant]);
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
                tpm_version, bitlocker_status, gpu_name, tenant_id
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                ?, ?, ?,
                ?, ?, ?, ?
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
            $gpuName,
            $keyTenant
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
$leftUnchanged = [];

/**
 * Is there anything in this part of the report?
 *
 * 🔴 AN EMPTY LIST IS "I DID NOT FIND OUT", NOT "THERE IS NOTHING THERE".
 * Every block below wipes the asset's rows and reinserts, which is right when
 * the agent has told us what the machine currently has. It was wrong when it
 * had not: the DELETE ran unconditionally while the INSERT was guarded, so a
 * report carrying no disks emptied the table and left it empty — a WMI blip
 * taking 226 device rows with it, silently, until the next good run. Any
 * client holding an API key could do the same with `{"disks":{}}`.
 *
 * Skipping the wipe is safe because the opposite case barely exists: a working
 * machine that can reach this endpoint has at least one volume, one drive and
 * one network adapter. Stale data outliving a removed disk by one run is a far
 * smaller problem than an inventory that empties itself.
 *
 * Silence is the other half of the bug, so a skipped section is NAMED in the
 * response rather than looking like a successful sync of nothing.
 */
function reportHas($value): bool {
    return !empty($value) && is_array($value);
}

try {
    // The agent has authoritative per-drive data, so it owns asset_disks for
    // any host it reports for — but only when it actually reported some.
    if (!reportHas($data['disks']['logical'] ?? null)) {
        $leftUnchanged[] = 'disks';
    } else {
        $conn->prepare("DELETE FROM asset_disks WHERE asset_id = ?")->execute([$hostId]);
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
// 2b. Sync physical disks (delete + reinsert)
// --------------------------------------------------
//
// The agent has sent `disks.physical` — model, serial, size, media type and
// interface — since its first version, and until now this endpoint read only
// `disks.logical` and dropped the rest on the floor (discussion #97). The
// serial is the useful part: it is what a warranty claim and a disposal audit
// both ask for, and nothing else in the product records it.
//
// ⚠️ ITS OWN try/catch, and it never fails the report. asset_physical_disks
// only exists after Database Verification has run, so on an install that has
// pulled this update but not yet verified, the INSERT throws — and letting
// that 500 would stop every agent in the estate from reporting anything at
// all over a table nobody has yet asked for. The machine's hardware, volumes,
// adapters and software still land; the drives start appearing after the
// next verification.
$physicalDisksSynced = 0;

try {
    if (!reportHas($data['disks']['physical'] ?? null)) {
        $leftUnchanged[] = 'physical_disks';
    } else {
        $conn->prepare("DELETE FROM asset_physical_disks WHERE asset_id = ?")->execute([$hostId]);
        $stmt = $conn->prepare("
            INSERT INTO asset_physical_disks (asset_id, model, serial, size_bytes, media_type, interface_type)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($data['disks']['physical'] as $disk) {
            // A model with no serial is still worth having — plenty of USB
            // enclosures and virtual disks report one and not the other — but a
            // row that is entirely empty is noise, and VMs produce them.
            $trimmed = static function ($value, int $max) {
                $v = trim((string)($value ?? ''));
                return $v === '' ? null : mb_substr($v, 0, $max);
            };
            $model  = $trimmed($disk['model'] ?? null, 255);
            $serial = $trimmed($disk['serial'] ?? null, 100);
            $size   = isset($disk['size_bytes']) && is_numeric($disk['size_bytes']) ? (int)$disk['size_bytes'] : null;
            if ($model === null && $serial === null && $size === null) {
                continue;
            }
            $stmt->execute([
                $hostId,
                $model,
                $serial,
                $size,
                $trimmed($disk['media_type'] ?? null, 100),
                $trimmed($disk['interface'] ?? null, 50)
            ]);
            $physicalDisksSynced++;
        }
    }
} catch (PDOException $e) {
    // Deliberately swallowed — see above.
    $physicalDisksSynced = 0;
}

// --------------------------------------------------
// 3. Sync network adapters (delete + reinsert)
// --------------------------------------------------
$adaptersSynced = 0;

try {
    if (!reportHas($data['network_adapters'] ?? null)) {
        $leftUnchanged[] = 'network_adapters';
    } else {
        $conn->prepare("DELETE FROM asset_network_adapters WHERE asset_id = ?")->execute([$hostId]);
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
                    require_once dirname(__DIR__, 4) . '/workflow/includes/engine.php';
                    WorkflowEngine::dispatch('software.application_discovered', ['application' => ['id' => $appId, 'name' => $displayName, 'publisher' => $publisher]]);
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
            'physical_disks_synced' => $physicalDisksSynced,
            'left_unchanged'   => $leftUnchanged,
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
    'physical_disks_synced' => $physicalDisksSynced,
    'left_unchanged'     => $leftUnchanged,
    'adapters_synced'    => $adaptersSynced,
    'software_processed' => $softwareProcessed,
    'software_new_apps'  => $insertedApps,
    'software_new_links' => $insertedDetails,
    'software_updated'   => $updatedDetails,
    'software_removed'   => $deletedDetails,
    'message'            => 'Asset inventory synchronized'
]);
