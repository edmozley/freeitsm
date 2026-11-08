<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * People across companies - who may read whom.
 *
 * A person (a row in `users`) belongs to one company. An analyst limited to
 * other companies must not be able to read them by id, from any screen.
 *
 * ⚠️ Needs a restriction to test, and on most installs nobody is restricted.
 * Everything runs inside a transaction that is ALWAYS rolled back.
 *
 * Run:  php tests/people-scope.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/services/assets.php';
require_once __DIR__ . '/../includes/people.php';
I18n::initFromSession();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nPeople across companies\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
if (!isMultiTenant($conn)) { echo "  SKIP  needs two or more companies\n"; exit(0); }
$default = getDefaultTenantId($conn);
$others  = array_values(array_diff(array_map('intval', $conn->query("SELECT id FROM tenants WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)), [$default]));
if (count($others) < 2) { echo "  SKIP  needs two companies besides Default\n"; exit(0); }
[$mine, $theirs] = $others;

// Not an admin, and chosen by SQL alone: getAccessibleTenantIds() and the
// module check both cache per analyst for the life of the process, so nothing
// may ask about $r before the transaction below gives them every module.
$r = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND (is_admin = 0 OR is_admin IS NULL) ORDER BY id LIMIT 1")->fetchColumn();
if (!$r) { echo "  SKIP  needs a non-admin analyst\n"; exit(0); }
// A second analyst, never asked about yet, to restrict by MODULE (module access is cached per process too).
$m = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND (is_admin = 0 OR is_admin IS NULL) AND id > $r ORDER BY id LIMIT 1")->fetchColumn();

$conn->beginTransaction();
try {
    $conn->prepare("UPDATE analysts SET can_access_all_tenants = 0, can_access_all_modules = 1 WHERE id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$r]);
    $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$r, $mine]);
    ok("analyst {$r} is limited to company {$mine}", getAccessibleTenantIds($conn, $r) === [$mine]);

    $mk = function (?int $tenant, string $name, ?int $manager = null) use ($conn): int {
        $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active, manager_id, phone) VALUES (?, ?, ?, 1, ?, '01632 960000')")
             ->execute([strtolower(str_replace(' ', '.', $name)) . '.pstest@example.invalid', $name, $tenant, $manager]);
        return (int)$conn->lastInsertId();
    };
    $boss    = $mk($mine, 'PS Boss');
    $uMine   = $mk($mine, 'PS Mine', $boss);
    $uTheirs = $mk($theirs, 'PS Theirs', $boss);     // one manager, reports in two companies
    $ctx = new ActorContext(actorId: $r, companyScope: [$mine], source: 'ui');

    echo "\nAssets > Users (one person and what they hold):\n";
    ok('someone in another company reads as no such person', AssetsService::assetsForUser($conn, $ctx, $uTheirs) === null);
    $mineRow = AssetsService::assetsForUser($conn, $ctx, $uMine);
    ok('POSITIVE CONTROL: someone in their own company is returned', $mineRow !== null && $mineRow['user']['id'] === $uMine);
    $bossRow = AssetsService::assetsForUser($conn, $ctx, $boss);
    $reports = array_column($bossRow['user']['reports'] ?? [], 'id');
    ok('a manager\'s reports in another company are not listed', !in_array($uTheirs, $reports, true), json_encode($reports));
    ok('POSITIVE CONTROL: reports in their own company are', in_array($uMine, $reports, true), json_encode($reports));

    echo "\nPeople pages (#153 step 2):\n";
    $rows = array_column(peopleListRows($conn, $r, ['q' => 'PS ', 'status' => 'all']), 'id');
    ok('the list leaves out people in other companies', !in_array($uTheirs, $rows, true), json_encode($rows));
    ok('POSITIVE CONTROL: and includes their own', in_array($uMine, $rows, true));
    ok('asking the list for another company returns nobody', peopleListRows($conn, $r, ['company' => $theirs, 'status' => 'all']) === []);
    ok('a person page for someone in another company is not found', personDetail($conn, $r, $uTheirs) === null);
    $pd = personDetail($conn, $r, $boss);
    ok('POSITIVE CONTROL: their own company\'s person opens', $pd !== null && $pd['person']['id'] === $boss);
    ok('...and lists only the reports they can see', $pd && array_column($pd['person']['reports'], 'id') === [$uMine], json_encode($pd['person']['reports'] ?? null));
    $pm = personDetail($conn, $r, $uMine);
    ok('...and shows a manager they can see', $pm && ($pm['person']['manager']['id'] ?? null) === $boss);
    ok('a company page for a company they cannot access is not found', companyDetail($conn, $r, $theirs) === null);
    $cd = companyDetail($conn, $r, $mine);
    ok('POSITIVE CONTROL: their own company opens, with its people', $cd !== null && in_array($uMine, array_column($cd['sections']['people'], 'id'), true));
    $companyIds = array_column(peopleCompanies($conn, $r), 'id');
    ok('the companies list is only theirs', $companyIds === [$mine], json_encode($companyIds));

    // Suppliers and contacts (#153 step 3, #162) are install-wide, but the
    // domains on their pages belong to companies and must follow that rule.
    require_once __DIR__ . '/../includes/domains/customer.php';
    $conn->prepare("INSERT INTO suppliers (legal_name, trading_name, is_active) VALUES ('PS Supplier Ltd', 'PS Supplier', 1)")->execute();
    $sup = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO contacts (supplier_id, first_name, surname, email, is_active) VALUES (?, 'PS', 'Contact', 'ps.contact.pstest@example.invalid', 1)")->execute([$sup]);
    $con = (int)$conn->lastInsertId();
    if (domainPartiesReady($conn) && peopleCanSeeSuppliers($conn, $r) && analystCanAccessModule($conn, $r, 'domains')) {
        echo "\nSuppliers and contacts (#153 step 3, #162):\n";
        $dom = function (int $tenant, string $name) use ($conn, $sup, $con): int {
            $conn->prepare("INSERT INTO domains (domain_name, tenant_id, customer_supplier_id, customer_contact_id, tech_contact_id) VALUES (?, ?, ?, ?, ?)")
                 ->execute([$name, $tenant, $sup, $con, $con]);
            return (int)$conn->lastInsertId();
        };
        $dMine = $dom($mine, 'ps-mine.pstest.invalid');
        $dTheirs = $dom($theirs, 'ps-theirs.pstest.invalid');
        $sd = supplierDetail($conn, $r, $sup);
        $sdIds = array_column($sd['sections']['domains']['rows'] ?? [], 'id');
        ok('a supplier\'s page leaves out domains in other companies', !in_array($dTheirs, $sdIds, true), json_encode($sdIds));
        ok('POSITIVE CONTROL: and shows the one in their own company', in_array($dMine, $sdIds, true));
        $roles = ($sd['sections']['domains']['rows'][0]['roles'] ?? []);
        ok('...saying it is the customer and the technical contact', $roles === ['customer', 'tech'], json_encode($roles));
        ok('...and lists its contact', in_array($con, array_column($sd['sections']['contacts'] ?? [], 'id'), true));
        $cd2 = supplierContactDetail($conn, $r, $con);
        $cdIds = array_column($cd2['sections']['domains']['rows'] ?? [], 'id');
        ok('a contact\'s page leaves out domains in other companies', !in_array($dTheirs, $cdIds, true), json_encode($cdIds));
        ok('POSITIVE CONTROL: and shows the one in their own company', in_array($dMine, $cdIds, true));
    } else {
        echo "  SKIP  the supplier checks need Database Verification (#162 columns) and an analyst with People, Contracts and Domains\n";
    }

    if ($m) {
        $conn->prepare("UPDATE analysts SET can_access_all_modules = 0 WHERE id = ?")->execute([$m]);
        $conn->prepare("DELETE FROM analyst_modules WHERE analyst_id = ?")->execute([$m]);
        $conn->prepare("DELETE FROM analyst_teams WHERE analyst_id = ?")->execute([$m]);
        $conn->prepare("INSERT INTO analyst_modules (analyst_id, module_key) VALUES (?, 'people'), (?, 'tickets')")->execute([$m, $m]);
        $pm2 = personDetail($conn, $m, $uMine);
        ok('an analyst with only People and Tickets gets just the Tickets section', $pm2 && array_keys($pm2['sections']) === ['tickets'], json_encode(array_keys($pm2['sections'] ?? [])));
        $cm2 = companyDetail($conn, $m, $mine);
        // 'groups' is People's own card (#237), there only when a directory group has been imported.
        $cm2Keys = array_values(array_diff(array_keys($cm2['sections'] ?? []), ['groups']));
        ok('...on a company page too (plus its people)', $cm2 && $cm2Keys === ['people', 'tickets'], json_encode(array_keys($cm2['sections'] ?? [])));
        // Suppliers are Contracts' records: People alone does not open them.
        ok('without Contracts, a supplier page is not found', supplierDetail($conn, $m, $sup) === null);
        ok('...nor a supplier contact\'s', supplierContactDetail($conn, $m, $con) === null);
    } else {
        echo "  SKIP  the module check needs a second non-admin analyst\n";
    }
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}

$left = (int)$conn->query("SELECT COUNT(*) FROM users WHERE email LIKE '%.pstest@example.invalid'")->fetchColumn();
$left += (int)$conn->query("SELECT COUNT(*) FROM contacts WHERE email LIKE '%.pstest@example.invalid'")->fetchColumn();
$left += (int)$conn->query("SELECT COUNT(*) FROM domains WHERE domain_name LIKE '%.pstest.invalid'")->fetchColumn();
ok('nothing survived the run (rolled back)', $left === 0, "{$left} test rows left");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
