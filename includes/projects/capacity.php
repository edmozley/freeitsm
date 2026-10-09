<?php
/**
 * Projects - capacity (3.3.0): who is over-committed in the weeks ahead, once
 * project work and service-desk duty are put side by side.
 *
 * Per analyst, per week (Monday to Sunday, from this week):
 *
 *   project hours  every OPEN project task they are assigned, in a live project
 *                  the viewer can see: its estimate less the time already logged
 *                  on it (and its subtasks), spread evenly over the WORKING days
 *                  (Monday to Friday) from its start - or today, if that is later
 *                  - to its due date. Late work (due before today) lands in this
 *                  week. Only the part inside a week is counted in that week.
 *   desk hours     their shifts on the service-desk rota that week (Tickets ->
 *                  Rota), each shift's length. On the rota, they are not on the
 *                  project. Shown only to a viewer who can open Tickets.
 *   load           (project + desk) / hours per week (Projects -> Settings ->
 *                  Health). Amber at the amber share, red over 100%.
 *
 * Beside the weeks: their open tickets (context, not hours - a ticket has no
 * size), how many live projects they have open work on (flagged at the setting),
 * work with no due date ("unscheduled", hours), and tasks with no estimate (a
 * count - unknown work is a risk, not zero).
 *
 * 🔑 WORKED OUT, NEVER STORED, like health. Nothing here writes.
 *
 * 🔑 WHOSE: projects through activeTenantReadFilter (the viewer's company, as on
 * the portfolio); tickets through allAccessibleTenantsFilter (every company the
 * viewer can see - a person's load is all of it that the viewer may know about).
 * The rota is install-wide, as on the rota screen.
 *
 * Who appears: analysts with open assigned work in those projects, and analyst
 * members of them (so somebody on a project with nothing assigned shows as free).
 * Work assigned to a TEAM and nobody in it is totalled separately - it is real
 * work that nobody has picked up.
 */

require_once __DIR__ . '/read.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../tenancy.php';

/** The working days (Mon-Fri) from $from to $to inclusive, as Y-m-d. */
function projectCapacityWorkdays(string $from, string $to): array
{
    $out = [];
    for ($t = strtotime($from . ' 00:00:00 UTC'), $end = strtotime($to . ' 00:00:00 UTC'); $t <= $end; $t += 86400) {
        $dow = (int)gmdate('N', $t);
        if ($dow <= 5) $out[] = gmdate('Y-m-d', $t);
    }
    return $out;
}

/** Length of a rota shift in hours; a shift that ends at or before it starts runs past midnight. */
function projectCapacityShiftHours(string $start, string $end): float
{
    $s = strtotime('1970-01-01 ' . $start . ' UTC'); $e = strtotime('1970-01-01 ' . $end . ' UTC');
    if ($e <= $s) $e += 86400;
    return round(($e - $s) / 3600, 2);
}

/**
 * @param int $weeks 1-12
 * @return array{weeks:array, hours_per_week:float, amber:int, several:int, can_tickets:bool, rows:array, team_hours:float, team_tasks:int}
 */
function projectCapacity(PDO $conn, int $viewerId, int $weeks = 4): array
{
    $weeks = max(1, min(12, $weeks));
    $set = projectSettings($conn);
    $hoursPerWeek = (float)($set['project_capacity_hours'] ?? 37.5);
    $amber = (int)($set['project_capacity_amber'] ?? 85);
    $several = (int)($set['project_capacity_projects'] ?? 3);

    $today = gmdate('Y-m-d');
    $monday = gmdate('Y-m-d', strtotime($today . ' 00:00:00 UTC') - ((int)gmdate('N', strtotime($today . ' 00:00:00 UTC')) - 1) * 86400);
    $weekList = [];
    for ($i = 0; $i < $weeks; $i++) {
        $s = gmdate('Y-m-d', strtotime($monday . ' 00:00:00 UTC') + $i * 7 * 86400);
        $weekList[] = ['start' => $s, 'end' => gmdate('Y-m-d', strtotime($s . ' 00:00:00 UTC') + 6 * 86400)];
    }
    $windowEnd = $weekList[$weeks - 1]['end'];
    $weekOf = function (string $d) use ($monday, $weeks): ?int {
        $i = intdiv((int)round((strtotime($d . ' 00:00:00 UTC') - strtotime($monday . ' 00:00:00 UTC')) / 86400), 7);
        return $i >= 0 && $i < $weeks ? $i : null;
    };
    $canTickets = analystCanAccessModule($conn, $viewerId, 'tickets');
    $out = ['weeks' => $weekList, 'hours_per_week' => $hoursPerWeek, 'amber' => $amber, 'several' => $several,
            'can_tickets' => $canTickets, 'rows' => [], 'team_hours' => 0.0, 'team_tasks' => 0, 'estimates_ready' => projectEstimatesReady($conn)];

    // Live projects the viewer can see.
    [$tSql, $tArgs] = activeTenantReadFilter($conn, $viewerId, 'p');
    $st = $conn->prepare("SELECT p.id, p.name FROM projects p WHERE p.status IN ('proposed', 'active') $tSql");
    $st->execute($tArgs);
    $projects = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $projects[(int)$p['id']] = $p['name'];
    if (!$projects) return $out;
    $pin = implode(',', array_keys($projects));

    $est = $out['estimates_ready'] ? 't.estimate_hours' : 'NULL AS estimate_hours';
    $tasks = $conn->query(
        "SELECT t.id, t.title, t.start_date, t.due_date, $est, t.assigned_analyst_id, t.assigned_team_id, t.project_id,
                (SELECT COALESCE(SUM(e.time_spent_minutes), 0) FROM task_time_entries e
                  WHERE e.is_active = 1 AND (e.task_id = t.id OR e.task_id IN (SELECT c.id FROM tasks c WHERE c.parent_task_id = t.id))) AS logged_minutes
           FROM tasks t
      LEFT JOIN task_statuses ts ON ts.id = t.status_id
          WHERE t.project_id IN ($pin) AND t.parent_task_id IS NULL AND COALESCE(ts.is_closed, 0) = 0
            AND (t.assigned_analyst_id IS NOT NULL OR t.assigned_team_id IS NOT NULL)"
    )->fetchAll(PDO::FETCH_ASSOC);

    $people = [];
    $person = function (int $id) use (&$people, $weeks) {
        if (!isset($people[$id])) {
            $people[$id] = ['analyst_id' => $id, 'name' => '', 'weeks' => array_fill(0, $weeks, ['project' => 0.0, 'desk' => 0.0]),
                            'projects' => [], 'unscheduled' => 0.0, 'no_estimate' => 0, 'later' => 0.0, 'late' => 0, 'open_tickets' => null, 'tasks' => []];
        }
        return $id;
    };
    // Analyst members of these projects show even with nothing assigned.
    try {
        foreach ($conn->query("SELECT DISTINCT analyst_id FROM project_members WHERE project_id IN ($pin) AND analyst_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $a) $person((int)$a);
    } catch (Throwable $e) { /* before phase 2 verification */ }

    foreach ($tasks as $t) {
        $remaining = $t['estimate_hours'] !== null ? max(0.0, (float)$t['estimate_hours'] - (int)$t['logged_minutes'] / 60) : null;
        if ($t['assigned_analyst_id'] === null) {
            // A team's, nobody's yet.
            $out['team_tasks']++;
            if ($remaining !== null) $out['team_hours'] += $remaining;
            continue;
        }
        $pid = (int)$t['project_id'];
        $id = $person((int)$t['assigned_analyst_id']);
        $people[$id]['projects'][$pid] = ['id' => $pid, 'code' => projectCode($pid), 'name' => $projects[$pid]];
        $perWeek = array_fill(0, $weeks, 0.0);
        $late = $t['due_date'] !== null && $t['due_date'] < $today;
        $where = 'scheduled';
        if ($remaining === null) {
            $people[$id]['no_estimate']++;
            $where = 'no_estimate';
        } elseif ($remaining <= 0) {
            $where = 'spent';     // estimate used up and still open - shown, counted as nothing left
        } elseif ($t['due_date'] === null) {
            $people[$id]['unscheduled'] += $remaining;
            $where = 'unscheduled';
        } elseif ($late) {
            $perWeek[0] += $remaining;
            $people[$id]['late']++;
        } else {
            $from = max($t['start_date'] ?: $today, $today);
            if ($from > $t['due_date']) $from = $t['due_date'];
            $days = projectCapacityWorkdays($from, $t['due_date']) ?: [$t['due_date']];
            $share = $remaining / count($days);
            foreach ($days as $d) {
                $w = $weekOf($d);
                if ($w === null) $people[$id]['later'] += $share; else $perWeek[$w] += $share;
            }
        }
        foreach ($perWeek as $w => $h) $people[$id]['weeks'][$w]['project'] += $h;
        $people[$id]['tasks'][] = ['id' => (int)$t['id'], 'title' => $t['title'], 'project_id' => $pid, 'project_code' => projectCode($pid),
            'project_name' => $projects[$pid], 'due_date' => $t['due_date'], 'estimate_hours' => $t['estimate_hours'] !== null ? (float)$t['estimate_hours'] : null,
            'remaining_hours' => $remaining !== null ? round($remaining, 2) : null, 'late' => $late, 'state' => $where,
            'weeks' => array_map(fn($h) => round($h, 2), $perWeek)];
    }
    if (!$people) return $out;

    $ids = array_keys($people);
    $in = implode(',', $ids);
    foreach ($conn->query("SELECT id, full_name FROM analysts WHERE id IN ($in) AND is_active = 1")->fetchAll(PDO::FETCH_KEY_PAIR) as $aid => $name) $people[(int)$aid]['name'] = $name;
    $people = array_filter($people, fn($p) => $p['name'] !== '');   // inactive analysts drop out
    if (!$people) return $out;
    $in = implode(',', array_keys($people));

    if ($canTickets) {
        // The desk: rota shifts in the window.
        try {
            $rs = $conn->prepare("SELECT e.analyst_id, e.rota_date, s.start_time, s.end_time FROM ticket_rota_entries e JOIN ticket_rota_shifts s ON s.id = e.shift_id
                                   WHERE e.analyst_id IN ($in) AND e.rota_date BETWEEN ? AND ?");
            $rs->execute([$monday, $windowEnd]);
            foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $w = $weekOf(substr((string)$r['rota_date'], 0, 10));
                if ($w !== null) $people[(int)$r['analyst_id']]['weeks'][$w]['desk'] += projectCapacityShiftHours((string)$r['start_time'], (string)$r['end_time']);
            }
        } catch (Throwable $e) { /* no rota tables */ }
        // Open tickets they own, in the companies the viewer can see.
        [$kSql, $kArgs] = allAccessibleTenantsFilter($conn, $viewerId, 't.tenant_id');
        $ks = $conn->prepare("SELECT t.owner_id, COUNT(*) FROM tickets t LEFT JOIN ticket_statuses s ON s.id = t.status_id
                               WHERE t.owner_id IN ($in) AND t.deleted_datetime IS NULL AND COALESCE(s.is_closed, 0) = 0 $kSql GROUP BY t.owner_id");
        $ks->execute($kArgs);
        foreach (array_keys($people) as $aid) $people[$aid]['open_tickets'] = 0;
        foreach ($ks->fetchAll(PDO::FETCH_KEY_PAIR) as $aid => $n) if (isset($people[(int)$aid])) $people[(int)$aid]['open_tickets'] = (int)$n;
    }

    foreach ($people as &$p) {
        $worst = 0;
        foreach ($p['weeks'] as &$w) {
            $w['project'] = round($w['project'], 1);
            $w['desk'] = round($w['desk'], 1);
            $w['load_pct'] = $hoursPerWeek > 0 ? (int)round(($w['project'] + $w['desk']) * 100 / $hoursPerWeek) : 0;
            $w['state'] = $w['load_pct'] > 100 ? 'red' : ($w['load_pct'] >= $amber ? 'amber' : 'green');
            $worst = max($worst, $w['load_pct']);
        }
        unset($w);
        $p['projects'] = array_values($p['projects']);
        $p['several'] = count($p['projects']) >= $several;
        $p['unscheduled'] = round($p['unscheduled'], 1);
        $p['later'] = round($p['later'], 1);
        $p['worst_pct'] = $worst;
        usort($p['tasks'], fn($a, $b) => strcmp((string)($a['due_date'] ?? '9999'), (string)($b['due_date'] ?? '9999')));
    }
    unset($p);
    $rows = array_values($people);
    usort($rows, fn($a, $b) => ($b['worst_pct'] <=> $a['worst_pct']) ?: strcmp($a['name'], $b['name']));
    $out['rows'] = $rows;
    $out['team_hours'] = round($out['team_hours'], 1);
    return $out;
}
