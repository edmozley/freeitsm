<?php
/**
 * API: Tasks — Reorder after drag-and-drop
 * POST — JSON body with {task_id, new_status, positions: [{id, board_position}, ...]}
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/checklists.php';   // completion gate (discussion #138)
require_once '../../includes/services/task_history.php'; // THE status hook (#181)
require_once '../../includes/services/tasks.php';        // announceStatusMove() - notifications + workflows (#266)

header('Content-Type: application/json');

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tasks');
requireModuleWriteJson('tasks');

$input = json_decode(file_get_contents('php://input'), true);
$taskId = isset($input['task_id']) ? (int)$input['task_id'] : 0;
$newStatus = $input['new_status'] ?? '';
$positions = $input['positions'] ?? [];

if (!$taskId || !$newStatus) {
    echo json_encode(['success' => false, 'error' => 'Missing task_id or new_status']);
    exit;
}

try {
    $conn = connectToDatabase();

    // 🔒 Company scope. reorder.php deliberately stays off TasksService (the UI
    // sends client-computed positions where the API re-packs server-side), so it
    // needs its own gate rather than inheriting the service's.
    if (!analystCanAccessTask($conn, (int) $_SESSION['analyst_id'], $taskId)) {
        echo json_encode(['success' => false, 'error' => 'Task not found']);
        exit;
    }

    // Resolve new status name -> id and decide whether to stamp completed_datetime
    $stsStmt = $conn->prepare("SELECT id, is_closed FROM task_statuses WHERE name = ? LIMIT 1");
    $stsStmt->execute([$newStatus]);
    $sts = $stsStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sts) {
        echo json_encode(['success' => false, 'error' => "Unknown status: $newStatus"]);
        exit;
    }

    // 🔴 Checklist gate (discussion #138), BEFORE the write. This endpoint stays
    // off TasksService (see the comment above), so it must call the gate itself -
    // dragging a card into Done is the commonest way a task is completed, and
    // leaving it out would make a Critical checklist block the tick box and not
    // the drag.
    $wasClosedStmt = $conn->prepare("SELECT ts.is_closed FROM tasks t LEFT JOIN task_statuses ts ON ts.id = t.status_id WHERE t.id = ?");
    $wasClosedStmt->execute([$taskId]);
    $completing = !empty($sts['is_closed']) && empty($wasClosedStmt->fetchColumn());
    if ($completing) {
        try {
            ChecklistsService::assertTaskCompletionAllowed($conn, $taskId);
        } catch (ServiceError $se) {
            echo json_encode(['success' => false, 'error' => $se->getMessage(), 'code' => $se->errorCode]);
            exit;
        }
    }

    $before = TaskHistory::before($conn, $taskId);   // #181: read before the write
    $conn->beginTransaction();
    $newStatusId = (int)$sts['id'];
    $completedSql = $sts['is_closed']
        ? ", completed_datetime = COALESCE(completed_datetime, UTC_TIMESTAMP())"
        : ", completed_datetime = NULL";

    $stmt = $conn->prepare(
        "UPDATE tasks SET status_id = ?, updated_datetime = UTC_TIMESTAMP(){$completedSql} WHERE id = ?"
    );
    $stmt->execute([$newStatusId, $taskId]);

    // Update board positions for all tasks in the affected columns.
    //
    // ⚠️ These ids come straight from the browser and are NOT the task that was
    // dragged, so gating $taskId above does not cover them: a crafted request
    // could otherwise renumber another company's board. The scope predicate is
    // carried into the UPDATE itself, so an out-of-scope id matches no row and
    // changes nothing, rather than being rejected noisily — the client is sending
    // a whole column's worth of positions and a partial column is not an error.
    [$posTenantSql, $posTenantArgs] = activeTenantFilter($conn, (int) $_SESSION['analyst_id'], '');
    $posStmt = $conn->prepare("UPDATE tasks SET board_position = ? WHERE id = ?{$posTenantSql}");
    foreach ($positions as $pos) {
        $posStmt->execute(array_merge([(int)$pos['board_position'], (int)$pos['id']], $posTenantArgs));
    }

    // #181: THE status hook. Dragging a card into a closed column is the commonest
    // way a task is completed. Its EVIDENCE is written here, inside the drag's own
    // transaction (Dr4inder's review): if the history cannot be saved, the card does
    // not move. A reorder within one column changes no status and records nothing.
    $ctx = ActorContext::fromSession($conn);
    if ($completing) {
        ChecklistsService::recordTaskCompletionOverride($conn, $ctx, $taskId, true);
    }
    $statusResult = TaskHistory::recordStatusChange($conn, $ctx, $taskId, $before, $newStatusId);
    $conn->commit();
    // After the commit: a repeat's next occurrence, its expectation already recorded.
    TaskHistory::afterCommit($conn, $ctx, $statusResult);
    // GH #266: announce the move like a save - the bell notifications, emails and
    // workflows all hang off the workflow dispatch, which this endpoint never made,
    // so a task dragged to Done told nobody. A reorder within one column changes no
    // status and announces nothing. After the commit: the calendar makes a network call.
    TasksService::announceStatusMove($conn, $taskId, (int)($before['status_id'] ?? 0), $newStatusId, $completing);
    echo json_encode(['success' => true, 'message' => 'Task reordered']);

} catch (Throwable $e) {
    // The status lookup and the checklist gate now run BEFORE the transaction
    // starts, so there may be nothing to roll back.
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
