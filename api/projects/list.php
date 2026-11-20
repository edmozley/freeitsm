<?php
/**
 * GET - the portfolio: every project this analyst may see, with progress and
 * health worked out from its tasks. Filters: q, status, mine=1.
 *
 * Also the no-cron fallback for project alerts: at most every 15 minutes,
 * opening the portfolio runs the scan (includes/projects/alerts.php).
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';

projectApiRun(function () use ($conn, $analystId) {
    require_once __DIR__ . '/../../includes/projects/alerts.php';
    projectAlertsOpportunistic($conn);
    $rows = projectListRows($conn, $analystId, [
        'q'      => $_GET['q'] ?? '',
        'status' => $_GET['status'] ?? '',
        'mine'   => !empty($_GET['mine']),
    ]);
    require_once __DIR__ . '/../../includes/projects/settings.php';
    $out = ['projects' => $rows, 'multi_company' => isMultiTenant($conn), 'can_create' => projectCanCreate($conn, $analystId)];
    // Watchtower's "stages end in the next 7 days" links to the portfolio
    // (discussion #388): how many such stages each project has, by project id.
    // The SAME conditions as the card in includes/watchtower_queries.php (stage
    // not closed, end_date from today to today + 7, the naive "today"); the page
    // keeps the card's live = proposed or active rule. Only the projects this
    // analyst can already see are asked about, so it never widens the list.
    if (!empty($_GET['stages_week'])) {
        require_once __DIR__ . '/../../includes/timezone.php';
        $today = naive_today_sql();
        $ids = array_map('intval', array_column($rows, 'id'));
        $out['stages_week'] = new stdClass();
        if ($ids) {
            $st = $conn->query(
                "SELECT project_id, COUNT(*) FROM project_stages
                  WHERE project_id IN (" . implode(',', $ids) . ") AND status <> 'closed'
                    AND end_date BETWEEN {$today} AND DATE_ADD({$today}, INTERVAL 7 DAY)
                  GROUP BY project_id");
            $week = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
            // ...and milestones due in the same 7 days: the card's number is PROJECTS with a stage
            // ending or a milestone due (includes/watchtower_queries.php - change both together).
            try {
                $ms = $conn->query(
                    "SELECT project_id, COUNT(*) FROM project_milestones
                      WHERE project_id IN (" . implode(',', $ids) . ") AND done_date IS NULL
                        AND due_date BETWEEN {$today} AND DATE_ADD({$today}, INTERVAL 7 DAY)
                      GROUP BY project_id");
                foreach ($ms->fetchAll(PDO::FETCH_KEY_PAIR) as $pid => $n) $week[(int)$pid] = ($week[(int)$pid] ?? 0) + (int)$n;
            } catch (Throwable $e) { /* no milestones table yet */ }
            $out['stages_week'] = (object)$week;
        }
    }
    projectApiOk($out);
});
