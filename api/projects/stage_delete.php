<?php
/** POST {project_id, id} - remove a phase, stage or sprint. Its tasks stay in the project. */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
projectApiRequirePost();

projectApiRun(function () use ($conn, $ctx) {
    $in = projectApiBody();
    ProjectsService::deleteStage($conn, $ctx, (int)($in['project_id'] ?? 0), (int)($in['id'] ?? 0));
    projectApiOk();
});
