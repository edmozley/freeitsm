<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * "Company B supports company A" (GH #228).
 *
 *   php tests/tenant-support.php
 *
 * The rules under test (includes/tenant_support.php):
 *   - a requester belongs to ONE company; a link only says what B expects;
 *   - "Don't warn" quietens the wrong-company warning one way only (B filed, A requester);
 *   - "Portal" offers B under Raise with to A's people - and only then is B
 *     accepted as the ticket's company; any other id falls back to their own;
 *   - an inactive supporter is never offered; a company cannot support itself;
 *   - both ticks off removes the link; deleting a company removes its links;
 *   - the mailboxes offered follow the chosen company;
 *   - a manager's team tickets may sit in a company that supports theirs.
 *
 * Runs inside a transaction that is ALWAYS rolled back: nothing it adds
 * survives, and the last check proves it. It creates no ticket and sends nothing.
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/tenancy.php";
require_once "$root/includes/tenant_support.php";
require_once "$root/includes/self_service_ticket_types.php";
require_once "$root/includes/self_service_mailboxes.php";
require_once "$root/includes/managers.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %s\n", $label); }
    else       { $fail++; printf("  FAIL %s  <- %s\n", $label, $detail); }
}

$conn = connectToDatabase();
if (!tenantSupportReady($conn)) { echo "Run Database Verification first.\n"; exit(1); }
$tag = 'zzts' . bin2hex(random_bytes(3));

$conn->beginTransaction();
try {
    $newCo = function (string $name, int $active = 1) use ($conn, $tag): int {
        $conn->prepare("INSERT INTO tenants (name, is_default, is_active) VALUES (?, 0, ?)")->execute(["$tag $name", $active]);
        return (int)$conn->lastInsertId();
    };
    // Companies BEFORE anything asks isMultiTenant() - it counts once per run.
    $a = $newCo('A'); $b = $newCo('B'); $c = $newCo('C inactive', 0); $d = $newCo('D');
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active) VALUES (?, ?, ?, 1)")->execute(["u@$tag.example", "$tag user", $a]);
    $uA = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active) VALUES (?, ?, NULL, 1)")->execute(["n@$tag.example", "$tag nobody"]);
    $uNone = (int)$conn->lastInsertId();
    $ids = fn(array $list) => array_map(fn($x) => $x['id'], $list);

    echo "\nNo links - nothing changes:\n";
    ok('no Raise with picker', selfServiceRaiseWithCompanies($conn, $a) === []);
    ok('a posted company B is ignored (their own)', selfServiceTicketTenant($conn, $uA, $b) === $a);
    ok('no quiet warning', !tenantSupportQuietWarning($conn, $b, $a));
    ok('a manager sees only their own company', managerTicketCompanies($conn, $a) === [$a]);

    echo "\nB supports A, Don't warn only:\n";
    tenantSupportSet($conn, $b, $a, true, false);
    ok('warning quiet on B tickets from A people', tenantSupportQuietWarning($conn, $b, $a));
    ok('...one way only (A tickets from B people still warn)', !tenantSupportQuietWarning($conn, $a, $b));
    ok('still no picker without the Portal tick', selfServiceRaiseWithCompanies($conn, $a) === []);
    ok('still no B from the portal', selfServiceTicketTenant($conn, $uA, $b) === $a);

    echo "\nB supports A, Portal ticked:\n";
    tenantSupportSet($conn, $b, $a, true, true);
    $offer = selfServiceRaiseWithCompanies($conn, $a);
    ok('picker offers own company first, then B', $ids($offer) === [$a, $b], json_encode($ids($offer)));
    ok('own company is marked own', $offer[0]['own'] === true && $offer[1]['own'] === false);
    ok('B is accepted as the ticket company', selfServiceTicketTenant($conn, $uA, $b) === $b);
    ok('D (not linked) falls back to their own', selfServiceTicketTenant($conn, $uA, $d) === $a);
    ok('no choice = their own', selfServiceTicketTenant($conn, $uA, null) === $a);
    ok('the link is not two-way (B people get no picker)', selfServiceRaiseWithCompanies($conn, $b) === []);
    ok('a manager at A also sees tickets in B', managerTicketCompanies($conn, $a) === [$a, $b], json_encode(managerTicketCompanies($conn, $a)));

    echo "\nInactive and edge cases:\n";
    tenantSupportSet($conn, $c, $a, false, true);
    ok('an inactive supporter is not offered', $ids(selfServiceRaiseWithCompanies($conn, $a)) === [$a, $b]);
    ok('...nor accepted', selfServiceTicketTenant($conn, $uA, $c) === $a);
    ok('a requester with no company gets no picker', selfServiceRaiseWithCompanies($conn, null) === []);
    ok('...and a posted B is ignored', selfServiceTicketTenant($conn, $uNone, $b) === null);
    $selfErr = '';
    try { tenantSupportSet($conn, $a, $a, true, true); } catch (InvalidArgumentException $e) { $selfErr = $e->getMessage(); }
    ok('a company cannot support itself', $selfErr !== '');
    tenantSupportSet($conn, $d, $a, true, false);
    tenantSupportSet($conn, $d, $a, false, false);
    ok('both ticks off removes the link', !isset(tenantSupportLinksFor($conn, $d)[$a]));
    $links = tenantSupportLinksFor($conn, $b);
    ok('links read back with both ticks', ($links[$a] ?? null) === ['quiet_warning' => true, 'portal_raise' => true], json_encode($links));

    echo "\nMailboxes follow the chosen company:\n";
    $mb = function (string $name, ?int $co) use ($conn, $tag): int {
        $conn->prepare("INSERT INTO target_mailboxes (name, provider, azure_tenant_id, azure_client_id, azure_client_secret,
                            oauth_redirect_uri, imap_server, imap_port, target_mailbox, is_active, tenant_id)
                        VALUES (?, 'imap', '', '', '', '', '127.0.0.1', 1, ?, 1, ?)")->execute(["$tag $name", "$name@$tag.invalid", $co]);
        return (int)$conn->lastInsertId();
    };
    $mbA = $mb('mbA', $a); $mbB = $mb('mbB', $b);
    $own = selfServiceMailboxes($conn, $uA);
    ok('own company: A mailbox offered, B not', isset($own[$mbA]) && !isset($own[$mbB]));
    $toB = selfServiceMailboxes($conn, $uA, $b);
    ok('Raise with B: B mailbox offered, A not', isset($toB[$mbB]) && !isset($toB[$mbA]));
    $toD = selfServiceMailboxes($conn, $uA, $d);
    ok('a company not offered: back to A mailboxes', isset($toD[$mbA]) && !isset($toD[$mbB]));

    echo "\nDeleting a company removes its links:\n";
    $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$c]);
    $n = $conn->prepare("SELECT COUNT(*) FROM tenant_support_links WHERE supporter_tenant_id = ? OR supported_tenant_id = ?");
    $n->execute([$c, $c]);
    ok('links of a deleted company are gone (FK cascade)', (int)$n->fetchColumn() === 0);
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
}
$left = (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE name LIKE '$tag%'")->fetchColumn()
      + (int)$conn->query("SELECT COUNT(*) FROM users WHERE email LIKE '%@$tag.example'")->fetchColumn();
ok('nothing survived (rolled back)', $left === 0, "$left rows");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
