<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Changes: move a change to another company (GH #390).
 *
 *   php tests/change-move-company.php      (run from a TEST install, never a real one)
 *
 * ChangesService::moveToCompany():
 *   - moves the change with the tasks linked to it and their subtasks, in one go,
 *     and records the move in the change's history and each task's;
 *   - refuses, and moves NOTHING, when a linked ticket is in another company, or a
 *     linked task cannot follow: it is in a project, or linked to a ticket, a
 *     contract or another change, in another company, or it is a subtask whose
 *     parent stays behind;
 *   - refuses an analyst with read-only Changes, and - when tasks would move -
 *     read-only or no Tasks; a linked task outside the actor's companies is refused;
 *   - does not count a deleted ticket's link;
 *   - refuses analyst 0, a target outside the actor's companies and an unknown company;
 *   - moving to the company it is in does nothing.
 *
 * Writes, and removes in a finally block by the ids it got back:
 *   - one company "ZZ Change move" (a single-company install becomes multi-company
 *     for the run - that is why this must be a test install);
 *   - two changes, ZZ-named tasks, tickets, a project, a contract and three
 *     analysts with limited module access.
 * Never fires a workflow: moving a change dispatches no event.
 * Control: drop a refusal from moveToCompany() and its check fails; drop the task
 * update and "the linked task and its subtask move" fails.
 *
 * With FREEITSM_TEST_URL=<this install's address> it also checks the REST API over
 * real HTTP, with a ZZ API key (removed in finally): PATCH refuses a different
 * company_id and accepts the current one, POST /changes/{id}/move-to-company moves
 * and refuses an unknown company. Without it those checks are skipped.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/services/changes.php';

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

/**
 * Runs a move and returns the ServiceError message it refused with, or null when it moved.
 *
 * @param int $id  the change to move
 * @param int $to  the target company
 */
function refusal(PDO $conn, ActorContext $ctx, int $id, int $to): ?string {
    try { ChangesService::moveToCompany($conn, $ctx, $id, $to); return null; }
    catch (ServiceError $e) { return $e->getMessage(); }
}

/**
 * The stored company of a row, normalised: NULL is the Default company.
 *
 * @param string $table  'changes' or 'tasks' - a literal in this file
 * @param int $id  the row
 */
function companyOf(PDO $conn, string $table, int $id): int {
    $t = $conn->query("SELECT tenant_id FROM `$table` WHERE id = " . (int)$id)->fetchColumn();
    return $t === null ? (int)getDefaultTenantId($conn) : (int)$t;
}

echo "\nChanges: move a change to another company (GH #390)\n" . str_repeat('=', 70) . "\n";
$conn = connectToDatabase();
$db = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
echo "  database: $db\n";
if (!preg_match('/test/i', $db)) { echo "  STOP  this test adds a company; run it only on a test install\n"; exit(1); }

$viewer = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND can_access_all_tenants = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$viewer) { echo "  SKIP  no active analyst with access to every company\n"; exit(0); }

$tenantZ = 0; $tenantY = 0; $changeId = 0; $change2 = 0;
$made = ['tasks' => [], 'tickets' => [], 'projects' => [], 'contracts' => [], 'api_keys' => [], 'analysts' => []];
try {
    // The company first: tenantCount() caches, and nothing may ask it before this.
    $conn->prepare("INSERT INTO tenants (name) VALUES (?)")->execute(['ZZ Change move']);
    $tenantZ = (int)$conn->lastInsertId();
    $default = (int)getDefaultTenantId($conn);
    ok('the install is multi-company for the run', isMultiTenant($conn));

    $_SESSION = ['analyst_id' => $viewer];
    $ctx = ActorContext::fromSession($conn);

    $changeId = ChangesService::createChange($conn, $ctx, $default, ['title' => 'ZZ change move']);
    $task = function (string $title, array $cols = []) use ($conn, $viewer, &$made): int {
        $cols += ['tenant_id' => null];
        $names = implode(', ', array_keys($cols));
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $conn->prepare("INSERT INTO tasks (title, created_by_id, $names) VALUES (?, ?, $ph)")
             ->execute(array_merge([$title, $viewer], array_values($cols)));
        return $made['tasks'][] = (int)$conn->lastInsertId();
    };
    $linked = $task('ZZ linked task', ['change_id' => $changeId]);
    $child  = $task('ZZ subtask of linked', ['parent_task_id' => $linked]);
    $other  = $task('ZZ unrelated task');
    $moving = [$linked, $child];
    $inDefault = fn() => companyOf($conn, 'changes', $changeId) === $default
        && companyOf($conn, 'tasks', $linked) === $default && companyOf($conn, 'tasks', $child) === $default;

    echo "\nRefusals move nothing\n";
    $conn->prepare("INSERT INTO tickets (ticket_number, subject, tenant_id) VALUES ('ZZCM-1', 'ZZ change move ticket', NULL)")->execute();
    $ticket = $made['tickets'][] = (int)$conn->lastInsertId();
    ChangesService::linkTicket($conn, $ctx, $changeId, ['ticket_id' => $ticket]);
    $r = refusal($conn, $ctx, $changeId, $tenantZ);
    ok('a linked ticket in another company is refused, naming it', $r !== null && str_contains($r, 'ZZCM-1'), (string)$r);
    // Deleted, the link stays but no longer counts: nobody can see or unlink it.
    $conn->prepare("UPDATE tickets SET deleted_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$ticket]);

    $conn->prepare("INSERT INTO projects (name, tenant_id) VALUES ('ZZ change move project', NULL)")->execute();
    $project = $made['projects'][] = (int)$conn->lastInsertId();
    $conn->prepare("UPDATE tasks SET project_id = ? WHERE id = ?")->execute([$project, $linked]);
    $r = refusal($conn, $ctx, $changeId, $tenantZ);
    ok('a linked task in a project in another company is refused', $r !== null && str_contains($r, 'ZZ change move project'), (string)$r);
    $conn->prepare("UPDATE tasks SET project_id = NULL WHERE id = ?")->execute([$linked]);

    $conn->prepare("INSERT INTO tickets (ticket_number, subject, tenant_id) VALUES ('ZZCM-2', 'ZZ task ticket', NULL)")->execute();
    $taskTicket = $made['tickets'][] = (int)$conn->lastInsertId();
    // On the SUBTASK: anything in the moving set that cannot follow stops the move.
    $conn->prepare("UPDATE tasks SET ticket_id = ? WHERE id = ?")->execute([$taskTicket, $child]);
    $r = refusal($conn, $ctx, $changeId, $tenantZ);
    ok('a moving subtask linked to a ticket in another company is refused', $r !== null && str_contains($r, 'linked to a ticket'), (string)$r);
    $conn->prepare("UPDATE tasks SET ticket_id = NULL WHERE id = ?")->execute([$child]);

    if (contractTenantReady($conn)) {
        $conn->prepare("INSERT INTO contracts (contract_number, title, tenant_id) VALUES ('ZZCM-C1', 'ZZ change move contract', NULL)")->execute();
        $contract = $made['contracts'][] = (int)$conn->lastInsertId();
        $conn->prepare("UPDATE tasks SET contract_id = ? WHERE id = ?")->execute([$contract, $linked]);
        $r = refusal($conn, $ctx, $changeId, $tenantZ);
        ok('a linked task on a contract in another company is refused', $r !== null && str_contains($r, 'contract'), (string)$r);
        $conn->prepare("UPDATE tasks SET contract_id = NULL WHERE id = ?")->execute([$linked]);
    } else {
        echo "  SKIP  contracts have no company on this install yet\n";
    }

    // A subtask linked on its own, whose parent is not linked and stays in Default.
    $conn->prepare("UPDATE tasks SET change_id = ? WHERE id = ?")->execute([$changeId, $other]);
    $lone = $task('ZZ lone linked subtask', ['parent_task_id' => $other]);
    $conn->prepare("UPDATE tasks SET change_id = NULL WHERE id = ?")->execute([$other]);
    $conn->prepare("UPDATE tasks SET change_id = ? WHERE id = ?")->execute([$changeId, $lone]);
    $r = refusal($conn, $ctx, $changeId, $tenantZ);
    ok('a linked subtask whose parent stays behind is refused, naming the parent', $r !== null && str_contains($r, 'ZZ unrelated task'), (string)$r);
    $conn->prepare("UPDATE tasks SET change_id = NULL WHERE id = ?")->execute([$lone]);

    // The SUBTASK carries a change of its own: moving this change must not drag it away.
    $change2 = ChangesService::createChange($conn, $ctx, $default, ['title' => 'ZZ change move other']);
    $conn->prepare("UPDATE tasks SET change_id = ? WHERE id = ?")->execute([$change2, $child]);
    $r = refusal($conn, $ctx, $changeId, $tenantZ);
    ok('a moving subtask linked to another change in another company is refused', $r !== null && str_contains($r, 'another change'), (string)$r);
    $conn->prepare("UPDATE tasks SET change_id = NULL WHERE id = ?")->execute([$child]);

    ok('after every refusal, the change and its tasks are still in Default', $inDefault());

    echo "\nModule access\n";
    // A fresh analyst per case: analystModuleLevel() caches per analyst and module.
    $limited = function (string $name, array $modules) use ($conn, &$made): ActorContext {
        $conn->prepare("INSERT INTO analysts (username, full_name, email, password_hash, is_active, can_access_all_tenants, can_access_all_modules) VALUES (?, ?, ?, '!', 1, 1, 0)")
             ->execute([$name, $name, $name . '@example.invalid']);
        $id = $made['analysts'][] = (int)$conn->lastInsertId();
        foreach ($modules as $key => $level) {
            $conn->prepare("INSERT INTO analyst_modules (analyst_id, module_key, access_level) VALUES (?, ?, ?)")->execute([$id, $key, $level]);
        }
        return new ActorContext(actorId: $id, companyScope: null);
    };
    $r = refusal($conn, $limited('zz_cm_chg_read', ['changes' => 'read', 'tasks' => 'full']), $changeId, $tenantZ);
    ok('read-only Changes is refused', $r !== null && str_contains($r, 'read-only'), (string)$r);
    $r = refusal($conn, $limited('zz_cm_tsk_read', ['changes' => 'full', 'tasks' => 'read']), $changeId, $tenantZ);
    ok('read-only Tasks is refused when tasks would move', $r !== null && str_contains($r, 'read-only'), (string)$r);
    $r = refusal($conn, $limited('zz_cm_tsk_none', ['changes' => 'full']), $changeId, $tenantZ);
    ok('no Tasks access is refused when tasks would move', $r !== null && str_contains($r, 'access to Tasks'), (string)$r);
    // A task linked to the change but already outside the actor's companies (data out of step).
    $conn->prepare("INSERT INTO tenants (name) VALUES ('ZZ Change move hidden')")->execute();
    $tenantY = (int)$conn->lastInsertId();
    $conn->prepare("UPDATE tasks SET tenant_id = ? WHERE id = ?")->execute([$tenantY, $child]);
    $scoped = new ActorContext(actorId: $viewer, companyScope: [$default, $tenantZ]);
    $r = refusal($conn, $scoped, $changeId, $tenantZ);
    ok('a linked task outside the actor\'s companies is refused, without its name',
        $r !== null && str_contains($r, 'company you do not have access to') && !str_contains($r, 'ZZ subtask'), (string)$r);
    $conn->prepare("UPDATE tasks SET tenant_id = NULL WHERE id = ?")->execute([$child]);
    ok('none of them moved anything', $inDefault());

    echo "\nWho may move\n";
    $nobody = new ActorContext(actorId: 0, companyScope: null);
    ok('analyst 0 is refused', refusal($conn, $nobody, $changeId, $tenantZ) === 'You do not have access to that company.');
    $oneCo = new ActorContext(actorId: $viewer, companyScope: [$default]);
    ok('a target outside the actor\'s companies is refused', refusal($conn, $oneCo, $changeId, $tenantZ) === 'You do not have access to that company.');
    ok('an unknown company is refused the same way', refusal($conn, $ctx, $changeId, 999999999) === 'You do not have access to that company.');
    ok('none of them moved anything', $inDefault());

    echo "\nThe move\n";
    $res = ChangesService::moveToCompany($conn, $ctx, $changeId, $tenantZ);
    ok('the change moves (a deleted ticket\'s link does not stop it)', $res['moved'] === 1 && companyOf($conn, 'changes', $changeId) === $tenantZ, json_encode($res));
    ok('the linked task and its subtask move with it', $res['tasks_moved'] === 2 && $res['task_ids'] === $moving
        && companyOf($conn, 'tasks', $linked) === $tenantZ && companyOf($conn, 'tasks', $child) === $tenantZ, json_encode($res));
    ok('an unlinked task stays where it was', companyOf($conn, 'tasks', $other) === $default);
    $h = $conn->prepare("SELECT old_value, new_value FROM change_audit WHERE change_id = ? AND field_name = 'Company' ORDER BY id DESC LIMIT 1");
    $h->execute([$changeId]);
    $row = $h->fetch(PDO::FETCH_ASSOC);
    ok('the change\'s history says where it moved', $row && $row['new_value'] === 'ZZ Change move', json_encode($row));
    $h = $conn->prepare("SELECT COUNT(*) FROM task_audit WHERE field_name = 'Company' AND new_value = 'ZZ Change move' AND task_id IN ($linked, $child)");
    $h->execute();
    ok('and each task\'s history', (int)$h->fetchColumn() === 2);

    $again = ChangesService::moveToCompany($conn, $ctx, $changeId, $tenantZ);
    ok('moving to the company it is in does nothing', $again['moved'] === 0 && $again['tasks_moved'] === 0);

    $back = ChangesService::moveToCompany($conn, $ctx, $changeId, $default);
    ok('and it moves back, with its tasks', $back['moved'] === 1 && $back['tasks_moved'] === 2 && $inDefault(), json_encode($back));

    $base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
    if ($base === '') {
        echo "\nREST API: skipped (set FREEITSM_TEST_URL to this install's address)\n";
    } else {
        echo "\nREST API\n";
        $raw = 'zzk_' . bin2hex(random_bytes(20));
        $conn->prepare("INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime) VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP())")
             ->execute(['ZZ change move', substr($raw, 0, 12), hash('sha256', $raw), $viewer, json_encode(['changes' => ['read', 'update']])]);
        $made['api_keys'][] = (int)$conn->lastInsertId();
        $call = function (string $method, string $path, array $body) use ($base, $raw): array {
            $ch = curl_init("$base/api/v1$path");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
                CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ["Authorization: Bearer $raw", 'Content-Type: application/json']]);
            $out = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            return [$code, json_decode($out, true) ?: ['raw' => substr($out, 0, 300)]];
        };
        // With a real field, so the 422 cannot come from an empty update.
        [$c, $r] = $call('PATCH', "/changes/$changeId", ['company_id' => $tenantZ, 'title' => 'ZZ change move']);
        ok('PATCH with another company_id is refused (422), not ignored', $c === 422 && str_contains(json_encode($r), 'move-to-company') && $inDefault(), "$c " . json_encode($r));
        [$c, $r] = $call('PATCH', "/changes/$changeId", ['company_id' => $default, 'title' => 'ZZ change move']);
        ok('  control: PATCH naming the company it is in is accepted', $c === 200, "$c " . json_encode($r));
        [$c, $r] = $call('POST', "/changes/$changeId/move-to-company", ['company_id' => 999999999]);
        ok('POST move-to-company: an unknown company is 422', $c === 422, "$c " . json_encode($r));
        [$c, $r] = $call('POST', "/changes/$changeId/move-to-company", []);
        ok('POST move-to-company: no company_id is 422', $c === 422, "$c " . json_encode($r));
        [$c, $r] = $call('POST', "/changes/$changeId/move-to-company", ['company_id' => $tenantZ]);
        ok('POST move-to-company moves the change and its tasks', $c === 200 && ($r['data']['moved'] ?? null) === 1 && ($r['data']['tasks_moved'] ?? null) === 2
            && ($r['data']['company']['id'] ?? null) === $tenantZ && companyOf($conn, 'tasks', $child) === $tenantZ, "$c " . json_encode($r));
        [$c, $r] = $call('POST', "/changes/$changeId/move-to-company", ['company_id' => $default]);
        ok('  and back to Default', $c === 200 && $inDefault(), "$c " . json_encode($r));
    }
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    foreach ([$changeId, $change2] as $cid) {
        if (!$cid) continue;
        // Children first: change_tickets / change_audit may have no cascade on an old schema.
        foreach (['change_tickets', 'change_audit'] as $t) $conn->prepare("DELETE FROM `$t` WHERE change_id = ?")->execute([$cid]);
        $conn->prepare("DELETE FROM changes WHERE id = ?")->execute([$cid]);
    }
    foreach ($made['analysts'] as $aid) $conn->prepare("DELETE FROM analyst_modules WHERE analyst_id = ?")->execute([$aid]);
    if ($made['tasks']) {
        $in = implode(',', array_map('intval', $made['tasks']));
        $conn->exec("DELETE FROM task_audit WHERE task_id IN ($in)");
        $conn->exec("UPDATE tasks SET parent_task_id = NULL WHERE id IN ($in)");
    }
    foreach ($made as $table => $ids) {
        foreach ($ids as $id) $conn->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);
    }
    foreach ([$tenantZ, $tenantY] as $tid) if ($tid) $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tid]);
}

echo "\n" . str_repeat('-', 70) . "\n  $pass passed, $fail failed\n\n";
exit($fail ? 1 : 0);
