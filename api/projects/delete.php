<?php
/**
 * POST {id} - delete a project. Its tasks are kept and taken out of the
 * project; its stages and history are removed with it.
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
projectApiRequirePost();

projectApiRun(function () use ($conn, $ctx) {
    $in = projectApiBody();
    projectApiOk(ProjectsService::deleteProject($conn, $ctx, (int)($in['id'] ?? 0)));
});
