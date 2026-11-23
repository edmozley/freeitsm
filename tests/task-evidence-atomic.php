<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Evidence is written WITH the change, or the change does not happen (#181, Dr4inder's review).
 *
 *   php tests/task-evidence-atomic.php [base-url]
 *
 * Deliberately fails each evidence write and proves the task is left exactly as it was:
 * completing, a due date change, a board move, a skip, and deleting an occurrence (a
 * failed snapshot READ, a failed retained-record write, a failed "removed" event). Each
 * has a positive control: the same action with nothing failing does happen, with its record.
 *
 * Two ways of failing:
 *  - in this process, TaskHistory::$failNextWrite (a test-only hook) for the service paths;
 *  - with a base URL, a temporary DATABASE TRIGGER that refuses task_events rows for one
 *    ZZ task, for the two endpoints that run in the web server (the board's drag and ticking
 *    a subtask), so the failure is a real database refusal there. The trigger is dropped
 *    however the script ends.
 *
 * Tidies everything it made, history included.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/tasks.php';
require_once __DIR__ . '/../includes/services/task_history.php';

$BASE = isset($argv[1]) ? rtrim($argv[1], '/') . '/' : null;
$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}
function section(string $t): void { echo "\n{$t}\n"; }

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!TaskHistory::ready($conn)) { echo "Run Database Verification first.\n"; exit(1); }
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx   = new ActorContext($admin, null, 'ui', 'en', 'ZZ atomic tester');
$open  = (int)$conn->query("SELECT id FROM task_statuses WHERE is_active=1 AND is_closed=0 ORDER BY is_default DESC, display_order, id LIMIT 1")->fetchColumn();
$openName   = (string)$conn->query("SELECT name FROM task_statuses WHERE id = $open")->fetchColumn();
$closed     = (int)$conn->query("SELECT id FROM task_statuses WHERE is_active=1 AND is_closed=1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
$closedName = (string)$conn->query("SELECT name FROM task_statuses WHERE id = $closed")->fetchColumn();

$tids = []; $rids = [];
$settingBefore = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'tasks_evidence_delete_occurrence'")->fetchColumn();

function zzTask(PDO $c, string $title, ?int $rid = null, ?int $parent = null): int {
    global $tids, $open, $admin;
    $c->prepare("INSERT INTO tasks (title, status_id, due_date, scheduled_date, recurrence_id, parent_task_id, created_by_id, created_datetime, updated_datetime)
                 VALUES (?, ?, CURDATE(), CURDATE(), ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([$title, $open, $rid, $parent, $admin]);
    return $tids[] = (int)$c->lastInsertId();
}
function zzSeries(PDO $c, string $mode = 'completion'): int {
    global $rids;
    $c->prepare("INSERT INTO task_recurrences (mode, freq, interval_n, ends_mode, occurrences_created, is_active, created_datetime, updated_datetime)
                 VALUES (?, 'daily', 1, 'never', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([$mode]);
    return $rids[] = (int)$c->lastInsertId();
}
function state(PDO $c, int $id): array {
    $r = $c->query("SELECT status_id, due_date, completed_datetime, completed_by_id, board_position FROM tasks WHERE id = $id")->fetch(PDO::FETCH_ASSOC) ?: [];
    $r['events'] = (int)$c->query("SELECT COUNT(*) FROM task_events WHERE task_id = $id")->fetchColumn();
    $r['exists'] = (int)$c->query("SELECT COUNT(*) FROM tasks WHERE id = $id")->fetchColumn();
    $r['removed_records'] = (int)$c->query("SELECT COUNT(*) FROM task_removed_records WHERE task_id = $id")->fetchColumn();
    return $r;
}
function refused(callable $fn): ?Throwable { try { $fn(); return null; } catch (Throwable $e) { return $e; } }
function cleanup(): void {
    global $conn, $tids, $rids, $settingBefore;
    try { $conn->exec("DROP TRIGGER IF EXISTS zz_atomic_refuse_events"); } catch (Throwable $e) {}
    try {
        if ($tids) {
            $p = implode(',', array_map('intval', $tids));
            $conn->exec("DELETE FROM task_events WHERE task_id IN ($p)");
            $conn->exec("DELETE FROM task_removed_records WHERE task_id IN ($p)");
            $conn->exec("DELETE FROM task_audit WHERE task_id IN ($p)");
            $conn->exec("DELETE FROM notifications WHERE entity_type = 'task' AND entity_id IN ($p)");
            $conn->exec("DELETE FROM tasks WHERE id IN ($p) OR parent_task_id IN ($p)");
        }
        foreach ($rids as $rid) {
            $conn->exec("DELETE FROM task_events WHERE recurrence_id = " . (int)$rid);
            $conn->exec("DELETE FROM task_removed_records WHERE recurrence_id = " . (int)$rid);
            $conn->exec("DELETE FROM task_recurrence_versions WHERE recurrence_id = " . (int)$rid);
            $conn->exec("DELETE FROM notifications WHERE entity_type = 'task' AND entity_id IN (SELECT id FROM tasks WHERE recurrence_id = " . (int)$rid . ")");
            $conn->exec("DELETE FROM tasks WHERE recurrence_id = " . (int)$rid);
            $conn->exec("DELETE FROM task_recurrences WHERE id = " . (int)$rid);
        }
        $conn->exec("DELETE FROM system_settings WHERE setting_key = 'tasks_evidence_delete_occurrence'");
        if ($settingBefore !== false) {
            $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('tasks_evidence_delete_occurrence', ?)")->execute([$settingBefore]);
        }
    } catch (Throwable $e) { echo "CLEANUP FAILED: " . $e->getMessage() . "\n"; }
}
register_shutdown_function('cleanup');

section('1. Completing a task (the task window, REST PATCH)');
$t = zzTask($conn, 'ZZ-ATOM complete');
$was = state($conn, $t);
TaskHistory::$failNextWrite = 'completed';
$e = refused(fn() => TasksService::saveTask($conn, $ctx, ['id' => $t, 'status' => $closedName]));
$now = state($conn, $t);
ok('a failed "completed" record refuses the completion', $e instanceof ServiceError && $e->errorCode === 'evidence_not_recorded', $e ? $e->getMessage() : 'no throw');
ok('...and the task is exactly as it was: status, completion, completer', $now['status_id'] === $was['status_id'] && $now['completed_datetime'] === null && $now['completed_by_id'] === null);
ok('...with no half-written history (the status_changed row rolled back too)', $now['events'] === $was['events'], "{$was['events']} -> {$now['events']}");
TasksService::saveTask($conn, $ctx, ['id' => $t, 'status' => $closedName]);
$now = state($conn, $t);
ok('control: nothing failing, it completes, with its record', (int)$now['status_id'] === $closed && $now['completed_by_id'] !== null
   && (int)$conn->query("SELECT COUNT(*) FROM task_events WHERE task_id = $t AND event_type = 'completed'")->fetchColumn() === 1);

section('2. A due date change');
$t2 = zzTask($conn, 'ZZ-ATOM due');
$was = state($conn, $t2);
TaskHistory::$failNextWrite = 'due_date_changed';
$e = refused(fn() => TasksService::saveTask($conn, $ctx, ['id' => $t2, 'due_date' => date('Y-m-d', strtotime('+9 days'))]));
ok('a failed record refuses the due date change, leaving the date as it was', $e instanceof ServiceError && state($conn, $t2)['due_date'] === $was['due_date']);
TasksService::saveTask($conn, $ctx, ['id' => $t2, 'due_date' => date('Y-m-d', strtotime('+9 days'))]);
ok('control: it changes, with its record', state($conn, $t2)['due_date'] === date('Y-m-d', strtotime('+9 days')));

section('3. A board move (REST /move)');
$t3 = zzTask($conn, 'ZZ-ATOM move');
$was = state($conn, $t3);
TaskHistory::$failNextWrite = 'status_changed';
$e = refused(fn() => TasksService::moveTask($conn, $ctx, $t3, ['status_id' => $closed, 'position' => 0]));
$now = state($conn, $t3);
ok('a failed record refuses the move: status and completion unchanged', $e instanceof ServiceError && $now['status_id'] === $was['status_id'] && $now['completed_datetime'] === null);
ok('...its board position unchanged (the re-pack rolled back with it)', $now['board_position'] === $was['board_position']);
TasksService::moveTask($conn, $ctx, $t3, ['status_id' => $closed, 'position' => 0]);
ok('control: it moves, with its record', (int)state($conn, $t3)['status_id'] === $closed
   && (int)$conn->query("SELECT COUNT(*) FROM task_events WHERE task_id = $t3 AND event_type = 'status_changed'")->fetchColumn() === 1);

section('4. A successor expected by an after-completion repeat');
$rid = zzSeries($conn, 'completion');
$t4 = zzTask($conn, 'ZZ-ATOM repeat', $rid);
TaskHistory::$failNextWrite = 'successor';
$e = refused(fn() => TasksService::saveTask($conn, $ctx, ['id' => $t4, 'status' => $closedName]));
ok('a failed successor EXPECTATION refuses the completion it belongs to', $e instanceof ServiceError && (int)state($conn, $t4)['status_id'] === $open);
ok('...and no successor was made', (int)$conn->query("SELECT COUNT(*) FROM tasks WHERE previous_occurrence_id = $t4")->fetchColumn() === 0);
TasksService::saveTask($conn, $ctx, ['id' => $t4, 'status' => $closedName]);
$exp = $conn->query("SELECT id FROM task_events WHERE task_id = $t4 AND event_type = 'successor'")->fetchColumn();
$succ = $conn->query("SELECT id, created_datetime FROM tasks WHERE previous_occurrence_id = $t4")->fetch(PDO::FETCH_ASSOC);
ok('control: the expectation is recorded with the completion, and the successor made after', $exp && $succ);
if ($succ) $tids[] = (int)$succ['id'];

section('5. Skipping an occurrence');
$rid5 = zzSeries($conn, 'schedule');
$t5 = zzTask($conn, 'ZZ-ATOM skip', $rid5);
TaskHistory::$failNextWrite = 'skipped';
$e = refused(fn() => TasksService::skipOccurrence($conn, $ctx, $t5, 'Bank holiday'));
ok('a failed "skipped" record refuses the skip: still open', $e instanceof ServiceError && (int)state($conn, $t5)['status_id'] === $open && state($conn, $t5)['completed_datetime'] === null);
TasksService::skipOccurrence($conn, $ctx, $t5, 'Bank holiday');
ok('control: it is skipped, with its record', (int)$conn->query("SELECT COUNT(*) FROM task_events WHERE task_id = $t5 AND event_type = 'skipped'")->fetchColumn() === 1);

section('6. Deleting an occurrence');
$conn->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('tasks_evidence_delete_occurrence', 'allow') ON DUPLICATE KEY UPDATE setting_value = 'allow'");
$t6 = zzTask($conn, 'ZZ-ATOM delete', $rid5);
$conn->prepare("INSERT INTO task_comments (task_id, analyst_id, comment, created_datetime) VALUES (?, ?, 'ZZ evidence comment', UTC_TIMESTAMP())")->execute([$t6, $admin]);
foreach (['snapshot_read' => 'a failed READ of the record (not an empty section)',
          'removed_record' => 'a failed write of the retained record',
          'removed' => 'a failed "removed" event'] as $inject => $label) {
    TaskHistory::$failNextWrite = $inject;
    $e = refused(fn() => TasksService::deleteTask($conn, $ctx, $t6));
    $s6 = state($conn, $t6);
    ok("$label refuses the delete: the occurrence is still there, with its comment", $e instanceof ServiceError && $s6['exists'] === 1
       && (int)$conn->query("SELECT COUNT(*) FROM task_comments WHERE task_id = $t6")->fetchColumn() === 1, $e ? $e->getMessage() : 'no throw');
    ok('...and no retained record was left half-written', $s6['removed_records'] === 0);
}
TaskHistory::$failNextWrite = null;
TasksService::deleteTask($conn, $ctx, $t6);
$rec = $conn->query("SELECT snapshot_json FROM task_removed_records WHERE task_id = $t6")->fetchColumn();
ok('control: deleted, with a complete retained record (its comment included)', state($conn, $t6)['exists'] === 0 && $rec
   && str_contains((string)$rec, 'ZZ evidence comment'));

if ($BASE) {
    section('7. Over HTTP, with the DATABASE refusing the history row (a trigger)');
    $sess = 'zzat' . bin2hex(random_bytes(3));
    $sessDir = rtrim((string)(ini_get('session.save_path') ?: sys_get_temp_dir()), '/\\');
    $name = 'ZZ atomic tester';
    file_put_contents("$sessDir/sess_$sess", 'analyst_id|i:' . $admin . ';analyst_name|s:' . strlen($name) . ':"' . $name . '";csrf_token|s:8:"zzatcsrf";');
    $post = function (string $path, array $body) use ($BASE, $sess) {
        $ch = curl_init($BASE . 'api/tasks/' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => "PHPSESSID=$sess", CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: zzatcsrf']]);
        $r = json_decode((string)curl_exec($ch), true); return $r ?: [];
    };
    $t7 = zzTask($conn, 'ZZ-ATOM drag');
    $parent = zzTask($conn, 'ZZ-ATOM parent');
    $t8 = zzTask($conn, 'ZZ-ATOM subtask', null, $parent);
    try {
        $conn->exec("CREATE TRIGGER zz_atomic_refuse_events BEFORE INSERT ON task_events FOR EACH ROW
                     BEGIN IF NEW.task_id IN ($t7, $t8) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'zz atomic test refusal'; END IF; END");
        $r = $post('reorder.php', ['task_id' => $t7, 'new_status' => $closedName, 'positions' => [['id' => $t7, 'board_position' => 0]]]);
        $s7 = state($conn, $t7);
        ok('the board\'s drag into Done, with the history refused: answers failure', empty($r['success']), json_encode($r));
        ok('...and the card did not move: still open, not completed', (int)$s7['status_id'] === $open && $s7['completed_datetime'] === null);
        $r = $post('toggle_subtask.php', ['id' => $t8]);
        $s8 = state($conn, $t8);
        ok('ticking a subtask, with the history refused: answers failure, still open', empty($r['success']) && (int)$s8['status_id'] === $open, json_encode($r));
    } finally {
        $conn->exec("DROP TRIGGER IF EXISTS zz_atomic_refuse_events");
    }
    $r = $post('reorder.php', ['task_id' => $t7, 'new_status' => $closedName, 'positions' => [['id' => $t7, 'board_position' => 0]]]);
    ok('control: with the history accepted, the drag completes it', !empty($r['success']) && (int)state($conn, $t7)['status_id'] === $closed, json_encode($r));
    $r = $post('toggle_subtask.php', ['id' => $t8]);
    ok('control: and the tick ticks it', !empty($r['success']) && (int)state($conn, $t8)['status_id'] !== $open, json_encode($r));
    @unlink("$sessDir/sess_$sess");
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
