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

/** Seconds to wait before the next retry, keyed by the attempt that just failed. */
function webhookBackoffSeconds(int $failedAttempt): int {
    static $schedule = [1 => 60, 2 => 300, 3 => 900, 4 => 3600, 5 => 21600]; // 1m, 5m, 15m, 1h, 6h
    return $schedule[$failedAttempt] ?? 21600;
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
    $stmt->execute([
        $d['workflow_id'] ?? null,
        $d['execution_id'] ?? null,
        $d['preset'] ?? null,
        (string)$d['url'],
        strtoupper($d['method'] ?? 'POST'),
        json_encode(array_values($d['headers'] ?? [])),
        (string)($d['body'] ?? ''),
        (int)($d['max_attempts'] ?? 6),
    ]);
    return (int)$conn->lastInsertId();
}

/**
 * Attempt to deliver one already-claimed row (status must be 'delivering').
 * Updates the row with the outcome and returns 'delivered' | 'failed' | 'dead'.
 */
function webhookAttemptDelivery(PDO $conn, array $row): string {
    $headers = json_decode($row['request_headers'] ?: '[]', true) ?: [];
    $attemptNo = (int)$row['attempts'] + 1;

    $ch = curl_init($row['url']);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $row['method'] ?: 'POST',
        CURLOPT_POSTFIELDS     => $row['request_body'] ?? '',
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

    $ok      = ($respBody !== false && $err === '' && $status >= 200 && $status < 300);
    $snippet = $respBody === false ? null : mb_substr((string)$respBody, 0, 500);
    $error   = $ok ? null : mb_substr($err !== '' ? $err : ('HTTP ' . $status), 0, 500);

    if ($ok) {
        $conn->prepare(
            "UPDATE webhook_deliveries
             SET status='delivered', attempts=?, last_status_code=?, last_error=NULL,
                 response_snippet=?, delivered_datetime=UTC_TIMESTAMP(), next_attempt_at=NULL,
                 updated_datetime=UTC_TIMESTAMP()
             WHERE id=?"
        )->execute([$attemptNo, $status, $snippet, $row['id']]);
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
         WHERE id=? AND status IN ('delivered','failed','dead')"
    );
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}
