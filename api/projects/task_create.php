<?php
/**
 * POST {project_id, stage_id?, title, assigned_analyst_id?, due_date?, start_date?}
 * - a new task straight into the project, made through TasksService so it
 * behaves exactly like one made on the Tasks board.
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
projectApiRequirePost();

projectApiRun(function () use ($conn, $ctx) {
    $in = projectApiBody();
    $projectId = (int)($in['project_id'] ?? 0);
    $stageId = isset($in['stage_id']) && $in['stage_id'] !== '' && $in['stage_id'] !== null ? (int)$in['stage_id'] : null;
    $task = array_intersect_key($in, array_flip(['title', 'description', 'assigned_analyst_id', 'assigned_team_id', 'start_date', 'due_date', 'priority_id', 'status_id']));
    $id = ProjectsService::createTaskInProject($conn, $ctx, $projectId, $stageId, $task);
    projectApiOk(['id' => $id]);
});
