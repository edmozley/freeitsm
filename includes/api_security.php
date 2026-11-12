<?php
/**
 * REST API security (GH #287): where and when a key may be used, the download
 * guard, alerts to administrators, and the security log.
 *
 * Built with the project documents API, because an API that can download
 * documents is also a way to take every contract, design and HR letter in one
 * evening if a key leaks. Three layers:
 *
 *   1. WHERE AND WHEN (every request, before anything else runs):
 *      api_keys.allowed_ips   - addresses and ranges the key may be used from
 *      api_keys.allowed_hours - days and a time window, in its own time zone
 *      A key used from an address it has not used for 30 days raises a
 *      `new_ip` alert (a heads-up, not a block).
 *
 *   2. THE DOWNLOAD GUARD (every document download):
 *      the key's files and bytes over the last 60 minutes, from
 *      document_access_log. Past the alert level administrators are told;
 *      at the limit the download is refused - or the key is switched off -
 *      as System -> API -> Security says.
 *
 *   3. THE WATCHERS, on every request:
 *      - an address that keeps sending bad keys is blocked for a while
 *        (api_failed_auth_limit in api_failed_auth_minutes);
 *      - a key that keeps being refused (403/404 - and "forbidden" and "does not
 *        exist" are answered alike on purpose, so this is also how enumeration of
 *        ids shows) is reported at one level and can be switched off at another.
 *
 *   4. THE SECURITY LOG: api_key_events, one row per alert or refusal. Alerts
 *      are sent once per key per event per hour, using it.
 *
 * 🔴 The address is REMOTE_ADDR, the connection's own - never a header such as
 * X-Forwarded-For, which the caller writes. Behind a reverse proxy every request
 * comes from the proxy, so an allow-list there must name the proxy - the help says so.
 *
 * @wiki Project-Documents-API-Design
 */

/** The install's API security settings with their defaults (System -> API -> Security). */
const API_SECURITY_DEFAULTS = [
    'api_download_alert_files'  => '50',
    'api_download_alert_mb'     => '500',
    'api_download_limit_files'  => '200',
    'api_download_limit_mb'     => '2048',
    'api_download_limit_action' => 'refuse',      // refuse | suspend
    'api_new_ip_alert'          => 'bell_email',  // bell_email | bell | off
    'api_failed_auth_limit'     => '50',          // bad keys from one address ...
    'api_failed_auth_minutes'   => '15',          // ... within this many minutes block it for as long
    'api_refusal_alert'         => '20',          // refusals (403/404) for one key in 10 minutes: tell administrators
    'api_refusal_limit'         => '100',         // ... and switch the key off at this many; 0 = never
    'api_admin_keys'            => 'allow',       // keys acting as an administrator: allow | require_ips | forbid
    'api_log_successes'         => '1',           // the activity log records successful calls too (failures always)
    'api_log_keep_days'         => '30',          // ... for this many days, then
    'api_log_old_rows'          => 'delete',      // delete | archive (zips in uploads/api-log-archive/, a CSV per day inside)
    'api_log_archive_period'    => 'month',       // one zip per day | week | month - however often the job runs
];

/**
 * The install's API security settings, defaults filled in.
 *
 * @return array<string,string>
 */
function apiSecuritySettings(PDO $conn): array
{
    $out = API_SECURITY_DEFAULTS;
    try {
        $in = implode(',', array_fill(0, count($out), '?'));
        $st = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $st->execute(array_keys($out));
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) if ($v !== null && $v !== '') $out[$k] = (string)$v;
    } catch (Throwable) { /* defaults */ }
    return $out;
}

/** Are the 5.1.0 security columns there (Database Verification has run)? */
function apiSecurityReady(PDO $conn): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $conn->query("SELECT allowed_ips, allowed_hours, suspended_reason FROM api_keys LIMIT 0");
        $conn->query("SELECT id FROM api_key_events LIMIT 0");
        $conn->query("SELECT api_key_id, bytes FROM document_access_log LIMIT 0");
        return $ok = true;
    } catch (Throwable) {
        return $ok = false;
    }
}

// ── Where ────────────────────────────────────────────────────────────────────

/**
 * The entries of an allowed-addresses list that are valid, normalised: single
 * addresses as typed (canonical form), ranges as "network/bits".
 *
 * @param string $raw  one address or range per line (commas and spaces also separate)
 * @return array{ok: string[], bad: string[]}
 */
function apiAllowedIpsParse(string $raw): array
{
    $ok = []; $bad = [];
    foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
        if (str_contains($entry, '/')) {
            [$net, $bits] = explode('/', $entry, 2);
            $bin = @inet_pton($net);
            if ($bin === false || !ctype_digit($bits) || (int)$bits > strlen($bin) * 8) { $bad[] = $entry; continue; }
            $ok[] = inet_ntop(apiMaskBits($bin, (int)$bits)) . '/' . (int)$bits;
        } else {
            $bin = @inet_pton($entry);
            if ($bin === false) { $bad[] = $entry; continue; }
            $ok[] = inet_ntop($bin);
        }
    }
    return ['ok' => array_values(array_unique($ok)), 'bad' => $bad];
}

/**
 * Is this address allowed by the list? An empty or missing list allows any address.
 *
 * @param ?string $raw  api_keys.allowed_ips
 * @param string $ip    the connection's address (REMOTE_ADDR)
 */
function apiIpAllowed(?string $raw, string $ip): bool
{
    $list = apiAllowedIpsParse((string)$raw)['ok'];
    if (!$list) return true;
    $a = @inet_pton($ip);
    if ($a === false) return false;
    foreach ($list as $entry) {
        if (!str_contains($entry, '/')) { if (@inet_pton($entry) === $a) return true; continue; }
        [$net, $bits] = explode('/', $entry, 2);
        $n = @inet_pton($net);
        if ($n !== false && strlen($n) === strlen($a) && apiMaskBits($a, (int)$bits) === apiMaskBits($n, (int)$bits)) return true;
    }
    return false;
}

/** The first $bits bits of a packed address, the rest zeroed. */
function apiMaskBits(string $bin, int $bits): string
{
    $out = '';
    for ($i = 0, $len = strlen($bin); $i < $len; $i++) {
        $keep = max(0, min(8, $bits - $i * 8));
        $out .= chr(ord($bin[$i]) & (0xFF << (8 - $keep)) & 0xFF);
    }
    return $out;
}

// ── When ─────────────────────────────────────────────────────────────────────

/**
 * Allowed hours, cleaned: days 1 (Monday) to 7, from/to as HH:MM, and a time zone.
 * Null when the input says "any time" or is not valid.
 *
 * @param mixed $in  array or JSON {days, from, to, tz}
 */
function apiAllowedHoursClean($in): ?array
{
    if (is_string($in)) $in = json_decode($in, true);
    if (!is_array($in)) return null;
    $days = array_values(array_unique(array_filter(array_map('intval', (array)($in['days'] ?? [])), fn($d) => $d >= 1 && $d <= 7)));
    sort($days);
    $from = (string)($in['from'] ?? ''); $to = (string)($in['to'] ?? '');
    if (!$days || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $to) || $from === $to) return null;
    $tz = (string)($in['tz'] ?? '');
    if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) $tz = date_default_timezone_get();
    $mode = ((string)($in['mode'] ?? 'refuse')) === 'alert' ? 'alert' : 'refuse';   // alert = let it through, tell administrators
    return ['days' => $days, 'from' => $from, 'to' => $to, 'tz' => $tz, 'mode' => $mode];
}

/**
 * Is now inside the key's allowed hours? Missing or invalid = any time. A window
 * whose "to" is before its "from" runs past midnight (22:00-06:00), counted from
 * the day it starts.
 *
 * @param ?string $json  api_keys.allowed_hours
 * @param ?DateTimeImmutable $now  for tests; default now
 */
function apiHoursAllowed(?string $json, ?DateTimeImmutable $now = null): bool
{
    $h = apiAllowedHoursClean($json);
    if ($h === null) return true;
    $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($h['tz']));
    $t = $now->format('H:i');
    $day = (int)$now->format('N');
    if ($h['from'] < $h['to']) return in_array($day, $h['days'], true) && $t >= $h['from'] && $t < $h['to'];
    // Overnight: after "from" on an allowed day, or before "to" the morning after one.
    $yesterday = $day === 1 ? 7 : $day - 1;
    return ($t >= $h['from'] && in_array($day, $h['days'], true)) || ($t < $h['to'] && in_array($yesterday, $h['days'], true));
}

// ── The checks on every request ──────────────────────────────────────────────

/**
 * Where and when, for one request - called by apiAuthenticate() straight after
 * the key is found to be valid. Refuses with 403 (and logs it) or returns; and
 * raises the new-address alert.
 *
 * @param array $key  the api_keys row
 */
function apiKeyRequestGuard(PDO $conn, array $key): void
{
    if (!apiSecurityReady($conn)) return;
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $keyId = (int)$key['id'];
    if (!apiIpAllowed($key['allowed_ips'] ?? null, $ip)) {
        apiSecurityEvent($conn, $keyId, 'ip_refused', $ip, 'A request from an address this key is not allowed to use.');
        apiSecurityAlertOnce($conn, $keyId, 'ip_refused', $ip, 'API key "' . $key['name'] . '" was used from ' . ($ip ?: 'an unknown address') . ', which it is not allowed to use. The request was refused.');
        apiError(403, 'ip_not_allowed', 'This API key may not be used from this address.');
    }
    if (!apiHoursAllowed($key['allowed_hours'] ?? null)) {
        $alertOnly = (apiAllowedHoursClean($key['allowed_hours'] ?? null)['mode'] ?? 'refuse') === 'alert';
        apiSecurityEvent($conn, $keyId, 'outside_hours', $ip, $alertOnly ? 'A request outside this key\'s usual hours (allowed - alert only).' : 'A request outside the hours this key may be used.');
        apiSecurityAlertOnce($conn, $keyId, 'outside_hours', $ip, 'API key "' . $key['name'] . '" was used outside its ' . ($alertOnly ? 'usual' : 'allowed') . ' hours, from ' . ($ip ?: 'an unknown address') . '. ' . ($alertOnly ? 'It was allowed (alert only).' : 'The request was refused.'));
        if (!$alertOnly) apiError(403, 'outside_allowed_hours', 'This API key may not be used at this time.');
    }
    // Keys that act as an administrator (System -> API -> Threat protection). Checked on
    // every request, not only on save: an analyst made an administrator AFTER the key
    // was created must not quietly give an unlocked key administrator reach.
    $adminRule = apiSecuritySettings($conn)['api_admin_keys'];
    if ($adminRule !== 'allow' && apiKeyIsAdmin($conn, $key)) {
        if ($adminRule === 'forbid') {
            apiSecurityEvent($conn, $keyId, 'admin_key_refused', $ip, 'Keys that act as an administrator are not allowed.');
            apiSecurityAlertOnce($conn, $keyId, 'admin_key_refused', $ip, 'API key "' . $key['name'] . '" acts as an administrator, which Threat protection does not allow. Its requests are refused.');
            apiError(403, 'admin_key_not_allowed', 'This API key acts as an administrator, which this install does not allow. Use a key for a limited analyst.');
        }
        if ($adminRule === 'require_ips' && !apiAllowedIpsParse((string)($key['allowed_ips'] ?? ''))['ok']) {
            apiSecurityEvent($conn, $keyId, 'admin_key_refused', $ip, 'An administrator key with no allowed addresses.');
            apiSecurityAlertOnce($conn, $keyId, 'admin_key_refused', $ip, 'API key "' . $key['name'] . '" acts as an administrator but has no allowed addresses, which Threat protection requires. Its requests are refused until it is given some.');
            apiError(403, 'admin_key_unrestricted', 'This API key acts as an administrator, so it must be limited to allowed addresses (System - API).');
        }
    }

    // A new address: not seen for this key in the last 30 days. Never a block.
    if ($ip !== '' && (string)($key['last_used_ip'] ?? '') !== '' && $ip !== (string)$key['last_used_ip']) {
        try {
            $seen = $conn->prepare("SELECT 1 FROM api_key_events WHERE api_key_id = ? AND ip_address = ? AND event IN ('seen_ip','new_ip')
                                     AND created_datetime > UTC_TIMESTAMP() - INTERVAL 30 DAY LIMIT 1");
            $seen->execute([$keyId, $ip]);
            if (!$seen->fetchColumn()) {
                apiSecurityEvent($conn, $keyId, 'new_ip', $ip, 'First use from this address in 30 days (last used from ' . $key['last_used_ip'] . ').');
                $mode = apiSecuritySettings($conn)['api_new_ip_alert'];
                if ($mode !== 'off') {
                    apiSecurityNotifyAdmins($conn, $keyId, 'API key used from a new address',
                        'API key "' . $key['name'] . '" was used from ' . $ip . ' - an address it has not used in the last 30 days (it was last used from ' . $key['last_used_ip'] . '). If you do not recognise it, switch the key off on System -> API.',
                        $mode === 'bell_email');
                }
            }
        } catch (Throwable $e) { error_log('[api_security] new ip: ' . $e->getMessage()); }
    } elseif ($ip !== '' && (string)($key['last_used_ip'] ?? '') === '') {
        // The first address a key is ever used from is its baseline, not an alert.
        try { apiSecurityEvent($conn, $keyId, 'seen_ip', $ip, 'First use of this key.'); } catch (Throwable) {}
    }
}

// ── The watchers ─────────────────────────────────────────────────────────────

/**
 * Before a key is even looked at: is this address blocked for sending too many
 * bad keys? Refuses with 429. Call first thing in apiAuthenticate().
 */
function apiAddressBlockGuard(PDO $conn): void
{
    if (!apiSecurityReady($conn)) return;
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    if ($ip === '') return;
    $s = apiSecuritySettings($conn);
    $limit = (int)$s['api_failed_auth_limit']; $mins = max(1, (int)$s['api_failed_auth_minutes']);
    if ($limit <= 0) return;
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM api_key_events WHERE event = 'auth_failed' AND ip_address = ? AND created_datetime > UTC_TIMESTAMP() - INTERVAL $mins MINUTE");
        $st->execute([$ip]);
        if ((int)$st->fetchColumn() >= $limit) {
            apiError(429, 'address_blocked', 'Too many requests with an invalid API key from this address. Try again later.');
        }
    } catch (Throwable) { /* never block on a broken log */ }
}

/**
 * A request with a missing or invalid key: logged against the address, and when the
 * address reaches the limit administrators are told (once an hour per address).
 */
function apiAuthFailed(PDO $conn): void
{
    if (!apiSecurityReady($conn)) return;
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    if ($ip === '') return;
    apiSecurityEvent($conn, null, 'auth_failed', $ip, 'A request with a missing or invalid API key.');
    $s = apiSecuritySettings($conn);
    $limit = (int)$s['api_failed_auth_limit']; $mins = max(1, (int)$s['api_failed_auth_minutes']);
    if ($limit <= 0) return;
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM api_key_events WHERE event = 'auth_failed' AND ip_address = ? AND created_datetime > UTC_TIMESTAMP() - INTERVAL $mins MINUTE");
        $st->execute([$ip]);
        if ((int)$st->fetchColumn() !== $limit) return;   // exactly at the limit: the moment it is blocked
        $told = $conn->prepare("SELECT 1 FROM api_key_events WHERE event = 'address_blocked' AND ip_address = ? AND created_datetime > UTC_TIMESTAMP() - INTERVAL 60 MINUTE LIMIT 1");
        $told->execute([$ip]);
        if ($told->fetchColumn()) return;
        apiSecurityEvent($conn, null, 'address_blocked', $ip, $limit . ' requests with an invalid API key in ' . $mins . ' minutes - blocked for ' . $mins . ' minutes.');
        apiSecurityNotifyAdmins($conn, 0, 'An address is trying API keys',
            $ip . ' sent ' . $limit . ' requests with a missing or invalid API key in ' . $mins . ' minutes, and is blocked from the API for ' . $mins . ' minutes. Someone may be guessing keys.', true);
    } catch (Throwable $e) { error_log('[api_security] auth failed: ' . $e->getMessage()); }
}

/**
 * A refused request (403 or 404) by a valid key - called from apiError(). Too many in
 * 10 minutes is someone probing: administrators are told, and at the limit the key is
 * switched off. "Forbidden" and "does not exist" are deliberately answered alike, so
 * this one counter is also how enumeration of ids shows up. Never throws, never calls apiError.
 *
 * @param int $status  the HTTP status being sent
 * @param string $code  the error code being sent
 */
function apiRefusalRecord(PDO $conn, array $key, int $status, string $code): void
{
    static $busy = false;
    if ($busy || !apiSecurityReady($conn) || !in_array($status, [403, 404], true)) return;
    // Refusals the key's own restrictions raise are logged already, under their own names.
    if (in_array($code, ['ip_not_allowed', 'outside_allowed_hours', 'key_disabled', 'key_expired'], true)) return;
    $busy = true;
    try {
        $keyId = (int)$key['id'];
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $path = substr((string)($_GET['path'] ?? parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)), 0, 200);
        apiSecurityEvent($conn, $keyId, 'refused', $ip, $status . ' ' . $code . ' ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . $path);
        $st = $conn->prepare("SELECT COUNT(*) FROM api_key_events WHERE api_key_id = ? AND event = 'refused' AND created_datetime > UTC_TIMESTAMP() - INTERVAL 10 MINUTE");
        $st->execute([$keyId]);
        $n = (int)$st->fetchColumn();
        $s = apiSecuritySettings($conn);
        $alert = (int)$s['api_refusal_alert']; $limit = (int)$s['api_refusal_limit'];
        if ($limit > 0 && $n >= $limit) {
            $reason = 'Switched off on ' . gmdate('Y-m-d H:i') . ' UTC: ' . $n . ' refused requests in 10 minutes (possible probing).';
            $conn->prepare("UPDATE api_keys SET active = 0, suspended_reason = ? WHERE id = ? AND active = 1")->execute([$reason, $keyId]);
            apiSecurityEvent($conn, $keyId, 'key_suspended', $ip, $reason);
            apiSecurityNotifyAdmins($conn, $keyId, 'API key switched off - probing',
                'API key "' . $key['name'] . '" was refused ' . $n . ' times in 10 minutes, from ' . $ip . ' - it may be trying ids it should not. It has been switched off. Check System -> API -> Security before switching it back on.', true);
        } elseif ($alert > 0 && $n >= $alert) {
            apiSecurityAlertOnce($conn, $keyId, 'refusals', $ip,
                'API key "' . $key['name'] . '" has been refused ' . $n . ' times in 10 minutes, from ' . $ip . ' - it may be trying ids it should not.' . ($limit > 0 ? ' It will be switched off at ' . $limit . '.' : ''));
        }
    } catch (Throwable $e) {
        error_log('[api_security] refusal: ' . $e->getMessage());
    } finally {
        $busy = false;
    }
}

// ── The download guard ───────────────────────────────────────────────────────

/**
 * The limits for one key: its own overrides, otherwise the install's.
 *
 * @return array{alert_files:int, alert_bytes:int, limit_files:int, limit_bytes:int, action:string}
 */
function apiDownloadLimits(PDO $conn, array $key): array
{
    $s = apiSecuritySettings($conn);
    $own = fn($col, $fallback) => (isset($key[$col]) && $key[$col] !== null && (int)$key[$col] > 0) ? (int)$key[$col] : (int)$fallback;
    return [
        'alert_files' => $own('download_alert_files', $s['api_download_alert_files']),
        'alert_bytes' => (int)$s['api_download_alert_mb'] * 1048576,
        'limit_files' => $own('download_limit_files', $s['api_download_limit_files']),
        'limit_bytes' => $own('download_limit_mb', $s['api_download_limit_mb']) * 1048576,
        'action'      => $s['api_download_limit_action'] === 'suspend' ? 'suspend' : 'refuse',
    ];
}

/** A key's downloads over the last 60 minutes. @return array{files:int, bytes:int} */
function apiDownloadUsage(PDO $conn, int $keyId): array
{
    $st = $conn->prepare("SELECT COUNT(*) AS files, COALESCE(SUM(bytes), 0) AS bytes FROM document_access_log
                           WHERE api_key_id = ? AND action = 'download' AND created_datetime > UTC_TIMESTAMP() - INTERVAL 60 MINUTE");
    $st->execute([$keyId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['files' => (int)($r['files'] ?? 0), 'bytes' => (int)($r['bytes'] ?? 0)];
}

/**
 * Before a document is sent: would this download take the key past its limit?
 * Refuses (429) - or switches the key off and refuses (403) - and logs it; at
 * the alert level tells administrators, once an hour. Call BEFORE reading the file.
 *
 * @param array $key    the api_keys row
 * @param int $bytes    the size of the file about to be sent
 * @param int $documentId  for the log
 */
function apiDownloadGuard(PDO $conn, array $key, int $bytes, int $documentId): void
{
    if (!apiSecurityReady($conn)) return;
    $keyId = (int)$key['id'];
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $lim = apiDownloadLimits($conn, $key);
    $use = apiDownloadUsage($conn, $keyId);
    $files = $use['files'] + 1;
    $total = $use['bytes'] + max(0, $bytes);
    $mb = fn($b) => round($b / 1048576, 1) . ' MB';

    if ($files > $lim['limit_files'] || $total > $lim['limit_bytes']) {
        $what = $files > $lim['limit_files'] ? $lim['limit_files'] . ' files' : $mb($lim['limit_bytes']);
        $conn->prepare("INSERT INTO document_access_log (document_id, analyst_id, action, ip_address, api_key_id, bytes) VALUES (?,?,?,?,?,0)")
             ->execute([$documentId, (int)$key['analyst_id'], 'refused', $ip, $keyId]);
        if ($lim['action'] === 'suspend') {
            $reason = 'Switched off by the download guard on ' . gmdate('Y-m-d H:i') . ' UTC: more than ' . $what . ' in an hour.';
            $conn->prepare("UPDATE api_keys SET active = 0, suspended_reason = ? WHERE id = ?")->execute([$reason, $keyId]);
            apiSecurityEvent($conn, $keyId, 'key_suspended', $ip, $reason);
            apiSecurityNotifyAdmins($conn, $keyId, 'API key switched off - too many downloads',
                'API key "' . $key['name'] . '" tried to download more than ' . $what . ' of documents in an hour, from ' . $ip . '. It has been switched off. Check System -> API -> Security before switching it back on.', true);
            apiError(403, 'key_disabled', 'This API key has been switched off: it reached its download limit.');
        }
        apiSecurityEvent($conn, $keyId, 'download_limit', $ip, 'Download refused: more than ' . $what . ' in an hour.');
        apiSecurityAlertOnce($conn, $keyId, 'download_limit', $ip, 'API key "' . $key['name'] . '" reached its download limit (' . $what . ' in an hour), from ' . $ip . '. Further downloads are refused until the hour has passed.');
        apiError(429, 'download_limit', 'This API key has reached its download limit for the hour.');
    }
    if ($files >= $lim['alert_files'] || $total >= $lim['alert_bytes']) {
        apiSecurityAlertOnce($conn, $keyId, 'download_alert', $ip,
            'API key "' . $key['name'] . '" has downloaded ' . $files . ' documents (' . $mb($total) . ') in the last hour, from ' . $ip . '. It will be stopped at ' . $lim['limit_files'] . ' files or ' . $mb($lim['limit_bytes']) . '.');
    }
}

/**
 * Record a download (or a followed link) made through the API.
 *
 * @param string $action  'download' or 'follow'
 */
function apiDownloadRecord(PDO $conn, array $key, int $documentId, string $action, int $bytes): void
{
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    if (apiSecurityReady($conn)) {
        $conn->prepare("INSERT INTO document_access_log (document_id, analyst_id, action, ip_address, api_key_id, bytes) VALUES (?,?,?,?,?,?)")
             ->execute([$documentId, (int)$key['analyst_id'], $action, $ip, (int)$key['id'], $bytes]);
    } else {
        $conn->prepare("INSERT INTO document_access_log (document_id, analyst_id, action, ip_address) VALUES (?,?,?,?)")
             ->execute([$documentId, (int)$key['analyst_id'], $action, $ip]);
    }
}

// ── The log and the alerts ───────────────────────────────────────────────────

/** Write one row of the security log. Never throws. */
function apiSecurityEvent(PDO $conn, ?int $keyId, string $event, string $ip, string $detail): void
{
    try {
        $conn->prepare("INSERT INTO api_key_events (api_key_id, event, ip_address, detail, created_datetime) VALUES (?,?,?,?, UTC_TIMESTAMP())")
             ->execute([$keyId, $event, $ip !== '' ? $ip : null, mb_substr($detail, 0, 500)]);
    } catch (Throwable $e) { error_log('[api_security] event: ' . $e->getMessage()); }
}

/**
 * Tell administrators - at most once per key per event per hour, so a burst of
 * refused requests is one alert, not hundreds.
 */
function apiSecurityAlertOnce(PDO $conn, int $keyId, string $event, string $ip, string $message): void
{
    try {
        $st = $conn->prepare("SELECT 1 FROM api_key_events WHERE api_key_id = ? AND event = ? AND created_datetime > UTC_TIMESTAMP() - INTERVAL 60 MINUTE LIMIT 1");
        $st->execute([$keyId, $event . '_alerted']);
        if ($st->fetchColumn()) return;
        apiSecurityEvent($conn, $keyId, $event . '_alerted', $ip, 'Administrators told.');
        $titles = ['download_alert' => 'API key downloading a lot of documents', 'download_limit' => 'API key reached its download limit',
                   'ip_refused' => 'API key used from an address it may not use', 'outside_hours' => 'API key used outside its hours',
                   'refusals' => 'API key refused again and again', 'admin_key_refused' => 'Administrator API key refused'];
        apiSecurityNotifyAdmins($conn, $keyId, $titles[$event] ?? 'API security alert', $message, true);
    } catch (Throwable $e) { error_log('[api_security] alert: ' . $e->getMessage()); }
}

/**
 * The bell for every active administrator, and an email when asked - from the
 * first mailbox that can send. Never throws; with no mailbox the log says so.
 */
function apiSecurityNotifyAdmins(PDO $conn, int $keyId, string $title, string $message, bool $email): void
{
    $admins = [];
    try {
        $admins = $conn->query("SELECT id, email FROM analysts WHERE is_admin = 1 AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) { return; }
    try {
        require_once __DIR__ . '/services/notifications.php';
        foreach ($admins as $a) {
            NotificationsService::notify($conn, [
                // entity_id must be > 0 for the bell; an address-only alert (no key) points at 1 - the link is the Security tab either way.
                'analyst_id' => (int)$a['id'], 'event_type' => 'api.security', 'entity_type' => 'api_key', 'entity_id' => max(1, $keyId),
                'title' => mb_substr($title, 0, 255), 'body' => mb_substr($message, 0, 500), 'actor_name' => null,
            ]);
        }
    } catch (Throwable $e) { error_log('[api_security] bell: ' . $e->getMessage()); }
    if (!$email) return;
    try {
        require_once __DIR__ . '/mailbox_send.php';
        require_once __DIR__ . '/mailbox_graph.php';
        $mailbox = null;
        foreach ($conn->query("SELECT * FROM target_mailboxes WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $c = decryptMailboxRow($row);
            if (mailboxCanSend($c)) { $mailbox = $c; break; }
        }
        if (!$mailbox) { apiSecurityEvent($conn, $keyId, 'alert_not_emailed', '', 'No mailbox can send, so administrators were told by the bell only.'); return; }
        $html = '<p>' . htmlspecialchars($message) . '</p><p>System &rarr; API &rarr; Security has the full log.</p>';
        foreach ($admins as $a) {
            if (filter_var((string)$a['email'], FILTER_VALIDATE_EMAIL)) mailboxSendSimple($conn, $mailbox, (string)$a['email'], 'FreeITSM: ' . $title, $html, 'api_security');
        }
    } catch (Throwable $e) { error_log('[api_security] email: ' . $e->getMessage()); }
}

// ── Saving a key's restrictions (System -> API) ──────────────────────────────

/**
 * The restriction columns a key save sets, validated: allowed_ips, allowed_hours and
 * the download overrides. Only the fields present in $input; an empty value clears.
 *
 * @param array $input  the request: allowed_ips (text), allowed_hours ({days, from, to, tz, mode} or null),
 *                      download_alert_files / download_limit_files / download_limit_mb (int or blank)
 * @return array<string, mixed> column => value
 * @throws Exception with a message for the person saving, when something is not valid
 */
function apiKeyRestrictionValues(PDO $conn, array $input): array
{
    if (!apiSecurityReady($conn)) return [];
    $out = [];
    if (array_key_exists('allowed_ips', $input)) {
        $parsed = apiAllowedIpsParse((string)($input['allowed_ips'] ?? ''));
        if ($parsed['bad']) throw new Exception('Not an address or range: ' . implode(', ', $parsed['bad']));
        $out['allowed_ips'] = $parsed['ok'] ? implode("\n", $parsed['ok']) : null;
    }
    if (array_key_exists('allowed_hours', $input)) {
        if ($input['allowed_hours'] === null || $input['allowed_hours'] === '' || $input['allowed_hours'] === []) {
            $out['allowed_hours'] = null;
        } else {
            $h = apiAllowedHoursClean($input['allowed_hours']);
            if ($h === null) throw new Exception('Allowed hours need at least one day, and a start and end time that differ.');
            $out['allowed_hours'] = json_encode($h);
        }
    }
    foreach (['download_alert_files', 'download_limit_files', 'download_limit_mb'] as $col) {
        if (!array_key_exists($col, $input)) continue;
        $v = $input[$col];
        $out[$col] = ($v === null || $v === '' || (int)$v <= 0) ? null : min(1000000, (int)$v);
    }
    return $out;
}

/** Does this key act as an administrator (now - the analyst may have been promoted since)? */
function apiKeyIsAdmin(PDO $conn, array $key): bool
{
    try {
        $st = $conn->prepare("SELECT is_admin FROM analysts WHERE id = ?");
        $st->execute([(int)($key['analyst_id'] ?? 0)]);
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable) {
        return false;
    }
}

// ── The activity log (System -> API -> Activity) ─────────────────────────────

/** api_request_log exists (Database Verification has run since 5.1.0). */
function apiActivityReady(PDO $conn): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $conn->query("SELECT id FROM api_request_log LIMIT 0"); return $ok = true; } catch (Throwable) { return $ok = false; }
}

/**
 * Start recording this call: one row in api_request_log when the request ends -
 * however it ends (a response, an error, a download). Call first thing in an entry
 * point (api/v1/index.php, api/mcp/index.php). Never the contents of the request
 * or the response; never throws.
 *
 * @param string $channel  'rest' or 'mcp'
 */
function apiActivityStart(string $channel): void
{
    $GLOBALS['FREEITSM_API_STARTED'] = microtime(true);
    register_shutdown_function(function () use ($channel) {
        try {
            $conn = $GLOBALS['FREEITSM_API_CONN'] ?? null;
            if (!$conn instanceof PDO) $conn = connectToDatabase();
            if (!apiActivityReady($conn)) return;
            $status = (int)(http_response_code() ?: 200);
            if ($status < 400 && apiSecuritySettings($conn)['api_log_successes'] !== '1') return;
            $key = $GLOBALS['FREEITSM_API_KEY'] ?? null;
            // Bytes sent: a Content-Length the response set (a download), else what
            // apiRespond()/apiError() wrote (they note it), else unknown.
            $bytes = $GLOBALS['FREEITSM_API_BYTES'] ?? null;
            foreach (headers_list() as $h) if (stripos($h, 'Content-Length:') === 0) $bytes = (int)trim(substr($h, 15));
            $path = (string)($_GET['path'] ?? parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
            $conn->prepare("INSERT INTO api_request_log (api_key_id, channel, method, path, status, error_code, duration_ms, bytes_out, ip_address)
                            VALUES (?,?,?,?,?,?,?,?,?)")->execute([
                is_array($key) ? (int)$key['id'] : null, $channel,
                substr((string)($_SERVER['REQUEST_METHOD'] ?? ''), 0, 8), mb_substr($path, 0, 255), $status,
                isset($GLOBALS['FREEITSM_API_ERROR_CODE']) ? substr((string)$GLOBALS['FREEITSM_API_ERROR_CODE'], 0, 50) : null,
                (int)round((microtime(true) - (float)($GLOBALS['FREEITSM_API_STARTED'] ?? microtime(true))) * 1000),
                $bytes, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]);
        } catch (Throwable $e) {
            error_log('[api_security] activity: ' . $e->getMessage());   // never fails the call
        }
    });
}

/** Where archived days of the activity log are kept. */
function apiLogArchiveDir(): string
{
    return dirname(__DIR__) . '/uploads/api-log-archive';
}

/**
 * The zip a day belongs in, by System -> API -> Activity -> "One zip per":
 * 2026-11-03 (day), 2026-W45 (ISO week) or 2026-11 (month).
 *
 * @param string $day  YYYY-MM-DD
 * @param string $period  day | week | month
 */
function apiLogArchivePeriodKey(string $day, string $period): string
{
    $t = strtotime($day . ' 12:00:00 UTC');
    return match ($period) { 'day' => gmdate('Y-m-d', $t), 'week' => gmdate('o-\WW', $t), default => gmdate('Y-m', $t) };
}

/**
 * The api_log_tidy job: rows older than api_log_keep_days are deleted - or, when the
 * setting says archive, first added to their period's zip (one CSV per day inside) in
 * uploads/api-log-archive/. The job runs daily, so the log is trimmed to exactly the
 * days kept; "One zip per" decides how the days are grouped - a month in one zip
 * however often the job runs. A day is only deleted once the zip holding it is
 * written and checked. At most $maxDays days per run, oldest first.
 *
 * @return array{days:int, rows:int, archived:int, files:string[]}
 */
function apiLogTidy(PDO $conn, int $maxDays = 60): array
{
    $out = ['days' => 0, 'rows' => 0, 'archived' => 0, 'files' => []];
    if (!apiActivityReady($conn)) return $out;
    $s = apiSecuritySettings($conn);
    $keep = max(1, (int)$s['api_log_keep_days']);
    $archive = $s['api_log_old_rows'] === 'archive';
    $period = in_array($s['api_log_archive_period'], ['day', 'week', 'month'], true) ? $s['api_log_archive_period'] : 'month';
    $cutoff = gmdate('Y-m-d', time() - $keep * 86400) . ' 00:00:00';
    $days = $conn->prepare("SELECT DISTINCT DATE(created_datetime) AS d FROM api_request_log WHERE created_datetime < ? ORDER BY d LIMIT " . max(1, $maxDays));
    $days->execute([$cutoff]);
    foreach ($days->fetchAll(PDO::FETCH_COLUMN) as $day) {
        $from = $day . ' 00:00:00';
        $to = gmdate('Y-m-d', strtotime($day . ' 00:00:00 UTC +1 day')) . ' 00:00:00';   // TRAP: UTC - a local +1 day printed in UTC is the same day in summer time
        if ($archive) {
            $n = apiLogArchiveDay($conn, $day, $from, $to, apiLogArchivePeriodKey($day, $period), $name, $period);
            $out['archived'] += $n;
            if ($name !== '' && !in_array($name, $out['files'], true)) $out['files'][] = $name;
        }
        $del = $conn->prepare("DELETE FROM api_request_log WHERE created_datetime >= ? AND created_datetime < ?");
        $del->execute([$from, $to]);
        $out['rows'] += $del->rowCount();
        $out['days']++;
    }
    return $out;
}

/**
 * Add one day's rows to its period's zip, as api-log-YYYY-MM-DD.csv inside.
 *
 * A zip is NAMED by its first and last record: api-log-20261001-20261031.zip. So
 * the period's zip is found by the period of the first date in its name, and when a
 * later day is added it is renamed to end on that day.
 *
 * Built on a copy and checked before it replaces the zip, so a failure leaves the old
 * zip intact and nothing deleted. A day already in the zip (archived before, with rows
 * added since) gets a -2, -3... file beside it rather than overwriting it.
 *
 * @param string $periodKey  from apiLogArchivePeriodKey()
 * @param string $period  day | week | month - to recognise the period's zip
 * @param ?string $name  set to the zip's (new) file name
 * @return int the rows written
 */
function apiLogArchiveDay(PDO $conn, string $day, string $from, string $to, string $periodKey, ?string &$name, string $period = 'month'): int
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Archiving needs the PHP zip extension - set old rows to Delete, or install it.');
    $dir = apiLogArchiveDir();
    // Created with its deny-all guards (.htaccess, web.config), like every upload folder.
    require_once __DIR__ . '/uploads.php';
    uploadPrepareDir($dir);
    $csv = fopen('php://temp', 'w+');
    fputcsv($csv, ['id', 'time_utc', 'api_key_id', 'key_name', 'channel', 'method', 'path', 'status', 'error_code', 'duration_ms', 'bytes_out', 'ip_address'], ',', '"', '');
    $rows = $conn->prepare("SELECT l.*, k.name AS key_name FROM api_request_log l LEFT JOIN api_keys k ON k.id = l.api_key_id
                             WHERE l.created_datetime >= ? AND l.created_datetime < ? ORDER BY l.created_datetime, l.id");
    $rows->execute([$from, $to]);
    $n = 0; $first = null; $last = null;
    while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($csv, [$r['id'], $r['created_datetime'], $r['api_key_id'], $r['key_name'], $r['channel'], $r['method'], $r['path'], $r['status'],
            $r['error_code'], $r['duration_ms'], $r['bytes_out'], $r['ip_address']], ',', '"', '');
        $stamp = str_replace('-', '', substr((string)$r['created_datetime'], 0, 10));
        $first = $first === null ? $stamp : min($first, $stamp);
        $last  = $last === null ? $stamp : max($last, $stamp);
        $n++;
    }
    rewind($csv);
    if ($n === 0) { fclose($csv); $name = ''; return 0; }

    // The period's zip so far, if any: the one whose first date falls in this period.
    $existing = null;
    foreach (apiLogArchives() as $arc) {
        if (apiLogArchivePeriodKey(apiLogStampToDay($arc['from']), $period) === $periodKey) { $existing = $arc; break; }
    }
    if ($existing) { $first = min($first, $existing['from']); $last = max($last, $existing['to']); }
    $name = 'api-log-' . $first . '-' . $last . '.zip';
    $path = $dir . '/' . $name;
    $oldPath = $existing ? $dir . '/' . $existing['name'] : null;
    $tmp = $path . '.part';
    if ($oldPath && !copy($oldPath, $tmp)) throw new RuntimeException('Cannot copy ' . $existing['name']);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) throw new RuntimeException("Cannot write $name");
    $inner = 'api-log-' . $day . '.csv';
    for ($i = 2; $zip->locateName($inner) !== false; $i++) $inner = 'api-log-' . $day . '-' . $i . '.csv';
    $zip->addFromString($inner, stream_get_contents($csv));
    $zip->close();
    fclose($csv);
    // Checked before anything is deleted: it opens, and holds this day.
    $check = new ZipArchive();
    if ($check->open($tmp) !== true || $check->locateName($inner) === false) { @unlink($tmp); throw new RuntimeException("The archive for $day did not check out - nothing deleted."); }
    $check->close();
    if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException("Cannot write $name"); }
    if ($oldPath && $oldPath !== $path) @unlink($oldPath);   // renamed to its new last date
    return $n;
}

/** A name's datestamp (20261031) as a day (2026-10-31). */
function apiLogStampToDay(string $stamp): string
{
    return substr($stamp, 0, 4) . '-' . substr($stamp, 4, 2) . '-' . substr($stamp, 6, 2);
}

/** The archives, newest first: name, from and to (datestamps of the first and last record), size. */
function apiLogArchives(): array
{
    $out = [];
    foreach (glob(apiLogArchiveDir() . '/api-log-*.zip') ?: [] as $f) {
        if (!preg_match(API_LOG_ARCHIVE_NAME, basename($f), $m)) continue;
        $out[] = ['name' => basename($f), 'from' => $m[1], 'to' => $m[2], 'bytes' => (int)filesize($f)];
    }
    usort($out, fn($a, $b) => strcmp($b['from'], $a['from']));
    return $out;
}

/** An archive's name: api-log-<first record>-<last record>.zip, each a YYYYMMDD datestamp. */
const API_LOG_ARCHIVE_NAME = '/^api-log-(\d{8})-(\d{8})\.zip$/';
