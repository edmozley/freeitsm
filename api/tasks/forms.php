<?php
/**
 * API: Tasks — forms a task occurrence needs (GH #190).
 *
 * GET  ?task_id=N                     -> { enabled, forms, available }   the task window's Forms section
 * GET  ?task_form_id=N&for=fill       -> { fill }                         what the fill page needs
 * POST { action: add,    task_id, form_id, required, mode }
 * POST { action: update, task_form_id, required, mode }
 * POST { action: remove, task_form_id }
 * POST { action: draft,  task_form_id, revision, answers }       -> { revision }  or 409-style { conflict }
 * POST { action: submit, task_form_id, revision, client_reference, data } -> { submission_id }  or { conflict }
 *
 * Thin adapter over TaskForms, which holds every rule (the access rule, the
 * revision check, the retry key). Same shape as checklists.php beside it.
 *
 * 🔒 Every action is gated on the TASK, including those addressed by a
 * requirement id: resolve it to its task first, then analystCanAccessTask().
 * Out of scope answers "not found", never "not yours".
 *
 * ⚠️ While Tasks → Settings → Checklists → "Forms on tasks" is OFF, GET says so
 * and every write is refused.
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../includes/service_context.php';   // ServiceError, thrown below (GH #288)
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/rbac.php';
require_once '../../includes/services/task_forms.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tasks');

/** Refuse unless this analyst can reach the task. Same wording for "gone" and "not yours". */
function taskFormsGate(PDO $conn, int $taskId): void
{
    if ($taskId <= 0 || !analystCanAccessTask($conn, (int)$_SESSION['analyst_id'], $taskId)) {
        throw new ServiceError('not_found', 'not_found', 'Task not found.');
    }
}

/** The forms that can be added: the newest version of each active form. */
function taskFormsAvailable(PDO $conn): array
{
    $st = $conn->query("SELECT f.id, f.title FROM forms f
                         WHERE f.is_active = 1
                           AND NOT EXISTS (SELECT 1 FROM forms c WHERE c.parent_form_id = f.id)
                         ORDER BY f.title");
    return array_map(function ($r) { return ['id' => (int)$r['id'], 'title' => (string)$r['title']]; }, $st->fetchAll(PDO::FETCH_ASSOC));
}

try {
    $conn     = connectToDatabase();
    $ctx      = ActorContext::fromSession($conn);
    $viewerId = (int)$_SESSION['analyst_id'];
    $enabled  = TaskForms::enabled($conn) && TaskForms::columnsReady($conn);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        if (isset($_GET['task_form_id'])) {
            if (!$enabled) throw new ServiceError('validation', 'disabled', 'Forms on tasks are switched off (Tasks → Settings → Checklists).');
            $row = TaskForms::row($conn, (int)$_GET['task_form_id']);
            taskFormsGate($conn, (int)$row['task_id']);
            echo json_encode(['success' => true, 'fill' => TaskForms::forFill($conn, (int)$row['id']),
                              'may_fill' => TaskForms::mayFill($conn, $viewerId)]);
            exit;
        }
        $taskId = (int)($_GET['task_id'] ?? 0);
        taskFormsGate($conn, $taskId);
        if (!$enabled) {
            echo json_encode(['success' => true, 'enabled' => false, 'forms' => [], 'available' => []]);
            exit;
        }
        echo json_encode([
            'success'   => true,
            'enabled'   => true,
            'forms'     => TaskForms::forTask($conn, $taskId, $viewerId, !empty($_GET['answers'])),
            'available' => taskFormsAvailable($conn),
        ]);
        exit;
    }

    // Read only access to Tasks (#181 Part G) reads a task's forms and changes none.
    requireModuleWriteJson('tasks');
    if (!$enabled) {
        throw new ServiceError('validation', 'disabled', 'Forms on tasks are switched off (Tasks → Settings → Checklists).');
    }

    $in     = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    $extra  = [];

    switch ($action) {
        case 'add':
            $taskId = (int)($in['task_id'] ?? 0);
            taskFormsGate($conn, $taskId);
            TaskForms::add($conn, $ctx, $taskId, (int)($in['form_id'] ?? 0), !array_key_exists('required', $in) || !empty($in['required']),
                           (string)($in['mode'] ?? 'block'));
            break;

        case 'update':
            $row = TaskForms::row($conn, (int)($in['task_form_id'] ?? 0));
            $taskId = (int)$row['task_id'];
            taskFormsGate($conn, $taskId);
            TaskForms::update($conn, $ctx, (int)$row['id'], !empty($in['required']), (string)($in['mode'] ?? 'block'));
            break;

        case 'remove':
            $row = TaskForms::row($conn, (int)($in['task_form_id'] ?? 0));
            $taskId = (int)$row['task_id'];
            taskFormsGate($conn, $taskId);
            TaskForms::remove($conn, $ctx, (int)$row['id']);
            break;

        case 'draft':
            $row = TaskForms::row($conn, (int)($in['task_form_id'] ?? 0));
            $taskId = (int)$row['task_id'];
            taskFormsGate($conn, $taskId);
            $extra = TaskForms::saveDraft($conn, $ctx, (int)$row['id'], (int)($in['revision'] ?? -1), (array)($in['answers'] ?? []));
            break;

        case 'submit':
            $row = TaskForms::row($conn, (int)($in['task_form_id'] ?? 0));
            $taskId = (int)$row['task_id'];
            taskFormsGate($conn, $taskId);
            $extra = TaskForms::submit($conn, $ctx, (int)$row['id'], (int)($in['revision'] ?? -1),
                                       (string)($in['client_reference'] ?? ''), (array)($in['data'] ?? []));
            break;

        default:
            throw new ServiceError('validation', 'invalid_field', 'Unknown action.');
    }

    // The whole state back, so the section re-renders from one source of truth.
    echo json_encode(['success' => true, 'enabled' => true] + $extra + [
        'forms' => TaskForms::forTask($conn, $taskId, $viewerId, false),
    ]);

} catch (TaskFormConflict $e) {
    // A lost race: nothing was written. What the other person saved comes back, so the
    // page can keep this analyst's answers on screen and offer "use theirs" / "keep mine".
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode, 'conflict' => $e->detail]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    error_log('[api/tasks/forms] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
