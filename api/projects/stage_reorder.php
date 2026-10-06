<?php
/** POST {project_id, ids:[...]} - put the project's time boxes in this order. */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
projectApiRequirePost();

projectApiRun(function () use ($conn, $ctx) {
    $in = projectApiBody();
    ProjectsService::reorderStages($conn, $ctx, (int)($in['project_id'] ?? 0), is_array($in['ids'] ?? null) ? $in['ids'] : []);
    projectApiOk();
});
