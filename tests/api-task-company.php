<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * REST API: the company of a task - through real HTTP.
 *
 *   FREEITSM_TEST_DATABASE=<db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/api-task-company.php
 *
 * BOTH are required (FREEITSM_BASE_URL is read when FREEITSM_TEST_URL is not set): the
 * database this writes to, and the install it calls - which must be the same one.
 *
 * Proves, on POST /tasks: company_id puts the task in that company and the response
 * says so (`company`); tenant_id still works as the older name; the two naming
 * different companies is 422; a company that does not exist is 422 (it used to reach
 * the INSERT and come back 500); one outside the key's list is 403. With no company,
 * a key that cannot reach Default gets its own company - and can read the task it
 * just made (it used to land in Default and 404) - while a key that reaches Default
 * still gets Default. A change or parent task in a company the key cannot reach is
 * "unknown" (422), as a ticket already was; a task linked to a change takes the
 * change's company; a company that disagrees with the linked change, ticket or parent
 * is 422, and so is a ticket and a change in two companies; naming Default for a
 * ticket stored with no company is a match. GET /tasks?company_id= filters (Default
 * includes tasks stored with none) and refuses a company outside the key (403). PATCH
 * refuses another company (422) and accepts the one the task is in. Every refusal has
 * a positive control. Its companies (ZZ-named), keys, ticket, change and tasks are
 * removed at the end. Task workflows that are on are paused for the run and switched
 * back on at the end, so none fires against the fixtures.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "  <- $detail" : '') . "\n"; }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$base = rtrim((string)(getenv('FREEITSM_TEST_URL') ?: getenv('FREEITSM_BASE_URL')), '/');
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName || $base === '') {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE (this database is $dbName) and FREEITSM_TEST_URL to the install that uses it.\n";
    exit(0);
}

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 AND can_access_all_tenants = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$admin) { echo "SKIPPED: no active administrator with access to every company.\n"; exit(0); }
$defaultId = (int)$conn->query("SELECT id FROM tenants WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$defaultId) { echo "SKIPPED: no Default company (run Database Verification).\n"; exit(0); }

$tag = 'zztc' . bin2hex(random_bytes(3));
$tenantIds = []; $keyIds = []; $taskIds = []; $ticketIds = []; $changeIds = [];

$newTenant = function (string $name) use ($conn, $tag, &$tenantIds): int {
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)")
         ->execute(["ZZ $name $tag", "zz-$name-$tag"]);
    return $tenantIds[] = (int)$conn->lastInsertId();
};
$newKey = function (string $name, array $perms, ?array $companies) use ($conn, $admin, &$keyIds): string {
    $raw = 'zzk_' . bin2hex(random_bytes(20));
    $conn->prepare("INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, active) VALUES (?, ?, ?, ?, ?, ?, 1)")
         ->execute(["ZZ $name", substr($raw, 0, 12), hash('sha256', $raw), $admin, json_encode($perms), $companies === null ? null : json_encode($companies)]);
    $keyIds[] = (int)$conn->lastInsertId();
    return $raw;
};
$call = function (string $key, string $method, string $path, ?array $body = null) use ($base): array {
    $ch = curl_init("$base/api/v1$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(["Authorization: Bearer $key"], $body !== null ? ['Content-Type: application/json'] : [])]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$out, true) ?: ['raw' => $out]];
};
// POST /tasks, remembering what it made so it is always cleaned up.
$create = function (string $key, array $body) use ($call, $tag, &$taskIds): array {
    $body['title'] = 'ZZ ' . ($body['title'] ?? 'task') . " $tag";
    [$code, $r] = $call($key, 'POST', '/tasks', $body);
    if ($code === 201 && isset($r['data']['id'])) $taskIds[] = (int)$r['data']['id'];
    return [$code, $r];
};
$companyOf = function (array $r): ?int { return isset($r['data']['company']['id']) ? (int)$r['data']['company']['id'] : null; };
$stored = function (int $taskId) use ($conn) {
    $s = $conn->prepare("SELECT tenant_id FROM tasks WHERE id = ?"); $s->execute([$taskId]);
    $v = $s->fetchColumn(); return $v === null ? null : (int)$v;
};
$err = function (array $r): string { return json_encode($r['error'] ?? $r); };

$perms = ['tasks' => ['read', 'create', 'update', 'delete']];

// Never fire workflows: every POST/PATCH here would run this install's task.*
// workflows (emails, assignments) against the ZZ tasks. Those that are on are
// switched off for the run and switched back on in `finally`, by id.
$pausedWorkflows = [];
try {
    $pausedWorkflows = array_map('intval', $conn->query("SELECT id FROM workflows WHERE is_active = 1 AND trigger_event LIKE 'task.%'")->fetchAll(PDO::FETCH_COLUMN));
} catch (PDOException $e) { /* no workflows table on this install: nothing to fire */ }
if ($pausedWorkflows) {
    $conn->exec("UPDATE workflows SET is_active = 0 WHERE id IN (" . implode(',', $pausedWorkflows) . ")");
    echo "  (" . count($pausedWorkflows) . " task workflow(s) paused for the run)
";
}
try {
    $a = $newTenant('alpha');
    $b = $newTenant('bravo');

    // A ticket in Bravo, a ticket stored with no company (= Default), a change in Bravo.
    $mkTicket = function (?int $tenant) use ($conn, $tag, &$ticketIds): int {
        $conn->prepare("INSERT INTO tickets (ticket_number, subject, tenant_id, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
             ->execute(['ZZ-' . strtoupper(bin2hex(random_bytes(4))), "ZZ ticket $tag", $tenant]);
        return $ticketIds[] = (int)$conn->lastInsertId();
    };
    $ticketB    = $mkTicket($b);
    $ticketNone = $mkTicket(null);
    $conn->prepare("INSERT INTO changes (title, tenant_id) VALUES (?, ?)")->execute(["ZZ change $tag", $b]);
    $changeB = $changeIds[] = (int)$conn->lastInsertId();

    $full   = $newKey('task company: all', $perms, null);
    $alpha  = $newKey('task company: alpha', $perms, [$a]);

    echo "POST /tasks - company_id\n";
    [$c, $r] = $create($full, ['company_id' => $a]);
    ok('company_id puts the task in that company (201)', $c === 201 && $companyOf($r) === $a && $stored($r['data']['id'] ?? 0) === $a, "$c " . $err($r));
    ok('the response names the company', ($r['data']['company']['name'] ?? '') === "ZZ alpha $tag", json_encode($r['data']['company'] ?? null));
    $taskA = (int)($r['data']['id'] ?? 0);

    [$c, $r] = $create($full, ['tenant_id' => $b]);
    ok('tenant_id still works as the older name', $c === 201 && $companyOf($r) === $b, "$c " . $err($r));
    $taskB = (int)($r['data']['id'] ?? 0);

    [$c, $r] = $create($full, ['company_id' => $a, 'tenant_id' => $b]);
    ok('company_id and tenant_id naming different companies is 422', $c === 422, "$c " . $err($r));
    [$c, $r] = $create($full, ['company_id' => $a, 'tenant_id' => $a]);
    ok('  control: the same company in both is fine', $c === 201 && $companyOf($r) === $a, "$c " . $err($r));

    $missing = (int)$conn->query("SELECT COALESCE(MAX(id), 0) + 1000 FROM tenants")->fetchColumn();
    [$c, $r] = $create($full, ['company_id' => $missing]);
    ok('a company that does not exist is 422 (was 500)', $c === 422, "$c " . $err($r));
    [$c, $r] = $create($full, ['tenant_id' => $missing]);
    ok('  ...and by its older name too', $c === 422, "$c " . $err($r));

    [$c, $r] = $create($alpha, ['company_id' => $b]);
    ok('a company outside the key is 403', $c === 403, "$c " . $err($r));
    [$c, $r] = $create($alpha, ['company_id' => $a]);
    ok('  control: the key\'s own company is fine', $c === 201 && $companyOf($r) === $a, "$c " . $err($r));

    echo "POST /tasks - no company\n";
    [$c, $r] = $create($alpha, []);
    $id = (int)($r['data']['id'] ?? 0);
    ok('a key that cannot reach Default gets its own company', $c === 201 && $companyOf($r) === $a && $stored($id) === $a, "$c " . $err($r));
    [$c2, $r2] = $call($alpha, 'GET', "/tasks/$id");
    ok('  ...and can read the task it just made (was 404)', $c2 === 200, "$c2 " . $err($r2));
    [$c, $r] = $create($full, []);
    ok('  control: a key that reaches Default still gets Default', $c === 201 && $companyOf($r) === $defaultId && $stored($r['data']['id'] ?? 0) === null, "$c " . $err($r));

    echo "POST /tasks - links\n";
    [$c, $r] = $create($alpha, ['change_id' => $changeB]);
    ok('a change in a company the key cannot reach is unknown (422)', $c === 422 && str_contains($r['error']['message'] ?? '', 'Unknown change id'), "$c " . $err($r));
    [$c, $r] = $create($full, ['change_id' => $changeB]);
    ok('  control: a key that reaches it may link it, and the task takes the change\'s company', $c === 201 && $companyOf($r) === $b, "$c " . $err($r));
    [$c, $r] = $create($full, ['change_id' => $changeB, 'company_id' => $a]);
    ok('a company that disagrees with the change is 422', $c === 422, "$c " . $err($r));
    [$c, $r] = $create($full, ['ticket_id' => $ticketNone, 'change_id' => $changeB]);
    ok('a ticket and a change in different companies is 422', $c === 422, "$c " . $err($r));
    [$c, $r] = $create($full, ['ticket_id' => $ticketB, 'change_id' => $changeB]);
    ok('  control: a ticket and a change in the same company is fine', $c === 201 && $companyOf($r) === $b, "$c " . $err($r));
    [$c, $r] = $create($full, ['ticket_id' => $ticketB, 'company_id' => $a]);
    ok('a company that disagrees with the ticket is 422', $c === 422, "$c " . $err($r));
    [$c, $r] = $create($full, ['ticket_id' => $ticketNone, 'company_id' => $defaultId]);
    ok('naming Default for a ticket stored with no company is a match (was 422)', $c === 201 && $companyOf($r) === $defaultId, "$c " . $err($r));

    [$c, $r] = $create($alpha, ['parent_task_id' => $taskB]);
    ok('a parent task in a company the key cannot reach is unknown (422)', $c === 422 && str_contains($r['error']['message'] ?? '', 'Unknown parent_task id'), "$c " . $err($r));
    [$c, $r] = $create($alpha, ['parent_task_id' => $taskA]);
    ok('  control: a parent in the key\'s company is fine, and the subtask is there', $c === 201 && $companyOf($r) === $a, "$c " . $err($r));
    [$c, $r] = $create($full, ['parent_task_id' => $taskB, 'company_id' => $a]);
    ok('a company that disagrees with the parent task is 422 (was silently ignored)', $c === 422, "$c " . $err($r));
    [$c, $r] = $create($full, ['parent_task_id' => $taskB, 'company_id' => $b]);
    ok('  control: the parent\'s own company is fine', $c === 201 && $companyOf($r) === $b, "$c " . $err($r));

    echo "GET /tasks?company_id=\n";
    $listed = function (string $key, array $q) use ($call): array {
        [$c, $r] = $call($key, 'GET', '/tasks?per_page=100&q=' . rawurlencode($q['q']) . (isset($q['company_id']) ? '&company_id=' . $q['company_id'] : ''));
        return [$c, array_map(fn($t) => (int)$t['company']['id'], $r['data'] ?? [])];
    };
    [$c, $cos] = $listed($full, ['q' => $tag, 'company_id' => $a]);
    ok('filters to that company', $c === 200 && $cos && count(array_unique($cos)) === 1 && $cos[0] === $a, "$c " . json_encode($cos));
    [$c, $cos] = $listed($full, ['q' => $tag, 'company_id' => $defaultId]);
    ok('Default includes tasks stored with no company', $c === 200 && $cos && count(array_unique($cos)) === 1 && $cos[0] === $defaultId, "$c " . json_encode($cos));
    [$c, $cos] = $listed($alpha, ['q' => $tag, 'company_id' => $b]);
    ok('a company outside the key is 403', $c === 403, (string)$c);
    [$c, $cos] = $listed($full, ['q' => $tag]);
    ok('  control: without it, every company is listed', $c === 200 && count(array_unique($cos)) >= 3, json_encode(array_unique($cos)));

    echo "PATCH /tasks/{id}\n";
    [$c, $r] = $call($full, 'PATCH', "/tasks/$taskA", ['company_id' => $b]);
    ok('another company is refused (422, was silently ignored)', $c === 422 && $stored($taskA) === $a, "$c " . $err($r));
    [$c, $r] = $call($full, 'PATCH', "/tasks/$taskA", ['tenant_id' => $b]);
    ok('  ...by its older name too', $c === 422, "$c " . $err($r));
    [$c, $r] = $call($full, 'PATCH', "/tasks/$taskA", ['company_id' => $a, 'description' => "ZZ patched $tag"]);
    ok('  control: the company it is already in is accepted, and the rest applies', $c === 200 && ($r['data']['description'] ?? '') === "ZZ patched $tag" && $companyOf($r) === $a, "$c " . $err($r));
} finally {
    if ($pausedWorkflows) {
        $conn->exec("UPDATE workflows SET is_active = 1 WHERE id IN (" . implode(',', $pausedWorkflows) . ")");
    }
    // Tasks first, through the API's own delete (it removes subtasks and history with
    // them; a subtask already gone with its parent answers 404). Then what they pointed at.
    if ($taskIds && isset($full)) {
        foreach (array_reverse($taskIds) as $t) {
            [$c] = $call($full, 'DELETE', "/tasks/$t");
            if ($c !== 200 && $c !== 404) echo "  cleanup: DELETE /tasks/$t answered $c\n";
        }
        $left = $conn->query("SELECT COUNT(*) FROM tasks WHERE id IN (" . implode(',', array_map('intval', $taskIds)) . ")")->fetchColumn();
        if ($left) echo "  cleanup: $left task(s) left behind\n";
    }
    foreach ($changeIds as $id) $conn->prepare("DELETE FROM changes WHERE id = ?")->execute([$id]);
    foreach ($ticketIds as $id) $conn->prepare("DELETE FROM tickets WHERE id = ?")->execute([$id]);
    foreach ($keyIds as $id)    $conn->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$id]);
    foreach ($tenantIds as $id) $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$id]);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
