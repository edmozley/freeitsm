<?php
/**
 * GET ?weeks=4|8|12 - capacity (3.3.0): each person's load in the weeks ahead,
 * project work against service-desk duty. Read-only; the rules are in
 * includes/projects/capacity.php.
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';

projectApiRun(function () use ($conn, $analystId) {
    require_once __DIR__ . '/../../includes/projects/capacity.php';
    $weeks = (int)($_GET['weeks'] ?? 4);
    if (!in_array($weeks, [4, 8, 12], true)) $weeks = 4;
    projectApiOk(['capacity' => projectCapacity($conn, $analystId, $weeks)]);
});
