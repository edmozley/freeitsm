<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A reused sign-in name or email address never hands one person another's record.
 *
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/directory-identity-reuse.php
 *
 * Directory accounts come and go, and their names and addresses are handed on. A new
 * account arriving with an old account's name is a NEW person. This proves it,
 * through the real functions:
 *
 *   - LDAP sign-in, analysts and portal: an email match on an account already linked
 *     to a different directory identity is refused (ldapLinkedToOther());
 *   - imports (LDAP, CardDAV, Entra): a name match on a record linked to a different
 *     identity is 'taken', and one belonging to another sign-in source is
 *     'other_directory' (dsyncNameMatchRefused()); Entra reports it as a conflict.
 *
 * Controls: the same identity signs in again, a genuinely new person is created,
 * and an unlinked record is still adopted by email - so a refusal means "refused",
 * not "broken".
 *
 * It writes real rows, so it runs only when FREEITSM_TEST_DATABASE names the
 * database config.php points at. Its rows are named zz-... and removed at the end.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ldap.php';
require_once __DIR__ . '/../includes/directory_sync.php';
require_once __DIR__ . '/../includes/entra_sync.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the throwaway database config.php points at (this one is {$dbName}).\n";
    exit(0);
}

$tag = 'zz-' . bin2hex(random_bytes(3));
$domain = "$tag.example.test";
$newProvider = function (string $protocol, string $suffix) use ($conn, $tag): array {
    $conn->prepare("INSERT INTO auth_providers (display_name, protocol, issuer_url, client_id, auto_create_users, auto_create_analysts)
                    VALUES (?, ?, 'https://zz.example.test', 'zz', 1, 1)")->execute(["$tag $suffix", $protocol]);
    $s = $conn->prepare("SELECT * FROM auth_providers WHERE id = ?");
    $s->execute([(int)$conn->lastInsertId()]);
    return $s->fetch(PDO::FETCH_ASSOC);
};
$person = fn(string $guid, string $user, ?string $mail, string $name) => [
    'guid' => $guid, 'username' => $user, 'email' => $mail !== null ? "$mail@$domain" : null, 'name' => "$tag $name",
    'job_title' => null, 'department' => null, 'office' => null, 'phone' => null, 'mobile' => null,
    'employee_id' => null, 'manager_dn' => null, 'dn' => '', 'disabled' => false,
];

$ldap  = $newProvider('ldap', 'LDAP');
$entra = $newProvider('oidc', 'Entra');
$pids  = [(int)$ldap['id'], (int)$entra['id']];
$analystIds = [];

try {
    echo "Imports: a name match on somebody else's record\n";
    $alice = dsyncCreate($conn, $ldap, $person('guid-a1', 'alice', 'alice', 'Alice'), false);
    check('a new person is created and linked (control)', $alice > 0);
    [$r, $how] = dsyncFindExisting($conn, $ldap, $person('guid-a1', 'alice', 'alice', 'Alice'));
    check('the same identity is found by its GUID (control)', $how === 'guid' && (int)$r['id'] === $alice);
    [$r, $how] = dsyncFindExisting($conn, $ldap, $person('guid-a2', 'alice', 'other', 'New Starter'));
    check('a new GUID on the same username is taken', $how === 'taken' && (int)$r['id'] === $alice, $how);
    [$r, $how] = dsyncFindExisting($conn, $ldap, $person('guid-a3', 'someone', 'alice', 'New Starter'));
    check('a new GUID on the same email is taken', $how === 'taken', $how);

    $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, ?, UTC_TIMESTAMP())")->execute(["bob@$domain", "$tag Bob"]);
    $bob = (int)$conn->lastInsertId();
    [$r, $how] = dsyncFindExisting($conn, $ldap, $person('guid-b1', 'bob', 'bob', 'Bob'));
    check('an unlinked record is still matched by email for adoption (control)', $how === 'email' && (int)$r['id'] === $bob, $how);

    $conn->prepare("INSERT INTO users (email, display_name, auth_provider_id, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())")
         ->execute(["carol@$domain", "$tag Carol", (int)$entra['id']]);
    $carol = (int)$conn->lastInsertId();
    [$r, $how] = dsyncFindExisting($conn, $ldap, $person('guid-c1', 'carol', 'carol', 'Carol'));
    check('a record belonging to another sign-in source is other_directory', $how === 'other_directory', $how);

    echo "Entra: the same address already managed by LDAP\n";
    $conn->prepare("INSERT INTO directory_sync_runs (provider_id, mode, status) VALUES (?, 'live', 'running')")->execute([(int)$entra['id']]);
    $runId = (int)$conn->lastInsertId();
    $counts = ['seen' => 0, 'created' => 0, 'updated' => 0, 'adopted' => 0, 'deactivated' => 0, 'conflict' => 0, 'error' => 0];
    $seen = []; $map = [];
    $graphUser = ['id' => '00000001-0000-4000-8000-000000000000', 'displayName' => "$tag Alice Entra",
                  'userPrincipalName' => "alice.e@$domain", 'mail' => "alice@$domain", 'accountEnabled' => true];
    entraImportPerson($conn, $entra, entraMapUser($graphUser, $entra), false, $runId, $counts, $seen, $map);
    $prov = $conn->prepare("SELECT auth_provider_id, display_name FROM users WHERE id = ?");
    $prov->execute([$alice]);
    $row = $prov->fetch(PDO::FETCH_ASSOC);
    check('it is a conflict, not an adoption', $counts['conflict'] === 1 && $counts['adopted'] === 0 && $counts['updated'] === 0);
    check('the person stays with LDAP, untouched', (int)$row['auth_provider_id'] === (int)$ldap['id'] && $row['display_name'] === "$tag Alice");

    echo "Portal sign-in (LDAP)\n";
    $r = ldapResolveUser($conn, $ldap, ['guid' => 'guid-a1', 'username' => 'alice', 'email' => "alice@$domain", 'name' => 'Alice']);
    check('the same identity signs in to its own record (control)', $r['ok'] && (int)$r['user_id'] === $alice);
    $r = ldapResolveUser($conn, $ldap, ['guid' => 'guid-a9', 'username' => 'newstarter', 'email' => "alice@$domain", 'name' => 'New Starter']);
    check('a different identity with the same email is refused', !$r['ok'], json_encode($r));
    $r = ldapResolveUser($conn, $ldap, ['guid' => 'guid-d1', 'username' => 'dave', 'email' => "dave@$domain", 'name' => "$tag Dave"]);
    check('a genuinely new person is created (control)', $r['ok'] && (int)$r['user_id'] > 0 && (int)$r['user_id'] !== $alice);

    echo "Analyst sign-in (LDAP)\n";
    $r = ldapResolveAnalyst($conn, $ldap, ['guid' => 'guid-an1', 'username' => "$tag-ana", 'email' => "ana@$domain", 'name' => "$tag Ana"]);
    if (!empty($r['analyst_id'])) $analystIds[] = (int)$r['analyst_id'];
    check('a new analyst is created (control)', $r['ok'] && !empty($r['analyst_id']), json_encode($r));
    $ana = (int)($r['analyst_id'] ?? 0);
    $r = ldapResolveAnalyst($conn, $ldap, ['guid' => 'guid-an1', 'username' => "$tag-ana", 'email' => "ana@$domain", 'name' => "$tag Ana"]);
    check('the same identity signs in again (control)', $r['ok'] && (int)($r['analyst_id'] ?? 0) === $ana);
    $r = ldapResolveAnalyst($conn, $ldap, ['guid' => 'guid-an2', 'username' => "$tag-intruder", 'email' => "ana@$domain", 'name' => 'Intruder']);
    if (!empty($r['analyst_id']) && (int)$r['analyst_id'] !== $ana) $analystIds[] = (int)$r['analyst_id'];
    check('a different identity with the same email is refused - never signed in as that analyst', !$r['ok'], json_encode($r));
} finally {
    foreach ($pids as $pid) {
        $conn->prepare("DELETE e FROM directory_sync_entries e JOIN directory_sync_runs r ON r.id = e.run_id WHERE r.provider_id = ?")->execute([$pid]);
        $conn->prepare("DELETE FROM directory_sync_runs WHERE provider_id = ?")->execute([$pid]);
        $conn->prepare("DELETE FROM user_sso_identities WHERE provider_id = ?")->execute([$pid]);
        $conn->prepare("DELETE FROM analyst_sso_identities WHERE provider_id = ?")->execute([$pid]);
    }
    $conn->prepare("DELETE FROM users WHERE display_name LIKE ? OR email LIKE ?")->execute(["$tag%", "%@$domain"]);
    foreach ($analystIds as $aid) $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$aid]);
    foreach ($pids as $pid) $conn->prepare("DELETE FROM auth_providers WHERE id = ?")->execute([$pid]);
}

$left = $conn->prepare("SELECT (SELECT COUNT(*) FROM users WHERE email LIKE ?) + (SELECT COUNT(*) FROM analysts WHERE email LIKE ?)");
$left->execute(["%@$domain", "%@$domain"]);
echo "\n$pass passed, $fail failed. Left behind: " . $left->fetchColumn() . "\n";
exit($fail ? 1 : 0);
