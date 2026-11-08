<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Workflows: {{<record>.full...}} - the record as the REST API returns it - works in
 * EVERY action, not only a webhook (GH #275), and never for a confidential ticket.
 *
 *   FREEITSM_TEST_DATABASE=<the throwaway database config.php uses> php tests/workflow-full-record.php
 *
 * Runs real action lists through WorkflowEngine::runActionList() with a log_message
 * action, whose result is the rendered text. Uses a ZZ task linked to an existing
 * ticket; the ticket's sensitivity is changed for one check and put back, and the
 * task and the runs are deleted in finally. Control: before #275 the first check
 * renders blank.
 *
 * @wiki Workflows
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../workflow/includes/engine.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
$db = $conn->query('SELECT DATABASE()')->fetchColumn();
if (!$expected || $expected !== $db) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the throwaway database config.php uses ({$db}).\n";
    exit(0);
}

$taskId = 0; $runs = []; $ticket = null;
try {
    $ticket = $conn->query("SELECT id, ticket_number, sensitivity FROM tickets ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) throw new RuntimeException('This database has no ticket to link a task to.');
    $tid = (int)$ticket['id'];
    $creator = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $status = (int)$conn->query("SELECT id FROM task_statuses ORDER BY id LIMIT 1")->fetchColumn();
    $conn->prepare("INSERT INTO tasks (title, created_by_id, status_id, ticket_id, board_position) VALUES ('ZZ275 full record', ?, ?, ?, 0)")
         ->execute([$creator, $status, $tid]);
    $taskId = (int)$conn->lastInsertId();

    $say = function (string $message, array $payload) use (&$runs): string {
        $r = WorkflowEngine::runActionList('ZZ275 test', 'task.completed', [['type' => 'log_message', 'args' => ['message' => $message]]], $payload);
        if (!empty($r['execution_id'])) $runs[] = (int)$r['execution_id'];
        return (string)($r['steps'][0]['result']['message'] ?? '(no result: ' . json_encode($r) . ')');
    };

    $m = $say('linked={{task.full.ticket_id}} title={{task.full.title}}', ['task' => ['id' => $taskId]]);
    check("a task's linked ticket, from its full record, in an ordinary action", $m === "linked={$tid} title=ZZ275 full record", $m);

    $m = $say('{{ticket.full.ticket_number}}', ['ticket' => ['id' => $tid]]);
    check("a ticket's full record works the same way", $m === (string)$ticket['ticket_number'], $m);

    $m = $say('plain {{task.id}}', ['task' => ['id' => $taskId]]);
    check('an action that does not use .full still renders as before', $m === "plain {$taskId}", $m);

    $conn->prepare("UPDATE tickets SET sensitivity = 'confidential' WHERE id = ?")->execute([$tid]);
    $m = $say('[{{ticket.full.subject}}]', ['ticket' => ['id' => $tid]]);
    check("a confidential ticket's full record is never loaded", $m === '[]', $m);
} catch (Throwable $e) {
    check('the test ran: ' . $e->getMessage(), false);
} finally {
    if ($ticket) $conn->prepare("UPDATE tickets SET sensitivity = ? WHERE id = ?")->execute([$ticket['sensitivity'], (int)$ticket['id']]);
    if ($taskId) $conn->prepare("DELETE FROM tasks WHERE id = ?")->execute([$taskId]);
    foreach ($runs as $id) {
        try { $conn->prepare("DELETE FROM workflow_executions WHERE id = ?")->execute([$id]); } catch (Throwable $e) {}
    }
}
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
