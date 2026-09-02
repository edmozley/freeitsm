<?php
/**
 * API: Tasks — the other people on a task ("Involved", GH #89).
 *
 * GET  ?task_id=N            -> { collaborators, candidates, completion_enabled }
 * POST { task_id, analyst_id, action: add | remove | done | undone }
 *
 * Thin UI adapter over TasksService, which holds every rule: the owner is never
 * a collaborator, collaborators are top-level tasks only, and an analyst can
 * only be added if they can reach the task's company.
 *
 * 🔒 THE CANDIDATE LIST IS THE SAME QUESTION AS THE SAVE, so it is answered the
 * same way. A picker offering analysts from other companies is a disclosure on
 * its own, before anybody is added and whether or not the save would refuse it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/tasks.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tasks');

try {
    $conn = connectToDatabase();
    $ctx  = ActorContext::fromSession($conn);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $taskId = (int)($_GET['task_id'] ?? 0);
        if ($taskId <= 0) {
            throw new Exception('task_id is required');
        }

        // Company scope + 404, via the service's own choke point. Reading who is
        // on a task is as much a disclosure as writing it.
        $task = TasksService::taskForCollaborators($conn, $ctx, $taskId);

        echo json_encode([
            'success'            => true,
            'collaborators'      => TasksService::collaboratorsFor($conn, $taskId),
            'candidates'         => collaboratorCandidates($conn, $task),
            'completion_enabled' => TasksService::collaboratorCompletionEnabled($conn),
            // The UI hides the whole section on a subtask rather than offering a
            // control that would be refused on save.
            'allowed'            => empty($task['parent_task_id']),
        ]);
        exit;
    }

    $input     = json_decode(file_get_contents('php://input'), true) ?: [];
    $taskId    = (int)($input['task_id'] ?? 0);
    $analystId = (int)($input['analyst_id'] ?? 0);
    $action    = (string)($input['action'] ?? '');

    switch ($action) {
        case 'add':
            $result = TasksService::addCollaborator($conn, $ctx, $taskId, $analystId);
            break;
        case 'remove':
            $result = TasksService::removeCollaborator($conn, $ctx, $taskId, $analystId);
            break;
        case 'done':
        case 'undone':
            $result = TasksService::setCollaboratorDone($conn, $ctx, $taskId, $analystId, $action === 'done');
            break;
        default:
            throw new Exception('Unknown action');
    }

    // Always hand back the whole list. The panel re-renders from one source of
    // truth rather than patching its own copy, which is how a chip survives two
    // people editing the same task at once.
    echo json_encode(array_merge(['success' => true], $result, [
        'collaborators' => TasksService::collaboratorsFor($conn, $taskId),
    ]));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Who may be offered as a collaborator on this task: active analysts, minus the
 * owner, minus anybody already on it, and — on a multi-company install — only
 * those who can reach the task's own company.
 */
function collaboratorCandidates(PDO $conn, array $task): array
{
    $rows = $conn->query("SELECT id, full_name FROM analysts WHERE is_active = 1 ORDER BY full_name")
                 ->fetchAll(PDO::FETCH_ASSOC);

    $ownerId = (int)($task['assigned_analyst_id'] ?? 0);
    $already = [];
    foreach (TasksService::collaboratorsFor($conn, (int)$task['id']) as $c) {
        $already[$c['analyst_id']] = true;
    }

    $multi    = isMultiTenant($conn);
    $tenantId = $task['tenant_id'] ?? null;
    if ($multi) {
        $tenantId = ($tenantId === null) ? getDefaultTenantId($conn) : (int)$tenantId;
    }

    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        if ($id === $ownerId || isset($already[$id])) {
            continue;
        }
        if ($multi && !analystCanAccessTenant($conn, $id, (int)$tenantId)) {
            continue;
        }
        $out[] = ['id' => $id, 'name' => $r['full_name']];
    }
    return $out;
}
