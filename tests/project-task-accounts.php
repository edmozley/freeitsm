<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Accounts on tasks (GH #282, slice 3): where the TIME logged on a project's tasks counts.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> [FREEITSM_TEST_URL=http://localhost/<that install>] php tests/project-task-accounts.php
 *
 * A task's split is kept as dated versions; Projects -> Settings -> Budget decides whether
 * every hour follows the latest one (the default) or the one in force the day it was
 * logged. A subtask follows its parent unless it has its own. Also pins the 5.1.0 fix: time
 * on SUBTASKS now reaches a project's labour (it never did - subtasks carry no project_id).
 *
 * Writes a ZZPTA project with tasks, a subtask, time entries, ZZPTA accounts, a project rate
 * and two settings; removes or restores all of them in finally.
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }
function throws(callable $fn): string { try { $fn(); return 'ok'; } catch (ServiceError $e) { return $e->errorCode . ': ' . $e->getMessage(); } }

$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/tenancy.php';
require_once 'includes/services/projects.php';
require_once 'includes/services/project_tools.php';
require_once 'includes/services/accounts.php';
require_once 'includes/projects/budget.php';
require_once 'includes/projects/accounts.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
if (!projectTaskAccountsReady($conn)) { echo "(skipped: run Database Verification - the #282 slice 3 tables are missing)\n"; exit(1); }

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_admin = 1 AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION = ['analyst_id' => $admin, 'analyst_name' => 'ZZPTA test', 'is_admin' => 1];
$ctx = ActorContext::fromSession($conn);
$all = new ActorContext($admin, null, 'ui');
$T1 = (int)getDefaultTenantId($conn);
$projectId = null; $keyId = 0; $taskIds = [];
$keys = ['project_account_resplit', 'project_labour_mode', 'project_default_labour_account'];
$old = [];
foreach ($keys as $k) { $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $s->execute([$k]); $old[$k] = $s->fetchColumn(); }
$set = function (string $k, ?string $v) use ($conn) {
    if ($v === null) $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
    else $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
    projectSettings($conn, true);
};
/** Time by account as code => minutes (or cost), "-" = not assigned. */
$by = function (string $field = 'minutes') use ($conn, &$projectId): array {
    $p = $conn->query("SELECT * FROM projects WHERE id = " . (int)$projectId)->fetch(PDO::FETCH_ASSOC);
    $out = [];
    foreach (projectLabourByAccount($conn, $p) as $r) $out[$r['account']['code'] ?? '-'] = $r[$field];
    ksort($out);
    return $out;
};

try {
    $mk = fn(string $code, string $kind, bool $active = true) => AccountsService::create($conn, $all, $T1, ['code' => $code, 'name' => "ZZPTA $code", 'kind' => $kind, 'is_active' => $active]);
    $pay = $mk('ZZPTA-PAY', 'payroll');
    $kit = $mk('ZZPTA-KIT', 'fixed_asset');
    $svc = $mk('ZZPTA-SVC', 'expense');
    $projectId = ProjectsService::createProject($conn, $ctx, ['name' => 'ZZPTA task accounts test', 'methodology' => 'staged'], null);
    $status = (int)$conn->query("SELECT id FROM task_statuses WHERE is_closed = 0 ORDER BY display_order, id LIMIT 1")->fetchColumn();
    $ins = $conn->prepare("INSERT INTO tasks (title, status_id, created_by_id, created_datetime, project_id, parent_task_id, tenant_id) VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?)");
    $ins->execute(['ZZPTA firewall build', $status, $admin, $projectId, null, $T1]);   $fw = $taskIds[] = (int)$conn->lastInsertId();
    $ins->execute(['ZZPTA cabling', $status, $admin, null, $fw, $T1]);                  $sub = $taskIds[] = (int)$conn->lastInsertId();   // a subtask: no project_id of its own
    $ins->execute(['ZZPTA admin', $status, $admin, $projectId, null, $T1]);            $plain = $taskIds[] = (int)$conn->lastInsertId();
    $time = $conn->prepare("INSERT INTO task_time_entries (task_id, analyst_id, time_spent_minutes, entry_datetime, is_active) VALUES (?, ?, ?, ?, 1)");
    $time->execute([$fw, $admin, 120, '2026-01-10 10:00:00']);
    $time->execute([$fw, $admin, 60, '2026-03-10 10:00:00']);
    $time->execute([$sub, $admin, 60, '2026-03-11 10:00:00']);
    $time->execute([$plain, $admin, 30, '2026-03-12 10:00:00']);
    $set('project_labour_mode', 'hours');

    echo "Time on subtasks reaches the budget (5.1.0 fix)\n";
    $p = $conn->query("SELECT * FROM projects WHERE id = $projectId")->fetch(PDO::FETCH_ASSOC);
    check('labour counts the task, its subtask and the other task: 270 minutes', projectLabour($conn, [$projectId => $p])[$projectId]['minutes'] === 270, (string)projectLabour($conn, [$projectId => $p])[$projectId]['minutes']);

    echo "\nNo accounts yet\n";
    check('all of it is "not assigned"', $by() === ['-' => 270], json_encode($by()));
    $set('project_default_labour_account', 'ZZPTA-PAY');
    check('with an account for time, all of it counts there', $by() === ['ZZPTA-PAY' => 270], json_encode($by()));

    echo "\nA task's split\n";
    check('a split must add up to 100', str_contains(throws(fn() => ProjectToolsService::setTaskAccounts($conn, $ctx, $projectId, $fw, [['account_id' => $kit, 'percent' => 50], ['account_id' => $svc, 'percent' => 40]])), '100%'));
    check('a task is split by percentage only', str_contains(throws(fn() => ProjectToolsService::setTaskAccounts($conn, $ctx, $projectId, $fw, [['account_id' => $kit, 'amount' => 5]])), 'percentage only'));
    check('a task of another project is refused', str_starts_with(throws(fn() => ProjectToolsService::setTaskAccounts($conn, $ctx, $projectId, 999999999, [])), 'not_found'));
    check('set: 75% kit, 25% services', ProjectToolsService::setTaskAccounts($conn, $ctx, $projectId, $fw, [['account_id' => $kit, 'percent' => 75], ['account_id' => $svc, 'percent' => 25]]) === true);
    check('saving the same split again writes no new version', ProjectToolsService::setTaskAccounts($conn, $ctx, $projectId, $fw, [['account_id' => $kit, 'percent' => 75], ['account_id' => $svc, 'percent' => 25]]) === false);
    // The task (180) and its subtask (60, following the parent) split 75/25; the other task (30) in the account for time.
    check('all hours: the task and its subtask follow the split; the rest goes to the account for time', $by() === ['ZZPTA-KIT' => 180, 'ZZPTA-PAY' => 30, 'ZZPTA-SVC' => 60], json_encode($by()));
    check('the change is in the project\'s history, with the task\'s name', (int)$conn->query("SELECT COUNT(*) FROM project_audit WHERE project_id = $projectId AND field_name = 'task_account' AND old_value = 'ZZPTA firewall build' AND new_value = '75% ZZPTA-KIT, 25% ZZPTA-SVC'")->fetchColumn() === 1);

    echo "\nA subtask with its own\n";
    ProjectToolsService::setTaskAccounts($conn, $ctx, $projectId, $sub, [['account_id' => $svc]]);
    check('its own account wins over the parent\'s', $by() === ['ZZPTA-KIT' => 135, 'ZZPTA-PAY' => 30, 'ZZPTA-SVC' => 105], json_encode($by()));
    ProjectToolsService::setTaskAccounts($conn, $ctx, $projectId, $sub, []);
    check('cleared, it follows the parent again', $by() === ['ZZPTA-KIT' => 180, 'ZZPTA-PAY' => 30, 'ZZPTA-SVC' => 60], json_encode($by()));

    echo "\nOnly hours from then on\n";
    // History: the firewall task had NO split before 2026-02-01, then all kit, then (today) 75/25.
    $conn->exec("DELETE FROM task_account_versions WHERE task_id = $fw");
    $conn->exec("INSERT INTO task_account_versions (task_id, effective_from) VALUES ($fw, '2026-02-01')");
    $v1 = (int)$conn->lastInsertId();
    $conn->exec("INSERT INTO task_account_shares (version_id, account_id, percent) VALUES ($v1, $kit, 100)");
    $conn->exec("INSERT INTO task_account_versions (task_id, effective_from) VALUES ($fw, '2026-03-11')");
    $v2 = (int)$conn->lastInsertId();
    $conn->exec("INSERT INTO task_account_shares (version_id, account_id, percent) VALUES ($v2, $kit, 50), ($v2, $svc, 50)");
    $conn->exec("DELETE FROM task_account_versions WHERE task_id = $sub");
    $set('project_account_resplit', 'all');
    check('"all hours": everything follows the latest version (50/50)', $by() === ['ZZPTA-KIT' => 120, 'ZZPTA-PAY' => 30, 'ZZPTA-SVC' => 120], json_encode($by()));
    $set('project_account_resplit', 'from_then');
    // 10 Jan (120, before any version) -> account for time; 10 Mar (60) -> all kit; 11 Mar subtask (60) -> 50/50; admin (30) -> account for time.
    check('"from then on": each hour by the version in force the day it was logged', $by() === ['ZZPTA-KIT' => 90, 'ZZPTA-PAY' => 150, 'ZZPTA-SVC' => 30], json_encode($by()));

    echo "\nPriced\n";
    $set('project_labour_mode', 'rate');
    $conn->prepare("INSERT INTO project_labour_rates (scope, ref_id, hourly_rate, effective_from) VALUES ('project', ?, 100, '2000-01-01')")->execute([$projectId]);
    projectLabourRatesReset();
    check('cost follows the same shares (100 an hour)', $by('cost') === ['ZZPTA-KIT' => 150.0, 'ZZPTA-PAY' => 250.0, 'ZZPTA-SVC' => 50.0], json_encode($by('cost')));
    $tot = projectLabour($conn, [$projectId => $conn->query("SELECT * FROM projects WHERE id = $projectId")->fetch(PDO::FETCH_ASSOC)])[$projectId]['cost'];
    check('...and adds up to the labour total', abs(array_sum($by('cost')) - (float)$tot) < 0.01, "$tot");
    $set('project_account_resplit', 'all');

    echo "\nAn account a task uses cannot be deleted\n";
    check('refused, saying why', str_contains(throws(fn() => AccountsService::delete($conn, $all, $svc)), 'task'));

    if ($base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/')) {
        echo "\nThe REST API\n";
        $key = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime) VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP())')
             ->execute(['ZZPTA key', substr($key, 0, 12), hash('sha256', $key), $admin, json_encode(['projects' => ['read', 'update']])]);
        $keyId = (int)$conn->lastInsertId();
        $rest = function (string $method, string $path, ?array $body = null) use ($base, $key): array {
            $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode($path));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body)]);
            $raw = (string)curl_exec($ch);
            return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 300)]];
        };
        [$st, $j] = $rest('PATCH', "/projects/$projectId/tasks/$sub/accounts", ['accounts' => [['account_id' => $kit, 'percent' => 60], ['account_id' => $svc, 'percent' => 40]]]);
        check('PATCH a subtask\'s accounts', $st === 200 && count($j['data']['accounts'] ?? []) === 2 && count($j['data']['parent_accounts'] ?? []) === 2, json_encode($j));
        [$st, $j] = $rest('GET', "/projects/$projectId/tasks/$fw/accounts");
        check('GET a task\'s accounts, with its history', $st === 200 && count($j['data']['history'] ?? []) >= 2, json_encode($j));
        [$st, $j] = $rest('GET', "/projects/$projectId/budget");
        check('GET /budget returns labour_by_account', $st === 200 && is_array($j['data']['labour_by_account'] ?? null) && count($j['data']['labour_by_account']) === 3, json_encode($j['data']['labour_by_account'] ?? $j));
        [$st] = $rest('PATCH', "/projects/$projectId/tasks/$fw/accounts", []);
        check('PATCH without accounts is a 422', $st === 422, (string)$st);
    }
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($taskIds) {
        $in = implode(',', $taskIds);
        $conn->exec("DELETE FROM task_time_entries WHERE task_id IN ($in)");
        $conn->exec("DELETE FROM task_account_versions WHERE task_id IN ($in)");
        $conn->exec("DELETE FROM tasks WHERE id IN ($in) AND parent_task_id IS NOT NULL");
        $conn->exec("DELETE FROM tasks WHERE id IN ($in)");
    }
    if ($projectId) {
        $conn->exec("DELETE FROM project_labour_rates WHERE scope = 'project' AND ref_id = " . (int)$projectId);
        try { ProjectsService::deleteProject($conn, $ctx, $projectId); } catch (Throwable $e) { echo "  cleanup: " . $e->getMessage() . "\n"; }
    }
    $conn->exec("DELETE FROM accounts WHERE code LIKE 'ZZPTA-%'");
    if ($keyId) $conn->exec("DELETE FROM api_keys WHERE id = $keyId");
    foreach ($old as $k => $v) $set($k, $v === false ? null : $v);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
