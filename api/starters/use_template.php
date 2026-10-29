<?php
/**
 * POST {id, template_id} - give a case that has none a template, and add its
 * steps (or put its questions on the case's task first).
 */
require_once __DIR__ . '/../../includes/starters/api_bootstrap.php';

startersApiRequirePost();
startersApiRun(function () use ($conn, $ctx) {
    $in = startersApiBody();
    $warnings = [];
    StartersService::useTemplate($conn, $ctx, (int)($in['id'] ?? 0), (int)($in['template_id'] ?? 0), $warnings);
    startersApiOk(['warnings' => $warnings]);
});
