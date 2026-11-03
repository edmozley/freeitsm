<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Intune: an asset nobody holds is given to its device's primary user - and nothing else is.
 *
 * intuneAssignPrimaryUsers() joins a managed device's `userId` (an Entra object
 * id) to the object id the Entra directory import keeps in
 * user_sso_identities.source_ref. It writes assignments, custody rows and
 * history, so every way it could pick the WRONG person, or overwrite a person's
 * own decision, is pinned here:
 *
 *   - an asset that already has a holder is never changed;
 *   - a leaver is never made a holder;
 *   - two devices naming two different people for one asset is left alone;
 *   - an object id nobody imported assigns nothing (no guessing by name);
 *   - on a multi-company install, never a holder from another company.
 *
 * Written after the first version wrote assigned_by_analyst_id = 0 for the
 * system actor, which fk_users_assets_analyst refuses: the "assigned" case is
 * also the proof that the actor is now stored as NULL.
 *
 * Runs in a transaction that is ALWAYS rolled back. asset.assigned workflows are
 * paused for the run (they dispatch on their own connection, outside the
 * transaction) and switched back on in `finally`. The function reads every
 * Intune device on the install, so real unheld assets are assigned too - inside
 * the same transaction, and rolled back with it.
 *
 * Run:  FREEITSM_TEST_DATABASE=<throwaway db> php tests/intune-primary-user.php
 *
 * @wiki Asset-Reconciliation-Developer-Guide
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/intune.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nIntune: assets to their primary user\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
// Writes (inside a transaction that is rolled back, but writes), so it runs only
// against the throwaway database it was pointed at - never a live install by accident.
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes assets, assignments and Intune devices, and pauses asset.assigned workflows.\n");
}
foreach (['intune_devices', 'users_assets', 'user_sso_identities'] as $t) {
    try { $conn->query("SELECT 1 FROM $t LIMIT 0"); }
    catch (PDOException $e) { echo "  SKIP  run Database Verification first: no $t table\n"; exit(0); }
}

$paused = array_map('intval', $conn->query("SELECT id FROM workflows WHERE is_active = 1 AND trigger_event = 'asset.assigned'")->fetchAll(PDO::FETCH_COLUMN));
if ($paused) $conn->exec("UPDATE workflows SET is_active = 0 WHERE id IN (" . implode(',', $paused) . ")");

$conn->beginTransaction();
try {
    $conn->prepare("INSERT INTO auth_providers (protocol, display_name, issuer_url, client_id, enabled)
                    VALUES ('oidc', 'ZZ Intune test Entra', 'https://login.microsoftonline.com/zz-test/v2.0', 'zz-test', 1)")->execute();
    $pid = (int)$conn->lastInsertId();
    $multi = isMultiTenant($conn);
    $default = getDefaultTenantId($conn);
    $other = $multi ? (int)$conn->query("SELECT id FROM tenants WHERE id <> $default AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn() : 0;

    $oid = fn() => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
    $person = function (string $name, int $active = 1, ?int $tenant = null) use ($conn, $pid, $oid): array {
        $conn->prepare("INSERT INTO users (email, display_name, is_active, tenant_id, is_managed, auth_provider_id) VALUES (?, ?, ?, ?, 1, ?)")
             ->execute([strtolower(str_replace(' ', '.', $name)) . '.iptest@example.invalid', $name, $active, $tenant, $pid]);
        $id = (int)$conn->lastInsertId();
        $o = $oid();
        $conn->prepare("INSERT INTO user_sso_identities (user_id, provider_id, subject, source_ref) VALUES (?, ?, ?, ?)")
             ->execute([$id, $pid, 'entra:' . $o, $o]);
        return [$id, $o];
    };
    $asset = function (string $host, ?int $tenant = null) use ($conn): int {
        $conn->prepare("INSERT INTO assets (hostname, tenant_id) VALUES (?, ?)")->execute([$host, $tenant]);
        return (int)$conn->lastInsertId();
    };
    $device = fn(int $asset, string $userOid) => $conn->prepare("INSERT INTO intune_devices (intune_id, asset_id, device_name, user_id) VALUES (?, ?, ?, ?)")
        ->execute(['zz-' . bin2hex(random_bytes(8)), $asset, 'ZZ-IP-DEV', $userOid]);
    $holders = function (int $asset) use ($conn): array {
        $s = $conn->prepare("SELECT user_id FROM users_assets WHERE asset_id = ? ORDER BY user_id"); $s->execute([$asset]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    };

    [$ana, $anaOid]   = $person('IP Ana');
    [$ben, $benOid]   = $person('IP Ben');
    [$gone, $goneOid] = $person('IP Leaver', 0);

    $aFree     = $asset('ZZ-IP-FREE');      $device($aFree, $anaOid);
    $aHeld     = $asset('ZZ-IP-HELD');      $device($aHeld, $anaOid);
    $conn->prepare("INSERT INTO users_assets (asset_id, user_id) VALUES (?, ?)")->execute([$aHeld, $ben]);
    $aLeaver   = $asset('ZZ-IP-LEAVER');    $device($aLeaver, $goneOid);
    $aTwo      = $asset('ZZ-IP-TWO');       $device($aTwo, $anaOid); $device($aTwo, $benOid);
    $aUnknown  = $asset('ZZ-IP-UNKNOWN');   $device($aUnknown, $oid());
    $aOther    = $other ? $asset('ZZ-IP-OTHERCO', $other) : 0;
    if ($aOther) $device($aOther, $anaOid);   // Ana is in the Default company

    $r = intuneAssignPrimaryUsers($conn);
    ok('it returns how many were assigned and how many failed', isset($r['assigned'], $r['failed']), json_encode($r));
    ok('nothing failed', $r['failed'] === 0, json_encode($r));

    ok('POSITIVE CONTROL: an unheld asset goes to its device\'s primary user', $holders($aFree) === [$ana], json_encode($holders($aFree)));
    $by = $conn->prepare("SELECT assigned_by_analyst_id, notes FROM users_assets WHERE asset_id = ?");
    $by->execute([$aFree]);
    $row = $by->fetch(PDO::FETCH_ASSOC);
    ok('...recorded as nobody (NULL), not analyst 0', $row && $row['assigned_by_analyst_id'] === null, json_encode($row));
    ok('...with a note saying where it came from', $row && $row['notes'] === 'Primary user of the device in Intune');
    ok('an asset somebody already holds is not changed', $holders($aHeld) === [$ben], json_encode($holders($aHeld)));
    ok('a leaver is never made a holder', $holders($aLeaver) === []);
    ok('two devices naming two people leave the asset alone', $holders($aTwo) === []);
    ok('an object id nobody imported assigns nothing', $holders($aUnknown) === []);
    if ($aOther) ok('a person from another company is never made the holder', $holders($aOther) === []);
    else echo "  SKIP  the other-company case needs a second company\n";

    $again = intuneAssignPrimaryUsers($conn);
    ok('a second run changes nothing it already did', $holders($aFree) === [$ana] && $again['failed'] === 0, json_encode($again));
} finally {
    $conn->rollBack();
    if ($paused) $conn->exec("UPDATE workflows SET is_active = 1 WHERE id IN (" . implode(',', $paused) . ")");
}

echo "\n" . str_repeat('=', 70) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
