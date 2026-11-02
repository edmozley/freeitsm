<?php
/**
 * Domains → Settings, behind the SAME capability as each tab.
 *
 * GET                       every setting with its default, the last run, the
 *                           cron URL, and which tabs this analyst may write
 * POST {action:'save', tab, settings:{key: value}}
 *                           validated per key; every key must belong to `tab`,
 *                           and `tab`'s capability is checked — so one request
 *                           cannot smuggle an alerts key past a monitoring grant
 * POST {action:'preview'}   the alert run as a dry run: who WOULD be e-mailed
 * POST {action:'run_now'}   one scheduled run, now (alerts or monitoring cap)
 * POST {action:'sync_calendar'}
 */
require_once __DIR__ . '/../../includes/domains/api_bootstrap.php';
require_once __DIR__ . '/../../includes/domains/scheduler.php';

$tabCaps = [
    'alerts'     => capKey(Cap::DOMAINS_ALERTS),
    'monitoring' => capKey(Cap::DOMAINS_MONITORING),
    'auth-codes' => capKey(Cap::DOMAINS_AUTH_CODES),
    'service-status' => capKey(Cap::DOMAINS_SERVICE_STATUS),
    'internal'   => capKey(Cap::DOMAINS_INTERNAL),   // GH #203 slice 2
];

domainApiRun(function () use ($conn, $analystId, $tabCaps) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $values = domainSettings($conn, true);
        $defs = [];
        foreach (domainSettingDefinitions() as $k => $d) $defs[$k] = ['default' => $d[0], 'tab' => $d[2]];
        $canWrite = [];
        foreach ($tabCaps as $tab => $cap) $canWrite[$tab] = domainHasCap($conn, $analystId, Cap::from($cap));

        $cronUrl = null;
        if ($canWrite['monitoring'] || $canWrite['alerts']) {
            try {
                $tok = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'domain_cron_token'")->fetchColumn();
                if ($tok) {
                    require_once __DIR__ . '/../../includes/public_url.php';
                    $cronUrl = rtrim(publicBaseUrl($conn), '/') . '/cron/domains.php?token=' . rawurlencode((string)decryptValue($tok));
                }
            } catch (Throwable $e) {}
        }
        // Only monitored domains can be waiting for a lookup or a check — a
        // domain with monitoring off (demo data, a record-only domain) never will.
        $counts = $conn->query("SELECT COUNT(*) AS total,
                                       COALESCE(SUM(monitoring_enabled = 1 AND last_lookup_datetime IS NULL), 0) AS never_looked_up,
                                       COALESCE(SUM(monitoring_enabled = 1 AND last_check_datetime IS NULL), 0) AS never_checked
                                  FROM domains")->fetch(PDO::FETCH_ASSOC);
        domainApiOk([
            'settings'   => $values,
            'definitions'=> $defs,
            'can_write'  => $canWrite,
            'last_run'   => $values[DOMAIN_SETTING_LAST_RUN] ?: null,
            'cron_url'   => $cronUrl,
            'cron_path'  => realpath(__DIR__ . '/../../cron/domains.php'),
            'counts'     => array_map('intval', $counts ?: []),
            'windows'    => PHP_OS_FAMILY === 'Windows',
            'has_intl'   => function_exists('idn_to_ascii'),
        ]);
    }

    $in = domainApiBody();
    $action = (string)($in['action'] ?? 'save');

    if ($action === 'preview') {
        domainRequireCap($conn, Cap::DOMAINS_ALERTS);
        domainApiOk(['preview' => domainAlertsRun($conn, true)]);
    }
    if ($action === 'run_now') {
        if (!domainHasCap($conn, $analystId, Cap::DOMAINS_ALERTS) && !domainHasCap($conn, $analystId, Cap::DOMAINS_MONITORING)) {
            domainApiFail('You do not have permission to run the scheduled work.', 403);
        }
        set_time_limit(180);
        // #182: a MANUAL run - recorded, but it never makes the schedule look alive.
        require_once __DIR__ . '/../../includes/job_runs.php';
        if (!JobRun::tryLock($conn, 'domains')) {   // #233: never alongside a scheduled run
            domainApiFail('A scheduled run is going. Try again in a few minutes.', 409);
        }
        $runId = JobRun::start($conn, 'domains', 'manual', $analystId);
        try {
            $r = domainScheduledRun($conn, 90, false);
        } catch (Throwable $e) {
            JobRun::fail($conn, $runId, $e);
            throw $e;
        }
        domainJobFinish($conn, $runId, $r);
        domainApiOk(['run' => $r]);
    }
    if ($action === 'sync_calendar') {
        domainRequireCap($conn, Cap::DOMAINS_ALERTS);
        domainApiOk(['calendar' => domainSyncExpiryCalendar($conn)]);
    }
    if ($action !== 'save') domainApiFail('Unknown action.');

    $tab = (string)($in['tab'] ?? '');
    if (!isset($tabCaps[$tab])) domainApiFail('Unknown settings tab.');
    domainRequireCap($conn, Cap::from($tabCaps[$tab]));

    $defs = domainSettingDefinitions();
    $clean = [];
    foreach ((array)($in['settings'] ?? []) as $key => $value) {
        if (!isset($defs[$key]) || $defs[$key][2] !== $tab) domainApiFail("That setting does not belong on this tab: $key");
        try { $clean[$key] = domainSettingValidate($key, $value); }
        catch (InvalidArgumentException $e) { domainApiFail($e->getMessage()); }
    }
    foreach ($clean as $k => $v) domainSettingWrite($conn, $k, $v);

    // Where renewals show changed → the calendar follows at once.
    if (isset($clean['domain_expiry_surface']) || isset($clean['domain_cert_surface'])) domainSyncExpiryCalendar($conn);
    domainApiOk(['settings' => domainSettings($conn, true)]);
});
