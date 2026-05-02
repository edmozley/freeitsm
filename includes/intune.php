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

        $msg = "Synced $processed devices. {$linkResult['linked']} linked to assets ({$linkResult['stubs_created']} new asset stubs created).";
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
    $worker = realpath(__DIR__ . '/../intune_worker.php');
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
