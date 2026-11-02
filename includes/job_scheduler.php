<?php
/**
 * The built-in scheduler (#233, designed by vsc55) - one cron line, cron/run.php, starts every job.
 *
 * cron/run.php runs once a minute. Each time it writes a heartbeat, makes sure every job in
 * jobRegistry() has a row in job_schedules, and starts each job that is due as its own
 * process. The job then does exactly what it does under its own cron line: its run lock,
 * its JobRun record, its exit code. So an install that still has the old per-job cron
 * lines as well is safe - a job started twice runs once (JobRun::lock()).
 *
 * The rules, and why:
 *
 *  - CLAIMED BY ONE ATOMIC UPDATE. A job is started only by the caller whose UPDATE moved
 *    its next_due_at on. A SELECT then an UPDATE would let two dispatchers started in the
 *    same minute both start it.
 *  - NOT NEEDED IS NOT STARTED. A job whose `needed` test says no (its module is off, nothing
 *    is set up) is left alone and not claimed, so it starts the minute it is needed. A test
 *    that could not be read starts it anyway: the job knows how to say no for itself.
 *  - DAILY TIMES ARE IN THE INSTALL'S TIMEZONE, the one config.php sets - a job has no
 *    signed-in analyst, so nobody's own timezone applies. next_due_at is stored in UTC,
 *    so a clock change moves the UTC time and keeps the local one.
 *  - A NEW JOB RUNS. A job added in a release gets its row the first time run.php sees it,
 *    with the registry's interval - nothing to add on the server.
 *
 * TRAP: the command run is built from the registry's 'command', and only ever a
 *   cron/ or scripts/ file with --flag arguments. Nothing from the database reaches a shell.
 *
 * @wiki Background-Jobs-Developer-Guide
 */

require_once __DIR__ . '/job_registry.php';

/** The heartbeat cron/run.php writes on every run - how the page knows a real schedule exists. */
const JOB_SCHEDULER_BEAT = 'scheduler_last_beat';

/** The fallback (#233 slice 4): its switch, off unless '1'; its own heartbeat; and when it may next start. */
const JOB_SCHEDULER_FALLBACK      = 'scheduler_fallback';
const JOB_SCHEDULER_FALLBACK_BEAT = 'scheduler_fallback_beat';
const JOB_SCHEDULER_FALLBACK_NEXT = 'scheduler_fallback_next';
/** Set when the fallback could not start PHP - shown on the tab. */
const JOB_SCHEDULER_FALLBACK_ERROR = 'scheduler_fallback_error';

/** Seconds without a heartbeat before the real scheduler counts as not running. */
const JOB_SCHEDULER_STALE = 180;

/** The modes a schedule can have. */
const JOB_SCHEDULE_MODES = ['interval', 'daily', 'off'];

/**
 * When a job is next due after $now, or null when it is off.
 *
 * @param string $mode  interval, daily or off
 * @param ?int $interval  minutes between runs, for interval
 * @param ?string $at  HH:MM in $tz, for daily
 * @param DateTimeImmutable $now  the time it is being claimed, any zone
 * @param DateTimeZone $tz  the install's timezone
 * @return ?string  UTC, 'Y-m-d H:i:s'
 */
function jobScheduleNextDue(string $mode, ?int $interval, ?string $at, DateTimeImmutable $now, DateTimeZone $tz): ?string
{
    $utc = new DateTimeZone('UTC');
    if ($mode === 'interval') {
        // From the start of this minute, so a dispatcher that runs a few seconds late
        // one minute and early the next still finds the job due on time.
        $u = $now->setTimezone($utc);
        return $u->setTime((int)$u->format('H'), (int)$u->format('i'))
                 ->modify('+' . max(1, (int)$interval) . ' minutes')->format('Y-m-d H:i:s');
    }
    if ($mode === 'daily') {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string)$at, $m)) return null;
        $local = $now->setTimezone($tz);
        $next = $local->setTime((int)$m[1], (int)$m[2]);
        if ($next <= $local) $next = $local->modify('+1 day')->setTime((int)$m[1], (int)$m[2]);
        return $next->setTimezone($utc)->format('Y-m-d H:i:s');
    }
    return null;
}

/**
 * The one scheduled task to set up, for the server FreeITSM is running on: a crontab line,
 * or on Windows a Task Scheduler command. "php" stands for the PHP the web server uses.
 */
function jobSchedulerLine(): string
{
    $run = realpath(__DIR__ . '/../cron/run.php');
    if (PHP_OS_FAMILY === 'Windows') {
        return 'schtasks /create /tn "FreeITSM scheduler" /tr "php \"' . $run . '\" --quiet" /sc minute /mo 1 /ru SYSTEM';
    }
    return '* * * * * php ' . $run . ' --quiet';
}

/**
 * Gives every registry job a schedule row, once. The first schedule follows what the
 * administrator already told health: their interval, and "not used here" as off.
 */
function jobSchedulerSeed(PDO $conn): void
{
    $have = array_flip($conn->query("SELECT job_key FROM job_schedules")->fetchAll(PDO::FETCH_COLUMN));
    $cfg = jobHealthConfig($conn);
    $ins = $conn->prepare("INSERT IGNORE INTO job_schedules (job_key, mode, interval_minutes, next_due_at) VALUES (?, ?, ?, UTC_TIMESTAMP())");
    foreach (jobRegistry() as $key => $def) {
        if (isset($have[$key])) continue;
        $mine = $cfg[$key] ?? [];
        $ins->execute([$key, !empty($mine['not_used']) ? 'off' : 'interval',
                       isset($mine['interval']) && (int)$mine['interval'] > 0 ? (int)$mine['interval'] : (int)$def['interval']]);
    }
}

/**
 * Every job's schedule, keyed by job.
 *
 * @return array<string,array{mode:string,interval_minutes:?int,at_time:?string,next_due_at:?string,last_claimed_at:?string}>
 */
function jobSchedules(PDO $conn): array
{
    $out = [];
    try {
        foreach ($conn->query("SELECT job_key, mode, interval_minutes, at_time, next_due_at, last_claimed_at FROM job_schedules")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['job_key']] = $r;
        }
    } catch (Throwable $e) {
        if (!jobLookupMissingTable($e)) throw $e;   // not set up yet: no schedules
    }
    return $out;
}

/**
 * Saves a job's schedule from the Background jobs tab and works out its next start: a new
 * interval counts from the job's last start (or now), a daily time is its next occurrence.
 *
 * @param string $mode  interval, daily or off
 * @param ?int $interval  minutes, for interval
 * @param ?string $at  HH:MM in the install's timezone, for daily
 * @param int $analystId  who changed it
 */
function jobScheduleSave(PDO $conn, string $job, string $mode, ?int $interval, ?string $at, int $analystId): void
{
    jobSchedulerSeed($conn);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $next = null;
    if ($mode === 'interval') {
        $s = $conn->prepare("SELECT last_claimed_at FROM job_schedules WHERE job_key = ?");
        $s->execute([$job]);
        $last = $s->fetchColumn();
        $next = $last ? (new DateTimeImmutable($last, new DateTimeZone('UTC')))->modify('+' . max(1, (int)$interval) . ' minutes')->format('Y-m-d H:i:s')
                      : $now->format('Y-m-d H:i:s');
    } elseif ($mode === 'daily') {
        $next = jobScheduleNextDue('daily', null, $at, $now, new DateTimeZone(date_default_timezone_get()));
    }
    $conn->prepare("UPDATE job_schedules SET mode = ?, interval_minutes = ?, at_time = ?, next_due_at = ?,
                           updated_by = ?, updated_datetime = UTC_TIMESTAMP()
                     WHERE job_key = ?")
         ->execute([$mode, $mode === 'interval' ? max(1, (int)$interval) : null, $mode === 'daily' ? $at : null, $next, $analystId, $job]);
}

/**
 * Claims a job that is due: moves its next_due_at on in ONE statement, so only one caller
 * wins. True when this caller won and should start it.
 *
 * @param string $nextDue  UTC, from jobScheduleNextDue()
 */
function jobSchedulerClaim(PDO $conn, string $job, string $nextDue): bool
{
    $s = $conn->prepare("UPDATE job_schedules SET last_claimed_at = UTC_TIMESTAMP(), next_due_at = ?
                          WHERE job_key = ? AND mode <> 'off' AND (next_due_at IS NULL OR next_due_at <= UTC_TIMESTAMP())");
    $s->execute([$nextDue, $job]);
    return $s->rowCount() === 1;
}

/**
 * The script and arguments a registry command runs - or null for anything that is not
 * plainly "php cron/x.php --flag".
 *
 * @param string $command  a registry 'command', e.g. 'php scripts/directory_sync.php --all --quiet'
 * @return ?array{0:string,1:array<int,string>}  the script's full path and its arguments
 */
function jobSchedulerCommand(string $command): ?array
{
    $parts = preg_split('/\s+/', trim($command));
    if (count($parts) < 2 || $parts[0] !== 'php') return null;
    if (!preg_match('#^(cron|scripts)/[a-z0-9_]+\.php$#', $parts[1])) return null;
    $args = array_slice($parts, 2);
    foreach ($args as $a) {
        if (!preg_match('/^--[a-z][a-z0-9-]*$/', $a)) return null;
    }
    $path = realpath(__DIR__ . '/../' . $parts[1]);
    return $path ? [$path, $args] : null;
}

/**
 * Starts a job as its own process and returns at once, so one slow job never holds up the rest.
 *
 * @param string $phpExe  the PHP command-line binary
 * @param string $script  the script's full path
 * @param array<int,string> $args  its --flag arguments
 */
function jobSchedulerSpawn(string $phpExe, string $script, array $args): bool
{
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = sprintf('start /B "" "%s" "%s"%s > NUL 2>&1', $phpExe, $script, $args ? ' ' . implode(' ', $args) : '');
    } else {
        $cmd = sprintf('nohup %s %s%s > /dev/null 2>&1 &', escapeshellarg($phpExe), escapeshellarg($script),
            $args ? ' ' . implode(' ', array_map('escapeshellarg', $args)) : '');
    }
    $h = @popen($cmd, 'r');
    if ($h === false) return false;
    pclose($h);
    return true;
}

/** Whether the real scheduler (a scheduled task or the Docker loop) has run in the last few minutes. */
function jobSchedulerRunning(PDO $conn): bool
{
    $beat = jobSetting($conn, JOB_SCHEDULER_BEAT);
    return $beat !== '' && strtotime($beat . ' UTC') > time() - JOB_SCHEDULER_STALE;
}

/**
 * Whether the fallback should start the scheduler now: switched on, no real scheduler
 * running, and its minute has come round. Read-only and cheap - the bell's poll asks it
 * from every open analyst tab.
 */
function jobSchedulerFallbackDue(PDO $conn): bool
{
    try {
        $s = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (?, ?, ?)");
        $s->execute([JOB_SCHEDULER_FALLBACK, JOB_SCHEDULER_BEAT, JOB_SCHEDULER_FALLBACK_NEXT]);
        $v = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable) {
        return false;
    }
    if (($v[JOB_SCHEDULER_FALLBACK] ?? '') !== '1') return false;
    $beat = (string)($v[JOB_SCHEDULER_BEAT] ?? '');
    if ($beat !== '' && strtotime($beat . ' UTC') > time() - JOB_SCHEDULER_STALE) return false;
    $next = (string)($v[JOB_SCHEDULER_FALLBACK_NEXT] ?? '');
    return $next === '' || strtotime($next . ' UTC') <= time();
}

/**
 * Starts the scheduler from a page, when the fallback is due: claims the minute with one
 * atomic UPDATE, so ten open tabs start it once, then runs `cron/run.php --fallback` as
 * its own process. If PHP may not start a process, that is logged and recorded, and the
 * next try waits 15 minutes rather than coming round every minute.
 *
 * @return string  started, not_due or failed
 */
function jobSchedulerFallbackStart(PDO $conn): string
{
    if (!jobSchedulerFallbackDue($conn)) return 'not_due';
    $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, '2000-01-01 00:00:00')")
         ->execute([JOB_SCHEDULER_FALLBACK_NEXT]);
    $claim = $conn->prepare("UPDATE system_settings SET setting_value = DATE_FORMAT(UTC_TIMESTAMP() + INTERVAL 1 MINUTE, '%Y-%m-%d %H:%i:00')
                              WHERE setting_key = ? AND setting_value <= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s')");
    $claim->execute([JOB_SCHEDULER_FALLBACK_NEXT]);
    if ($claim->rowCount() !== 1) return 'not_due';

    require_once __DIR__ . '/intune.php';   // intuneFindPhpExe(): the same PHP the Intune worker uses
    $run = realpath(__DIR__ . '/../cron/run.php');
    $ok = $run && jobSchedulerSpawn(intuneFindPhpExe(jobSetting($conn, 'intune_php_exe') ?: null), $run, ['--fallback', '--quiet']);
    $save = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if ($ok) {
        $save->execute([JOB_SCHEDULER_FALLBACK_ERROR, '']);
        return 'started';
    }
    error_log('[scheduler fallback] could not start PHP (popen refused or failed); trying again in 15 minutes');
    $save->execute([JOB_SCHEDULER_FALLBACK_ERROR, gmdate('Y-m-d H:i:s')]);
    $save->execute([JOB_SCHEDULER_FALLBACK_NEXT, gmdate('Y-m-d H:i:00', time() + 900)]);
    return 'failed';
}

/**
 * One tick of the scheduler: what cron/run.php does each minute.
 *
 * @param string $phpExe  the PHP binary to start jobs with
 * @param bool $dryRun  work out what is due without claiming or starting anything
 * @param bool $fallback  started from a page by the fallback: writes the fallback's own
 *     heartbeat, never the real one, so the tab keeps saying no scheduled task runs
 * @return array<string,string>  per job: started, not_due, not_needed, off, claimed_elsewhere, failed_to_start, bad_command
 */
function jobSchedulerTick(PDO $conn, string $phpExe, bool $dryRun = false, bool $fallback = false): array
{
    if (!$dryRun) {
        $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, UTC_TIMESTAMP())
                        ON DUPLICATE KEY UPDATE setting_value = UTC_TIMESTAMP()")->execute([$fallback ? JOB_SCHEDULER_FALLBACK_BEAT : JOB_SCHEDULER_BEAT]);
        jobSchedulerSeed($conn);
    }
    $schedules = jobSchedules($conn);
    $tz  = new DateTimeZone(date_default_timezone_get());
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $out = [];
    foreach (jobRegistry() as $key => $def) {
        $row = $schedules[$key] ?? ['mode' => 'interval', 'interval_minutes' => $def['interval'], 'at_time' => null, 'next_due_at' => null];
        if ($row['mode'] === 'off') { $out[$key] = 'off'; continue; }
        if ($row['next_due_at'] !== null && $row['next_due_at'] > $now->format('Y-m-d H:i:s')) { $out[$key] = 'not_due'; continue; }
        try {
            $needed = (bool)$def['needed']($conn);
        } catch (Throwable) {
            $needed = true;   // could not tell: let the job decide for itself
        }
        if (!$needed) { $out[$key] = 'not_needed'; continue; }
        $cmd = jobSchedulerCommand($def['command']);
        if ($cmd === null) { $out[$key] = 'bad_command'; continue; }
        if ($dryRun) { $out[$key] = 'due'; continue; }
        $next = jobScheduleNextDue($row['mode'], $row['interval_minutes'] !== null ? (int)$row['interval_minutes'] : (int)$def['interval'],
                                   $row['at_time'], $now, $tz);
        if ($next === null || !jobSchedulerClaim($conn, $key, $next)) { $out[$key] = 'claimed_elsewhere'; continue; }
        $out[$key] = jobSchedulerSpawn($phpExe, $cmd[0], $cmd[1]) ? 'started' : 'failed_to_start';
    }
    return $out;
}
