<?php
/**
 * Outbound webhook delivery — shared between the workflow engine (which
 * ENQUEUES) and the cron worker (which DELIVERS).
 *
 * The `send_webhook` workflow action builds the request (URL, headers incl. any
 * HMAC signature, rendered JSON body) and calls webhookEnqueue() — returning
 * instantly, so a slow or dead endpoint never blocks the host request. The
 * worker (cron/webhook_deliveries.php) later picks up due rows and calls
 * webhookRunQueue(), which delivers each with retries and exponential backoff,
 * dead-lettering after max_attempts. Every outcome is recorded on the row, so
 * the delivery log is the single source of truth for what was sent and when.
 */

require_once __DIR__ . '/encryption.php';

// -----------------------------------------------------------------------------
//  Secrets at rest
//
//  A webhook URL is a bearer credential (anyone holding a Slack/Discord URL can
//  post into that channel) and the signing secret is a true secret — its whole
//  job is proving a message genuinely came from us, which a reader of the
//  database could otherwise forge. Both are therefore encrypted at rest, in
//  workflows.actions AND in webhook_deliveries.url.
//
//  BEST-EFFORT BY DESIGN. The encryption key is a file an admin creates; not
//  every install has one. If encryptValue() throws because the key is missing,
//  we store the value as we always did rather than break webhooks outright —
//  and System > Webhooks says so plainly instead of implying protection that
//  isn't there. decryptValue() already passes non-"ENC:" values through
//  untouched, so plaintext rows written before this change keep working and get
//  encrypted the next time they're saved. No backfill, no migration window.
// -----------------------------------------------------------------------------

/** True when an encryption key is actually usable — drives the honesty banner. */
function webhookEncryptionAvailable(): bool {
    static $ok = null;
    if ($ok === null) {
        try { getEncryptionKey(); $ok = true; }
        catch (Exception) { $ok = false; }
    }
    return $ok;
}

/**
 * Encrypt if we can; fall back to plaintext rather than breaking the webhook.
 *
 * @param ?string $v  the value to store, e.g. a webhook URL; null and '' come back unchanged
 */
function webhookEncrypt(?string $v): ?string {
    if ($v === null || $v === '') return $v;
    try { return encryptValue($v); }
    catch (Exception $e) {
        error_log('[webhook] storing value unencrypted — ' . $e->getMessage());
        return $v;
    }
}

/**
 * Decrypt; plaintext passes straight through (pre-encryption rows still work).
 *
 * @param ?string $v  a stored value, encrypted or (on older rows) plain; null and '' come back unchanged
 */
function webhookDecrypt(?string $v): ?string {
    if ($v === null || $v === '') return $v;
    try { return decryptValue($v); }
    catch (Exception $e) {
        error_log('[webhook] could not decrypt a stored value — ' . $e->getMessage());
        return '';
    }
}

/**
 * A URL safe to show in the delivery log: keeps the bit that identifies WHERE
 * it went, hides the bit that lets you POST there. A Discord webhook URL ends
 * in a token; printing it in a log everyone can read defeats encrypting it.
 *
 *   https://discord.com/api/webhooks/142.../aBcD-secret  →  https://discord.com/api/webhooks/…/••••
 *
 * @param ?string $url  the decrypted webhook URL
 */
function webhookRedactUrl(?string $url): string {
    $u = (string)$url;
    if ($u === '') return '';
    $parts = @parse_url($u);
    if (!$parts || empty($parts['host'])) return '(hidden)';
    $scheme = $parts['scheme'] ?? 'https';
    $path   = $parts['path'] ?? '';
    // Keep the leading path segments for recognisability, mask the last one
    // (which is where the token lives on every provider we support).
    $segs = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
    if (count($segs) > 1) {
        array_pop($segs);
        $keep = '/' . implode('/', array_slice($segs, 0, 3)) . '/…/••••';
    } else {
        $keep = $segs ? '/…/••••' : '/••••';
    }
    return $scheme . '://' . $parts['host'] . $keep;
}

// -----------------------------------------------------------------------------
//  Payload retention
//
//  webhook_deliveries.request_body holds the FULL rendered payload — and with
//  the Full-record preset that is an entire ticket (subject, requester, the
//  lot), copied in plaintext into a second table and kept forever. That is a
//  bigger data-at-rest question than the URL ever was, so it gets an explicit,
//  admin-visible answer rather than an accidental one.
//
//  This is DISTINCT from the pre-existing `webhook_delivery_retention_days`
//  (default 30), which deletes the whole ROW. This one blanks just the BODIES
//  and keeps the row, so it can run on a SHORTER clock: the sensitive payload
//  goes early, while the delivery record (status, timing, endpoint, error) —
//  the part you actually need for an audit — survives to the row's own horizon.
//  Defaults to 7 days on that reasoning. Replay is a "something just broke"
//  tool used within hours, not weeks.
//
//  The purge BLANKS the bodies but KEEPS the rows, so the dashboard's
//  success-rate / volume / timing stats survive intact. The trade-off: Replay
//  needs the body, so a purged delivery can no longer be replayed — the replay
//  endpoint says so rather than silently POSTing an EMPTY payload to a live
//  endpoint, which would be far worse than refusing.
// -----------------------------------------------------------------------------

const WEBHOOK_RETENTION_SETTING = 'webhook_payload_retention_days';
const WEBHOOK_RETENTION_DEFAULT = 7;    // days — shorter than the row's own retention
const WEBHOOK_RETENTION_NEVER   = 0;    // scrub the moment a delivery settles
const WEBHOOK_RETENTION_FOREVER = -1;   // keep bodies as long as the row lives

/** Days to keep payload bodies. 0 = never store, -1 = forever. */
function webhookPayloadRetentionDays(PDO $conn): int {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([WEBHOOK_RETENTION_SETTING]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null || $v === '') return WEBHOOK_RETENTION_DEFAULT;
        return (int)$v;
    } catch (Exception) {
        return WEBHOOK_RETENTION_DEFAULT;
    }
}

/**
 * Blank the stored request/response bodies of deliveries past the retention
 * window. Returns how many rows were scrubbed. Called by the cron worker after
 * each queue run, so retention is enforced without another scheduled task.
 */
function webhookPurgePayloads(PDO $conn): int {
    $days = webhookPayloadRetentionDays($conn);
    if ($days === WEBHOOK_RETENTION_FOREVER) return 0;   // admin chose to keep them
    if ($days === WEBHOOK_RETENTION_NEVER)   $days = 0;  // scrub as soon as they're settled

    // Only touch settled rows — never scrub a payload still queued or retrying,
    // which would leave the worker nothing to send.
    $stmt = $conn->prepare(
        "UPDATE webhook_deliveries
            SET request_body = NULL, response_snippet = NULL, payload_purged = 1,
                updated_datetime = updated_datetime
          WHERE payload_purged = 0
            AND status IN ('delivered', 'dead')
            AND created_datetime < (UTC_TIMESTAMP() - INTERVAL ? DAY)"
    );
    $stmt->execute([$days]);
    return $stmt->rowCount();
}

// -----------------------------------------------------------------------------
//  Delivery settings (System > Webhooks)
//
//  How many times a delivery is tried, how long each try waits for the endpoint,
//  and what happens to a try the worker never finished. Each is a short list the
//  page offers - the save endpoint refuses anything else - and each default is
//  what FreeITSM did before the setting existed.
//
//  An INTERRUPTED delivery is one still marked 'delivering' long after any try
//  could have finished: the worker was killed mid-send (time limit, restart,
//  crash). Nobody knows whether the endpoint got it. Before these settings such
//  a row stayed 'delivering' for ever - never retried, never replayable.
// -----------------------------------------------------------------------------

const WEBHOOK_ATTEMPTS_SETTING    = 'webhook_max_attempts';
const WEBHOOK_ATTEMPTS_DEFAULT    = 6;
const WEBHOOK_ATTEMPTS_ALLOWED    = [1, 3, 6, 10];
const WEBHOOK_TIMEOUT_SETTING     = 'webhook_timeout_seconds';
const WEBHOOK_TIMEOUT_DEFAULT     = 15;
const WEBHOOK_TIMEOUT_ALLOWED     = [5, 10, 15, 30, 60];
const WEBHOOK_INTERRUPTED_SETTING = 'webhook_interrupted_action';
const WEBHOOK_INTERRUPTED_RETRY   = 'retry';   // count it as a failed try and retry on the normal schedule
const WEBHOOK_INTERRUPTED_STOP    = 'stop';    // give up on it; an admin decides with Replay
const WEBHOOK_INTERRUPTED_DEFAULT = WEBHOOK_INTERRUPTED_RETRY;
// Far longer than the longest try (60s timeout + 5s connect) and the worker's own
// 120s time limit, so a row this old in 'delivering' cannot still be in flight.
const WEBHOOK_INTERRUPTED_AFTER_SECONDS = 600;

/** @return array{max_attempts:int, timeout:int, interrupted:string} */
function webhookDeliverySettings(PDO $conn): array {
    $cfg = [
        'max_attempts' => WEBHOOK_ATTEMPTS_DEFAULT,
        'timeout'      => WEBHOOK_TIMEOUT_DEFAULT,
        'interrupted'  => WEBHOOK_INTERRUPTED_DEFAULT,
    ];
    try {
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (?, ?, ?)");
        $stmt->execute([WEBHOOK_ATTEMPTS_SETTING, WEBHOOK_TIMEOUT_SETTING, WEBHOOK_INTERRUPTED_SETTING]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception) {
        return $cfg;
    }
    // A value outside the offered list (hand-edited, or from a later version) falls
    // back to the default rather than being trusted.
    $n = (int)($rows[WEBHOOK_ATTEMPTS_SETTING] ?? 0);
    if (in_array($n, WEBHOOK_ATTEMPTS_ALLOWED, true)) $cfg['max_attempts'] = $n;
    $n = (int)($rows[WEBHOOK_TIMEOUT_SETTING] ?? 0);
    if (in_array($n, WEBHOOK_TIMEOUT_ALLOWED, true)) $cfg['timeout'] = $n;
    $v = (string)($rows[WEBHOOK_INTERRUPTED_SETTING] ?? '');
    if (in_array($v, [WEBHOOK_INTERRUPTED_RETRY, WEBHOOK_INTERRUPTED_STOP], true)) $cfg['interrupted'] = $v;
    return $cfg;
}

/**
 * Settle every delivery an earlier worker run claimed and never finished.
 * Returns how many were settled.
 *
 * 'retry' records the interrupted try as a failed attempt, so it is retried on
 * the normal backoff and still goes dead at max_attempts - the endpoint may get
 * it twice, which the X-FreeITSM-Delivery header lets a receiver detect. 'stop'
 * marks it dead at once, where Replay can send it again by hand.
 *
 * @param string $action  WEBHOOK_INTERRUPTED_RETRY or WEBHOOK_INTERRUPTED_STOP
 */
function webhookRecoverInterrupted(PDO $conn, string $action): int {
    $stuck = $conn->prepare(
        "SELECT id, attempts, max_attempts FROM webhook_deliveries
          WHERE status = 'delivering' AND updated_datetime < (UTC_TIMESTAMP() - INTERVAL ? SECOND)"
    );
    $stuck->execute([WEBHOOK_INTERRUPTED_AFTER_SECONDS]);

    $error = 'Interrupted: the delivery worker stopped before the endpoint answered, so it may or may not have arrived.';
    // WHERE status = 'delivering' again, so a row another worker settled in the
    // meantime is left alone.
    $retry = $conn->prepare(
        "UPDATE webhook_deliveries
            SET status='failed', attempts=?, last_status_code=NULL, last_error=?,
                next_attempt_at = UTC_TIMESTAMP() + INTERVAL ? SECOND, updated_datetime=UTC_TIMESTAMP()
          WHERE id=? AND status='delivering'"
    );
    $dead = $conn->prepare(
        "UPDATE webhook_deliveries
            SET status='dead', attempts=?, last_status_code=NULL, last_error=?,
                next_attempt_at=NULL, updated_datetime=UTC_TIMESTAMP()
          WHERE id=? AND status='delivering'"
    );

    $settled = 0;
    foreach ($stuck->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $attemptNo = (int)$r['attempts'] + 1;
        if ($action === WEBHOOK_INTERRUPTED_RETRY && $attemptNo < (int)$r['max_attempts']) {
            $retry->execute([$attemptNo, $error, webhookBackoffSeconds($attemptNo), $r['id']]);
            $settled += $retry->rowCount();
        } else {
            $dead->execute([$attemptNo, $error, $r['id']]);
            if ($dead->rowCount() > 0) {
                $settled++;
                webhookScrubIfNoRetention($conn, (int)$r['id']);
            }
        }
    }
    return $settled;
}

/**
 * Seconds to wait before the next retry, keyed by the attempt that just failed.
 *
 * @param int $failedAttempt  the attempt number that just failed, from 1; beyond 5 waits 6 hours
 */
function webhookBackoffSeconds(int $failedAttempt): int {
    static $schedule = [1 => 60, 2 => 300, 3 => 900, 4 => 3600, 5 => 21600]; // 1m, 5m, 15m, 1h, 6h
    return $schedule[$failedAttempt] ?? 21600;
}

/**
 * Turn a raw cURL transport error into something a human can act on.
 *
 * A message like "SSL certificate problem: unable to get local issuer
 * certificate" is perfectly accurate and completely useless to most people —
 * it says what failed, not what to DO. Worse, its cause usually isn't the
 * webhook at all: it's that PHP has no CA bundle configured, which on a stock
 * Windows/WAMP install is the default state. Every outbound HTTPS call in the
 * product fails the same way, so it's worth naming explicitly.
 *
 * Returns null when we've nothing useful to add (the raw error stands on its
 * own), otherwise a diagnosis the UI renders in place of a shrug:
 *   ['code', 'title', 'summary', 'help' => absolute URL | null]
 *
 * Called at RENDER time against the stored last_error, so old rows get the
 * benefit too — no schema column, nothing to backfill.
 *
 * @param ?string $err  the stored last_error (a cURL error or 'HTTP nnn'); null or '' gives null
 */
function webhookDiagnoseError(?string $err): ?array {
    $e = trim((string)$err);
    if ($e === '') return null;
    $l = strtolower($e);

    // Wording differs by platform and cURL build — Windows/OpenSSL says
    // "SSL certificate problem: unable to get local issuer certificate",
    // Linux/GnuTLS says "server certificate verification failed. CAfile: none".
    // Match all the variants that mean "I can't establish trust".
    $isTlsTrust = str_contains($l, 'unable to get local issuer certificate')
        || str_contains($l, 'certificate verify failed')
        || str_contains($l, 'certificate verification failed')
        || str_contains($l, 'ssl certificate problem')
        || str_contains($l, 'self-signed certificate')
        || str_contains($l, 'self signed certificate')
        || str_contains($l, 'unable to get issuer certificate')
        || str_contains($l, 'cafile: none');

    if ($isTlsTrust) {
        return [
            'code'    => 'tls_trust',
            'title'   => 'This server can\'t verify the endpoint\'s HTTPS certificate',
            'summary' => 'Almost always this means PHP has no list of trusted certificate authorities '
                       . '(a "CA bundle") configured — not that anything is wrong with the webhook or with '
                       . 'the endpoint. It affects every outbound HTTPS call, not just this one. '
                       . 'It is a one-time server fix and takes a couple of minutes.',
            'help'    => (defined('BASE_URL') ? BASE_URL : '/') . 'workflow/help-ssl.php',
        ];
    }

    if (str_contains($l, 'could not resolve host') || str_contains($l, 'name or service not known')) {
        return [
            'code'    => 'dns',
            'title'   => 'The hostname in the webhook URL could not be found',
            'summary' => 'DNS could not resolve it. Check the URL for a typo, and that this server is allowed to reach the internet.',
            'help'    => null,
        ];
    }

    if (str_contains($l, 'connection refused') || str_contains($l, 'failed to connect')) {
        return [
            'code'    => 'refused',
            'title'   => 'The endpoint refused the connection',
            'summary' => 'The host was found but nothing accepted the connection — the service may be down, or a firewall may be blocking this server.',
            'help'    => null,
        ];
    }

    if (str_contains($l, 'timed out') || str_contains($l, 'timeout')) {
        return [
            'code'    => 'timeout',
            'title'   => 'The endpoint took too long to respond',
            'summary' => 'The request was abandoned after the timeout. If the endpoint is simply slow this will usually succeed on a retry.',
            'help'    => null,
        ];
    }

    return null;
}

/**
 * Queue a webhook for asynchronous delivery. $d keys: workflow_id, execution_id,
 * preset, url, method, headers (array of header lines), body (string), max_attempts.
 * Returns the new delivery id.
 *
 * @param array $d  the delivery: workflow_id, execution_id, preset, url, method, headers, body, max_attempts (default: the System > Webhooks setting)
 */
function webhookEnqueue(PDO $conn, array $d): int {
    $stmt = $conn->prepare(
        "INSERT INTO webhook_deliveries
         (workflow_id, execution_id, preset, url, method, request_headers, request_body,
          status, attempts, max_attempts, next_attempt_at, created_datetime, updated_datetime)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    // NB the body is always written here, even when retention is "don't store":
    // delivery is ASYNCHRONOUS, so the worker has nothing to send unless the
    // payload is persisted until it's been sent. "Don't store" therefore means
    // "scrub it the moment the delivery settles" — see webhookScrubIfNoRetention().
    $stmt->execute([
        $d['workflow_id'] ?? null,
        $d['execution_id'] ?? null,
        $d['preset'] ?? null,
        webhookEncrypt((string)$d['url']),          // bearer credential — encrypted at rest
        strtoupper($d['method'] ?? 'POST'),
        json_encode(array_values($d['headers'] ?? [])),
        (string)($d['body'] ?? ''),
        // Fixed per row when queued, so changing the setting does not move the
        // goalposts for a delivery that is already part-way through its retries.
        (int)($d['max_attempts'] ?? webhookDeliverySettings($conn)['max_attempts']),
    ]);
    return (int)$conn->lastInsertId();
}

/**
 * Perform the outbound HTTP request for a webhook and return the raw outcome.
 * Shared by the delivery worker and the editor's "Send test" preview so both
 * use identical transport (method, timeouts, TLS verification) — the test can
 * never behave differently from a real delivery.
 *
 * @param string $url  the full endpoint URL, decrypted
 * @param array $headers  header lines, e.g. 'Content-Type: application/json'
 * @param string $body  the request body as sent
 * @param string $method  the HTTP method; '' means POST
 * @param int $timeout  seconds to wait for the whole request; the connect wait is capped at 5
 * @return array{body:string|false,status:int,error:string,ms:int}
 */
function webhookHttpSend(string $url, array $headers, string $body, string $method = 'POST', int $timeout = WEBHOOK_TIMEOUT_DEFAULT): array {
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method ?: 'POST',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    // Webhooks always verify — they carry record data to third parties over the
    // public internet, so there is deliberately no off switch (see help-ssl.php).
    sslApplyCurl($ch, true);
    $respBody = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    return ['body' => $respBody, 'status' => $status, 'error' => $err, 'ms' => (int)round((microtime(true) - $start) * 1000)];
}

/**
 * Attempt to deliver one already-claimed row (status must be 'delivering').
 * Updates the row with the outcome and returns 'delivered' | 'failed' | 'dead'.
 *
 * @param array $row  the webhook_deliveries row, already claimed (status 'delivering')
 * @param int $timeout  seconds to wait for the endpoint (the System > Webhooks setting)
 */
function webhookAttemptDelivery(PDO $conn, array $row, int $timeout = WEBHOOK_TIMEOUT_DEFAULT): string {
    $headers = json_decode($row['request_headers'] ?: '[]', true) ?: [];
    // The same on every try and on a Replay, so a receiver can drop a repeat - an
    // interrupted try is retried without knowing whether the first one arrived.
    // Added at send time, not stored: it is not part of what the HMAC signs.
    $headers[] = 'X-FreeITSM-Delivery: ' . (int)$row['id'];
    $attemptNo = (int)$row['attempts'] + 1;

    // The stored URL is encrypted at rest — decrypt for the wire only.
    $url = webhookDecrypt($row['url']);

    $res      = webhookHttpSend($url, $headers, (string)($row['request_body'] ?? ''), $row['method'] ?: 'POST', $timeout);
    $respBody = $res['body'];
    $status   = $res['status'];
    $err      = $res['error'];

    $ok      = ($respBody !== false && $err === '' && $status >= 200 && $status < 300);
    // Store the response body in full (capped only to stay within the TEXT column's
    // ~64KB limit) so the delivery log can show exactly what the endpoint returned.
    $snippet = $respBody === false ? null : mb_substr((string)$respBody, 0, 60000);
    $error   = $ok ? null : mb_substr($err !== '' ? $err : ('HTTP ' . $status), 0, 500);

    if ($ok) {
        $conn->prepare(
            "UPDATE webhook_deliveries
             SET status='delivered', attempts=?, last_status_code=?, last_error=NULL,
                 response_snippet=?, delivered_datetime=UTC_TIMESTAMP(), next_attempt_at=NULL,
                 updated_datetime=UTC_TIMESTAMP()
             WHERE id=?"
        )->execute([$attemptNo, $status, $snippet, $row['id']]);
        webhookScrubIfNoRetention($conn, (int)$row['id']);
        return 'delivered';
    }

    $dead = $attemptNo >= (int)$row['max_attempts'];
    if ($dead) {
        $conn->prepare(
            "UPDATE webhook_deliveries
             SET status='dead', attempts=?, last_status_code=?, last_error=?, response_snippet=?,
                 next_attempt_at=NULL, updated_datetime=UTC_TIMESTAMP()
             WHERE id=?"
        )->execute([$attemptNo, $status ?: null, $error, $snippet, $row['id']]);
        webhookScrubIfNoRetention($conn, (int)$row['id']);
        return 'dead';
    }
    $conn->prepare(
        "UPDATE webhook_deliveries
         SET status='failed', attempts=?, last_status_code=?, last_error=?, response_snippet=?,
             next_attempt_at = UTC_TIMESTAMP() + INTERVAL ? SECOND, updated_datetime=UTC_TIMESTAMP()
         WHERE id=?"
    )->execute([$attemptNo, $status ?: null, $error, $snippet, webhookBackoffSeconds($attemptNo), $row['id']]);
    return 'failed';
}

/**
 * Retention "don't store": the payload had to exist long enough for the worker
 * to send it, so we scrub it the instant the delivery settles rather than
 * waiting for the next purge sweep. A no-op on every other retention setting.
 *
 * @param int $id  the webhook_deliveries id
 */
function webhookScrubIfNoRetention(PDO $conn, int $id): void {
    if (webhookPayloadRetentionDays($conn) !== WEBHOOK_RETENTION_NEVER) return;
    try {
        $conn->prepare(
            "UPDATE webhook_deliveries
                SET request_body = NULL, response_snippet = NULL, payload_purged = 1
              WHERE id = ?"
        )->execute([$id]);
    } catch (Exception $e) {
        error_log('[webhook] scrub-on-settle failed for delivery ' . $id . ': ' . $e->getMessage());
    }
}

/**
 * Deliver up to $limit due rows. Claims each atomically (so overlapping worker
 * runs never double-send). Returns counts.
 *
 * First settles any delivery an earlier run claimed and never finished (see
 * webhookRecoverInterrupted). Then, with a $budgetSeconds, stops claiming new
 * rows once one more full-length try might not fit: being killed by the time
 * limit mid-send is exactly what leaves a row interrupted. What is left is due
 * on the next run.
 *
 * @param int $limit  the most due deliveries to attempt in this run
 * @param int $budgetSeconds  stop starting new tries after this long; 0 = no limit
 */
function webhookRunQueue(PDO $conn, int $limit = 50, int $budgetSeconds = 0): array {
    $started = microtime(true);
    $cfg     = webhookDeliverySettings($conn);
    $counts  = ['attempted' => 0, 'delivered' => 0, 'failed' => 0, 'dead' => 0,
                'interrupted' => webhookRecoverInterrupted($conn, $cfg['interrupted'])];
    $due = $conn->prepare(
        "SELECT id FROM webhook_deliveries
         WHERE status IN ('pending','failed') AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
         ORDER BY next_attempt_at IS NULL DESC, next_attempt_at ASC, id ASC
         LIMIT " . (int)$limit
    );
    $due->execute();
    $ids = $due->fetchAll(PDO::FETCH_COLUMN);

    $claim = $conn->prepare("UPDATE webhook_deliveries SET status='delivering', updated_datetime=UTC_TIMESTAMP() WHERE id=? AND status IN ('pending','failed')");
    $load  = $conn->prepare("SELECT * FROM webhook_deliveries WHERE id=?");
    foreach ($ids as $id) {
        if ($budgetSeconds > 0 && (microtime(true) - $started) + $cfg['timeout'] + 5 > $budgetSeconds) break;
        $claim->execute([$id]);
        if ($claim->rowCount() === 0) continue; // another worker took it
        $load->execute([$id]);
        $row = $load->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue;
        $counts['attempted']++;
        $counts[webhookAttemptDelivery($conn, $row, $cfg['timeout'])]++;
    }
    return $counts;
}

/**
 * Reset a delivery so the worker sends it again (the "Replay" button).
 *
 * @param int $id  the webhook_deliveries id to send again
 */
function webhookReplay(PDO $conn, int $id): bool {
    $stmt = $conn->prepare(
        "UPDATE webhook_deliveries
         SET status='pending', next_attempt_at=UTC_TIMESTAMP(), last_error=NULL, updated_datetime=UTC_TIMESTAMP()
         WHERE id=? AND status IN ('delivered','failed','dead') AND payload_purged = 0"
    );
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/**
 * Why a replay was refused — so the UI can say "the payload was purged by your
 * retention setting" rather than a generic failure. Replay re-sends the STORED
 * body, so a purged row has nothing to send and must not be re-queued (that
 * would POST an empty payload to a live endpoint).
 *
 * @param int $id  the webhook_deliveries id the replay was refused for
 */
function webhookReplayBlockedReason(PDO $conn, int $id): ?string {
    $stmt = $conn->prepare("SELECT status, payload_purged FROM webhook_deliveries WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 'That delivery no longer exists.';
    if ((int)$row['payload_purged'] === 1) {
        return 'The payload for this delivery has been purged by the payload-retention setting, '
             . 'so there is nothing left to re-send. Retention is configured on this page.';
    }
    if (!in_array($row['status'], ['delivered', 'failed', 'dead'], true)) {
        return 'That delivery is still in flight — wait for it to finish before replaying it.';
    }
    return null;
}

/**
 * Encrypt the url and secret of every send_webhook step in an action list - the
 * one place this is done, for every route that saves actions.
 *
 * 🔴 Only the browser's workflow save did it. POST and PATCH /workflows stored
 * them as given, and so did every form action list (form designer and REST), so a
 * Slack or Teams URL - a bearer token in all but name - and a signing secret sat
 * readable in the database and every backup. Reported privately, October 2026.
 * Idempotent: an "ENC:" value is not encrypted again, so a GET-then-PATCH round
 * trip of ciphertext is safe.
 *
 * @param array $actions  a list of {type, args}
 */
function webhookEncryptActions(array $actions): array {
    return webhookMapWebhooks($actions, function (array $a): array {
        foreach (['url', 'secret'] as $k) {
            if (isset($a['args'][$k]) && $a['args'][$k] !== '') $a['args'][$k] = webhookEncrypt((string)$a['args'][$k]);
        }
        return $a;
    });
}

/**
 * Every send_webhook action in a list through $fn - at ANY depth (GH #364).
 *
 * TRAP: a workflow's actions can hold branches (branch_if / branch_switch) with their own
 * action lists. A walker that looks only at the top level stores a webhook inside a branch
 * in plain text, and - worse - the editor gets it back still encrypted and the next save
 * encrypts it again, so the webhook silently stops working. Self-contained on purpose
 * (this file is loaded without the workflow engine); the shape matches
 * WorkflowEngine::mapActionTree().
 *
 * @param array $actions  an actions list
 * @param callable $fn  fn(array $sendWebhookAction): array
 */
function webhookMapWebhooks(array $actions, callable $fn): array {
    foreach ($actions as $i => $a) {
        if (!is_array($a)) continue;
        $type = $a['type'] ?? '';
        if ($type === 'send_webhook') { $actions[$i] = $fn($a); continue; }
        if ($type !== 'branch_if' && $type !== 'branch_switch') continue;
        $args = is_array($a['args'] ?? null) ? $a['args'] : [];
        foreach (['branches', 'cases'] as $listKey) {
            foreach (is_array($args[$listKey] ?? null) ? $args[$listKey] : [] as $j => $path) {
                if (is_array($path['actions'] ?? null)) $args[$listKey][$j]['actions'] = webhookMapWebhooks($path['actions'], $fn);
            }
        }
        foreach (['otherwise', 'default'] as $k) {
            if (is_array($args[$k]['actions'] ?? null)) $args[$k]['actions'] = webhookMapWebhooks($args[$k]['actions'], $fn);
        }
        $actions[$i]['args'] = $args;
    }
    return $actions;
}

/**
 * The reverse, for an editor showing an action list to the person who owns it.
 *
 * @param array $actions  a list of {type, args}
 */
function webhookDecryptActions(array $actions): array {
    return webhookMapWebhooks($actions, function (array $a): array {
        foreach (['url', 'secret'] as $k) {
            if (isset($a['args'][$k]) && $a['args'][$k] !== '') $a['args'][$k] = webhookDecrypt((string)$a['args'][$k]);
        }
        return $a;
    });
}
