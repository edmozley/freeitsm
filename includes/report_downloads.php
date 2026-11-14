<?php
/**
 * Emailed reports (5.3.0): how a report reaches the analyst's inbox, and the
 * guarded download link that is one of the ways.
 *
 * System -> Emailed reports sets which of three ways are used, any combination:
 *   - in the BODY of the email (the HTML version of the report);
 *   - as an ATTACHMENT (the PDF) - the only one that works on an install nobody
 *     can reach from outside, which is why it exists;
 *   - as a DOWNLOAD LINK, which opens only for the analyst it was sent to, signed
 *     in, until it expires (1, 7 or 30 days) or an administrator revokes it.
 *
 * THE LINK
 *   - token: 32 random bytes as 64 hex; only its SHA-256 is stored, so a copy of
 *     the database cannot be turned back into working links;
 *   - the PDF is stored as uploads/report-downloads/<32 hex>.bin - nothing from the
 *     caller reaches the file system, the folder is denied to the web
 *     (uploadPrepareDir) and the only way to the bytes is
 *     api/system/report_download.php;
 *   - every create, download, revoke and refusal is written to system_logs as
 *     'report_download' (the existing audit log, as sign-ins are) with the analyst,
 *     IP address, browser and outcome;
 *   - the scheduled job report_downloads_tidy deletes the files of links that have
 *     expired or been revoked (the row is kept and marked file_deleted_at), and
 *     removes rows REPORT_DL_KEEP_DAYS after they expired.
 *
 * TRAP: every function here copes with the table not existing yet (before Database
 *   Verification has run): the link option is then simply unavailable and the
 *   other two still work. reportDownloadsReady() is the one test.
 *
 * @wiki Outbound-Email-Attachments-Developer-Guide
 */

require_once __DIR__ . '/uploads.php';      // uploadPrepareDir()
require_once __DIR__ . '/public_url.php';   // publicBaseUrl(), publicBaseUrlSetting()

/** Days a download link may last - the only values the setting accepts. */
const REPORT_LINK_DAY_CHOICES = [1, 7, 30];

/** Rows are removed this many days after their link expired (the files go at expiry). */
const REPORT_DL_KEEP_DAYS = 90;

/** The audit log type in system_logs. */
const REPORT_DL_LOG_TYPE = 'report_download';

/**
 * A string from the system.emailed_reports namespace, or the English given when
 * i18n is not loaded (cron, tests) or has no such key - like tf() in JavaScript.
 *
 * @param string $key  the key inside system.emailed_reports, e.g. 'none_ticked'
 * @param string $english  the English, with {name} placeholders
 * @param array $params  values for the placeholders
 */
function reportT(string $key, string $english, array $params = []): string {
    if (function_exists('t')) {
        $full = 'system.emailed_reports.' . $key;
        $v = t($full, $params);
        if ($v !== $full && $v !== '') return $v;
    }
    return preg_replace_callback('/\{(\w+)\}/', fn($m) => array_key_exists($m[1], $params) ? (string)$params[$m[1]] : $m[0], $english);
}

/**
 * The delivery settings, with their defaults: body and attachment on, link off,
 * links last 7 days. A missing or unreadable setting is the default.
 *
 * @return array{body: bool, attach: bool, link: bool, link_days: int}
 */
function reportEmailSettings(PDO $conn): array {
    $s = ['body' => true, 'attach' => true, 'link' => false, 'link_days' => 7];
    try {
        $rows = $conn->query("SELECT setting_key, setting_value FROM system_settings
                               WHERE setting_key IN ('report_email_body','report_email_attach','report_email_link','report_link_days')")
                     ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        return $s;
    }
    foreach (['body' => 'report_email_body', 'attach' => 'report_email_attach', 'link' => 'report_email_link'] as $k => $key) {
        if (isset($rows[$key]) && $rows[$key] !== '') $s[$k] = $rows[$key] === '1';
    }
    $days = (int)($rows['report_link_days'] ?? 0);
    if (in_array($days, REPORT_LINK_DAY_CHOICES, true)) $s['link_days'] = $days;
    // A stored "nothing ticked" cannot be saved, but if one is ever found the
    // report still has to arrive: fall back to the defaults.
    if (!$s['body'] && !$s['attach'] && !$s['link']) { $s['body'] = true; $s['attach'] = true; }
    return $s;
}

/**
 * Save the delivery settings. Throws InvalidArgumentException, with a sentence
 * fit to show, when nothing is ticked or the days are not one of the choices.
 *
 * @param array $in  body, attach, link (truthy/falsy), link_days (1, 7 or 30)
 */
function reportEmailSaveSettings(PDO $conn, array $in): array {
    $body = !empty($in['body']); $attach = !empty($in['attach']); $link = !empty($in['link']);
    if (!$body && !$attach && !$link) {
        throw new InvalidArgumentException(reportT('none_ticked', 'Tick at least one way to email reports.'));
    }
    $days = (int)($in['link_days'] ?? 7);
    if (!in_array($days, REPORT_LINK_DAY_CHOICES, true)) {
        throw new InvalidArgumentException(reportT('bad_days', 'Download links can last 1, 7 or 30 days.'));
    }
    $st = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach (['report_email_body' => $body ? '1' : '0', 'report_email_attach' => $attach ? '1' : '0',
              'report_email_link' => $link ? '1' : '0', 'report_link_days' => (string)$days] as $k => $v) {
        $st->execute([$k, $v]);
    }
    return reportEmailSettings($conn);
}

/** Does the report_downloads table exist? False before Database Verification has added it. */
function reportDownloadsReady(PDO $conn): bool {
    try {
        $conn->query('SELECT 1 FROM report_downloads LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Where the stored PDFs live, created and denied to the web on first use. No trailing slash. */
function reportDownloadsDir(): string {
    $dir = str_replace('\\', '/', dirname(__DIR__)) . '/uploads/report-downloads';
    uploadPrepareDir($dir);
    return $dir;
}

/** The SHA-256 of a link token - what is stored and looked up. */
function reportDownloadHash(string $token): string {
    return hash('sha256', $token);
}

/**
 * The address the download links start with, or '' when it looks reachable from
 * elsewhere. Returned (non-empty) when nothing is configured and the address comes
 * from a browser or BASE_URL alone, or when its host is localhost, a private or
 * loopback address, a .local name or a name with no dot - places a link in an
 * email will not open from anywhere else. For the warning under the link tick.
 */
function reportLinkReachabilityWarning(PDO $conn): string {
    $base = publicBaseUrl($conn);
    $host = strtolower((string)parse_url($base, PHP_URL_HOST));
    if ($host === '') return $base !== '' ? $base : '/';
    $host = trim($host, '[]');
    if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) return $base;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        return $public ? '' : $base;
    }
    return strpos($host, '.') === false ? $base : '';
}

/**
 * Record one event in the audit log (system_logs, type 'report_download').
 * Never throws: an audit row that cannot be written must not break a download.
 *
 * @param ?int $analystId  who did it (null when nobody is signed in)
 * @param string $action  create | download | refused | revoke | tidy
 * @param string $outcome  ok, or why it was refused: wrong_analyst | expired | revoked | unknown | missing_file | not_ready
 * @param array $extra  download_id, filename, owner_id and anything else worth keeping
 */
function reportDownloadAudit(PDO $conn, ?int $analystId, string $action, string $outcome, array $extra = []): void {
    try {
        $details = json_encode($extra + [
            'action'     => $action,
            'outcome'    => $outcome,
            'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'cli' : 'unknown')),
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
             ->execute([REPORT_DL_LOG_TYPE, $analystId, $details]);
    } catch (Throwable $e) {
        error_log('report download audit failed: ' . $e->getMessage());
    }
}

/**
 * Store a report and make its link. Returns the token (shown ONCE, in the email)
 * and the absolute URL. Throws when the table is missing or the file cannot be written.
 *
 * @param int $analystId  the owner - the only analyst the link will ever open for
 * @param string $filename  the name it downloads as, already sanitised
 * @param string $mime  its content type
 * @param string $bytes  the file
 * @param int $days  how long the link lasts (one of REPORT_LINK_DAY_CHOICES)
 * @return array{id: int, token: string, url: string, expires_at: string}
 */
function reportDownloadCreate(PDO $conn, int $analystId, string $filename, string $mime, string $bytes, int $days): array {
    if (!reportDownloadsReady($conn)) throw new RuntimeException('Run Database Verification to add download links.');
    if (!in_array($days, REPORT_LINK_DAY_CHOICES, true)) $days = 7;
    $token = bin2hex(random_bytes(32));
    $rel = bin2hex(random_bytes(16)) . '.bin';
    $path = reportDownloadsDir() . '/' . $rel;
    if (@file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
        @unlink($path);
        throw new RuntimeException('The report could not be stored for download.');
    }
    $now = gmdate('Y-m-d H:i:s');
    $expires = gmdate('Y-m-d H:i:s', time() + $days * 86400);
    try {
        $conn->prepare("INSERT INTO report_downloads
                          (token_hash, analyst_id, filename, mime, size_bytes, storage_path, created_at, expires_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
             ->execute([reportDownloadHash($token), $analystId, substr($filename, 0, 255), substr($mime, 0, 100),
                        strlen($bytes), $rel, $now, $expires]);
    } catch (Throwable $e) {
        @unlink($path);
        throw $e;
    }
    $id = (int)$conn->lastInsertId();
    reportDownloadAudit($conn, $analystId, 'create', 'ok', ['download_id' => $id, 'filename' => $filename, 'days' => $days]);
    return ['id' => $id, 'token' => $token, 'url' => reportDownloadUrl($conn, $token), 'expires_at' => $expires];
}

/** The absolute link for a token, on the install's public address. */
function reportDownloadUrl(PDO $conn, string $token): string {
    return publicAbsoluteUrl($conn, 'api/system/report_download.php?t=' . $token);
}

/**
 * Decide whether $analystId may have the file behind $token. Audits every answer.
 *
 * @return array{ok: bool, status: int, outcome: string, row: ?array, path: ?string}
 *   outcome: ok | not_ready | unknown | wrong_analyst | revoked | expired | missing_file
 */
function reportDownloadAuthorise(PDO $conn, string $token, int $analystId): array {
    $out = function (bool $ok, int $status, string $outcome, ?array $row = null, ?string $path = null) use ($conn, $analystId) {
        reportDownloadAudit($conn, $analystId, $ok ? 'download' : 'refused', $outcome, $row ? [
            'download_id' => (int)$row['id'], 'filename' => $row['filename'], 'owner_id' => (int)$row['analyst_id'],
        ] : []);
        return ['ok' => $ok, 'status' => $status, 'outcome' => $outcome, 'row' => $row, 'path' => $path];
    };
    if (!reportDownloadsReady($conn)) return $out(false, 404, 'not_ready');
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) return $out(false, 404, 'unknown');

    $hash = reportDownloadHash($token);
    $st = $conn->prepare('SELECT * FROM report_downloads WHERE token_hash = ?');
    $st->execute([$hash]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    // The row was found BY the hash; compare again in constant time so the answer
    // never rests on how the database compared two strings.
    if (!$row || !hash_equals((string)$row['token_hash'], $hash)) return $out(false, 404, 'unknown');
    if ((int)$row['analyst_id'] !== $analystId) return $out(false, 403, 'wrong_analyst', $row);
    if ($row['revoked_at'] !== null) return $out(false, 410, 'revoked', $row);
    if (strtotime($row['expires_at'] . ' UTC') <= time()) return $out(false, 410, 'expired', $row);
    $path = reportDownloadsDir() . '/' . basename((string)$row['storage_path']);
    if ($row['file_deleted_at'] !== null || !is_file($path)) return $out(false, 410, 'missing_file', $row);

    $conn->prepare('UPDATE report_downloads SET download_count = download_count + 1, last_downloaded_at = UTC_TIMESTAMP() WHERE id = ?')
         ->execute([(int)$row['id']]);
    return $out(true, 200, 'ok', $row, $path);
}

/**
 * Revoke a link and delete its file at once. False when there is no such link
 * or it was already revoked.
 *
 * @param int $id  the report_downloads id
 * @param int $byAnalystId  the administrator revoking it
 */
function reportDownloadRevoke(PDO $conn, int $id, int $byAnalystId): bool {
    $st = $conn->prepare('SELECT * FROM report_downloads WHERE id = ? AND revoked_at IS NULL');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $conn->prepare('UPDATE report_downloads SET revoked_at = UTC_TIMESTAMP(), revoked_by = ? WHERE id = ?')->execute([$byAnalystId, $id]);
    reportDownloadDeleteFile($conn, $row);
    reportDownloadAudit($conn, $byAnalystId, 'revoke', 'ok', ['download_id' => $id, 'filename' => $row['filename'], 'owner_id' => (int)$row['analyst_id']]);
    return true;
}

/** Delete a link's stored file and mark the row. */
function reportDownloadDeleteFile(PDO $conn, array $row): void {
    if (($row['storage_path'] ?? '') !== '') {
        @unlink(reportDownloadsDir() . '/' . basename((string)$row['storage_path']));
    }
    $conn->prepare('UPDATE report_downloads SET file_deleted_at = UTC_TIMESTAMP() WHERE id = ? AND file_deleted_at IS NULL')
         ->execute([(int)$row['id']]);
}

/**
 * The scheduled tidy-up: delete the files of expired and revoked links, remove
 * rows REPORT_DL_KEEP_DAYS after expiry, and delete any stored file no row knows
 * (an analyst deleted with their links) once it is a day old.
 *
 * @return array{files: int, rows: int, orphans: int}
 */
function reportDownloadsTidy(PDO $conn): array {
    $r = ['files' => 0, 'rows' => 0, 'orphans' => 0];
    if (!reportDownloadsReady($conn)) return $r;
    $rows = $conn->query("SELECT * FROM report_downloads WHERE file_deleted_at IS NULL
                           AND (expires_at <= UTC_TIMESTAMP() OR revoked_at IS NOT NULL)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) { reportDownloadDeleteFile($conn, $row); $r['files']++; }
    $st = $conn->prepare('DELETE FROM report_downloads WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL ? DAY)');
    $st->execute([REPORT_DL_KEEP_DAYS]);
    $r['rows'] = $st->rowCount();

    $known = array_flip($conn->query('SELECT storage_path FROM report_downloads')->fetchAll(PDO::FETCH_COLUMN));
    foreach (glob(reportDownloadsDir() . '/*.bin') ?: [] as $f) {
        if (!isset($known[basename($f)]) && filemtime($f) < time() - 86400) { @unlink($f); $r['orphans']++; }
    }
    if ($r['files'] || $r['rows'] || $r['orphans']) reportDownloadAudit($conn, null, 'tidy', 'ok', $r);
    return $r;
}

/**
 * The links for the administrators' list, newest first, with owner names.
 *
 * @param int $limit  how many rows
 */
function reportDownloadsList(PDO $conn, int $limit = 200): array {
    if (!reportDownloadsReady($conn)) return [];
    $st = $conn->prepare("SELECT d.id, d.analyst_id, a.full_name AS owner_name, d.filename, d.size_bytes, d.created_at,
                                 d.expires_at, d.revoked_at, d.download_count, d.last_downloaded_at, d.file_deleted_at
                            FROM report_downloads d LEFT JOIN analysts a ON a.id = d.analyst_id
                           ORDER BY d.id DESC LIMIT " . max(1, min(1000, $limit)));
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Recent audit events for the administrators' list, newest first.
 *
 * @param int $limit  how many rows
 */
function reportDownloadsActivity(PDO $conn, int $limit = 50): array {
    try {
        $st = $conn->prepare("SELECT l.created_datetime, l.analyst_id, a.full_name AS analyst_name, l.details
                                FROM system_logs l LEFT JOIN analysts a ON a.id = l.analyst_id
                               WHERE l.log_type = ? ORDER BY l.id DESC LIMIT " . max(1, min(500, $limit)));
        $st->execute([REPORT_DL_LOG_TYPE]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as &$r) { $r['details'] = json_decode((string)$r['details'], true) ?: []; }
    return $rows;
}
