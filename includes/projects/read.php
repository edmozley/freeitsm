<?php
/**
 * Projects - the reads the screens share: the portfolio and one project.
 *
 * Reads live here, not in the service (writes are unified, reads stay per
 * surface - the Service-Layer rule). The REST API will have its own serialiser.
 *
 * 🔑 SCOPE IN SQL, NOT IN JS. The portfolio is filtered by company in the query
 * (activeTenantReadFilter - one company, or every company the analyst can see in
 * the "All companies" view). A project the analyst may not see is never sent.
 *
 * 🔑 PROGRESS AND HEALTH ARE WORKED OUT, NEVER STORED. Progress is the share of
 * the project's top-level tasks that are in a closed status; automatic health is
 * derived from dates and overdue work (projectAutoHealth). Storing either would
 * let it go stale the moment somebody ticked a task on the Tasks board.
 */

require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/methodologies.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/targets.php';

/**
 * Per-project task counts in one query: total, done, overdue (top-level tasks
 * only), plus targets_health - the worst of the project's asset targets
 * (includes/projects/targets.php) - so every screen's health agrees.
 */
function projectTaskStats(PDO $conn, array $projectIds): array
{
    $out = [];
    if (!$projectIds) return $out;
    $ph = implode(',', array_fill(0, count($projectIds), '?'));
    $st = $conn->prepare(
        "SELECT t.project_id,
                COUNT(*) AS total,
                SUM(CASE WHEN ts.is_closed = 1 THEN 1 ELSE 0 END) AS done,
                SUM(CASE WHEN COALESCE(ts.is_closed, 0) = 0 AND t.due_date IS NOT NULL AND t.due_date < UTC_DATE() THEN 1 ELSE 0 END) AS overdue
           FROM tasks t
      LEFT JOIN task_statuses ts ON ts.id = t.status_id
          WHERE t.project_id IN ($ph) AND t.parent_task_id IS NULL
       GROUP BY t.project_id");
    $st->execute(array_map('intval', $projectIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['project_id']] = ['total' => (int)$r['total'], 'done' => (int)$r['done'], 'overdue' => (int)$r['overdue']];
    }
    foreach (projectTargetsFor($conn, $projectIds) as $pid => $targets) {
        $out[$pid] = ($out[$pid] ?? ['total' => 0, 'done' => 0, 'overdue' => 0]) + ['targets_health' => projectTargetsWorst($targets)];
    }
    return $out;
}

/**
 * The automatic health of a live project, from what is actually happening:
 *   red   - past its target end date with work still open, or a quarter or more
 *           of the open work overdue;
 *   amber - any open work overdue, or the target date within 14 days and less
 *           than three quarters done;
 *   green - otherwise.
 * An asset target that is red makes the project red; an amber one makes a
 * green project amber (projectTargetHealth).
 * Finished projects have no health (null). Written in the help page too - keep
 * the two in step.
 */
function projectAutoHealth(array $p, array $stats, ?array $cfg = null): ?string
{
    if (in_array($p['status'], projectFinishedStatuses(), true)) return null;
    // Projects -> Settings -> Health; the defaults are 14 days, 75% and 25%.
    $cfg = $cfg ?? ['amber_days' => 14, 'amber_progress' => 75, 'red_overdue_pct' => 25];
    $total = $stats['total'] ?? 0; $done = $stats['done'] ?? 0; $overdue = $stats['overdue'] ?? 0;
    $open = $total - $done;
    $targets = $stats['targets_health'] ?? null;
    if ($targets === 'red') return 'red';
    $today = gmdate('Y-m-d');
    if (!empty($p['target_end_date']) && $p['target_end_date'] < $today && $open > 0) return 'red';
    if ($open > 0 && $overdue > 0 && $overdue * 100 >= $open * $cfg['red_overdue_pct']) return 'red';
    if ($overdue > 0) return 'amber';
    if (!empty($p['target_end_date']) && $open > 0) {
        $days = (strtotime($p['target_end_date']) - strtotime($today)) / 86400;
        if ($days <= $cfg['amber_days'] && $total > 0 && ($done * 100 / $total) < $cfg['amber_progress']) return 'amber';
    }
    return $targets === 'amber' ? 'amber' : 'green';
}

/** The Health tab's thresholds, as numbers. */
function projectHealthConfig(PDO $conn): array
{
    return [
        'amber_days'      => (int)projectSetting($conn, 'project_amber_days'),
        'amber_progress'  => (int)projectSetting($conn, 'project_amber_progress'),
        'red_overdue_pct' => (int)projectSetting($conn, 'project_red_overdue_pct'),
    ];
}

/** Has Database Verification created the phase 2 tables? The portfolio query names them. */
function projectsPhase2Ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT 1 FROM project_tolerances LIMIT 0"); $conn->query("SELECT 1 FROM project_raid LIMIT 0"); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/** The columns the exceptions are read from, as SQL - NULLs before Verification. */
function projectExceptionColumns(PDO $conn): string
{
    if (!projectsPhase2Ready($conn)) return "NULL AS max_risk, NULL AS tol_time, NULL AS tol_risk, NULL AS active_stage_end";
    return "(SELECT MAX(r.probability * r.impact) FROM project_raid r WHERE r.project_id = p.id AND r.type = 'risk' AND r.status = 'open') AS max_risk,
            (SELECT t.value FROM project_tolerances t WHERE t.project_id = p.id AND t.stage_id IS NULL AND t.dimension = 'time') AS tol_time,
            (SELECT t.value FROM project_tolerances t WHERE t.project_id = p.id AND t.stage_id IS NULL AND t.dimension = 'risk') AS tol_risk,
            (SELECT s.end_date FROM project_stages s WHERE s.project_id = p.id AND s.status = 'active' ORDER BY s.position, s.id LIMIT 1) AS active_stage_end";
}

/**
 * A project's tolerance breaches - PRINCE2-style "manage by exception". Only for
 * a live project with the Gates tool on and a tolerance set:
 *   time  - the target finish (or the active stage's end) is more than the
 *           allowed days in the past with work still open;
 *   risk  - an open risk scores above the allowed score.
 * An exception turns automatic health red. Worked out, never stored.
 */
function projectExceptions(array $p, array $stats): array
{
    if (in_array($p['status'], projectFinishedStatuses(), true) || !in_array('gates', projectEnabledTools($p), true)) return [];
    $out = [];
    $open = ($stats['total'] ?? 0) - ($stats['done'] ?? 0);
    $today = strtotime(gmdate('Y-m-d'));
    if (isset($p['tol_time']) && $p['tol_time'] !== null && $open > 0) {
        foreach (['target_end_date' => 'time', 'active_stage_end' => 'stage_time'] as $col => $kind) {
            if (empty($p[$col])) continue;
            $late = (int)floor(($today - strtotime($p[$col])) / 86400);
            if ($late > (int)$p['tol_time']) $out[] = ['kind' => $kind, 'late' => $late, 'allowed' => (int)$p['tol_time']];
        }
    }
    if (isset($p['tol_risk']) && $p['tol_risk'] !== null && !empty($p['max_risk']) && (int)$p['max_risk'] > (int)$p['tol_risk']) {
        $out[] = ['kind' => 'risk', 'score' => (int)$p['max_risk'], 'allowed' => (int)$p['tol_risk']];
    }
    return $out;
}

/** Add progress, counts and the health actually shown to a project row. */
function projectDecorate(array $p, array $stats, ?array $cfg = null): array
{
    $s = $stats + ['total' => 0, 'done' => 0, 'overdue' => 0];
    $p['task_total']   = $s['total'];
    $p['task_done']    = $s['done'];
    $p['task_overdue'] = $s['overdue'];
    $p['progress']     = $s['total'] > 0 ? (int)round($s['done'] * 100 / $s['total']) : 0;
    $p['auto_health']  = projectAutoHealth($p, $s, $cfg);
    $p['exceptions']   = projectExceptions($p, $s);
    if ($p['exceptions'] && $p['auto_health'] !== null) $p['auto_health'] = 'red';
    $p['shown_health'] = in_array($p['status'], projectFinishedStatuses(), true)
        ? null
        : ($p['health'] !== 'auto' ? $p['health'] : $p['auto_health']);
    $p['code'] = projectCode((int)$p['id']);
    $p['tools'] = projectEnabledTools($p);
    return $p;
}

/** The reference people say out loud: PRJ-0042. */
function projectCode(int $id): string
{
    return 'PRJ-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/**
 * The portfolio, scoped to what this analyst may see.
 *
 * @param array $f optional filters: q, status, owner_analyst_id, mine (bool)
 */
function projectListRows(PDO $conn, int $analystId, array $f = []): array
{
    [$tSql, $tArgs] = activeTenantReadFilter($conn, $analystId, 'p');
    $where = ['1=1']; $args = [];
    if (!empty($f['q'])) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string)$f['q'])) . '%';
        $where[] = '(p.name LIKE ? OR p.summary LIKE ? OR p.goal LIKE ?)';
        array_push($args, $like, $like, $like);
    }
    if (!empty($f['status']) && in_array($f['status'], projectStatuses(), true)) {
        $where[] = 'p.status = ?'; $args[] = $f['status'];
    }
    if (!empty($f['mine'])) {
        $where[] = 'p.owner_analyst_id = ?'; $args[] = $analystId;
    }
    $sql = "SELECT p.id, p.tenant_id, tn.name AS company_name, p.name, p.summary, p.goal, p.methodology,
                   p.status, p.health, p.health_note, p.owner_analyst_id, a.full_name AS owner_name,
                   p.start_date, p.target_end_date, p.actual_end_date, p.colour, p.icon, p.tailoring, p.created_by_id,
                   p.created_datetime, p.updated_datetime, p.closed_datetime,
                   (SELECT s.name FROM project_stages s WHERE s.project_id = p.id AND s.status = 'active' ORDER BY s.position, s.id LIMIT 1) AS active_stage_name,
                   (SELECT COUNT(*) FROM project_stages s WHERE s.project_id = p.id) AS stage_count,
                   " . projectExceptionColumns($conn) . "
              FROM projects p
         LEFT JOIN analysts a ON a.id = p.owner_analyst_id
         LEFT JOIN tenants tn ON tn.id = p.tenant_id
             WHERE " . implode(' AND ', $where) . " $tSql
          ORDER BY FIELD(p.status, 'active', 'proposed', 'on_hold', 'closed', 'cancelled'), p.target_end_date IS NULL, p.target_end_date, p.name";
    $st = $conn->prepare($sql);
    $st->execute(array_merge($args, $tArgs));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $stats = projectTaskStats($conn, array_column($rows, 'id'));
    $cfg = projectHealthConfig($conn);
    foreach ($rows as &$r) {
        $r = projectDecorate($r, $stats[(int)$r['id']] ?? [], $cfg);
    }
    unset($r);
    return $rows;
}

/** One project for its page: the row, its time boxes with their counts, its tasks and its history. */
function projectDetail(PDO $conn, array $row): array
{
    $id = (int)$row['id'];
    $st = $conn->prepare("SELECT a.full_name AS owner_name, tn.name AS company_name, " . projectExceptionColumns($conn) . "
                            FROM projects p LEFT JOIN analysts a ON a.id = p.owner_analyst_id
                       LEFT JOIN tenants tn ON tn.id = p.tenant_id WHERE p.id = ?");
    $st->execute([$id]);
    $row += $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats = projectTaskStats($conn, [$id]);
    $project = projectDecorate($row, $stats[$id] ?? [], projectHealthConfig($conn));

    $s = $conn->prepare("SELECT s.*,
                                (SELECT COUNT(*) FROM tasks t WHERE t.project_stage_id = s.id AND t.parent_task_id IS NULL) AS task_total,
                                (SELECT COUNT(*) FROM tasks t JOIN task_statuses ts ON ts.id = t.status_id
                                  WHERE t.project_stage_id = s.id AND t.parent_task_id IS NULL AND ts.is_closed = 1) AS task_done
                           FROM project_stages s WHERE s.project_id = ? ORDER BY s.position, s.id");
    $s->execute([$id]);
    $stages = $s->fetchAll(PDO::FETCH_ASSOC);

    $t = $conn->prepare("SELECT t.id, t.title, t.status_id, ts.name AS status_name, ts.colour AS status_colour, COALESCE(ts.is_closed, 0) AS is_closed,
                                t.priority_id, tp.name AS priority_name, tp.colour AS priority_colour,
                                t.start_date, t.due_date, t.assigned_analyst_id, an.full_name AS assignee_name,
                                t.assigned_team_id, tm.name AS team_name, t.project_stage_id, t.completed_datetime,
                                (SELECT COUNT(*) FROM tasks c WHERE c.parent_task_id = t.id) AS subtask_count
                           FROM tasks t
                      LEFT JOIN task_statuses ts ON ts.id = t.status_id
                      LEFT JOIN task_priorities tp ON tp.id = t.priority_id
                      LEFT JOIN analysts an ON an.id = t.assigned_analyst_id
                      LEFT JOIN teams tm ON tm.id = t.assigned_team_id
                          WHERE t.project_id = ? AND t.parent_task_id IS NULL
                       ORDER BY t.board_position, t.id");
    $t->execute([$id]);
    $tasks = $t->fetchAll(PDO::FETCH_ASSOC);

    $h = $conn->prepare("SELECT pa.field_name, pa.old_value, pa.new_value, pa.source, pa.created_datetime, an.full_name AS analyst_name
                           FROM project_audit pa LEFT JOIN analysts an ON an.id = pa.analyst_id
                          WHERE pa.project_id = ? ORDER BY pa.created_datetime DESC, pa.id DESC LIMIT 50");
    $h->execute([$id]);

    return ['project' => $project, 'stages' => $stages, 'tasks' => $tasks, 'history' => $h->fetchAll(PDO::FETCH_ASSOC)];
}
