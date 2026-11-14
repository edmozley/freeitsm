<?php
/**
 * Background job health (#182) - is each job doing what this install needs of it?
 *
 * Worked out WHEN ASKED, from the run history (job_runs, written by
 * includes/job_runs.php) and the registry (includes/job_registry.php). Nothing
 * here runs a job, and nothing depends on a job running to say it has stopped:
 * a stopped job turns overdue because time passes, not because it ran to say so.
 *
 * The rules, agreed on #182 (Dr4inder's clarifications of 19 Oct 2026):
 *
 *  1. FRESHNESS READS ONLY SCHEDULED RUNS. A job is fresh when a run triggered by
 *     cron (the command line) or web (its token address) finished ok or partial
 *     within its interval plus grace. A page load or Run now does the work and is
 *     shown, but never makes an overdue schedule look healthy - otherwise a monitor
 *     that only reads status codes stays green while the cron entry is gone.
 *  2. FAILURE READS ONLY REAL RUNS. Failing and partial come from runs that
 *     processed work (ok, partial, failed). Skipped runs - a lock, a minimum
 *     interval, switched off, a dry run - are never consulted, so the newest run
 *     being a skip cannot clear a failure. Refused calls are not runs at all.
 *  3. RECOVERY IS PER SOURCE. A source (a tracker connection, a directory...) is
 *     failing until a later run processed THAT source successfully - or it no
 *     longer exists or is switched off, which changes whether it is expected.
 *  4. "NOT USED HERE" is the explicit change: an administrator marking a job so,
 *     or the feature behind it falling out of use, clears its health.
 *
 * TRAP: health is install-wide. The REST address and the page must never be
 * narrowed by company - a job serves every company at once - and so neither may
 * be opened by a key or a person who only reaches some companies (see the gate
 * in api/v1/resources/jobs.php).
 */

require_once __DIR__ . '/job_registry.php';

/** The states that need somebody's attention - the ones the Watchtower card, the System notice and ?strict=1 react to. */
// 'unknown' (#182 review): something needed to judge the job could not be read - never assumed healthy.
const JOB_HEALTH_UNHEALTHY = ['unknown', 'stuck', 'failing', 'overdue', 'never_seen', 'partial'];

/** A string through the translator when there is one, otherwise the fallback (the REST address, a CLI). */
function jobHealthT(string $key, string $fallback, array $params = []): string
{
    if (function_exists('t')) {
        $v = t($key, $params);
        if ($v !== $key) return $v;
    }
    foreach ($params as $k => $p) $fallback = str_replace('{' . $k . '}', (string)$p, $fallback);
    return $fallback;
}

/** A job's name: a pushed source's carries the connection's name. */
function jobHealthName(array $def): string
{
    return jobHealthT($def['name_key'], $def['name'] ?? $def['key'], $def['name_params'] ?? []);
}

/**
 * The health of every job, in the registry's order.
 *
 * @return array<string,array> keyed by job; see jobHealthOne()
 */
function jobHealthAll(PDO $conn): array
{
    $cfgFailed = false;
    try {
        $cfg = jobHealthConfig($conn, true);
    } catch (Throwable $e) {
        // Without the configuration nothing can be called "not used here" or on time.
        error_log('[job health] configuration unreadable: ' . ($e->getPrevious() ?? $e)->getMessage());
        $cfg = [];
        $cfgFailed = true;
    }
    $out = [];
    try {
        $registry = jobRegistryAll($conn);
    } catch (JobHealthUnknown $e) {
        error_log('[job health] pushed sources unreadable: ' . ($e->getPrevious() ?? $e)->getMessage());
        $registry = jobRegistry();
        $out['pushed_sources'] = jobHealthUnknownResult(['key' => 'pushed_sources', 'name_key' => 'reporting.jobs.name_pushed_sources',
            'affects_key' => 'reporting.jobs.affects_pushed_sources', 'command' => '', 'web' => false, 'interval' => 15, 'grace' => 15], 'pushed sources');
    }
    foreach ($registry as $key => $def) {
        $out[$key] = $cfgFailed ? jobHealthUnknownResult($def, 'configuration') : jobHealthOne($conn, $def, $cfg[$key] ?? []);
    }
    return $out;
}

/**
 * The overall answer: healthy or not, and which jobs need attention, worst first.
 *
 * @return array{healthy:bool, checked_utc:string, unhealthy:array<int,array>}
 */
function jobHealthSummary(PDO $conn): array
{
    $all = jobHealthAll($conn);
    $bad = array_values(array_filter($all, fn($j) => in_array($j['status'], JOB_HEALTH_UNHEALTHY, true)));
    usort($bad, fn($a, $b) => $b['severity'] <=> $a['severity']);
    return ['healthy' => !$bad, 'checked_utc' => gmdate('Y-m-d H:i:s'), 'unhealthy' => $bad, 'jobs' => $all];
}

/**
 * One job's health.
 *
 * @param array $def  its jobRegistry() entry
 * @param array $cfg  the administrator's changes for it: interval, not_used
 * @return array{key:string,name:string,affects:string,status:string,severity:int,problems:array,needed:bool,
 *     interval:int,default_interval:int,grace:int,last_scheduled_success:?string,last_success:?string,
 *     last_success_trigger:?string,last_run:?array,failing_sources:array,refused:?array,not_used:?array}
 */
function jobHealthOne(PDO $conn, array $def, array $cfg): array
{
    $key      = $def['key'];
    $interval = isset($cfg['interval']) && (int)$cfg['interval'] > 0 ? (int)$cfg['interval'] : (int)$def['interval'];
    $grace    = max((int)$def['grace'], (int)ceil($interval / 2));   // a longer interval earns a longer grace
    $now      = time();

    try {
        return jobHealthJudge($conn, $def, $cfg, $key, $interval, $grace, $now);
    } catch (Throwable $e) {
        // 🔴 A failed lookup is not an answer: "unknown", which is unhealthy, never "not needed".
        $what = $e instanceof JobHealthUnknown ? $e->getMessage() : 'history';
        error_log('[job health] ' . $key . ': could not read the ' . $what . ': ' . ($e->getPrevious() ?? $e)->getMessage());
        return jobHealthUnknownResult($def, $what, $interval, $grace);
    }
}

/**
 * The status for a job whose health could not be worked out: "unknown", the most severe, naming
 * WHAT could not be read (requirement, configuration, setting, sources, source health, history)
 * but not the database's own message - that goes to the server log, not to a monitor or a page.
 *
 * @param string $what  which lookup failed
 */
function jobHealthUnknownResult(array $def, string $what, ?int $interval = null, ?int $grace = null): array
{
    $key = $def['key'];
    return [
        'key' => $key, 'name' => jobHealthName($def), 'affects' => jobHealthT($def['affects_key'], $def['affects'] ?? ''),
        'command' => $def['command'], 'web' => (bool)$def['web'], 'needed' => null,
        'interval' => $interval ?? (int)$def['interval'], 'default_interval' => (int)$def['interval'], 'grace' => $grace ?? (int)$def['grace'],
        'last_scheduled_success' => null, 'last_success' => null, 'last_success_trigger' => null, 'last_run' => null,
        'failing_sources' => [], 'refused' => null, 'not_used' => null,
        'status' => 'unknown', 'severity' => 6,
        'problems' => [['code' => 'unknown', 'severity' => 6, 'what' => $what]],
    ];
}

/** jobHealthOne()'s work. Throws when something it needs cannot be read. */
function jobHealthJudge(PDO $conn, array $def, array $cfg, string $key, int $interval, int $grace, int $now): array
{
    $row = function (string $sql, array $args) use ($conn) {
        try { $s = $conn->prepare($sql); $s->execute($args); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
        catch (Throwable $e) {
            if (jobLookupMissingTable($e)) return null;      // run history not set up yet
            throw new JobHealthUnknown('history', 0, $e);
        }
    };
    $ts = fn(?string $utc) => $utc ? strtotime($utc . ' UTC') : null;

    $lastSched = $row("SELECT finished_datetime FROM job_runs
                        WHERE job_key = ? AND trigger_kind IN ('cron','web','push') AND outcome IN ('ok','partial')
                        ORDER BY id DESC LIMIT 1", [$key]);
    $lastAny   = $row("SELECT finished_datetime, trigger_kind FROM job_runs
                        WHERE job_key = ? AND outcome IN ('ok','partial') ORDER BY id DESC LIMIT 1", [$key]);
    $lastReal  = $row("SELECT id, trigger_kind, outcome, started_datetime, finished_datetime, summary FROM job_runs
                        WHERE job_key = ? AND outcome IN ('ok','partial','failed') ORDER BY id DESC LIMIT 1", [$key]);
    // Who started it, for the audit (#343): an analyst for Run now; nobody - System - for a schedule.
    $lastRun   = $row("SELECT r.id, r.trigger_kind, r.outcome, r.started_datetime, r.finished_datetime, r.summary, r.counts_json,
                               a.full_name AS analyst_name
                          FROM job_runs r LEFT JOIN analysts a ON a.id = r.analyst_id
                         WHERE r.job_key = ? ORDER BY r.id DESC LIMIT 1", [$key]);
    $running   = $row("SELECT id, started_datetime, trigger_kind FROM job_runs
                        WHERE job_key = ? AND outcome = 'running' ORDER BY id DESC LIMIT 1", [$key]);

    // 🔴 Not caught: a requirement that cannot be looked up is "unknown", never "not needed".
    $needed = (bool)($def['needed'])($conn);

    // Rule 3: each source's newest result; a failing one counts while the source still exists.
    $failing = [];
    try {
        $s = $conn->prepare("SELECT s.source_key, s.source_label, s.ok, s.message, s.created_datetime
                               FROM job_run_sources s
                               JOIN (SELECT MAX(id) AS id FROM job_run_sources WHERE job_key = ? GROUP BY source_key) m ON m.id = s.id");
        $s->execute([$key]);
        $current = isset($def['sources']) ? array_flip(($def['sources'])($conn)) : null;
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $src) {
            if ((int)$src['ok'] === 1) continue;
            if ($current !== null && !isset($current[$src['source_key']])) continue;   // gone or switched off: no longer expected
            $failing[] = ['key' => $src['source_key'], 'label' => $src['source_label'], 'message' => $src['message'],
                          'at' => $src['created_datetime']];
        }
    } catch (Throwable $e) {
        // Only a run-history table not created yet means "nothing failing". A failed READ of
        // source health, or of which sources still exist, is not "removed" - it is unknown.
        if (!jobLookupMissingTable($e)) throw $e instanceof JobHealthUnknown ? $e : new JobHealthUnknown('source health', 0, $e);
    }

    $refused = null;
    $r = jobSetting($conn, 'job_refused_' . $key);
    if ($r !== '') { $refused = json_decode($r, true) ?: null; }

    $base = [
        'key'                    => $key,
        'name'                   => jobHealthName($def),
        'affects'                => jobHealthT($def['affects_key'], $def['affects'] ?? ''),
        'pushed'                 => !empty($def['pushed']),
        'command'                => $def['command'],
        'web'                    => (bool)$def['web'],
        'needed'                 => $needed,
        'interval'               => $interval,
        'default_interval'       => (int)$def['interval'],
        'grace'                  => $grace,
        'last_scheduled_success' => $lastSched['finished_datetime'] ?? null,
        'last_success'           => $lastAny['finished_datetime'] ?? null,
        'last_success_trigger'   => $lastAny['trigger_kind'] ?? null,
        'last_run'               => $lastRun ? [
            'id' => (int)$lastRun['id'], 'trigger' => $lastRun['trigger_kind'], 'outcome' => $lastRun['outcome'],
            'started' => $lastRun['started_datetime'], 'finished' => $lastRun['finished_datetime'],
            'summary' => $lastRun['summary'], 'counts' => $lastRun['counts_json'] ? (json_decode($lastRun['counts_json'], true) ?: []) : [],
            'analyst_name' => $lastRun['analyst_name'],
        ] : null,
        'failing_sources'        => $failing,
        'refused'                => $refused,
        'not_used'               => !empty($cfg['not_used']) ? ['by' => $cfg['not_used_by'] ?? null, 'at' => $cfg['not_used_at'] ?? null] : null,
        'problems'               => [],
    ];

    // Rule 4: not expected at all.
    if (!empty($cfg['not_used'])) return $base + ['status' => 'not_used', 'severity' => 0];
    if (!$needed)                 return $base + ['status' => 'not_needed', 'severity' => 0];

    $problems = [];

    // Stuck: a run started longer ago than its longest normal run, with nothing real since.
    if ($running && $ts($running['started_datetime']) < $now - 60 * (int)$def['max_runtime']
        && (!$lastReal || (int)$lastReal['id'] < (int)$running['id'])) {
        $problems[] = ['code' => 'stuck', 'severity' => 5, 'since' => $running['started_datetime']];
    }
    // Rule 2: failing - the newest REAL run failed.
    if ($lastReal && $lastReal['outcome'] === 'failed') {
        $problems[] = ['code' => 'failing', 'severity' => 4, 'since' => $lastReal['started_datetime'], 'message' => $lastReal['summary']];
    }
    // Rule 1: freshness, from scheduled runs only.
    $sched = $ts($base['last_scheduled_success']);
    if ($sched === null) {
        $problems[] = ['code' => 'never_seen', 'severity' => 3,
                       'other_runs' => $base['last_success'] !== null, 'other_trigger' => $base['last_success_trigger']];
    } elseif ($sched < $now - 60 * ($interval + $grace)) {
        $problems[] = ['code' => 'overdue', 'severity' => 3, 'since' => $base['last_scheduled_success'],
                       'other_runs' => $ts($base['last_success']) > $sched, 'other_trigger' => $base['last_success_trigger']];
    }
    // Rule 3 (and a partial run with no named sources): partial.
    if ($failing || ($lastReal && $lastReal['outcome'] === 'partial' && !isset($def['sources']))) {
        $problems[] = ['code' => 'partial', 'severity' => 2, 'sources' => $failing,
                       'message' => $failing ? null : $lastReal['summary']];
    }

    if (!$problems) return array_merge($base, ['status' => 'healthy', 'severity' => 0]);
    usort($problems, fn($a, $b) => $b['severity'] <=> $a['severity']);
    return array_merge($base, ['status' => $problems[0]['code'], 'severity' => $problems[0]['severity'], 'problems' => $problems]);
}

/**
 * Who may see job health: an administrator, or an analyst holding "View background job health"
 * who reaches EVERY company - job health is install-wide, and its messages name mailboxes,
 * directories and servers that belong to all of them.
 *
 * @param int $analystId  the analyst asking
 */
function jobHealthMayView(PDO $conn, int $analystId): bool
{
    if ($analystId <= 0) return false;
    require_once __DIR__ . '/rbac.php';
    if (analystIsAdmin($conn, $analystId)) return true;
    if (!analystHasCapability($conn, $analystId, Cap::REPORTING_JOB_HEALTH)) return false;
    return jobHealthReachesAllCompanies($conn, $analystId);
}

/** May this analyst change what jobs are expected to do (intervals, "not used here")? */
function jobHealthMayConfigure(PDO $conn, int $analystId): bool
{
    if ($analystId <= 0) return false;
    require_once __DIR__ . '/rbac.php';
    if (analystIsAdmin($conn, $analystId)) return true;
    return analystHasCapability($conn, $analystId, Cap::REPORTING_JOB_SETTINGS) && jobHealthReachesAllCompanies($conn, $analystId);
}

/** Does this analyst reach every company? True on a single-company install. */
function jobHealthReachesAllCompanies(PDO $conn, int $analystId): bool
{
    require_once __DIR__ . '/tenancy.php';
    try {
        if (!isMultiTenant($conn)) return true;
        $ids = getAccessibleTenantIds($conn, $analystId);
        $all = $conn->query("SELECT id FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
        return !array_diff(array_map('intval', $all), array_map('intval', $ids));
    } catch (Throwable) {
        return false;   // fail closed
    }
}
