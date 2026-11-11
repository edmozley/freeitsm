<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Workflows for tasks (GitHub #274): task events carry the task's ticket, a ticket action
 * on a task workflow reaches that ticket, and the three actions that change a task.
 *
 *   FREEITSM_TEST_DATABASE=<the throwaway database config.php uses> php tests/workflow-task-actions.php
 *
 * The example from the issue end to end: a real, active workflow "task completed -> note on
 * its ticket", fired by completing a ZZ task through TasksService. Then the actions through
 * WorkflowEngine::runActionList(). Makes two ZZ tasks (one on a ticket), one workflow, notes,
 * comments and runs; all removed in finally, with the forms setting put back.
 *
 * @wiki Workflows
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/tasks.php';
require_once __DIR__ . '/../workflow/includes/engine.php';
require_once __DIR__ . '/../workflow/includes/templates.php';

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
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/db_verify_cli.php') . ' --apply 2>&1', $o, $code);
if ($code !== 0) { check('Database Verify ran', false); exit(1); }

$tag = 'ZZ274-' . bin2hex(random_bytes(3));
$tasks = []; $wfId = 0; $runs = []; $ticketId = 0;
$formsBefore = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'tasks_forms_enabled'")->fetchColumn();
try {
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ tester');
    $ticketId = (int)$conn->query("SELECT id FROM tickets WHERE deleted_datetime IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    $ticketNo = (string)$conn->query("SELECT ticket_number FROM tickets WHERE id = $ticketId")->fetchColumn();
    $open   = (int)$conn->query("SELECT id FROM task_statuses WHERE is_active = 1 AND is_closed = 0 ORDER BY is_default DESC, display_order LIMIT 1")->fetchColumn();
    $open2  = (int)$conn->query("SELECT id FROM task_statuses WHERE is_active = 1 AND is_closed = 0 AND id <> $open ORDER BY display_order LIMIT 1")->fetchColumn();
    $closed = (int)$conn->query("SELECT id FROM task_statuses WHERE is_active = 1 AND is_closed = 1 ORDER BY display_order LIMIT 1")->fetchColumn();
    $linked   = $tasks[] = TasksService::saveTask($conn, $ctx, ['title' => "$tag linked", 'status_id' => $open, 'ticket_id' => $ticketId, 'assigned_analyst_id' => $admin])['id'];
    $unlinked = $tasks[] = TasksService::saveTask($conn, $ctx, ['title' => "$tag alone", 'status_id' => $open])['id'];
    $noteCount = fn(string $like) => (int)$conn->query("SELECT COUNT(*) FROM ticket_notes WHERE ticket_id = $ticketId AND note_text LIKE " . $conn->quote($like))->fetchColumn();

    echo "The designer\n";
    check('every task trigger offers "Task · Ticket id" as a condition', in_array('task.ticket_id', WorkflowEngine::availableFields('task.completed'), true)
        && in_array('task.ticket_id', WorkflowEngine::availableFields('task.comment_added'), true));
    $vars = array_column(WorkflowEngine::availableVariables('task.status_changed'), 'path');
    check('...and the merge codes task.ticket_number, task.ticket_subject, task.ticket_url', !array_diff(['task.ticket_number', 'task.ticket_subject', 'task.ticket_url'], $vars));
    $acts = WorkflowEngine::availableActions();
    check('a ticket action starts as the task\'s ticket on a task trigger', ($acts['add_ticket_note']['args']['ticket_id']['trigger_defaults']['task.'] ?? '') === '{{task.ticket_id}}'
        && ($acts['send_email']['args']['ticket_id']['trigger_defaults']['task.'] ?? '') === '{{task.ticket_id}}');
    check('...and stays {{ticket.id}} everywhere else', ($acts['add_ticket_note']['args']['ticket_id']['default'] ?? '') === '{{ticket.id}}');
    check('three new actions: set task status, assign task, add a comment', isset($acts['set_task_status'], $acts['assign_task'], $acts['add_task_comment']));
    $tpl = WorkflowTemplates::all()['task_done_note_on_ticket'] ?? null;
    check('the sample recipe uses a real trigger and a real action', $tpl && isset(WorkflowEngine::availableTriggers()[$tpl['trigger_event']]) && isset($acts[$tpl['actions'][0]['type']]));
    $en = WorkflowEngine::enrichWithTaskTicket($conn, ['task' => ['id' => $linked, 'ticket_id' => $ticketId]]);
    check('the ticket number, subject and link are filled in', ($en['task']['ticket_number'] ?? '') === $ticketNo && isset($en['task']['ticket_subject']) && str_ends_with((string)($en['task']['ticket_url'] ?? ''), "ticket_id=$ticketId"));

    echo "\nThe example: task done -> note on its ticket (a real workflow)\n";
    $conn->prepare("INSERT INTO workflows (name, trigger_event, conditions, actions, is_active) VALUES (?, 'task.completed', ?, ?, 1)")->execute([
        "$tag sample",
        json_encode($tpl['conditions']),
        json_encode([['type' => 'add_ticket_note', 'args' => ['ticket_id' => '{{task.ticket_id}}', 'note' => "$tag done: {{task.title}} on {{task.ticket_number}}", 'write_to' => 'note']]]),
    ]);
    $wfId = (int)$conn->lastInsertId();
    TasksService::saveTask($conn, $ctx, ['id' => $linked, 'status_id' => $closed]);
    $n = $conn->query("SELECT note_text, analyst_id, is_internal FROM ticket_notes WHERE ticket_id = $ticketId AND note_text LIKE " . $conn->quote("$tag done:%"))->fetch(PDO::FETCH_ASSOC);
    check('completing the linked task writes a note on its ticket', $n !== false);
    check('...with the task and the ticket number filled in', $n && $n['note_text'] === "$tag done: $tag linked on $ticketNo", $n['note_text'] ?? '');
    check('...internal, by System', $n && (int)$n['is_internal'] === 1 && $n['analyst_id'] === null);
    TasksService::saveTask($conn, $ctx, ['id' => $unlinked, 'status_id' => $closed]);
    check('a task with no ticket: the condition skips it, nothing written', $noteCount("$tag done: $tag alone%") === 0);
    $last = $conn->query("SELECT status FROM workflow_executions WHERE workflow_id = $wfId ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('...recorded as skipped, not failed', $last === 'skipped', (string)$last);
    $conn->prepare("UPDATE workflows SET is_active = 0 WHERE id = ?")->execute([$wfId]);

    $run = function (string $event, array $actions, array $task) use (&$runs): array {
        $r = WorkflowEngine::runActionList('ZZ274 test', $event, $actions, ['task' => $task]);
        if (!empty($r['execution_id'])) $runs[] = (int)$r['execution_id'];
        return $r;
    };
    $err = fn(array $r) => (string)($r['steps'][0]['error'] ?? '');

    echo "\nNo ticket, said plainly\n";
    $r = $run('task.completed', [['type' => 'add_ticket_note', 'args' => ['ticket_id' => '{{task.ticket_id}}', 'note' => "$tag x"]]], ['id' => $unlinked, 'ticket_id' => null]);
    check('a ticket action on a task with no ticket fails saying the task has no ticket', str_contains($err($r), 'not linked to a ticket'), $err($r));

    echo "\nSet task status\n";
    $r = $run('task.created', [['type' => 'set_task_status', 'args' => ['task_id' => '{{task.id}}', 'status_id' => (string)$open2]]], ['id' => $linked]);
    $st = (int)$conn->query("SELECT status_id FROM tasks WHERE id = $linked")->fetchColumn();
    check('the task moves to the chosen status', ($r['status'] ?? '') === 'success' && $st === $open2, $err($r));
    $who = $conn->query("SELECT actor_kind, actor_name FROM task_events WHERE task_id = $linked ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('...and its history says a workflow did it', $who && $who['actor_kind'] === 'workflow' && str_starts_with((string)$who['actor_name'], 'Workflow'), json_encode($who));
    $conn->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('tasks_forms_enabled', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
    $fid = (int)$conn->query("SELECT id FROM forms WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    if ($fid) {
        $conn->prepare("INSERT INTO task_forms (task_id, form_id, title, is_required, requirement_mode) VALUES (?, ?, ?, 1, 'block')")->execute([$linked, $fid, "$tag form"]);
        $r = $run('task.created', [['type' => 'set_task_status', 'args' => ['task_id' => '{{task.id}}', 'status_id' => (string)$closed]]], ['id' => $linked]);
        $st = (int)$conn->query("SELECT status_id FROM tasks WHERE id = $linked")->fetchColumn();
        check('completing obeys the task\'s gate: a required form set to block stops it, and says so', ($r['status'] ?? '') === 'failed' && $st === $open2 && str_contains($err($r), "$tag form"), $err($r));
        $conn->prepare("DELETE FROM task_forms WHERE task_id = ?")->execute([$linked]);
    } else {
        check('(no active form on this install to test the gate with)', true);
    }

    echo "\nAssign task\n";
    $other = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND id <> $admin ORDER BY id LIMIT 1")->fetchColumn();
    $r = $run('task.created', [['type' => 'assign_task', 'args' => ['task_id' => '{{task.id}}', 'analyst_id' => (string)$other]]], ['id' => $linked]);
    check('the task gets the new owner', ($r['status'] ?? '') === 'success' && (int)$conn->query("SELECT assigned_analyst_id FROM tasks WHERE id = $linked")->fetchColumn() === $other, $err($r));
    $r = $run('task.created', [['type' => 'assign_task', 'args' => ['task_id' => '{{task.id}}', 'analyst_id' => '999999999']]], ['id' => $linked]);
    check('an analyst who does not exist fails the step', ($r['status'] ?? '') === 'failed');

    echo "\nAdd a comment to the task\n";
    $r = $run('task.created', [['type' => 'add_task_comment', 'args' => ['task_id' => '{{task.id}}', 'comment' => "$tag comment on {{task.id}}"]]], ['id' => $linked]);
    $c = $conn->query("SELECT analyst_id, comment FROM task_comments WHERE task_id = $linked AND comment LIKE " . $conn->quote("$tag comment%"))->fetch(PDO::FETCH_ASSOC);
    check('a comment, with merge codes filled in', ($r['status'] ?? '') === 'success' && $c && $c['comment'] === "$tag comment on $linked", $err($r));
    check('...written by System (no analyst)', $c && $c['analyst_id'] === null);
    $nullable = $conn->query("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'task_comments' AND column_name = 'analyst_id'")->fetchColumn();
    check('Database Verify made task_comments.analyst_id nullable', $nullable === 'YES');

    echo "\nThe task actions default to the event's task\n";
    check('task_id starts as {{task.id}}', ($acts['set_task_status']['args']['task_id']['default'] ?? '') === '{{task.id}}');
    $r = $run('task.created', [['type' => 'add_task_comment', 'args' => ['task_id' => '999999999', 'comment' => "$tag nope"]]], ['id' => $linked]);
    check('a task that does not exist fails the step', ($r['status'] ?? '') === 'failed' && str_contains($err($r), 'Unknown task_id'), $err($r));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($wfId) {
        $conn->exec("DELETE FROM workflow_executions WHERE workflow_id = $wfId");
        $conn->exec("DELETE FROM workflows WHERE id = $wfId");
    }
    foreach ($runs as $id) { try { $conn->exec("DELETE FROM workflow_executions WHERE id = " . (int)$id); } catch (Throwable $e) {} }
    if ($ticketId) $conn->prepare("DELETE FROM ticket_notes WHERE ticket_id = ? AND note_text LIKE ?")->execute([$ticketId, "$tag%"]);
    if ($tasks) {
        $p = implode(',', array_map('intval', $tasks));
        foreach (["DELETE FROM task_comments WHERE task_id IN ($p)", "DELETE FROM task_forms WHERE task_id IN ($p)", "DELETE FROM task_events WHERE task_id IN ($p)",
                  "DELETE FROM task_audit WHERE task_id IN ($p)", "DELETE FROM notifications WHERE entity_type = 'task' AND entity_id IN ($p)",
                  "DELETE FROM tasks WHERE id IN ($p)"] as $sql) {
            try { $conn->exec($sql); } catch (Throwable $e) { echo "  cleanup: " . $e->getMessage() . "\n"; }
        }
    }
    if ($formsBefore === false) $conn->exec("DELETE FROM system_settings WHERE setting_key = 'tasks_forms_enabled'");
    else $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'tasks_forms_enabled'")->execute([$formsBefore]);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
