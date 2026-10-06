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
    projectApiOk(projectDetail($conn, $row) + ['permissions' => $perms]);
});
