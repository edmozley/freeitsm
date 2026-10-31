<?php
/**
 * Automatic directory imports: when a source is due, who starts it, and that only one does.
 *
 * A source (LDAP, CardDAV, Microsoft Entra ID) with `sync_interval_minutes` > 0
 * is imported at most that often. Two things can start it:
 *
 *   1. The scheduled task - `php cron/directory_sync.php --all` from cron or
 *      Task Scheduler. Reliable, and what an install should have.
 *   2. With no scheduled task, FreeITSM itself: alongside its minute poll, the
 *      notification bell in every open analyst tab POSTs to
 *      api/system/background_tick.php, which calls dsyncKickDue(). That starts
 *      a due import as a SEPARATE background process
 *      (cron/directory_sync.php --auto), never inside the request. A POST, not
 *      the bell's GET poll, because claiming the import is a write.
 *
 * ⚠️ (2) IS A FALLBACK, NOT A SCHEDULER. PHP under Apache has no process that is
 * awake waiting for a time; something has to wake the app. If nobody has
 * FreeITSM open all weekend, nothing imports all weekend. Runs started this way
 * are recorded with the 'page' trigger, which the Background jobs health check
 * never counts as proof the schedule is alive - so an install relying on it is
 * still told it has no scheduled task, which is the truth.
 *
 * 🔑 ONE RUN PER SOURCE, HOWEVER MANY TRIGGERS. Ten open tabs poll in the same
 * minute, and the scheduled task may fire then too. dsyncClaimDue() is a single
 * UPDATE ... WHERE <due>: whichever caller changes the row is the one that runs,
 * everyone else changes nothing and walks away. dsyncLock() (a MySQL named lock,
 * held by the process doing the import) then stops a slow run overlapping the
 * next one, or the Import now button.
 *
 * 0 keeps the behaviour every source had before the column existed: import on
 * every run of the scheduled task, never on its own.
 *
 * Kept apart from directory_sync.php on purpose: this file is loaded by the bell
 * poll on every open tab every minute, and must cost one small query.
 */

/**
 * Rows that are people sources: LDAP, CardDAV, and OpenID Connect providers whose
 * issuer is Microsoft Entra ID - the same test as entraIsEntraIssuer().
 */
const DSYNC_AUTO_SOURCES_SQL = "(protocol IN ('ldap','carddav') OR (protocol = 'oidc' AND (issuer_url LIKE 'https://login.microsoftonline.%'"
                             . " OR issuer_url LIKE 'https://login.chinacloudapi.cn/%' OR issuer_url LIKE 'https://sts.windows.net/%')))";

/**
 * Due: switched on, with an interval, and neither an automatic start nor a
 * finished import within that interval. Both clocks count, so pressing Import
 * now also postpones the next automatic run.
 */
const DSYNC_AUTO_DUE_SQL = "enabled = 1 AND sync_enabled = 1 AND sync_interval_minutes > 0"
    . " AND (sync_last_attempt_datetime IS NULL OR sync_last_attempt_datetime <= UTC_TIMESTAMP() - INTERVAL sync_interval_minutes MINUTE)"
    . " AND (sync_last_run_datetime IS NULL OR sync_last_run_datetime <= UTC_TIMESTAMP() - INTERVAL sync_interval_minutes MINUTE)";

/** The intervals offered on a source's import settings, in minutes. 0 = only when the scheduled task runs. */
const DSYNC_AUTO_INTERVALS = [0, 60, 240, 720, 1440];

/**
 * Is this "Database Verification has not added the columns yet" - the one failure
 * that is an answer rather than a fault? Anything else is logged, never swallowed.
 *
 * @param Throwable $e  what the query threw
 */
function dsyncAutoNotReady(Throwable $e): bool
{
    $state = $e instanceof PDOException ? (string)($e->errorInfo[0] ?? $e->getCode()) : '';
    return in_array($state, ['42S22', '42S02'], true);   // unknown column / table
}

/**
 * Claim this source's due automatic import. True for exactly one caller per
 * interval: the one whose UPDATE changed the row.
 *
 * @param int $providerId  auth_providers.id
 */
function dsyncClaimDue(PDO $conn, int $providerId): bool
{
    $st = $conn->prepare("UPDATE auth_providers SET sync_last_attempt_datetime = UTC_TIMESTAMP()
                           WHERE id = ? AND " . DSYNC_AUTO_DUE_SQL);
    $st->execute([$providerId]);
    return $st->rowCount() === 1;
}

/**
 * Record that an import of this source has started, due or not (an interval of
 * 0, or Import now), so the automatic one does not follow straight after.
 *
 * @param int $providerId  auth_providers.id
 */
function dsyncMarkAttempt(PDO $conn, int $providerId): void
{
    try {
        $conn->prepare("UPDATE auth_providers SET sync_last_attempt_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$providerId]);
    } catch (Throwable $e) {
        // Before Database Verification the column is not there yet, and the import
        // itself must still run. Anything else is a real fault: say so, carry on.
        if (!dsyncAutoNotReady($e)) error_log('[dsync] could not record the start of an import of provider ' . $providerId . ': ' . $e->getMessage());
    }
}

/**
 * Hold the one-import-at-a-time lock for a source, for as long as this
 * connection lives. False when another process is importing it right now.
 *
 * @param int $providerId  auth_providers.id
 */
function dsyncLock(PDO $conn, int $providerId): bool
{
    $st = $conn->prepare("SELECT GET_LOCK(?, 0)");
    $st->execute(['freeitsm.directory_sync.' . $providerId]);
    return (int)$st->fetchColumn() === 1;
}

/**
 * Start every due automatic import as a background process. Called from the
 * background tick (api/system/background_tick.php), so it never throws: somebody's page is waiting on that
 * request and an import problem must never become theirs. Faults go to the error
 * log instead (only "not set up yet" is quiet).
 *
 * Costs one small SELECT when nothing is due, which is almost always.
 */
function dsyncKickDue(PDO $conn): void
{
    if (PHP_SAPI === 'cli' || !function_exists('popen')) return;
    try {
        $due = $conn->query("SELECT id FROM auth_providers WHERE " . DSYNC_AUTO_DUE_SQL . " AND " . DSYNC_AUTO_SOURCES_SQL . " LIMIT 5")
                    ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($due as $pid) {
            // Claimed BEFORE spawning: a spawn that fails (exec blocked on this
            // host) then waits a whole interval before trying again, instead of
            // being retried by every tab every minute.
            if (dsyncClaimDue($conn, (int)$pid)) dsyncSpawn($conn, (int)$pid);
        }
    } catch (Throwable $e) {
        // Silent only for "Database Verification not run yet". Anything else goes
        // to the error log - never to the page that happened to be polling.
        if (!dsyncAutoNotReady($e)) error_log('[dsync] automatic import check failed: ' . $e->getMessage());
    }
}

/**
 * Start `cron/directory_sync.php --provider=N --auto` detached from this request.
 *
 * Uses the same PHP the Intune worker does (Assets → Settings → Intune can name
 * one), because finding a CLI PHP from inside Apache is the same problem there.
 *
 * @param int $providerId  auth_providers.id
 */
function dsyncSpawn(PDO $conn, int $providerId): bool
{
    $script = realpath(__DIR__ . '/../cron/directory_sync.php');
    if (!$script) return false;
    require_once __DIR__ . '/intune.php';   // intuneFindPhpExe()
    $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'intune_php_exe'");
    $st->execute();
    $phpExe = intuneFindPhpExe(($st->fetchColumn() ?: null));

    if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
        $cmd = sprintf('start /B "" "%s" "%s" --provider=%d --auto --quiet', $phpExe, $script, $providerId);
    } else {
        $cmd = sprintf('nohup %s %s --provider=%d --auto --quiet > /dev/null 2>&1 &',
            escapeshellarg($phpExe), escapeshellarg($script), $providerId);
    }
    $h = @popen($cmd, 'r');
    if ($h === false) {
        error_log('[dsync] could not start an automatic import of provider ' . $providerId . ' (is popen allowed?)');
        return false;
    }
    pclose($h);
    return true;
}
