<?php
/**
 * Shared Watchtower Dashboard Queries
 * Returns unified attention summary data from all modules
 */

require_once __DIR__ . '/tenancy.php';   // knowledgeTenantFilter() for the Knowledge card

/**
 * $analystId is optional and only used to scope the Knowledge card to the
 * company the analyst has switched to. Omitted (or 0) = unscoped, which is the
 * behaviour every other card on this dashboard still has.
 */
function getWatchtowerData($conn, $analystId = 0) {
    $today = date('Y-m-d');

    // -- Morning Checks --

    $mcTotal = (int)$conn->query("SELECT COUNT(*) FROM morningChecks_Checks WHERE IsActive = 1")->fetchColumn();

    $mcDoneStmt = $conn->prepare(
        "SELECT COUNT(DISTINCT r.CheckID)
         FROM morningChecks_Results r
         JOIN morningChecks_Checks c ON r.CheckID = c.CheckID
         WHERE c.IsActive = 1 AND DATE(r.CheckDate) = ?"
    );
    $mcDoneStmt->execute([$today]);
    $mcDone = (int)$mcDoneStmt->fetchColumn();

    $mcStatusStmt = $conn->prepare(
        "SELECT r.Status, COUNT(*) AS cnt
         FROM morningChecks_Results r
         WHERE DATE(r.CheckDate) = ?
         GROUP BY r.Status"
    );
    $mcStatusStmt->execute([$today]);
    $mcStatuses = [];
    while ($row = $mcStatusStmt->fetch(PDO::FETCH_ASSOC)) {
        $mcStatuses[$row['Status']] = (int)$row['cnt'];
    }

    $morningChecks = [
        'total_checks'    => $mcTotal,
        'completed_today' => $mcDone,
        'statuses'        => $mcStatuses,
        'not_started'     => $mcDone === 0 && $mcTotal > 0
    ];

    // -- Tickets --

    $tkStatusStmt = $conn->query(
        "SELECT ts.name AS status, COUNT(*) AS cnt
         FROM tickets t
         JOIN ticket_statuses ts ON ts.id = t.status_id
         WHERE ts.is_closed = 0
         GROUP BY ts.name"
    );
    $tkStatuses = [];
    while ($row = $tkStatusStmt->fetch(PDO::FETCH_ASSOC)) {
        $tkStatuses[$row['status']] = (int)$row['cnt'];
    }

    $tkUrgent = (int)$conn->query(
        "SELECT COUNT(*)
         FROM tickets t
         JOIN ticket_priorities tp ON tp.id = t.priority_id
         JOIN ticket_statuses   ts ON ts.id = t.status_id
         WHERE tp.name IN ('Urgent','High','Critical') AND ts.is_closed = 0"
    )->fetchColumn();

    $tkUnassigned = (int)$conn->query(
        "SELECT COUNT(*)
         FROM tickets t
         JOIN ticket_statuses ts ON ts.id = t.status_id
         WHERE t.assigned_analyst_id IS NULL AND ts.is_closed = 0"
    )->fetchColumn();

    // Paused too long: tickets currently in a status flagged pauses_sla
    // (e.g. On Hold, Awaiting Response) where the last status change was
    // longer ago than the configured threshold. Surfaces tickets being
    // parked in a paused status to escape the SLA clock. Falls back to
    // tickets.created_datetime if no status-change audit row exists
    // (ticket has never moved off its default status).
    $pausedThresholdStmt = $conn->prepare(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'watchtower_paused_too_long_hours' LIMIT 1"
    );
    $pausedThresholdStmt->execute();
    $pausedThresholdHours = (int)($pausedThresholdStmt->fetchColumn() ?: 24);
    if ($pausedThresholdHours < 1) $pausedThresholdHours = 24;

    $tkPausedStmt = $conn->prepare(
        "SELECT COUNT(*)
           FROM tickets t
           JOIN ticket_statuses ts ON ts.id = t.status_id
          WHERE ts.is_closed = 0
            AND ts.pauses_sla = 1
            AND COALESCE(
                (SELECT MAX(a.created_datetime)
                   FROM ticket_audit a
                  WHERE a.ticket_id = t.id AND a.field_name = 'status'),
                t.created_datetime
            ) < DATE_SUB(NOW(), INTERVAL ? HOUR)"
    );
    $tkPausedStmt->execute([$pausedThresholdHours]);
    $tkPausedTooLong = (int)$tkPausedStmt->fetchColumn();

    $tickets = [
        'open'                    => $tkStatuses['Open'] ?? 0,
        'in_progress'             => $tkStatuses['In Progress'] ?? 0,
        'on_hold'                 => $tkStatuses['On Hold'] ?? 0,
        'urgent_high'             => $tkUrgent,
        'unassigned'              => $tkUnassigned,
        'paused_too_long'         => $tkPausedTooLong,
        'paused_threshold_hours'  => $pausedThresholdHours,
    ];

    // -- Changes --
    // changes.status (legacy VARCHAR) was migrated to status_id → change_statuses.
    // Join the lookup and compare by name to preserve the original semantics.

    $chUpcoming = (int)$conn->query(
        "SELECT COUNT(*)
         FROM changes c
         JOIN change_statuses cs ON cs.id = c.status_id
         WHERE c.work_start_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
           AND cs.name NOT IN ('Closed','Cancelled')"
    )->fetchColumn();

    $chUnapproved = (int)$conn->query(
        "SELECT COUNT(*)
         FROM changes c
         JOIN change_statuses cs ON cs.id = c.status_id
         WHERE cs.name IN ('Submitted','Pending Approval')"
    )->fetchColumn();

    $chInProgress = (int)$conn->query(
        "SELECT COUNT(*)
         FROM changes c
         JOIN change_statuses cs ON cs.id = c.status_id
         WHERE cs.name = 'In Progress'
           AND c.work_start_datetime <= NOW()
           AND (c.work_end_datetime >= NOW() OR c.work_end_datetime IS NULL)"
    )->fetchColumn();

    $changes = [
        'upcoming_7d'       => $chUpcoming,
        'unapproved'        => $chUnapproved,
        'in_progress_today' => $chInProgress
    ];

    // -- Calendar --

    $calTodayStmt = $conn->query(
        "SELECT id, title, start_datetime, end_datetime, all_day
         FROM calendar_events
         WHERE DATE(start_datetime) = CURDATE()
            OR (all_day = 1 AND DATE(start_datetime) <= CURDATE() AND (DATE(end_datetime) >= CURDATE() OR end_datetime IS NULL))
         ORDER BY all_day DESC, start_datetime
         LIMIT 10"
    );
    $calTodayEvents = $calTodayStmt->fetchAll(PDO::FETCH_ASSOC);

    $calWeek = (int)$conn->query(
        "SELECT COUNT(*) FROM calendar_events
         WHERE start_datetime BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
    )->fetchColumn();

    $calendar = [
        'today_count'  => count($calTodayEvents),
        'today_events' => $calTodayEvents,
        'week_count'   => $calWeek
    ];

    // -- Service Status --

    $ssDegradedStmt = $conn->query(
        "SELECT ss.id, ss.name, worst.current_status, worst.severity_order
         FROM status_services ss
         JOIN (
            SELECT sis.service_id, il.name AS current_status, il.severity_order
            FROM status_incident_services sis
            JOIN status_incidents si ON sis.incident_id = si.id
            JOIN service_impact_levels il ON il.id = sis.impact_level_id
            LEFT JOIN service_incident_statuses sst ON sst.id = si.status_id
            WHERE (sst.is_resolved = 0 OR sst.id IS NULL)
              AND il.name <> 'Operational'
         ) worst ON worst.service_id = ss.id
         WHERE ss.is_active = 1
         ORDER BY worst.severity_order ASC, ss.display_order, ss.name"
    );
    $ssDegraded = $ssDegradedStmt->fetchAll(PDO::FETCH_ASSOC);

    $ssActiveIncidents = (int)$conn->query(
        "SELECT COUNT(*) FROM status_incidents si
         LEFT JOIN service_incident_statuses sst ON sst.id = si.status_id
         WHERE (sst.is_resolved = 0 OR sst.id IS NULL)"
    )->fetchColumn();

    $serviceStatus = [
        'degraded_services' => $ssDegraded,
        'active_incidents'  => $ssActiveIncidents,
        'all_operational'   => empty($ssDegraded) && $ssActiveIncidents === 0
    ];

    // -- Contracts --

    $ctExp30 = (int)$conn->query(
        "SELECT COUNT(*) FROM contracts
         WHERE is_active = 1 AND contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
    )->fetchColumn();

    $ctExp90 = (int)$conn->query(
        "SELECT COUNT(*) FROM contracts
         WHERE is_active = 1 AND contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)"
    )->fetchColumn();

    $ctNotice = (int)$conn->query(
        "SELECT COUNT(*) FROM contracts
         WHERE is_active = 1 AND notice_date IS NOT NULL
           AND notice_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
    )->fetchColumn();

    $contracts = [
        'expiring_30d'       => $ctExp30,
        'expiring_90d'       => $ctExp90,
        'notice_periods_30d' => $ctNotice
    ];

    // -- Knowledge --

    // Company scope. NOTE: Knowledge is currently the ONLY card here that scopes
    // by company — the rest of this dashboard (tickets included) is install-wide,
    // which is a pre-existing multi-tenancy gap in Watchtower rather than
    // something this introduced. Scoped here anyway: an article carries an owning
    // company now, so surfacing another company's titles on the dashboard would be
    // a hole in Knowledge, whatever the neighbouring cards do.
    $kbTenantSql    = '';
    $kbTenantParams = [];
    if ($analystId > 0 && function_exists('knowledgeTenantFilter')) {
        [$kbTenantSql, $kbTenantParams] = knowledgeTenantFilter($conn, $analystId, 'ka');
    }

    $kbRecentStmt = $conn->prepare(
        "SELECT ka.id, ka.title, ka.created_datetime
         FROM knowledge_articles ka
         WHERE ka.is_published = 1 AND ka.is_archived = 0
           AND ka.created_datetime >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
         . $kbTenantSql . "
         ORDER BY ka.created_datetime DESC
         LIMIT 5"
    );
    $kbRecentStmt->execute($kbTenantParams);
    $kbRecent = $kbRecentStmt->fetchAll(PDO::FETCH_ASSOC);

    $kbOverdueStmt = $conn->prepare(
        "SELECT COUNT(*) FROM knowledge_articles ka
         WHERE ka.is_published = 1 AND ka.is_archived = 0
           AND ka.next_review_date IS NOT NULL AND ka.next_review_date < CURDATE()"
         . $kbTenantSql
    );
    $kbOverdueStmt->execute($kbTenantParams);
    $kbOverdue = (int)$kbOverdueStmt->fetchColumn();

    $knowledge = [
        'recent_articles' => $kbRecent,
        'overdue_reviews' => $kbOverdue
    ];

    // -- Assets --

    $asTotal = (int)$conn->query("SELECT COUNT(*) FROM assets")->fetchColumn();

    $asNotSeen = (int)$conn->query(
        "SELECT COUNT(*) FROM assets
         WHERE last_seen IS NOT NULL AND last_seen < DATE_SUB(NOW(), INTERVAL 7 DAY)"
    )->fetchColumn();

    // Warranty expiries — only surfaced here when the asset_warranty_surface
    // setting includes the dashboard. Counts assets already expired or expiring
    // within asset_warranty_days (default 30). Defensive: warranty_expiry may
    // not exist until a DB verification has run.
    $wtSurface = 'dashboard';
    $wtDays = 30;
    try {
        $set = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('asset_warranty_surface','asset_warranty_days')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($set['asset_warranty_surface'])) $wtSurface = $set['asset_warranty_surface'];
        if (!empty($set['asset_warranty_days']) && (int)$set['asset_warranty_days'] > 0) $wtDays = (int)$set['asset_warranty_days'];
    } catch (Exception $e) { /* defaults */ }
    $wtShowWarranty = in_array($wtSurface, ['dashboard', 'both'], true);
    $asWarranty = 0;
    if ($wtShowWarranty) {
        try {
            $w = $conn->prepare("SELECT COUNT(*) FROM assets WHERE warranty_expiry IS NOT NULL AND warranty_expiry <= DATE_ADD(CURDATE(), INTERVAL ? DAY)");
            $w->execute([$wtDays]);
            $asWarranty = (int)$w->fetchColumn();
        } catch (Exception $e) { $wtShowWarranty = false; }
    }

    $assets = [
        'total'         => $asTotal,
        'not_seen_7d'   => $asNotSeen,
        'warranty_soon' => $asWarranty,
        'warranty_days' => $wtDays,
        'warranty_show' => $wtShowWarranty
    ];

    // -- Tasks --

    $taskOverdue = (int)$conn->query(
        "SELECT COUNT(*) FROM tasks t
         LEFT JOIN task_statuses ts ON ts.id = t.status_id
         WHERE t.due_date < CURDATE()
           AND (ts.is_closed = 0 OR ts.id IS NULL)
           AND t.parent_task_id IS NULL"
    )->fetchColumn();

    $taskDueToday = (int)$conn->query(
        "SELECT COUNT(*) FROM tasks t
         LEFT JOIN task_statuses ts ON ts.id = t.status_id
         WHERE t.due_date = CURDATE()
           AND (ts.is_closed = 0 OR ts.id IS NULL)
           AND t.parent_task_id IS NULL"
    )->fetchColumn();

    $taskInProgress = (int)$conn->query(
        "SELECT COUNT(*) FROM tasks t
         JOIN task_statuses ts ON ts.id = t.status_id
         WHERE ts.name = 'In Progress'
           AND t.parent_task_id IS NULL"
    )->fetchColumn();

    $taskTodo = (int)$conn->query(
        "SELECT COUNT(*) FROM tasks t
         JOIN task_statuses ts ON ts.id = t.status_id
         WHERE ts.name = 'To Do'
           AND t.parent_task_id IS NULL"
    )->fetchColumn();

    $tasksWt = [
        'overdue'     => $taskOverdue,
        'due_today'   => $taskDueToday,
        'in_progress' => $taskInProgress,
        'todo'        => $taskTodo
    ];

    // -- Workflows --
    // A failing workflow is silent by design: the engine swallows its own errors
    // so a broken rule can never break the ticket save that triggered it. Which
    // is correct — and means nothing tells you it's broken. This card is that
    // "something".
    //
    // Real runs only (is_dry_run = 0): a dry run is a person testing, and its
    // failures are expected, not an incident.
    $wf = [
        'failed_24h'      => 0,
        'aborted_24h'     => 0,
        'dead_webhooks'   => 0,
        'worst'           => [],   // the workflows failing most, so the card names names
        'all_clear'       => true,
        'available'       => false,
    ];
    try {
        $wfFailed = $conn->query(
            "SELECT COUNT(*) FROM workflow_executions
              WHERE status = 'failed' AND is_dry_run = 0
                AND started_datetime >= UTC_TIMESTAMP() - INTERVAL 24 HOUR"
        )->fetchColumn();
        $wfAborted = $conn->query(
            "SELECT COUNT(*) FROM workflow_executions
              WHERE status = 'aborted' AND is_dry_run = 0
                AND started_datetime >= UTC_TIMESTAMP() - INTERVAL 24 HOUR"
        )->fetchColumn();

        // Which workflows, and what they're actually saying — an error message is
        // far more useful on the dashboard than a bare count.
        $wfWorst = $conn->query(
            "SELECT COALESCE(w.name, e.workflow_name, '(deleted workflow)') AS name,
                    COUNT(*) AS failures,
                    SUBSTRING_INDEX(GROUP_CONCAT(e.error_message ORDER BY e.id DESC SEPARATOR '||'), '||', 1) AS last_error
               FROM workflow_executions e
               LEFT JOIN workflows w ON w.id = e.workflow_id
              WHERE e.status IN ('failed','aborted') AND e.is_dry_run = 0
                AND e.started_datetime >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
              GROUP BY name
              ORDER BY failures DESC
              LIMIT 3"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Dead-lettered webhooks belong here too: the workflow itself "succeeded"
        // (it queued the send), so nothing else would ever surface the fact that
        // the message never arrived.
        $wfDead = 0;
        try {
            $wfDead = (int)$conn->query(
                "SELECT COUNT(*) FROM webhook_deliveries
                  WHERE status = 'dead'
                    AND updated_datetime >= UTC_TIMESTAMP() - INTERVAL 24 HOUR"
            )->fetchColumn();
        } catch (Exception $ignore) { /* table may not exist yet */ }

        $wf = [
            'failed_24h'    => (int)$wfFailed,
            'aborted_24h'   => (int)$wfAborted,
            'dead_webhooks' => $wfDead,
            'worst'         => array_map(fn($r) => [
                'name'       => $r['name'],
                'failures'   => (int)$r['failures'],
                'last_error' => $r['last_error'],
            ], $wfWorst),
            'all_clear'     => ((int)$wfFailed + (int)$wfAborted + $wfDead) === 0,
            'available'     => true,
        ];
    } catch (Exception $e) {
        // workflow_executions missing (pre-Database-Verify) — show nothing rather
        // than breaking the whole dashboard for every other module.
        $wf['available'] = false;
    }

    return [
        'morning_checks' => $morningChecks,
        'tickets'        => $tickets,
        'changes'        => $changes,
        'calendar'       => $calendar,
        'service_status' => $serviceStatus,
        'contracts'      => $contracts,
        'knowledge'      => $knowledge,
        'assets'         => $assets,
        'tasks'          => $tasksWt,
        'workflows'      => $wf
    ];
}
