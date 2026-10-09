<?php
/**
 * Projects - task dependencies and the critical path (3.3.0).
 *
 * A dependency is FINISH-TO-START: task_id cannot start until depends_on_id
 * has finished, plus lag_days. Both tasks belong to the same project (checked
 * when it is made; a task later moved out of the project is simply left out on
 * read). Everything else is worked out, never stored:
 *
 *   waiting_on  an OPEN task whose predecessor is not finished - "blocked by a
 *               dependency", in the status breakdown and on the Plan
 *   clash       the task is planned to start before its predecessor (plus the
 *               lag) is due to finish - the plan cannot be true as written
 *   critical    on the critical path: no slack. A forward pass (earliest start
 *               = the later of its own start and every predecessor's finish +
 *               lag + 1) and a backward pass from the latest finish; slack =
 *               latest start - earliest start. Only dated tasks take part.
 *
 * Cycles are refused when a dependency is made (projectDependencyMakesCycle).
 */

function projectDependenciesReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT 1 FROM task_dependencies LIMIT 0"); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/** A project's dependencies - both ends inside the project: [{id, task_id, depends_on_id, lag_days}]. */
function projectDependencies(PDO $conn, int $projectId): array
{
    if (!projectDependenciesReady($conn)) return [];
    $st = $conn->prepare("SELECT d.id, d.task_id, d.depends_on_id, d.lag_days
                            FROM task_dependencies d
                            JOIN tasks a ON a.id = d.task_id AND a.project_id = ?
                            JOIN tasks b ON b.id = d.depends_on_id AND b.project_id = ?
                        ORDER BY d.id");
    $st->execute([$projectId, $projectId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'task_id' => (int)$r['task_id'], 'depends_on_id' => (int)$r['depends_on_id'], 'lag_days' => (int)$r['lag_days']],
        $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Would task -> dependsOn close a loop (dependsOn already waits, directly or not, for task)? */
function projectDependencyMakesCycle(array $deps, int $taskId, int $dependsOnId): bool
{
    $preds = [];
    foreach ($deps as $d) $preds[$d['task_id']][] = $d['depends_on_id'];
    $stack = [$dependsOnId]; $seen = [];
    while ($stack) {
        $n = array_pop($stack);
        if ($n === $taskId) return true;
        if (isset($seen[$n])) continue;
        $seen[$n] = true;
        foreach ($preds[$n] ?? [] as $p) $stack[] = $p;
    }
    return false;
}

/**
 * Per task: depends_on [{id, lag}], waiting_on [ids], clash (bool), critical
 * (bool), slack (days or null). $tasks need id, start_date, due_date, is_closed.
 */
function projectDependencyAnalysis(array $tasks, array $deps): array
{
    $day = fn(?string $s) => $s ? (int)floor(strtotime(substr($s, 0, 10) . ' 00:00:00 UTC') / 86400) : null;
    $byId = [];
    foreach ($tasks as $t) {
        $s = $day($t['start_date'] ?? null) ?? $day($t['due_date'] ?? null);
        $e = $day($t['due_date'] ?? null) ?? $s;
        if ($s !== null && $e !== null && $s > $e) [$s, $e] = [$e, $s];
        $byId[(int)$t['id']] = ['s' => $s, 'e' => $e, 'closed' => !empty($t['is_closed']) && (int)$t['is_closed'] === 1];
    }
    $out = [];
    foreach ($byId as $id => $_) $out[$id] = ['depends_on' => [], 'waiting_on' => [], 'clash' => false, 'critical' => false, 'slack' => null];
    $preds = []; $succs = [];
    foreach ($deps as $d) {
        $a = $d['task_id']; $b = $d['depends_on_id'];
        if (!isset($byId[$a], $byId[$b])) continue;
        $preds[$a][] = [$b, $d['lag_days']];
        $succs[$b][] = [$a, $d['lag_days']];
        $out[$a]['depends_on'][] = ['id' => $b, 'lag' => $d['lag_days']];
        if (!$byId[$a]['closed'] && !$byId[$b]['closed']) $out[$a]['waiting_on'][] = $b;
        if ($byId[$a]['s'] !== null && $byId[$b]['e'] !== null && $byId[$a]['s'] <= $byId[$b]['e'] + $d['lag_days']) $out[$a]['clash'] = true;
    }
    // Critical path over the dated tasks: topological order first (Kahn).
    $dated = array_filter($byId, fn($x) => $x['s'] !== null);
    if (!$dated) return $out;
    $in = []; foreach ($dated as $id => $_) $in[$id] = 0;
    foreach ($dated as $id => $_) foreach ($preds[$id] ?? [] as [$p]) if (isset($dated[$p])) $in[$id]++;
    $queue = array_keys(array_filter($in, fn($n) => $n === 0)); $order = [];
    while ($queue) {
        $n = array_shift($queue); $order[] = $n;
        foreach ($succs[$n] ?? [] as [$s]) if (isset($dated[$s]) && --$in[$s] === 0) $queue[] = $s;
    }
    if (count($order) !== count($dated)) return $out;   // a loop slipped in: no critical path rather than a wrong one
    $es = []; $ef = [];
    foreach ($order as $id) {
        $d = $dated[$id]['e'] - $dated[$id]['s'];   // length in days, less one
        $start = $dated[$id]['s'];
        foreach ($preds[$id] ?? [] as [$p, $lag]) if (isset($ef[$p])) $start = max($start, $ef[$p] + $lag + 1);
        $es[$id] = $start; $ef[$id] = $start + $d;
    }
    $finish = max($ef);
    $lf = []; $ls = [];
    foreach (array_reverse($order) as $id) {
        $late = $finish;
        foreach ($succs[$id] ?? [] as [$s, $lag]) if (isset($ls[$s])) $late = min($late, $ls[$s] - $lag - 1);
        $lf[$id] = $late; $ls[$id] = $late - ($dated[$id]['e'] - $dated[$id]['s']);
    }
    foreach ($order as $id) {
        $out[$id]['slack'] = $ls[$id] - $es[$id];
        $out[$id]['critical'] = !$dated[$id]['closed'] && $out[$id]['slack'] <= 0;
    }
    return $out;
}
