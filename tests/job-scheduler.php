<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The built-in scheduler (#233) - when a job is due, who gets to start it, and run locks.
 *
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/job-scheduler.php
 *
 * Proves, each with a positive control:
 *   - interval and daily times, across midnight and a clock change;
 *   - two dispatchers racing for one job: exactly one claims it;
 *   - a job started twice runs once (JobRun::lock), and two databases never share a lock;
 *   - a runner started by the fallback records itself as a page run;
 *   - only "php cron/x.php --flag" commands are ever run;
 *   - a job with no schedule row gets one, with the registry's interval;
 *   - a started job really starts, and starting it does not wait for it.
 *
 * It writes job_schedules rows, so it runs only when FREEITSM_TEST_DATABASE names the
 * database config.php points at. It never starts a real job.
 *
 * @wiki Background-Jobs-Developer-Guide
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/job_runs.php';
require_once __DIR__ . '/../includes/job_scheduler.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the throwaway database config.php points at (this one is {$dbName}).\n";
    exit(0);
}
$other = connectToDatabase();   // a second dispatcher

echo "When a job is next due\n";
$utc = new DateTimeZone('UTC');
$london = new DateTimeZone('Europe/London');
$at = fn(string $s, ?DateTimeZone $z = null) => new DateTimeImmutable($s, $z ?? $utc);
check('every 5 minutes, claimed at 10:00:37, is next due at 10:05:00',
    jobScheduleNextDue('interval', 5, null, $at('2026-11-02 10:00:37'), $london) === '2026-11-02 10:05:00');
check('daily at 06:00 London, claimed at 05:00 in winter, is today at 06:00 UTC',
    jobScheduleNextDue('daily', null, '06:00', $at('2026-11-02 05:00:00'), $london) === '2026-11-02 06:00:00');
check('daily at 06:00, claimed just after, is tomorrow',
    jobScheduleNextDue('daily', null, '06:00', $at('2026-11-02 06:00:30'), $london) === '2026-11-03 06:00:00');
check('daily at 00:30, claimed at 23:50, crosses midnight',
    jobScheduleNextDue('daily', null, '00:30', $at('2026-11-02 23:50:00'), $london) === '2026-11-03 00:30:00');
check('across the clocks going forward, 06:00 London stays 06:00 local (05:00 UTC)',
    jobScheduleNextDue('daily', null, '06:00', $at('2026-03-28 07:00:00'), $london) === '2026-03-29 05:00:00');
check('a daily time that is not HH:MM is never due', jobScheduleNextDue('daily', null, '6am', $at('2026-11-02 05:00:00'), $london) === null);
check('off is never due', jobScheduleNextDue('off', 5, null, $at('2026-11-02 05:00:00'), $london) === null);

echo "Claiming a job\n";
$zz = 'zz_sched_' . bin2hex(random_bytes(2));
$conn->prepare("INSERT INTO job_schedules (job_key, mode, interval_minutes, next_due_at) VALUES (?, 'interval', 5, UTC_TIMESTAMP() - INTERVAL 1 MINUTE)")->execute([$zz]);
$next = gmdate('Y-m-d H:i:s', time() + 300);
$a = jobSchedulerClaim($conn, $zz, $next);
$b = jobSchedulerClaim($other, $zz, $next);
check('control: a due job is claimed', $a);
check('two dispatchers in the same minute: the second does not also claim it', !$b);
check('once claimed it is not due again until next_due_at', !jobSchedulerClaim($conn, $zz, $next));
$conn->prepare("UPDATE job_schedules SET mode = 'off', next_due_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE job_key = ?")->execute([$zz]);
check('an off job is never claimed, even when its time has passed', !jobSchedulerClaim($conn, $zz, $next));
$conn->prepare("DELETE FROM job_schedules WHERE job_key = ?")->execute([$zz]);

echo "Run locks\n";
check('control: a free lock is taken', JobRun::tryLock($conn, $zz));
check('a second process cannot take it while the first holds it', !JobRun::tryLock($other, $zz));
JobRun::unlock($conn, $zz);
check('given back, the second process takes it', JobRun::tryLock($other, $zz));
JobRun::unlock($other, $zz);
$name = JobRun::lockName($conn, $zz);
check('the lock name carries this database, so two installs on one server never block each other',
    str_contains($name, substr(md5($dbName), 0, 16)) && strlen($name) <= 64, $name);

echo "Who started it\n";
putenv('FREEITSM_JOB_TRIGGER');
check('control: a command-line run is a cron run', JobRun::scheduledTrigger() === 'cron');
putenv('FREEITSM_JOB_TRIGGER=page');
check('started by the fallback, it is a page run', JobRun::scheduledTrigger() === 'page');
putenv('FREEITSM_JOB_TRIGGER');

echo "What may be run\n";
$cmd = jobSchedulerCommand('php scripts/directory_sync.php --all --quiet');
check('control: a registry command resolves to its script and flags',
    $cmd !== null && str_ends_with(str_replace('\\', '/', $cmd[0]), 'scripts/directory_sync.php') && $cmd[1] === ['--all', '--quiet']);
foreach (['php cron/x.php; rm -rf /', 'php ../config.php', 'bash cron/domains.php', 'php cron/domains.php --a=$(id)', 'php cron/no_such_job.php'] as $bad) {
    check("refused: {$bad}", jobSchedulerCommand($bad) === null);
}
foreach (jobRegistry() as $key => $def) {
    if (jobSchedulerCommand($def['command']) === null) check("every registry job can be started ({$key})", false, $def['command']);
}
check('every registry job\'s command resolves', true);

echo "A job with no schedule yet\n";
$keep = $conn->query("SELECT * FROM job_schedules WHERE job_key = 'domains'")->fetch(PDO::FETCH_ASSOC);
$conn->exec("DELETE FROM job_schedules WHERE job_key = 'domains'");
jobSchedulerSeed($conn);
$row = $conn->query("SELECT mode, interval_minutes, next_due_at FROM job_schedules WHERE job_key = 'domains'")->fetch(PDO::FETCH_ASSOC);
check('gets a row, every 60 minutes and due now', $row && $row['mode'] === 'interval' && (int)$row['interval_minutes'] === 60 && $row['next_due_at'] !== null);
$conn->exec("DELETE FROM job_schedules WHERE job_key = 'domains'");
if ($keep) {
    $conn->prepare("INSERT INTO job_schedules (" . implode(',', array_keys($keep)) . ") VALUES (" . implode(',', array_fill(0, count($keep), '?')) . ")")
         ->execute(array_values($keep));
}

echo "A dry run\n";
$beat = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = '" . JOB_SCHEDULER_BEAT . "'")->fetchColumn();
$r = jobSchedulerTick($conn, PHP_BINARY, true);
$after = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = '" . JOB_SCHEDULER_BEAT . "'")->fetchColumn();
check('reports on every registry job', count($r) === count(jobRegistry()));
check('writes no heartbeat and starts nothing', $beat === $after && !in_array('started', $r, true));

echo "Starting a process\n";
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz_sched_' . bin2hex(random_bytes(3));
mkdir($dir);
$script = $dir . DIRECTORY_SEPARATOR . 'zz.php';
$flag = $dir . DIRECTORY_SEPARATOR . 'ran.txt';
file_put_contents($script, '<?php sleep(2); file_put_contents(' . var_export($flag, true) . ', getenv("FREEITSM_JOB_TRIGGER") . "|" . implode(" ", array_slice($argv, 1)));');
putenv('FREEITSM_JOB_TRIGGER=page');
$t = microtime(true);
$started = jobSchedulerSpawn(PHP_BINARY, $script, ['--quiet']);
$took = microtime(true) - $t;
putenv('FREEITSM_JOB_TRIGGER');
check('it starts', $started);
check('starting it does not wait for it to finish (the job sleeps 2s)', $took < 1.5, round($took, 2) . 's');
for ($i = 0; $i < 40 && !is_file($flag); $i++) usleep(250000);
$got = is_file($flag) ? file_get_contents($flag) : '';
check('it really ran, with its flags and the trigger it was given', $got === 'page|--quiet', $got);
@unlink($flag); @unlink($script); @rmdir($dir);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
