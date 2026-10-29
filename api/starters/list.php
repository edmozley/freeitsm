<?php
/**
 * GET ?kind=&status= - the cases this analyst may see, with progress read from Tasks.
 */
require_once __DIR__ . '/../../includes/starters/api_bootstrap.php';

startersApiRun(function () use ($conn, $analystId) {
    startersApiOk(['cases' => StartersService::listCases($conn, $analystId, [
        'kind'   => (string)($_GET['kind'] ?? ''),
        'status' => (string)($_GET['status'] ?? ''),
    ])]);
});
