<?php
/**
 * POST {kind, effective_date, template_id?, user_id | person_name+person_email,
 *       job_title?, manager_user_id?, owner_analyst_id?, notes?} - open a case.
 * StartersService makes the work in Tasks and tells Workflows. A step that could
 * not be added comes back in `warnings`; the case is still made.
 */
require_once __DIR__ . '/../../includes/starters/api_bootstrap.php';

startersApiRequirePost();
startersApiRun(function () use ($conn, $ctx) {
    $warnings = [];
    $id = StartersService::createCase($conn, $ctx, startersApiBody(), $warnings);
    startersApiOk(['id' => $id, 'warnings' => $warnings]);
});
