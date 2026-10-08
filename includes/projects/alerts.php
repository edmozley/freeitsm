<?php
/**
 * Projects - events and alerts (3.2.0): what a project tells Workflows,
 * webhooks and the notification bell.
 *
 * Every event goes through WorkflowEngine::dispatch(), the one funnel: a
 * workflow can act on it, a webhook can carry it ("post to Teams when a project
 * goes red"), and includes/notifications_router.php turns the ones listed in
 * NotificationsService::types() into a bell for the PROJECT MANAGER.
 *
 *   project.created / .updated / .deleted   the write paths (ProjectsService)
 *   project.stage_closed     a stage, phase or sprint closed - by its gate or by hand
 *   project.health_changed   the health a project SHOWS moved (auto or by hand)
 *   project.tolerance_breached  a time or risk tolerance was exceeded
 *   project.stage_due        a stage ends in 7 days, then tomorrow (time-based)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WORKED OUT, NEVER STORED - SO SOMETHING HAS TO LOOK
 *
 * Health and exceptions are computed on read (includes/projects/read.php). A
 * task going overdue overnight turns a project amber without anybody saving
 * anything, so there is no write path to dispatch from. projectAlertsScan()
 * looks, and compares with what it saw last time:
 *
 *   projects.alert_health      the health last seen
 *   projects.alert_exceptions  the breaches last seen ("risk,time")
 *
 * 🔑 An event fires on a CHANGE only, and a breach re-arms once it clears - a
 * project that stays red is not news every hour. The write is compare-and-set
 * (WHERE alert_health <=> what we read), so two overlapping scans cannot both
 * fire. A NULL alert_health means never scanned: that first scan only records,
 * so upgrading does not ring every project manager's bell at once.
 *
 * Stage ends use the workflow_scheduled_emissions ledger (the contract-expiry
 * one) with the end date as the fingerprint, so moving a stage's end re-arms
 * it. Unlike workflowEmitOnce() it records even when no workflow listens: the
 * bell is always listening.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHO RUNS THE SCAN
 *
 *   cron/workflow_scheduled.php   the reliable path (workflowScheduledRun)
 *   api/projects/list.php         opening the portfolio, at most every 15 minutes,
 *                                 for installs with no cron
 *   ProjectsService writes        one project, straight after a change to it
 *
 * TRAP: the bell never tells you about your OWN action, and decides "own" from
 * the session. A scan started by somebody opening the portfolio is not their
 * action - if it ran as them, a project manager who opens Projects would never
 * hear that their project went red. Scans run through projectAlertsAsSystem(),
 * which hides the session's analyst while it dispatches. A change somebody
 * makes by hand (setting health to red) runs as them, so they are not told
 * about what they just did.
 */

require_once __DIR__ . '/read.php';
require_once __DIR__ . '/../../workflow/includes/engine.php';

/** The project as every project event carries it. */
function projectEventPayload(array $p): array
{
    return [
        'id'               => (int)$p['id'],
        'code'             => projectCode((int)$p['id']),
        'name'             => $p['name'],
        'status'           => $p['status'],
        'health'           => $p['shown_health'] ?? null,
        'methodology'      => $p['methodology'],
        'owner_analyst_id' => $p['owner_analyst_id'] !== null ? (int)$p['owner_analyst_id'] : null,
        'owner_name'       => $p['owner_name'] ?? null,
        'company_id'       => $p['tenant_id'] !== null ? (int)$p['tenant_id'] : null,
        'start_date'       => $p['start_date'],
        'target_end_date'  => $p['target_end_date'],
    ];
}

/** Dispatch, never throwing: an event must not break the change that caused it. */
function projectDispatch(string $event, array $payload): void
{
    try {
        WorkflowEngine::dispatch($event, $payload);
    } catch (Throwable $e) {
        error_log('projects dispatch ' . $event . ': ' . $e->getMessage());
    }
}

/** Run $fn with nobody signed in, as cron does - see the TRAP above. */
function projectAlertsAsSystem(callable $fn)
{
    if (!isset($_SESSION) || !is_array($_SESSION)) return $fn();
    $saved = [];
    foreach (['analyst_id', 'analyst_name'] as $k) {
        if (array_key_exists($k, $_SESSION)) { $saved[$k] = $_SESSION[$k]; unset($_SESSION[$k]); }
    }
    try {
        return $fn();
    } finally {
        foreach ($saved as $k => $v) $_SESSION[$k] = $v;
    }
}

/** Live projects decorated with their health and exceptions, plus what the scan saw last. */
function projectAlertRows(PDO $conn, ?int $projectId = null): array
{
    $where = "p.status IN ('proposed', 'active')";
    $args = [];
    if ($projectId !== null) { $where = 'p.id = ?'; $args[] = $projectId; }
    $st = $conn->prepare(
        "SELECT p.id, p.tenant_id, p.name, p.methodology, p.status, p.health, p.owner_analyst_id, a.full_name AS owner_name,
                p.start_date, p.target_end_date, p.tailoring, p.alert_health, p.alert_exceptions,
                " . projectExceptionColumns($conn) . "
           FROM projects p
      LEFT JOIN analysts a ON a.id = p.owner_analyst_id
          WHERE $where");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $stats = projectTaskStats($conn, array_column($rows, 'id'));
    $cfg = projectHealthConfig($conn);
    foreach ($rows as &$r) $r = projectDecorate($r, $stats[(int)$r['id']] ?? [], $cfg);
    unset($r);
    return $rows;
}

/**
 * Compare each live project with what was seen last, and fire what changed.
 * $projectId = one project (after a change to it); null = all of them.
 *
 * @return array{health:int, breaches:int, stages_due:int}
 */
function projectAlertsScan(PDO $conn, ?int $projectId = null): array
{
    $out = ['health' => 0, 'breaches' => 0, 'stages_due' => 0];
    try {
        $conn->query("SELECT alert_health FROM projects LIMIT 0");   // before Database Verification: nothing to compare with
    } catch (Throwable $e) {
        return $out;
    }

    foreach (projectAlertRows($conn, $projectId) as $p) {
        $live = in_array($p['status'], ['proposed', 'active'], true);
        $health = $live ? (string)($p['shown_health'] ?? '') : '';
        $kinds = $live ? array_values(array_unique(array_column($p['exceptions'], 'kind'))) : [];
        sort($kinds);
        $seenHealth = $p['alert_health'];
        $seenKinds = ($p['alert_exceptions'] ?? '') === '' ? [] : explode(',', $p['alert_exceptions']);

        // Compare-and-set: only the scan whose write lands may fire.
        $cas = $conn->prepare("UPDATE projects SET alert_health = ?, alert_exceptions = ? WHERE id = ? AND alert_health <=> ? AND alert_exceptions <=> ?");
        $cas->execute([$health, implode(',', $kinds), (int)$p['id'], $seenHealth, $p['alert_exceptions']]);
        if ($cas->rowCount() !== 1 || $seenHealth === null) continue;   // unchanged, lost the race, or the first look

        $project = projectEventPayload($p);
        if ($health !== $seenHealth && $health !== '' && $seenHealth !== '') {
            projectDispatch('project.health_changed', [
                'project' => $project,
                'from'    => $seenHealth,
                'to'      => $health,
                'manual'  => $p['health'] !== 'auto' ? 1 : 0,
            ]);
            $out['health']++;
        }
        foreach ($p['exceptions'] as $ex) {
            if (in_array($ex['kind'], $seenKinds, true)) continue;
            $seenKinds[] = $ex['kind'];   // one event per kind, even if listed twice
            projectDispatch('project.tolerance_breached', [
                'project'   => $project,
                'kind'      => $ex['kind'],                // time | stage_time | risk
                'late_days' => $ex['late'] ?? null,
                'score'     => $ex['score'] ?? null,
                'allowed'   => $ex['allowed'],
            ]);
            $out['breaches']++;
        }
    }

    $out['stages_due'] = projectAlertsStagesDue($conn, $projectId);
    return $out;
}

/** The two warnings before a stage ends. */
function projectStageDueWindows(): array
{
    return [7, 1];
}

/**
 * Stages ending soon -> project.stage_due, once per window per end date. Only
 * the nearest window fires: a stage first seen a day out gets "tomorrow", not
 * a week's notice as well.
 */
function projectAlertsStagesDue(PDO $conn, ?int $projectId = null): int
{
    $windows = projectStageDueWindows();
    $today = gmdate('Y-m-d');
    $sql = "SELECT s.id AS stage_id, s.name AS stage_name, s.kind, s.end_date, s.status AS stage_status,
                   p.id, p.tenant_id, p.name, p.methodology, p.status, p.health, p.owner_analyst_id, a.full_name AS owner_name,
                   p.start_date, p.target_end_date,
                   DATEDIFF(s.end_date, ?) AS days_remaining
              FROM project_stages s
              JOIN projects p ON p.id = s.project_id
         LEFT JOIN analysts a ON a.id = p.owner_analyst_id
             WHERE s.status <> 'closed' AND s.end_date IS NOT NULL
               AND s.end_date >= ? AND s.end_date <= DATE_ADD(?, INTERVAL ? DAY)
               AND p.status IN ('proposed', 'active')";
    $args = [$today, $today, $today, max($windows)];
    if ($projectId !== null) { $sql .= ' AND p.id = ?'; $args[] = $projectId; }
    $st = $conn->prepare($sql);
    $st->execute($args);

    $claim = $conn->prepare("INSERT IGNORE INTO workflow_scheduled_emissions (trigger_event, entity_key, fingerprint, emitted_datetime) VALUES ('project.stage_due', ?, ?, UTC_TIMESTAMP())");
    $fired = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $days = (int)$r['days_remaining'];
        $window = null;
        foreach ($windows as $w) if ($days <= $w) $window = $w;   // ascending scan leaves the smallest that applies
        if ($window === null) continue;
        try {
            $claim->execute(['project_stage:' . (int)$r['stage_id'] . ':' . $window, (string)$r['end_date']]);
        } catch (Throwable $e) {
            return $fired;   // no ledger yet: firing without one would repeat every run
        }
        if ($claim->rowCount() !== 1) continue;
        projectDispatch('project.stage_due', [
            'project' => projectEventPayload($r),
            'stage'   => ['id' => (int)$r['stage_id'], 'name' => $r['stage_name'], 'kind' => $r['kind'], 'end_date' => $r['end_date'], 'status' => $r['stage_status']],
            'days_remaining' => $days,
            'window_days'    => $window,
        ]);
        $fired++;
    }
    return $fired;
}

/** The whole scan as nobody - what cron and the portfolio fallback call. */
function projectAlertsRun(PDO $conn): array
{
    return projectAlertsAsSystem(fn() => projectAlertsScan($conn));
}

/**
 * For installs with no cron: opening the portfolio runs the scan, at most every
 * 15 minutes. Never throws; the portfolio must load whatever happens here.
 */
function projectAlertsOpportunistic(PDO $conn): void
{
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'project_alerts_last_run'");
        $st->execute();
        $last = (string)$st->fetchColumn();
        if ($last !== '' && strtotime($last . ' UTC') > time() - 900) return;
        $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('project_alerts_last_run', ?)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([gmdate('Y-m-d H:i:s')]);
        projectAlertsRun($conn);
    } catch (Throwable $e) {
        error_log('projects alerts: ' . $e->getMessage());
    }
}
