<?php
/**
 * Projects - task flow over time (3.3.0): the cumulative flow diagram.
 *
 * Nothing records when a task changes status, so the flow is SNAPSHOTTED: how
 * many of a project's tasks sit in each status, once per day per project
 * (project_task_flow). Today's row is rewritten every time somebody looks
 * (the project page) and every alert scan (cron hourly, or the portfolio's
 * fallback), so the last write of the day stands for the day. Days that
 * nobody and nothing looked at are carried forward on read.
 *
 * 🔑 BEFORE THE FIRST SNAPSHOT only two things are known about a task: when it
 * was created and when it was finished. Those days are RECONSTRUCTED as two
 * bands - finished, and "open (status not recorded)" - and the chart marks the
 * day real history starts. Never invent the statuses in between.
 */

function projectFlowReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT 1 FROM project_task_flow LIMIT 0"); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/** Record today's counts by status for these projects (top-level tasks). Quiet: never breaks its caller. */
function projectFlowSnapshot(PDO $conn, array $projectIds): void
{
    if (!$projectIds || !projectFlowReady($conn)) return;
    try {
        $ids = array_map('intval', $projectIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $conn->prepare("SELECT project_id, COALESCE(status_id, 0) AS s, COUNT(*) AS n FROM tasks
                               WHERE project_id IN ($ph) AND parent_task_id IS NULL GROUP BY project_id, COALESCE(status_id, 0)");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $conn->prepare("DELETE FROM project_task_flow WHERE day = UTC_DATE() AND project_id IN ($ph)")->execute($ids);
        $ins = $conn->prepare("INSERT INTO project_task_flow (project_id, day, status_id, task_count) VALUES (?, UTC_DATE(), ?, ?)");
        foreach ($rows as $r) $ins->execute([(int)$r['project_id'], (int)$r['s'], (int)$r['n']]);
    } catch (Throwable $e) {
        error_log('projects flow snapshot: ' . $e->getMessage());
    }
}

/**
 * The flow for one project's chart:
 *   statuses  [{id, name, closed}] in board order (the install's task statuses)
 *   points    [{d, counts: {status_id|'open'|'done': n}, recorded: bool}]
 *   from      the first recorded day (null = none yet)
 */
function projectFlow(PDO $conn, array $project): array
{
    $pid = (int)$project['id'];
    $statuses = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'closed' => (int)$r['is_closed'] === 1],
        $conn->query("SELECT id, name, is_closed FROM task_statuses ORDER BY display_order, id")->fetchAll(PDO::FETCH_ASSOC));
    $rec = [];
    if (projectFlowReady($conn)) {
        $st = $conn->prepare("SELECT day, status_id, task_count FROM project_task_flow WHERE project_id = ? ORDER BY day");
        $st->execute([$pid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rec[$r['day']][(int)$r['status_id']] = (int)$r['task_count'];
    }
    $from = $rec ? array_key_first($rec) : null;
    $today = gmdate('Y-m-d');
    // Where the chart starts: the project's start, or its first task.
    $t = $conn->prepare("SELECT MIN(DATE(created_datetime)) FROM tasks WHERE project_id = ? AND parent_task_id IS NULL");
    $t->execute([$pid]);
    $start = min(array_filter([$project['start_date'] ?? null, $t->fetchColumn() ?: null, $from ?? $today])) ?: $today;
    $days = (int)round((strtotime($today) - strtotime($start)) / 86400);
    $step = $days > 120 ? 7 : 1;

    // Before history: created and finished only.
    $tasks = [];
    if (!$from || $start < $from) {
        $q = $conn->prepare("SELECT DATE(created_datetime) AS c, DATE(completed_datetime) AS f FROM tasks WHERE project_id = ? AND parent_task_id IS NULL");
        $q->execute([$pid]);
        $tasks = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    $points = []; $carry = null;
    for ($i = 0; $i <= $days; $i += $step) {
        $d = gmdate('Y-m-d', strtotime($start . ' 00:00:00 UTC') + $i * 86400);
        if ($i + $step > $days) $d = $today;   // the last point is always today
        if ($from && $d >= $from) {
            // Recorded: the day itself, or the last recorded day before it.
            foreach ($rec as $day => $c) { if ($day <= $d) $carry = $c; else break; }
            $points[] = ['d' => $d, 'counts' => (object)($carry ?? []), 'recorded' => true];
        } else {
            $open = 0; $done = 0;
            foreach ($tasks as $x) {
                if (!$x['c'] || $x['c'] > $d) continue;
                if ($x['f'] && $x['f'] <= $d) $done++; else $open++;
            }
            $points[] = ['d' => $d, 'counts' => (object)['open' => $open, 'done' => $done], 'recorded' => false];
        }
        if ($d === $today) break;
    }
    return ['statuses' => $statuses, 'points' => $points, 'from' => $from];
}
