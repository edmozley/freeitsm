<?php
/**
 * API: the tickets a MANAGER may see - raised by the people they manage
 * (discussion #62, step 3). GET, same row shape as get_tickets.php plus who
 * raised each one.
 *
 *   ?page=N          50 at a time, newest activity first
 *   ?person=<id>     only this person's tickets (must be on the team)
 *   ?status=<name>   only tickets in this status
 *
 * Also returns `people` and `statuses` - each with a count - for the two
 * dropdowns, so they list only what is there, whatever page is loaded.
 *
 * 🔑 PAGED ON THE SERVER. It used to stop at the 300 most recent tickets, which
 * a head of department with a big team outgrows in weeks, and filtering by
 * person or status in the browser only ever filtered those 300.
 *
 * 🔑 The rules portalTicketAccess() applies to a list - same company as the
 * manager (no company = Default), and confidential tickets left out when
 * managers may see nothing of them - are ALSO written into the query, so that a
 * page is a full page and the counts are true. Every row then still goes through
 * portalTicketAccess() itself, the same rule the ticket page, the attachments and
 * the recordings use: if the two ever disagreed, a page would come back a row
 * short - never a row too long.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/managers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$userId = (int)$_SESSION['ss_user_id'];

const TEAM_TICKETS_PAGE = 50;

try {
    $conn = connectToDatabase();

    $team = managerTeamUserIds($conn, $userId);   // [] when off, not ready, or they have left
    if (!$team) {
        echo json_encode(['success' => true, 'tickets' => [], 'is_manager' => false]);
        exit;
    }

    // ── What every list and count below shares ──────────────────────────────
    $base = "t.user_id IN (" . implode(',', array_fill(0, count($team), '?')) . ") AND t.deleted_datetime IS NULL";
    $args = $team;
    if (isMultiTenant($conn)) {
        $m  = managerRow($conn, $userId);
        // GH #228: the manager's company, and any that supports it from the portal.
        $cos = managerTicketCompanies($conn, $m['tenant_id'] ?? null);
        $in  = "t.tenant_id IN (" . implode(',', array_fill(0, count($cos), '?')) . ")";
        $base .= in_array(getDefaultTenantId($conn), $cos, true) ? " AND ($in OR t.tenant_id IS NULL)" : " AND $in";
        array_push($args, ...$cos);
    }
    if (managersSettings($conn)['confidential'] === 'none' && ticketSensitivityReady($conn)) {
        $base .= " AND t.sensitivity <> 'confidential'";
    }

    // ── The dropdowns ───────────────────────────────────────────────────────
    $st = $conn->prepare(
        "SELECT t.user_id AS id, COALESCE(NULLIF(u.display_name, ''), NULLIF(u.email, ''), u.username) AS name, COUNT(*) AS n
           FROM tickets t LEFT JOIN users u ON u.id = t.user_id
          WHERE $base GROUP BY t.user_id, name ORDER BY name"
    );
    $st->execute($args);
    $people = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'count' => (int)$r['n']], $st->fetchAll(PDO::FETCH_ASSOC));

    // Only somebody on the team - an id from the URL is any number.
    $person = (int)($_GET['person'] ?? 0);
    if ($person && !in_array($person, $team, true)) $person = 0;
    $byPerson = $person ? " AND t.user_id = ?" : '';
    $personArg = $person ? [$person] : [];

    // Statuses within the chosen person, so the status counts add up to what the
    // list will show.
    $st = $conn->prepare(
        "SELECT ts.name, COUNT(*) AS n FROM tickets t LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
          WHERE $base $byPerson AND ts.name IS NOT NULL GROUP BY ts.name ORDER BY ts.name"
    );
    $st->execute(array_merge($args, $personArg));
    $statuses = array_map(fn($r) => ['name' => $r['name'], 'count' => (int)$r['n']], $st->fetchAll(PDO::FETCH_ASSOC));

    $status = trim((string)($_GET['status'] ?? ''));
    $byStatus = $status !== '' ? " AND ts.name = ?" : '';
    $statusArg = $status !== '' ? [$status] : [];

    // ── The page ────────────────────────────────────────────────────────────
    $where = "$base $byPerson $byStatus";
    $whereArgs = array_merge($args, $personArg, $statusArg);

    $c = $conn->prepare("SELECT COUNT(*) FROM tickets t LEFT JOIN ticket_statuses ts ON ts.id = t.status_id WHERE $where");
    $c->execute($whereArgs);
    $total = (int)$c->fetchColumn();

    $page = max(1, (int)($_GET['page'] ?? 1));
    $stmt = $conn->prepare(
        "SELECT t.id, t.ticket_number, t.subject, ts.name AS status, tp.name AS priority,
                ts.colour AS status_colour, ts.is_closed,
                t.created_datetime, t.updated_datetime,
                d.name AS department_name,
                COALESCE(NULLIF(u.display_name, ''), u.email) AS requester_name
           FROM tickets t
      LEFT JOIN ticket_statuses ts   ON ts.id = t.status_id
      LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
      LEFT JOIN departments d        ON d.id  = t.department_id
      LEFT JOIN users u              ON u.id  = t.user_id
          WHERE $where
       ORDER BY t.updated_datetime DESC, t.id DESC
          LIMIT " . TEAM_TICKETS_PAGE . " OFFSET " . (($page - 1) * TEAM_TICKETS_PAGE)
    );
    $stmt->execute($whereArgs);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // The newest message the TICKET'S requester may see - not simply the newest.
    require_once '../../includes/portal_visibility.php';
    $previews = portalVisiblePreviews($conn, array_column($rows, 'id'));

    $out = [];
    foreach ($rows as $t) {
        $t['preview'] = $previews[(int)$t['id']] ?? null;
        $access = portalTicketAccess($conn, $userId, (int)$t['id']);
        if (!$access) continue;                 // the final word, as on every other endpoint
        if ($access['stub']) {
            // That it exists, and nothing it says.
            $t['subject'] = '';
            $t['preview'] = '';
            $t['priority'] = null;
            $t['department_name'] = null;
        }
        $t['stub'] = $access['stub'];
        $t['sensitivity'] = ticketSensitivityNormalise($access['ticket']['sensitivity']);
        $out[] = $t;
    }

    echo json_encode([
        'success'  => true,
        'tickets'  => $out,
        'is_manager' => true,
        'total'    => $total,
        'page'     => $page,
        'per_page' => TEAM_TICKETS_PAGE,
        'has_more' => $page * TEAM_TICKETS_PAGE < $total,
        'people'   => $people,
        'statuses' => $statuses,
        'person'   => $person,
        'status'   => $status,
    ]);
} catch (Exception $e) {
    error_log('[self-service] get_team_tickets: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not load the tickets']);
}
