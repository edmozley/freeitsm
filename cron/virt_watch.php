<?php
/**
 * Hyper-V collector watch (#296 review) - cron entry point, command line only.
 *
 * A collector that stops sends nothing, so nothing that happens when a report arrives can
 * notice it. Once an hour this asks VirtualEstateService::watch(): a switched-on connection
 * that is Overdue (later than its own interval + grace), or a cluster whose storage read is
 * failing, puts ONE notification in the bell of everyone who manages Hyper-V connections
 * (Assets + the Hyper-V capability + the connection's company). It is not repeated until the
 * connection has reported on time again, or the storage has been read again.
 *
 * Started by the built-in scheduler (cron/run.php) while any Hyper-V connection is switched
 * on; nothing to set up.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}
set_time_limit(300);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/job_runs.php';
require_once __DIR__ . '/../includes/services/virtual_estate.php';

try {
    $conn = connectToDatabase();
} catch (Throwable $e) {
    fwrite(STDERR, "Database unavailable\n");
    exit(1);
}

$trigger = JobRun::scheduledTrigger();
// Hyper-V reports into Assets; with Assets switched off its collectors are refused anyway.
if (cronModuleSwitchedOff($conn, 'assets', 'virt_watch', $trigger)) exit(0);
if (!JobRun::lock($conn, 'virt_watch', $trigger)) exit(0);
$runId = JobRun::start($conn, 'virt_watch', $trigger);
try {
    $r = VirtualEstateService::watch($conn);
    $summary = sprintf('%d connection(s) newly overdue, %d cluster(s) newly failing to read storage, %d recovered; %d notification(s).',
        $r['stale'], $r['storage'], $r['cleared'], $r['told']);
    JobRun::finish($conn, $runId, 'ok', $r, $summary);
    echo "Hyper-V collector watch: $summary\n";
} catch (Throwable $e) {
    JobRun::fail($conn, $runId, $e);
    fwrite(STDERR, 'Hyper-V collector watch failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    JobRun::unlock($conn, 'virt_watch');
}
exit(0);
