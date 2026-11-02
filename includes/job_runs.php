<?php
/**
 * The background-job run recorder (#182) - one row per run of any background job.
 *
 * Every job calls start() when its work begins and finish() when it ends, and
 * names each source it works through (a tracker connection, a directory, a
 * server) with source(). includes/job_health.php reads these rows to decide
 * whether each job is healthy. Nothing here schedules anything: FreeITSM only
 * RECORDS what cron, a scheduled web call, a page load or Run now did.
 *
 * The rules the health page depends on, and why they live here:
 *
 *  - A run is 'running' until finish(). A process that dies leaves it running;
 *    a shutdown handler marks it failed where PHP gives the chance, and the next
 *    finished run of the same job marks any older one past its longest normal
 *    run as failed ("never finished") - so a crash is a recorded failure, not
 *    a row that looks busy for ever.
 *  - 'skipped' is for a run that did no work: its lock or minimum interval
 *    turned it away, the feature is switched off, or it was a dry run. Health
 *    never reads skipped rows when deciding whether a job is failing, so a skip
 *    can never clear a failure (Dr4inder on #182).
 *  - A call turned away at the door (wrong token) is NOT a run - anybody can
 *    make one, and a table strangers can fill is not a record. refused() keeps
 *    one line per job instead.
 *
 * 🔴 RECORDING MUST NEVER BREAK A JOB. Every method swallows its own errors -
 * an install that has not run Database Verification yet has no job_runs table,
 * and its SLA emails must still go out.
 *
 * TRAP: summaries and source messages are shown to anybody holding the
 * "View background job health" permission and returned by the REST health
 * address. Pass them through clean() (finish/source/fail do) - an exception
 * message from an HTTP client can carry a URL with a token or a password in it.
 */

require_once __DIR__ . '/job_registry.php';

final class JobRun
{
    /** Who started a run. cron = the command line; web = the job's token address; push = a collector's report arrived. */
    const TRIGGERS = ['cron', 'web', 'page', 'manual', 'push'];   // push: a report a collector sent (GH #199)

    /** Runs started in this request and not yet finished - for the shutdown handler. */
    private static $open = [];
    /** The newest run started in this request, so a task it creates can say which run made it (#181). */
    private static $current = null;
    private static $shutdownRegistered = false;

    /**
     * 'cron' from the command line, 'web' over HTTP - how a cron entry point names its own trigger.
     * A job the scheduler's fallback started (#233) is a 'page' run: cron/run.php --fallback passes
     * FREEITSM_JOB_TRIGGER=page to it, so health never counts it as the schedule being alive.
     */
    public static function scheduledTrigger(): string
    {
        if (PHP_SAPI !== 'cli') return 'web';
        return getenv('FREEITSM_JOB_TRIGGER') === 'page' ? 'page' : 'cron';
    }

    /**
     * Takes this job's run lock, so a job started twice - by the scheduler, an old per-job cron
     * line, its token address, the fallback or Run now - still runs once (#233). Turned away, it
     * records the skip, answers 429 over HTTP and returns false: the caller exits.
     *
     *     if (!JobRun::lock($conn, 'domains', $trigger)) exit;
     *
     * TRAP: the lock belongs to the database CONNECTION and goes when it closes, which is the
     *   end of the process or request. Never open the connection with PDO::ATTR_PERSISTENT, or
     *   a finished request keeps the lock and the job never runs again.
     * TRAP: GET_LOCK names are shared by every database on the MySQL server, so the name
     *   includes this database - two installs on one server must not lock each other out.
     *
     * @param string $job  a key from jobRegistry()
     * @param string $trigger  how this run was started, for the skip record
     */
    public static function lock(PDO $conn, string $job, string $trigger): bool
    {
        if (self::tryLock($conn, $job)) return true;
        if (PHP_SAPI !== 'cli') http_response_code(429);
        self::skipped($conn, $job, $trigger, 'Another run is still going.');
        echo "Skipped: another run of this job is still going.\n";
        return false;
    }

    /**
     * Takes this job's run lock and says nothing either way - for Run now and page-load runs,
     * which answer in their own words. The same lock as lock(), so they never overlap a scheduled run.
     *
     * @param string $job  a key from jobRegistry()
     */
    public static function tryLock(PDO $conn, string $job): bool
    {
        $s = $conn->prepare("SELECT GET_LOCK(?, 0)");
        $s->execute([self::lockName($conn, $job)]);
        return (int)$s->fetchColumn() === 1;
    }

    /** Gives the lock back before the connection closes - for a page run, which carries on serving the page. */
    public static function unlock(PDO $conn, string $job): void
    {
        try {
            $conn->prepare("SELECT RELEASE_LOCK(?)")->execute([self::lockName($conn, $job)]);
        } catch (Throwable) {
            // it goes when the connection closes anyway
        }
    }

    /** The lock's name, within MySQL's 64 characters whatever the database is called. */
    public static function lockName(PDO $conn, string $job): string
    {
        return 'freeitsm.job.' . substr(md5((string)$conn->query('SELECT DATABASE()')->fetchColumn()), 0, 16) . '.' . $job;
    }

    /**
     * Records that a job has started work. Returns the run id, or null when it could not be recorded.
     *
     * @param string $job  a key from jobRegistry(), e.g. 'task_recurrence'
     * @param string $trigger  cron, web, page or manual
     * @param ?int $analystId  who pressed Run now, or whose page load ran it; null for a schedule
     */
    public static function start(PDO $conn, string $job, string $trigger, ?int $analystId = null): ?int
    {
        if (!in_array($trigger, self::TRIGGERS, true)) $trigger = 'manual';
        try {
            $conn->prepare("INSERT INTO job_runs (job_key, trigger_kind, outcome, started_datetime, analyst_id)
                            VALUES (?, ?, 'running', UTC_TIMESTAMP(), ?)")
                 ->execute([$job, $trigger, $analystId ?: null]);
            $id = (int)$conn->lastInsertId();
        } catch (Throwable) {
            return null;
        }
        self::$open[$id] = $conn;
        self::$current = $id;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'onShutdown']);
        }
        return $id;
    }

    /** The newest run started in this request (still open or not), or null. */
    public static function current(): ?int
    {
        return self::$current;
    }

    /**
     * Records the result for one source the run worked through.
     *
     * @param ?int $runId  from start(); null records nothing
     * @param string $job  the job key (kept on the row so health reads one table)
     * @param string $sourceKey  a stable key for the source, e.g. 'connection:3' - recovery is matched on it
     * @param string $label  what to call it on the page, e.g. the connection's name
     * @param bool $ok  whether that source was processed successfully
     * @param ?string $message  a short message; cleaned of anything secret-looking
     */
    public static function source(PDO $conn, ?int $runId, string $job, string $sourceKey, string $label, bool $ok, ?string $message = null): void
    {
        if (!$runId) return;
        try {
            $conn->prepare("INSERT INTO job_run_sources (run_id, job_key, source_key, source_label, ok, message, created_datetime)
                            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$runId, $job, mb_substr($sourceKey, 0, 80), mb_substr($label, 0, 190), $ok ? 1 : 0,
                            $message !== null && $message !== '' ? self::clean($message) : null]);
        } catch (Throwable) {
            // never break the job
        }
    }

    /**
     * Completes a run.
     *
     * @param ?int $runId  from start(); null records nothing
     * @param ?string $outcome  ok, partial, failed or skipped; null works it out from the sources recorded -
     *     all failed is failed, some failed is partial, otherwise ok
     * @param array $counts  what it did, e.g. ['sent' => 3, 'failed' => 0] - shown on the page
     * @param ?string $summary  one line written by the job; cleaned of anything secret-looking
     */
    public static function finish(PDO $conn, ?int $runId, ?string $outcome = null, array $counts = [], ?string $summary = null): void
    {
        if (!$runId) return;
        unset(self::$open[$runId]);
        try {
            if ($outcome === null) {
                $s = $conn->prepare("SELECT SUM(ok = 1), SUM(ok = 0) FROM job_run_sources WHERE run_id = ?");
                $s->execute([$runId]);
                [$okN, $badN] = array_map('intval', $s->fetch(PDO::FETCH_NUM) ?: [0, 0]);
                $outcome = $badN === 0 ? 'ok' : ($okN === 0 ? 'failed' : 'partial');
            }
            if (!in_array($outcome, ['ok', 'partial', 'failed', 'skipped'], true)) $outcome = 'failed';
            $conn->prepare("UPDATE job_runs
                               SET outcome = ?, finished_datetime = UTC_TIMESTAMP(),
                                   duration_ms = TIMESTAMPDIFF(MICROSECOND, started_datetime, UTC_TIMESTAMP()) DIV 1000,
                                   counts_json = ?, summary = ?
                             WHERE id = ? AND outcome = 'running'")
                 ->execute([$outcome, $counts ? json_encode($counts) : null,
                            $summary !== null && $summary !== '' ? self::clean($summary) : null, $runId]);

            // A run of this job that started long ago and never finished died
            // without saying so. Now that a later one has finished, call it what it was.
            $job = $conn->prepare("SELECT job_key FROM job_runs WHERE id = ?");
            $job->execute([$runId]);
            $key = (string)$job->fetchColumn();
            $def = jobRegistry()[$key] ?? null;
            if ($def) {
                $conn->prepare("UPDATE job_runs SET outcome = 'failed', finished_datetime = UTC_TIMESTAMP(),
                                       summary = 'Never finished: the process stopped before recording a result.'
                                 WHERE job_key = ? AND outcome = 'running' AND id < ?
                                   AND started_datetime < UTC_TIMESTAMP() - INTERVAL ? MINUTE")
                     ->execute([$key, $runId, (int)$def['max_runtime']]);
            }
            if (random_int(1, 50) === 1) self::prune($conn);
        } catch (Throwable) {
            // never break the job
        }
    }

    /**
     * Completes a run as failed, with the reason.
     *
     * @param ?int $runId  from start()
     * @param Throwable|string $why  the exception or a sentence; cleaned before it is stored
     */
    public static function fail(PDO $conn, ?int $runId, $why, array $counts = []): void
    {
        $msg = $why instanceof Throwable ? $why->getMessage() : (string)$why;
        self::finish($conn, $runId, 'failed', $counts, $msg !== '' ? $msg : 'Failed.');
    }

    /**
     * Records a run that did no work - turned away by its lock or minimum interval, switched off, or a dry run.
     * Health never reads these when deciding whether a job is failing.
     *
     * @param string $reason  why, in a short sentence
     */
    public static function skipped(PDO $conn, string $job, string $trigger, string $reason, ?int $analystId = null): void
    {
        $id = self::start($conn, $job, $trigger, $analystId);
        self::finish($conn, $id, 'skipped', [], $reason);
    }

    /**
     * Runs $work as one recorded run - for page loads and Run now, which call the
     * same function the cron does. $work receives the run id and returns
     * ['outcome' => ?, 'counts' => [], 'summary' => ?] or anything else (taken as ok).
     * Rethrows, after recording the failure.
     *
     * @param callable $work  function (?int $runId): mixed
     */
    public static function wrap(PDO $conn, string $job, string $trigger, callable $work, ?int $analystId = null)
    {
        $id = self::start($conn, $job, $trigger, $analystId);
        try {
            $r = $work($id);
        } catch (Throwable $e) {
            self::fail($conn, $id, $e);
            throw $e;
        }
        $r2 = is_array($r) ? $r : [];
        self::finish($conn, $id, $r2['outcome'] ?? null, $r2['counts'] ?? [], $r2['summary'] ?? null);
        return $r;
    }

    /**
     * Remembers the most recent call turned away at the door (a wrong or missing token).
     * One line per job, overwritten - never a run.
     *
     * @param string $reason  e.g. 'wrong token'
     */
    public static function refused(PDO $conn, string $job, string $reason): void
    {
        try {
            $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                 ->execute(['job_refused_' . $job, json_encode(['at' => gmdate('Y-m-d H:i:s'), 'reason' => mb_substr($reason, 0, 120)])]);
        } catch (Throwable) {
            // never break the job
        }
    }

    /**
     * Deletes old runs: skipped ones after 30 days, the rest after the retention set on the page
     * (13 months by default, so a yearly audit - and #181's report - can still read them).
     */
    public static function prune(PDO $conn): void
    {
        try {
            $months = jobHealthRetentionMonths($conn);
            $conn->exec("DELETE FROM job_runs WHERE outcome = 'skipped' AND started_datetime < UTC_TIMESTAMP() - INTERVAL 30 DAY LIMIT 5000");
            $old = $conn->prepare("SELECT id FROM job_runs WHERE started_datetime < UTC_TIMESTAMP() - INTERVAL ? MONTH ORDER BY id LIMIT 5000");
            $old->execute([$months]);
            $ids = array_map('intval', $old->fetchAll(PDO::FETCH_COLUMN));
            if ($ids) {
                $in = implode(',', $ids);
                $conn->exec("DELETE FROM job_run_sources WHERE run_id IN ($in)");
                $conn->exec("DELETE FROM job_runs WHERE id IN ($in)");
            }
            // Sources whose run was a skip, or has gone.
            $conn->exec("DELETE s FROM job_run_sources s LEFT JOIN job_runs r ON r.id = s.run_id WHERE r.id IS NULL");
        } catch (Throwable) {
            // never break the job
        }
    }

    /**
     * Makes a message safe to show: credentials in URLs, query strings, long hex or base64 runs
     * (tokens, keys) and "password=..." pairs are blanked, then it is cut to 500 characters.
     *
     * @param string $msg  a message that may have come from an exception
     */
    public static function clean(string $msg): string
    {
        $msg = preg_replace('#(https?://)[^/\s:@]+:[^/\s@]+@#i', '$1***@', $msg);           // user:pass@host
        $msg = preg_replace('#(https?://[^\s?]+)\?\S*#i', '$1?***', $msg);                  // query strings carry tokens
        $msg = preg_replace('/\b(password|passwd|pwd|secret|token|api[_-]?key|apikey|authorization|bearer)\b(\s*[=:]\s*|\s+)\S+/i', '$1$2***', $msg);
        $msg = preg_replace('/\b[A-Fa-f0-9]{24,}\b/', '***', $msg);                          // hex tokens
        $msg = preg_replace('/\b[A-Za-z0-9+\/_-]{40,}={0,2}/', '***', $msg);                 // long base64-ish keys
        $msg = trim(preg_replace('/\s+/', ' ', (string)$msg));
        return mb_substr($msg, 0, 500);
    }

    /** Marks any run this request started and never finished as failed - a fatal error, a timeout. */
    public static function onShutdown(): void
    {
        if (!self::$open) return;
        $err = error_get_last();
        $why = $err && in_array($err['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)
            ? 'Stopped by a PHP error: ' . $err['message']
            : 'Stopped before recording a result.';
        foreach (self::$open as $id => $conn) {
            self::fail($conn, $id, $why);
        }
        self::$open = [];
    }
}
