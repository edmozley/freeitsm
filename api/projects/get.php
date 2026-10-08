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
        // The budget (3.2.0): lines, labour, totals - null before Database Verification.
        'budget' => (function () use ($conn, $row, $analystId) {
            require_once __DIR__ . '/../../includes/projects/budget.php';
            return projectBudgetReady($conn) ? projectBudgetDetail($conn, $row, $analystId) : null;
        })(),
        // Disruption announced on Service Status (3.2.0); null without Service Status.
        'announcements' => ProjectToolsService::announcements($conn, $analystId, $pid),
        // Linked changes not yet approved, for the stage gate. null = this analyst
        // cannot open Changes, so the gate says nothing rather than "none".
        'gate_changes' => analystCanAccessModule($conn, $analystId, 'changes')
            ? (function () use ($conn, $pid) { require_once __DIR__ . '/../../includes/projects/links.php'; return projectUnapprovedChanges($conn, $pid); })()
            : null,
    ]);
});
