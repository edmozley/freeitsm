<?php
/**
 * The background job registry (#182) - every scheduled job FreeITSM has, and what is expected of it.
 *
 * ONE list, read by the run recorder (includes/job_runs.php), the health check
 * (includes/job_health.php), the Background jobs tab and the REST health address.
 * A new background job is not finished until it is here AND calls JobRun - the
 * health page can only judge jobs it knows about.
 *
 * Per job:
 *   name_key / affects_key  i18n keys (reporting namespace): its name, and what stops without it
 *   command                 how it is scheduled, as Scheduled-Tasks documents it
 *   web                     whether it also has a token web address (otherwise command line only)
 *   interval                expected minutes between scheduled runs (an administrator can change it)
 *   grace                   minutes of slack before a late run is called overdue
 *   max_runtime             minutes after which a run still marked running is stuck
 *   needed                  fn(PDO): bool - the SAME test the feature uses; false = "Not needed"
 *   sources                 fn(PDO): array of source keys that still exist, or absent when the job has
 *                           none - a failing source that no longer exists stops counting (it is no longer
 *                           expected), which is the "explicit change" Dr4inder asked for
 *
 * TRAP: every `needed` and `sources` callable must survive a missing table - an
 * install part-way through an upgrade still opens the health page - AND must NOT
 * survive any other failure. jobNeedOne(), jobSourceKeys() and jobSettingStrict()
 * draw that line for you; use them.
 *
 * 🔴 A FAILED LOOKUP IS NOT AN ANSWER (#182, Dr4inder's review). These used to turn
 * every error into false / empty, so a database that could not be read made every
 * job "Not needed" and the health address said healthy - a monitor stayed green
 * through exactly the failure it is there to catch. Only a table that does not
 * exist (SQLSTATE 42S02: the feature has never been set up here) is an answer;
 * anything else throws JobHealthUnknown and the job's status is "unknown", which
 * counts as unhealthy.
 */

/** The auth_providers rows directory_sync imports from: LDAP, CardDAV, and OIDC providers whose issuer is Microsoft Entra ID. */
const JOB_DSYNC_PROTOCOLS = "(protocol IN ('ldap','carddav') OR (protocol = 'oidc' AND (issuer_url LIKE 'https://login.microsoftonline.%'"
                          . " OR issuer_url LIKE 'https://login.chinacloudapi.cn/%' OR issuer_url LIKE 'https://sts.windows.net/%')))";

/** A lookup the health check could not make. The job's status becomes "unknown". */
class JobHealthUnknown extends RuntimeException {}

/** Is this failure "the table is not there" (the feature never set up) rather than a read that failed? */
function jobLookupMissingTable(Throwable $e): bool
{
    return $e instanceof PDOException && (string)$e->getCode() === '42S02';
}

/** True when the query returns a row; false when it returns none or its table does not exist. Throws on any other failure. */
function jobNeedOne(PDO $conn, string $sql, array $args = []): bool
{
    try {
        $s = $conn->prepare($sql);
        $s->execute($args);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) {
        if (jobLookupMissingTable($e)) return false;
        throw new JobHealthUnknown('requirement', 0, $e);
    }
}

/** The source keys a query returns, prefixed; empty when its table does not exist. Throws on any other failure. */
function jobSourceKeys(PDO $conn, string $prefix, string $sql): array
{
    try {
        return array_map(fn($id) => $prefix . $id, $conn->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        if (jobLookupMissingTable($e)) return [];
        throw new JobHealthUnknown('sources', 0, $e);
    }
}

/** A system_settings value, or '' when unset. Throws when it cannot be read - for the health check. */
function jobSettingStrict(PDO $conn, string $key): string
{
    try {
        $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v === false || $v === null ? '' : (string)$v;
    } catch (Throwable $e) {
        if (jobLookupMissingTable($e)) return '';
        throw new JobHealthUnknown('setting', 0, $e);
    }
}

/** A system_settings value, or '' when unset or unreadable. */
function jobSetting(PDO $conn, string $key): string
{
    try {
        $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v === false || $v === null ? '' : (string)$v;
    } catch (Throwable) {
        return '';
    }
}

function jobRegistry(): array
{
    static $jobs = null;
    if ($jobs !== null) return $jobs;

    $jobs = [
        'sla_breach' => [
            'command' => 'php cron/sla_breach_check.php', 'web' => true,
            'interval' => 5, 'grace' => 10, 'max_runtime' => 10,
            'needed'  => fn(PDO $c) => jobSettingStrict($c, 'sla_enforce_from') !== '',
        ],
        'webhook_deliveries' => [
            'command' => 'php cron/webhook_deliveries.php', 'web' => true,
            'interval' => 1, 'grace' => 5, 'max_runtime' => 5,
            // A workflow that can send one, or something already queued.
            'needed'  => fn(PDO $c) => jobNeedOne($c, "SELECT 1 FROM workflows WHERE is_active = 1 AND actions LIKE '%send_webhook%' LIMIT 1")
                                     || jobNeedOne($c, "SELECT 1 FROM webhook_deliveries WHERE status IN ('pending','delivering','failed') LIMIT 1"),
        ],
        'workflow_scheduled' => [
            'command' => 'php cron/workflow_scheduled.php', 'web' => true,
            'interval' => 5, 'grace' => 60, 'max_runtime' => 10,
            'needed'  => fn(PDO $c) => jobNeedOne($c, "SELECT 1 FROM workflows WHERE is_active = 1 AND trigger_event IN ('contract.expiring','asset.warranty_expiring') LIMIT 1")
                                     || jobNeedOne($c, "SELECT 1 FROM status_planned WHERE state = 'scheduled' LIMIT 1")
                                     || jobNeedOne($c, "SELECT 1 FROM projects LIMIT 1"),
            // Its three parts, each its own source so one failing part is named.
            'sources' => fn(PDO $c) => ['part:expiries', 'part:planned_maintenance', 'part:projects'],
        ],
        'integration_poll' => [
            'command' => 'php cron/integration_poll.php', 'web' => true,
            'interval' => 5, 'grace' => 15, 'max_runtime' => 10,
            'needed'  => fn(PDO $c) => jobNeedOne($c, "SELECT 1 FROM integration_connections WHERE is_active = 1 LIMIT 1"),
            'sources' => fn(PDO $c) => jobSourceKeys($c, 'connection:', "SELECT id FROM integration_connections WHERE is_active = 1"),
        ],
        'task_recurrence' => [
            'command' => 'php cron/task_recurrence.php', 'web' => true,
            'interval' => 1440, 'grace' => 360, 'max_runtime' => 10,
            // Only a FIXED SCHEDULE needs it: a repeat on completion makes its next one in the request that completed it.
            'needed'  => fn(PDO $c) => jobNeedOne($c, "SELECT 1 FROM task_recurrences WHERE is_active = 1 AND mode = 'schedule' LIMIT 1"),
        ],
        'attachment_extract' => [
            'command' => 'php cron/attachment_extract.php', 'web' => true,
            'interval' => 5, 'grace' => 30, 'max_runtime' => 15,
            'needed'  => fn(PDO $c) => jobSettingStrict($c, 'attachment_extract_cron') === '1'
                                     && jobNeedOne($c, "SELECT 1 FROM system_settings WHERE setting_key = 'tika_url' AND setting_value <> '' LIMIT 1"),
        ],
        'calendar_sync_pull' => [
            'command' => 'php cron/calendar_sync_pull.php', 'web' => true,
            'interval' => 5, 'grace' => 15, 'max_runtime' => 10,
            // Only two-way sync reads calendars back. An install that only hands out
            // subscription links has nothing to pull.
            'needed'  => fn(PDO $c) => jobNeedOne($c, "SELECT 1 FROM calendar_enrolments WHERE mode = 'push' LIMIT 1"),
            'sources' => fn(PDO $c) => jobSourceKeys($c, 'analyst:', "SELECT analyst_id FROM calendar_enrolments WHERE mode = 'push'"),
        ],
        'domains' => [
            'command' => 'php cron/domains.php', 'web' => true,
            'interval' => 60, 'grace' => 120, 'max_runtime' => 10,
            'needed'  => fn(PDO $c) => jobNeedOne($c, "SELECT 1 FROM domains WHERE monitoring_enabled = 1 LIMIT 1"),
        ],
        'lms_reminders' => [
            'command' => 'php cron/lms_reminders.php', 'web' => true,
            'interval' => 1440, 'grace' => 360, 'max_runtime' => 10,
            'needed'  => fn(PDO $c) => jobSettingStrict($c, 'lms_reminders_enabled') === '1',
        ],
        'proxmox_sync' => [
            'command' => 'php cron/proxmox_sync.php', 'web' => false,
            'interval' => 15, 'grace' => 30, 'max_runtime' => 30,
            'needed'  => fn(PDO $c) => jobNeedOne($c, "SELECT 1 FROM proxmox_connections WHERE is_active = 1 LIMIT 1"),
            'sources' => fn(PDO $c) => jobSourceKeys($c, 'server:', "SELECT id FROM proxmox_connections WHERE is_active = 1"),
        ],
        'vcloud_sync' => [
            'command' => 'php cron/vcloud_sync.php', 'web' => false,
            'interval' => 15, 'grace' => 30, 'max_runtime' => 30,
            'needed'  => fn(PDO $c) => jobNeedOne($c, "SELECT 1 FROM vcloud_connections WHERE is_active = 1 LIMIT 1"),
            'sources' => fn(PDO $c) => jobSourceKeys($c, 'server:', "SELECT id FROM vcloud_connections WHERE is_active = 1"),
        ],
        'documents_maintenance' => [
            'command' => 'php scripts/documents_maintenance.php --quiet', 'web' => false,
            'interval' => 5, 'grace' => 60, 'max_runtime' => 30,
            'needed'  => fn(PDO $c) => jobNeedOne($c, "SELECT 1 FROM documents LIMIT 1"),
            'sources' => fn(PDO $c) => ['part:extract', 'part:tidy'],
        ],
        'directory_sync' => [
            'command' => 'php scripts/directory_sync.php --all --quiet', 'web' => false,
            'interval' => 1440, 'grace' => 720, 'max_runtime' => 60,
            // An OIDC row is a source only when its issuer is Entra - the same
            // test as entraIsEntraIssuer() and scripts/directory_sync.php --all.
            'needed'  => fn(PDO $c) => jobNeedOne($c, "SELECT 1 FROM auth_providers WHERE enabled = 1 AND sync_enabled = 1 AND " . JOB_DSYNC_PROTOCOLS . " LIMIT 1"),
            'sources' => fn(PDO $c) => jobSourceKeys($c, 'provider:', "SELECT id FROM auth_providers WHERE enabled = 1 AND sync_enabled = 1 AND " . JOB_DSYNC_PROTOCOLS),
        ],
    ];
    // GH #217. A job whose module is switched off for the install is not needed:
    // it skips by design, and health must not call that overdue.
    $jobModules = [
        'lms_reminders' => 'lms', 'domains' => 'domains', 'task_recurrence' => 'tasks',
        'webhook_deliveries' => 'workflow', 'proxmox_sync' => 'assets', 'vcloud_sync' => 'assets',
        'sla_breach' => 'sla',   // a Tickets feature (slice 2)
    ];
    foreach ($jobs as $k => &$j) {
        $j['key']         = $k;
        $j['name_key']    = 'reporting.jobs.name_' . $k;
        $j['affects_key'] = 'reporting.jobs.affects_' . $k;
        if (isset($jobModules[$k])) {
            $needed = $j['needed'];
            $module = $jobModules[$k];
            $j['needed'] = fn(PDO $c) => moduleIsEnabled($c, $module) && $needed($c);
        }
    }
    unset($j);
    return $jobs;
}

/**
 * Every job health judges: the scheduled jobs above, plus one entry per active PUSHED source
 * (GH #199) - a Hyper-V connection whose collector should have reported by now. A pushed
 * source has no command for FreeITSM to run; it is healthy when its reports arrive on time,
 * so each entry carries its own interval and grace from the connection, its own name, and
 * 'pushed' => true. Its runs are recorded with trigger 'push' when a report arrives
 * (VirtualEstateService::submit()), and the freshness rule counts those as scheduled runs.
 *
 * TRAP: anything that lists or looks up jobs must use this, not jobRegistry(), or a pushed
 *   connection is invisible on System -> Background jobs and in the REST health address.
 *
 * @return array<string,array> keyed by job, the scheduled ones first
 */
function jobRegistryAll(PDO $conn): array
{
    $jobs = jobRegistry();
    // GH #217. Hyper-V collectors report into Assets; with Assets switched off they
    // are refused, so they are not jobs to judge.
    if (!moduleIsEnabled($conn, 'assets')) return $jobs;
    try {
        $rows = $conn->query("SELECT id, name, expected_interval_minutes, grace_minutes FROM virt_connections WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if (jobLookupMissingTable($e)) return $jobs;   // the virtual estate has never been set up here
        throw new JobHealthUnknown('pushed sources', 0, $e);
    }
    foreach ($rows as $r) {
        $key = 'virt_push_' . (int)$r['id'];
        $jobs[$key] = [
            'key' => $key, 'pushed' => true, 'connection_id' => (int)$r['id'],
            'name' => 'Hyper-V reports: ' . $r['name'],
            'affects' => 'What Assets -> Servers shows for this estate goes stale, and nothing marks VMs that have gone.',
            'name_key' => 'reporting.jobs.name_virt_push', 'affects_key' => 'reporting.jobs.affects_virt_push',
            'name_params' => ['name' => $r['name']],
            'command' => 'scripts/Invoke-HyperVInventory.ps1 (a scheduled task on your Windows management server)', 'web' => false,
            'interval' => max(1, (int)$r['expected_interval_minutes']), 'grace' => max(0, (int)$r['grace_minutes']), 'max_runtime' => 5,
            'needed' => fn(PDO $c) => true,   // listed only while the connection is switched on
        ];
    }
    return $jobs;
}

/**
 * The administrator's changes to the defaults: per job, an interval in minutes and whether it is
 * "not used here". Stored as one JSON setting, job_health_config.
 *
 * @return array<string,array{interval?:int,not_used?:bool,not_used_by?:string,not_used_at?:string}>
 */
function jobHealthConfig(PDO $conn, bool $strict = false): array
{
    // Strict for the health check: whether a job is "not used here", and its interval,
    // are part of the verdict - a failed read must not pass for "no changes".
    $raw = $strict ? jobSettingStrict($conn, 'job_health_config') : jobSetting($conn, 'job_health_config');
    $cfg = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($cfg) ? $cfg : [];
}

/** Months of run history kept (not counting skipped runs, which go after 30 days). 13 by default; 1 to 60. */
function jobHealthRetentionMonths(PDO $conn): int
{
    $v = (int)jobSetting($conn, 'job_runs_retention_months');
    return $v >= 1 ? min(60, $v) : 13;
}
