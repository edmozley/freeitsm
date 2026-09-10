<?php
/**
 * API Endpoint: Get list of emails
 * Returns emails from the database for display in inbox
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_snooze.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    // Get filter parameters
    $department_id = $_GET['department_id'] ?? null;
    $status = $_GET['status'] ?? null;
    $assignee_id = $_GET['assignee_id'] ?? null;
    $team_id     = $_GET['team_id'] ?? null;   // #1566

    // Connect to database
    $conn = connectToDatabase();

    // Pre-upgrade installs have no snooze columns; the busiest query in the
    // product must not name them until Database Verification has run.
    $snoozeReady = snoozeSchemaReady($conn);
    $snoozeCols  = $snoozeReady ? "t.snoozed_until, t.snooze_reason," : "NULL AS snoozed_until, NULL AS snooze_reason,";

    // The schedule travels with the row so the context menu can open Schedule on
    // ANY ticket, not just the one in the reading pane — right-clicking a row
    // that is not open is the normal case, and a round-trip per right-click to
    // fetch three columns we are already reading would be a poor trade.
    //
    // Guarded exactly as snooze is, and for the same reason: work_end_datetime /
    // work_all_day are new (#1161), and the busiest query in the product must not
    // name a column until Database Verification has run. work_start_datetime has
    // been there for years but is gated with them so there is ONE condition to
    // reason about rather than two overlapping ones.
    $schedReady = scheduleSchemaReady($conn);
    $schedCols  = $schedReady
        ? "t.work_start_datetime, t.work_end_datetime, t.work_all_day,"
        : "NULL AS work_start_datetime, NULL AS work_end_datetime, 0 AS work_all_day,";

    // Build query with filters - show only the most recent email per ticket
    $sql = "WITH LatestEmails AS (
                SELECT
                    e.id,
                    e.from_address,
                    e.from_name,
                    e.received_datetime,
                    e.body_preview,
                    e.is_read,
                    e.has_attachments,
                    e.importance,
                    e.ticket_id,
                    ROW_NUMBER() OVER (PARTITION BY e.ticket_id ORDER BY e.received_datetime DESC) as rn
                FROM emails e
            )
            SELECT
                -- 🔑 A ROW STILL NEEDS A UNIQUE ID WHEN THERE IS NO EMAIL.
                -- The inbox keys selection, drag and the reading pane off this.
                -- Email ids are positive, so the negative ticket id can never
                -- collide with one, and its sign is what tells the front end to
                -- open the ticket directly instead of an email.
                COALESCE(le.id, -t.id) AS id,
                le.id AS email_id,
                -- With no email there is no sender, so the row falls back to the
                -- ticket's requester. A row showing a ticket number and then a
                -- blank sender would be worse than the bug this fixes.
                COALESCE(le.from_address, u.email) AS from_address,
                COALESCE(le.from_name, u.display_name, u.username) AS from_name,
                COALESCE(le.received_datetime, t.created_datetime) AS received_datetime,
                le.body_preview,
                -- No email means nothing to have left unread.
                COALESCE(le.is_read, 1) AS is_read,
                COALESCE(le.has_attachments, 0) AS has_attachments,
                le.importance,
                t.id AS ticket_id,
                t.ticket_number,
                t.subject,
                ts.name AS status,
                t.department_id,
                t.assigned_analyst_id,
                $snoozeCols
                $schedCols
                tp.name AS priority,
                -- Row-display fields (discussion #61). The names and the analyst id
                -- were already selected here; only the colours and the analyst's
                -- display name are new, and both come off joins that already exist
                -- or cost one more. What the row actually SHOWS is decided per
                -- analyst — see includes/inbox_display.php — but the data is sent
                -- regardless so switching a chip on is instant rather than a reload.
                tp.colour AS priority_colour,
                ts.colour AS status_colour,
                aa.full_name AS assignee_name,
                -- The consolidated view (#1554). Always selected, rendered only
                -- when the analyst is looking at more than one company — one
                -- LEFT JOIN is cheaper than a second query shape to maintain.
                t.tenant_id,
                tn.name AS company_name,
                (SELECT COUNT(*) FROM emails WHERE ticket_id = t.id) as email_count
            -- 🔴 DRIVEN FROM `tickets`, NOT FROM `emails`.
            --
            -- This used to be `FROM LatestEmails le INNER JOIN tickets t`, which
            -- quietly made the inbox a list of EMAILS wearing a ticket's clothes:
            -- a ticket with no email row was counted in every folder badge and
            -- appeared in none of them, and could not be opened by any route.
            -- The folder said 99 and the list showed 96.
            --
            -- A ticket with no email is not exotic. A merge moves the emails to
            -- the surviving ticket; anything that removes the last email leaves
            -- one behind. The inbox lists TICKETS, so tickets is what it reads.
            FROM tickets t
            LEFT JOIN LatestEmails le ON le.ticket_id = t.id AND le.rn = 1
            LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
            LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
            LEFT JOIN analysts aa ON aa.id = t.assigned_analyst_id
            LEFT JOIN users u ON u.id = t.user_id
            LEFT JOIN tenants tn ON tn.id = t.tenant_id
            -- A merged-away ticket has been absorbed into another. It is kept, and
            -- kept findable by number, but it is not sitting in a queue. The old
            -- query dropped it only by accident (the merge took its emails); now
            -- it is excluded deliberately, and the counts exclude it to match.
            WHERE t.merged_into_id IS NULL";

    $params = [];

    if ($department_id === 'unassigned') {
        $sql .= " AND t.department_id IS NULL";
    } elseif ($department_id !== null && $department_id !== '') {
        $sql .= " AND t.department_id = ?";
        $params[] = $department_id;
    }

    if ($assignee_id === 'unassigned') {
        $sql .= " AND t.assigned_analyst_id IS NULL";
    } elseif ($assignee_id !== null && $assignee_id !== '') {
        $sql .= " AND t.assigned_analyst_id = ?";
        $params[] = $assignee_id;
    }

    // Which team owns the ticket (#1566).
    //
    // 🔴 A FILTER, NOT A PERMISSION. This narrows what is on screen; it does not
    // widen what the analyst may see. The tenancy predicate and the department
    // visibility rules above still apply exactly as they did — asking for one
    // team's queue can only ever return a subset of what you could already read.
    if ($team_id === 'unassigned') {
        $sql .= " AND t.assigned_team_id IS NULL";
    } elseif ($team_id !== null && $team_id !== '') {
        $sql .= " AND t.assigned_team_id = ?";
        $params[] = (int)$team_id;
    }

    if ($status !== null && $status !== '') {
        $sql .= " AND ts.name = ?";
        $params[] = $status;
    }

    // Multi-tenancy: scope the list to the analyst's active company (no-op at N=1).
    list($tenantSql, $tenantParams) = ticketTenantFilter($conn, (int)$_SESSION['analyst_id']);
    $sql .= $tenantSql;
    $params = array_merge($params, $tenantParams);

    // Trash: the Trash folder shows ONLY soft-deleted tickets; every other view
    // hides them.
    $sql .= !empty($_GET['trashed'])
        ? " AND t.deleted_datetime IS NOT NULL"
        : " AND t.deleted_datetime IS NULL";

    // Snoozed (#933): sleeping tickets leave every folder and gather in their own,
    // exactly as trashed ones do. The Trash view is excepted so a ticket that was
    // snoozed and then binned is still findable where it was put last.
    if (!empty($_GET['snoozed'])) {
        $sql .= snoozeOnlySql($conn, 't');
    } elseif (empty($_GET['trashed'])) {
        $sql .= snoozeHiddenSql($conn, 't');
    }

    // The Snoozed folder sorts by when each ticket comes back — the only order
    // that answers the question you open that folder to ask ("what's next?").
    $sql .= (!empty($_GET['snoozed']) && $snoozeReady)
        ? " ORDER BY t.snoozed_until ASC"
        : " ORDER BY COALESCE(le.received_datetime, t.created_datetime) DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format dates for display
    foreach ($emails as &$email) {
        if ($email['received_datetime']) {
            $email['received_datetime'] = date('Y-m-d\TH:i:s', strtotime($email['received_datetime']));
        }
        // Convert bit fields to boolean
        $email['is_read'] = (bool)$email['is_read'];
        $email['has_attachments'] = (bool)$email['has_attachments'];
        // Only report a snooze that is still in the future — an expired one has
        // already woken, and a row badge saying "until last Tuesday" is noise.
        if (!empty($email['snoozed_until']) && strtotime($email['snoozed_until'] . ' UTC') <= time()) {
            $email['snoozed_until'] = null;
            $email['snooze_reason'] = null;
        }
    }

    echo json_encode([
        'success' => true,
        'emails' => $emails,
        'count' => count($emails),
        // The consolidated view (#1554). Told once per fetch rather than inferred
        // by the front end from whether the rows happen to span companies — a
        // filtered page can easily contain one company's tickets while the view
        // is still "all", and the column must not vanish when it does.
        'all_companies' => isActiveTenantAll($conn)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
