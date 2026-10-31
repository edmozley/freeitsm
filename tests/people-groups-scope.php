<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Directory groups in People - who may see which group, and which members.
 *
 * Groups imported from Entra ID appear in People as a filter, a Groups tab, a
 * page per group, on a person's page and on a company's page. A group belongs
 * to the provider that imported it, and a provider can belong to a client
 * company. A group's NAME can say a lot about that client ("Redundancies
 * 2026"), so an analyst limited to other companies must not see it at all -
 * not in the filter, not by id, not on a company page - and a global group
 * must only ever list the members the analyst could already open.
 *
 * Also pinned: inactive groups (no longer imported) are offered nowhere except
 * the Groups tab, and leavers are listed on a group page but never counted.
 *
 * ⚠️ Needs a restriction to test, so it needs two companies besides Default and
 * a non-admin analyst. Everything runs inside a transaction that is ALWAYS
 * rolled back.
 *
 * Run:  php tests/people-groups-scope.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/people.php';
I18n::initFromSession();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nDirectory groups in People\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
if (!isMultiTenant($conn)) { echo "  SKIP  needs two or more companies\n"; exit(0); }
$default = getDefaultTenantId($conn);
$others  = array_values(array_diff(array_map('intval', $conn->query("SELECT id FROM tenants WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)), [$default]));
if (count($others) < 2) { echo "  SKIP  needs two companies besides Default\n"; exit(0); }
[$mine, $theirs] = $others;
try { $conn->query("SELECT 1 FROM directory_groups LIMIT 0"); }
catch (PDOException $e) { echo "  SKIP  run Database Verification first: no directory_groups table\n"; exit(0); }

// Chosen by SQL alone: getAccessibleTenantIds() caches per analyst for the life
// of the process, so nothing may ask about $r before the restriction is set.
$r = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND (is_admin = 0 OR is_admin IS NULL) ORDER BY id LIMIT 1")->fetchColumn();
if (!$r) { echo "  SKIP  needs a non-admin analyst\n"; exit(0); }

$conn->beginTransaction();
try {
    $conn->prepare("UPDATE analysts SET can_access_all_tenants = 0, can_access_all_modules = 1 WHERE id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$r, $mine]);
    ok("analyst {$r} is limited to company {$mine}", getAccessibleTenantIds($conn, $r) === [$mine]);

    $provider = function (?int $tenant, string $name) use ($conn): int {
        $conn->prepare("INSERT INTO auth_providers (protocol, display_name, issuer_url, client_id, tenant_id, enabled)
                        VALUES ('oidc', ?, 'https://login.microsoftonline.com/zz-test/v2.0', 'zz-test', ?, 1)")->execute([$name, $tenant]);
        return (int)$conn->lastInsertId();
    };
    $group = function (int $provider, string $name, int $active = 1) use ($conn): int {
        $conn->prepare("INSERT INTO directory_groups (provider_id, external_id, display_name, is_active, member_count)
                        VALUES (?, ?, ?, ?, 0)")->execute([$provider, 'zz-' . bin2hex(random_bytes(6)), $name, $active]);
        return (int)$conn->lastInsertId();
    };
    $person = function (?int $tenant, string $name, int $active = 1) use ($conn): int {
        $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active) VALUES (?, ?, ?, ?)")
             ->execute([strtolower(str_replace(' ', '.', $name)) . '.pgtest@example.invalid', $name, $tenant, $active]);
        return (int)$conn->lastInsertId();
    };
    $member = fn(int $g, int $u) => $conn->prepare("INSERT INTO directory_group_members (group_id, user_id) VALUES (?, ?)")->execute([$g, $u]);

    $pGlobal = $provider(null, 'ZZ Global Entra');
    $pTheirs = $provider($theirs, 'ZZ Client Entra');
    $gGlobal   = $group($pGlobal, 'ZZ PG Finance');
    $gTheirs   = $group($pTheirs, 'ZZ PG Client Redundancies');
    $gInactive = $group($pGlobal, 'ZZ PG Retired group', 0);
    $uMine     = $person($mine, 'PG Mine');
    $uMineLeft = $person($mine, 'PG Mine Leaver', 0);
    $uTheirs   = $person($theirs, 'PG Theirs');
    foreach ([$uMine, $uMineLeft, $uTheirs] as $u) $member($gGlobal, $u);
    $member($gTheirs, $uTheirs);
    $member($gInactive, $uMine);

    echo "\nThe Group filter and the Groups tab:\n";
    $offered = array_column(peopleGroups($conn, $r), null, 'id');
    ok('a client company\'s group is not offered to an analyst outside it', !isset($offered[$gTheirs]), json_encode(array_keys($offered)));
    ok('POSITIVE CONTROL: a global group is offered', isset($offered[$gGlobal]));
    ok('an inactive group is not offered in the filter', !isset($offered[$gInactive]));
    ok('a global group counts only current people the analyst can see (not the leaver, not the other company)',
       ($offered[$gGlobal]['people'] ?? null) === 1, json_encode($offered[$gGlobal] ?? null));
    $tab = array_column(peopleGroupRows($conn, $r), null, 'id');
    ok('the Groups tab leaves out the client company\'s group', !isset($tab[$gTheirs]));
    ok('the Groups tab includes the inactive group, flagged', isset($tab[$gInactive]) && $tab[$gInactive]['is_active'] === false);

    echo "\nA group's page:\n";
    ok('a client company\'s group, opened by id, is not found', peopleGroupDetail($conn, $r, $gTheirs) === null);
    $d = peopleGroupDetail($conn, $r, $gGlobal);
    $ids = array_column($d['members'] ?? [], 'id');
    ok('POSITIVE CONTROL: the global group opens', $d !== null);
    ok('...listing their own company\'s people, current first, leaver included', $ids === [$uMine, $uMineLeft], json_encode($ids));
    ok('...and never the member in the other company', !in_array($uTheirs, $ids, true));

    echo "\nThe People list, filtered by group:\n";
    $all = array_column(peopleListRows($conn, $r, ['group' => $gGlobal, 'status' => 'all']), 'id');
    sort($all);
    $want = [$uMine, $uMineLeft]; sort($want);
    ok('filtering by a global group lists its members in their companies', $all === $want, json_encode($all));
    ok('...and Show current leaves the leaver out', array_column(peopleListRows($conn, $r, ['group' => $gGlobal]), 'id') === [$uMine]);
    ok('filtering by a group they cannot see lists nobody, not everybody', peopleListRows($conn, $r, ['group' => $gTheirs, 'status' => 'all']) === []);

    echo "\nA person's page and a company's page:\n";
    $pg = array_column(peoplePersonGroups($conn, $r, $uMine), 'id');
    ok('a person\'s page lists their active groups only', $pg === [$gGlobal], json_encode($pg));
    $cg = array_column(peopleCompanyGroups($conn, $r, $mine) ?? [], null, 'id');
    ok('a company page lists the groups its people are in, with how many', ($cg[$gGlobal]['here'] ?? null) === 1, json_encode($cg));
    ok('...and not a group none of its current people are in', !isset($cg[$gTheirs]) && !isset($cg[$gInactive]));
    $cd = companyDetail($conn, $r, $mine);
    ok('companyDetail() carries the groups section', isset($cd['sections']['groups']));
} finally {
    $conn->rollBack();
}

echo "\n" . str_repeat('=', 70) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
