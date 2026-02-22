<?php
/**
 * API: Watchtower Dashboard — Unified attention summary across all modules
 * GET — Returns attention items from every module in a single response
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $today = date('Y-m-d');

    // ── Morning Checks ──────────────────────────────────────────────────────────

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
        'total_checks'   => $mcTotal,
        'completed_today' => $mcDone,
        'statuses'       => $mcStatuses,
        'not_started'    => $mcDone === 0 && $mcTotal > 0
    ];

    // ── Tickets ─────────────────────────────────────────────────────────────────

    $tkStatusStmt = $conn->query(
        "SELECT status, COUNT(*) AS cnt FROM tickets WHERE status != 'Closed' GROUP BY status"
    );
    $tkStatuses = [];
    while ($row = $tkStatusStmt->fetch(PDO::FETCH_ASSOC)) {
        $tkStatuses[$row['status']] = (int)$row['cnt'];
    }

    $tkUrgent = (int)$conn->query(
        "SELECT COUNT(*) FROM tickets WHERE priority IN ('Urgent','High') AND status != 'Closed'"
    )->fetchColumn();

    $tkUnassigned = (int)$conn->query(
        "SELECT COUNT(*) FROM tickets WHERE assigned_analyst_id IS NULL AND status != 'Closed'"
    )->fetchColumn();

    $tickets = [
        'open'        => $tkStatuses['Open'] ?? 0,
        'in_progress' => $tkStatuses['In Progress'] ?? 0,
        'on_hold'     => $tkStatuses['On Hold'] ?? 0,
        'urgent_high' => $tkUrgent,
        'unassigned'  => $tkUnassigned
    ];

    // ── Changes ─────────────────────────────────────────────────────────────────

    $chUpcoming = (int)$conn->query(
        "SELECT COUNT(*) FROM changes
         WHERE work_start_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
           AND status NOT IN ('Closed','Cancelled')"
    )->fetchColumn();

    $chUnapproved = (int)$conn->query(
        "SELECT COUNT(*) FROM changes WHERE status IN ('Submitted','Pending Approval')"
    )->fetchColumn();

    $chInProgress = (int)$conn->query(
        "SELECT COUNT(*) FROM changes
         WHERE status = 'In Progress'
           AND work_start_datetime <= NOW()
           AND (work_end_datetime >= NOW() OR work_end_datetime IS NULL)"
    )->fetchColumn();

    $changes = [
        'upcoming_7d'       => $chUpcoming,
        'unapproved'        => $chUnapproved,
        'in_progress_today' => $chInProgress
    ];

    // ── Calendar ────────────────────────────────────────────────────────────────

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

    // ── Service Status ──────────────────────────────────────────────────────────

    $ssDegradedStmt = $conn->query(
        "SELECT ss.id, ss.name,
            COALESCE(
                (SELECT sis.impact_level
                 FROM status_incident_services sis
                 JOIN status_incidents si ON sis.incident_id = si.id
                 WHERE sis.service_id = ss.id AND si.status != 'Resolved'
                 ORDER BY CASE sis.impact_level
                     WHEN 'Major Outage' THEN 1 WHEN 'Partial Outage' THEN 2
                     WHEN 'Degraded' THEN 3 WHEN 'Maintenance' THEN 4
                     ELSE 5 END ASC
                 LIMIT 1),
                'Operational'
            ) AS current_status
         FROM status_services ss
         WHERE ss.is_active = 1
         HAVING current_status != 'Operational'
         ORDER BY CASE current_status
             WHEN 'Major Outage' THEN 1 WHEN 'Partial Outage' THEN 2
             WHEN 'Degraded' THEN 3 WHEN 'Maintenance' THEN 4
             ELSE 5 END ASC"
    );
    $ssDegraded = $ssDegradedStmt->fetchAll(PDO::FETCH_ASSOC);

    $ssActiveIncidents = (int)$conn->query(
        "SELECT COUNT(*) FROM status_incidents WHERE status != 'Resolved'"
    )->fetchColumn();

    $serviceStatus = [
        'degraded_services' => $ssDegraded,
        'active_incidents'  => $ssActiveIncidents,
        'all_operational'   => empty($ssDegraded) && $ssActiveIncidents === 0
    ];

    // ── Contracts ───────────────────────────────────────────────────────────────

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

    // ── Knowledge ───────────────────────────────────────────────────────────────

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

    // ── Assets ──────────────────────────────────────────────────────────────────

    $asTotal = (int)$conn->query("SELECT COUNT(*) FROM assets")->fetchColumn();

    $asNotSeen = (int)$conn->query(
        "SELECT COUNT(*) FROM assets
         WHERE last_seen IS NOT NULL AND last_seen < DATE_SUB(NOW(), INTERVAL 7 DAY)"
    )->fetchColumn();

    $assets = [
        'total'       => $asTotal,
        'not_seen_7d' => $asNotSeen
    ];

    // ── Response ────────────────────────────────────────────────────────────────

    echo json_encode([
        'success'        => true,
        'generated_at'   => gmdate('Y-m-d\TH:i:s\Z'),
        'morning_checks' => $morningChecks,
        'tickets'        => $tickets,
        'changes'        => $changes,
        'calendar'       => $calendar,
        'service_status' => $serviceStatus,
        'contracts'      => $contracts,
        'knowledge'      => $knowledge,
        'assets'         => $assets
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
