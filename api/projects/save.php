<?php
/**
 * POST - create (no id) or update (id) a project. Thin adapter over
 * ProjectsService::saveProject(). A new project goes in the analyst's active
 * company unless the body names another they can reach (company_id). A new
 * project with `template` ("builtin:office_move", "saved:12") is built from
 * that template by ProjectTemplatesService; what the form says wins.
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
projectApiRequirePost();

projectApiRun(function () use ($conn, $ctx, $analystId) {
    $in = projectApiBody();
    $isNew = empty($in['id']);
    $tenant = $isNew ? projectApiTenantForCreate($conn, $analystId, $in['company_id'] ?? null) : null;
    unset($in['company_id']);
    $template = trim((string)($in['template'] ?? ''));
    unset($in['template']);
    if ($isNew && $template !== '') {
        require_once __DIR__ . '/../../includes/services/project_templates.php';
        projectApiOk(['id' => ProjectTemplatesService::createFromTemplate($conn, $ctx, $template, $in, $tenant), 'created' => true]);
    }
    $res = ProjectsService::saveProject($conn, $ctx, $in, $tenant);
    projectApiOk(['id' => $res['id'], 'created' => $res['created']]);
});
