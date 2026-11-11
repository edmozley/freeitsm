<?php
/**
 * API: Tasks — Knowledge procedures on a task (GH #190 phase 2).
 *
 * GET  ?task_id=N              -> { enabled, procedures, may_add }
 * GET  ?task_id=N&search=text  -> { results }   articles this analyst may read, for the picker
 * POST { action: add,    task_id, article_id }
 * POST { action: remove, procedure_id }
 *
 * Thin adapter over TaskProcedures. Every action is gated on the TASK first
 * (analystCanAccessTask), and every article goes through Knowledge's own
 * visibility rules - a procedure the viewer may not read is named as such,
 * never shown, and its title is not given away.
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../includes/service_context.php';   // ServiceError, thrown below (GH #288)
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/task_procedures.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tasks');

/** Refuse unless this analyst can reach the task. Same wording for "gone" and "not yours". */
function procedureTaskGate(PDO $conn, int $taskId): void
{
    if ($taskId <= 0 || !analystCanAccessTask($conn, (int)$_SESSION['analyst_id'], $taskId)) {
        throw new ServiceError('not_found', 'not_found', 'Task not found.');
    }
}

try {
    $conn     = connectToDatabase();
    $ctx      = ActorContext::fromSession($conn);
    $viewerId = (int)$_SESSION['analyst_id'];
    $enabled  = TaskProcedures::enabled($conn) && TaskProcedures::ready($conn);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $taskId = (int)($_GET['task_id'] ?? 0);
        procedureTaskGate($conn, $taskId);
        if (!$enabled) {
            echo json_encode(['success' => true, 'enabled' => false, 'procedures' => []]);
            exit;
        }
        if (isset($_GET['search'])) {
            echo json_encode(['success' => true, 'results' => TaskProcedures::search($conn, $viewerId, trim((string)$_GET['search']))]);
            exit;
        }
        echo json_encode(['success' => true, 'enabled' => true,
                          'procedures' => TaskProcedures::forTask($conn, $taskId, $viewerId),
                          'may_add' => TaskProcedures::mayReadKnowledge($conn, $viewerId)]);
        exit;
    }

    requireModuleWriteJson('tasks');
    if (!$enabled) throw new ServiceError('validation', 'disabled', 'Procedures on tasks are switched off (Tasks → Settings → Checklists).');

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    switch ((string)($in['action'] ?? '')) {
        case 'add':
            $taskId = (int)($in['task_id'] ?? 0);
            procedureTaskGate($conn, $taskId);
            TaskProcedures::add($conn, $ctx, $taskId, (int)($in['article_id'] ?? 0));
            break;
        case 'remove':
            $taskId = TaskProcedures::taskIdFor($conn, (int)($in['procedure_id'] ?? 0));
            procedureTaskGate($conn, $taskId);
            TaskProcedures::remove($conn, $ctx, (int)$in['procedure_id']);
            break;
        default:
            throw new ServiceError('validation', 'invalid_field', 'Unknown action.');
    }
    echo json_encode(['success' => true, 'enabled' => true, 'procedures' => TaskProcedures::forTask($conn, $taskId, $viewerId),
                      'may_add' => TaskProcedures::mayReadKnowledge($conn, $viewerId)]);

} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    error_log('[api/tasks/procedures] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
