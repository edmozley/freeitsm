<?php
/**
 * Projects - benefits realisation (3.3.0): what a project is meant to improve,
 * measured from a baseline towards a target, and reviewed on a date.
 *
 * A BENEFIT says what improves (title), how it is measured (measure, unit),
 * which way is better (direction up / down), where it started (baseline),
 * where it should get to (target, by target_date), who owns it and when it is
 * next reviewed. MEASUREMENTS are dated values; the latest by date is "now".
 *
 * Its state is worked out, never stored (the same rule as health):
 *   closed        - somebody stopped reviewing it (status = closed)
 *   not_measured  - no measurement yet
 *   achieved      - the latest measurement reaches the target, in its direction
 *   missed        - the target date has passed without reaching it
 *   in_progress   - otherwise
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔑 BENEFITS OUTLIVE THE PROJECT. Most arrive after it closes, so reviews are
 * found by projectAlertsBenefits() for EVERY project that is not cancelled -
 * unlike the other alerts, which only look at live ones.
 *   project.benefit_review_due  once per benefit per review date (the ledger,
 *                               fingerprint = the date), to its owner and the
 *                               project manager (project_benefit_notify).
 * Recording a measurement on or after (review date - 14 days) moves the next
 * review on by the benefit's review_months; 0 / NULL means no repeat.
 */

require_once __DIR__ . '/settings.php';

const PROJECT_BENEFIT_EARLY_DAYS = 14;   // a measurement this close before the review counts as the review

/** Has Database Verification created the tables? */
function projectBenefitsReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT 1 FROM project_benefits LIMIT 0"); $conn->query("SELECT 1 FROM project_benefit_measures LIMIT 0"); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/** The worked-out state and progress of one benefit, given its latest value. */
function projectBenefitState(array $b, ?float $now, ?string $today = null): array
{
    $today = $today ?? gmdate('Y-m-d');
    $base = $b['baseline_value'] !== null ? (float)$b['baseline_value'] : null;
    $target = $b['target_value'] !== null ? (float)$b['target_value'] : null;
    $up = ($b['direction'] ?? 'up') !== 'down';
    $pct = null;
    if ($now !== null && $base !== null && $target !== null && $target != $base) {
        $pct = (int)round(max(0, min(1.5, ($now - $base) / ($target - $base))) * 100);
    }
    $reached = $now !== null && $target !== null && ($up ? $now >= $target : $now <= $target);
    if (($b['status'] ?? 'open') === 'closed') $state = 'closed';
    elseif ($now === null) $state = 'not_measured';
    elseif ($reached) $state = 'achieved';
    elseif (!empty($b['target_date']) && $b['target_date'] < $today) $state = 'missed';
    else $state = 'in_progress';
    return [
        'state' => $state,
        'progress' => $pct,
        'review_due' => ($b['status'] ?? 'open') === 'open' && !empty($b['review_date']) && $b['review_date'] <= $today,
    ];
}

/** One project's benefits with their measurements, state and progress - for its Benefits tab. */
function projectBenefits(PDO $conn, int $projectId): array
{
    if (!projectBenefitsReady($conn)) return [];
    $st = $conn->prepare("SELECT b.*, a.full_name AS owner_name FROM project_benefits b
                       LEFT JOIN analysts a ON a.id = b.owner_analyst_id
                           WHERE b.project_id = ? ORDER BY b.position, b.id");
    $st->execute([$projectId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $m = $conn->prepare("SELECT m.*, a.full_name AS recorded_by_name FROM project_benefit_measures m
                    LEFT JOIN analysts a ON a.id = m.recorded_by_id
                        WHERE m.benefit_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                     ORDER BY m.measured_date, m.id");
    $m->execute($ids);
    $by = [];
    foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $by[(int)$x['benefit_id']][] = ['id' => (int)$x['id'], 'value' => (float)$x['value'], 'measured_date' => $x['measured_date'],
            'note' => $x['note'], 'recorded_by_name' => $x['recorded_by_name']];
    }
    $out = [];
    foreach ($rows as $r) {
        $list = $by[(int)$r['id']] ?? [];
        $now = $list ? end($list)['value'] : null;
        $out[] = [
            'id' => (int)$r['id'], 'title' => $r['title'], 'measure' => $r['measure'], 'unit' => $r['unit'], 'direction' => $r['direction'],
            'baseline_value' => $r['baseline_value'] !== null ? (float)$r['baseline_value'] : null,
            'target_value' => $r['target_value'] !== null ? (float)$r['target_value'] : null,
            'target_date' => $r['target_date'], 'owner_analyst_id' => $r['owner_analyst_id'] !== null ? (int)$r['owner_analyst_id'] : null,
            'owner_name' => $r['owner_name'], 'review_date' => $r['review_date'],
            'review_months' => $r['review_months'] !== null ? (int)$r['review_months'] : null,
            'status' => $r['status'], 'notes' => $r['notes'], 'current' => $now,
            'measures' => $list,
        ] + projectBenefitState($r, $now);
    }
    return $out;
}

/** Per project: benefits open and reviews due - for the Overview, the card and the assistant. */
function projectBenefitStats(PDO $conn, array $projectIds): array
{
    $out = [];
    if (!$projectIds || !projectBenefitsReady($conn)) return $out;
    $ids = array_map('intval', $projectIds);
    $st = $conn->prepare("SELECT project_id, COUNT(*) AS n,
                                 SUM(CASE WHEN status = 'open' AND review_date IS NOT NULL AND review_date <= UTC_DATE() THEN 1 ELSE 0 END) AS due
                            FROM project_benefits WHERE project_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") GROUP BY project_id");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['project_id']] = ['benefits' => (int)$r['n'], 'benefits_due' => (int)$r['due']];
    return $out;
}

/** The next review after $from, $months on; null when it does not repeat. */
function projectBenefitNextReview(string $from, ?int $months): ?string
{
    if (!$months || $months <= 0) return null;
    $d = new DateTime($from . ' 00:00:00', new DateTimeZone('UTC'));
    $d->modify('+' . $months . ' months');
    return $d->format('Y-m-d');
}

/** Who is reminded about a benefit's review (project_benefit_notify). */
function projectBenefitNotifyIds(PDO $conn, ?int $ownerId, ?int $pmId): array
{
    $ids = projectSetting($conn, 'project_benefit_notify') === 'owner' ? [$ownerId ?: $pmId] : [$ownerId, $pmId];
    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}

/**
 * Reviews that have fallen due -> project.benefit_review_due, once per benefit
 * per review date. Every project that is not cancelled: benefits outlive it.
 * Called from projectAlertsScan(); quiet before Database Verification.
 */
function projectAlertsBenefits(PDO $conn, ?int $projectId = null): int
{
    if (!projectBenefitsReady($conn)) return 0;
    require_once __DIR__ . '/alerts.php';
    $sql = "SELECT b.id AS b_id, b.title AS b_title, b.measure, b.unit, b.review_date, b.owner_analyst_id AS b_owner, b.target_value, b.baseline_value, b.direction,
                   p.id, p.tenant_id, p.name, p.methodology, p.status, p.health, p.owner_analyst_id, a.full_name AS owner_name,
                   p.start_date, p.target_end_date
              FROM project_benefits b
              JOIN projects p ON p.id = b.project_id
         LEFT JOIN analysts a ON a.id = p.owner_analyst_id
             WHERE b.status = 'open' AND b.review_date IS NOT NULL AND b.review_date <= UTC_DATE()
               AND b.review_date >= DATE_SUB(UTC_DATE(), INTERVAL 30 DAY)
               AND p.status <> 'cancelled'";
    $args = [];
    if ($projectId !== null) { $sql .= ' AND p.id = ?'; $args[] = $projectId; }
    $st = $conn->prepare($sql);
    $st->execute($args);
    $claim = $conn->prepare("INSERT IGNORE INTO workflow_scheduled_emissions (trigger_event, entity_key, fingerprint, emitted_datetime) VALUES ('project.benefit_review_due', ?, ?, UTC_TIMESTAMP())");
    $fired = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        try {
            $claim->execute(['project_benefit:' . (int)$r['b_id'] . ':review', (string)$r['review_date']]);
        } catch (Throwable $e) {
            return $fired;   // no ledger: firing without one would repeat every run
        }
        if ($claim->rowCount() !== 1) continue;
        // TRAP: a review falling due is nobody's action. This scan also runs inside
        // somebody's request (afterChange), and the bell never tells you about your
        // own action - so it is dispatched as the system, or the project manager who
        // happened to close the project would never hear that a review is due.
        projectAlertsAsSystem(fn() => projectDispatch('project.benefit_review_due', [
            'project' => projectEventPayload($r),
            'benefit' => ['id' => (int)$r['b_id'], 'title' => $r['b_title'], 'measure' => $r['measure'], 'unit' => $r['unit'], 'review_date' => $r['review_date'],
                          'baseline_value' => $r['baseline_value'] !== null ? (float)$r['baseline_value'] : null,
                          'target_value' => $r['target_value'] !== null ? (float)$r['target_value'] : null,
                          'owner_analyst_id' => $r['b_owner'] !== null ? (int)$r['b_owner'] : null],
            'notify_ids' => projectBenefitNotifyIds($conn, $r['b_owner'] !== null ? (int)$r['b_owner'] : null, $r['owner_analyst_id'] !== null ? (int)$r['owner_analyst_id'] : null),
        ]));
        $fired++;
    }
    return $fired;
}
