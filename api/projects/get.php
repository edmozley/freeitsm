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
    // Dependencies (3.3.0): each task learns what it waits for, whether it clashes, and the critical path.
    require_once __DIR__ . '/../../includes/projects/dependencies.php';
    $detail = projectDetail($conn, $row);
    $deps = projectDependencies($conn, $pid);
    $an = projectDependencyAnalysis($detail['tasks'], $deps);
    foreach ($detail['tasks'] as &$tk) $tk += $an[(int)$tk['id']] ?? ['depends_on' => [], 'waiting_on' => [], 'clash' => false, 'critical' => false, 'slack' => null];
    unset($tk);
    projectApiOk($detail + [
        'dependencies' => $deps,
        'permissions' => $perms,
        'members'     => ProjectToolsService::members($conn, $pid),
        'items'       => ProjectToolsService::items($conn, $pid),
        'raci'        => (object)ProjectToolsService::raci($conn, $pid),
        'raid'        => ProjectToolsService::raid($conn, $pid),
        'tolerances'  => ProjectToolsService::tolerances($conn, $pid),
        'targets'     => projectTargetsDetail($conn, $pid),
        'milestones'  => projectMilestones($conn, $pid),
        'can_assets'  => analystCanAccessModule($conn, $analystId, 'assets'),
        // The budget (3.2.0): lines, labour, totals - null before Database Verification.
        'budget' => (function () use ($conn, $row, $analystId) {
            require_once __DIR__ . '/../../includes/projects/budget.php';
            return projectBudgetReady($conn) ? projectBudgetDetail($conn, $row, $analystId) : null;
        })(),
        // Gate checklists (3.3.0): items per stage id, and what a document or change item may point at.
        'gate' => (function () use ($conn, $pid, $analystId) {
            require_once __DIR__ . '/../../includes/projects/gatecheck.php';
            $chg = analystCanAccessModule($conn, $analystId, 'changes');
            return ['items' => (object)projectGateItems($conn, $pid, $chg), 'documents' => projectGateDocuments($conn, $pid),
                    'changes' => $chg ? projectGateChanges($conn, $pid) : [], 'mode' => projectSetting($conn, 'project_gate_checklist'), 'me' => $analystId];
        })(),
        // Benefits (3.3.0): each with its measurements, state and progress.
        'benefits' => (function () use ($conn, $pid) {
            require_once __DIR__ . '/../../includes/projects/benefits.php';
            return projectBenefits($conn, $pid);
        })(),
        // Intake (3.3.0): the proposal and its approval - null when it never needed one.
        'proposal' => (function () use ($conn, $row, $analystId) {
            require_once __DIR__ . '/../../includes/projects/intake.php';
            return projectProposalDetail($conn, $row, $analystId);
        })(),
        // Change control (3.3.0): baselines with their drift, change requests - null before Verification.
        'control' => (function () use ($conn, $row, $analystId) {
            require_once __DIR__ . '/../../includes/projects/control.php';
            return projectControlDetail($conn, $row, $analystId);
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
