<?php
/**
 * Training reminders — cron entry point.
 *
 * Emails the people whose assigned training is nearly due, or overdue, according
 * to LMS → Settings → Reminders. Does nothing at all until reminders are
 * switched on there.
 *
 * ONCE A DAY is the right schedule. The work is driven by "how many days until
 * the deadline", which does not change within a day, so running it hourly sends
 * nothing extra — the fire-once table sees to that — and simply asks the
 * database the same question twenty-four times.
 *
 * SECURITY (HTTP invocation only): a shared-secret token in ?token=, compared
 * against `lms_reminder_cron_token` in system_settings with hash_equals(). Same
 * shape as the SLA cron, minus its per-IP lockout and run log: this job sends
 * email that is idempotent and rate-limited by its own dedup table, so the worst
 * a leaked token buys is an early run of something that was going to happen
 * anyway. CLI invocation needs no token.
 *
 *   Linux   0 8 * * *  /usr/bin/php /path/to/cron/lms_reminders.php
 *   Windows Task Scheduler, daily, running php.exe with the full script path
 *   HTTP    https://your-freeitsm/cron/lms_reminders.php?token=…
 */

set_time_limit(300);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lms_reminders.php';

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
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'lms_reminder_cron_token'");
        $st->execute();
        $expected = (string)$st->fetchColumn();
    } catch (Throwable $e) {
        $expected = '';
    }

    $given = (string)($_GET['token'] ?? '');

    // ⚠️ No token configured means the HTTP door is SHUT, not open. An unset
    // secret must never compare equal to an empty query string — that is how a
    // guard becomes an invitation.
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit(1);
    }
}

$settings = lmsReminderSettings($conn);
if ($settings[LMS_REM_ENABLED] !== '1') {
    echo "Training reminders are switched off (LMS > Settings > Reminders). Nothing to do.\n";
    exit(0);
}

$r = lmsRemindersRun($conn, false);

printf(
    "Training reminders: %d sent, %d already sent, %d with no email address, %d failed.%s\n",
    $r['sent'], $r['skipped'], $r['unreachable'], $r['failed'],
    $r['capped'] ? sprintf(' Stopped at the %d-per-run limit; run again to continue.', LMS_REM_MAX_PER_RUN) : ''
);

// A failure is worth a non-zero exit so a scheduler can notice. Reaching the
// per-run cap is NOT a failure — it is the design working.
exit($r['failed'] > 0 ? 1 : 0);
