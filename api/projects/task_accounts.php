<?php
/**
 * The account(s) the time on a task counts in (GH #282, slice 3) - for the task window.
 *
 *   GET  ?task_id=N        what the task window shows: whether the task is in a project, the
 *                          accounts to choose from, the task's own split (latest version),
 *                          its parent's (a subtask follows it unless it has its own), the
 *                          project's account for time, the resplit setting, and its history
 *   POST {task_id, accounts}  set it: [] = none of its own, [{account_id}] = one,
 *                          [{account_id, percent}, ...] = a split adding up to 100
 *
 * Rules: includes/projects/accounts.php; the write goes through
 * ProjectToolsService::setTaskAccounts(), which needs the right to change the project.
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
require_once __DIR__ . '/../../includes/services/project_tools.php';
require_once __DIR__ . '/../../includes/projects/accounts.php';

projectApiRun(function () use ($conn, $ctx) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = projectApiBody();
        $taskId = (int)($in['task_id'] ?? 0);
        [$pid] = ProjectToolsService::taskProject($conn, $taskId);
        if ($pid <= 0) projectApiFail('That task is not in a project.');
        $changed = ProjectToolsService::setTaskAccounts($conn, $ctx, $pid, $taskId, $in['accounts'] ?? []);
        projectApiOk(['changed' => $changed]);
    }

    $taskId = (int)($_GET['task_id'] ?? 0);
    [$pid, $task] = ProjectToolsService::taskProject($conn, $taskId);
    if ($pid <= 0 || !projectTaskAccountsReady($conn)) projectApiOk(['in_project' => false]);
    try {
        $p = ProjectsService::loadForActor($conn, $ctx, $pid);
    } catch (ServiceError $e) {
        projectApiOk(['in_project' => false]);   // a project this person cannot see: say nothing about it
    }
    $choices = projectCostAccounts($conn, $p);   // costs only - never an income account (slice 5)
    $parentId = (int)($task['parent_task_id'] ?? 0);
    $versions = projectTaskAccountVersions($conn, array_filter([$taskId, $parentId]));
    $own = $versions[$taskId] ?? [];
    $parent = $parentId ? ($versions[$parentId] ?? []) : [];
    $canChange = true;
    try { ProjectsService::assertCanChange($conn, $ctx, $p); } catch (ServiceError $e) { $canChange = false; }
    projectApiOk([
        'in_project' => true,
        'project_id' => $pid,
        'project_name' => $p['name'],
        'can_change' => $canChange,
        'choices'    => $choices,
        // null = none of its own (never set, or cleared): a subtask then follows its parent.
        'own'        => $own && end($own)['shares'] ? end($own)['shares'] : null,
        'parent'     => $parent && end($parent)['shares'] ? end($parent)['shares'] : null,
        'is_subtask' => $parentId > 0,
        'labour_default' => projectDefaultAccounts($conn, $p)['labour'],
        'resplit'    => projectSetting($conn, 'project_account_resplit') === 'from_then' ? 'from_then' : 'all',
        'history'    => array_map(fn($v) => ['from' => $v['from'], 'shares' => $v['shares']], $own),
    ]);
});
