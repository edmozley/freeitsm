<?php
/**
 * Chart of accounts (GH #282, slice 1): AccountsService.
 *
 *   php tests/accounts.php
 *
 * Writes to the database it finds, but inside ONE transaction that is rolled back at
 * the end, so it leaves nothing behind - including the second company it makes to
 * test the company rules. Needs Database Verification to have run (accounts).
 *
 * Shaped like tests/cost-centres.php (the two services share their rules), plus the
 * account's KIND: required on create, words mapped onto keys, inherited from a parent
 * in a sync, and never emptied.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'config.php';
require 'includes/functions.php';
require 'includes/services/accounts.php';

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok    $label\n"; }
    else { $fail++; echo "  FAIL  $label\n        got  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n        want " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; }
}
/** The ServiceError code a call throws, or 'ok'. */
function throws(callable $fn): string {
    try { $fn(); return 'ok'; } catch (ServiceError $e) { return $e->errorCode; }
}

echo "Kinds\n";
foreach (['expense' => 'expense', 'Fixed asset' => 'fixed_asset', 'CAPEX' => 'fixed_asset', 'Salaries' => 'payroll', 'Revenue' => 'income', 'Anlagevermögen' => 'fixed_asset', 'Erlöse' => 'income', 'fixed_asset' => 'fixed_asset'] as $in => $want) {
    check("'$in' is $want", AccountsService::cleanKind($in), $want);
}
check('an unknown kind is refused', throws(fn() => AccountsService::cleanKind('banana')), 'invalid_field');
check('a blank kind is refused', throws(fn() => AccountsService::cleanKind('  ')), 'missing_field');

echo "\nAccountsService\n";
$c = connectToDatabase();
if (!AccountsService::ready($c)) { echo "  skip  accounts table missing - run Database Verification\n"; goto done; }
$c->beginTransaction();

$T1 = getDefaultTenantId($c);
$c->exec("INSERT INTO tenants (name, is_active, is_default) VALUES ('Beta (accounts test)', 1, 0)");
$T2 = (int)$c->lastInsertId();
$all = new ActorContext(1, null, 'ui');
$onlyT2 = new ActorContext(1, [$T2], 'ui');
$c->prepare("DELETE FROM accounts WHERE tenant_id IN (?, ?)")->execute([$T1, $T2]);   // rolled back with the rest

$parent = AccountsService::create($c, $all, $T1, ['code' => ' 2.0.3.0 ', 'name' => 'IT running costs', 'kind' => 'Expense']);
check('code is trimmed and kept as text', AccountsService::load($c, $all, $parent)['code'], '2.0.3.0');
check('kind stored as its key', AccountsService::load($c, $all, $parent)['kind'], 'expense');
$lic = AccountsService::create($c, $all, $T1, ['code' => '2.0.3.0.4', 'name' => 'Software Licences', 'kind' => 'expense', 'parent_code' => '2.0.3.0']);
check('parent by code', (int)AccountsService::load($c, $all, $lic)['parent_id'], $parent);
check('a kind is required on create', throws(fn() => AccountsService::create($c, $all, $T1, ['code' => 'X1', 'name' => 'x'])), 'missing_field');
check('a made-up kind is refused', throws(fn() => AccountsService::create($c, $all, $T1, ['code' => 'X2', 'name' => 'x', 'kind' => 'misc'])), 'invalid_field');
check('the same code again is refused', throws(fn() => AccountsService::create($c, $all, $T1, ['code' => '2.0.3.0', 'name' => 'x', 'kind' => 'expense'])), 'conflict');
check('another company may use the same code', throws(fn() => AccountsService::create($c, $all, $T2, ['code' => '2.0.3.0.4', 'name' => 'Beta licences', 'kind' => 'expense'])), 'ok');
check('a parent in another company is refused', throws(fn() => AccountsService::create($c, $all, $T2, ['code' => 'B1', 'name' => 'x', 'kind' => 'expense', 'parent_id' => $parent])), 'invalid_field');
check('a loop is refused', throws(fn() => AccountsService::update($c, $all, $parent, ['parent_id' => $lic])), 'invalid_field');
check('PATCH-style update: only the kind changes', (function () use ($c, $all, $lic) {
    AccountsService::update($c, $all, $lic, ['kind' => 'fixed_asset']);
    $r = AccountsService::load($c, $all, $lic);
    return [$r['kind'], $r['name'], (int)$r['parent_id'] > 0];
})(), ['fixed_asset', 'Software Licences', true]);
check('the kind cannot be emptied', throws(fn() => AccountsService::update($c, $all, $lic, ['kind' => ''])), 'missing_field');
check('deleting a parent is refused', throws(fn() => AccountsService::delete($c, $all, $parent)), 'conflict');
check('a company outside the scope: by id is not found', throws(fn() => AccountsService::load($c, $onlyT2, $parent)), 'not_found');
check('a company outside the scope: create is forbidden', throws(fn() => AccountsService::create($c, $onlyT2, $T1, ['code' => 'Z', 'name' => 'z', 'kind' => 'income'])), 'forbidden');
check('companiesFor honours the scope', array_column(AccountsService::companiesFor($c, $onlyT2), 'id'), [$T2]);

echo "\nSync\n";
$count = fn() => (int)$c->query("SELECT COUNT(*) FROM accounts WHERE tenant_id = $T1")->fetchColumn();
$before = $count();
$r = AccountsService::sync($c, $all, $T1, [
    ['line' => 2, 'code' => '2.1', 'name' => 'Fixed assets', 'kind' => 'Capex'],
    ['line' => 3, 'code' => '2.1.0.3.2', 'name' => 'Computer Equipment', 'parent_code' => '2.1'],   // no kind: takes the parent's (same file)
    ['line' => 4, 'code' => '2.0.3.0.5', 'name' => 'Cloud', 'parent_code' => '2.0.3.0'],          // no kind: takes the existing parent's
    ['line' => 5, 'code' => '2.0.3.0.4', 'kind' => 'Expense'],                                     // existing: kind back to expense
], ['dry_run' => true]);
check('dry run: counts', $r['counts'], ['create' => 3, 'update' => 1, 'unchanged' => 0, 'deactivate' => 0]);
check('dry run: kinds inherited from a parent in the file and an existing parent', array_column($r['rows'], 'kind'), ['fixed_asset', 'fixed_asset', 'expense', 'expense']);
check('dry run: the kind change is seen', $r['rows'][3]['changes'], ['kind']);
check('dry run writes nothing', [$r['applied'], $count()], [false, $before]);

$r = AccountsService::sync($c, $all, $T1, [
    ['line' => 2, 'code' => 'NEW1', 'name' => 'Orphan'],                                 // new, no kind, no parent
    ['line' => 3, 'code' => 'NEW2', 'name' => 'x', 'kind' => 'banana'],
    ['line' => 4, 'code' => 'OK1', 'name' => 'fine', 'kind' => 'income'],
]);
check('bad rows: a new account with no kind and no parent, and an unknown kind, are both reported', array_column($r['errors'], 'line'), [3, 2]);
check('bad rows: ALL OR NOTHING - the good row was not written either', [$r['applied'], $count()], [false, $before]);

$r = AccountsService::sync($c, $all, $T1, [
    ['code' => '2.1', 'name' => 'Fixed assets', 'kind' => 'fixed asset'],
    ['code' => '2.1.0.3.2', 'name' => 'Computer Equipment', 'parent_code' => '2.1'],
    ['code' => '2.0.3.0', 'name' => 'IT running costs'],
    ['code' => '2.0.3.0.4', 'name' => 'Software Licences', 'kind' => ''],               // blank kind = leave it
], ['deactivate_missing' => false]);
check('apply: written', [$r['applied'], $r['counts']['create']], [true, 2]);
$byCode = [];
foreach (AccountsService::listForCompany($c, $all, $T1) as $row) $byCode[$row['code']] = $row;
check('the inherited kind was stored', $byCode['2.1.0.3.2']['kind'], 'fixed_asset');
check('a blank kind in a sync leaves the kind as it was', $byCode['2.0.3.0.4']['kind'], 'fixed_asset');
check('the parent was set from a code earlier in the same list', $byCode['2.1.0.3.2']['parent_code'], '2.1');

$c->rollBack();
check('rolled back: nothing left behind', (int)$c->query("SELECT COUNT(*) FROM tenants WHERE name = 'Beta (accounts test)'")->fetchColumn(), 0);

done:
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
