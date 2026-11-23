<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Cost centres - an owner and a deputy (GH #160 Part B), through CostCentresService.
 *
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/cost-centre-owners.php
 *
 *   - the owner is a person OR a people group, never both; the deputy is a person,
 *     never the owner;
 *   - a named person must be active and in the cost centre's company;
 *   - set by id or by email / group name (the import and the API), an unknown one
 *     refused, never guessed;
 *   - sync: owner_email / owner_group / deputy_email set, change and clear them; a
 *     file WITHOUT those columns changes nothing; a bad email refuses the whole sync;
 *   - responsibleFor() lists what a person answers for.
 *
 * Runs in one transaction that is always rolled back.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/cost_centres.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}
function refused(callable $fn): ?string { try { $fn(); return null; } catch (ServiceError $e) { return $e->getMessage(); } }

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $conn->query('SELECT DATABASE()')->fetchColumn()) { echo "SKIPPED: set FREEITSM_TEST_DATABASE.\n"; exit(0); }
if (!CostCentresService::peopleReady($conn)) { echo "SKIPPED: run Database Verification (cost_centres has no owner columns).\n"; exit(0); }

$admin = (int)$conn->query('SELECT MIN(id) FROM analysts WHERE is_admin = 1 AND is_active = 1')->fetchColumn();
$ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ');
$tag = 'zz' . bin2hex(random_bytes(3));
$conn->beginTransaction();
try {
    $home = (int)getDefaultTenantId($conn);
    $conn->prepare('INSERT INTO tenants (name, slug) VALUES (?, ?)')->execute(["$tag co", $tag]);
    $other = (int)$conn->lastInsertId();
    $person = function (string $n, ?int $t, int $active = 1) use ($conn, $tag) {
        $conn->prepare('INSERT INTO users (email, display_name, tenant_id, is_active) VALUES (?, ?, ?, ?)')->execute(["$n.$tag@example.test", "$n $tag", $t, $active]);
        return (int)$conn->lastInsertId();
    };
    $priya = $person('priya', $home); $sam = $person('sam', $home); $nocomp = $person('nocomp', null);
    $outsider = $person('outsider', $other); $gone = $person('gone', $home, 0);
    $conn->prepare('INSERT INTO knowledge_user_groups (name, is_active) VALUES (?, 1)')->execute(["Controlling $tag"]);
    $group = (int)$conn->lastInsertId();

    echo "\nSetting them\n";
    $id = CostCentresService::create($conn, $ctx, $home, ['code' => "$tag-1", 'name' => 'Logistics', 'owner_email' => "PRIYA.$tag@example.test", 'deputy_user_id' => $sam]);
    $r = CostCentresService::load($conn, $ctx, $id);
    check('create takes an owner by email (ignoring capitals) and a deputy by id', (int)$r['owner_user_id'] === $priya && (int)$r['deputy_user_id'] === $sam && $r['owner_name'] === "priya $tag" && $r['deputy_email'] === "sam.$tag@example.test", json_encode([$r['owner_user_id'], $r['deputy_user_id']]));
    CostCentresService::update($conn, $ctx, $id, ['owner_group' => "controlling $tag"]);
    $r = CostCentresService::load($conn, $ctx, $id);
    check('a people group as owner, by name - and the owner person is cleared', (int)$r['owner_group_id'] === $group && $r['owner_user_id'] === null && $r['owner_group_name'] === "Controlling $tag");
    CostCentresService::update($conn, $ctx, $id, ['owner_user_id' => $priya]);
    $r = CostCentresService::load($conn, $ctx, $id);
    check('a person again - the group is cleared', (int)$r['owner_user_id'] === $priya && $r['owner_group_id'] === null);
    CostCentresService::update($conn, $ctx, $id, ['name' => 'Logistics UK']);
    check('a change that does not mention them leaves them alone', (int)CostCentresService::load($conn, $ctx, $id)['owner_user_id'] === $priya);
    check('a person with no company counts as the Default company\'s', refused(fn() => CostCentresService::update($conn, $ctx, $id, ['deputy_user_id' => $nocomp])) === null);

    echo "\nRefusals\n";
    check('a person and a group together', (bool)refused(fn() => CostCentresService::update($conn, $ctx, $id, ['owner_user_id' => $sam, 'owner_group_id' => $group])));
    check('the deputy being the owner', (bool)refused(fn() => CostCentresService::update($conn, $ctx, $id, ['deputy_user_id' => $priya])));
    check('someone from another company', (bool)refused(fn() => CostCentresService::update($conn, $ctx, $id, ['owner_user_id' => $outsider])));
    check('a deactivated person', (bool)refused(fn() => CostCentresService::update($conn, $ctx, $id, ['deputy_user_id' => $gone])));
    check('an email nobody has', (bool)refused(fn() => CostCentresService::update($conn, $ctx, $id, ['owner_email' => "nobody.$tag@example.test"])));
    check('a group nobody has', (bool)refused(fn() => CostCentresService::update($conn, $ctx, $id, ['owner_group' => "No such $tag"])));
    $r = CostCentresService::load($conn, $ctx, $id);
    check('...and after all those refusals nothing changed', (int)$r['owner_user_id'] === $priya && (int)$r['deputy_user_id'] === $nocomp);
    CostCentresService::update($conn, $ctx, $id, ['owner_email' => '', 'deputy_email' => '']);
    $r = CostCentresService::load($conn, $ctx, $id);
    check('present but empty clears them', $r['owner_user_id'] === null && $r['deputy_user_id'] === null);

    echo "\nThe import and the API (sync)\n";
    $rep = CostCentresService::sync($conn, $ctx, $home, [
        ['line' => 2, 'code' => "$tag-1", 'owner_email' => "priya.$tag@example.test", 'deputy_email' => "sam.$tag@example.test"],
        ['line' => 3, 'code' => "$tag-2", 'name' => 'Finance', 'owner_group' => "Controlling $tag"],
    ]);
    $one = CostCentresService::load($conn, $ctx, $id);
    $twoId = (int)$conn->query("SELECT id FROM cost_centres WHERE code = '$tag-2'")->fetchColumn();
    $two = CostCentresService::load($conn, $ctx, $twoId);
    check('sync sets owners and deputies, on existing and new cost centres', $rep['applied'] && (int)$one['owner_user_id'] === $priya && (int)$one['deputy_user_id'] === $sam && (int)$two['owner_group_id'] === $group, json_encode($rep['errors']));
    $plan = array_column($rep['rows'], 'changes', 'code');
    check('the preview says owner and deputy changed', in_array('owner', $plan["$tag-1"] ?? [], true) && in_array('deputy', $plan["$tag-1"] ?? [], true), json_encode($plan));
    CostCentresService::sync($conn, $ctx, $home, [['line' => 2, 'code' => "$tag-1", 'name' => 'Logistics renamed']]);
    check('a file WITHOUT owner columns changes nothing', (int)CostCentresService::load($conn, $ctx, $id)['owner_user_id'] === $priya);
    $rep = CostCentresService::sync($conn, $ctx, $home, [
        ['line' => 2, 'code' => "$tag-1", 'deputy_email' => ''],
        ['line' => 3, 'code' => "$tag-2", 'owner_email' => "nobody.$tag@example.test"],
    ]);
    check('a bad email refuses the WHOLE sync, with its line', !$rep['applied'] && ($rep['errors'][0]['line'] ?? 0) === 3 && (int)CostCentresService::load($conn, $ctx, $id)['deputy_user_id'] === $sam, json_encode($rep['errors']));

    echo "\nWho answers for what\n";
    $rf = CostCentresService::responsibleFor($conn, $sam);
    check('responsibleFor() lists the deputy\'s cost centre with its role', count($rf) === 1 && $rf[0]['role'] === 'deputy' && (int)$rf[0]['id'] === $id);
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
