<?php
/**
 * Projects - milestones (3.3.0): the dates a project promises, as diamonds on
 * the Timeline, the Overview, the Calendar and in the health.
 *
 * A milestone is a NAMED DATE, not work: "Move day", "Go-live", "Board sign-off".
 * The work is tasks; a milestone is what the tasks are racing towards. It may
 * sit in a stage (it is drawn in that stage's band) or belong to the whole
 * project (stage_id NULL).
 *
 * Its state is worked out, never stored, the same rule as health:
 *   done    - done_date is set ("reached"); met if done on or before due_date
 *   missed  - not done and due_date is in the past
 *   due     - not done, today or later
 *
 * 🔑 A MISSED MILESTONE TURNS AUTOMATIC HEALTH AMBER (projectAutoHealth), the
 * same weight as an overdue task: a date the project promised has gone by. It
 * never makes a project red on its own - that is what the time tolerance is for.
 */

/** Has Database Verification created the table? Everything here is quiet before it. */
function projectMilestonesReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT 1 FROM project_milestones LIMIT 0"); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/** done | missed | due - see the header. */
function projectMilestoneState(array $m, ?string $today = null): string
{
    if (!empty($m['done_date'])) return 'done';
    return $m['due_date'] < ($today ?? gmdate('Y-m-d')) ? 'missed' : 'due';
}

/** One project's milestones in date order, each with its state, for the project page. */
function projectMilestones(PDO $conn, int $projectId): array
{
    if (!projectMilestonesReady($conn)) return [];
    $st = $conn->prepare("SELECT m.id, m.stage_id, s.name AS stage_name, m.name, m.due_date, m.done_date, m.done_by_analyst_id,
                                 a.full_name AS done_by_name, m.notes, m.position
                            FROM project_milestones m
                       LEFT JOIN project_stages s ON s.id = m.stage_id
                       LEFT JOIN analysts a ON a.id = m.done_by_analyst_id
                           WHERE m.project_id = ? ORDER BY m.due_date, m.position, m.id");
    $st->execute([$projectId]);
    $today = gmdate('Y-m-d');
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['state'] = projectMilestoneState($r, $today);
        $r['met'] = $r['state'] === 'done' ? ($r['done_date'] <= $r['due_date']) : null;
    }
    unset($r);
    return $rows;
}

/**
 * Per project: how many milestones are missed, and the next one still to come
 * ({id, name, due_date} or null) - for health and the portfolio card.
 */
function projectMilestoneStats(PDO $conn, array $projectIds): array
{
    $out = [];
    if (!$projectIds || !projectMilestonesReady($conn)) return $out;
    $ids = array_map('intval', $projectIds);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $conn->prepare("SELECT project_id, COUNT(*) AS n FROM project_milestones
                           WHERE project_id IN ($ph) AND done_date IS NULL AND due_date < UTC_DATE() GROUP BY project_id");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['project_id']]['missed'] = (int)$r['n'];
    // The next one: the earliest not done from today on. Ties go to position.
    $st = $conn->prepare("SELECT m.project_id, m.id, m.name, m.due_date FROM project_milestones m
                           WHERE m.project_id IN ($ph) AND m.done_date IS NULL AND m.due_date >= UTC_DATE()
                        ORDER BY m.project_id, m.due_date, m.position, m.id");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['project_id'];
        if (isset($out[$pid]['next'])) continue;
        $out[$pid]['next'] = ['id' => (int)$r['id'], 'name' => $r['name'], 'due_date' => $r['due_date']];
    }
    return $out;
}
