<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Each directory source says when it is imported on its own - read from the built-in scheduler.
 *
 * The LDAP, CardDAV and Entra pages show one line under their Run buttons,
 * dsyncScheduleNotice(), built from jobScheduleSummary('directory_sync'). It has to
 * tell the truth in every state the scheduler can be in, because the wrong one is
 * worse than none: "imported every day" on an install where nothing runs the
 * scheduler is how a directory silently stops being imported for months.
 *
 *   - a daily or interval schedule, with the next import, when the scheduler runs;
 *   - the same, saying imports start from open pages, when only the fallback runs;
 *   - a warning when the job is switched off, or when nothing will run it;
 *   - "not imported automatically" for a source --all does not pick up.
 *
 * Runs in a transaction that is ALWAYS rolled back.
 *
 * Run:  FREEITSM_TEST_DATABASE=<throwaway db> php tests/directory-sync-schedule.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/timezone.php';
require_once __DIR__ . '/../system/includes/directory_schedule.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nWhen a directory source imports on its own\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test changes the directory import's schedule and the scheduler's heartbeat.\n");
}
try { $conn->query("SELECT 1 FROM job_schedules LIMIT 0"); }
catch (PDOException $e) { exit("  SKIP  run Database Verification first: no job_schedules table\n"); }

$conn->beginTransaction();
try {
    $setting = function (string $key, ?string $value) use ($conn): void {
        $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$key]);
        if ($value !== null) $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $value]);
    };
    $schedule = function (string $mode, ?int $interval, ?string $at, ?string $next) use ($conn): void {
        $conn->prepare("DELETE FROM job_schedules WHERE job_key = 'directory_sync'")->execute();
        $conn->prepare("INSERT INTO job_schedules (job_key, mode, interval_minutes, at_time, next_due_at) VALUES ('directory_sync', ?, ?, ?, ?)")
             ->execute([$mode, $interval, $at, $next]);
    };
    $running  = fn() => $setting(JOB_SCHEDULER_BEAT, gmdate('Y-m-d H:i:s'));
    $stopped  = fn() => $setting(JOB_SCHEDULER_BEAT, gmdate('Y-m-d H:i:s', time() - 3600));
    $fallback = fn(bool $on) => $setting(JOB_SCHEDULER_FALLBACK, $on ? '1' : '0');
    $source = ['enabled' => 1, 'sync_enabled' => 1];
    $next = '2030-01-02 03:04:00';

    echo "\nThe scheduler is running:\n";
    $running(); $fallback(false);
    $schedule('daily', null, '02:00', $next);
    $s = jobScheduleSummary($conn, 'directory_sync');
    ok('the summary reads a daily schedule', $s['mode'] === 'daily' && $s['at'] === '02:00' && $s['next_due'] === $next && $s['runner'] === 'scheduler', json_encode($s));
    $line = dsyncScheduleNotice($conn, $source);
    ok("the line says every day at the time, in the install's timezone", str_contains($line, 'every day at 02:00 (' . date_default_timezone_get() . ')'), $line);
    ok('...and when the next import is, in the reader\'s time', str_contains($line, htmlspecialchars(fmt_datetime($next))), $line);
    ok('...and links to Background jobs', str_contains($line, 'reporting/logs/?tab=jobs'), $line);
    ok('...without a warning', !str_contains($line, 'color:'), $line);

    $schedule('interval', 240, null, $next);
    ok('every 240 minutes reads as every 4 hours', str_contains(dsyncScheduleNotice($conn, $source), 'every 4 hours'));
    $schedule('interval', 60, null, $next);
    ok('every 60 minutes reads as every hour', str_contains(dsyncScheduleNotice($conn, $source), 'every hour'));
    $schedule('interval', 90, null, $next);
    ok('every 90 minutes reads as minutes', str_contains(dsyncScheduleNotice($conn, $source), 'every 90 minutes'));
    $schedule('interval', 1440, null, null);
    ok('no next time yet says the scheduler will pick it up', str_contains(dsyncScheduleNotice($conn, $source), 'when the scheduler next runs'));

    echo "\nOnly the fallback runs it:\n";
    $stopped(); $fallback(true);
    $schedule('daily', null, '02:00', $next);
    ok('the summary says the fallback runs it', jobScheduleSummary($conn, 'directory_sync')['runner'] === 'fallback');
    $line = dsyncScheduleNotice($conn, $source);
    ok('the line still gives the schedule', str_contains($line, 'every day at 02:00'), $line);
    ok('...and says imports start from open pages', str_contains($line, 'open pages'), $line);

    echo "\nNothing runs it, or it is switched off:\n";
    $fallback(false);
    ok('CONTROL: with neither, the summary says nothing runs it', jobScheduleSummary($conn, 'directory_sync')['runner'] === 'none');
    $line = dsyncScheduleNotice($conn, $source);
    ok('the line warns that nothing imports this source automatically', str_contains($line, 'Nothing imports this source automatically') && str_contains($line, 'color:'), $line);
    ok('...and never claims a schedule', !str_contains($line, 'every day'), $line);
    $running();
    $schedule('off', 1440, null, null);
    $line = dsyncScheduleNotice($conn, $source);
    ok('switched off in Background jobs: a warning, and only Run imports it', str_contains($line, 'switched off') && str_contains($line, 'color:'), $line);

    echo "\nA source --all does not pick up:\n";
    $schedule('daily', null, '02:00', $next);
    ok('importing switched off: not imported automatically', str_contains(dsyncScheduleNotice($conn, ['enabled' => 1, 'sync_enabled' => 0]), 'not imported automatically'));
    ok('the source switched off: not imported automatically', str_contains(dsyncScheduleNotice($conn, ['enabled' => 0, 'sync_enabled' => 1]), 'not imported automatically'));

    echo "\nA schedule the scheduler has not written yet:\n";
    $conn->prepare("DELETE FROM job_schedules WHERE job_key = 'directory_sync'")->execute();
    $s = jobScheduleSummary($conn, 'directory_sync');
    ok('reads as the registry\'s interval', $s['mode'] === 'interval' && $s['interval'] === (int)jobRegistry()['directory_sync']['interval'], json_encode($s));
} finally {
    $conn->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
