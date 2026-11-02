<?php
/**
 * Calendar sync — read changes back out of analysts' calendars (GH #75).
 *
 * The outbound half needs no cron: FreeITSM pushes as things change. This is
 * the inbound half — you move or delete one of these appointments in Outlook
 * and FreeITSM follows. Latency is roughly your cron interval; every 5 minutes
 * is sensible.
 *
 * 🔑 A DELTA QUERY, NOT A SUBSCRIPTION. Change notifications need a publicly
 * reachable HTTPS endpoint for Microsoft to call, plus renewal before it
 * expires. Plenty of FreeITSM installs are internal only and could never have
 * that. This is an ordinary outbound GET, so it works behind any firewall.
 *
 * With a CalDAV server (#133) the same job reads each analyst's calendar with a
 * sync-collection query, signed in as that analyst. CalDAV has no change
 * notifications, so for those installs this job is the only way changes come
 * back; the subscription step below skips them.
 *
 * ⚠️ NOT RUNNING THIS COSTS YOU NOTHING YOU ALREADY HAD. Without it the sync is
 * one-way, exactly as it shipped. Deleting an event in Outlook then simply means
 * the next change to that ticket puts a fresh one back.
 *
 * SECURITY (HTTP invocation only), mirroring the other crons: a shared-secret
 * token via ?token=<value> matching `webhook_cron_token` in system_settings.
 * CLI invocation skips it — there is no untrusted caller.
 */

set_time_limit(300);
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';
require_once __DIR__ . '/../includes/services/tickets.php';
require_once __DIR__ . '/../includes/calendar_sync/pull.php';
require_once __DIR__ . '/../includes/job_runs.php';   // run history (#182)

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
            JobRun::refused($conn, 'calendar_sync_pull', 'token not set');
            echo "Cron token not set. Run Database Verification to seed webhook_cron_token.\n";
            exit;
        }
        if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) {
            http_response_code(403);
            JobRun::refused($conn, 'calendar_sync_pull', 'wrong token');
            echo "Forbidden\n";
            exit;
        }
    }

    if (!calendarSyncSchemaReady($conn)) {
        JobRun::skipped($conn, 'calendar_sync_pull', $trigger, 'Calendar sync tables are not present.');
        echo "Calendar sync tables are not present. Run Database Verification.\n";
        exit;
    }

    // Never two at once (#182): a slow pull overlapping the next one would read the
    // same calendars twice and race to apply the same moves.
    if (!JobRun::lock($conn, 'calendar_sync_pull', $trigger)) exit;
    $runId = JobRun::start($conn, 'calendar_sync_pull', $trigger);

    // ── Subscriptions first ─────────────────────────────────────────────────
    // Create for anyone newly enrolled, renew before they lapse (Graph caps
    // calendar subscriptions at about three days), and take down any left behind
    // by someone who has since opted out.
    //
    // ⚠️ THIS DOES NOT REPLACE THE POLL BELOW. A notification says only that
    // something changed, and notifications go missing — dropped by the provider,
    // lost while the endpoint was down for a deploy, or silently stopped when a
    // subscription lapsed. A gap then looks exactly like "nothing changed", so
    // the poll stays as the backstop and notifications simply make the common
    // case near-instant.
    $notifyUrl = calendarNotifyUrl($conn);
    if ($notifyUrl !== '') {
        $subs = calendarSyncEnsureAllSubscriptions($conn);
        $tally = array_count_values($subs);
        echo "Notifications: " . $notifyUrl . "\n";
        echo "  subscriptions — " . (
            $tally ? implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($tally), $tally)) : 'none'
        ) . "\n";
    } else {
        echo "Notifications: not configured (polling only).\n";
    }

    $reports = calendarSyncPullAll($conn);
    if (!$reports) {
        JobRun::finish($conn, $runId, 'ok', ['calendars' => 0], 'Nobody has calendar sync switched on.');
        echo "Nobody has calendar sync switched on. Nothing to do.\n";
        exit;
    }

    $accept = calendarAcceptDeletes($conn) ? 'on' : 'off';
    echo "Polled " . count($reports) . " calendar(s). Deletions: $accept.\n";
    foreach ($reports as $r) {
        // A baseline is reported explicitly rather than looking like a quiet run,
        // because "I have just learned where I am" and "nothing changed" are very
        // different states and only one of them is worth worrying about twice.
        $line = "  analyst {$r['analyst_id']}: ";
        $line .= $r['baseline']
            ? 'baseline taken (nothing applied)'
            : "{$r['moved']} moved, {$r['unscheduled']} unscheduled, {$r['skipped']} ignored";
        if ($r['error']) $line .= ' — ' . $r['error'];
        echo $line . "\n";
        // Each analyst's calendar is a source, so "every pull is failing" is FAILING
        // on the health page rather than looking the same as "never run" (#182).
        JobRun::source($conn, $runId, 'calendar_sync_pull', 'analyst:' . (int)$r['analyst_id'],
            calendarPullAnalystName($conn, (int)$r['analyst_id']), empty($r['error']), $r['error'] ?: null);
    }
    $bad = count(array_filter($reports, fn($r) => !empty($r['error'])));
    JobRun::finish($conn, $runId, null, ['calendars' => count($reports), 'failed' => $bad],
        'Polled ' . count($reports) . " calendar(s), {$bad} failed.");
    if ($bad > 0) exit(1);
} catch (Exception $e) {
    if (isset($conn)) JobRun::fail($conn, $runId, $e);
    echo "Calendar pull failed: " . $e->getMessage() . "\n";
    exit(1);
}

/** An analyst's name for the health page's source list. */
function calendarPullAnalystName(PDO $conn, int $id): string
{
    $s = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
    $s->execute([$id]);
    return (string)($s->fetchColumn() ?: ('Analyst #' . $id));
}
