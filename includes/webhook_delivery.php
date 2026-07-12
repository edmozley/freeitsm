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
        catch (Exception $e) { $ok = false; }
    }
    return $ok;
}

/** Encrypt if we can; fall back to plaintext rather than breaking the webhook. */
function webhookEncrypt(?string $v): ?string {
    if ($v === null || $v === '') return $v;
    try { return encryptValue($v); }
    catch (Exception $e) {
        error_log('[webhook] storing value unencrypted — ' . $e->getMessage());
        return $v;
    }
}

/** Decrypt; plaintext passes straight through (pre-encryption rows still work). */
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
    } catch (Exception $e) {
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

/** Seconds to wait before the next retry, keyed by the attempt that just failed. */
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
        (int)($d['max_attempts'] ?? 6),
    ]);
    return (int)$conn->lastInsertId();
}

/**
 * Perform the outbound HTTP request for a webhook and return the raw outcome.
 * Shared by the delivery worker and the editor's "Send test" preview so both
 * use identical transport (method, timeouts, TLS verification) — the test can
 * never behave differently from a real delivery.
 *
 * @return array{body:string|false,status:int,error:string,ms:int}
 */
function webhookHttpSend(string $url, array $headers, string $body, string $method = 'POST'): array {
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method ?: 'POST',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $respBody = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    return ['body' => $respBody, 'status' => $status, 'error' => $err, 'ms' => (int)round((microtime(true) - $start) * 1000)];
}

/**
 * Attempt to deliver one already-claimed row (status must be 'delivering').
 * Updates the row with the outcome and returns 'delivered' | 'failed' | 'dead'.
 */
function webhookAttemptDelivery(PDO $conn, array $row): string {
    $headers = json_decode($row['request_headers'] ?: '[]', true) ?: [];
    $attemptNo = (int)$row['attempts'] + 1;

    // The stored URL is encrypted at rest — decrypt for the wire only.
    $url = webhookDecrypt($row['url']);

    $res      = webhookHttpSend($url, $headers, (string)($row['request_body'] ?? ''), $row['method'] ?: 'POST');
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
 */
function webhookRunQueue(PDO $conn, int $limit = 50): array {
    $counts = ['attempted' => 0, 'delivered' => 0, 'failed' => 0, 'dead' => 0];
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
        $claim->execute([$id]);
        if ($claim->rowCount() === 0) continue; // another worker took it
        $load->execute([$id]);
        $row = $load->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue;
        $counts['attempted']++;
        $counts[webhookAttemptDelivery($conn, $row)]++;
    }
    return $counts;
}

/** Reset a delivery so the worker sends it again (the "Replay" button). */
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
