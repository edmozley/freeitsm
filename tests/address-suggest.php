<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Address suggestions in a ticket's To and Cc (GitHub #307).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/address-suggest.php
 *
 * A ZZ second company (so the install is multi-company for the run), a supplier with a
 * service desk address and a contact, a person in each company, and outbound emails from
 * a ticket in each company plus a confidential one. Proves the four sources, their order,
 * that nothing crosses from one company's tickets to another's, that a confidential
 * ticket's recipients are never offered, and the supplier field's validation. Then the
 * endpoint over HTTP. Everything ZZ is removed in finally.
 *
 * TRAP: the second company is added BEFORE anything asks tenancy a question -
 * isMultiTenant() is cached per process.
 *
 * @wiki Ticket-Address-Suggestions-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/tickets.php';
require_once __DIR__ . '/../includes/services/contracts.php';
require_once __DIR__ . '/../includes/services/address_suggest.php';

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE)\n"; exit(0); }
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$tag = 'zz' . bin2hex(random_bytes(3));
$made = ['tenant' => 0, 'tickets' => [], 'users' => [], 'supplier' => 0, 'contact' => 0, 'emails' => []];
$sid = null;
try {
    // FIRST: a second company, before tenancy caches "single company".
    $conn->prepare("INSERT INTO tenants (name, is_default, is_active) VALUES (?, 0, 1)")->execute(["ZZ $tag Globex"]);
    $tB = $made['tenant'] = (int)$conn->lastInsertId();
    $tA = getDefaultTenantId($conn);
    check('the install is multi-company for the run', isMultiTenant($conn));

    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ tester');
    $_SESSION = ['analyst_id' => $admin];
    $user = (int)$conn->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
    // A closure with &$made, not fn(): an arrow function captures by value, and the ids would never reach the clean-up.
    $mk = function (int $tenant, string $subj) use ($conn, $ctx, $tag, $user, &$made): int { return $made['tickets'][] = TicketsService::createTicket($conn, $ctx, $tenant, ['subject' => "ZZ $tag $subj", 'user_id' => $user], null, 'ZZ test'); };
    $ticketA = $mk($tA, 'A');
    $ticketB = $mk($tB, 'B');
    $ticketConf = $mk($tA, 'confidential');
    $conn->prepare("UPDATE tickets SET sensitivity = 'confidential' WHERE id = ?")->execute([$ticketConf]);

    $sup = ContractsService::saveSupplier($conn, $ctx, ['legal_name' => "$tag Acme Ltd", 'trading_name' => "{$tag}acme", 'ticket_email' => "desk@{$tag}acme.example"]);
    $made['supplier'] = $sup['id'];
    $conn->prepare("INSERT INTO contacts (supplier_id, first_name, surname, email) VALUES (?, ?, 'Smith', ?)")->execute([$sup['id'], "{$tag}carol", "carol@{$tag}acme.example"]);
    $made['contact'] = (int)$conn->lastInsertId();
    $u = $conn->prepare("INSERT INTO users (email, display_name, tenant_id) VALUES (?, ?, ?)");
    $u->execute(["alice@{$tag}a.example", "{$tag}alice A", $tA]); $made['users'][] = (int)$conn->lastInsertId();
    $u->execute(["bob@{$tag}b.example", "{$tag}bob B", $tB]); $made['users'][] = (int)$conn->lastInsertId();
    $e = $conn->prepare("INSERT INTO emails (ticket_id, subject, direction, to_recipients, cc_recipients, received_datetime) VALUES (?, 'ZZ', 'Outbound', ?, ?, UTC_TIMESTAMP())");
    $e->execute([$ticketA, "partner@{$tag}a.example", "Someone <cc.partner@{$tag}a.example>"]); $made['emails'][] = (int)$conn->lastInsertId();
    $e->execute([$ticketB, "secret@{$tag}b.example", null]); $made['emails'][] = (int)$conn->lastInsertId();
    $e->execute([$ticketConf, "hush@{$tag}a.example", null]); $made['emails'][] = (int)$conn->lastInsertId();

    $s = fn(int $ticket, string $q) => AddressSuggest::search($conn, $admin, $ticket, $q);
    $emails = fn(array $r) => array_column($r, 'email');

    echo "Sources\n";
    $r = $s($ticketA, "{$tag}acme");
    check('a supplier\'s service desk comes first, by supplier name', ($r[0]['source'] ?? '') === 'service_desk' && $r[0]['email'] === "desk@{$tag}acme.example" && $r[0]['name'] === "{$tag}acme", json_encode($r));
    check('...followed by its contacts (search by supplier finds them)', in_array("carol@{$tag}acme.example", $emails($r), true));
    $r = $s($ticketA, "{$tag}carol");
    check('a supplier contact, with the supplier as detail', ($r[0]['source'] ?? '') === 'contact' && $r[0]['detail'] === "{$tag}acme" && $r[0]['name'] === "{$tag}carol Smith", json_encode($r));
    $r = $s($ticketA, "{$tag}alice");
    check('a person of the ticket\'s company', ($r[0]['source'] ?? '') === 'person' && $r[0]['email'] === "alice@{$tag}a.example", json_encode($r));
    $r = $s($ticketA, "partner@{$tag}");
    check('recently emailed from the company\'s tickets - To and Cc, with a display name stripped', ($r[0]['source'] ?? '') === 'recent' && in_array("partner@{$tag}a.example", $emails($r), true) && in_array("cc.partner@{$tag}a.example", $emails($r), true), json_encode($r));

    echo "Nothing crosses companies\n";
    check('a person of another company is not offered', !in_array("bob@{$tag}b.example", $emails($s($ticketA, "{$tag}bob")), true));
    check('...but is on that company\'s ticket', in_array("bob@{$tag}b.example", $emails($s($ticketB, "{$tag}bob")), true));
    check('an address emailed from another company\'s ticket is not offered', $s($ticketA, "secret@{$tag}") === []);
    check('...but is on that company\'s ticket', in_array("secret@{$tag}b.example", $emails($s($ticketB, "secret@{$tag}")), true));
    check('who a confidential ticket was emailed to is never offered', $s($ticketA, "hush@{$tag}") === []);
    check('suppliers are shared - offered on either company\'s ticket', ($s($ticketB, "{$tag}acme")[0]['source'] ?? '') === 'service_desk');

    echo "Edges\n";
    check('fewer than 2 characters asks nothing', $s($ticketA, 'z') === []);
    check('% and _ are typed characters, not wildcards', $s($ticketA, '%%') === [] && $s($ticketA, '__') === []);
    $r = $s($ticketA, "{$tag}");
    check('one address once, under its first source, at most ' . AddressSuggest::LIMIT, count($r) === count(array_unique(array_map('strtolower', $emails($r)))) && count($r) <= AddressSuggest::LIMIT, json_encode($emails($r)));

    echo "The supplier field\n";
    $bad = null;
    try { ContractsService::saveSupplier($conn, $ctx, ['id' => $sup['id'], 'ticket_email' => 'not an address']); } catch (ServiceError $x) { $bad = $x->getMessage(); }
    check('a service desk address must be an email address', $bad !== null && str_contains($bad, 'ticket_email'), (string)$bad);
    ContractsService::saveSupplier($conn, $ctx, ['id' => $sup['id'], 'ticket_email' => '']);
    check('emptied, it is no longer offered', ($s($ticketA, "{$tag}acme")[0]['source'] ?? '') !== 'service_desk');
    ContractsService::saveSupplier($conn, $ctx, ['id' => $sup['id'], 'ticket_email' => "desk@{$tag}acme.example"]);

    if ($base !== '') {
        echo "The endpoint\n";
        $csrf = bin2hex(random_bytes(32));
        $sid = 'zzas' . bin2hex(random_bytes(6));
        $sessDir = rtrim((string)ini_get('session.save_path'), '/\\') ?: sys_get_temp_dir();
        file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_name|s:5:"zztst";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');
        $get = function (string $path) use ($base, $sid) {
            $ch = curl_init("$base/$path");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid"]);
            return json_decode((string)curl_exec($ch), true) ?: [];
        };
        $r = $get("api/tickets/address_suggest.php?ticket_id=$ticketA&q=" . rawurlencode("{$tag}acme"));
        check('GET with a ticket answers its suggestions', !empty($r['success']) && ($r['suggestions'][0]['source'] ?? '') === 'service_desk', json_encode($r));
        $r = $get("api/tickets/address_suggest.php?ticket_id=999999999&q=abc");
        check('an unknown ticket answers nothing', empty($r['success']), json_encode($r));
        $r = $get("api/contracts/get_suppliers.php");
        $row = array_values(array_filter($r['suppliers'] ?? $r['data'] ?? [], fn($x) => (int)($x['id'] ?? 0) === $sup['id']))[0] ?? [];
        check('the supplier list carries the service desk address', ($row['ticket_email'] ?? '') === "desk@{$tag}acme.example", json_encode($row));
    }
} catch (Throwable $x) {
    check('no exception', false, get_class($x) . ': ' . $x->getMessage() . ' @ ' . basename($x->getFile()) . ':' . $x->getLine());
} finally {
    if ($sid) { $sessDir = rtrim((string)ini_get('session.save_path'), '/\\') ?: sys_get_temp_dir(); @unlink("$sessDir/sess_$sid"); }
    if ($made['emails']) $conn->exec("DELETE FROM emails WHERE id IN (" . implode(',', $made['emails']) . ")");
    $t = array_values(array_filter(array_map('intval', $made['tickets'])));
    if ($t) {
        $in = implode(',', $t);
        $tables = $conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'ticket_id' AND TABLE_NAME <> 'tickets'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $tbl) { try { $conn->exec("DELETE FROM `$tbl` WHERE ticket_id IN ($in)"); } catch (Throwable $e) {} }
        $conn->exec("DELETE FROM tickets WHERE id IN ($in)");
    }
    if ($made['users']) $conn->exec("DELETE FROM users WHERE id IN (" . implode(',', $made['users']) . ")");
    if ($made['contact']) $conn->exec("DELETE FROM contacts WHERE id = " . (int)$made['contact']);
    if ($made['supplier']) $conn->exec("DELETE FROM suppliers WHERE id = " . (int)$made['supplier']);
    if ($made['tenant']) $conn->exec("DELETE FROM tenants WHERE id = " . (int)$made['tenant']);
    $left = (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE name LIKE " . $conn->quote("ZZ $tag%"))->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM tickets WHERE subject LIKE " . $conn->quote("ZZ $tag%"))->fetchColumn();
    check('cleaned up', $left === 0);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
