<?php
/**
 * Attachment text extraction worker — cron entry point (discussion #53, tier 2).
 *
 * Reads attachments sitting at `pending` in `attachment_text` and asks the
 * configured extraction service (System → Integrations → Apache Tika) for their
 * text. Tier 1 formats never reach here; they are read inline in milliseconds.
 * This is for the slow ones — PDFs, and anything needing OCR, which can take
 * minutes per document and must not run inside a web request.
 *
 * Every 5 minutes is a sensible default. Latency is roughly your cron interval.
 *
 * ⚠️ It is NOT the only way the queue drains. FreeITSM also takes a few items
 * opportunistically when somebody is already using it, so an installation with
 * no cron still works — see includes/search/extract_queue.php. Either mechanism
 * can be switched off in Tickets → Settings → Indexing.
 *
 * SECURITY (HTTP invocation only), mirroring cron/webhook_deliveries.php:
 *   - Shared-secret token via ?token=<value> matching `webhook_cron_token` in
 *     system_settings (hash_equals). The same token the other crons use, so an
 *     administrator has one secret to manage rather than four.
 * CLI invocation skips the token — there is no untrusted caller.
 */

set_time_limit(600);   // OCR is slow; a batch of scanned documents needs room
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';
require_once __DIR__ . '/../includes/search/extract_queue.php';
require_once __DIR__ . '/../includes/job_runs.php';   // run history (#182)

/** Attachments per run. Bounded so one run cannot become open-ended. */
const EXTRACT_CRON_BATCH = 25;

$isCli = (PHP_SAPI === 'cli');
$trigger = JobRun::scheduledTrigger();
$runId = null;

try {
    $conn = connectToDatabase();

    if (!$isCli) {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'webhook_cron_token'");
        $st->execute();
        $expected = decryptValue($st->fetchColumn() ?: null);
        if (empty($expected)) {
            http_response_code(503);
            JobRun::refused($conn, 'attachment_extract', 'token not set');
            echo "Cron token not set. Run Database Verification to seed webhook_cron_token.\n";
            exit;
        }
        if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) {
            http_response_code(403);
            JobRun::refused($conn, 'attachment_extract', 'wrong token');
            echo "Forbidden\n";
            exit;
        }
    }

    // The administrator's switch. Off means off — including for a cron someone
    // scheduled and forgot about.
    if (!extractQueueSettingOn($conn, EXTRACT_SETTING_CRON)) {
        JobRun::skipped($conn, 'attachment_extract', $trigger, 'Scheduled extraction is switched off.');
        echo "Scheduled extraction is switched off in Tickets > Settings > Indexing.\n";
        exit;
    }

    if (!tikaConfigured($conn)) {
        JobRun::skipped($conn, 'attachment_extract', $trigger, 'No extraction service configured.');
        echo "No extraction service configured (System > Integrations > Apache Tika). Nothing to do.\n";
        exit;
    }

    // ⚠️ Overlap guard. A five-minute schedule with a run that takes ten gives you
    // two workers on one queue. The claim in extractQueueDrain() makes that SAFE,
    // but it is still two processes doing half the work each and competing for
    // the extractor, so the second one is better off not starting. The minimum
    // interval below reads then writes, so on its own two callers can both pass
    // it: the lock is what makes it one run (#233).
    if (!JobRun::lock($conn, 'attachment_extract', $trigger)) exit;
    $MIN_INTERVAL = 60;   // seconds
    $lastRun = null;
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'attachment_extract_last_run'");
        $st->execute();
        $lastRun = $st->fetchColumn() ?: null;
    } catch (Exception $e) { /* first run */ }

    if ($lastRun) {
        $age = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, UTC_TIMESTAMP())");
        $age->execute([$lastRun]);
        $secs = (int)$age->fetchColumn();
        if ($secs >= 0 && $secs < $MIN_INTERVAL) {
            JobRun::skipped($conn, 'attachment_extract', $trigger, "Minimum interval: last run {$secs}s ago, minimum {$MIN_INTERVAL}s.");
            echo "Skipped: last run was {$secs}s ago (minimum {$MIN_INTERVAL}s).\n";
            exit;
        }
    }
    $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value)
         VALUES ('attachment_extract_last_run', UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value = UTC_TIMESTAMP()"
    )->execute();

    $runId = JobRun::start($conn, 'attachment_extract', $trigger);
    $waiting = extractQueueDepth($conn);
    if ($waiting === 0) {
        JobRun::finish($conn, $runId, 'ok', ['waiting' => 0], 'Nothing pending.');
        echo "Nothing pending.\n";
        exit;
    }

    $started = microtime(true);
    $res     = extractQueueDrain($conn, EXTRACT_CRON_BATCH);
    $secs    = round(microtime(true) - $started, 1);

    printf("waiting %d, processed %d tickets in %ss, %d still pending%s\n",
        $waiting, $res['done'], $secs, $res['still_pending'],
        $res['skipped_reason'] !== '' ? ' (' . $res['skipped_reason'] . ')' : '');

    // A queue that did not move is worth saying out loud: it means the service
    // is unreachable, and the run looks identical to a successful one otherwise.
    // A queue that did not move is a FAILURE for health (#182): the run looks
    // identical to a good one otherwise, which is exactly the silent stop #182 is about.
    $stuck = $res['still_pending'] >= $waiting && $res['done'] > 0;
    if ($stuck) {
        echo "WARNING: nothing cleared. The extraction service is probably unreachable.\n";
    }
    JobRun::finish($conn, $runId, $stuck ? 'failed' : 'ok', ['waiting' => $waiting, 'processed' => $res['done'], 'still_pending' => $res['still_pending']],
        $stuck ? 'Nothing cleared: the extraction service is probably unreachable.' : "Processed {$res['done']}, {$res['still_pending']} still pending.");
    if ($stuck) exit(1);
} catch (Throwable $e) {
    if (isset($conn)) JobRun::fail($conn, $runId, $e);
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
