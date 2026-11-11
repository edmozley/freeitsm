<?php
/**
 * API: Tasks — checklists on a task (discussion #138, round three).
 *
 * GET  ?task_id=N                 -> { enabled, checklists, templates }
 * GET  ?task_id=N&check=complete  -> { enabled, blocking, warning }   what completing it now would hit
 * POST { action: attach,  task_id, template_id }
 * POST { action: toggle,  item_id, completed, response_value }
 * POST { action: remove,  checklist_id }
 *
 * Thin UI adapter over ChecklistsService, which holds every rule. Same shape as
 * collaborators.php beside it.
 *
 * 🔒 Every action is gated on the TASK, including the two addressed by a child
 * id (a step, a checklist): resolve the child to its task first, then ask
 * analystCanAccessTask(). A child id with no gate of its own is exactly how
 * another company's data leaks - the ticket-side endpoint had that hole until
 * it was fixed alongside this one. Out of scope answers "not found", never
 * "not yours".
 *
 * ⚠️ While Tasks → Settings → Checklists is OFF, GET says so and every write is
 * refused: the panel shows nothing, and nothing can be attached behind its back.
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../includes/service_context.php';   // ServiceError, thrown below (GH #288)
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/checklists.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tasks');

/** Refuse unless this analyst can reach the task. Same wording for "gone" and "not yours". */
function checklistTaskGate(PDO $conn, int $taskId): void
{
    if ($taskId <= 0 || !analystCanAccessTask($conn, (int) $_SESSION['analyst_id'], $taskId)) {
        throw new ServiceError('not_found', 'not_found', 'Task not found.');
    }
}

try {
    $conn    = connectToDatabase();
    $ctx     = ActorContext::fromSession($conn);
    $enabled = ChecklistsService::tasksEnabled($conn);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $taskId = (int)($_GET['task_id'] ?? 0);
        checklistTaskGate($conn, $taskId);

        if (($_GET['check'] ?? '') === 'complete') {
            $check = ChecklistsService::taskCompletionCheck($conn, $taskId);
            echo json_encode(['success' => true, 'enabled' => $enabled] + $check);
            exit;
        }
        if (!$enabled) {
            echo json_encode(['success' => true, 'enabled' => false, 'checklists' => [], 'templates' => []]);
            exit;
        }
        echo json_encode([
            'success'    => true,
            'enabled'    => true,
            'checklists' => ChecklistsService::taskChecklists($conn, $taskId),
            'templates'  => ChecklistsService::templatesForTask($conn, $taskId),
        ]);
        exit;
    }

    // Read only access to Tasks (#181 Part G) reads checklists and changes none.
    requireModuleWriteJson('tasks');

    if (!$enabled) {
        throw new ServiceError('validation', 'disabled', 'Checklists on tasks are switched off (Tasks → Settings → Checklists).');
    }

    $in     = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');

    switch ($action) {
        case 'attach':
            $taskId = (int)($in['task_id'] ?? 0);
            checklistTaskGate($conn, $taskId);
            ChecklistsService::attachTemplateToTask($conn, $ctx, $taskId, (int)($in['template_id'] ?? 0));
            break;

        case 'toggle':
            $taskId = ChecklistsService::taskIdForItem($conn, (int)($in['item_id'] ?? 0));
            checklistTaskGate($conn, $taskId);
            ChecklistsService::toggleTaskItem(
                $conn, $ctx, (int)$in['item_id'], !empty($in['completed']),
                isset($in['response_value']) ? (string)$in['response_value'] : null
            );
            break;

        // #181: Pass / Fail / Not applicable on a step that asks for a result; Fail needs a note.
        case 'result':
            $taskId = ChecklistsService::taskIdForItem($conn, (int)($in['item_id'] ?? 0));
            checklistTaskGate($conn, $taskId);
            ChecklistsService::setTaskItemResult($conn, $ctx, (int)$in['item_id'], (string)($in['result'] ?? ''),
                isset($in['exception_note']) ? (string)$in['exception_note'] : null);
            break;

        case 'remove':
            $taskId = ChecklistsService::taskIdForChecklist($conn, (int)($in['checklist_id'] ?? 0));
            checklistTaskGate($conn, $taskId);
            ChecklistsService::removeTaskChecklist($conn, (int)$in['checklist_id'], $ctx);
            break;

        // Dragged into a new order in the task window. The whole list is sent,
        // top first; the service pins every row to the task / checklist gated here.
        case 'reorder':
            $taskId = (int)($in['task_id'] ?? 0);
            checklistTaskGate($conn, $taskId);
            ChecklistsService::reorderTaskChecklists($conn, $taskId, (array)($in['ids'] ?? []));
            break;

        case 'reorder_items':
            $taskId = ChecklistsService::taskIdForChecklist($conn, (int)($in['checklist_id'] ?? 0));
            checklistTaskGate($conn, $taskId);
            ChecklistsService::reorderTaskChecklistItems($conn, (int)$in['checklist_id'], (array)($in['ids'] ?? []));
            break;

        default:
            throw new ServiceError('validation', 'invalid_field', 'Unknown action.');
    }

    // Always hand back the whole state, so the panel re-renders from one source
    // of truth - the same reasoning as collaborators.php.
    echo json_encode([
        'success'    => true,
        'enabled'    => true,
        'checklists' => ChecklistsService::taskChecklists($conn, $taskId),
        'templates'  => ChecklistsService::templatesForTask($conn, $taskId),
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
