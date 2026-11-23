<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Custom fields on companies and people (GH #279).
 *
 *   php tests/record-fields.php      (run from a TEST install, never a real one)
 *
 * Writes throwaway rows and removes them by the ids it got back: ZZ company
 * and people fields (hard-deleted at the end with their options, values and
 * history), a "ZZ" second company and two ZZ companies made through
 * CompaniesService, and two ZZ people. Optionally (FREEITSM_TEST_URL) saves a
 * person through the real api/tickets/save_user.php with a forged admin session.
 *
 * Covers: designing fields (keys, dropdown options, the kind locked once
 * answered, soft delete); a company's fields through CompaniesService (unknown
 * key, bad email, required on create, the history row); people fields shared
 * and per company (another company's field is not a person's; moving company
 * hides, moving back shows); the REST map; the real save endpoint.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/record_fields.php';
require_once __DIR__ . '/../includes/services/companies.php';

$pass = 0; $fail = 0;
/** Records and prints one PASS or FAIL line. */
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}
/** The ServiceError message a call throws, or '' when it does not throw. */
function refusal(callable $f): string { try { $f(); return ''; } catch (ServiceError $e) { return $e->getMessage() ?: 'refused'; } }

echo "\nCustom fields on companies and people (GH #279)\n" . str_repeat('=', 70) . "\n";
$conn = connectToDatabase();
$db = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
echo "  database: $db\n";
if (!preg_match('/test/i', $db)) { echo "  STOP  this test adds a company; run it only on a test install\n"; exit(1); }
if (!RecordFieldsService::schemaReady($conn, 'company') || !RecordFieldsService::schemaReady($conn, 'person')) { echo "  STOP  run Database Verification first\n"; exit(1); }
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx = ActorContext::fromApiKey(['analyst_id' => $admin, 'company_scope' => null, 'analyst_name' => 'test']);

$fieldIds = ['company' => [], 'person' => []]; $tenants = []; $users = [];
try {
    $conn->exec("INSERT INTO tenants (name) VALUES ('ZZ record fields other')");
    $tenants[] = $other = (int)$conn->lastInsertId();
    $def = getDefaultTenantId($conn);

    echo "\nDesigning fields\n";
    $mk = function (string $entity, array $in) use ($conn, $ctx, &$fieldIds) {
        $r = RecordFieldsService::saveField($conn, $ctx, $entity, $in);
        $fieldIds[$entity][] = $r['id'];
        return $r;
    };
    $tier  = $mk('company', ['label' => 'ZZ Support tier', 'field_type' => 'dropdown', 'options' => ['Gold', 'Silver']]);
    $mail  = $mk('company', ['label' => 'ZZ Billing email', 'field_type' => 'email']);
    ok('a key is made from the name', $tier['key'] === 'zz_support_tier', $tier['key']);
    ok('a dropdown needs its choices', refusal(fn() => RecordFieldsService::saveField($conn, $ctx, 'company', ['label' => 'ZZ Empty', 'field_type' => 'dropdown'])) !== '');
    $badge = $mk('person', ['label' => 'ZZ Badge', 'field_type' => 'text', 'tenant_id' => $other]);
    $start = $mk('person', ['label' => 'ZZ Start date', 'field_type' => 'date']);
    $defsCo = RecordFieldsService::fieldsFor($conn, 'company');
    ok('a company has every company field', isset($defsCo[$tier['key']], $defsCo[$mail['key']]));

    echo "\nA company's fields\n";
    $a = CompaniesService::save($conn, ['name' => 'ZZ Fields Alpha', 'custom_fields' => [$tier['key'] => 'Gold', $mail['key'] => 'billing@example.invalid']], null, $ctx);
    $tenants[] = $a['id'];
    $vals = RecordFieldsService::values($conn, 'company', $a['id'], $defsCo);
    ok('they are saved with the company', ($vals[$tier['key']] ?? null) === 'Gold' && ($vals[$mail['key']] ?? null) === 'billing@example.invalid', json_encode($vals));
    ok('a choice that is not offered is refused', refusal(fn() => CompaniesService::save($conn, ['custom_fields' => [$tier['key'] => 'Bronze']], $a['id'], $ctx)) !== '');
    ok('an email field takes only an email address', refusal(fn() => CompaniesService::save($conn, ['custom_fields' => [$mail['key'] => 'not an address']], $a['id'], $ctx)) !== '');
    ok('an unknown key is refused', refusal(fn() => CompaniesService::save($conn, ['custom_fields' => ['zz_no_such' => 'x']], $a['id'], $ctx)) !== '');
    CompaniesService::save($conn, ['custom_fields' => [$tier['key'] => 'Silver']], $a['id'], $ctx);
    $h = $conn->prepare("SELECT old_value, new_value FROM company_audit WHERE tenant_id = ? AND field_name = 'ZZ Support tier' ORDER BY id DESC LIMIT 1");
    $h->execute([$a['id']]); $row = $h->fetch(PDO::FETCH_ASSOC);
    ok('the change is in the company history', $row && $row['old_value'] === 'Gold' && $row['new_value'] === 'Silver', json_encode($row));
    ok('the REST map has every field', (array)RecordFieldsService::apiMap($conn, 'company', $a['id']) == [$tier['key'] => 'Silver', $mail['key'] => 'billing@example.invalid']);
    // Required on create.
    RecordFieldsService::saveField($conn, $ctx, 'company', ['id' => $mail['id'], 'label' => 'ZZ Billing email', 'field_type' => 'email', 'is_required' => 1]);
    ok('a required field must be given when a company is made', refusal(function () use ($conn, $ctx, &$tenants) { $r = CompaniesService::save($conn, ['name' => 'ZZ Fields Beta'], null, $ctx); $tenants[] = $r['id']; }) !== '');
    ok('a required field cannot be cleared', refusal(fn() => CompaniesService::save($conn, ['custom_fields' => [$mail['key'] => '']], $a['id'], $ctx)) !== '');
    RecordFieldsService::saveField($conn, $ctx, 'company', ['id' => $mail['id'], 'label' => 'ZZ Billing email', 'field_type' => 'email', 'is_required' => 0]);
    ok("a field's kind is locked once answered", refusal(fn() => RecordFieldsService::saveField($conn, $ctx, 'company', ['id' => $tier['id'], 'label' => 'ZZ Support tier', 'field_type' => 'text'])) !== '');

    echo "\nPeople fields\n";
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id, is_active, created_at) VALUES ('zz-rf-1@example.invalid', 'ZZ RF One', ?, 1, UTC_TIMESTAMP())")->execute([$other]);
    $users[] = $ann = (int)$conn->lastInsertId();
    $defsOther = RecordFieldsService::fieldsFor($conn, 'person', $other);
    $defsDef = RecordFieldsService::fieldsFor($conn, 'person', $def);
    ok("the company's own field is its people's", isset($defsOther[$badge['key']], $defsOther[$start['key']]));
    ok("another company's people do not have it", !isset($defsDef[$badge['key']]) && isset($defsDef[$start['key']]));
    ok("another company's field is refused for them", refusal(fn() => RecordFieldsService::validate($conn, 'person', $defsDef, [$badge['key'] => 'B1'], false)) !== '');
    $clean = RecordFieldsService::validate($conn, 'person', $defsOther, [$badge['key'] => 'B-42', $start['key'] => '2026-11-16'], false);
    RecordFieldsService::write($conn, $ctx, 'person', $ann, $defsOther, $clean);
    $v = RecordFieldsService::values($conn, 'person', $ann, $defsOther);
    ok('a person keeps their answers', ($v[$badge['key']] ?? null) === 'B-42' && str_starts_with((string)($v[$start['key']] ?? ''), '2026-11-16'), json_encode($v));
    $au = $conn->prepare("SELECT COUNT(*) FROM user_audit WHERE user_id = ? AND field_name IN ('ZZ Badge', 'ZZ Start date')");
    $au->execute([$ann]);
    ok('the changes are in the person history', (int)$au->fetchColumn() === 2);
    $conn->prepare("UPDATE users SET tenant_id = ? WHERE id = ?")->execute([$def, $ann]);
    $m = (array)RecordFieldsService::apiMap($conn, 'person', $ann, $def);
    ok("moving company hides the old company's field", !array_key_exists($badge['key'], $m) && array_key_exists($start['key'], $m), json_encode($m));
    $conn->prepare("UPDATE users SET tenant_id = ? WHERE id = ?")->execute([$other, $ann]);
    $m = (array)RecordFieldsService::apiMap($conn, 'person', $ann, $other);
    ok('moving back brings the answer back', ($m[$badge['key']] ?? null) === 'B-42');
    RecordFieldsService::deleteField($conn, $ctx, 'person', $badge['id']);
    ok('a deleted field is no longer shown', !isset(RecordFieldsService::fieldsFor($conn, 'person', $other)[$badge['key']]));

    echo "\nThe real person save\n";
    $base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
    if ($base === '') {
        echo "  SKIP  set FREEITSM_TEST_URL\n";
    } else {
        $sessDir = rtrim((string)(getenv('FREEITSM_SESS_DIR') ?: 'C:/wamp64/tmp'), '/');
        $sid = 'zzrf' . bin2hex(random_bytes(4));
        file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_username|s:5:"admin";analyst_name|s:5:"Admin";csrf_token|s:1:"x";');
        $call = function (array $body) use ($base, $sid) {
            $ch = curl_init("$base/api/tickets/save_user.php");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: x', 'Cookie: PHPSESSID=' . $sid, 'Sec-Fetch-Site: same-origin']]);
            $raw = (string)curl_exec($ch);
            return json_decode(trim($raw), true) ?: ['raw' => substr($raw, 0, 200)];
        };
        try {
            $d = $call(['display_name' => 'ZZ RF Two', 'email' => 'zz-rf-2@example.invalid', 'tenant_id' => $def, 'custom_fields' => [$start['key'] => '2026-12-01']]);
            if (!empty($d['id'])) $users[] = (int)$d['id'];
            $v = !empty($d['id']) ? RecordFieldsService::values($conn, 'person', (int)$d['id'], RecordFieldsService::fieldsFor($conn, 'person', $def)) : [];
            ok('a new person is saved with their fields', !empty($d['success']) && str_starts_with((string)($v[$start['key']] ?? ''), '2026-12-01'), json_encode($d));
            $d2 = $call(['id' => $d['id'] ?? 0, 'custom_fields' => ['zz_no_such' => 'x']]);
            ok('the save refuses an unknown field', empty($d2['success']), json_encode($d2));
        } finally {
            @unlink("$sessDir/sess_$sid");
        }
    }
} finally {
    if ($users) $conn->exec("DELETE FROM users WHERE id IN (" . implode(',', array_map('intval', $users)) . ")");
    foreach (array_reverse($tenants) as $t) { $conn->prepare("DELETE FROM company_audit WHERE tenant_id = ?")->execute([$t]); $conn->prepare("DELETE FROM company_field_values WHERE tenant_id = ?")->execute([$t]); $conn->prepare("DELETE FROM tenants WHERE id = ?")->execute([$t]); }
    foreach (['company' => ['company_field_values', 'company_field_options', 'company_fields'], 'person' => ['person_field_values', 'person_field_options', 'person_fields']] as $ent => [$vt, $ot, $dt]) {
        foreach ($fieldIds[$ent] as $fid) {
            $conn->prepare("DELETE FROM $vt WHERE field_id = ?")->execute([$fid]);
            $conn->prepare("DELETE FROM $ot WHERE field_id = ?")->execute([$fid]);
            $conn->prepare("DELETE FROM $dt WHERE id = ?")->execute([$fid]);
        }
    }
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM tenants WHERE name LIKE 'ZZ record fields%' OR name LIKE 'ZZ Fields %')
        + (SELECT COUNT(*) FROM users WHERE email LIKE 'zz-rf-%@example.invalid')
        + (SELECT COUNT(*) FROM company_fields WHERE label LIKE 'ZZ %') + (SELECT COUNT(*) FROM person_fields WHERE label LIKE 'ZZ %')")->fetchColumn();
    echo "\n";
    ok('everything the test wrote is gone', $left === 0, "$left rows left");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
