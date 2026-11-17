<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Domains: move a domain to another company (GH #384).
 *
 *   php tests/domain-move-company.php      (run from a TEST install, never a real one)
 *
 * DomainsService::moveToCompany():
 *   - moves the domain with its internal view and subdomains, in one go;
 *   - refuses a subdomain on its own, a name the target already holds, ticket
 *     and CMDB links, and a contract - and moves NOTHING when it refuses;
 *   - clears the registrar account, cost centre and customer person the target
 *     company does not have, and records each in the history;
 *   - refuses analyst 0 and a target outside the actor's companies;
 *   - moving back to Default stores NULL, as every scoped table does.
 *
 * Writes, and removes in a finally block by the ids it got back:
 *   - one company "ZZ Domain move" (a single-company install becomes
 *     multi-company for the run - that is why this must be a test install);
 *   - domains zzmv.example (public + internal) and shop.zzmv.example, and a
 *     zzmv.example in the ZZ company for the duplicate check;
 *   - a registrar account, cost centre, ticket, CMDB object (and class, if the
 *     install has none) and contract, all ZZ-named.
 * Never fires a workflow: the domain.* events dispatch only to configured
 * workflows, and the test install has none for domains.
 * Control: drop a refusal or a clear from moveToCompany() and its check fails.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/services/domains.php';

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
 * Runs a move and returns the ServiceError message it refused with, or null when it moved.
 *
 * @param int $id  the domain to move
 * @param int $to  the target company
 */
function refusal(PDO $conn, ActorContext $ctx, int $id, int $to): ?string {
    try { DomainsService::moveToCompany($conn, $ctx, $id, $to); return null; }
    catch (ServiceError $e) { return $e->getMessage(); }
}

/**
 * The stored company of each domain, id => tenant_id (null = Default).
 *
 * @param int[] $ids  the domains to read
 */
function tenants(PDO $conn, array $ids): array {
    $st = $conn->prepare("SELECT id, tenant_id FROM domains WHERE id IN (" . implode(',', array_map('intval', $ids)) . ") ORDER BY id");
    $st->execute();
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r['tenant_id'] === null ? null : (int)$r['tenant_id'];
    return $out;
}

echo "\nDomains: move a domain to another company (GH #384)\n" . str_repeat('=', 70) . "\n";
$conn = connectToDatabase();
$db = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
echo "  database: $db\n";
if (!preg_match('/test/i', $db)) { echo "  STOP  this test adds a company; run it only on a test install\n"; exit(1); }

$viewer = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND can_access_all_tenants = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$viewer) { echo "  SKIP  no active analyst with access to every company\n"; exit(0); }

$tenantZ = 0; $domains = []; $made = ['domain_registrar_accounts' => [], 'cost_centres' => [], 'tickets' => [], 'cmdb_objects' => [], 'contracts' => [], 'users' => [], 'cmdb_classes' => []];
try {
    // The company first: tenantCount() caches, and nothing may ask it before this.
    $conn->prepare("INSERT INTO tenants (name) VALUES (?)")->execute(['ZZ Domain move']);
    $tenantZ = (int)$conn->lastInsertId();
    $default = getDefaultTenantId($conn);
    ok('the install is multi-company for the run', isMultiTenant($conn));

    $_SESSION = ['analyst_id' => $viewer];
    $ctx = ActorContext::fromSession($conn);

    // Company-owned things in Default, for the domain to carry.
    $conn->prepare("INSERT INTO domain_registrar_accounts (account_name, tenant_id, created_datetime) VALUES ('ZZ move account', NULL, UTC_TIMESTAMP())")->execute();
    $acc = $made['domain_registrar_accounts'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO cost_centres (code, name, tenant_id, is_active) VALUES ('ZZMV', 'ZZ move cost centre', ?, 1)")->execute([$default]);
    $cc = $made['cost_centres'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active) VALUES ('zzmv@example.invalid', 'ZZ move customer', NULL, 1)")->execute();
    $person = $made['users'][] = (int)$conn->lastInsertId();

    $p = $domains[] = DomainsService::createDomain($conn, $ctx, [
        'domain_name' => 'zzmv.example', 'registrar_account_id' => $acc, 'cost_centre_id' => $cc, 'customer_user_id' => $person,
    ], $default);
    $twin = $domains[] = DomainsService::addInternalView($conn, $ctx, $p);
    // Not www.: domainNormalise() strips it, which would make this zzmv.example again.
    $sub = $domains[] = DomainsService::createDomain($conn, $ctx, ['domain_name' => 'shop.zzmv.example'], $default);
    $group = [$p, $twin, $sub];
    $parentOf = (int)$conn->query("SELECT parent_domain_id FROM domains WHERE id = $sub")->fetchColumn();
    ok('fixture: shop.zzmv.example is a subdomain of zzmv.example', $parentOf === $p, "parent $parentOf");

    echo "\nRefusals move nothing\n";
    $r = refusal($conn, $ctx, $sub, $tenantZ);
    ok('a subdomain on its own is refused, naming its parent', $r !== null && str_contains($r, 'subdomain of zzmv.example'), (string)$r);

    $dupZ = $domains[] = DomainsService::createDomain($conn, $ctx, ['domain_name' => 'zzmv.example'], $tenantZ);
    $r = refusal($conn, $ctx, $p, $tenantZ);
    ok('a name the target already holds is refused', $r !== null && str_contains($r, 'already has zzmv.example'), (string)$r);
    DomainsService::deleteDomain($conn, $ctx, $dupZ);

    $conn->prepare("INSERT INTO tickets (ticket_number, subject, tenant_id) VALUES ('ZZMV-1', 'ZZ move ticket', NULL)")->execute();
    $ticket = $made['tickets'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO ticket_domains (ticket_id, domain_id) VALUES (?, ?)")->execute([$ticket, $p]);
    $r = refusal($conn, $ctx, $p, $tenantZ);
    ok('a linked ticket is refused', $r !== null && str_contains($r, 'tickets: 1'), (string)$r);
    // Deleted, the link stays but no longer counts: the Links tab cannot show or
    // remove it. It is still there for the move below, which must not be stopped.
    $conn->prepare("UPDATE tickets SET deleted_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$ticket]);

    $class = (int)$conn->query("SELECT id FROM cmdb_classes ORDER BY id LIMIT 1")->fetchColumn();
    if (!$class) {
        $conn->prepare("INSERT INTO cmdb_classes (class_key, name) VALUES ('zz_move', 'ZZ move class')")->execute();
        $class = $made['cmdb_classes'][] = (int)$conn->lastInsertId();
    }
    if ($class) {
        $conn->prepare("INSERT INTO cmdb_objects (class_id, name, tenant_id) VALUES (?, 'ZZ move CI', NULL)")->execute([$class]);
        $ci = $made['cmdb_objects'][] = (int)$conn->lastInsertId();
        // On the SUBDOMAIN: a link anywhere in the group stops the whole move.
        $conn->prepare("INSERT INTO domain_cmdb_objects (domain_id, cmdb_object_id) VALUES (?, ?)")->execute([$sub, $ci]);
        $r = refusal($conn, $ctx, $p, $tenantZ);
        ok('a CMDB link on a subdomain is refused', $r !== null && str_contains($r, 'CMDB objects: 1'), (string)$r);
        $conn->prepare("DELETE FROM domain_cmdb_objects WHERE cmdb_object_id = ?")->execute([$ci]);
    } else {
        echo "  SKIP  no CMDB class to make a CI with\n";
    }

    $conn->prepare("INSERT INTO contracts (contract_number, title, tenant_id) VALUES ('ZZMV-C1', 'ZZ move contract', NULL)")->execute();
    $contract = $made['contracts'][] = (int)$conn->lastInsertId();
    $conn->prepare("UPDATE domains SET contract_id = ? WHERE id = ?")->execute([$contract, $twin]);
    $r = refusal($conn, $ctx, $p, $tenantZ);
    ok('a contract on the internal view is refused', $r !== null && str_contains($r, 'contract'), (string)$r);
    $conn->prepare("UPDATE domains SET contract_id = NULL WHERE id = ?")->execute([$twin]);

    ok('after every refusal, all three are still in Default', tenants($conn, $group) === [$p => null, $twin => null, $sub => null], json_encode(tenants($conn, $group)));

    echo "\nWho may move\n";
    $nobody = new ActorContext(actorId: 0, companyScope: null);
    ok('analyst 0 is refused', refusal($conn, $nobody, $p, $tenantZ) === 'You do not have access to that company.');
    $oneCo = new ActorContext(actorId: $viewer, companyScope: [$default]);
    ok('a target outside the actor\'s companies is refused', refusal($conn, $oneCo, $p, $tenantZ) === 'You do not have access to that company.');
    ok('an unknown company is refused the same way', refusal($conn, $ctx, $p, 999999999) === 'You do not have access to that company.');

    echo "\nThe move\n";
    $res = DomainsService::moveToCompany($conn, $ctx, $p, $tenantZ);
    ok('moves the domain, its internal view and its subdomain (a deleted ticket\'s link does not stop it)',$res['moved'] === 3 && $res['ids'] === $group, json_encode($res));
    ok('all three are in the ZZ company', tenants($conn, $group) === [$p => $tenantZ, $twin => $tenantZ, $sub => $tenantZ], json_encode(tenants($conn, $group)));
    $row = DomainsService::loadRow($conn, $p);
    ok('registrar account, cost centre and customer are cleared', $row['registrar_account_id'] === null && $row['cost_centre_id'] === null && $row['customer_user_id'] === null, json_encode(array_intersect_key($row, array_flip(['registrar_account_id', 'cost_centre_id', 'customer_user_id']))));
    $cl = array_values(array_filter($res['cleared'], fn($c) => $c['id'] === $p));
    ok('and reported as cleared', $cl && $cl[0]['fields'] === ['registrar_account_id', 'cost_centre_id', 'customer_user_id'], json_encode($res['cleared']));
    $h = $conn->prepare("SELECT domain_id, old_value, new_value FROM domain_audit WHERE field_name = 'company' AND domain_id IN ($p, $twin, $sub) ORDER BY domain_id");
    $h->execute();
    $hist = $h->fetchAll(PDO::FETCH_ASSOC);
    ok('each record\'s history says where it moved', count($hist) === 3 && $hist[0]['new_value'] === 'ZZ Domain move', json_encode($hist));
    $h = $conn->prepare("SELECT field_name FROM domain_audit WHERE domain_id = ? AND field_name IN ('registrar_account_id', 'cost_centre_id', 'customer_user_id') AND new_value IS NULL ORDER BY id");
    $h->execute([$p]);
    ok('and each cleared field', $h->fetchAll(PDO::FETCH_COLUMN) === ['registrar_account_id', 'cost_centre_id', 'customer_user_id']);
    ok('the subdomain keeps its parent', (int)$conn->query("SELECT parent_domain_id FROM domains WHERE id = $sub")->fetchColumn() === $p);

    $again = DomainsService::moveToCompany($conn, $ctx, $p, $tenantZ);
    ok('moving to the company it is in does nothing', $again['moved'] === 0);

    $back = DomainsService::moveToCompany($conn, $ctx, $p, $default);
    ok('moving back to Default stores NULL', $back['moved'] === 3 && tenants($conn, $group) === [$p => null, $twin => null, $sub => null], json_encode(tenants($conn, $group)));
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    // Subdomain first, then the rest: deleteDomain() also clears history and links.
    foreach (array_reverse($domains) as $id) {
        try { if ($conn->query("SELECT 1 FROM domains WHERE id = " . (int)$id)->fetchColumn()) DomainsService::deleteDomain($conn, $ctx ?? new ActorContext(actorId: $viewer), $id); }
        catch (Throwable $e) { echo "  WARN  cleanup domain $id: " . $e->getMessage() . "\n"; }
    }
    foreach ($made as $table => $ids) {
        foreach ($ids as $id) $conn->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);
    }
    if ($tenantZ) $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tenantZ]);
}

echo "\n" . str_repeat('-', 70) . "\n  $pass passed, $fail failed\n\n";
exit($fail ? 1 : 0);
