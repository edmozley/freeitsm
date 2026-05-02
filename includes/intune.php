<?php
/**
 * Microsoft InTune (Microsoft Graph) sync helpers.
 *
 * Token + paged Graph GET, plus the upsert/link/stub-creation logic that
 * keeps intune_devices and assets in step. Designed to run from a detached
 * CLI worker so syncs don't block the web request.
 */

require_once __DIR__ . '/encryption.php';

/**
 * Load the InTune connection settings from system_settings, decrypting
 * the sensitive values. Returns null if any required setting is missing.
 */
function intuneGetSettings(PDO $conn): ?array {
    $stmt = $conn->prepare(
        "SELECT setting_key, setting_value FROM system_settings
          WHERE setting_key IN ('intune_tenant_id','intune_client_id','intune_client_secret','intune_verify_ssl','intune_php_exe')"
    );
    $stmt->execute();

    $values = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = $row['setting_value'];
        if (isEncryptedSettingKey($row['setting_key'])) {
            $value = decryptValue($value);
        }
        $values[$row['setting_key']] = $value;
    }

    foreach (['intune_tenant_id','intune_client_id','intune_client_secret'] as $required) {
        if (empty($values[$required])) return null;
    }

    return [
        'tenant_id'     => $values['intune_tenant_id'],
        'client_id'     => $values['intune_client_id'],
        'client_secret' => $values['intune_client_secret'],
        'verify_ssl'    => ($values['intune_verify_ssl'] ?? '1') !== '0',
        'php_exe'       => $values['intune_php_exe'] ?? null,
    ];
}

/**
 * Acquire a Graph access token via client-credentials. Cached for the lifetime
 * of the PHP process (token TTL is ~1h).
 */
function intuneGetToken(array $settings): string {
    static $token = null;
    static $expiresAt = 0;

    if ($token !== null && time() < $expiresAt - 60) {
        return $token;
    }

    $url  = "https://login.microsoftonline.com/{$settings['tenant_id']}/oauth2/v2.0/token";
    $body = http_build_query([
        'client_id'     => $settings['client_id'],
        'client_secret' => $settings['client_secret'],
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => $settings['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => $settings['verify_ssl'] ? 2 : 0,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code !== 200) {
        throw new RuntimeException("InTune token request failed (HTTP $code): " . ($err ?: $resp));
    }

    $data = json_decode($resp, true);
    if (!isset($data['access_token'])) {
        throw new RuntimeException('InTune token response missing access_token: ' . $resp);
    }

    $token     = $data['access_token'];
    $expiresAt = time() + (int)($data['expires_in'] ?? 3600);
    return $token;
}

/**
 * GET a Graph URL, retrying on 429/5xx with Retry-After honoured.
 */
function intuneGraphGet(string $url, array $settings, int $maxRetries = 5): array {
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $token = intuneGetToken($settings);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'ConsistencyLevel: eventual',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => $settings['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => $settings['verify_ssl'] ? 2 : 0,
        ]);
        $raw    = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrLen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            if ($attempt === $maxRetries) throw new RuntimeException("Graph GET failed: $err");
            sleep(min(60, 2 ** $attempt));
            continue;
        }

        $headers = substr($raw, 0, $hdrLen);
        $body    = substr($raw, $hdrLen);

        if ($code === 200) {
            $json = json_decode($body, true);
            if (!is_array($json)) throw new RuntimeException("Graph returned non-JSON: $body");
            return $json;
        }

        if ($code === 429 || ($code >= 500 && $code < 600)) {
            $retryAfter = 0;
            if (preg_match('/^Retry-After:\s*(\d+)/im', $headers, $m)) {
                $retryAfter = (int)$m[1];
            }
            $wait = $retryAfter > 0 ? $retryAfter : min(60, 2 ** $attempt);
            if ($attempt === $maxRetries) {
                throw new RuntimeException("Graph GET $url giving up after $maxRetries tries (HTTP $code): $body");
            }
            sleep($wait);
            continue;
        }

        throw new RuntimeException("Graph GET $url failed (HTTP $code): $body");
    }

    throw new RuntimeException("Graph GET $url unreachable");
}

/**
 * Convert a Graph ISO-8601 timestamp ("2025-04-30T08:14:22Z") to a MySQL
 * DATETIME string ("2025-04-30 08:14:22"), in UTC. Returns null for empty input.
 */
function intuneIsoToMysql(?string $iso): ?string {
    if ($iso === null || $iso === '') return null;
    try {
        return (new DateTime($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Upsert a single Graph managedDevice into intune_devices. Keyed on intune_id.
 */
function intuneUpsertDevice(PDO $conn, array $d): void {
    static $stmt = null;
    if ($stmt === null) {
        $sql = "INSERT INTO intune_devices (
                    intune_id, device_name, user_principal_name, user_display_name, user_id,
                    operating_system, os_version, compliance_state, management_state,
                    managed_device_owner_type, device_enrollment_type, device_registration_state,
                    enrolled_datetime, last_sync_datetime, model, manufacturer, serial_number,
                    imei, meid, wifi_mac_address, ethernet_mac_address, azure_ad_device_id,
                    is_encrypted, is_supervised, jail_broken,
                    total_storage_bytes, free_storage_bytes, raw_json, last_seen_local
                ) VALUES (
                    :intune_id, :device_name, :user_principal_name, :user_display_name, :user_id,
                    :operating_system, :os_version, :compliance_state, :management_state,
                    :managed_device_owner_type, :device_enrollment_type, :device_registration_state,
                    :enrolled_datetime, :last_sync_datetime, :model, :manufacturer, :serial_number,
                    :imei, :meid, :wifi_mac_address, :ethernet_mac_address, :azure_ad_device_id,
                    :is_encrypted, :is_supervised, :jail_broken,
                    :total_storage_bytes, :free_storage_bytes, :raw_json, UTC_TIMESTAMP()
                )
                ON DUPLICATE KEY UPDATE
                    device_name = VALUES(device_name),
                    user_principal_name = VALUES(user_principal_name),
                    user_display_name = VALUES(user_display_name),
                    user_id = VALUES(user_id),
                    operating_system = VALUES(operating_system),
                    os_version = VALUES(os_version),
                    compliance_state = VALUES(compliance_state),
                    management_state = VALUES(management_state),
                    managed_device_owner_type = VALUES(managed_device_owner_type),
                    device_enrollment_type = VALUES(device_enrollment_type),
                    device_registration_state = VALUES(device_registration_state),
                    enrolled_datetime = VALUES(enrolled_datetime),
                    last_sync_datetime = VALUES(last_sync_datetime),
                    model = VALUES(model),
                    manufacturer = VALUES(manufacturer),
                    serial_number = VALUES(serial_number),
                    imei = VALUES(imei),
                    meid = VALUES(meid),
                    wifi_mac_address = VALUES(wifi_mac_address),
                    ethernet_mac_address = VALUES(ethernet_mac_address),
                    azure_ad_device_id = VALUES(azure_ad_device_id),
                    is_encrypted = VALUES(is_encrypted),
                    is_supervised = VALUES(is_supervised),
                    jail_broken = VALUES(jail_broken),
                    total_storage_bytes = VALUES(total_storage_bytes),
                    free_storage_bytes = VALUES(free_storage_bytes),
                    raw_json = VALUES(raw_json),
                    last_seen_local = UTC_TIMESTAMP()";
        $stmt = $conn->prepare($sql);
    }

    $stmt->execute([
        ':intune_id'                 => $d['id'] ?? null,
        ':device_name'               => $d['deviceName'] ?? null,
        ':user_principal_name'       => $d['userPrincipalName'] ?? null,
        ':user_display_name'         => $d['userDisplayName'] ?? null,
        ':user_id'                   => $d['userId'] ?? null,
        ':operating_system'          => $d['operatingSystem'] ?? null,
        ':os_version'                => $d['osVersion'] ?? null,
        ':compliance_state'          => $d['complianceState'] ?? null,
        ':management_state'          => $d['managementState'] ?? null,
        ':managed_device_owner_type' => $d['managedDeviceOwnerType'] ?? null,
        ':device_enrollment_type'    => $d['deviceEnrollmentType'] ?? null,
        ':device_registration_state' => $d['deviceRegistrationState'] ?? null,
        ':enrolled_datetime'         => intuneIsoToMysql($d['enrolledDateTime'] ?? null),
        ':last_sync_datetime'        => intuneIsoToMysql($d['lastSyncDateTime'] ?? null),
        ':model'                     => $d['model'] ?? null,
        ':manufacturer'              => $d['manufacturer'] ?? null,
        ':serial_number'             => $d['serialNumber'] ?? null,
        ':imei'                      => $d['imei'] ?? null,
        ':meid'                      => $d['meid'] ?? null,
        ':wifi_mac_address'          => $d['wiFiMacAddress'] ?? null,
        ':ethernet_mac_address'      => $d['ethernetMacAddress'] ?? null,
        ':azure_ad_device_id'        => $d['azureADDeviceId'] ?? null,
        ':is_encrypted'              => isset($d['isEncrypted'])  ? (int)(bool)$d['isEncrypted']  : null,
        ':is_supervised'             => isset($d['isSupervised']) ? (int)(bool)$d['isSupervised'] : null,
        ':jail_broken'               => $d['jailBroken'] ?? null,
        ':total_storage_bytes'       => $d['totalStorageSpaceInBytes'] ?? null,
        ':free_storage_bytes'        => $d['freeStorageSpaceInBytes'] ?? null,
        ':raw_json'                  => json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * Link intune_devices to assets by hostname (case-insensitive), then create
 * stub asset rows for any intune_devices still unlinked. Returns counts so
 * the sync job message can show what happened.
 */
function intuneLinkDevicesToAssets(PDO $conn): array {
    // Step 1: link any device whose name matches an existing asset hostname.
    $conn->exec(
        "UPDATE intune_devices id
            JOIN assets a
              ON LOWER(a.hostname) = LOWER(id.device_name)
             SET id.asset_id = a.id
           WHERE id.device_name IS NOT NULL
             AND id.device_name <> ''"
    );

    // Step 2: find still-unlinked devices with a usable name and create stubs.
    $unlinked = $conn->query(
        "SELECT id, device_name, manufacturer, model, operating_system, last_sync_datetime, serial_number
           FROM intune_devices
          WHERE asset_id IS NULL
            AND device_name IS NOT NULL
            AND device_name <> ''"
    )->fetchAll(PDO::FETCH_ASSOC);

    $stubsCreated = 0;
    if ($unlinked) {
        $insert = $conn->prepare(
            "INSERT INTO assets (hostname, manufacturer, model, operating_system, service_tag, first_seen, last_seen)
             VALUES (:hostname, :manufacturer, :model, :operating_system, :service_tag, UTC_TIMESTAMP(), :last_seen)"
        );
        $linkOne = $conn->prepare("UPDATE intune_devices SET asset_id = :asset_id WHERE id = :id");

        foreach ($unlinked as $row) {
            // assets.hostname is VARCHAR(50); Intune device_name can be 256.
            // Truncate to fit. Same goes for service_tag (VARCHAR(50)).
            $hostname  = substr((string)$row['device_name'], 0, 50);
            $serialTag = $row['serial_number'] !== null ? substr((string)$row['serial_number'], 0, 50) : null;

            $insert->execute([
                ':hostname'         => $hostname,
                ':manufacturer'     => $row['manufacturer'] !== null ? substr((string)$row['manufacturer'], 0, 50) : null,
                ':model'            => $row['model'] !== null ? substr((string)$row['model'], 0, 50) : null,
                ':operating_system' => $row['operating_system'] !== null ? substr((string)$row['operating_system'], 0, 50) : null,
                ':service_tag'      => $serialTag,
                ':last_seen'        => $row['last_sync_datetime'],
            ]);
            $newAssetId = (int)$conn->lastInsertId();
            $linkOne->execute([':asset_id' => $newAssetId, ':id' => $row['id']]);
            $stubsCreated++;
        }
    }

    // Recount how many ended up linked total for the message.
    $linked = (int)$conn->query("SELECT COUNT(*) FROM intune_devices WHERE asset_id IS NOT NULL")->fetchColumn();

    return ['linked' => $linked, 'stubs_created' => $stubsCreated];
}

/**
 * Map an Intune operating_system string to a sensible "drive" label for
 * asset_disks. Intune only gives us a single device-level total/free figure,
 * so we fake a single drive entry whose name reflects the platform.
 */
function intuneOsToDrive(?string $os): string {
    if ($os === null || $os === '') return 'System';
    $lc = strtolower($os);
    if (strpos($lc, 'windows') !== false) return 'C:';
    if (strpos($lc, 'macos') !== false || strpos($lc, 'mac os') !== false) return '/';
    if (strpos($lc, 'linux') !== false) return '/';
    if (strpos($lc, 'ios') !== false || strpos($lc, 'ipados') !== false) return 'Internal';
    if (strpos($lc, 'android') !== false) return 'Internal';
    return 'System';
}

/**
 * Populate asset_disks with the single device-level total/free figure Intune
 * provides — but only for assets that have NO agent-sourced rows. The PS
 * inventory agent's per-drive data is more accurate, so we never overwrite it.
 *
 * Returns the number of asset_disks rows inserted (one per eligible asset).
 */
function intuneSyncDisks(PDO $conn): int {
    // Find linked devices with a usable storage figure. LEFT JOIN against
    // asset_disks restricted to source='agent' so we can filter to assets
    // where the agent has NOT reported any rows.
    $devices = $conn->query(
        "SELECT id_dev.asset_id, id_dev.operating_system,
                id_dev.total_storage_bytes, id_dev.free_storage_bytes
           FROM intune_devices id_dev
          WHERE id_dev.asset_id IS NOT NULL
            AND id_dev.total_storage_bytes IS NOT NULL
            AND id_dev.total_storage_bytes > 0
            AND NOT EXISTS (
                SELECT 1 FROM asset_disks ad
                 WHERE ad.asset_id = id_dev.asset_id
                   AND ad.source = 'agent'
            )"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$devices) return 0;

    $deleteIntune = $conn->prepare("DELETE FROM asset_disks WHERE asset_id = ? AND source = 'intune'");
    $insert = $conn->prepare(
        "INSERT INTO asset_disks (asset_id, drive, label, file_system, size_bytes, free_bytes, used_percent, source)
         VALUES (?, ?, NULL, NULL, ?, ?, ?, 'intune')"
    );

    $count = 0;
    foreach ($devices as $d) {
        $size = (int)$d['total_storage_bytes'];
        $free = $d['free_storage_bytes'] !== null ? (int)$d['free_storage_bytes'] : null;
        $usedPct = ($free !== null && $size > 0)
            ? round((($size - $free) / $size) * 100, 1)
            : null;

        $deleteIntune->execute([$d['asset_id']]);
        $insert->execute([
            (int)$d['asset_id'],
            intuneOsToDrive($d['operating_system']),
            $size,
            $free,
            $usedPct,
        ]);
        $count++;
    }
    return $count;
}

/**
 * Run the full sync for a given job id. Updates intune_sync_jobs as it goes
 * so the UI progress bar can poll. Designed for CLI worker use.
 */
function intuneRunSync(PDO $conn, int $jobId): void {
    $logFile = __DIR__ . '/../logs/intune_sync.log';
    @mkdir(dirname($logFile), 0775, true);
    $log = function (string $msg) use ($logFile, $jobId) {
        @file_put_contents(
            $logFile,
            sprintf("[%s] job=%d %s\n", gmdate('Y-m-d H:i:s'), $jobId, $msg),
            FILE_APPEND
        );
    };

    $update = $conn->prepare(
        "UPDATE intune_sync_jobs
            SET total = :total, processed = :processed, status = :status,
                finished_datetime = :finished, message = :message
          WHERE id = :id"
    );
    $setProgress = function (int $total, int $processed, string $status, ?string $message, bool $finished) use ($update, $jobId) {
        $update->execute([
            ':id'        => $jobId,
            ':total'     => $total,
            ':processed' => $processed,
            ':status'    => $status,
            ':finished'  => $finished ? gmdate('Y-m-d H:i:s') : null,
            ':message'   => $message,
        ]);
    };

    try {
        $log('worker started');

        $settings = intuneGetSettings($conn);
        if ($settings === null) {
            throw new RuntimeException('InTune credentials not configured. Save them on the InTune settings tab first.');
        }

        $setProgress(0, 0, 'running', 'Requesting access token...', false);
        intuneGetToken($settings);
        $log('token acquired');

        $url = 'https://graph.microsoft.com/beta/deviceManagement/managedDevices?$top=100';
        $setProgress(0, 0, 'running', 'Fetching first page from Graph...', false);

        $first = intuneGraphGet($url, $settings);
        $processed = 0;
        $failed    = 0;
        $log('first page returned with ' . count($first['value'] ?? []) . ' devices');

        $upsertOne = function (array $row) use ($conn, &$processed, &$failed, $log, $setProgress) {
            try {
                intuneUpsertDevice($conn, $row);
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                $log("UPSERT FAIL id=" . ($row['id'] ?? '?') . " name=" . ($row['deviceName'] ?? '?') . ": " . $e->getMessage());
            }
            if (($processed + $failed) % 10 === 0) {
                $setProgress($processed, $processed, 'running', 'Importing devices...', false);
            }
        };

        foreach (($first['value'] ?? []) as $row) $upsertOne($row);

        $next = $first['@odata.nextLink'] ?? null;
        $pageNum = 1;
        while ($next) {
            $pageNum++;
            $setProgress($processed, $processed, 'running', "Fetching page $pageNum...", false);
            $page = intuneGraphGet($next, $settings);
            foreach (($page['value'] ?? []) as $row) $upsertOne($row);
            $log("page $pageNum processed (running total $processed, failed $failed)");
            $next = $page['@odata.nextLink'] ?? null;
        }

        $setProgress($processed, $processed, 'running', 'Linking to assets...', false);
        $linkResult = intuneLinkDevicesToAssets($conn);
        $log("linking done: linked={$linkResult['linked']}, stubs_created={$linkResult['stubs_created']}");

        $setProgress($processed, $processed, 'running', 'Updating disk usage...', false);
        $disksWritten = intuneSyncDisks($conn);
        $log("disk sync done: rows_written=$disksWritten (only for assets with no agent disk data)");

        $msg = "Synced $processed devices. {$linkResult['linked']} linked to assets ({$linkResult['stubs_created']} new asset stubs created). $disksWritten disk rows populated from Intune.";
        if ($failed > 0) $msg .= " ($failed failed — see logs/intune_sync.log)";
        $setProgress($processed, $processed, 'done', $msg, true);
        $log("done, processed=$processed, failed=$failed");

    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $setProgress(0, 0, 'error', $msg, true);
        $log("ERROR: $msg\n" . $e->getTraceAsString());
    }
}

/**
 * Locate a usable PHP CLI binary so the web request can spawn a worker.
 * Tries: explicit setting → PHP_BINARY's directory → known WAMP layout → 'php' from PATH.
 * Returns the path or throws if nothing works.
 */
function intuneFindPhpExe(?string $override): string {
    $isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0 || PHP_OS_FAMILY === 'Windows';
    $exeName = $isWindows ? 'php.exe' : 'php';

    $candidates = [];
    if (!empty($override)) $candidates[] = $override;
    if (defined('PHP_BINARY') && PHP_BINARY) {
        $candidates[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $exeName;
    }
    if ($isWindows) {
        foreach (glob('c:\\wamp64\\bin\\php\\php*\\php.exe') ?: [] as $g) {
            $candidates[] = $g;
        }
    }

    foreach ($candidates as $c) {
        if ($c && is_file($c)) return $c;
    }

    // Last resort: 'php' from PATH. We don't verify, just return it.
    return $exeName;
}

/**
 * Spawn the CLI worker so it survives the web request. Returns true on success.
 */
function intuneSpawnWorker(int $jobId, ?string $phpExeOverride): bool {
    return intuneSpawnGenericWorker('intune_worker.php', $jobId, $phpExeOverride);
}

/**
 * Generic spawn helper used by both the device sync and the app sync workers.
 */
function intuneSpawnGenericWorker(string $workerScriptName, int $jobId, ?string $phpExeOverride): bool {
    $worker = realpath(__DIR__ . '/../' . $workerScriptName);
    if (!$worker) return false;

    $phpExe = intuneFindPhpExe($phpExeOverride);

    if (stripos(PHP_OS_FAMILY, 'Windows') === 0 || PHP_OS_FAMILY === 'Windows') {
        $cmd = sprintf('start /B "" "%s" "%s" %d', $phpExe, $worker, $jobId);
    } else {
        $cmd = sprintf('nohup %s %s %d > /dev/null 2>&1 &',
            escapeshellarg($phpExe), escapeshellarg($worker), $jobId);
    }

    $h = popen($cmd, 'r');
    if ($h === false) return false;
    pclose($h);
    return true;
}

// ============================================================================
// App (software) sync — Graph $expand=detectedApps, batched into manual jobs
// ============================================================================

/**
 * Read the configured app sync batch size from system_settings, with a sane
 * default and bounds (1..500).
 */
function intuneGetAppBatchSize(PDO $conn): int {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'intune_app_batch_size'");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    $n = $val === false ? 30 : (int)$val;
    if ($n < 1) $n = 1;
    if ($n > 500) $n = 500;
    return $n;
}

/**
 * Pick the next $limit assets that are good candidates for an Intune app sync:
 *   - Have a linked intune_devices row (with a usable Graph id)
 *   - Have NO 'agent'-sourced rows in software_inventory_detail (agent wins)
 *   - Aren't already pending/running in another job
 * Ordered: assets that have never been Intune-synced first, then oldest synced.
 */
function intuneGetAppSyncCandidates(PDO $conn, int $limit): array {
    $sql = "
        SELECT a.id AS asset_id, a.hostname, id_dev.intune_id,
               (SELECT MAX(d.last_seen)
                  FROM software_inventory_detail d
                 WHERE d.host_id = a.id AND d.source = 'intune') AS last_intune_sync
          FROM assets a
          JOIN intune_devices id_dev ON id_dev.asset_id = a.id
         WHERE id_dev.intune_id IS NOT NULL
           AND id_dev.intune_id <> ''
           AND NOT EXISTS (
               SELECT 1 FROM software_inventory_detail d
                WHERE d.host_id = a.id AND d.source = 'agent'
           )
           AND NOT EXISTS (
               SELECT 1 FROM intune_app_sync_job_assets ja
                JOIN intune_app_sync_jobs j ON j.id = ja.job_id
                WHERE ja.asset_id = a.id
                  AND ja.status = 'pending'
                  AND j.status IN ('pending','running')
           )
         ORDER BY (last_intune_sync IS NULL) DESC,
                  last_intune_sync ASC,
                  a.id ASC
         LIMIT " . (int)$limit;
    return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create a new app-sync job for the next batch of candidate assets.
 * Returns ['job_id' => int, 'asset_count' => int, 'reused' => bool].
 * If a pending/running job already exists, returns it (reused=true).
 */
function intuneCreateAppSyncJob(PDO $conn): array {
    // Concurrency lock — only one app-sync job at a time
    $running = $conn->query(
        "SELECT id FROM intune_app_sync_jobs WHERE status IN ('pending','running') ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    if ($running) {
        $count = (int)$conn->query("SELECT COUNT(*) FROM intune_app_sync_job_assets WHERE job_id = " . (int)$running)->fetchColumn();
        return ['job_id' => (int)$running, 'asset_count' => $count, 'reused' => true];
    }

    // Self-heal: any 'running' job older than 10 minutes that hasn't progressed
    // is a worker that died. Mark it errored.
    $conn->exec(
        "UPDATE intune_app_sync_jobs
            SET status = 'error',
                message = 'Auto-failed: worker did not progress within 10 minutes',
                finished_datetime = UTC_TIMESTAMP()
          WHERE status = 'running'
            AND processed = 0
            AND started_datetime < (UTC_TIMESTAMP() - INTERVAL 10 MINUTE)"
    );

    $batch = intuneGetAppBatchSize($conn);
    $candidates = intuneGetAppSyncCandidates($conn, $batch);
    if (!$candidates) {
        return ['job_id' => 0, 'asset_count' => 0, 'reused' => false];
    }

    $conn->beginTransaction();
    try {
        $conn->prepare(
            "INSERT INTO intune_app_sync_jobs (started_datetime, status, total, processed, failed, message)
             VALUES (UTC_TIMESTAMP(), 'pending', ?, 0, 0, 'Queued')"
        )->execute([count($candidates)]);
        $jobId = (int)$conn->lastInsertId();

        $insChild = $conn->prepare(
            "INSERT INTO intune_app_sync_job_assets (job_id, asset_id, status) VALUES (?, ?, 'pending')"
        );
        foreach ($candidates as $c) {
            $insChild->execute([$jobId, (int)$c['asset_id']]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    return ['job_id' => $jobId, 'asset_count' => count($candidates), 'reused' => false];
}

/**
 * Fetch the detectedApps array for a single Intune-managed device.
 */
function intuneFetchDeviceApps(string $intuneId, array $settings): array {
    $url = 'https://graph.microsoft.com/beta/deviceManagement/managedDevices/'
         . rawurlencode($intuneId) . '?$expand=detectedApps';
    $r = intuneGraphGet($url, $settings);
    return $r['detectedApps'] ?? [];
}

/**
 * Wipe and re-write the source='intune' software rows for a single asset.
 * Apps catalogue (software_inventory_apps) is shared with the agent — we
 * dedupe on (display_name, publisher).
 */
function intuneUpsertSoftwareForAsset(PDO $conn, int $assetId, array $apps): int {
    // Wipe previous Intune rows for this asset so the import is idempotent.
    $conn->prepare("DELETE FROM software_inventory_detail WHERE host_id = ? AND source = 'intune'")
         ->execute([$assetId]);

    $appCache = [];
    $count = 0;

    $findApp = $conn->prepare(
        "SELECT id FROM software_inventory_apps
          WHERE display_name = ? AND (publisher IS NULL AND ? IS NULL OR publisher = ?)"
    );
    $insertApp = $conn->prepare(
        "INSERT INTO software_inventory_apps (display_name, publisher) VALUES (?, ?)"
    );
    $insertDetail = $conn->prepare(
        "INSERT INTO software_inventory_detail
            (host_id, app_id, display_version, estimated_size, system_component, created_at, last_seen, source)
         VALUES (?, ?, ?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'intune')"
    );

    foreach ($apps as $app) {
        $displayName = isset($app['displayName']) ? trim((string)$app['displayName']) : '';
        if ($displayName === '') continue;

        $publisher = (isset($app['publisher']) && trim((string)$app['publisher']) !== '')
            ? trim((string)$app['publisher']) : null;
        $version = isset($app['version']) ? (string)$app['version'] : null;
        $sizeBytes = (isset($app['sizeInByte']) && is_numeric($app['sizeInByte']))
            ? (string)$app['sizeInByte'] : null;

        $appKey = strtolower($displayName) . '|' . strtolower($publisher ?? '');
        if (isset($appCache[$appKey])) {
            $appId = $appCache[$appKey];
        } else {
            $findApp->execute([$displayName, $publisher, $publisher]);
            $row = $findApp->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $appId = (int)$row['id'];
            } else {
                $insertApp->execute([$displayName, $publisher]);
                $appId = (int)$conn->lastInsertId();
            }
            $appCache[$appKey] = $appId;
        }

        $insertDetail->execute([$assetId, $appId, $version, $sizeBytes]);
        $count++;
    }

    return $count;
}

/**
 * Run an app-sync job: iterate its child rows, fetch detectedApps from Graph
 * for each asset, upsert into software_inventory_*, and tick progress.
 * Designed for CLI worker use.
 */
function intuneRunAppSyncJob(PDO $conn, int $jobId): void {
    $logFile = __DIR__ . '/../logs/intune_app_sync.log';
    @mkdir(dirname($logFile), 0775, true);
    $log = function (string $msg) use ($logFile, $jobId) {
        @file_put_contents(
            $logFile,
            sprintf("[%s] job=%d %s\n", gmdate('Y-m-d H:i:s'), $jobId, $msg),
            FILE_APPEND
        );
    };

    $jobUpdate = $conn->prepare(
        "UPDATE intune_app_sync_jobs
            SET status = :status, processed = :processed, failed = :failed,
                finished_datetime = :finished, message = :message
          WHERE id = :id"
    );
    $childUpdate = $conn->prepare(
        "UPDATE intune_app_sync_job_assets
            SET status = :status, error_message = :error_message,
                synced_datetime = :synced, app_count = :app_count
          WHERE id = :id"
    );

    try {
        $log('app sync worker started');

        $settings = intuneGetSettings($conn);
        if ($settings === null) {
            throw new RuntimeException('InTune credentials not configured.');
        }

        $jobUpdate->execute([
            ':status' => 'running', ':processed' => 0, ':failed' => 0,
            ':finished' => null, ':message' => 'Running...', ':id' => $jobId,
        ]);

        $children = $conn->prepare(
            "SELECT ja.id AS child_id, ja.asset_id, id_dev.intune_id, a.hostname
               FROM intune_app_sync_job_assets ja
               JOIN assets a ON a.id = ja.asset_id
          LEFT JOIN intune_devices id_dev ON id_dev.asset_id = ja.asset_id
              WHERE ja.job_id = ? AND ja.status = 'pending'
              ORDER BY ja.id"
        );
        $children->execute([$jobId]);
        $rows = $children->fetchAll(PDO::FETCH_ASSOC);

        $processed = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $childId = (int)$row['child_id'];
            $assetId = (int)$row['asset_id'];
            $intuneId = $row['intune_id'] ?? null;
            $hostname = $row['hostname'] ?? '?';

            if (!$intuneId) {
                $childUpdate->execute([
                    ':status' => 'obsolete',
                    ':error_message' => 'No linked intune_devices row',
                    ':synced' => null, ':app_count' => null, ':id' => $childId,
                ]);
                $log("asset_id=$assetId ($hostname) skipped: no intune_id");
                $processed++;
                $jobUpdate->execute([
                    ':status' => 'running', ':processed' => $processed, ':failed' => $failed,
                    ':finished' => null, ':message' => "Processing $processed of " . count($rows) . "...",
                    ':id' => $jobId,
                ]);
                continue;
            }

            try {
                $apps = intuneFetchDeviceApps($intuneId, $settings);
                $count = intuneUpsertSoftwareForAsset($conn, $assetId, $apps);
                $childUpdate->execute([
                    ':status' => 'done',
                    ':error_message' => null,
                    ':synced' => gmdate('Y-m-d H:i:s'),
                    ':app_count' => $count,
                    ':id' => $childId,
                ]);
                $log("asset_id=$assetId ($hostname) ok: $count apps");
                $processed++;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                // 404 = device deleted in Intune since the device sync ran
                if (stripos($msg, 'HTTP 404') !== false) {
                    $childUpdate->execute([
                        ':status' => 'obsolete',
                        ':error_message' => 'Device not found in Intune (likely deleted)',
                        ':synced' => null, ':app_count' => null, ':id' => $childId,
                    ]);
                    $log("asset_id=$assetId ($hostname) obsolete: 404");
                    $processed++;
                } else {
                    $childUpdate->execute([
                        ':status' => 'error',
                        ':error_message' => substr($msg, 0, 1000),
                        ':synced' => null, ':app_count' => null, ':id' => $childId,
                    ]);
                    $log("asset_id=$assetId ($hostname) FAIL: $msg");
                    $failed++;
                }
            }

            $jobUpdate->execute([
                ':status' => 'running', ':processed' => $processed, ':failed' => $failed,
                ':finished' => null, ':message' => "Processing " . ($processed + $failed) . " of " . count($rows) . "...",
                ':id' => $jobId,
            ]);
        }

        $msg = "Synced apps for $processed of " . count($rows) . " assets.";
        if ($failed > 0) $msg .= " ($failed failed — see logs/intune_app_sync.log)";
        $jobUpdate->execute([
            ':status' => 'done', ':processed' => $processed, ':failed' => $failed,
            ':finished' => gmdate('Y-m-d H:i:s'), ':message' => $msg, ':id' => $jobId,
        ]);
        $log("done, processed=$processed, failed=$failed");

    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $jobUpdate->execute([
            ':status' => 'error', ':processed' => 0, ':failed' => 0,
            ':finished' => gmdate('Y-m-d H:i:s'), ':message' => $msg, ':id' => $jobId,
        ]);
        $log("ERROR: $msg\n" . $e->getTraceAsString());
    }
}

/**
 * Spawn the CLI app-sync worker.
 */
function intuneSpawnAppWorker(int $jobId, ?string $phpExeOverride): bool {
    return intuneSpawnGenericWorker('intune_app_worker.php', $jobId, $phpExeOverride);
}
