<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The type a new ticket gets when nobody chose one (GH #230).
 *
 *   php tests/ticket-type-rules.php
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/ticket-type-rules.php   (parts 2 and 3 as well)
 *
 * PART 1 - no database: what a rule's criteria keep, and when they match.
 * PART 2 - one company: rules in order, first match, inactive and criteria-less
 *          rules skipped, the default, a chosen type winning, and the real
 *          TicketsService::createTicket() giving the type and noting why on the
 *          history; the Workflow "Set ticket type" action.
 * PART 3 - two companies, in a child process (the company count is cached per
 *          process): a company's own rules before the shared ones, its own
 *          default / none / the shared one, and a type it hid or another
 *          company's type never given.
 *
 * Everything it makes is named ZZ230 and removed at the end, pass or fail,
 * including the second company in part 3. Never run against a real install's
 * database: it creates tickets.
 *
 * @wiki Ticket-Types-For-New-Tickets
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/tenant_settings.php';
require_once __DIR__ . '/../includes/ticket_type_rules.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "  <- $detail" : '') . "\n"; }
}
function done(): never { global $pass, $fail; echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0); }

/** The database, only when it is the throwaway one the caller named. */
function testDb(): ?PDO
{
    $conn = connectToDatabase();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return getenv('FREEITSM_TEST_DATABASE') === $conn->query('SELECT DATABASE()')->fetchColumn() ? $conn : null;
}
function zzType(PDO $c, string $name, ?int $tenant = null): int
{
    $c->prepare("INSERT INTO ticket_types (name, is_active, display_order, tenant_id) VALUES (?, 1, 999, ?)")->execute([$name, $tenant]);
    return (int)$c->lastInsertId();
}
function zzRule(PDO $c, string $name, int $type, array $cond, int $order, ?int $tenant = null, int $active = 1): int
{
    $c->prepare("INSERT INTO ticket_type_rules (tenant_id, name, ticket_type_id, conditions_json, display_order, is_active) VALUES (?, ?, ?, ?, ?, ?)")
      ->execute([$tenant, $name, $type, json_encode(ticketTypeRuleConditions($cond)), $order, $active]);
    return (int)$c->lastInsertId();
}
function zzCleanup(PDO $c): void
{
    $c->exec("DELETE FROM ticket_type_rules WHERE name LIKE 'ZZ230%'");
    $ids = $c->query("SELECT id FROM tickets WHERE subject LIKE 'ZZ230%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach (['ticket_audit', 'emails', 'ticket_field_values'] as $t) { try { $c->exec("DELETE FROM $t WHERE ticket_id IN ($in)"); } catch (Throwable) {} }
        $c->exec("DELETE FROM tickets WHERE id IN ($in)");
    }
    $c->exec("DELETE FROM users WHERE email LIKE 'zz230%'");
    $t = $c->query("SELECT id FROM ticket_types WHERE name LIKE 'ZZ230%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($t) $c->exec("DELETE FROM tenant_config_hidden WHERE entity_type = 'ticket_type' AND entity_id IN (" . implode(',', array_map('intval', $t)) . ")");
    $c->exec("DELETE FROM ticket_types WHERE name LIKE 'ZZ230%'");
    $c->prepare("DELETE FROM tenant_settings WHERE setting_key = ?")->execute([TICKET_TYPE_DEFAULT_KEY]);
}

// ---------------------------------------------------------------------------
// Child: PART 3, run in its own process once the second company exists.
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === '--multi') {
    $c = testDb();
    $A = getDefaultTenantId($c); $B = (int)$argv[2];
    ok('control: this process sees two companies', isMultiTenant($c));
    // Part 2 left an install-wide default; this part sets its own.
    $c->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([TICKET_TYPE_DEFAULT_KEY]);
    $shared = zzType($c, 'ZZ230 shared');
    $hidden = zzType($c, 'ZZ230 hidden by B');
    $bOwn   = zzType($c, 'ZZ230 B only', $B);
    $c->prepare("INSERT INTO tenant_config_hidden (tenant_id, entity_type, entity_id) VALUES (?, 'ticket_type', ?)")->execute([$B, $hidden]);
    zzRule($c, 'ZZ230 shared email', $shared, ['channels' => ['email']], 10);
    zzRule($c, 'ZZ230 shared hidden', $hidden, ['channels' => ['portal']], 20);
    zzRule($c, 'ZZ230 B email', $bOwn, ['channels' => ['email']], 10, $B);
    $pick = fn(int $t, string $ch) => ticketTypeAutoPick($c, $t, ['channel' => $ch]);
    ok('B\'s own rule comes before the shared one', ($pick($B, 'email')['type_id'] ?? 0) === $bOwn);
    ok('...and A, with no rule of its own, gets the shared rule', ($pick($A, 'email')['type_id'] ?? 0) === $shared);
    ok('a shared rule naming a type B hid is passed over for B', $pick($B, 'portal') === null);
    ok('...while A still gets it', ($pick($A, 'portal')['type_id'] ?? 0) === $hidden);
    ok('B\'s own type is never given to A', !isset(ticketTypeUsable($c, $A)[$bOwn]));
    ok('a ticket with no company yet (triage) gets only shared types', !isset(ticketTypeUsable($c, null)[$bOwn]) && isset(ticketTypeUsable($c, null)[$shared]));

    $c->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([TICKET_TYPE_DEFAULT_KEY, (string)$shared]);
    tenantSettingForget();
    ok('a company with no default of its own follows the shared default', ($pick($B, 'phone')['type_id'] ?? 0) === $shared);
    setTenantSetting($c, $B, TICKET_TYPE_DEFAULT_KEY, (string)$bOwn); tenantSettingForget();
    ok('...its own default wins over the shared one', ($pick($B, 'phone')['type_id'] ?? 0) === $bOwn);
    setTenantSetting($c, $B, TICKET_TYPE_DEFAULT_KEY, '0'); tenantSettingForget();
    ok('...and "none" for it means none, though the install has a default', $pick($B, 'phone') === null && ($pick($A, 'phone')['type_id'] ?? 0) === $shared);
    $c->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([TICKET_TYPE_DEFAULT_KEY]);
    done();
}

// ---------------------------------------------------------------------------
echo "PART 1 - criteria\n";
$c0 = ticketTypeRuleConditions(['channels' => ['email', 'fax', 'portal'], 'mailboxes' => ['3', 3, 'x', -1], 'subject' => "  Password Reset \n\npassword reset\nVPN",
                                'domains' => "@Example.com\nsupplier.co.uk.\n", 'evil' => 'x']);
ok('unknown channels and keys are dropped', $c0['channels'] === ['email', 'portal'] && !isset($c0['evil']));
ok('ids are unique positive numbers', $c0['mailboxes'] === [3]);
ok('words are trimmed, lower case, once each', $c0['subject'] === ['password reset', 'vpn']);
ok('a domain typed with @ or a trailing dot is cleaned', $c0['domains'] === ['example.com', 'supplier.co.uk']);
ok('a rule with nothing filled in has no criteria', !ticketTypeRuleHasCriteria(ticketTypeRuleConditions([])) && ticketTypeRuleHasCriteria($c0));

$m = fn(array $c, array $f) => ticketTypeRuleMatches(ticketTypeRuleConditions($c), $f);
ok('control: arrived by email matches an email', $m(['channels' => ['email']], ['channel' => 'email']));
ok('...and not a portal request', !$m(['channels' => ['email']], ['channel' => 'portal']));
ok('every part filled in must match', !$m(['channels' => ['email'], 'mailboxes' => [5]], ['channel' => 'email', 'mailbox_id' => 6]) && $m(['channels' => ['email'], 'mailboxes' => [5]], ['channel' => 'email', 'mailbox_id' => 5]));
ok('several ticked in one part means any of them', $m(['mailboxes' => [5, 6]], ['mailbox_id' => 6]));
ok('an unknown fact does not match (no department yet)', !$m(['departments' => [2]], ['department_id' => null]));
ok('subject words match in any case, anywhere', $m(['subject' => "vpn"], ['subject' => 'Cannot connect to the VPN today']) && !$m(['subject' => 'vpn'], ['subject' => 'Printer jam']));
ok('a domain matches its subdomains, not look-alikes', $m(['domains' => 'example.com'], ['requester_email' => 'a@mail.example.com'])
   && !$m(['domains' => 'example.com'], ['requester_email' => 'a@badexample.com']) && !$m(['domains' => 'example.com'], ['requester_email' => '']));

echo "\nPART 2 - one company\n";
$conn = testDb();
if (!$conn) { echo "  SKIP  parts 2 and 3 - set FREEITSM_TEST_DATABASE to the throwaway database config.php points at\n"; done(); }
if (!ticketTypeRulesReady($conn)) { echo "  SKIP  parts 2 and 3 - run Database Verification on this database first\n"; done(); }
if (isMultiTenant($conn)) { echo "  SKIP  parts 2 and 3 - needs a single-company test database\n"; done(); }
$savedDefault = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = '" . TICKET_TYPE_DEFAULT_KEY . "'")->fetchColumn();
$B = null;
try {
    zzCleanup($conn);
    $T = getDefaultTenantId($conn);
    $inc = zzType($conn, 'ZZ230 Incident'); $req = zzType($conn, 'ZZ230 Request'); $off = zzType($conn, 'ZZ230 Retired');
    $conn->exec("UPDATE ticket_types SET is_active = 0 WHERE id = $off");
    zzRule($conn, 'ZZ230 off', $req, ['channels' => ['email']], 5, null, 0);
    zzRule($conn, 'ZZ230 retired type', $off, ['channels' => ['email']], 6);
    zzRule($conn, 'ZZ230 requests', $req, ['channels' => ['email'], 'subject' => 'request'], 10);
    zzRule($conn, 'ZZ230 email', $inc, ['channels' => ['email']], 20);
    $p = fn(array $f) => ticketTypeAutoPick($conn, $T, $f);
    ok('the first matching rule decides', ($p(['channel' => 'email', 'subject' => 'New laptop request'])['rule_name'] ?? '') === 'ZZ230 requests');
    ok('...the next one when it does not match', ($p(['channel' => 'email', 'subject' => 'Broken'])['type_id'] ?? 0) === $inc);
    ok('a rule that is off, or names a retired type, is passed over', ($p(['channel' => 'email', 'subject' => 'x'])['rule_name'] ?? '') === 'ZZ230 email');
    ok('control: no rule and no default gives no type', $p(['channel' => 'portal']) === null);
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([TICKET_TYPE_DEFAULT_KEY, (string)$req]);
    tenantSettingForget();
    $d = $p(['channel' => 'portal']);
    ok('with a default, an unmatched ticket gets it', ($d['type_id'] ?? 0) === $req && $d['rule_id'] === null);

    require_once __DIR__ . '/../includes/service_context.php';
    require_once __DIR__ . '/../includes/services/tickets.php';
    $ctx = new ActorContext(0, null, 'api', 'en', 'ZZ230');
    $row = function (int $id) use ($conn) { $s = $conn->prepare("SELECT ticket_type_id FROM tickets WHERE id = ?"); $s->execute([$id]); return (int)$s->fetchColumn(); };
    $audit = function (int $id) use ($conn) { $s = $conn->prepare("SELECT new_value FROM ticket_audit WHERE ticket_id = ? AND field_name = 'Ticket Type'"); $s->execute([$id]); return (string)$s->fetchColumn(); };
    $t1 = TicketsService::createTicket($conn, $ctx, $T, ['subject' => 'ZZ230 a request for a monitor', 'requester_email' => 'zz230@example.com'], null, 'ZZ230', 'email');
    ok('a ticket created through the service gets the rule\'s type', $row($t1) === $req);
    ok('...and its history says which rule', str_contains($audit($t1), 'ZZ230 requests'), $audit($t1));
    $t2 = TicketsService::createTicket($conn, $ctx, $T, ['subject' => 'ZZ230 request', 'requester_email' => 'zz230@example.com', 'ticket_type_id' => $inc], null, 'ZZ230', 'email');
    ok('a type the caller chose always wins, and no rule is noted', $row($t2) === $inc && $audit($t2) === '');
    $t3 = TicketsService::createTicket($conn, $ctx, $T, ['subject' => 'ZZ230 request', 'requester_email' => 'zz230@example.com'], null, 'ZZ230');
    ok('an API ticket is "REST API", not email, unless the caller says so', $row($t3) === $req && str_contains($audit($t3), 'default'), $audit($t3));

    require_once __DIR__ . '/../workflow/includes/engine.php';
    $r = WorkflowEngine::runActionList('ZZ230', 'zz.test', [['type' => 'set_ticket_type', 'args' => ['ticket_id' => (string)$t1, 'ticket_type_id' => (string)$inc]]], ['ticket' => ['id' => $t1]]);
    ok('Workflow "Set ticket type" changes the type', $r['status'] === 'success' && $row($t1) === $inc, json_encode($r['steps']));
    $r = WorkflowEngine::runActionList('ZZ230', 'zz.test', [['type' => 'set_ticket_type', 'args' => ['ticket_id' => (string)$t1, 'ticket_type_id' => (string)$off]]], ['ticket' => ['id' => $t1]]);
    ok('...and refuses a type the company cannot use', $r['status'] !== 'success' && $row($t1) === $inc);

    echo "\nPART 3 - two companies\n";
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES ('ZZ230 Company B', 'zz230-b', 0, 1)")->execute();
    $B = (int)$conn->lastInsertId();
    $env = 'FREEITSM_TEST_DATABASE=' . getenv('FREEITSM_TEST_DATABASE');
    $out = []; $code = 0;
    putenv($env);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --multi ' . $B . ' 2>&1', $out, $code);
    foreach ($out as $l) {
        if (preg_match('/^  (PASS|FAIL)  (.*)$/', $l, $mm)) ok($mm[2], $mm[1] === 'PASS');
        elseif (trim($l) !== '' && !preg_match('/^\d+ passed/', $l)) echo "  ..    $l\n";
    }
} finally {
    zzCleanup($conn);
    $conn->exec("DELETE FROM workflow_executions WHERE workflow_name = 'ZZ230'");
    if ($B) { $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$B]); }
    if ($savedDefault === false) $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([TICKET_TYPE_DEFAULT_KEY]);
    else $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?")->execute([$savedDefault, TICKET_TYPE_DEFAULT_KEY]);
}
done();
