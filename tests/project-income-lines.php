<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Income lines on a project (GH #282, slice 4): money a project brings in, beside the
 * budget and never added to it, with an INCOME account from the chart of accounts.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> [FREEITSM_TEST_URL=http://localhost/<that install>] php tests/project-income-lines.php
 *
 * Writes a ZZPIL project, ZZPIL accounts and its lines; removes them in finally.
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
if (!projectIncomeReady($conn)) { echo "(skipped: run Database Verification - project_income_lines is missing)\n"; exit(1); }

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_admin = 1 AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION = ['analyst_id' => $admin, 'analyst_name' => 'ZZPIL test', 'is_admin' => 1];
$ctx = ActorContext::fromSession($conn);
$all = new ActorContext($admin, null, 'ui');
$T1 = (int)getDefaultTenantId($conn);
$projectId = null; $keyId = 0;

try {
    $rech = AccountsService::create($conn, $all, $T1, ['code' => 'ZZPIL-4.1', 'name' => 'ZZPIL Recharges', 'kind' => 'income']);
    $grant = AccountsService::create($conn, $all, $T1, ['code' => 'ZZPIL-4.2', 'name' => 'ZZPIL Grants', 'kind' => 'income']);
    $exp = AccountsService::create($conn, $all, $T1, ['code' => 'ZZPIL-2.4', 'name' => 'ZZPIL Licences', 'kind' => 'expense']);
    $projectId = ProjectsService::createProject($conn, $ctx, ['name' => 'ZZPIL income test', 'methodology' => 'staged'], null);
    ProjectToolsService::saveBudgetLine($conn, $ctx, $projectId, ['title' => 'ZZPIL laptops', 'planned' => '8000', 'actual' => '7500']);
    $save = fn(array $in) => ProjectToolsService::saveIncomeLine($conn, $ctx, $projectId, $in);
    $detail = fn() => projectBudgetDetail($conn, ProjectsService::loadForActor($conn, $ctx, $projectId), $admin);

    echo "Adding income\n";
    $l1 = $save(['title' => 'ZZPIL recharge to Finance', 'planned' => '6000', 'received' => '3000', 'planned_date' => '2026-06-30', 'received_date' => '2026-03-01', 'account_id' => $rech]);
    $l2 = $save(['title' => 'ZZPIL innovation grant', 'planned' => '5,000', 'account_id' => $grant]);
    $b = $detail();
    check('the income totals', $b['income']['planned'] === 11000.0 && $b['income']['received'] === 3000.0, json_encode([$b['income']['planned'], $b['income']['received']]));
    check('income is NOT added to the budget', $b['planned'] === 8000.0 && $b['actual'] === 7500.0, json_encode([$b['planned'], $b['actual']]));
    check('a line carries its account and dates', ($b['income']['lines'][0]['account']['code'] ?? '') === 'ZZPIL-4.1' && $b['income']['lines'][0]['received_date'] === '2026-03-01');
    check('the income accounts offered are income ones only', array_column($b['income_accounts'], 'code') === ['ZZPIL-4.1', 'ZZPIL-4.2'] || !array_diff(['ZZPIL-4.1', 'ZZPIL-4.2'], array_column($b['income_accounts'], 'code')) && !in_array('ZZPIL-2.4', array_column($b['income_accounts'], 'code'), true));
    check('added and changed lines are in the history', (int)$conn->query("SELECT COUNT(*) FROM project_audit WHERE project_id = $projectId AND field_name = 'income_line_added'")->fetchColumn() === 2);

    echo "\nRefused\n";
    check('a name is required', str_starts_with(throws(fn() => $save(['planned' => 1])), 'missing_field'));
    check('an expense account on an income line', str_contains(throws(fn() => $save(['title' => 'x', 'account_id' => $exp])), 'income account'));
    check('received in the future', str_contains(throws(fn() => $save(['title' => 'x', 'received_date' => gmdate('Y-m-d', time() + 86400 * 3)])), 'future'));
    check('an amount with three decimals', str_starts_with(throws(fn() => $save(['title' => 'x', 'planned' => '1.234'])), 'invalid_field'));
    check('another project\'s line', str_starts_with(throws(fn() => ProjectToolsService::saveIncomeLine($conn, $ctx, $projectId, ['id' => 999999999, 'title' => 'x'])), 'not_found'));

    echo "\nKeeping, changing, deleting\n";
    $conn->exec("UPDATE accounts SET is_active = 0 WHERE id = $grant");
    check('a line keeps an income account since made inactive', throws(fn() => $save(['id' => $l2, 'title' => 'ZZPIL innovation grant', 'planned' => '5000', 'account_id' => $grant])) === 'ok');
    check('but a new line cannot pick it', str_contains(throws(fn() => $save(['title' => 'x', 'account_id' => $grant])), 'income account'));
    $conn->exec("UPDATE accounts SET is_active = 1 WHERE id = $grant");
    check('an account an income line uses cannot be deleted', str_contains(throws(fn() => AccountsService::delete($conn, $all, $rech)), 'income line'));
    ProjectToolsService::deleteIncomeLine($conn, $ctx, $projectId, $l2);
    check('deleted, and the totals follow', $detail()['income']['planned'] === 6000.0);

    if ($base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/')) {
        echo "\nThe REST API\n";
        $key = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime) VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP())')
             ->execute(['ZZPIL key', substr($key, 0, 12), hash('sha256', $key), $admin, json_encode(['projects' => ['read', 'update']])]);
        $keyId = (int)$conn->lastInsertId();
        $rest = function (string $method, string $path, ?array $body = null) use ($base, $key): array {
            $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode($path));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body)]);
            $raw = (string)curl_exec($ch);
            return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 300)]];
        };
        [$st, $j] = $rest('POST', "/projects/$projectId/income-lines", ['title' => 'ZZPIL via API', 'planned' => 1200, 'account_id' => $grant]);
        check('POST an income line', $st === 201 && ($j['data']['account']['code'] ?? '') === 'ZZPIL-4.2', json_encode($j));
        $apiLine = (int)($j['data']['id'] ?? 0);
        [$st, $j] = $rest('PATCH', "/projects/$projectId/income-lines/$apiLine", ['received' => 1200]);
        check('PATCH keeps what it leaves out', $st === 200 && $j['data']['received'] == 1200 && $j['data']['planned'] == 1200 && ($j['data']['account']['code'] ?? '') === 'ZZPIL-4.2', json_encode($j));
        [$st, $j] = $rest('GET', "/projects/$projectId/budget");
        check('GET /budget returns the income, apart from the budget', $st === 200 && ($j['data']['income']['planned'] ?? null) == 7200 && ($j['data']['planned'] ?? null) == 8000, json_encode($j['data']['income'] ?? $j));
        [$st] = $rest('DELETE', "/projects/$projectId/income-lines/$apiLine");
        check('DELETE an income line', $st === 200);
    }
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($projectId) {
        try { ProjectsService::deleteProject($conn, $ctx, $projectId); } catch (Throwable $e) { echo "  cleanup: " . $e->getMessage() . "\n"; }
        check('deleting the project removed its income lines', (int)$conn->query("SELECT COUNT(*) FROM project_income_lines WHERE project_id = " . (int)$projectId)->fetchColumn() === 0);
    }
    $conn->exec("DELETE FROM accounts WHERE code LIKE 'ZZPIL-%'");
    if ($keyId) $conn->exec("DELETE FROM api_keys WHERE id = $keyId");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
