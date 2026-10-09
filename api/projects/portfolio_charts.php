<?php
/**
 * GET - what the portfolio's Charts view draws across projects (3.3.0), for the
 * projects this analyst may see (the same scope as list.php):
 *   budgets     {project_id: {planned, actual, forecast, currency}} - never added across currencies
 *   risks       [{project_id, title, probability, impact}] - open risks with both scores
 *   milestones  [{project_id, name, due_date, state}] - every milestone, with its state
 * Progress comes from list.php's own rows.
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';

projectApiRun(function () use ($conn, $analystId) {
    require_once __DIR__ . '/../../includes/projects/budget.php';
    require_once __DIR__ . '/../../includes/projects/milestones.php';
    $rows = projectListRows($conn, $analystId);
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $out = ['budgets' => (object)[], 'risks' => [], 'milestones' => []];
    if (!$ids) projectApiOk($out);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    // Budgets: totals per project in its own currency (projectBudgetTotals needs currency on each row).
    if (projectBudgetReady($conn)) {
        $st = $conn->prepare("SELECT id, currency FROM projects WHERE id IN ($ph)");
        $st->execute($ids);
        $byId = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byId[(int)$r['id']] = $r;
        $b = [];
        foreach (projectBudgetTotals($conn, $byId) as $pid => $t) {
            if (empty($t['has_budget']) && empty($t['actual'])) continue;
            $b[$pid] = ['planned' => round((float)$t['planned'], 2), 'actual' => round((float)$t['actual'], 2), 'forecast' => round((float)($t['forecast'] ?? $t['actual']), 2), 'currency' => $t['currency']];
        }
        $out['budgets'] = (object)$b;
    }
    // Open risks with a probability and an impact.
    try {
        $st = $conn->prepare("SELECT project_id, title, probability, impact FROM project_raid
                               WHERE project_id IN ($ph) AND type = 'risk' AND status = 'open' AND probability IS NOT NULL AND impact IS NOT NULL");
        $st->execute($ids);
        $out['risks'] = array_map(fn($r) => ['project_id' => (int)$r['project_id'], 'title' => $r['title'], 'probability' => (int)$r['probability'], 'impact' => (int)$r['impact']], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { /* before Database Verification */ }
    // Every milestone, with its state.
    if (projectMilestonesReady($conn)) {
        $st = $conn->prepare("SELECT project_id, name, due_date, done_date FROM project_milestones WHERE project_id IN ($ph) ORDER BY due_date");
        $st->execute($ids);
        $today = gmdate('Y-m-d');
        $out['milestones'] = array_map(fn($r) => ['project_id' => (int)$r['project_id'], 'name' => $r['name'], 'due_date' => $r['due_date'],
            'state' => projectMilestoneState($r, $today)], $st->fetchAll(PDO::FETCH_ASSOC));
    }
    projectApiOk($out);
});
