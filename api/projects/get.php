<?php
/**
 * GET ?id= - one project for its page: the project, its time boxes, its tasks
 * and its history. Out of scope reads as not found.
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';

projectApiRun(function () use ($conn, $ctx, $analystId) {
    $row = ProjectsService::loadForActor($conn, $ctx, (int)($_GET['id'] ?? 0));
    // What this analyst may do here - the page hides what the server would refuse.
    $perms = ['can_change' => projectCanChange($conn, $analystId, $row), 'can_delete' => projectCanDelete($conn, $analystId, $row)];
    require_once __DIR__ . '/../../includes/services/project_tools.php';
    $pid = (int)$row['id'];
    projectApiOk(projectDetail($conn, $row) + [
        'permissions' => $perms,
        'members'     => ProjectToolsService::members($conn, $pid),
        'items'       => ProjectToolsService::items($conn, $pid),
        'raci'        => (object)ProjectToolsService::raci($conn, $pid),
        'raid'        => ProjectToolsService::raid($conn, $pid),
        'tolerances'  => ProjectToolsService::tolerances($conn, $pid),
        'targets'     => projectTargetsDetail($conn, $pid),
        'can_assets'  => analystCanAccessModule($conn, $analystId, 'assets'),
    ]);
});
