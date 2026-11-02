<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Entra import - whose record is it? Matching, linking and the first sign-in.
 *
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/entra-sync-identity.php
 *
 * tests/entra-sync-mapping.php proves a Graph user becomes the right person array
 * and needs no database. This proves what happens next, through the real
 * entraImportPerson() and oidcLinkRequester(): a reused sign-in name or email
 * never takes over somebody's record, and a sign-in never replaces a real link.
 *
 * It writes real rows, so it runs only when FREEITSM_TEST_DATABASE names the
 * database config.php points at. Its rows are named zz-... and removed at the end.
 *
 * Controls: a genuine new person is created, a re-import finds the same record
 * after a rename, and an import's placeholder IS replaced, so a refusal means
 * "refused", not "broken".
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/oidc.php';
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
$conn->prepare("INSERT INTO auth_providers (display_name, protocol, issuer_url, client_id) VALUES (?, 'oidc', 'https://login.microsoftonline.com/zz/v2.0', 'zz')")
     ->execute([$tag]);
$pid = (int)$conn->lastInsertId();
$s = $conn->prepare("SELECT * FROM auth_providers WHERE id = ?");
$s->execute([$pid]);
$provider = $s->fetch(PDO::FETCH_ASSOC);
$conn->prepare("INSERT INTO directory_sync_runs (provider_id, mode, status) VALUES (?, 'live', 'running')")->execute([$pid]);
$runId = (int)$conn->lastInsertId();

$oid = fn(int $n) => sprintf('%08x-0000-4000-8000-000000000000', $n);
$graph = fn(int $n, string $name, string $upn, ?string $mail = null) => [
    'id' => $oid($n), 'displayName' => $name, 'userPrincipalName' => "$upn@$domain",
    'mail' => $mail !== null ? "$mail@$domain" : null, 'accountEnabled' => true,
];
$import = function (array $u, array $p = []) use ($conn, &$provider, $runId) {
    $counts = ['seen' => 0, 'created' => 0, 'updated' => 0, 'adopted' => 0, 'deactivated' => 0, 'conflict' => 0, 'error' => 0];
    $seen = []; $map = [];
    entraImportPerson($conn, $provider + $p, entraMapUser($u, $provider), false, $GLOBALS['runId'], $counts, $seen, $map);
    return $counts;
};
$link = function (int $userId) use ($conn, $pid) {
    $s = $conn->prepare("SELECT subject, source_ref FROM user_sso_identities WHERE provider_id = ? AND user_id = ?");
    $s->execute([$pid, $userId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: ['subject' => null, 'source_ref' => null];
};
$byName = function (string $name) use ($conn) {
    $s = $conn->prepare("SELECT id FROM users WHERE display_name = ?");
    $s->execute([$name]);
    return (int)$s->fetchColumn();
};

try {
    echo "An import\n";
    $c = $import($graph(1, "$tag One", 'one'));
    $one = $byName("$tag One");
    check('a new person is created (control)', $c['created'] === 1 && $one > 0);
    check('their link holds the object id under a placeholder', $link($one)['source_ref'] === $oid(1) && $link($one)['subject'] === 'entra:' . $oid(1));

    $c = $import($graph(1, "$tag One Renamed", 'one.renamed'));
    check('a rename finds the same record (control)', $c['updated'] === 1 && $byName("$tag One Renamed") === $one);

    echo "A reused sign-in name\n";
    $c = $import($graph(2, "$tag Two", 'two'));
    $two = $byName("$tag Two");
    $c = $import($graph(3, "$tag New Starter", 'two'));
    check('a different account on the same name is a conflict', $c['conflict'] === 1 && $c['updated'] === 0 && $c['created'] === 0);
    check('the old record keeps its name', $byName("$tag Two") === $two && $byName("$tag New Starter") === 0);
    check('the old record keeps its link', $link($two)['source_ref'] === $oid(2));

    echo "A reused email address\n";
    $c = $import($graph(4, "$tag Four", 'four', 'shared'));
    $four = $byName("$tag Four");
    $c = $import($graph(5, "$tag Five", 'five', 'shared'));
    check('a different account on the same email is a conflict', $c['conflict'] === 1 && $c['adopted'] === 0 && $c['updated'] === 0);
    check('the old record keeps its link', $link($four)['source_ref'] === $oid(4));

    echo "A link that has been made\n";
    $conn->prepare("UPDATE user_sso_identities SET source_ref = ? WHERE provider_id = ? AND user_id = ?")->execute(['', $pid, $four]);
    entraLinkIdentity($conn, $pid, $four, ['entra_id' => $oid(4), 'email' => null]);
    check('an empty source_ref is filled (control)', $link($four)['source_ref'] === $oid(4));
    entraLinkIdentity($conn, $pid, $four, ['entra_id' => $oid(9), 'email' => null]);
    check('a different object id never overwrites it', $link($four)['source_ref'] === $oid(4));

    echo "The first sign-in\n";
    check('the right account replaces the placeholder (control)', oidcLinkRequester($conn, $pid, $four, 'real-sub-4', "x@$domain", $oid(4)) && $link($four)['subject'] === 'real-sub-4');
    check('with no oid claim, a placeholder is still replaced (control)', oidcLinkRequester($conn, $pid, $two, 'real-sub-2', "x2@$domain") && $link($two)['subject'] === 'real-sub-2');
    check('a real link is not replaced', !oidcLinkRequester($conn, $pid, $four, 'intruder', "x@$domain", '') && $link($four)['subject'] === 'real-sub-4');

    $c = $import($graph(6, "$tag Six", 'six'));
    $six = $byName("$tag Six");
    check('the wrong oid cannot take a placeholder', !oidcLinkRequester($conn, $pid, $six, 'intruder', "x@$domain", $oid(7)) && $link($six)['subject'] === 'entra:' . $oid(6));

    echo "One import at a time\n";
    check('the first takes the lock (control)', dsyncLock($conn, $pid));
    $second = connectToDatabase();
    check('a second connection is refused', !dsyncLock($second, $pid));
    dsyncUnlock($conn, $pid);
    check('it is free again afterwards (control)', dsyncLock($second, $pid));
    dsyncUnlock($second, $pid);
} finally {
    $conn->prepare("DELETE FROM directory_sync_entries WHERE run_id = ?")->execute([$runId]);
    $conn->prepare("DELETE FROM directory_sync_runs WHERE id = ?")->execute([$runId]);
    $conn->prepare("DELETE FROM user_sso_identities WHERE provider_id = ?")->execute([$pid]);
    $conn->prepare("DELETE FROM users WHERE auth_provider_id = ?")->execute([$pid]);
    $conn->prepare("DELETE FROM auth_providers WHERE id = ?")->execute([$pid]);
}

$left = $conn->prepare("SELECT COUNT(*) FROM users WHERE display_name LIKE ?");
$left->execute(["$tag%"]);
echo "\n$pass passed, $fail failed. Left behind: " . $left->fetchColumn() . "\n";
exit($fail ? 1 : 0);
