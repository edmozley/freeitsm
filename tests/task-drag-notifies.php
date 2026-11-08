<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Tasks: moving a task to another status on the BOARD tells the people on it,
 * exactly as changing the status from its menu does (GH #266).
 *
 * The board drag posts to api/tasks/reorder.php, which used to write the status
 * itself and dispatch no workflow event - and the bell notifications, emails and
 * workflows all hang off that dispatch. So a task dragged to Done told nobody.
 *
 *   FREEITSM_TEST_URL=http://localhost/<install> \
 *   FREEITSM_TEST_DATABASE=<the database that installation and config.php use> \
 *   php tests/task-drag-notifies.php
 *
 * Over HTTP, so it cannot sit in a transaction: two synthetic analysts and one
 * task, all tagged, deleted in finally. Control: with the old reorder.php the
 * first and third checks fail (no notification at all).
 *
 * @wiki Tasks-Developer-Guide
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/support/ticket-workspace-fixture.php';   // ticketWorkspaceHttp()

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$url = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$expected = getenv('FREEITSM_TEST_DATABASE');
if ($url === '' || !$expected || $expected !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_URL to the installation, and FREEITSM_TEST_DATABASE to the throwaway database it and config.php use (config.php's is {$dbName}).\n";
    exit(0);
}

// Static: both ways a status moves outside saveTask() announce it.
$reorder = (string)file_get_contents(__DIR__ . '/../api/tasks/reorder.php');
$service = (string)file_get_contents(__DIR__ . '/../includes/services/tasks.php');
check('the board drag announces the move', str_contains($reorder, 'TasksService::announceStatusMove('));
check('the REST move announces the move', substr_count($service, 'self::announceStatusMove(') >= 1);

$tag = 'zz-drag-' . bin2hex(random_bytes(3));
$password = bin2hex(random_bytes(20));
$made = ['analysts' => [], 'tasks' => []];

try {
    $status = function (string $name) use ($conn): void {
        $s = $conn->prepare("SELECT id FROM task_statuses WHERE name = ?");
        $s->execute([$name]);
        if (!$s->fetchColumn()) throw new RuntimeException("This install has no task status called $name.");
    };
    foreach (['To Do', 'In Progress', 'Done'] as $s) $status($s);

    $analyst = function (string $suffix) use ($conn, $tag, $password, &$made): array {
        $username = "$tag-$suffix";
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants,
                                              can_access_all_modules, password_changed_datetime)
                        VALUES (?, ?, ?, ?, 1, 0, 1, 0, UTC_TIMESTAMP())")
             ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $username, "$username@example.invalid"]);
        $id = $made['analysts'][] = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO analyst_modules (analyst_id, module_key, access_level) VALUES (?, 'tasks', 'full')")->execute([$id]);
        $conn->prepare("INSERT INTO user_preferences (analyst_id, preference_key, preference_value) VALUES (?, 'interface_language', 'en')")->execute([$id]);
        return ['id' => $id, 'username' => $username];
    };
    $mover = $analyst('mover');
    $owner = $analyst('owner');

    $todo = (int)$conn->query("SELECT id FROM task_statuses WHERE name = 'To Do'")->fetchColumn();
    $conn->prepare("INSERT INTO tasks (title, created_by_id, assigned_analyst_id, status_id, board_position) VALUES (?, ?, ?, ?, 0)")
         ->execute(["$tag task", $mover['id'], $owner['id'], $todo]);
    $taskId = $made['tasks'][] = (int)$conn->lastInsertId();

    // Sign the mover in, and take the CSRF token from the Tasks page.
    $curl = curl_init();
    if (getenv('FREEITSM_TEST_INSECURE_TLS') === '1') curl_setopt_array($curl, [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
    $login = ticketWorkspaceHttp($curl, $url . '/auth/login.php', ['username' => $mover['username'], 'password' => $password]);
    if ($login['status'] !== 302) throw new RuntimeException('The synthetic analyst could not sign in.');
    $page = ticketWorkspaceHttp($curl, $url . '/tasks/');
    if (!preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $m)) throw new RuntimeException('No CSRF token on the Tasks page.');
    $csrf = html_entity_decode($m[1]);

    $drag = fn(string $to) => ticketWorkspaceHttp($curl, $url . '/api/tasks/reorder.php',
        ['task_id' => $taskId, 'new_status' => $to, 'positions' => [['id' => $taskId, 'board_position' => 0]]], $csrf);
    $told = function () use ($conn, $taskId, $owner): array {
        $s = $conn->prepare("SELECT event_type, event_count FROM notifications WHERE entity_type = 'task' AND entity_id = ? AND analyst_id = ?");
        $s->execute([$taskId, $owner['id']]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    };
    $statusCount = fn(array $rows) => array_sum(array_map(fn($r) => $r['event_type'] === 'task.status_changed' ? (int)$r['event_count'] : 0, $rows));

    $r = $drag('In Progress');
    check('dragging To Do -> In Progress is accepted', ($r['json']['success'] ?? false) === true, json_encode($r['json']));
    check('...and the owner is told the status changed', $statusCount($told()) === 1, json_encode($told()));

    $r = $drag('In Progress');
    check('reordering within one column tells nobody', ($r['json']['success'] ?? false) === true && $statusCount($told()) === 1, json_encode($told()));

    $r = $drag('Done');
    check('dragging to Done tells the owner again', ($r['json']['success'] ?? false) === true && $statusCount($told()) === 2, json_encode($told()));

    $mine = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE entity_type = 'task' AND entity_id = ? AND analyst_id = ?");
    $mine->execute([$taskId, $mover['id']]);
    check('the person who dragged it is not told about their own move', (int)$mine->fetchColumn() === 0);
} catch (Throwable $e) {
    check('the test ran: ' . $e->getMessage(), false);
} finally {
    foreach ($made['tasks'] as $id) {
        $conn->prepare("DELETE FROM notifications WHERE entity_type = 'task' AND entity_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM tasks WHERE id = ?")->execute([$id]);
    }
    foreach ($made['analysts'] as $id) {
        foreach (['notifications', 'analyst_modules', 'user_preferences'] as $t) {
            try { $conn->prepare("DELETE FROM `$t` WHERE analyst_id = ?")->execute([$id]); } catch (Throwable $e) {}
        }
        try { $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$id]); } catch (Throwable $e) { echo "  (cleanup: analyst $id - {$e->getMessage()})\n"; }
    }
}
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
