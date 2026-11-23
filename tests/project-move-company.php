<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Move a project to another company - the app and the REST API (GH #370).
 *
 *   FREEITSM_TEST_DATABASE=<db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/project-move-company.php
 *
 * A project's company was fixed when it was created: PATCH ignored company_id
 * without a word, and the only fix was to recreate the project. Now
 * ProjectsService::moveProjectToCompany() moves the project, its tasks and their
 * subtasks, refusing - and saying why - when someone on the project could not see
 * the target company or a task is linked to a ticket in another company. A single
 * task in a project can no longer be moved out on its own.
 *
 * Over real HTTP: POST /api/v1/projects/{id}/move-to-company and PATCH with an API
 * key, and the app's api/tasks/move_to_company.php with a session written for the
 * call. Writes (all named ZZ, all removed in finally): two companies, an API key,
 * a project with a task and a subtask, a location, a person, a ticket.
 * Pauses this install's project.* and task.* workflows for the run.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$pass = 0; $fail = 0;
/**
 * Records and prints one PASS or FAIL line.
 *
 * @param string $what  what is being checked, in a few words
 * @param bool $cond  true when the check passed
 * @param string $detail  shown after a failure - the values that disagreed
 */
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nMove a project to another company (GH #370)\n" . str_repeat('=', 70) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName || $base === '') {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE (this database is $dbName) and FREEITSM_TEST_URL to the install that uses it.\n";
    exit(0);
}
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 AND can_access_all_tenants = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$admin) { echo "SKIPPED: no active administrator with access to every company.\n"; exit(0); }

$tag = 'zzpm' . bin2hex(random_bytes(3));
$tenants = []; $keyId = 0; $projectId = 0; $taskIds = []; $locationId = 0; $userId = 0; $ticketId = 0;
$raw = 'zzk_' . bin2hex(random_bytes(20));
$call = function (string $method, string $path, ?array $body = null) use ($base, &$raw): array {
    $ch = curl_init("$base/api/v1$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(["Authorization: Bearer $raw"], $body !== null ? ['Content-Type: application/json'] : [])]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode((string)$out, true) ?: ['raw' => substr((string)$out, 0, 300)]];
};
/** POSTs to an app endpoint as the administrator, with a session file written for the call. */
$app = function (string $path, array $body) use ($base, $admin): array {
    $sid = 'zzpm' . bin2hex(random_bytes(8)); $token = bin2hex(random_bytes(16));
    $file = rtrim((string)ini_get('session.save_path'), '/\\') . "/sess_$sid";
    file_put_contents($file, 'analyst_id|i:' . $admin . ';csrf_token|s:32:"' . $token . '";');
    $ch = curl_init("$base/$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Cookie: PHPSESSID=$sid", "X-CSRF-Token: $token"]]);
    $out = (string)curl_exec($ch); @unlink($file);
    return json_decode($out, true) ?: ['raw' => substr($out, 0, 300)];
};
$tenantOf = function (string $table, int $id) use ($conn) {
    $s = $conn->prepare("SELECT tenant_id FROM `$table` WHERE id = ?"); $s->execute([$id]); $v = $s->fetchColumn();
    return $v === null ? null : (int)$v;
};
$err = fn(array $r): string => json_encode($r['error'] ?? $r);

$paused = [];
try {
    $paused = array_map('intval', $conn->query("SELECT id FROM workflows WHERE is_active = 1 AND (trigger_event LIKE 'project.%' OR trigger_event LIKE 'task.%')")->fetchAll(PDO::FETCH_COLUMN));
} catch (PDOException $e) { /* no workflows table */ }
if ($paused) $conn->exec("UPDATE workflows SET is_active = 0 WHERE id IN (" . implode(',', $paused) . ")");

try {
    foreach (['alpha', 'bravo'] as $n) {
        $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)")->execute(["ZZ $n $tag", "zz-$n-$tag"]);
        $tenants[$n] = (int)$conn->lastInsertId();
    }
    $a = $tenants['alpha']; $b = $tenants['bravo'];
    $default = (int)$conn->query("SELECT id FROM tenants WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $conn->prepare("INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, active) VALUES (?, ?, ?, ?, ?, NULL, 1)")
         ->execute(["ZZ project move $tag", substr($raw, 0, 12), hash('sha256', $raw), $admin,
                    json_encode(['projects' => ['read', 'create', 'update', 'delete'], 'tasks' => ['read', 'update']])]);
    $keyId = (int)$conn->lastInsertId();

    // A project in the Default company, run by the administrator, at a Default-only location.
    $conn->prepare("INSERT INTO asset_locations (name, tenant_id) VALUES (?, ?)")->execute(["ZZ room $tag", $default]);
    $locationId = (int)$conn->lastInsertId();
    [$c, $r] = $call('POST', '/projects', ['name' => "ZZ project $tag", 'company_id' => $default, 'project_manager_id' => $admin, 'location_id' => $locationId]);
    $projectId = (int)($r['data']['id'] ?? 0);
    ok('setup: a project in the Default company', $c === 201 && $projectId > 0, "$c " . $err($r));
    $conn->prepare("UPDATE projects SET location_id = ? WHERE id = ?")->execute([$locationId, $projectId]);
    foreach ([null, 'sub'] as $kind) {
        $conn->prepare("INSERT INTO tasks (title, created_by_id, project_id, parent_task_id, tenant_id) VALUES (?, ?, ?, ?, NULL)")
             ->execute(["ZZ task $kind $tag", $admin, $kind ? null : $projectId, $kind ? $taskIds[0] : null]);
        $taskIds[] = (int)$conn->lastInsertId();
    }
    [$task, $subtask] = $taskIds;

    echo "\nRefusals\n";
    [$c, $r] = $call('PATCH', "/projects/$projectId", ['company_id' => $a, 'health' => 'red']);   // with a real field, or the old code's 'no fields' 422 would pass it
    ok('PATCH with another company_id is refused (422), not ignored', $c === 422, "$c " . $err($r));
    [$c, $r] = $call('PATCH', "/projects/$projectId", ['company_id' => $default, 'health' => 'amber']);
    ok('  control: PATCH naming the company it is in is accepted', $c === 200, "$c " . $err($r));

    [$c, $r] = $call('POST', "/projects/$projectId/move-to-company", ['company_id' => 999999]);
    ok('an unknown company is 422', $c === 422, "$c " . $err($r));

    // A person from People in the Default company is on the project.
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())")->execute(["$tag@example.invalid", "ZZ Member $tag", $default]);
    $userId = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO project_members (project_id, user_id) VALUES (?, ?)")->execute([$projectId, $userId]);
    [$c, $r] = $call('POST', "/projects/$projectId/move-to-company", ['company_id' => $a]);
    ok('a member who would not belong to the new company blocks the move, by name', $c === 422 && str_contains($err($r), "ZZ Member $tag"), "$c " . $err($r));
    ok('...and nothing moved', $tenantOf('projects', $projectId) === null && $tenantOf('tasks', $task) === null);
    $conn->prepare("DELETE FROM project_members WHERE project_id = ?")->execute([$projectId]);

    // The task is linked to a ticket in Bravo.
    $conn->prepare("INSERT INTO tickets (ticket_number, subject, tenant_id, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
         ->execute(['ZZ-' . strtoupper(bin2hex(random_bytes(4))), "ZZ ticket $tag", $b]);
    $ticketId = (int)$conn->lastInsertId();
    $conn->prepare("UPDATE tasks SET ticket_id = ? WHERE id = ?")->execute([$ticketId, $task]);
    [$c, $r] = $call('POST', "/projects/$projectId/move-to-company", ['company_id' => $a]);
    ok("a task linked to another company's ticket blocks the move", $c === 422 && str_contains($err($r), 'ticket'), "$c " . $err($r));
    $conn->prepare("UPDATE tasks SET ticket_id = NULL WHERE id = ?")->execute([$task]);

    $r = $app('api/tasks/move_to_company.php', ['task_id' => $task, 'tenant_id' => $a]);
    ok('a task in the project cannot be moved out on its own (app)', empty($r['success']) && str_contains((string)($r['error'] ?? ''), 'project'), json_encode($r));

    echo "\nThe move\n";
    [$c, $r] = $call('POST', "/projects/$projectId/move-to-company", ['company_id' => $a]);
    ok('the project moves (200)', $c === 200 && ($r['data']['project']['company']['id'] ?? $r['data']['project']['company_id'] ?? null) == $a, "$c " . $err($r));
    ok('...its task and subtask move with it', ($r['data']['moved_tasks'] ?? 0) === 2 && $tenantOf('tasks', $task) === $a && $tenantOf('tasks', $subtask) === $a,
        json_encode([$r['data']['moved_tasks'] ?? null, $tenantOf('tasks', $task), $tenantOf('tasks', $subtask)]));
    $loc = $conn->prepare("SELECT location_id FROM projects WHERE id = ?"); $loc->execute([$projectId]);
    ok("...the Default company's location is cleared, and the answer says so", $loc->fetchColumn() === null && ($r['data']['location_cleared'] ?? false) === true);
    $h = $conn->prepare("SELECT COUNT(*) FROM project_audit WHERE project_id = ? AND field_name = 'company'"); $h->execute([$projectId]);
    ok('...written to the project history', (int)$h->fetchColumn() === 1);
    $h = $conn->prepare("SELECT COUNT(*) FROM task_audit WHERE task_id IN (?, ?) AND field_name = 'Company'"); $h->execute([$task, $subtask]);
    ok("...and to each task's", (int)$h->fetchColumn() === 2);

    $conn->prepare("UPDATE api_keys SET company_ids = ? WHERE id = ?")->execute([json_encode([$a]), $keyId]);
    [$c, $r] = $call('POST', "/projects/$projectId/move-to-company", ['company_id' => $b]);
    ok("a company outside the key's list is 403", $c === 403, "$c " . $err($r));
} finally {
    if ($projectId) {
        $conn->prepare("UPDATE api_keys SET company_ids = NULL WHERE id = ?")->execute([$keyId]);
        $conn->prepare("UPDATE tasks SET project_id = NULL WHERE project_id = ?")->execute([$projectId]);
        [$c] = $call('DELETE', "/projects/$projectId");
        if ($c >= 300) $conn->prepare("DELETE FROM projects WHERE id = ?")->execute([$projectId]);
    }
    if ($taskIds) {
        $in = implode(',', array_fill(0, count($taskIds), '?'));
        foreach (['task_audit', 'task_events'] as $t) { try { $conn->prepare("DELETE FROM `$t` WHERE task_id IN ($in)")->execute($taskIds); } catch (PDOException $e) {} }
        $conn->prepare("DELETE FROM tasks WHERE id IN ($in) ORDER BY parent_task_id IS NULL")->execute($taskIds);
    }
    if ($ticketId) $conn->prepare("DELETE FROM tickets WHERE id = ?")->execute([$ticketId]);
    if ($userId) $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    if ($locationId) $conn->prepare("DELETE FROM asset_locations WHERE id = ?")->execute([$locationId]);
    if ($keyId) $conn->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$keyId]);
    foreach ($tenants as $t) $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$t]);
    if ($paused) $conn->exec("UPDATE workflows SET is_active = 1 WHERE id IN (" . implode(',', $paused) . ")");
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM projects WHERE name = 'ZZ project $tag') + (SELECT COUNT(*) FROM tasks WHERE title LIKE 'ZZ task % $tag')
                                    + (SELECT COUNT(*) FROM tenants WHERE slug LIKE 'zz-%-$tag') + (SELECT COUNT(*) FROM users WHERE email = '$tag@example.invalid')")->fetchColumn();
    echo "\n";
    ok('everything the test wrote is gone', $left === 0, "$left rows left");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
