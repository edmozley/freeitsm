<?php
/**
 * The pending-extraction queue (discussion #53, tier 2).
 *
 * WHY A QUEUE AT ALL
 * ------------------
 * Tier 1 reads a `.docx` in milliseconds, so it happens inline. Tier 2 does not:
 * OCR on a scanned document takes seconds to minutes per page. Doing that inside
 * the mailbox poll would stall every ticket queued behind it, and inside a web
 * request it would simply time out.
 *
 * So an attachment that needs the external extractor is recorded as `pending`
 * and read later. `pending` is also what a REACHABILITY failure writes — see
 * tikaExtract() — because "the service was down for five minutes" and "this file
 * cannot be read" are different facts and must not share a status.
 *
 * TWO WAYS IT DRAINS, EACH WITH ITS OWN SWITCH
 * --------------------------------------------
 *   cron           the real answer for a real install: cron/attachment_extract.php
 *   opportunistic  a few items whenever somebody is already using FreeITSM
 *
 * Both exist because a cron-only design does nothing at all on an installation
 * that has not set one up — which includes every evaluation, and any host that
 * does not offer cron. Opportunistic draining means it works out of the box;
 * the cron means it keeps up under load. Either can be switched off in
 * Tickets → Settings → Indexing if it misbehaves.
 */

require_once __DIR__ . '/indexer.php';
require_once __DIR__ . '/tika.php';

/** Settings keys, both in `system_settings`. Default ON. */
const EXTRACT_SETTING_CRON          = 'attachment_extract_cron';
const EXTRACT_SETTING_OPPORTUNISTIC = 'attachment_extract_opportunistic';

/**
 * ⚠️ ONE item, and a hard few seconds, because this runs inside a request an
 * analyst is waiting on. The cron takes the slow ones; a page load must not.
 */
const EXTRACT_OPPORTUNISTIC_BATCH   = 1;
const EXTRACT_OPPORTUNISTIC_SECONDS = 8;

/** Read a boolean setting that defaults to ON when absent. */
function extractQueueSettingOn(PDO $conn, string $key): bool {
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') return true;   // unset = on
        return $v === '1' || $v === 1 || strtolower((string)$v) === 'true';
    } catch (Exception $e) {
        return true;
    }
}

/** How many attachments are waiting. */
function extractQueueDepth(PDO $conn): int {
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM attachment_text WHERE status = ?");
        $st->execute([ATT_TEXT_PENDING]);
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Read up to $limit pending attachments.
 *
 * ⚠️ Re-indexes the whole TICKET for each one rather than writing the corpus row
 * directly. That keeps a single definition of what a ticket's rows are (see
 * searchIndexTicket) — and since the extracted text is cached in
 * `attachment_text`, re-reading the ticket's other attachments costs nothing.
 *
 * Never throws. Returns what it managed.
 *
 * @return array{done:int,still_pending:int,skipped_reason:string}
 */
function extractQueueDrain(PDO $conn, int $limit, float $deadline = 0): array {
    $out = ['done' => 0, 'still_pending' => 0, 'skipped_reason' => ''];

    try {
        if (!tikaConfigured($conn)) {
            // Nothing can clear these. Not an error: an install may have had an
            // extractor and turned it off.
            $out['skipped_reason'] = 'no extractor configured';
            $out['still_pending']  = extractQueueDepth($conn);
            return $out;
        }

        // ⚠️ Self-heal rows that can never clear. A `pending` row whose format
        // this extractor is not asked about would be picked up forever, tried by
        // nothing, and left pending — a queue that looks busy and moves nothing.
        // Sending them back to `unsupported` is both true and terminal.
        //
        // This is not hypothetical: an earlier version of the "configure Tika"
        // save requeued every unsupported row indiscriminately, and a .ogg voice
        // recording and an .html file went round forever.
        try {
            $stuck = $conn->prepare(
                "SELECT t.attachment_id, a.filename
                   FROM attachment_text t
                   JOIN email_attachments a ON a.id = t.attachment_id
                  WHERE t.status = ?"
            );
            $stuck->execute([ATT_TEXT_PENDING]);
            $bad = [];
            foreach ($stuck->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!tikaHandles((string)$row['filename'])) $bad[] = (int)$row['attachment_id'];
            }
            if ($bad) {
                $in = implode(',', array_fill(0, count($bad), '?'));
                $conn->prepare("UPDATE attachment_text SET status = ? WHERE attachment_id IN ($in)")
                     ->execute(array_merge([ATT_TEXT_UNSUPPORTED], $bad));
            }
        } catch (Exception $e) { /* best effort */ }

        // ⚠️ Return abandoned claims to the queue first. A worker that dies
        // mid-file — a killed cron, a request that timed out — leaves rows in
        // `extracting` where nothing would ever look at them again.
        try {
            $conn->prepare(
                "UPDATE attachment_text SET status = ?
                  WHERE status = ?
                    AND extracted_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)"
            )->execute([ATT_TEXT_PENDING, ATT_TEXT_EXTRACTING, ATT_TEXT_CLAIM_STALE_MINUTES]);
        } catch (Exception $e) { /* best effort */ }

        // Oldest first, so a backlog drains in the order it arrived.
        $sel = $conn->prepare(
            "SELECT t.attachment_id
               FROM attachment_text t
              WHERE t.status = ?
           ORDER BY t.extracted_datetime ASC
              LIMIT " . max(1, (int)$limit)
        );
        $sel->execute([ATT_TEXT_PENDING]);
        $candidates = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));
        if (!$candidates) { $out['still_pending'] = extractQueueDepth($conn); return $out; }

        // ── THE CLAIM ───────────────────────────────────────────────────────
        // ⚠️ This is what makes two workers safe. Without it, a cron run and an
        // analyst opening a page select the SAME oldest rows and both send the
        // same files to the extractor — one answer, paid for twice, and with OCR
        // that is expensive.
        //
        // The UPDATE is atomic and conditional on the row still being `pending`,
        // so of two racing workers exactly one wins each row. rowCount() is not
        // enough to know WHICH were won, so the rows are re-read afterwards
        // filtered on this worker's claim.
        $in = implode(',', array_fill(0, count($candidates), '?'));
        $claim = $conn->prepare(
            "UPDATE attachment_text SET status = ?, extracted_datetime = UTC_TIMESTAMP()
              WHERE attachment_id IN ($in) AND status = ?"
        );
        $claim->execute(array_merge([ATT_TEXT_EXTRACTING], $candidates, [ATT_TEXT_PENDING]));
        if ($claim->rowCount() === 0) {
            // Another worker took all of them. Nothing to do, and no complaint:
            // this is the mechanism working.
            $out['skipped_reason'] = 'claimed by another worker';
            $out['still_pending']  = extractQueueDepth($conn);
            return $out;
        }

        $sel2 = $conn->prepare(
            "SELECT t.attachment_id, e.ticket_id
               FROM attachment_text t
               JOIN email_attachments a ON a.id = t.attachment_id
               JOIN emails e            ON e.id = a.email_id
              WHERE t.attachment_id IN ($in) AND t.status = ?"
        );
        $sel2->execute(array_merge($candidates, [ATT_TEXT_EXTRACTING]));
        $rows = $sel2->fetchAll(PDO::FETCH_ASSOC);

        // One ticket may own several pending attachments; reindexing it once
        // clears all of them.
        $tickets = array_values(array_unique(array_map(fn($r) => (int)$r['ticket_id'], $rows)));

        foreach ($tickets as $ticketId) {
            // A wall-clock budget, checked BEFORE starting each ticket. One file
            // cannot be interrupted once curl is waiting on it, which is why the
            // opportunistic path also lowers the extractor timeout — the two
            // together are what bound a page load.
            if ($deadline > 0 && microtime(true) >= $deadline) {
                $out['skipped_reason'] = 'time budget reached';
                break;
            }
            searchIndexTicket($conn, $ticketId);
            $out['done']++;

            // ⚠️ Stop the moment the extractor goes away again, rather than
            // grinding through the whole batch collecting timeouts. Each failed
            // attempt costs a connect timeout, so a long batch against a dead
            // service is minutes of nothing.
            if (extractQueueDepthUnchanged($conn, $rows)) break;
        }

        $out['still_pending'] = extractQueueDepth($conn);
    } catch (Throwable $e) {
        error_log('[extractQueueDrain] ' . $e->getMessage());
        $out['skipped_reason'] = $e->getMessage();
    }

    return $out;
}

/**
 * Did the attachments we just tried stay pending? If so the extractor is
 * unreachable again and there is no point continuing this pass.
 */
function extractQueueDepthUnchanged(PDO $conn, array $rows): bool {
    if (!$rows) return false;
    $ids = array_map(fn($r) => (int)$r['attachment_id'], $rows);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM attachment_text
                               WHERE attachment_id IN ($in) AND status = ?");
        $st->execute(array_merge($ids, [ATT_TEXT_PENDING]));
        return (int)$st->fetchColumn() === count($ids);
    } catch (Exception $e) {
        return true;   // can't tell — stop rather than loop
    }
}

/**
 * A few items, on the back of a request somebody made anyway.
 *
 * Called from places an analyst already waits a moment: it must stay small and
 * must never be allowed to make that request feel slow. Silent by design.
 */
function extractQueueDrainOpportunistic(PDO $conn): void {
    try {
        if (!extractQueueSettingOn($conn, EXTRACT_SETTING_OPPORTUNISTIC)) return;
        if (!tikaConfigured($conn)) return;
        if (extractQueueDepth($conn) === 0) return;

        // Bound it twice over: a wall-clock deadline between items, and a much
        // shorter extractor timeout so no single file can hold the page either.
        // The configured timeout is sized for a scanned document on a cron.
        tikaTimeoutOverride(EXTRACT_OPPORTUNISTIC_SECONDS);
        try {
            extractQueueDrain(
                $conn,
                EXTRACT_OPPORTUNISTIC_BATCH,
                microtime(true) + EXTRACT_OPPORTUNISTIC_SECONDS
            );
        } finally {
            // Always put it back: this process may go on to serve other work,
            // and a stray short timeout would look like an unreliable extractor.
            tikaTimeoutOverride(null);
        }
    } catch (Throwable $e) {
        error_log('[extractQueueDrainOpportunistic] ' . $e->getMessage());
    }
}
