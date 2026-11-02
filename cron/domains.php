<?php
/**
 * Domains — cron entry point (#154).
 *
 * Refreshes registry details, runs the DNS / email-security / certificate
 * checks, runs the optional Certificate Transparency and look-alike watches,
 * then sends whatever alerts are due. See includes/domains/scheduler.php.
 *
 * HOURLY is the right schedule. Each run works for up to four minutes on the
 * domains that most need it (never looked up, oldest check first), so a large
 * register is covered across a few runs; alerts are fire-once, so running more
 * often never sends anything twice.
 *
 *   Linux    0 * * * *  /usr/bin/php /path/to/cron/domains.php
 *   Windows  Task Scheduler, hourly, running php.exe with the full script path
 *   HTTP     https://your-freeitsm/cron/domains.php?token=…
 *
 * Without a cron the module still works: opening Domains runs a short batch at
 * most once an hour (Domains → Settings → Monitoring). That is a fallback, not a
 * scheduler — if nobody opens the module, nothing is refreshed.
 *
 * SECURITY (HTTP only): ?token= must match the encrypted `domain_cron_token`
 * setting (seeded by Database Verification; `php scripts/cron_token.php` prints
 * it). No token configured means the HTTP door is SHUT. CLI needs no token.
 */

set_time_limit(360);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';
require_once __DIR__ . '/../includes/domains/scheduler.php';
require_once __DIR__ . '/../includes/job_runs.php';   // run history (#182)

$isCli = (PHP_SAPI === 'cli');

try {
    $conn = connectToDatabase();
} catch (Throwable $e) {
    http_response_code(500);
    echo "Database unavailable\n";
    exit(1);
}

if (!$isCli) {
    $expected = '';
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([DOMAIN_SETTING_CRON_TOKEN]);
        $raw = (string)$st->fetchColumn();
        $expected = $raw !== '' ? (string)decryptValue($raw) : '';
    } catch (Throwable $e) {
        $expected = '';
    }
    $given = (string)($_GET['token'] ?? '');
    // ⚠️ An unset secret must never compare equal to an empty query string.
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        JobRun::refused($conn, 'domains', $expected === '' ? 'token not set' : 'wrong token');
        echo "Forbidden\n";
        exit(1);
    }
}
$trigger = JobRun::scheduledTrigger();
// GH #217: nothing to do while the module is switched off for the install.
if (cronModuleSwitchedOff($conn, 'domains', 'domains', $trigger)) exit(0);

// Never two at once: a slow run overlapping the next hour's would double the
// registry and crt.sh traffic for no gain.
if (!JobRun::lock($conn, 'domains', $trigger)) exit(0);

$runId = JobRun::start($conn, 'domains', $trigger);
try {
    $r = domainScheduledRun($conn, 240, true);
    domainJobFinish($conn, $runId, $r);
} catch (Throwable $e) {
    JobRun::fail($conn, $runId, $e);
    throw $e;
} finally {
    JobRun::unlock($conn, 'domains');
}

$a = $r['alerts'] ?? [];
printf(
    "Domains: %d looked up (%d failed), %d checked, %d CT scans, %d look-alike scans; %d alerts due, %d events, %d e-mails (%d failed), %d renewal actions; calendar %s. %.1fs%s\n",
    $r['lookups'], $r['lookup_errors'], $r['checks'], $r['ct'], $r['lookalikes'],
    $a['due'] ?? 0, $a['events'] ?? 0, $a['emails'] ?? 0, $a['failed'] ?? 0, $a['actions'] ?? 0,
    !empty($r['calendar']['success']) ? ($r['calendar']['synced'] ?? 0) . ' entries' : 'skipped',
    $r['seconds'], $r['out_of_time'] ? ' (more to do: the next run carries on)' : ''
);
exit(($a['failed'] ?? 0) > 0 ? 1 : 0);
