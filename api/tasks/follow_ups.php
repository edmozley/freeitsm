<?php
/**
 * API: Tasks — follow-up tickets and tasks of an occurrence (GH #190 phase 3).
 *
 * GET  ?task_id=N    -> { follow_ups, may_ticket }
 * POST { action: create_task,   task_id, title, note, source_kind, source_id, client_reference }
 * POST { action: create_ticket, task_id, title, note, source_kind, source_id, client_reference }
 * POST { action: link,          task_id, kind (ticket|task), ref }
 * POST { action: unlink,        follow_up_id }
 *
 * Thin adapter over TaskFollowUps. Gated on the TASK first; a linked or listed
 * record the analyst cannot see is named by number only.
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../includes/service_context.php';   // ServiceError, thrown below (GH #288)
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/entity_links.php';
require_once '../../includes/services/task_follow_ups.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tasks');

/** Refuse unless this analyst can reach the task. Same wording for "gone" and "not yours". */
function followUpTaskGate(PDO $conn, int $taskId): void
{
    if ($taskId <= 0 || !analystCanAccessTask($conn, (int)$_SESSION['analyst_id'], $taskId)) {
        throw new ServiceError('not_found', 'not_found', 'Task not found.');
    }
}

/** The follow-ups, each with a link where the viewer may open it. */
function followUpList(PDO $conn, int $taskId, int $viewerId): array
{
    return array_map(function ($f) {
        $f['url'] = $f['visible'] ? entityLink($f['kind'], (int)$f['record_id']) : null;
        return $f;
    }, TaskFollowUps::forTask($conn, $taskId, $viewerId));
}

try {
    $conn     = connectToDatabase();
    $ctx      = ActorContext::fromSession($conn);
    $viewerId = (int)$_SESSION['analyst_id'];
    if (!TaskFollowUps::ready($conn)) throw new ServiceError('validation', 'disabled', 'Run System → Database Verification first.');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $taskId = (int)($_GET['task_id'] ?? 0);
        followUpTaskGate($conn, $taskId);
        echo json_encode(['success' => true, 'follow_ups' => followUpList($conn, $taskId, $viewerId),
                          'may_ticket' => analystCanAccessModule($conn, $viewerId, 'tickets')]);
        exit;
    }

    requireModuleWriteJson('tasks');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    $source = [isset($in['source_kind']) ? (string)$in['source_kind'] : null, isset($in['source_id']) ? (int)$in['source_id'] : null];
    $extra = [];
    if ($action === 'unlink') {
        $taskId = TaskFollowUps::taskIdFor($conn, (int)($in['follow_up_id'] ?? 0));
        followUpTaskGate($conn, $taskId);
        TaskFollowUps::unlink($conn, $ctx, (int)$in['follow_up_id']);
    } else {
        $taskId = (int)($in['task_id'] ?? 0);
        followUpTaskGate($conn, $taskId);
        switch ($action) {
            case 'create_task':
                $extra = TaskFollowUps::createTask($conn, $ctx, $taskId, (string)($in['title'] ?? ''), (string)($in['note'] ?? ''),
                                                   $source[0], $source[1], (string)($in['client_reference'] ?? ''));
                break;
            case 'create_ticket':
                $extra = TaskFollowUps::createTicket($conn, $ctx, $taskId, (string)($in['title'] ?? ''), (string)($in['note'] ?? ''),
                                                     $source[0], $source[1], (string)($in['client_reference'] ?? ''));
                break;
            case 'link':
                $extra = TaskFollowUps::link($conn, $ctx, $taskId, (string)($in['kind'] ?? 'ticket'), (string)($in['ref'] ?? ''), $source[0], $source[1]);
                break;
            default:
                throw new ServiceError('validation', 'invalid_field', 'Unknown action.');
        }
    }
    echo json_encode(['success' => true, 'created' => $extra, 'follow_ups' => followUpList($conn, $taskId, $viewerId),
                      'may_ticket' => analystCanAccessModule($conn, $viewerId, 'tickets')]);

} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    error_log('[api/tasks/follow_ups] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
