<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Assets -> People follows "All companies" (GH #356).
 *
 *   php tests/assets-all-companies.php      (run from a TEST install, never a real one)
 *
 * In the "All companies" view, Assets -> People and the asset list showed only
 * the LAST company picked: every Assets read used activeTenantFilter(), which
 * always answers with one company. Tickets -> Users had been moved to
 * activeTenantReadFilter() in 2.6.0, so People and Tickets disagreed (7 people
 * against 4 in the report).
 *
 * Drives the REAL endpoints in-process (api/assets/get_people.php and
 * get_assets.php) plus AssetsService for the holders list and a person's panel.
 *
 * The ASSET LIST deliberately stays on one company: an asset's type, status and
 * location dropdowns still come from the selected company, so a widened list
 * would make it easy to give one company's asset another company's location
 * (wiki: Assets-and-All-Companies-Design). Checked here so it is not widened by
 * accident before that is fixed.
 * Writes, and removes in a finally block by the ids it got back:
 *   - one company "ZZ All companies test" (a single-company install becomes
 *     multi-company for the length of the run - that is why this must be a
 *     test install);
 *   - one person and one asset in the Default company, one of each in the ZZ
 *     company, and the two users_assets rows linking them.
 * Control: with the old activeTenantFilter() the People / holders / panel checks fail.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/services/assets.php';

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
 * Runs an Assets endpoint in this process with the session and query given and
 * returns its decoded JSON.
 *
 * @param string $file  the endpoint's file name under api/assets/
 * @param array $session  analyst_id, active_tenant_id, active_tenant_all
 * @param array $get  the query string
 */
function callAssets(string $file, array $session, array $get): array {
    $endpoint = realpath(__DIR__ . '/../api/assets/' . $file);
    $_SESSION = $session;
    $_GET = $get;
    $cwd = getcwd();
    // After this script has printed, the endpoint's session_start() and header()
    // can only warn on the command line. Swallow exactly those two.
    set_error_handler(function ($no, $msg) {
        return stripos($msg, 'session') !== false || stripos($msg, 'headers already sent') !== false;
    });
    ob_start();
    try {
        chdir(dirname($endpoint));
        include $endpoint;
    } finally {
        $raw = (string)ob_get_clean();
        chdir($cwd);
        restore_error_handler();
    }
    $d = json_decode(trim($raw), true);
    if (!is_array($d) || empty($d['success'])) {
        echo "  FAIL  $file did not answer: " . substr(trim($raw), 0, 300) . "\n";
        $GLOBALS['fail']++;
        return [];
    }
    return $d;
}

echo "\nAssets -> People follows \"All companies\" (GH #356)\n" . str_repeat('=', 70) . "\n";
$conn = connectToDatabase();
$db = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
echo "  database: $db\n";
if (!preg_match('/test/i', $db)) { echo "  STOP  this test adds a company; run it only on a test install\n"; exit(1); }

// The viewer: an active analyst who may reach every company.
$viewer = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND can_access_all_tenants = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$viewer) { echo "  SKIP  no active analyst with access to every company\n"; exit(0); }

$tenantZ = 0; $users = []; $assets = [];
try {
    // The company first: tenantCount() caches, and nothing may ask it before this.
    $conn->prepare("INSERT INTO tenants (name) VALUES (?)")->execute(['ZZ All companies test']);
    $tenantZ = (int)$conn->lastInsertId();
    $default = getDefaultTenantId($conn);
    ok('the install is multi-company for the run', isMultiTenant($conn));

    $mkUser = function (string $name, int $tenant) use ($conn, &$users): int {
        $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())")
             ->execute([strtolower(str_replace(' ', '-', $name)) . '@example.invalid', $name, $tenant]);
        return $users[] = (int)$conn->lastInsertId();
    };
    $mkAsset = function (string $host, int $tenant, int $holder) use ($conn, &$assets): int {
        $conn->prepare("INSERT INTO assets (hostname, tenant_id) VALUES (?, ?)")->execute([$host, $tenant]);
        $id = $assets[] = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO users_assets (user_id, asset_id, assigned_datetime) VALUES (?, ?, UTC_TIMESTAMP())")->execute([$holder, $id]);
        return $id;
    };
    $userD = $mkUser('ZZ Allco Default', $default);
    $userZ = $mkUser('ZZ Allco Other', $tenantZ);
    $assetD = $mkAsset('ZZ-ALLCO-DEFAULT', $default, $userD);
    $assetZ = $mkAsset('ZZ-ALLCO-OTHER', $tenantZ, $userZ);

    $all = ['analyst_id' => $viewer, 'active_tenant_id' => $default, 'active_tenant_all' => true];
    $one = ['analyst_id' => $viewer, 'active_tenant_id' => $default, 'active_tenant_all' => false];
    $oth = ['analyst_id' => $viewer, 'active_tenant_id' => $tenantZ, 'active_tenant_all' => false];

    $peopleIds = fn(array $s) => array_map('intval', array_column(callAssets('get_people.php', $s, ['search' => 'ZZ Allco', 'scope' => 'current'])['users'] ?? [], 'id'));
    $assetIds  = fn(array $s) => array_map('intval', array_column(callAssets('get_assets.php', $s, ['search' => 'ZZ-ALLCO'])['assets'] ?? [], 'id'));

    echo "\nAssets -> People\n";
    $p = $peopleIds($all);
    ok('All companies lists the person in each company', in_array($userD, $p, true) && in_array($userZ, $p, true), json_encode($p));
    $p = $peopleIds($one);
    ok('one company still lists only its own person', $p === [$userD], json_encode($p));
    $p = $peopleIds($oth);
    ok('the other company lists only its own person', $p === [$userZ], json_encode($p));

    echo "\nThe asset list (one company until its dropdowns follow the asset)\n";
    $a = $assetIds($all);
    ok("All companies still lists only the selected company's asset", $a === [$assetD], json_encode($a));
    $a = $assetIds($one);
    ok('one company still lists only its own asset', $a === [$assetD], json_encode($a));

    echo "\nHolders and a person's equipment\n";
    $_SESSION = $all;
    $ctx = ActorContext::fromSession($conn);
    $h = array_map('intval', array_column(AssetsService::usersHoldingAssets($conn, $ctx, 'ZZ Allco'), 'id'));
    ok('All companies lists the holder in each company', in_array($userD, $h, true) && in_array($userZ, $h, true), json_encode($h));
    $panel = AssetsService::assetsForUser($conn, $ctx, $userZ);
    ok("All companies shows the other company's person their equipment",
        in_array($assetZ, array_map('intval', array_column($panel['assets'] ?? [], 'id')), true));
    $_SESSION = $one;
    $ctx = ActorContext::fromSession($conn);
    $h = array_map('intval', array_column(AssetsService::usersHoldingAssets($conn, $ctx, 'ZZ Allco'), 'id'));
    ok('one company still lists only its own holder', $h === [$userD], json_encode($h));
} finally {
    if ($assets) {
        $in = implode(',', array_fill(0, count($assets), '?'));
        $conn->prepare("DELETE FROM users_assets WHERE asset_id IN ($in)")->execute($assets);
        $conn->prepare("DELETE FROM assets WHERE id IN ($in)")->execute($assets);
    }
    if ($users) {
        $in = implode(',', array_fill(0, count($users), '?'));
        $conn->prepare("DELETE FROM users WHERE id IN ($in)")->execute($users);
    }
    if ($tenantZ) $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tenantZ]);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM tenants WHERE name = 'ZZ All companies test')
                                    + (SELECT COUNT(*) FROM users WHERE email LIKE 'zz-allco-%@example.invalid')
                                    + (SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZ-ALLCO-%')")->fetchColumn();
    echo "\n";
    ok('everything the test wrote is gone', $left === 0, "$left rows left");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
