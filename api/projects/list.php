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
    projectApiOk(['projects' => $rows, 'multi_company' => isMultiTenant($conn), 'can_create' => projectCanCreate($conn, $analystId)]);
});
