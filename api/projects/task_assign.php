<?php
/**
 * POST {task_id, project_id|null, stage_id?} - put an existing task in a
 * project (and stage), move it to another stage, or take it out (project_id null).
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
projectApiRequirePost();

projectApiRun(function () use ($conn, $ctx) {
    $in = projectApiBody();
    $projectId = isset($in['project_id']) && $in['project_id'] !== null && $in['project_id'] !== '' ? (int)$in['project_id'] : null;
    $stageId = isset($in['stage_id']) && $in['stage_id'] !== null && $in['stage_id'] !== '' ? (int)$in['stage_id'] : null;
    ProjectsService::assignTask($conn, $ctx, (int)($in['task_id'] ?? 0), $projectId, $stageId);
    projectApiOk();
});
