<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Accounts on a project's budget (GH #282, slice 2): one account or a split on a budget
 * line, the project's default accounts, the install's defaults by code, and an account in
 * use refusing to be deleted. Through the real services (and the REST API when
 * FREEITSM_TEST_URL is set).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> [FREEITSM_TEST_URL=http://localhost/<that install>] php tests/project-budget-accounts.php
 *
 * Writes a ZZPBA project, ZZPBA accounts (in the Default company and a second company it
 * makes) and two settings, and removes or restores all of them in finally.
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }
/** The ServiceError code a call throws, or 'ok'. */
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
if (!projectAccountsReady($conn)) { echo "(skipped: run Database Verification - the #282 slice 2 tables are missing)\n"; exit(1); }

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_admin = 1 AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION = ['analyst_id' => $admin, 'analyst_name' => 'ZZPBA test', 'is_admin' => 1];
$ctx = ActorContext::fromSession($conn);
$all = new ActorContext($admin, null, 'ui');
$T1 = (int)getDefaultTenantId($conn);
$projectId = null; $T2 = null; $keyId = 0;
$oldSettings = [];
foreach (['project_default_labour_account', 'project_default_other_account'] as $k) {
    $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $s->execute([$k]); $oldSettings[$k] = $s->fetchColumn();
}
$setSetting = fn(string $k, ?string $v) => $v === null
    ? $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k])
    : $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
$lineRows = function (int $lineId) use ($conn): array {
    $st = $conn->prepare("SELECT account_id, ROUND(percent, 4) AS p FROM project_budget_line_accounts WHERE line_id = ? ORDER BY position");
    $st->execute([$lineId]);
    return array_map(fn($r) => [(int)$r['account_id'], (float)$r['p']], $st->fetchAll(PDO::FETCH_ASSOC));
};
$split = function (int $lineId) use ($conn) { $s = $conn->prepare("SELECT account_split FROM project_budget_lines WHERE id = ?"); $s->execute([$lineId]); return $s->fetchColumn(); };

try {
    // A chart: expense, fixed asset, payroll, and one made inactive; plus another company's.
    $mk = fn(int $t, string $code, string $kind, bool $active = true) => AccountsService::create($conn, $all, $t, ['code' => $code, 'name' => "ZZPBA $code", 'kind' => $kind, 'is_active' => $active]);
    $lic  = $mk($T1, 'ZZPBA-2.4', 'expense');
    $prof = $mk($T1, 'ZZPBA-3.2', 'expense');
    $kit  = $mk($T1, 'ZZPBA-2.1', 'fixed_asset');
    $pay  = $mk($T1, 'ZZPBA-1.3', 'payroll');
    $old  = $mk($T1, 'ZZPBA-OLD', 'expense', false);
    $conn->exec("INSERT INTO tenants (name, is_active, is_default) VALUES ('ZZPBA other company', 1, 0)");
    $T2 = (int)$conn->lastInsertId();
    $foreign = $mk($T2, 'ZZPBA-2.4', 'expense');
    $projectId = ProjectsService::createProject($conn, $ctx, ['name' => 'ZZPBA budget accounts test'], null);
    $save = fn(array $in) => ProjectToolsService::saveBudgetLine($conn, $ctx, $projectId, $in);

    echo "One account, or a split\n";
    $l1 = $save(['title' => 'ZZPBA licences', 'planned' => '1200', 'accounts' => [['account_id' => $lic]]]);
    check('one account = one row at 100%', $lineRows($l1) === [[$lic, 100.0]] && $split($l1) === null, json_encode($lineRows($l1)));
    $l2 = $save(['title' => 'ZZPBA integration', 'planned' => '10000', 'account_split' => 'percent', 'accounts' => [['account_id' => $prof, 'percent' => '70'], ['account_id' => $lic, 'percent' => '30']]]);
    check('a split by percentage is stored as given', $lineRows($l2) === [[$prof, 70.0], [$lic, 30.0]] && $split($l2) === 'percent', json_encode($lineRows($l2)));
    $l3 = $save(['title' => 'ZZPBA firewall', 'planned' => '9000', 'account_split' => 'amount', 'accounts' => [['account_id' => $kit, 'amount' => '6000'], ['account_id' => $prof, 'amount' => '3,000']]]);
    check('a split by amount is turned into percentages of the line', $lineRows($l3) === [[$kit, 66.6667], [$prof, 33.3333]] && $split($l3) === 'amount', json_encode($lineRows($l3)));
    $l4 = $save(['title' => 'ZZPBA thirds', 'planned' => '100', 'account_split' => 'percent', 'accounts' => [['account_id' => $lic, 'percent' => '33.3333'], ['account_id' => $prof, 'percent' => '33.3333'], ['account_id' => $kit, 'percent' => '33.3334']]]);
    check('thirds that add up to 100 are accepted', count($lineRows($l4)) === 3);

    echo "\nRefused, and nothing written\n";
    $before = $lineRows($l2);
    check('amounts that do not add up to the line', str_starts_with(throws(fn() => $save(['id' => $l2, 'title' => 'ZZPBA integration', 'planned' => '10000', 'account_split' => 'amount', 'accounts' => [['account_id' => $prof, 'amount' => '7000'], ['account_id' => $lic, 'amount' => '2000']]])), 'invalid_field: The split adds up to 9,000.00'));
    check('...and the line kept its split', $lineRows($l2) === $before);
    check('percentages that add up to 99', str_contains(throws(fn() => $save(['id' => $l2, 'title' => 'x', 'planned' => '1', 'accounts' => [['account_id' => $prof, 'percent' => '60'], ['account_id' => $lic, 'percent' => '39']]])), 'must add up to 100%'));
    check('the same account twice', str_contains(throws(fn() => $save(['title' => 'x', 'planned' => '1', 'accounts' => [['account_id' => $lic, 'percent' => '50'], ['account_id' => $lic, 'percent' => '50']]])), 'twice'));
    check('an inactive account, newly', str_contains(throws(fn() => $save(['title' => 'x', 'accounts' => [['account_id' => $old]]])), 'active account'));
    check('another company\'s account', str_contains(throws(fn() => $save(['title' => 'x', 'accounts' => [['account_id' => $foreign]]])), 'active account'));
    check('a split by amount on a line with no amount', str_contains(throws(fn() => $save(['title' => 'x', 'account_split' => 'amount', 'accounts' => [['account_id' => $lic, 'amount' => '5'], ['account_id' => $prof, 'amount' => '5']]])), 'planned or actual amount'));
    check('nothing half-saved: no line titled x', (int)$conn->query("SELECT COUNT(*) FROM project_budget_lines WHERE project_id = $projectId AND title = 'x'")->fetchColumn() === 0);

    echo "\nKeeping and clearing\n";
    $save(['id' => $l1, 'title' => 'ZZPBA licences renamed', 'planned' => '1300']);
    check('a save that leaves accounts out keeps them', $lineRows($l1) === [[$lic, 100.0]]);
    $conn->exec("UPDATE accounts SET is_active = 0 WHERE id = $lic");
    check('a line keeps an account that has since been made inactive', throws(fn() => $save(['id' => $l1, 'title' => 'ZZPBA licences', 'planned' => '1300', 'accounts' => [['account_id' => $lic]]])) === 'ok');
    $conn->exec("UPDATE accounts SET is_active = 1 WHERE id = $lic");
    $save(['id' => $l4, 'title' => 'ZZPBA thirds', 'planned' => '100', 'accounts' => []]);
    check('[] removes a line\'s accounts', $lineRows($l4) === [] && $split($l4) === null);

    echo "\nThe project's default accounts\n";
    $p = ProjectsService::loadForActor($conn, $ctx, $projectId);
    check('none set: no defaults', projectDefaultAccounts($conn, $p) === ['labour' => null, 'other' => null]);
    $setSetting('project_default_labour_account', 'ZZPBA-1.3');
    $setSetting('project_default_other_account', 'zzpba-2.4');   // capitals ignored, as everywhere
    projectSettings($conn, true);   // settings are cached per request - read them again
    $d = projectDefaultAccounts($conn, $p);
    check('the install\'s defaults are found by code in the project\'s company', ($d['labour']['id'] ?? 0) === $pay && ($d['other']['id'] ?? 0) === $lic && $d['labour']['source'] === 'install', json_encode($d));
    ProjectToolsService::setDefaultAccounts($conn, $ctx, $projectId, ['other_account_id' => $prof]);
    $d = projectDefaultAccounts($conn, ProjectsService::loadForActor($conn, $ctx, $projectId));
    check('the project\'s own wins, the other still from the install', ($d['other']['id'] ?? 0) === $prof && $d['other']['source'] === 'project' && $d['labour']['source'] === 'install', json_encode($d));
    check('a default must be an active account of the project\'s company', str_contains(throws(fn() => ProjectToolsService::setDefaultAccounts($conn, $ctx, $projectId, ['labour_account_id' => $foreign])), 'active account'));
    check('the change is in the project\'s history', (int)$conn->query("SELECT COUNT(*) FROM project_audit WHERE project_id = $projectId AND field_name = 'other_account'")->fetchColumn() === 1);

    echo "\nThe Budget tab's data\n";
    $b = projectBudgetDetail($conn, ProjectsService::loadForActor($conn, $ctx, $projectId), $admin);
    $byId = array_column($b['lines'], null, 'id');
    check('each line carries its accounts, with the split', count($byId[$l2]['accounts']) === 2 && $byId[$l2]['accounts'][0]['code'] === 'ZZPBA-3.2' && $byId[$l2]['account_split'] === 'percent');
    check('the active accounts to choose from (not the inactive one)', in_array('ZZPBA-2.4', array_column($b['accounts'], 'code'), true) && !in_array('ZZPBA-OLD', array_column($b['accounts'], 'code'), true));
    check('and the defaults', ($b['default_accounts']['other']['code'] ?? '') === 'ZZPBA-3.2');

    echo "\nAn account in use cannot be deleted\n";
    check('deleting an account a line uses is refused, saying why', str_contains(throws(fn() => AccountsService::delete($conn, $all, $kit)), 'project budget line'));
    check('deleting a project default is refused', str_contains(throws(fn() => AccountsService::delete($conn, $all, $prof)), 'project'));
    check('an unused one still deletes', throws(fn() => AccountsService::delete($conn, $all, $old)) === 'ok');

    if ($base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/')) {
        echo "\nThe REST API\n";
        $key = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime) VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP())')
             ->execute(['ZZPBA key', substr($key, 0, 12), hash('sha256', $key), $admin, json_encode(['projects' => ['read', 'create', 'update']])]);
        $keyId = (int)$conn->lastInsertId();
        $rest = function (string $method, string $path, ?array $body = null) use ($base, $key): array {
            $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode($path));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body)]);
            $raw = (string)curl_exec($ch);
            return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 300)]];
        };
        [$st, $j] = $rest('POST', "/projects/$projectId/budget-lines", ['title' => 'ZZPBA via API', 'planned' => 400, 'account_split' => 'amount', 'accounts' => [['account_id' => $lic, 'amount' => 100], ['account_id' => $kit, 'amount' => 300]]]);
        check('POST a line with a split by amount', $st === 201 && array_column($j['data']['accounts'] ?? [], 'percent') === [25, 75] && ($j['data']['account_split'] ?? '') === 'amount', json_encode($j));
        $apiLine = (int)($j['data']['id'] ?? 0);
        [$st, $j] = $rest('PATCH', "/projects/$projectId/budget-lines/$apiLine", ['notes' => 'just the notes']);
        check('PATCH without accounts keeps them', $st === 200 && count($j['data']['accounts'] ?? []) === 2, json_encode($j));
        [$st, $j] = $rest('PATCH', "/projects/$projectId/budget", ['labour_account_id' => $pay]);
        check('PATCH /projects/{id}/budget sets a default, and answers with the budget', $st === 200 && ($j['data']['default_accounts']['labour']['source'] ?? '') === 'project', json_encode($j));
    }
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($projectId) {
        try { ProjectsService::deleteProject($conn, $ctx, $projectId); } catch (Throwable $e) { echo "  cleanup: project delete failed: " . $e->getMessage() . "\n"; }
        $left = (int)$conn->query("SELECT COUNT(*) FROM project_budget_line_accounts la JOIN accounts a ON a.id = la.account_id WHERE a.code LIKE 'ZZPBA-%'")->fetchColumn();
        check('deleting the project removed its lines\' accounts too', $left === 0, "$left left");
    }
    $conn->exec("UPDATE projects SET labour_account_id = NULL, other_account_id = NULL WHERE labour_account_id IN (SELECT id FROM accounts WHERE code LIKE 'ZZPBA-%') OR other_account_id IN (SELECT id FROM accounts WHERE code LIKE 'ZZPBA-%')");
    $conn->exec("DELETE FROM accounts WHERE code LIKE 'ZZPBA-%'");
    if ($T2) $conn->exec("DELETE FROM tenants WHERE id = $T2");
    if ($keyId) $conn->exec("DELETE FROM api_keys WHERE id = $keyId");
    foreach ($oldSettings as $k => $v) $setSetting($k, $v === false ? null : $v);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
