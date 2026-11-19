<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The company switcher remembers the last pick across sign-ins (includes/tenancy.php,
 * tenancyRememberCompany() / tenancyRestoreRememberedCompany()).
 *
 *   FREEITSM_TEST_DATABASE=<the install's database> php tests/remember-company.php
 *
 * Writes, and removes in a finally block by the ids it got back: one company
 * "ZZ Remember company", two analysts (zz_remember_all with access to every company,
 * zz_remember_one with the Default company only) and their preference rows.
 * Control: with the restore call taken out of getActiveTenantId(), checks 2 and 3 fail.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nThe company switcher remembers the last pick\n" . str_repeat('=', 60) . "\n";
$conn = connectToDatabase();
$db = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if (getenv('FREEITSM_TEST_DATABASE') !== $db) {
    echo "  STOP  set FREEITSM_TEST_DATABASE=$db to run against this install (it adds a company)\n";
    exit(1);
}

$tenantZ = 0; $analysts = [];
try {
    // The company first: tenantCount() caches, and nothing may ask it before this.
    $conn->prepare("INSERT INTO tenants (name) VALUES (?)")->execute(['ZZ Remember company']);
    $tenantZ = (int)$conn->lastInsertId();
    $default = getDefaultTenantId($conn);
    ok('the install is multi-company for the run', isMultiTenant($conn));

    $mk = function (string $username, int $allAccess) use ($conn, &$analysts): int {
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, can_access_all_tenants)
                        VALUES (?, '!', ?, ?, 1, ?)")
             ->execute([$username, 'ZZ ' . $username, $username . '@example.invalid', $allAccess]);
        return $analysts[] = (int)$conn->lastInsertId();
    };
    $all = $mk('zz_remember_all', 1);
    $one = $mk('zz_remember_one', 0);
    $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)")->execute([$one, $default]);

    /** A fresh sign-in: a session with nobody's company in it yet. */
    $signIn = function (int $analystId) { $_SESSION = ['analyst_id' => $analystId]; };

    /** My preferences -> Start in the company I last used. */
    $optIn = function (int $analystId, bool $on) use ($conn) {
        $conn->prepare("INSERT INTO user_preferences (analyst_id, preference_key, preference_value, updated_datetime)
                        VALUES (?, 'remember_company', ?, UTC_TIMESTAMP())
                        ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value)")
             ->execute([$analystId, $on ? 'on' : '']);
    };

    echo "\n1. Nothing remembered: as before\n";
    $signIn($all);
    ok('opens at the Default company', getActiveTenantId($conn, $all) === $default);
    ok('not All companies', !isActiveTenantAll($conn));

    echo "\n1b. A pick remembered but the preference off (the default): as before\n";
    tenancyRememberCompany($conn, $all, $tenantZ, true);
    $signIn($all);
    ok('opens at the Default company', getActiveTenantId($conn, $all) === $default);
    ok('not All companies', !isActiveTenantAll($conn));

    echo "\n2. A company remembered, preference on\n";
    $optIn($all, true);
    $optIn($one, true);
    tenancyRememberCompany($conn, $all, $tenantZ, false);
    $signIn($all);
    ok('opens at the remembered company', getActiveTenantId($conn, $all) === $tenantZ, (string)getActiveTenantId($conn, $all));
    ok('not All companies', !isActiveTenantAll($conn));

    echo "\n3. All companies remembered\n";
    tenancyRememberCompany($conn, $all, $tenantZ, true);
    $signIn($all);
    ok('opens in All companies', isActiveTenantAll($conn));
    ok('writes still go to the remembered company', getActiveTenantId($conn, $all) === $tenantZ);

    echo "\n4. A pick in this session wins\n";
    $signIn($all);
    setActiveTenantId($default);
    ok('the session pick stands', getActiveTenantId($conn, $all) === $default && !isActiveTenantAll($conn));
    // The case the restored flag exists for: All companies picked before anything
    // has read the company, on a session with no company in it.
    tenancyRememberCompany($conn, $all, $tenantZ, false);
    $signIn($all);
    setActiveTenantAll(true);
    ok('an All companies pick stands', isActiveTenantAll($conn), json_encode($_SESSION));

    echo "\n4b. A deactivated company is not restored\n";
    tenancyRememberCompany($conn, $all, $tenantZ, true);
    $conn->prepare("UPDATE tenants SET is_active = 0 WHERE id = ?")->execute([$tenantZ]);
    $signIn($all);
    ok('falls back to the Default company', getActiveTenantId($conn, $all) === $default);
    ok('and nothing else of the pick (not All companies)', !isActiveTenantAll($conn));
    $conn->prepare("UPDATE tenants SET is_active = 1 WHERE id = ?")->execute([$tenantZ]);

    echo "\n5. A company the analyst can no longer see is not restored\n";
    tenancyRememberCompany($conn, $one, $tenantZ, false);   // as if access was removed since
    $signIn($one);
    ok('falls back to a company they can see', getActiveTenantId($conn, $one) === $default);
    ok('the session holds no inaccessible company', ($_SESSION['active_tenant_id'] ?? null) !== $tenantZ);
    $signIn($one);
    ok('ignores the restore with no access check too', getActiveTenantId($conn) !== $tenantZ);
} finally {
    foreach ($analysts as $id) {
        $conn->prepare("DELETE FROM user_preferences WHERE analyst_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$id]);
    }
    if ($tenantZ) $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tenantZ]);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
