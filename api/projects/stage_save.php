<?php
/** POST {project_id, id?, name, goal, start_date, end_date, status} - add or edit a phase, stage or sprint. */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
projectApiRequirePost();

projectApiRun(function () use ($conn, $ctx) {
    $in = projectApiBody();
    $id = ProjectsService::saveStage($conn, $ctx, (int)($in['project_id'] ?? 0), $in);
    projectApiOk(['id' => $id]);
});
