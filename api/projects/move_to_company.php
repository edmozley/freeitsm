<?php
/**
 * POST {id, company_id} - move a project, its tasks and their subtasks to
 * another company (GH #370). ProjectsService::moveProjectToCompany() holds the
 * rules; a refusal comes back as the error, saying what is in the way.
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
projectApiRequirePost();

projectApiRun(function () use ($conn, $ctx) {
    $in = projectApiBody();
    projectApiOk(ProjectsService::moveProjectToCompany($conn, $ctx, (int)($in['id'] ?? 0), (int)($in['company_id'] ?? 0)));
});
