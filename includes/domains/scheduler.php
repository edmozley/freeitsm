<?php
/**
 * Domains — the scheduled run: keep every domain's facts fresh, then alert.
 *
 * One function, three callers:
 *
 *   cron/domains.php          hourly (recommended) — the reliable path
 *   api/domains/tick.php      a small run when somebody opens the module, at
 *                             most once an hour, for installs with no cron
 *   the settings screen       "Run now"
 *
 * Everything works to a TIME BUDGET rather than a count. Lookups and checks
 * take a second or two per domain, and 300 domains is several minutes; a run
 * does what fits, oldest first, and the next run carries on. Nothing is ever
 * left half-written — each domain is finished or not started.
 *
 * Order matters: lookups first (they move expiry dates), checks second (they
 * read what lookups wrote), alerts last (they read both).
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../services/domains.php';
require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/watch.php';
require_once __DIR__ . '/calendar.php';

/**
 * The Domains scheduled job, within a time budget - registry lookups that are due, daily health checks, the weekly certificate and look-alike watches, then alerts, the expiry calendar and Service Status.
 *
 * Stops starting new work when the budget runs low and says so in out_of_time.
 *
 * @param float $budgetSeconds   wall-clock seconds the run may use; 240 for cron, 25 for the page-load run
 * @param bool  $includeWatches  false skips the slow Certificate Transparency and look-alike scans
 *
 * @return array  counts for each stage, the alerts and calendar results, and the seconds taken
 */
function domainScheduledRun(PDO $conn, float $budgetSeconds = 240, bool $includeWatches = true): array
{
    $t0 = microtime(true);
    $s  = domainSettings($conn, true);
    $left = fn() => $budgetSeconds - (microtime(true) - $t0);
    $out = ['lookups' => 0, 'lookup_errors' => 0, 'checks' => 0, 'ct' => 0, 'lookalikes' => 0, 'alerts' => null, 'calendar' => null, 'out_of_time' => false];

    // GH #203: an internal domain is never looked up at a registry, never watched,
    // and is checked on its own interval. The gate (domainMayLeaveNetwork) is
    // applied here in the SQL, so an internal domain is never even picked up for
    // a trip outside; the scan functions ask it again themselves.
    $scoped     = domainScopeReady($conn);
    $notInternal = $scoped ? " AND scope <> 'internal'" : '';

    // ---- 1. registry lookups ---------------------------------------------------
    if ($s['domain_lookup_mode'] === 'scheduled') {
        $days = max(1, (int)$s['domain_lookup_refresh_days']);
        // Stale ones, plus anything expiring inside 45 days daily — that is when
        // a renewal (a new expiry date) most needs noticing.
        // Never a subdomain: its registration is its parent's, and the registry
        // would only answer "not found" (includes/domains/subdomains.php).
        $notSub = domainSubdomainsReady($conn) ? ' AND parent_domain_id IS NULL' : '';
        $ids = $conn->query(
            "SELECT id, domain_name FROM domains
              WHERE monitoring_enabled = 1$notSub$notInternal
                AND (last_lookup_datetime IS NULL
                     OR last_lookup_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $days DAY)
                     OR (expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(UTC_DATE(), INTERVAL 45 DAY)
                         AND last_lookup_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 HOUR)))
           ORDER BY last_lookup_datetime IS NOT NULL, last_lookup_datetime
              LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ids as $r) {
            if ($left() < 5) { $out['out_of_time'] = true; break; }
            domainLookupPace($conn, $r['domain_name']);
            $res = domainLookup($conn, $r['domain_name']);
            if ($res['ok']) { DomainsService::applyLookup($conn, (int)$r['id'], $res); $out['lookups']++; }
            else            { DomainsService::recordLookupError($conn, (int)$r['id'], (string)$res['error']); $out['lookup_errors']++; }
        }
    }

    // ---- 2. health / security checks (daily) ---------------------------------
    if ($s['domain_checks_enabled'] === '1' && !$out['out_of_time']) {
        // Public: daily. Internal: every domain_internal_interval minutes (default hourly).
        $publicDue = 'last_check_datetime IS NULL OR last_check_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 HOUR)';
        $checkDue  = $publicDue;
        if ($scoped) {
            $mins = domainInternalInterval($s);
            $checkDue = "(scope <> 'internal' AND ($publicDue))"
                      . " OR (scope = 'internal' AND (last_check_datetime IS NULL OR last_check_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $mins MINUTE)))";
        }
        $ids = $conn->query(
            "SELECT id FROM domains
              WHERE monitoring_enabled = 1
                AND ($checkDue)
           ORDER BY last_check_datetime IS NOT NULL, last_check_datetime
              LIMIT 500"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            if ($left() < 5) { $out['out_of_time'] = true; break; }
            try { DomainsService::runChecks($conn, null, (int)$id); $out['checks']++; }
            catch (Throwable $e) { error_log("domains check $id: " . $e->getMessage()); }
        }
    }

    // ---- 3. the outside-world watches (weekly per domain) ---------------------
    if ($includeWatches && !$out['out_of_time']) {
        if ($s['domain_ct_watch'] === '1') {
            $rows = domainWatchDue($conn, 'ct_scanned', 7, "purpose NOT IN ('parked')" . $notInternal);
            foreach ($rows as $r) {
                if ($left() < 50) { $out['out_of_time'] = true; break; }   // crt.sh can take 40s
                $res = domainCtScan($conn, (int)$r['id'], $r['domain_name']);
                domainWatchMark($conn, (int)$r['id'], 'ct_scanned');
                if ($res['ok']) $out['ct']++;
                usleep(12500000);                                           // crt.sh: ~5 a minute
            }
        }
        if ($s['domain_lookalike_scan'] === '1' && !$out['out_of_time']) {
            // Look-alikes are of names somebody could register - never a subdomain.
            $rows = domainWatchDue($conn, 'lookalikes_scanned', 7, "purpose IN ('primary', 'secondary')" . (domainSubdomainsReady($conn) ? ' AND parent_domain_id IS NULL' : '') . $notInternal);
            foreach ($rows as $r) {
                if ($left() < 70) { $out['out_of_time'] = true; break; }
                domainLookalikeScan($conn, (int)$r['id'], $r['domain_name'], $s['domain_dns_resolver'], 60);
                domainWatchMark($conn, (int)$r['id'], 'lookalikes_scanned');
                $out['lookalikes']++;
            }
        }
    }

    // ---- 4. alerts + calendar ---------------------------------------------------
    $out['alerts']   = domainAlertsRun($conn, false);
    $out['calendar'] = domainSyncExpiryCalendar($conn);
    // Service Status (3.0.0): raise for linked services (mode auto) and close
    // what a recovered domain raised (domain_status_auto_resolve).
    try { require_once __DIR__ . '/status_link.php'; $out['service_status'] = domainStatusRun($conn); }
    catch (Throwable $e) { error_log('domains service status: ' . $e->getMessage()); }
    $out['seconds']  = round(microtime(true) - $t0, 1);
    return $out;
}

/**
 * The weekly watches keep their "last scanned" in the domain's audit trail
 * rather than two more columns: one row per scan, source 'check'. A domain with
 * no such row in $days days is due.
 *
 * @param string $marker  the domain_audit field_name the watch records, 'ct_scanned' or 'lookalikes_scanned'
 * @param int    $days    how many days since the last marker make a domain due
 * @param string $where   an extra SQL condition on domains (alias d) - interpolated, so only literals from here
 */
function domainWatchDue(PDO $conn, string $marker, int $days, string $where): array
{
    $st = $conn->prepare(
        "SELECT d.id, d.domain_name FROM domains d
          WHERE d.monitoring_enabled = 1 AND $where
            AND NOT EXISTS (SELECT 1 FROM domain_audit a WHERE a.domain_id = d.id AND a.field_name = ?
                             AND a.created_datetime > DATE_SUB(UTC_TIMESTAMP(), INTERVAL $days DAY))
       ORDER BY d.id LIMIT 200"
    );
    $st->execute([$marker]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Records that a weekly watch ran for a domain today, keeping only the latest marker row.
 *
 * @param int    $id      the domain that was scanned
 * @param string $marker  the watch's marker name, 'ct_scanned' or 'lookalikes_scanned'
 *
 * @writes domain_audit - the marker row domainWatchDue() reads
 */
function domainWatchMark(PDO $conn, int $id, string $marker): void
{
    // Keep only the latest marker row per domain, so the history is not a
    // column of weekly "scanned" lines.
    $conn->prepare("DELETE FROM domain_audit WHERE domain_id = ? AND field_name = ?")->execute([$id, $marker]);
    DomainsService::audit($conn, $id, null, $marker, null, gmdate('Y-m-d'), 'check');
}

/**
 * Records the end of a Domains run in the job history (#182): failed lookups or failed
 * alert emails make it PARTIAL. Never throws.
 *
 * @param ?int $runId  from JobRun::start()
 * @param array $r  what domainScheduledRun() returned
 */
function domainJobFinish(PDO $conn, ?int $runId, array $r): void
{
    require_once __DIR__ . '/../job_runs.php';
    $a = $r['alerts'] ?? [];
    $problems = (int)($r['lookup_errors'] ?? 0) + (int)($a['failed'] ?? 0);
    $work = (int)($r['lookups'] ?? 0) + (int)($r['checks'] ?? 0) + (int)($a['emails'] ?? 0);
    JobRun::finish($conn, $runId, $problems === 0 ? 'ok' : ($work > 0 ? 'partial' : 'failed'),
        ['lookups' => (int)($r['lookups'] ?? 0), 'lookup_errors' => (int)($r['lookup_errors'] ?? 0), 'checks' => (int)($r['checks'] ?? 0),
         'emails' => (int)($a['emails'] ?? 0), 'emails_failed' => (int)($a['failed'] ?? 0)],
        sprintf('%d looked up (%d failed), %d checked, %d e-mails (%d failed).', $r['lookups'] ?? 0, $r['lookup_errors'] ?? 0,
            $r['checks'] ?? 0, $a['emails'] ?? 0, $a['failed'] ?? 0));
}

/**
 * The page-load run. Throttled to once an hour, short, and silent: it runs
 * inside a request somebody may be waiting on.
 */
function domainOpportunisticRun(PDO $conn): ?array
{
    try {
        if (domainSetting($conn, 'domain_opportunistic') !== '1') return null;
        $last = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'domain_opportunistic_last'")->fetchColumn();
        if ($last && (time() - (int)$last) < 3600) return null;
        domainSettingWrite($conn, 'domain_opportunistic_last', (string)time());   // claim first: two tabs, one run
        // #182: recorded as a PAGE run. It does the work, but never keeps the job's
        // schedule looking healthy - only a cron or token-address run does that.
        require_once __DIR__ . '/../job_runs.php';
        $runId = JobRun::start($conn, 'domains', 'page', isset($_SESSION['analyst_id']) ? (int)$_SESSION['analyst_id'] : null);
        try {
            $r = domainScheduledRun($conn, 25, false);
        } catch (Throwable $e) {
            JobRun::fail($conn, $runId, $e);
            throw $e;
        }
        domainJobFinish($conn, $runId, $r);
        return $r;
    } catch (Throwable $e) {
        error_log('domains opportunistic run: ' . $e->getMessage());
        return null;
    }
}
