<?php
/**
 * GET - the portfolio: every project this analyst may see, with progress and
 * health worked out from its tasks. Filters: q, status, mine=1.
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';

projectApiRun(function () use ($conn, $analystId) {
    $rows = projectListRows($conn, $analystId, [
        'q'      => $_GET['q'] ?? '',
        'status' => $_GET['status'] ?? '',
        'mine'   => !empty($_GET['mine']),
    ]);
    projectApiOk(['projects' => $rows, 'multi_company' => isMultiTenant($conn)]);
});
