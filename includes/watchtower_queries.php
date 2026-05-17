<?php
/**
 * Shared Watchtower Dashboard Queries
 * Returns unified attention summary data from all modules
 */

function getWatchtowerData($conn) {
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

    $kbRecentStmt = $conn->query(
        "SELECT id, title, created_datetime
         FROM knowledge_articles
         WHERE is_published = 1 AND is_archived = 0
           AND created_datetime >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY created_datetime DESC
         LIMIT 5"
    );
    $kbRecent = $kbRecentStmt->fetchAll(PDO::FETCH_ASSOC);

    $kbOverdue = (int)$conn->query(
        "SELECT COUNT(*) FROM knowledge_articles
         WHERE is_published = 1 AND is_archived = 0
           AND next_review_date IS NOT NULL AND next_review_date < CURDATE()"
    )->fetchColumn();

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

    $assets = [
        'total'       => $asTotal,
        'not_seen_7d' => $asNotSeen
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

    return [
        'morning_checks' => $morningChecks,
        'tickets'        => $tickets,
        'changes'        => $changes,
        'calendar'       => $calendar,
        'service_status' => $serviceStatus,
        'contracts'      => $contracts,
        'knowledge'      => $knowledge,
        'assets'         => $assets,
        'tasks'          => $tasksWt
    ];
}
