<?php
/**
 * Projects - change control (3.3.0): baselines and change requests.
 *
 * A BASELINE is the plan as it stood when it was agreed - start and target
 * finish, the planned budget, the work (tasks and estimated hours), the Must
 * scope, and in detail every stage, milestone, Must item and budget line. It is
 * never edited: a new one is taken instead, numbered 1, 2, 3. Comparing the
 * latest with the plan as it is NOW shows the drift, item by item.
 *
 * A CHANGE REQUEST asks to change the agreed plan and says what it does to time
 * (days, + later), cost (+ more, - a saving) and scope (words). It is proposed,
 * then approved or rejected by somebody allowed to (a setting), or withdrawn by
 * whoever raised it. It is never deleted - the requests ARE the audit trail of
 * why the plan moved.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE JUDGEMENT CALLS ARE SETTINGS (Ed's rule: "where different people will
 * want it to work slightly differently, make it configurable"):
 *   project_baseline_auto     off | start | stage - take one by itself when the
 *                             project goes active, or then AND each stage start
 *   project_change_approver   team | owner | managers - who may decide
 *   project_change_self       may the person who raised a request decide it
 *   project_change_apply      baseline | plan - approving only takes a new
 *                             baseline, or ALSO moves the target finish by the
 *                             days and adds the cost as a budget line
 * 🔑 Approving ALWAYS takes a new baseline: that is what "approved" means - the
 * changed plan becomes the agreed one. Rejecting changes nothing.
 * TRAP: the variance is worked out against the plan NOW, so a project manager
 * who moved the target date by hand before the request was approved sees the
 * drift on the old baseline straight away - which is the point.
 */

require_once __DIR__ . '/settings.php';

const PROJECT_CR_STATUSES = ['proposed', 'approved', 'rejected', 'withdrawn'];

/** Has Database Verification created the tables? Everything here is quiet before it. */
function projectControlReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT 1 FROM project_baselines LIMIT 0"); $conn->query("SELECT 1 FROM project_change_requests LIMIT 0"); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/**
 * The plan as it is now, in the shape a baseline stores: the headline figures
 * plus the detail, so the same function makes a baseline and the "now" side of
 * every comparison.
 */
function projectPlanSnapshot(PDO $conn, int $projectId): array
{
    $p = $conn->prepare("SELECT start_date, target_end_date, currency FROM projects WHERE id = ?");
    $p->execute([$projectId]);
    $row = $p->fetch(PDO::FETCH_ASSOC) ?: ['start_date' => null, 'target_end_date' => null, 'currency' => null];

    $t = $conn->prepare("SELECT COUNT(*) AS n, SUM(estimate_hours) AS h FROM tasks WHERE project_id = ? AND parent_task_id IS NULL");
    try { $t->execute([$projectId]); $tasks = $t->fetch(PDO::FETCH_ASSOC); }
    catch (Throwable $e) {   // before estimates existed
        $t = $conn->prepare("SELECT COUNT(*) AS n, NULL AS h FROM tasks WHERE project_id = ? AND parent_task_id IS NULL");
        $t->execute([$projectId]); $tasks = $t->fetch(PDO::FETCH_ASSOC);
    }

    $stages = $conn->prepare("SELECT id, name, start_date, end_date FROM project_stages WHERE project_id = ? ORDER BY position, id");
    $stages->execute([$projectId]);
    $stages = array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'start_date' => $s['start_date'], 'end_date' => $s['end_date']], $stages->fetchAll(PDO::FETCH_ASSOC));

    $milestones = [];
    try {
        $m = $conn->prepare("SELECT id, name, due_date FROM project_milestones WHERE project_id = ? ORDER BY due_date, position, id");
        $m->execute([$projectId]);
        $milestones = array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'due_date' => $x['due_date']], $m->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { /* no milestones yet */ }

    $must = [];
    try {
        $i = $conn->prepare("SELECT id, title FROM project_items WHERE project_id = ? AND moscow = 'must' AND status <> 'dropped' ORDER BY position, id");
        $i->execute([$projectId]);
        $must = array_map(fn($x) => ['id' => (int)$x['id'], 'title' => $x['title']], $i->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { /* no scope yet */ }

    $lines = []; $planned = null;
    try {
        $l = $conn->prepare("SELECT id, title, planned_amount FROM project_budget_lines WHERE project_id = ? ORDER BY position, id");
        $l->execute([$projectId]);
        foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $amt = $x['planned_amount'] !== null ? (float)$x['planned_amount'] : null;
            $lines[] = ['id' => (int)$x['id'], 'title' => $x['title'], 'planned' => $amt];
            if ($amt !== null) $planned = ($planned ?? 0) + $amt;
        }
    } catch (Throwable $e) { /* no budget yet */ }

    return [
        'start_date'      => $row['start_date'],
        'target_end_date' => $row['target_end_date'],
        'budget_planned'  => $planned !== null ? round($planned, 2) : null,
        'currency'        => $row['currency'],
        'task_count'      => (int)($tasks['n'] ?? 0),
        'estimate_hours'  => $tasks['h'] !== null ? round((float)$tasks['h'], 2) : null,
        'must_count'      => count($must),
        'stages'          => $stages,
        'milestones'      => $milestones,
        'must'            => $must,
        'lines'           => $lines,
    ];
}

/** Take a baseline of the plan as it is now. Returns its id. */
function projectTakeBaseline(PDO $conn, int $projectId, ?int $actorId, string $reason, ?string $label, ?int $stageId = null, ?int $changeId = null): int
{
    $s = projectPlanSnapshot($conn, $projectId);
    $n = (int)$conn->query("SELECT COALESCE(MAX(number), 0) + 1 FROM project_baselines WHERE project_id = " . $projectId)->fetchColumn();
    $conn->prepare("INSERT INTO project_baselines (project_id, number, label, reason, stage_id, change_request_id, start_date, target_end_date, budget_planned, currency,
                                                   task_count, estimate_hours, must_count, snapshot, created_by_id, created_datetime)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$projectId, $n, $label, $reason, $stageId, $changeId, $s['start_date'], $s['target_end_date'], $s['budget_planned'], $s['currency'],
                    $s['task_count'], $s['estimate_hours'], $s['must_count'],
                    json_encode(['stages' => $s['stages'], 'milestones' => $s['milestones'], 'must' => $s['must'], 'lines' => $s['lines']], JSON_UNESCAPED_UNICODE),
                    $actorId && $actorId > 0 ? $actorId : null]);
    return (int)$conn->lastInsertId();
}

/** Whole days from $a to $b (both Y-m-d); null if either is missing. */
function projectDaysBetween(?string $a, ?string $b): ?int
{
    if (!$a || !$b) return null;
    return (int)round((strtotime($b . ' 00:00:00 UTC') - strtotime($a . ' 00:00:00 UTC')) / 86400);
}

/**
 * How the plan NOW differs from a baseline. Headline figures, then the detail
 * by item: moved (days), added (in the plan now, not in the baseline) and
 * removed (in the baseline, gone now). Items are matched by id, so a renamed
 * milestone is the same milestone.
 */
function projectBaselineVariance(array $base, array $now): array
{
    $num = function ($b, $n) {
        if ($b === null && $n === null) return null;
        return round((float)($n ?? 0) - (float)($b ?? 0), 2);
    };
    $out = [
        'start_days'   => projectDaysBetween($base['start_date'] ?? null, $now['start_date'] ?? null),
        'finish_days'  => projectDaysBetween($base['target_end_date'] ?? null, $now['target_end_date'] ?? null),
        'budget'       => $num($base['budget_planned'] ?? null, $now['budget_planned'] ?? null),
        'budget_pct'   => !empty($base['budget_planned']) && $now['budget_planned'] !== null
                            ? (int)round(($now['budget_planned'] / $base['budget_planned'] - 1) * 100) : null,
        'tasks'        => (int)$now['task_count'] - (int)$base['task_count'],
        'hours'        => $num($base['estimate_hours'] ?? null, $now['estimate_hours'] ?? null),
        'must'         => (int)$now['must_count'] - (int)$base['must_count'],
        // The detail.
        'milestones'   => projectVarianceItems($base['milestones'] ?? [], $now['milestones'] ?? [], 'due_date'),
        'stages'       => projectVarianceItems($base['stages'] ?? [], $now['stages'] ?? [], 'end_date'),
        'must_added'   => [], 'must_removed' => [],
        'lines'        => [],
    ];
    $bm = array_column($base['must'] ?? [], 'title', 'id');
    $nm = array_column($now['must'] ?? [], 'title', 'id');
    foreach ($nm as $id => $t) if (!isset($bm[$id])) $out['must_added'][] = $t;
    foreach ($bm as $id => $t) if (!isset($nm[$id])) $out['must_removed'][] = $t;
    // Budget lines whose planned amount moved, arrived or went.
    $bl = []; foreach ($base['lines'] ?? [] as $l) $bl[$l['id']] = $l;
    $nl = []; foreach ($now['lines'] ?? [] as $l) $nl[$l['id']] = $l;
    foreach ($nl as $id => $l) {
        if (!isset($bl[$id])) { $out['lines'][] = ['title' => $l['title'], 'kind' => 'added', 'from' => null, 'to' => $l['planned']]; continue; }
        if ((float)($bl[$id]['planned'] ?? 0) !== (float)($l['planned'] ?? 0)) $out['lines'][] = ['title' => $l['title'], 'kind' => 'moved', 'from' => $bl[$id]['planned'], 'to' => $l['planned']];
    }
    foreach ($bl as $id => $l) if (!isset($nl[$id])) $out['lines'][] = ['title' => $l['title'], 'kind' => 'removed', 'from' => $l['planned'], 'to' => null];
    return $out;
}

/** Dated items compared by id on one date column: [{name, kind: moved|added|removed, from, to, days}]. */
function projectVarianceItems(array $base, array $now, string $col): array
{
    $out = [];
    $b = []; foreach ($base as $x) $b[$x['id']] = $x;
    $n = []; foreach ($now as $x) $n[$x['id']] = $x;
    foreach ($n as $id => $x) {
        if (!isset($b[$id])) { $out[] = ['name' => $x['name'], 'kind' => 'added', 'from' => null, 'to' => $x[$col], 'days' => null]; continue; }
        $d = projectDaysBetween($b[$id][$col], $x[$col]);
        if ($b[$id][$col] !== $x[$col]) $out[] = ['name' => $x['name'], 'kind' => 'moved', 'from' => $b[$id][$col], 'to' => $x[$col], 'days' => $d];
    }
    foreach ($b as $id => $x) if (!isset($n[$id])) $out[] = ['name' => $x['name'], 'kind' => 'removed', 'from' => $x[$col], 'to' => null, 'days' => null];
    return $out;
}

/** A stored baseline row in the snapshot shape. */
function projectBaselineShape(array $r): array
{
    $snap = json_decode((string)($r['snapshot'] ?? ''), true) ?: [];
    return [
        'start_date' => $r['start_date'], 'target_end_date' => $r['target_end_date'],
        'budget_planned' => $r['budget_planned'] !== null ? (float)$r['budget_planned'] : null, 'currency' => $r['currency'],
        'task_count' => (int)$r['task_count'], 'estimate_hours' => $r['estimate_hours'] !== null ? (float)$r['estimate_hours'] : null,
        'must_count' => (int)$r['must_count'],
        'stages' => $snap['stages'] ?? [], 'milestones' => $snap['milestones'] ?? [], 'must' => $snap['must'] ?? [], 'lines' => $snap['lines'] ?? [],
    ];
}

/**
 * May this analyst approve or reject a change request? The setting says who
 * (team = anyone who may change the project; owner = its project manager or
 * Manage Projects; managers = Manage Projects only), and project_change_self
 * whether the person who raised it may decide it too.
 */
function projectCanDecideChange(PDO $conn, int $analystId, array $project, ?int $raisedBy = null): bool
{
    if ($analystId <= 0) return false;
    if ($raisedBy !== null && $raisedBy === $analystId && projectSetting($conn, 'project_change_self') !== '1') return false;
    switch (projectSetting($conn, 'project_change_approver')) {
        case 'team':     return projectCanChange($conn, $analystId, $project);
        case 'managers': return projectIsManager($conn, $analystId);
        default:         return (int)($project['owner_analyst_id'] ?? 0) === $analystId || projectIsManager($conn, $analystId);
    }
}

/**
 * Take a baseline by itself when the setting says so - never twice for the same
 * stage, and once only for the project starting. Quiet: it must never break the
 * change that triggered it.
 *   $trigger 'start' - the project went active; 'stage' - $stageId started
 */
function projectBaselineAuto(PDO $conn, int $projectId, ?int $actorId, string $trigger, ?int $stageId = null): ?int
{
    try {
        if (!projectControlReady($conn)) return null;
        $mode = projectSetting($conn, 'project_baseline_auto');
        if ($mode === 'off' || ($trigger === 'stage' && $mode !== 'stage')) return null;
        $p = $conn->prepare("SELECT * FROM projects WHERE id = ?");
        $p->execute([$projectId]);
        $project = $p->fetch(PDO::FETCH_ASSOC);
        if (!$project || !in_array('control', projectEnabledTools($project), true)) return null;
        if ($trigger === 'stage') {
            $q = $conn->prepare("SELECT 1 FROM project_baselines WHERE project_id = ? AND stage_id = ? AND reason = 'stage' LIMIT 1");
            $q->execute([$projectId, $stageId]);
            if ($q->fetchColumn()) return null;
        } else {
            $q = $conn->prepare("SELECT 1 FROM project_baselines WHERE project_id = ? AND reason = 'start' LIMIT 1");
            $q->execute([$projectId]);
            if ($q->fetchColumn()) return null;
        }
        $id = projectTakeBaseline($conn, $projectId, $actorId, $trigger, null, $trigger === 'stage' ? $stageId : null);
        require_once __DIR__ . '/../services/projects.php';
        ProjectsService::audit($conn, $projectId, $actorId, 'baseline_taken', null, 'Baseline ' . (int)$conn->query("SELECT number FROM project_baselines WHERE id = " . $id)->fetchColumn(), 'app');
        return $id;
    } catch (Throwable $e) {
        error_log('projects baseline auto: ' . $e->getMessage());
        return null;
    }
}

/** Per project: change requests waiting for a decision - for the Overview, the card and the assistant. */
function projectChangeStats(PDO $conn, array $projectIds): array
{
    $out = [];
    if (!$projectIds || !projectControlReady($conn)) return $out;
    $ids = array_map('intval', $projectIds);
    $st = $conn->prepare("SELECT project_id, COUNT(*) AS n FROM project_change_requests
                           WHERE project_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND status = 'proposed' GROUP BY project_id");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['project_id']] = (int)$r['n'];
    return $out;
}

/** Everything the Change control tab shows. null before Database Verification. */
function projectControlDetail(PDO $conn, array $project, int $analystId): ?array
{
    if (!projectControlReady($conn)) return null;
    $pid = (int)$project['id'];
    $now = projectPlanSnapshot($conn, $pid);
    $st = $conn->prepare("SELECT b.*, a.full_name AS created_by_name, s.name AS stage_name, c.number AS change_number
                            FROM project_baselines b
                       LEFT JOIN analysts a ON a.id = b.created_by_id
                       LEFT JOIN project_stages s ON s.id = b.stage_id
                       LEFT JOIN project_change_requests c ON c.id = b.change_request_id
                           WHERE b.project_id = ? ORDER BY b.number DESC");
    $st->execute([$pid]);
    $baselines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $shape = projectBaselineShape($r);
        $baselines[] = [
            'id' => (int)$r['id'], 'number' => (int)$r['number'], 'label' => $r['label'], 'reason' => $r['reason'],
            'stage_name' => $r['stage_name'], 'change_number' => $r['change_number'] !== null ? (int)$r['change_number'] : null,
            'created_by_name' => $r['created_by_name'], 'created_datetime' => $r['created_datetime'],
            'plan' => array_intersect_key($shape, array_flip(['start_date', 'target_end_date', 'budget_planned', 'currency', 'task_count', 'estimate_hours', 'must_count'])),
            'variance' => projectBaselineVariance($shape, $now),
        ];
    }
    $st = $conn->prepare("SELECT c.*, r.full_name AS raised_by_name, d.full_name AS decided_by_name, b.number AS baseline_number
                            FROM project_change_requests c
                       LEFT JOIN analysts r ON r.id = c.raised_by_id
                       LEFT JOIN analysts d ON d.id = c.decided_by_id
                       LEFT JOIN project_baselines b ON b.id = c.baseline_id
                           WHERE c.project_id = ? ORDER BY c.number DESC");
    $st->execute([$pid]);
    $canChange = projectCanChange($conn, $analystId, $project);
    $requests = array_map(function ($r) use ($conn, $analystId, $project, $canChange) {
        $raisedBy = $r['raised_by_id'] !== null ? (int)$r['raised_by_id'] : null;
        $open = $r['status'] === 'proposed';
        return [
            'id' => (int)$r['id'], 'number' => (int)$r['number'], 'title' => $r['title'], 'description' => $r['description'], 'reason' => $r['reason'],
            'impact_days' => $r['impact_days'] !== null ? (int)$r['impact_days'] : null,
            'impact_cost' => $r['impact_cost'] !== null ? (float)$r['impact_cost'] : null,
            'impact_scope' => $r['impact_scope'], 'status' => $r['status'],
            'raised_by_name' => $r['raised_by_name'], 'raised_datetime' => $r['raised_datetime'],
            'decided_by_name' => $r['decided_by_name'], 'decided_datetime' => $r['decided_datetime'], 'decision_notes' => $r['decision_notes'],
            'applied' => $r['applied'] ? json_decode($r['applied'], true) : null,
            'baseline_number' => $r['baseline_number'] !== null ? (int)$r['baseline_number'] : null,
            // What this analyst may do with it - the server checks again.
            'can_decide' => $open && projectCanDecideChange($conn, $analystId, $project, $raisedBy),
            'can_edit'   => $open && ($raisedBy === $analystId || $canChange),
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
    return [
        'now'        => array_intersect_key($now, array_flip(['start_date', 'target_end_date', 'budget_planned', 'currency', 'task_count', 'estimate_hours', 'must_count'])),
        'baselines'  => $baselines,
        'requests'   => $requests,
        'apply'      => projectSetting($conn, 'project_change_apply'),
        'approver'   => projectSetting($conn, 'project_change_approver'),
        'auto'       => projectSetting($conn, 'project_baseline_auto'),
        'can_decide' => projectCanDecideChange($conn, $analystId, $project),
    ];
}
