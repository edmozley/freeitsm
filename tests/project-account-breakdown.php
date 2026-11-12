<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A project's costs and income by account (GH #282, slice 5): projectAccountBreakdown().
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> [FREEITSM_TEST_URL=http://localhost/<that install>] php tests/project-account-breakdown.php
 *
 * 🔑 The costs ALWAYS equal the Budget tab's planned / actual / forecast tiles - in hours
 * mode, priced, when the labour plan lines are larger than labour and when they are
 * smaller - because the breakdown uses the same functions and the same "nothing counted
 * twice" rule. Then: splits, defaults, "not assigned", income on its own, net.
 *
 * Writes a ZZPAB project (lines, tasks, time, income), ZZPAB accounts, a project rate and
 * settings; removes or restores all of them in finally.
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }

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
if (!projectIncomeReady($conn) || !projectTaskAccountsReady($conn)) { echo "(skipped: run Database Verification)\n"; exit(1); }

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_admin = 1 AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION = ['analyst_id' => $admin, 'analyst_name' => 'ZZPAB test', 'is_admin' => 1];
$ctx = ActorContext::fromSession($conn);
$all = new ActorContext($admin, null, 'ui');
$T1 = (int)getDefaultTenantId($conn);
$projectId = null; $taskIds = []; $keyId = 0;
$keys = ['project_labour_mode', 'project_forecast_labour', 'project_default_labour_account', 'project_default_other_account', 'project_account_resplit'];
$old = [];
foreach ($keys as $k) { $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $s->execute([$k]); $old[$k] = $s->fetchColumn(); }
$set = function (string $k, ?string $v) use ($conn) {
    if ($v === null) $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
    else $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
    projectSettings($conn, true);
    projectLabourRatesReset();
};
$p = function () use ($conn, &$projectId) { return $conn->query("SELECT * FROM projects WHERE id = " . (int)$projectId)->fetch(PDO::FETCH_ASSOC); };
/** Breakdown costs equal the tiles? */
$matches = function (string $what) use ($conn, $p, $admin) {
    $proj = $p();
    $d = projectBudgetDetail($conn, $proj, $admin);
    $b = projectAccountBreakdown($conn, $proj);
    $same = abs($b['costs']['planned'] - $d['planned']) < 0.005 && abs($b['costs']['actual'] - $d['actual']) < 0.005 && abs($b['costs']['forecast'] - $d['forecast']) < 0.005;
    check("$what: the costs equal the Budget tab's tiles", $same, 'tiles ' . json_encode([$d['planned'], $d['actual'], $d['forecast']]) . ' breakdown ' . json_encode($b['costs']));
    return $b;
};
/** code => [planned, actual, forecast] across every section and "-" for not assigned. */
$flat = function (array $b): array {
    $o = [];
    foreach ($b['sections'] as $s) foreach ($s['rows'] as $r) $o[$r['account']['code'] ?? '-'] = [$r['planned'], $r['actual'], $r['forecast']];
    if ($b['unassigned']) $o['-'] = [$b['unassigned']['planned'], $b['unassigned']['actual'], $b['unassigned']['forecast']];
    ksort($o);
    return $o;
};

try {
    $mk = fn(string $code, string $kind) => AccountsService::create($conn, $all, $T1, ['code' => $code, 'name' => "ZZPAB $code", 'kind' => $kind]);
    $lic = $mk('ZZPAB-2.4', 'expense'); $svc = $mk('ZZPAB-3.2', 'expense'); $kit = $mk('ZZPAB-2.1', 'fixed_asset');
    $pay = $mk('ZZPAB-1.3', 'payroll'); $gen = $mk('ZZPAB-9', 'expense'); $inc = $mk('ZZPAB-4.1', 'income');
    $set('project_labour_mode', 'hours'); $set('project_forecast_labour', '1');
    $set('project_default_labour_account', null); $set('project_default_other_account', null); $set('project_account_resplit', 'all');
    $projectId = ProjectsService::createProject($conn, $ctx, ['name' => 'ZZPAB breakdown test', 'methodology' => 'staged'], null);
    $line = fn(array $in) => ProjectToolsService::saveBudgetLine($conn, $ctx, $projectId, $in);

    echo "Budget lines only (hours mode)\n";
    $line(['title' => 'ZZPAB integration', 'planned' => '10000', 'actual' => '4000', 'account_split' => 'percent', 'accounts' => [['account_id' => $svc, 'percent' => 70], ['account_id' => $lic, 'percent' => 30]]]);
    $line(['title' => 'ZZPAB firewall', 'planned' => '9000', 'accounts' => [['account_id' => $kit]]]);
    $line(['title' => 'ZZPAB sundries', 'planned' => '500']);   // no account, no default: not assigned
    $b = $matches('lines');
    check('split 70/30, one account, and the line with no account "not assigned"', $flat($b) === ['-' => [500.0, 0.0, 500.0], 'ZZPAB-2.1' => [9000.0, 0.0, 9000.0], 'ZZPAB-2.4' => [3000.0, 1200.0, 3000.0], 'ZZPAB-3.2' => [7000.0, 2800.0, 7000.0]], json_encode($flat($b)));
    check('grouped by kind with subtotals', $b['sections']['expense']['planned'] === 10000.0 && $b['sections']['fixed_asset']['planned'] === 9000.0 && $b['sections']['payroll']['rows'] === []);
    $set('project_default_other_account', 'ZZPAB-9');
    check('with an account for other spending, the line with none counts there', isset($flat(projectAccountBreakdown($conn, $p()))['ZZPAB-9']) && !isset($flat(projectAccountBreakdown($conn, $p()))['-']));

    echo "\nLabour\n";
    $status = (int)$conn->query("SELECT id FROM task_statuses WHERE is_closed = 0 ORDER BY display_order, id LIMIT 1")->fetchColumn();
    $ins = $conn->prepare("INSERT INTO tasks (title, status_id, created_by_id, created_datetime, project_id, tenant_id, estimate_hours) VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?)");
    $ins->execute(['ZZPAB build', $status, $admin, $projectId, $T1, 10]); $build = $taskIds[] = (int)$conn->lastInsertId();
    $ins->execute(['ZZPAB admin', $status, $admin, $projectId, $T1, null]); $adm = $taskIds[] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO task_time_entries (task_id, analyst_id, time_spent_minutes, entry_datetime, is_active) VALUES (?, ?, 240, '2026-02-02 10:00:00', 1), (?, ?, 60, '2026-02-03 10:00:00', 1)")
         ->execute([$build, $admin, $adm, $admin]);
    ProjectToolsService::setTaskAccounts($conn, $ctx, $projectId, $build, [['account_id' => $kit]]);
    $set('project_default_labour_account', 'ZZPAB-1.3');
    $b = $matches('hours mode with time');
    check('hours mode: hours on the accounts, no invented money', $b['sections']['fixed_asset']['minutes'] === 240 && $b['sections']['payroll']['minutes'] === 60 && $b['sections']['payroll']['actual'] === 0.0, json_encode([$b['sections']['fixed_asset']['minutes'], $b['sections']['payroll']]));

    $set('project_labour_mode', 'rate');
    $conn->prepare("INSERT INTO project_labour_rates (scope, ref_id, hourly_rate, effective_from) VALUES ('project', ?, 50, '2000-01-01')")->execute([$projectId]);
    projectLabourRatesReset();
    $b = $matches('priced labour (logged 5h + 6h still to come, at 50)');
    // build: 4h logged (200) + 6h to come (300) on kit; admin 1h (50) on payroll.
    check('labour money lands on the task\'s account and the account for time', $flat($b)['ZZPAB-2.1'][1] === 200.0 && $flat($b)['ZZPAB-2.1'][2] === 9500.0 && $flat($b)['ZZPAB-1.3'] === [0.0, 50.0, 50.0], json_encode($flat($b)));

    // A labour plan line BIGGER than labour: the forecast takes the plan instead (nothing counted twice).
    $plan = $line(['title' => 'ZZPAB labour plan', 'category' => 'labour', 'planned' => '2000', 'accounts' => [['account_id' => $pay]]]);
    $b = $matches('a labour plan line larger than labour');
    check('...the forecast follows the plan line, not labour', $flat($b)['ZZPAB-1.3'][2] === 2000.0 && $flat($b)['ZZPAB-2.1'][2] === 9000.0, json_encode($flat($b)));
    $line(['id' => $plan, 'title' => 'ZZPAB labour plan', 'category' => 'labour', 'planned' => '100', 'accounts' => [['account_id' => $pay]]]);
    $b = $matches('a labour plan line smaller than labour');
    check('...and labour wins when it is larger', $flat($b)['ZZPAB-1.3'][2] === 50.0 && $flat($b)['ZZPAB-2.1'][2] === 9500.0, json_encode($flat($b)));

    echo "\nA contract in another currency\n";
    $conn->exec("INSERT INTO contracts (title, contract_value, currency, tenant_id) VALUES ('ZZPAB dollar contract', 999, 'USD', $T1)");
    $cid = (int)$conn->lastInsertId();
    $conn->exec("INSERT INTO project_contracts (project_id, contract_id) VALUES ($projectId, $cid)");
    $conn->exec("UPDATE project_budget_lines SET contract_id = $cid WHERE project_id = $projectId AND title = 'ZZPAB firewall'");
    $b = $matches('a line whose contract is in dollars');
    check('...its dollars are never added', $flat($b)['ZZPAB-2.1'][1] === 200.0, json_encode($flat($b)['ZZPAB-2.1']));

    echo "\nIncome and net\n";
    ProjectToolsService::saveIncomeLine($conn, $ctx, $projectId, ['title' => 'ZZPAB recharge', 'planned' => '15000', 'received' => '5000', 'account_id' => $inc]);
    ProjectToolsService::saveIncomeLine($conn, $ctx, $projectId, ['title' => 'ZZPAB grant', 'planned' => '1000']);
    $b = $matches('with income');
    check('income is its own section, the costs unchanged', $b['income']['planned'] === 16000.0 && $b['income']['actual'] === 5000.0 && count($b['sections']['income']['rows']) === 2, json_encode($b['income']));
    check('net = income less costs', $b['net']['planned'] === round(16000 - $b['costs']['planned'], 2) && $b['net']['actual'] === round(5000 - $b['costs']['actual'], 2));

    if ($base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/')) {
        echo "\nThe REST API\n";
        $key = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime) VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP())')
             ->execute(['ZZPAB key', substr($key, 0, 12), hash('sha256', $key), $admin, json_encode(['projects' => ['read']])]);
        $keyId = (int)$conn->lastInsertId();
        $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode("/projects/$projectId/budget"));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
        $j = json_decode((string)curl_exec($ch), true) ?: [];
        check('GET /budget returns by_account with the same costs', abs(($j['data']['by_account']['costs']['planned'] ?? -1) - $b['costs']['planned']) < 0.005 && isset($j['data']['by_account']['sections']['fixed_asset']), json_encode($j['data']['by_account']['costs'] ?? $j));
        // The portfolio answer: one breakdown per currency, and this project's ZZPAB rows inside its currency.
        $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode('/projects/by-account'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
        $j = json_decode((string)curl_exec($ch), true) ?: [];
        $mine = array_values(array_filter($j['data'] ?? [], fn($x) => ($x['currency'] ?? '') === $b['currency']))[0] ?? null;
        $codes = $mine ? array_map(fn($r) => $r['account']['code'] ?? '', $mine['sections']['fixed_asset']['rows'] ?? []) : [];
        check('GET /projects/by-account gives one breakdown per currency, with this project\'s accounts in it',
            $mine && count(array_unique(array_column($j['data'], 'currency'))) === count($j['data']) && (bool)preg_grep('/^ZZPAB-/', $codes) && $mine['costs']['planned'] >= $b['costs']['planned'] - 0.005,
            json_encode(array_map(fn($x) => [$x['currency'] ?? '?', $x['costs'] ?? null], $j['data'] ?? [$j])));
    }
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($taskIds) { $in = implode(',', $taskIds); $conn->exec("DELETE FROM task_time_entries WHERE task_id IN ($in)"); $conn->exec("DELETE FROM task_account_versions WHERE task_id IN ($in)"); $conn->exec("DELETE FROM tasks WHERE id IN ($in)"); }
    if ($projectId) {
        $conn->exec("DELETE FROM project_labour_rates WHERE scope = 'project' AND ref_id = " . (int)$projectId);
        try { ProjectsService::deleteProject($conn, $ctx, $projectId); } catch (Throwable $e) { echo "  cleanup: " . $e->getMessage() . "\n"; }
    }
    $conn->exec("DELETE FROM contracts WHERE title = 'ZZPAB dollar contract'");
    $conn->exec("DELETE FROM accounts WHERE code LIKE 'ZZPAB-%'");
    if ($keyId) $conn->exec("DELETE FROM api_keys WHERE id = $keyId");
    foreach ($old as $k => $v) $set($k, $v === false ? null : $v);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
