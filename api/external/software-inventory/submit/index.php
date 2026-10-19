<?php
/**
 * External API: the software inventory agent posts one machine's installed software
 * here.
 *
 * Authenticated by an API key in the Authorization header (apikeys); the key's company
 * owns the machine. The machine is matched or created through AssetsService (the same
 * reconcile rules as every inventory source), then software_inventory_apps and
 * software_inventory_detail are brought in line with the list - apps no longer reported
 * are removed from this machine. Every outcome is logged to software_inventory_log.
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
// 2. Load existing host/app mappings for this host
//    (to know what to delete later)
// --------------------------------------------------
$existingAppIds = [];

try {
    $stmt = $conn->prepare("SELECT app_id FROM software_inventory_detail WHERE host_id = ? AND source = 'agent'");
    $stmt->execute([$hostId]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($row['app_id'])) {
            $existingAppIds[(int)$row['app_id']] = true;
        }
    }
} catch (PDOException $e) {
    $errorMessage = ['error' => 'Failed to load existing host/app mappings', 'db_error' => $e->getMessage()];
    logApiResponse($conn, $hostId, $errorMessage);
    http_response_code(500);
    echo json_encode($errorMessage);
    exit;
}

// We'll track which app_ids are present in this new payload
$seenAppIds = [];

// Cache for app lookup to avoid repeated SELECTs
// Key is case-insensitive: lower(display_name) + '|' + lower(publisher or '')
$appCache = [];

// Counters for reporting
$insertedApps     = 0;
$updatedApps      = 0;
$insertedDetails  = 0;
$updatedDetails   = 0;

// --------------------------------------------------
// 3. Loop over Software list
// --------------------------------------------------
foreach ($softwareList as $item) {

    // Require a display name as the main key
    if (!isset($item['Display Name']) || trim($item['Display Name']) === '') {
        // Skip records with no display name
        continue;
    }

    $displayName = trim($item['Display Name']);
    // Treat empty publisher as null
    $publisher   = isset($item['Publisher']) && trim($item['Publisher']) !== ''
        ? trim($item['Publisher'])
        : null;

    $displayVersion  = $item['Display Version']  ?? null;
    $installDate     = $item['Install Date']     ?? null;
    $uninstallString = $item['Uninstall String'] ?? null;
    $installLocation = $item['Install Location'] ?? null;
    $estimatedSize   = $item['Estimated Size']   ?? null;
    $systemComponent = !empty($item['System Component']) ? 1 : 0;

    // Build case-insensitive cache key
    $appKey = strtolower($displayName) . '|' . strtolower($publisher ?? '');

    // --------------------------
    // 3a. Upsert app row
    // --------------------------
    $appId = null;

    if (isset($appCache[$appKey])) {
        $appId = $appCache[$appKey];
    } else {
        try {
            // Look up in DB
            $stmt = $conn->prepare("
                SELECT id, display_name, publisher, source
                FROM software_inventory_apps
                WHERE display_name = ?
                  AND (publisher IS NULL OR publisher = ?)
            ");
            $stmt->execute([$displayName, $publisher]);
            $appRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($appRow && isset($appRow['id'])) {
                // Exists -> optionally update display_name/publisher
                $appId = (int)$appRow['id'];

                $currentName      = $appRow['display_name'] ?? null;
                $currentPublisher = $appRow['publisher'] ?? null;

                // ⚠️ A MANUALLY ADDED APPLICATION IS NEVER REWRITTEN BY THE AGENT (#1549).
                //
                // ADOPTING one is right and deliberate: the lookup above matches on
                // `display_name = ? AND (publisher IS NULL OR publisher = ?)`, so a
                // hand-typed "Adobe Creative Cloud" with no publisher matches what an
                // agent later reports, and the installs SHOULD attach to the row
                // somebody already curated rather than starting a duplicate.
                //
                // What must not happen is the rest of this block running on it. The
                // agent reports a registry DisplayName and Publisher; a person typed
                // a name they chose, and possibly a URL and notes beside it. Letting
                // the agent "normalise to the latest values" would silently replace
                // curated text with whatever an installer wrote into the registry —
                // and there would be nothing on screen to say it had happened.
                //
                // `source` stays 'manual' for the same reason: the row's origin is a
                // fact about who is responsible for its fields, not about what has
                // since been found installed. The install count tells the other story.
                // The install rows below still attach to $appId either way — only
                // the app row's own fields are protected.
                if (($appRow['source'] ?? 'agent') !== 'manual') {
                    $needsUpdate = false;
                    $newName     = $currentName;
                    $newPub      = $currentPublisher;

                    // If collation is case-insensitive, this is mostly cosmetic,
                    // but we can still normalise to the latest values.
                    if ($displayName && $displayName !== $currentName) {
                        $newName = $displayName;
                        $needsUpdate = true;
                    }
                    if ($publisher !== null && $publisher !== $currentPublisher) {
                        $newPub = $publisher;
                        $needsUpdate = true;
                    }

                    if ($needsUpdate) {
                        $stmt = $conn->prepare("UPDATE software_inventory_apps SET display_name = ?, publisher = ? WHERE id = ?");
                        $stmt->execute([$newName, $newPub, $appId]);
                        $updatedApps++;
                    }
                }
            } else {
                // Insert new app
                $stmt = $conn->prepare("INSERT INTO software_inventory_apps (display_name, publisher) VALUES (?, ?)");
                $stmt->execute([$displayName, $publisher]);

                // Re-select to get id
                $stmt = $conn->prepare("
                    SELECT id FROM software_inventory_apps
                    WHERE display_name = ?
                      AND (publisher IS NULL OR publisher = ?)
                ");
                $stmt->execute([$displayName, $publisher]);
                $appRow2 = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$appRow2 || !isset($appRow2['id'])) {
                    $errorMessage = [
                        'error'        => 'Could not obtain app id after insert',
                        'display_name' => $displayName,
                        'publisher'    => $publisher
                    ];
                    logApiResponse($conn, $hostId, $errorMessage);
                    http_response_code(500);
                    echo json_encode($errorMessage);
                    exit;
                }

                $appId = (int)$appRow2['id'];
                $insertedApps++;
                require_once dirname(__DIR__, 4) . '/workflow/includes/engine.php';
                WorkflowEngine::dispatch('software.application_discovered', ['application' => ['id' => $appId, 'name' => $displayName, 'publisher' => $publisher]]);
            }

            // Cache for this request (case-insensitive key)
            $appCache[$appKey] = $appId;

        } catch (PDOException $e) {
            $errorMessage = [
                'error'        => 'Failed to process app',
                'db_error'     => $e->getMessage(),
                'display_name' => $displayName,
                'publisher'    => $publisher
            ];
            logApiResponse($conn, $hostId, $errorMessage);
            http_response_code(500);
            echo json_encode($errorMessage);
            exit;
        }
    }

    if ($appId === null) {
        continue; // Safety guard
    }

    // Remember that this host has seen this app in this scan
    $seenAppIds[$appId] = true;

    // --------------------------
    // 3b. Upsert detail row (host/app)
    // --------------------------
    try {
        $stmt = $conn->prepare("SELECT id FROM software_inventory_detail WHERE host_id = ? AND app_id = ? AND source = 'agent'");
        $stmt->execute([$hostId, $appId]);
        $detailRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($detailRow && isset($detailRow['id'])) {
            // Exists -> UPDATE
            $stmt = $conn->prepare("
                UPDATE software_inventory_detail
                SET
                    display_version  = ?,
                    install_date     = ?,
                    uninstall_string = ?,
                    install_location = ?,
                    estimated_size   = ?,
                    system_component = ?,
                    last_seen        = UTC_TIMESTAMP()
                WHERE host_id = ? AND app_id = ? AND source = 'agent'
            ");
            $stmt->execute([
                $displayVersion,
                $installDate,
                $uninstallString,
                $installLocation,
                $estimatedSize,
                $systemComponent,
                $hostId,
                $appId
            ]);
            $updatedDetails++;
        } else {
            // New mapping -> INSERT
            $stmt = $conn->prepare("
                INSERT INTO software_inventory_detail (
                    host_id,
                    app_id,
                    display_version,
                    install_date,
                    uninstall_string,
                    install_location,
                    estimated_size,
                    system_component,
                    created_at,
                    last_seen
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ");
            $stmt->execute([
                $hostId,
                $appId,
                $displayVersion,
                $installDate,
                $uninstallString,
                $installLocation,
                $estimatedSize,
                $systemComponent
            ]);
            $insertedDetails++;
        }
    } catch (PDOException $e) {
        $errorMessage = [
            'error'    => 'Failed to process detail',
            'db_error' => $e->getMessage(),
            'host_id'  => $hostId,
            'app_id'   => $appId
        ];
        logApiResponse($conn, $hostId, $errorMessage);
        http_response_code(500);
        echo json_encode($errorMessage);
        exit;
    }
}

// --------------------------------------------------
// 4. Delete ONLY mappings that disappeared for this host
// --------------------------------------------------
$toDeleteAppIds = array_diff_key($existingAppIds, $seenAppIds);
$deletedDetails = 0;

if (!empty($toDeleteAppIds)) {
    try {
        $stmt = $conn->prepare("DELETE FROM software_inventory_detail WHERE host_id = ? AND app_id = ? AND source = 'agent'");

        foreach ($toDeleteAppIds as $appId => $_) {
            $stmt->execute([$hostId, $appId]);
            $deletedDetails++;
        }
    } catch (PDOException $e) {
        $errorMessage = [
            'error'    => 'Failed to delete obsolete detail rows',
            'db_error' => $e->getMessage(),
            'host_id'  => $hostId
        ];
        logApiResponse($conn, $hostId, $errorMessage);
        http_response_code(500);
        echo json_encode($errorMessage);
        exit;
    }
}

// --------------------------------------------------
// Success response
// --------------------------------------------------
$successPayload = [
    'status'            => 'ok',
    'hostname'          => $hostname,
    'total_software'    => count($softwareList),
    'inserted_apps'     => $insertedApps,
    'updated_apps'      => $updatedApps,
    'inserted_details'  => $insertedDetails,
    'updated_details'   => $updatedDetails,
    'deleted_details'   => $deletedDetails,
    'message'           => 'Software inventory synchronized successfully (normalized on display_name + publisher)'
];

// Log success
logApiResponse($conn, $hostId, $successPayload);

// Output the response
echo json_encode($successPayload);
