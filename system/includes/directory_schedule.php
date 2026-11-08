<?php
/**
 * When a directory source is imported on its own - one line under the Run buttons of
 * the LDAP, CardDAV and Entra pages.
 *
 * Read from the built-in scheduler (#233), never from a setting of its own: the
 * directory import is one job there (directory_sync, `--all`), so every source is
 * imported on the same schedule, and Background jobs is the one place to change it.
 * These pages used to say "run php scripts/directory_sync.php --all from cron",
 * which stopped being the whole story when the scheduler arrived - with it running,
 * the import happens with nothing else to set up, and without it, nothing happens
 * at all. The line says which.
 */
require_once __DIR__ . '/../../includes/job_scheduler.php';

/**
 * The line, as HTML.
 *
 * @param array $provider  the auth_providers row: `enabled` and `sync_enabled` decide
 *   whether `--all` picks it up at all
 */
function dsyncScheduleNotice(PDO $conn, array $provider): string
{
    $e = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $link = '<a href="' . $e(BASE_URL . 'reporting/logs/?tab=jobs') . '">' . $e(t('system.sso.sched_change')) . '</a>';
    $line = function (string $text, bool $warn) use ($e, $link): string {
        return '<div class="hint sched"' . ($warn ? ' style="color:var(--warning-text,#92400e);"' : '') . '>'
             . $e($text) . ' ' . $link . '</div>';
    };

    // `--all` takes only sources that are switched on with importing switched on.
    if ((int)($provider['enabled'] ?? 0) !== 1 || (int)($provider['sync_enabled'] ?? 0) !== 1) {
        return '<div class="hint sched">' . $e(t('system.sso.sched_source_off')) . '</div>';
    }

    $s = jobScheduleSummary($conn, 'directory_sync');
    if ($s['mode'] === 'off') return $line(t('system.sso.sched_off'), true);
    if ($s['runner'] === 'none') return $line(t('system.sso.sched_none'), true);

    if ($s['mode'] === 'daily') {
        // The install's time, as Background jobs shows it: the next import below is in the reader's.
        $when = t('system.sso.sched_daily', ['time' => (string)$s['at'], 'tz' => date_default_timezone_get()]);
    } else {
        $n = max(1, (int)$s['interval']);
        $when = $n === 60 ? t('system.sso.sched_hourly')
              : ($n % 60 === 0 ? t('system.sso.sched_hours', ['n' => $n / 60]) : t('system.sso.sched_minutes', ['n' => $n]));
    }
    $text = t('system.sso.sched_line', [
        'when' => $when,
        'next' => $s['next_due'] ? fmt_datetime($s['next_due']) : t('system.sso.sched_next_soon'),
    ]);
    if ($s['runner'] === 'fallback') $text .= ' ' . t('system.sso.sched_fallback');
    return $line($text, false);
}
