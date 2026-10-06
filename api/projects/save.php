<?php
/**
 * POST - create (no id) or update (id) a project. Thin adapter over
 * ProjectsService::saveProject(). A new project goes in the analyst's active
 * company unless the body names another they can reach (company_id).
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
projectApiRequirePost();

projectApiRun(function () use ($conn, $ctx, $analystId) {
    $in = projectApiBody();
    $isNew = empty($in['id']);
    $tenant = $isNew ? projectApiTenantForCreate($conn, $analystId, $in['company_id'] ?? null) : null;
    unset($in['company_id']);
    $res = ProjectsService::saveProject($conn, $ctx, $in, $tenant);
    projectApiOk(['id' => $res['id'], 'created' => $res['created']]);
});
