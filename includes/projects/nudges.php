<?php
/**
 * Projects - the overdue digest and stalled-approval nudges (3.3.0). Called by
 * projectAlertsScan() (includes/projects/alerts.php), so cron or the portfolio
 * fallback runs them; both use the workflow_scheduled_emissions ledger so a
 * period or a nudge fires once, however often the scan runs.
 *
 *   project.tasks_overdue     a digest to the PROJECT MANAGER of the project's
 *                             overdue tasks - project_overdue_digest: weekly (the
 *                             first scan of each ISO week, so Monday morning with
 *                             cron), daily, or off. Nothing overdue, nothing sent.
 *   project.approval_stalled  something has waited project_nudge_days days for a
 *                             decision (0 = off), and again every as many days:
 *                               proposal       a proposal pending approval -> its approvers
 *                               change_request a change request still open -> whoever may decide it
 *                               signoff        a gate sign-off nobody has given -> the person named
 *                               report         a report still a draft -> the project manager
 *                             Only things waiting 45 days or less: on the day this
 *                             arrives, months-old leftovers do not all ring at once.
 *
 * Both are nobody's action, so they go out as the system (see the TRAP in
 * alerts.php), and both name their audience in notify_ids.
 */

require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/settings.php';

const PROJECT_NUDGE_MAX_DAYS = 45;

/** The overdue-task digests. $projectId = one project; null = every live one. */
function projectAlertsOverdueDigest(PDO $conn, ?int $projectId = null): int
{
    $mode = projectSetting($conn, 'project_overdue_digest');
    if ($mode !== 'daily' && $mode !== 'weekly') return 0;
    $period = $mode === 'daily' ? gmdate('Y-m-d') : gmdate('o-\WW');
    // Contractors (3.3.0): a supplier's late work says whose it is.
    require_once __DIR__ . '/../task_contractors.php';
    $ctr = tasksContractorReady($conn) ? "(SELECT " . tasksSupplierNameSql('sp') . " FROM suppliers sp WHERE sp.id = t.assigned_supplier_id)" : 'NULL';
    $sql = "SELECT t.project_id, t.id, t.title, t.due_date, an.full_name AS assignee_name, $ctr AS contractor_name
              FROM tasks t
              JOIN projects p ON p.id = t.project_id
         LEFT JOIN task_statuses ts ON ts.id = t.status_id
         LEFT JOIN analysts an ON an.id = t.assigned_analyst_id
             WHERE p.status = 'active' AND p.owner_analyst_id IS NOT NULL AND t.parent_task_id IS NULL
               AND COALESCE(ts.is_closed, 0) = 0 AND t.due_date IS NOT NULL AND t.due_date < UTC_DATE()";
    $args = [];
    if ($projectId !== null) { $sql .= ' AND p.id = ?'; $args[] = $projectId; }
    $st = $conn->prepare($sql . ' ORDER BY t.due_date, t.id');
    $st->execute($args);
    $byProject = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) $byProject[(int)$t['project_id']][] = $t;
    if (!$byProject) return 0;

    $claim = $conn->prepare("INSERT IGNORE INTO workflow_scheduled_emissions (trigger_event, entity_key, fingerprint, emitted_datetime) VALUES ('project.tasks_overdue', ?, ?, UTC_TIMESTAMP())");
    $fired = 0;
    foreach (projectNudgeProjects($conn, array_keys($byProject)) as $pid => $p) {
        try {
            $claim->execute(['project_overdue:' . $pid, $mode . ':' . $period]);
        } catch (Throwable $e) {
            return $fired;   // no ledger: firing without one would repeat every run
        }
        if ($claim->rowCount() !== 1) continue;
        $tasks = $byProject[$pid];
        $today = strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC');
        projectAlertsAsSystem(fn() => projectDispatch('project.tasks_overdue', [
            'project' => projectEventPayload($p),
            'count'   => count($tasks),
            'period'  => $mode,
            // The oldest ten - the whole list is one click away on the Plan.
            'tasks'   => array_map(fn($t) => ['id' => (int)$t['id'], 'title' => $t['title'], 'due_date' => $t['due_date'], 'assignee_name' => $t['assignee_name'], 'contractor_name' => $t['contractor_name'],
                                              'days_late' => (int)round(($today - strtotime($t['due_date'] . ' 00:00:00 UTC')) / 86400)], array_slice($tasks, 0, 10)),
            'notify_ids' => [(int)$p['owner_analyst_id']],
        ]));
        $fired++;
    }
    return $fired;
}

/** The nudges. $projectId = one project; null = all of them. */
function projectAlertsStalled(PDO $conn, ?int $projectId = null): int
{
    $every = (int)projectSetting($conn, 'project_nudge_days');
    if ($every <= 0) return 0;
    $one = $projectId !== null ? ' AND p.id = ' . (int)$projectId : '';
    $max = PROJECT_NUDGE_MAX_DAYS;
    $waiting = [];   // [kind, id, project_id, title, since, notify_ids]

    // Proposals waiting for approval (intake.php) - to the approvers.
    try {
        require_once __DIR__ . '/intake.php';
        if (projectIntakeReady($conn)) {
            $rows = $conn->query("SELECT p.id, p.name, p.created_datetime AS since FROM projects p
                                   WHERE p.approval_status = 'pending' AND p.status = 'proposed'
                                     AND p.created_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL $max DAY)$one")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) $approvers = projectProposalApprovers($conn);
            foreach ($rows as $r) $waiting[] = ['proposal', (int)$r['id'], (int)$r['id'], $r['name'], $r['since'], $approvers];
        }
    } catch (Throwable $e) { /* before Verification */ }

    // Change requests not decided (control.php) - to whoever may decide them.
    try {
        require_once __DIR__ . '/control.php';
        if (projectControlReady($conn)) {
            $rows = $conn->query("SELECT c.id, c.project_id, c.number, c.title, c.raised_by_id, c.raised_datetime AS since FROM project_change_requests c
                                    JOIN projects p ON p.id = c.project_id
                                   WHERE c.status = 'proposed' AND p.status IN ('proposed', 'active', 'on_hold')
                                     AND c.raised_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL $max DAY)$one")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $p = projectNudgeProjects($conn, [(int)$r['project_id']])[(int)$r['project_id']] ?? null;
                if (!$p) continue;
                $waiting[] = ['change_request', (int)$r['id'], (int)$r['project_id'], 'CR-' . (int)$r['number'] . ': ' . $r['title'], $r['since'],
                              projectNudgeDeciders($conn, $p, $r['raised_by_id'] !== null ? (int)$r['raised_by_id'] : null)];
            }
        }
    } catch (Throwable $e) { /* before Verification */ }

    // Gate sign-offs nobody has given (gatecheck.php) - to the person named.
    try {
        $rows = $conn->query("SELECT i.id, i.project_id, i.title, i.analyst_id, i.created_datetime AS since, s.name AS stage_name FROM project_gate_items i
                                JOIN project_stages s ON s.id = i.stage_id
                                JOIN projects p ON p.id = i.project_id
                               WHERE i.kind = 'signoff' AND i.done_datetime IS NULL AND i.analyst_id IS NOT NULL
                                 AND s.status <> 'closed' AND p.status IN ('proposed', 'active', 'on_hold')
                                 AND i.created_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL $max DAY)$one")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $waiting[] = ['signoff', (int)$r['id'], (int)$r['project_id'], $r['title'] . ' (' . $r['stage_name'] . ')', $r['since'], [(int)$r['analyst_id']]];
    } catch (Throwable $e) { /* before Verification */ }

    // Reports still drafts (reports) - to the project manager, who approves them.
    try {
        $rows = $conn->query("SELECT r.id, r.project_id, r.title, r.created_datetime AS since, p.owner_analyst_id FROM project_reports r
                                JOIN projects p ON p.id = r.project_id
                               WHERE r.status = 'draft' AND p.owner_analyst_id IS NOT NULL AND p.status IN ('proposed', 'active', 'on_hold')
                                 AND r.created_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL $max DAY)$one")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $waiting[] = ['report', (int)$r['id'], (int)$r['project_id'], $r['title'], $r['since'], [(int)$r['owner_analyst_id']]];
    } catch (Throwable $e) { /* before Verification */ }

    if (!$waiting) return 0;
    $projects = projectNudgeProjects($conn, array_unique(array_column($waiting, 2)));
    $claim = $conn->prepare("INSERT IGNORE INTO workflow_scheduled_emissions (trigger_event, entity_key, fingerprint, emitted_datetime) VALUES ('project.approval_stalled', ?, ?, UTC_TIMESTAMP())");
    $fired = 0;
    foreach ($waiting as [$kind, $id, $pid, $title, $since, $notify]) {
        $days = (int)floor((time() - strtotime($since . ' UTC')) / 86400);
        // Fires on day N, 2N, 3N... - the fingerprint is how many nudges are due.
        $round = intdiv($days, $every);
        if ($round < 1 || !isset($projects[$pid])) continue;
        $notify = array_values(array_unique(array_filter(array_map('intval', $notify))));
        if (!$notify) continue;
        try {
            $claim->execute(['project_stall:' . $kind . ':' . $id, (string)$round]);
        } catch (Throwable $e) {
            return $fired;
        }
        if ($claim->rowCount() !== 1) continue;
        projectAlertsAsSystem(fn() => projectDispatch('project.approval_stalled', [
            'project'      => projectEventPayload($projects[$pid]),
            'kind'         => $kind,          // proposal | change_request | signoff | report
            'item'         => ['id' => $id, 'title' => $title],
            'waiting_days' => $days,
            'since'        => $since,
            'notify_ids'   => $notify,
        ]));
        $fired++;
    }
    return $fired;
}

/** Project rows shaped for projectEventPayload(), by id. */
function projectNudgeProjects(PDO $conn, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $out = [];
    foreach ($conn->query("SELECT p.*, a.full_name AS owner_name FROM projects p LEFT JOIN analysts a ON a.id = p.owner_analyst_id WHERE p.id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $p['shown_health'] = null;
        $out[(int)$p['id']] = $p;
    }
    return $out;
}

/** Everybody who may decide a change request on this project - the setting's rule, applied to each active analyst. */
function projectNudgeDeciders(PDO $conn, array $project, ?int $raisedBy): array
{
    $out = [];
    foreach ($conn->query("SELECT id FROM analysts WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $a) {
        if (projectCanDecideChange($conn, (int)$a, $project, $raisedBy)) $out[] = (int)$a;
    }
    return $out;
}
