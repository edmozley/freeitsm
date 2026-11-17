<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Forms lookups: a Companies source, and People found by every field (GH #367).
 *
 *   php tests/forms-lookup-sources.php      (run from a TEST install, never a real one)
 *
 * A People lookup searched only name and email, so "4711" (an employee ID) found
 * nobody, and there was no way to ask "which company?". FormsService now searches
 * every person column plus their custom fields, and has a 'company' source over the
 * companies themselves - scoped to the companies the person can reach, active ones
 * only, never on the portal.
 *
 * Calls FormsService directly with an explicit company scope (the endpoint's only
 * job is to work that scope out, and tests/forms-lookup*.php elsewhere cover it).
 * Writes, and removes in a finally block: two companies (one inactive), one person,
 * one person custom field and one company custom field with a value each.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/services/forms.php';

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

echo "\nForms lookups: Companies, and People by every field (GH #367)\n" . str_repeat('=', 70) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
echo "  database: $db\n";
if (!preg_match('/test/i', $db)) { echo "  STOP  this test adds companies and a person; run it only on a test install\n"; exit(1); }

$tag = 'zzlk' . bin2hex(random_bytes(3));
$tenants = []; $userId = 0; $pField = 0; $cField = 0;
$ids = fn(array $rows) => array_map('intval', array_column($rows, 'id'));
try {
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active, external_ref, tax_id) VALUES (?, ?, 0, 1, ?, ?)")
         ->execute(["ZZ Lookup Co $tag", "zz-lookup-$tag", "EXT-$tag", "TAX-$tag"]);
    $active = $tenants[] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 0)")
         ->execute(["ZZ Lookup Gone $tag", "zz-lookup-gone-$tag"]);
    $inactive = $tenants[] = (int)$conn->lastInsertId();
    $default = getDefaultTenantId($conn);

    $conn->prepare("INSERT INTO users (email, display_name, tenant_id, employee_id, job_title, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())")
         ->execute(["$tag@example.invalid", "ZZ Lookup Person", $active, "EMP-$tag", "Chief $tag Officer"]);
    $userId = (int)$conn->lastInsertId();

    $conn->prepare("INSERT INTO person_fields (field_key, label, field_type) VALUES (?, 'ZZ badge', 'text')")->execute(["zz_badge_$tag"]);
    $pField = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO person_field_values (user_id, field_id, value_text) VALUES (?, ?, ?)")->execute([$userId, $pField, "BADGE-$tag"]);
    $conn->prepare("INSERT INTO company_fields (field_key, label, field_type) VALUES (?, 'ZZ account', 'text')")->execute(["zz_acct_$tag"]);
    $cField = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO company_field_values (tenant_id, field_id, value_text) VALUES (?, ?, ?)")->execute([$active, $cField, "ACCT-$tag"]);

    $both = [$default, $active, $inactive];

    echo "\nPeople\n";
    ok('found by employee ID', in_array($userId, $ids(FormsService::lookupSearch($conn, 'user', "EMP-$tag", $both)), true));
    ok('found by job title', in_array($userId, $ids(FormsService::lookupSearch($conn, 'user', "Chief $tag", $both)), true));
    ok('found by a custom field value', in_array($userId, $ids(FormsService::lookupSearch($conn, 'user', "BADGE-$tag", $both)), true));
    ok('still found by name, and labelled by name', ($r = FormsService::lookupSearch($conn, 'user', 'ZZ Lookup Person', $both)) && in_array($userId, $ids($r), true)
        && in_array('ZZ Lookup Person', array_column($r, 'label'), true));
    ok('not found from a company outside the scope', !in_array($userId, $ids(FormsService::lookupSearch($conn, 'user', "EMP-$tag", [$default])), true));
    $conn->prepare("UPDATE person_fields SET is_deleted = 1 WHERE id = ?")->execute([$pField]);
    ok('a deleted custom field is not searched', !in_array($userId, $ids(FormsService::lookupSearch($conn, 'user', "BADGE-$tag", $both)), true));

    echo "\nCompanies\n";
    ok("'company' is a lookup source", isset(FormsService::LOOKUP_SOURCES['company']));
    ok('found by name', in_array($active, $ids(FormsService::lookupSearch($conn, 'company', "Lookup Co $tag", $both)), true));
    ok('found by tax ID and by external reference', in_array($active, $ids(FormsService::lookupSearch($conn, 'company', "TAX-$tag", $both)), true)
        && in_array($active, $ids(FormsService::lookupSearch($conn, 'company', "EXT-$tag", $both)), true));
    ok('found by a company custom field value', in_array($active, $ids(FormsService::lookupSearch($conn, 'company', "ACCT-$tag", $both)), true));
    ok('a company outside the scope is not offered', !in_array($active, $ids(FormsService::lookupSearch($conn, 'company', $tag, [$default])), true));
    ok('an inactive company is not offered', !in_array($inactive, $ids(FormsService::lookupSearch($conn, 'company', $tag, $both)), true));
    ok('the submit accepts a company in scope', FormsService::lookupValueAllowed($conn, 'company', $active, $both));
    ok('...and refuses one outside it', !FormsService::lookupValueAllowed($conn, 'company', $active, [$default]));
    ok('...and refuses an inactive one', !FormsService::lookupValueAllowed($conn, 'company', $inactive, $both));
    ok('never offered on the portal', !FormsService::lookupPortalAllowed(['config' => ['lookup_source' => 'company', 'portal_lookup' => true]]));
} finally {
    if ($pField) { $conn->prepare("DELETE FROM person_field_values WHERE field_id = ?")->execute([$pField]); $conn->prepare("DELETE FROM person_fields WHERE id = ?")->execute([$pField]); }
    if ($cField) { $conn->prepare("DELETE FROM company_field_values WHERE field_id = ?")->execute([$cField]); $conn->prepare("DELETE FROM company_fields WHERE id = ?")->execute([$cField]); }
    if ($userId) $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    foreach ($tenants as $t) $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$t]);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM tenants WHERE slug LIKE 'zz-lookup-%$tag') + (SELECT COUNT(*) FROM users WHERE email = '$tag@example.invalid')
                                    + (SELECT COUNT(*) FROM person_fields WHERE field_key = 'zz_badge_$tag') + (SELECT COUNT(*) FROM company_fields WHERE field_key = 'zz_acct_$tag')")->fetchColumn();
    echo "\n";
    ok('everything the test wrote is gone', $left === 0, "$left rows left");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
