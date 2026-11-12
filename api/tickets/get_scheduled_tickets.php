<?php
/**
 * API Endpoint: Get scheduled tickets for calendar view
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/tickets.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

// New: support explicit start/end (YYYY-MM-DD) for week/day views.
// Fallback: legacy year/month query for backwards compatibility.
$start = $_GET['start'] ?? null;
$end = $_GET['end'] ?? null;

if ($start && $end && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    $startDate = $start;
    $endDate = $end;
} else {
    $year = (int)($_GET['year'] ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    $startDate = date('Y-m-d', strtotime("$year-$month-01 -7 days"));
    $endDate = date('Y-m-d', strtotime("$year-$month-01 +40 days"));
}

try {
    $conn = connectToDatabase();

    // Guarded like the inbox list: naming a column that a pre-upgrade install
    // does not have yet would empty the calendar with an SQL error rather than
    // simply showing every ticket at its old one-hour default (#1161).
    require_once '../../includes/ticket_snooze.php';
    $schedCols = scheduleSchemaReady($conn)
        ? "t.work_end_datetime, t.work_all_day,"
        : "NULL AS work_end_datetime, 0 AS work_all_day,";

    $sql = "SELECT
                t.id,
                t.ticket_number,
                t.subject,
                ts.name AS status,
                tp.name AS priority,
                t.work_start_datetime,
                $schedCols
                t.owner_id,
                u.display_name AS requester_name,
                u.email AS requester_email,
                d.name as department_name,
                a.full_name as owner_name
            FROM tickets t
            LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
            LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
            LEFT JOIN departments d ON d.id = t.department_id
            LEFT JOIN analysts a ON a.id = t.owner_id
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.work_start_datetime IS NOT NULL
              AND t.work_start_datetime >= ?
              AND t.work_start_datetime < ?
              AND ts.is_closed = 0";

    // Whose work to show. Defaults to MINE — the screen has always shown every
    // scheduled ticket in the company, which is useful for planning but is not
    // what you want when you open your own calendar to see your day.
    //
    // 🔑 NOT A PERMISSION. Everyone may switch to 'all': these are the same
    // tickets the inbox already lists, so gating the calendar view would be a
    // locked door beside an open window. What is scoped is TENANCY, below, which
    // applies to both modes.
    $scope = ($_GET['scope'] ?? 'mine') === 'all' ? 'all' : 'mine';
    $scopeParams = [];
    if ($scope === 'mine') {
        $sql .= " AND t.owner_id = ?";
        $scopeParams[] = (int)$_SESSION['analyst_id'];
    }

    // Multi-tenancy: scope the calendar to the active company (no-op at N=1).
    list($ttSql, $ttParams) = ticketScopeFilter($conn, (int)$_SESSION['analyst_id'], 't');
    $ttSql .= " AND t.deleted_datetime IS NULL"; // hide trashed tickets
    $sql .= $ttSql . " ORDER BY t.work_start_datetime ASC";

    // Order matters: the placeholders bind positionally, and the scope clause was
    // appended to $sql BEFORE the tenancy one.
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$startDate, $endDate], $scopeParams, $ttParams));
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format datetime for JavaScript
    //
    // 🔑 THE END IS RESOLVED HERE, NOT IN THE BROWSER. A ticket scheduled before
    // work_end_datetime existed has NULL, and so does one saved by a stale copy of
    // inbox.js — both must draw as a normal block rather than a zero-height sliver
    // or a crash. One default, applied server-side, so the calendar view and
    // anything else reading this endpoint cannot drift apart on what an
    // unspecified duration means.
    foreach ($tickets as &$ticket) {
        $ticket['work_all_day'] = (int)($ticket['work_all_day'] ?? 0) === 1;
        if ($ticket['work_start_datetime']) {
            $startTs = strtotime($ticket['work_start_datetime']);
            $endTs   = $ticket['work_end_datetime'] ? strtotime($ticket['work_end_datetime']) : null;
            // A stored end at or before the start would render as nothing at all;
            // treat it as unspecified rather than drawing an invisible event.
            if ($endTs === null || $endTs <= $startTs) {
                $endTs = $startTs + TicketsService::SCHEDULE_DEFAULT_MINUTES * 60;
            }
            $ticket['work_start_datetime'] = date('Y-m-d\TH:i:s', $startTs);
            $ticket['work_end_datetime']   = date('Y-m-d\TH:i:s', $endTs);
            $ticket['duration_minutes']    = (int)round(($endTs - $startTs) / 60);
        }
    }

    echo json_encode([
        'success' => true,
        'scope'   => $scope,
        'tickets' => $tickets
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
