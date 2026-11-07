<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Domains - split DNS (GH #249): the same name watched from outside AND inside,
 * as two linked records - one Public, one Internal.
 *
 *   php tests/domains-split-dns.php
 *
 * The rule under test: at most one record per name, per company, PER SCOPE. So a
 * name can be in the register twice (Public + Internal) and never three times.
 * Checked on add, on rename, on a scope change, through "Add an internal view", and
 * in how subdomains pick a parent when the parent's name is in twice.
 *
 * Runs inside a transaction that is ALWAYS rolled back (the domain-parties.php
 * pattern): nothing it adds survives, and the last check proves it. No lookups -
 * it never runs a check, so no name goes anywhere. No workflow listens to domain
 * events on a normal install; if one did, it would see zz... test names.
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/services/domains.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %s\n", $label); }
    else       { $fail++; printf("  FAIL %s  <- %s\n", $label, $detail); }
}

$conn = connectToDatabase();
if (!domainScopeReady($conn) || !domainSubdomainsReady($conn)) { echo "Run Database Verification first.\n"; exit(1); }
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx = new ActorContext(actorId: $admin, companyScope: null, source: 'ui');
$default = getDefaultTenantId($conn);
$tag = 'zzsd' . bin2hex(random_bytes(3));

$refused = function (callable $fn): string {
    try { $fn(); return ''; } catch (ServiceError $e) { return $e->getMessage(); }
};
$get = function (int $id) use ($conn): array {
    $s = $conn->prepare("SELECT id, domain_name, scope, tenant_id, parent_domain_id, owner_analyst_id FROM domains WHERE id = ?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
};
$parentOf = fn (int $id) => ($p = $get($id)['parent_domain_id'] ?? null) === null ? null : (int)$p;

echo "\nDomains - split DNS (#249), rolled back\n" . str_repeat('=', 72) . "\n";
$conn->beginTransaction();
try {
    $P = "$tag.example";

    echo "\n1. The same name, once per scope\n";
    $pub = DomainsService::createDomain($conn, $ctx, ['domain_name' => $P], $default);
    $int = DomainsService::createDomain($conn, $ctx, ['domain_name' => $P, 'scope' => 'internal'], $default);
    ok('a Public and an Internal record of the same name can both exist', $pub > 0 && $int > 0 && $pub !== $int);
    $err = $refused(fn () => DomainsService::createDomain($conn, $ctx, ['domain_name' => $P], $default));
    ok('a second PUBLIC record of the name is refused, and says which scope', str_contains($err, 'already in the register as a Public domain'), $err);
    $err = $refused(fn () => DomainsService::createDomain($conn, $ctx, ['domain_name' => $P, 'scope' => 'internal'], $default));
    ok('a second INTERNAL record of the name is refused', str_contains($err, 'already in the register as an Internal domain'), $err);

    echo "\n2. Each finds its twin, and each keeps its own permission\n";
    ok('the public record\'s twin is the internal one', domainTwin($conn, $pub, $P, null) === ['id' => $int, 'scope' => 'internal']);
    ok('...and the other way round', domainTwin($conn, $int, $P, null) === ['id' => $pub, 'scope' => 'public']);
    $pubRow = DomainsService::loadRow($conn, $pub);
    $intRow = DomainsService::loadRow($conn, $int);
    ok('PERMISSION IS PER RECORD: the public twin may leave the network', domainMayLeaveNetwork($pubRow));
    ok('...the internal twin may NOT - nothing is inherited from its public twin', !domainMayLeaveNetwork($intRow));
    require_once "$root/includes/feature_bingo.php";
    foreach (require "$root/includes/feature_bingo/cards/domains.php" as $card) {
        if ($card['id'] === 'domains.split_dns') ok('the Feature Bingo card "One name, watched from both sides" ticks while a twin exists', featureBingoRunCheck($conn, $card['check']) === true);
    }
    $lone = DomainsService::createDomain($conn, $ctx, ['domain_name' => "lone-$P"], $default);
    ok('a name in the register once has no twin', domainTwin($conn, $lone, "lone-$P", null) === null);

    echo "\n3. Changing scope or name cannot make a third\n";
    $err = $refused(fn () => DomainsService::updateDomain($conn, $ctx, $int, ['scope' => 'public']));
    ok('switching the internal twin to Public is refused - the public one exists', str_contains($err, 'one record per name and scope'), $err);
    $err = $refused(fn () => DomainsService::updateDomain($conn, $ctx, $pub, ['scope' => 'internal']));
    ok('...and the public twin to Internal likewise', str_contains($err, 'as an Internal domain'), $err);
    DomainsService::updateDomain($conn, $ctx, $lone, ['scope' => 'internal']);
    ok('a record WITHOUT a twin can still change scope freely', $get($lone)['scope'] === 'internal');
    DomainsService::updateDomain($conn, $ctx, $lone, ['scope' => 'public']);
    $err = $refused(fn () => DomainsService::updateDomain($conn, $ctx, $lone, ['domain_name' => $P]));
    ok('renaming a public record onto a name that already has a public record is refused', str_contains($err, 'already in the register'), $err);
    $err = $refused(fn () => DomainsService::updateDomain($conn, $ctx, $lone, ['domain_name' => $P, 'scope' => 'internal']));
    ok('...and renaming it AND making it Internal at once is refused too (that one exists as well)', str_contains($err, 'one record per name and scope'), $err);
    $l = $get($lone);
    ok('...and the refused edits changed nothing', $l['domain_name'] === "lone-$P" && $l['scope'] === 'public'
        && $get($pub)['scope'] === 'public' && $get($int)['scope'] === 'internal', json_encode($l));

    echo "\n4. Subdomains pick the parent of their own scope\n";
    $intSub = DomainsService::createDomain($conn, $ctx, ['domain_name' => "intra.$P", 'scope' => 'internal'], $default);
    $pubSub = DomainsService::createDomain($conn, $ctx, ['domain_name' => "web.$P"], $default);
    ok('an internal subdomain links to the INTERNAL parent', $parentOf($intSub) === $int, (string)$parentOf($intSub));
    ok('a public subdomain links to the PUBLIC parent', $parentOf($pubSub) === $pub, (string)$parentOf($pubSub));
    $c = domainParentCandidates($conn, "intra.$P", null, $intSub, 'internal');
    ok('the Edit dialog lists the same-scope parent first', ($c[0]['id'] ?? 0) === $int && count($c) === 2, json_encode($c));

    echo "\n5. Add an internal view - and subdomains move to it\n";
    $R = "r-$tag.example";
    $rPub = DomainsService::createDomain($conn, $ctx, ['domain_name' => $R, 'owner_analyst_id' => $admin], $default);
    $rIntSub = DomainsService::createDomain($conn, $ctx, ['domain_name' => "intra.$R", 'scope' => 'internal'], $default);
    $rPubSub = DomainsService::createDomain($conn, $ctx, ['domain_name' => "web.$R"], $default);
    ok('before the internal view exists, the internal subdomain hangs off the public parent', $parentOf($rIntSub) === $rPub);
    $rInt = DomainsService::addInternalView($conn, $ctx, $rPub);
    $row = $get($rInt);
    ok('Add an internal view makes an Internal record of the same name', $row['domain_name'] === $R && $row['scope'] === 'internal');
    ok('...in the SAME company as the public one', ($row['tenant_id'] === null) === ($get($rPub)['tenant_id'] === null) && $row['tenant_id'] == $get($rPub)['tenant_id']);
    ok('...starting with the same owner', (int)$row['owner_analyst_id'] === $admin);
    ok('the internal subdomain has moved to the new internal parent', $parentOf($rIntSub) === $rInt, (string)$parentOf($rIntSub));
    ok('...and the public one has stayed with the public parent', $parentOf($rPubSub) === $rPub);
    $h = $conn->prepare("SELECT old_value, new_value FROM domain_audit WHERE domain_id = ? AND field_name = 'parent_domain_id' ORDER BY id DESC LIMIT 1");
    $h->execute([$rIntSub]);
    $moved = $h->fetch(PDO::FETCH_ASSOC) ?: [];
    ok('...and the move is in the subdomain\'s history, from which record to which', ($moved['old_value'] ?? '') === "$R (public)" && ($moved['new_value'] ?? '') === "$R (internal)", json_encode($moved));
    $err = $refused(fn () => DomainsService::addInternalView($conn, $ctx, $rPub));
    ok('a second internal view is refused', str_contains($err, 'as an Internal domain'), $err);
    $err = $refused(fn () => DomainsService::addInternalView($conn, $ctx, $rInt));
    ok('ONE WAY ONLY: an internal record cannot be given a public view in one click', str_contains($err, 'already the internal view'), $err);

    echo "\n6. A twin is per company\n";
    $other = (int)$conn->query("SELECT id FROM tenants WHERE id <> $default ORDER BY id LIMIT 1")->fetchColumn();
    if ($other > 0) {
        $oInt = DomainsService::createDomain($conn, $ctx, ['domain_name' => "c-$tag.example", 'scope' => 'internal'], $other);
        $oPub = DomainsService::createDomain($conn, $ctx, ['domain_name' => "c-$tag.example"], $default);
        ok('the same name in two companies are not twins', domainTwin($conn, $oPub, "c-$tag.example", null) === null);
    } else {
        echo "  (only one company on this install - skipped)\n";
    }
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}
$left = (int)$conn->query("SELECT COUNT(*) FROM domains WHERE domain_name LIKE '%$tag%'")->fetchColumn();
ok('nothing survived (rolled back)', $left === 0, "$left rows");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
