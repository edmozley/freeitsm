<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * REST API: companies (GH #279) and a requester's employee_id - through real HTTP.
 *
 *   FREEITSM_TEST_DATABASE=<db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/api-companies.php
 *
 * BOTH are required (FREEITSM_BASE_URL is read when FREEITSM_TEST_URL is not set): the
 * database this writes to, and the install it calls - which must be the same one.
 * Without a URL it refuses to run rather than call another install with keys that
 * only exist here.
 *
 * Proves: POST /companies creates (201); a taken external_ref or tax_id is 409; the
 * upsert creates and then updates the same company (201 / 200, created true / false);
 * PATCH changes only what is sent; GET filters by external_ref, tax_id, q and active;
 * a company-limited key cannot create or upsert (403) and cannot see or change a
 * company outside its list (404, the same as an unknown id); a key that does not act
 * as an administrator cannot write (403); the Default company cannot be made inactive
 * (422); employee_id round-trips on /users and filters. Every refusal has a positive
 * control. Its companies (ZZ-named), keys and requesters are removed at the end.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/companies.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "  <- $detail" : '') . "\n"; }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$base = rtrim((string)(getenv('FREEITSM_TEST_URL') ?: getenv('FREEITSM_BASE_URL')), '/');
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName || $base === '') {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE (this database is $dbName) and FREEITSM_TEST_URL to the install that uses it.\n";
    exit(0);
}
if (!CompaniesService::refsReady($conn)) { echo "SKIPPED: run Database Verification first (tenants.external_ref / tax_id).\n"; exit(0); }
$empReady = true;
try { $conn->query("SELECT employee_id FROM users LIMIT 0"); } catch (Throwable $e) { $empReady = false; }

// An administrator who reaches every company, and an analyst who is not an administrator.
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 AND can_access_all_tenants = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$admin) { echo "SKIPPED: no active administrator with access to every company.\n"; exit(0); }
$plain = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 0 AND can_access_all_tenants = 1 ORDER BY id LIMIT 1")->fetchColumn();
$defaultId = (int)$conn->query("SELECT id FROM tenants WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn();

$tag = 'zz279' . bin2hex(random_bytes(3));
$keyIds = [];
$newKey = function (string $name, int $analyst, array $perms, ?array $companies) use ($conn, &$keyIds): string {
    $raw = 'zzk_' . bin2hex(random_bytes(20));
    $conn->prepare("INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, active) VALUES (?, ?, ?, ?, ?, ?, 1)")
         ->execute(["zz $name", substr($raw, 0, 12), hash('sha256', $raw), $analyst, json_encode($perms), $companies === null ? null : json_encode($companies)]);
    $keyIds[] = (int)$conn->lastInsertId();
    return $raw;
};
$call = function (string $key, string $method, string $path, ?array $body = null) use ($base): array {
    $ch = curl_init("$base/api/v1$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(["Authorization: Bearer $key"], $body !== null ? ['Content-Type: application/json'] : [])]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$out, true) ?: ['raw' => $out]];
};
$ids = function (array $r): array { return array_map(function ($c) { return (int)$c['id']; }, $r['data'] ?? []); };

$allPerms = ['companies' => ['read', 'create', 'update'], 'users' => ['read', 'create', 'update']];
$userIds = [];
try {
    $full = $newKey('companies admin', $admin, $allPerms, null);

    echo "Create\n";
    [$c, $r] = $call($full, 'POST', '/companies', ['name' => "ZZ $tag Alpha", 'external_ref' => "$tag-A", 'tax_id' => "$tag-TA", 'ticket_code' => '']);
    $A = $r['data'] ?? [];
    ok('an administrator key for all companies creates one (201)', $c === 201 && ($A['name'] ?? '') === "ZZ $tag Alpha"
        && ($A['external_ref'] ?? '') === "$tag-A" && ($A['tax_id'] ?? '') === "$tag-TA" && ($A['is_active'] ?? null) === true
        && ($A['is_default'] ?? null) === false && array_key_exists('ticket_code', $A) && $A['ticket_code'] === null, "$c " . json_encode($r));
    $aId = (int)($A['id'] ?? 0);
    [$c, $r] = $call($full, 'POST', '/companies', ['name' => "ZZ $tag Beta", 'external_ref' => "$tag-B"]);
    $bId = (int)($r['data']['id'] ?? 0);
    ok('(control) a second company with its own refs is created', $c === 201 && $bId > 0, "$c " . json_encode($r));
    [$c, $r] = $call($full, 'POST', '/companies', ['name' => "ZZ $tag Dup", 'external_ref' => strtoupper("$tag-a")]);
    ok('a taken external_ref (any case) is refused (409)', $c === 409 && ($r['error']['code'] ?? '') === 'duplicate', "$c " . json_encode($r));
    [$c, $r] = $call($full, 'POST', '/companies', ['name' => "ZZ $tag Dup", 'tax_id' => " $tag-TA "]);
    ok('a taken tax_id (spaces round it) is refused (409)', $c === 409, "$c " . json_encode($r));
    [$c, $r] = $call($full, 'POST', '/companies', ['external_ref' => "$tag-noname"]);
    ok('no name is refused (422)', $c === 422 && ($r['error']['code'] ?? '') === 'missing_field', "$c " . json_encode($r));
    [$c, $r] = $call($full, 'POST', '/companies', ['name' => ['not', 'text']]);
    ok('a name that is not text is refused (422)', $c === 422, "$c " . json_encode($r));
    ok('none of the refused ones was made', (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE name LIKE " . $conn->quote("ZZ $tag%"))->fetchColumn() === 2);

    echo "Upsert\n";
    [$c, $r] = $call($full, 'POST', '/companies/upsert', ['external_ref' => "$tag-U", 'name' => "ZZ $tag Upsert", 'tax_id' => "$tag-TU"]);
    $uId = (int)($r['data']['id'] ?? 0);
    ok('an unknown external_ref creates (201, created true)', $c === 201 && ($r['data']['created'] ?? null) === true && $uId > 0, "$c " . json_encode($r));
    [$c, $r] = $call($full, 'POST', '/companies/upsert', ['external_ref' => strtolower("$tag-u"), 'name' => "ZZ $tag Upsert Renamed"]);
    ok('the same external_ref again updates that company (200, created false, same id)', $c === 200 && ($r['data']['created'] ?? null) === false
        && (int)($r['data']['id'] ?? 0) === $uId && ($r['data']['name'] ?? '') === "ZZ $tag Upsert Renamed", "$c " . json_encode($r));
    ok('...and leaves what was not sent (tax_id) alone', ($r['data']['tax_id'] ?? '') === "$tag-TU", json_encode($r));
    [$c, $r] = $call($full, 'POST', '/companies/upsert', ['external_ref' => "$tag-U", 'tax_id' => "$tag-TA"]);
    ok('an upsert to a tax_id another company has is refused (409)', $c === 409, "$c " . json_encode($r));
    [$c, $r] = $call($full, 'POST', '/companies/upsert', ['name' => "ZZ $tag NoRef"]);
    ok('an upsert without external_ref is refused (422)', $c === 422 && ($r['error']['code'] ?? '') === 'missing_field', "$c " . json_encode($r));
    [$c, $r] = $call($full, 'POST', '/companies/upsert', ['external_ref' => "$tag-new-noname"]);
    ok('an upsert that would create without a name is refused (422)', $c === 422, "$c " . json_encode($r));
    $onlyCreate = $newKey('create only', $admin, ['companies' => ['read', 'create']], null);
    [$c, $r] = $call($onlyCreate, 'POST', '/companies/upsert', ['external_ref' => "$tag-U", 'name' => 'x']);
    ok('an upsert also needs companies.update (403)', $c === 403 && str_contains((string)($r['error']['message'] ?? ''), 'companies.update'), "$c " . json_encode($r));

    echo "PATCH\n";
    [$c, $r] = $call($full, 'PATCH', "/companies/$aId", ['tax_id' => "$tag-TA2"]);
    ok('PATCH changes only what is sent', $c === 200 && ($r['data']['tax_id'] ?? '') === "$tag-TA2" && ($r['data']['name'] ?? '') === "ZZ $tag Alpha"
        && ($r['data']['external_ref'] ?? '') === "$tag-A", "$c " . json_encode($r));
    [$c, $r] = $call($full, 'PATCH', "/companies/$aId", ['external_ref' => "$tag-B"]);
    ok('PATCH to an external_ref another company has is refused (409)', $c === 409, "$c " . json_encode($r));
    [$c, $r] = $call($full, 'PATCH', "/companies/$aId", []);
    ok('PATCH with nothing to change is refused (422)', $c === 422, "$c " . json_encode($r));
    [$c, $r] = $call($full, 'PATCH', "/companies/$defaultId", ['is_active' => false]);
    ok('the Default company cannot be made inactive (422)', $c === 422 && str_contains((string)($r['error']['message'] ?? ''), 'default'), "$c " . json_encode($r));
    ok('...and is still active', (int)$conn->query("SELECT is_active FROM tenants WHERE id = $defaultId")->fetchColumn() === 1);
    [$c, $r] = $call($full, 'PATCH', "/companies/$bId", ['is_active' => false]);
    ok('(control) another company can be made inactive', $c === 200 && ($r['data']['is_active'] ?? null) === false, "$c " . json_encode($r));
    [$c, $r] = $call($full, 'PATCH', "/companies/$bId", ['external_ref' => '']);
    ok('"" clears external_ref', $c === 200 && array_key_exists('external_ref', $r['data'] ?? []) && $r['data']['external_ref'] === null, "$c " . json_encode($r));
    [$c, $r] = $call($full, 'PATCH', "/companies/$bId", ['external_ref' => "$tag-B"]);
    ok('(control) and it can be set again', $c === 200 && ($r['data']['external_ref'] ?? '') === "$tag-B", "$c " . json_encode($r));

    echo "GET and filters\n";
    [$c, $r] = $call($full, 'GET', "/companies/$aId");
    ok('GET /companies/{id}', $c === 200 && ($r['data']['tax_id'] ?? '') === "$tag-TA2", "$c " . json_encode($r));
    [$c, $r] = $call($full, 'GET', '/companies/999999999');
    ok('an unknown id is 404', $c === 404, "$c");
    [$c, $r] = $call($full, 'GET', '/companies?external_ref=' . urlencode(strtoupper("$tag-a")));
    ok('?external_ref= finds exactly that company, ignoring case', $c === 200 && $ids($r) === [$aId], "$c " . json_encode($r));
    [$c, $r] = $call($full, 'GET', '/companies?external_ref=' . urlencode("$tag-B"));
    ok('?external_ref= finds an inactive company too', $c === 200 && $ids($r) === [$bId], "$c " . json_encode($r));
    [$c, $r] = $call($full, 'GET', '/companies?tax_id=' . urlencode("$tag-TU"));
    ok('?tax_id= finds exactly that company', $c === 200 && $ids($r) === [$uId], "$c " . json_encode($r));
    [$c, $r] = $call($full, 'GET', '/companies?tax_id=' . urlencode("$tag-T"));
    ok('?tax_id= is exact, not contains', $c === 200 && $ids($r) === [], "$c " . json_encode($r));
    [$c, $r] = $call($full, 'GET', '/companies?q=' . urlencode($tag));
    $got = $ids($r); sort($got);
    $want = [$aId, $uId]; sort($want);
    ok('?q= matches name / refs, active only by default', $c === 200 && $got === $want, "$c " . json_encode($r));
    [$c, $r] = $call($full, 'GET', '/companies?active=all&q=' . urlencode($tag));
    ok('?active=all adds the inactive one', $c === 200 && count($ids($r)) === 3, "$c " . json_encode($r));
    [$c, $r] = $call($full, 'GET', '/companies?active=false&q=' . urlencode($tag));
    ok('?active=false gives only the inactive one', $c === 200 && $ids($r) === [$bId], "$c " . json_encode($r));
    [$c, $r] = $call($full, 'GET', '/companies?q=' . urlencode("$tag-TU"));
    ok('?q= also searches tax_id', $c === 200 && $ids($r) === [$uId], "$c " . json_encode($r));
    [$c, $r] = $call($full, 'GET', '/companies?active=maybe');
    ok('a bad ?active= is 400', $c === 400, "$c");
    [$c, $r] = $call($full, 'GET', '/companies');
    $first = $r['data'][0] ?? [];
    ok('the list still has the Default company first, with the new fields', $c === 200 && (int)($first['id'] ?? 0) === $defaultId
        && ($first['is_default'] ?? null) === true && array_key_exists('external_ref', $first) && array_key_exists('tax_id', $first), "$c " . json_encode($first));

    echo "A key limited to one company\n";
    $limited = $newKey('limited', $admin, $allPerms, [$aId]);
    [$c, $r] = $call($limited, 'GET', '/companies?active=all');
    ok('it lists only its own company', $c === 200 && $ids($r) === [$aId], "$c " . json_encode($r));
    [$c, $r] = $call($limited, 'GET', "/companies/$aId");
    ok('(control) it can GET its own company', $c === 200, "$c");
    [$c, $r] = $call($limited, 'GET', "/companies/$uId");
    ok('it cannot GET another company (404, as unknown)', $c === 404 && ($r['error']['message'] ?? '') === 'Company not found.', "$c " . json_encode($r));
    $limited2 = $newKey('limited 2', $admin, $allPerms, [$aId]);
    [$c, $r] = $call($limited2, 'PATCH', "/companies/$uId", ['name' => 'nope']);
    ok('it cannot PATCH another company (404)', $c === 404, "$c " . json_encode($r));
    [$c, $r] = $call($limited2, 'PATCH', "/companies/$aId", ['name' => 'nope']);
    ok('it cannot PATCH its own company either: writes need all companies (403)', $c === 403 && str_contains((string)($r['error']['message'] ?? ''), 'all companies'), "$c " . json_encode($r));
    $limited3 = $newKey('limited 3', $admin, $allPerms, [$aId]);
    [$c, $r] = $call($limited3, 'POST', '/companies', ['name' => "ZZ $tag Limited"]);
    ok('it cannot create (403)', $c === 403, "$c " . json_encode($r));
    [$c, $r] = $call($limited3, 'POST', '/companies/upsert', ['external_ref' => "$tag-L", 'name' => "ZZ $tag Limited"]);
    ok('it cannot upsert (403)', $c === 403, "$c " . json_encode($r));
    ok('nothing was changed or made by it', (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE name LIKE " . $conn->quote("ZZ $tag%"))->fetchColumn() === 3
        && $conn->query("SELECT name FROM tenants WHERE id = $aId")->fetchColumn() === "ZZ $tag Alpha");

    echo "A key that does not act as an administrator\n";
    if ($plain) {
        $nonAdmin = $newKey('not admin', $plain, $allPerms, null);
        [$c, $r] = $call($nonAdmin, 'GET', "/companies/$aId");
        ok('(control) it can read', $c === 200, "$c");
        [$c, $r] = $call($nonAdmin, 'POST', '/companies', ['name' => "ZZ $tag NonAdmin"]);
        ok('it cannot create (403)', $c === 403 && str_contains((string)($r['error']['message'] ?? ''), 'administrator'), "$c " . json_encode($r));
        [$c, $r] = $call($nonAdmin, 'PATCH', "/companies/$aId", ['name' => 'nope']);
        ok('it cannot PATCH (403)', $c === 403, "$c");
        [$c, $r] = $call($nonAdmin, 'POST', '/companies/upsert', ['external_ref' => "$tag-A", 'name' => 'nope']);
        ok('it cannot upsert (403)', $c === 403, "$c");
        ok('nothing was changed by it', $conn->query("SELECT name FROM tenants WHERE id = $aId")->fetchColumn() === "ZZ $tag Alpha");
    } else {
        echo "  SKIP  no active analyst who is not an administrator\n";
    }
    $readOnly = $newKey('read only', $admin, ['companies' => ['read']], null);
    [$c, $r] = $call($readOnly, 'POST', '/companies', ['name' => "ZZ $tag ReadOnly"]);
    ok('an administrator key without companies.create cannot create (403)', $c === 403, "$c");

    echo "Requesters: employee_id\n";
    if ($empReady) {
        $emp = "E-$tag";
        [$c, $r] = $call($full, 'POST', '/users', ['email' => "$tag.one@example.com", 'display_name' => "ZZ $tag One", 'tenant_id' => $aId, 'employee_id' => " $emp "]);
        $u1 = (int)($r['data']['id'] ?? 0); if ($u1) $userIds[] = $u1;
        ok('POST /users stores employee_id (trimmed)', $c === 201 && ($r['data']['employee_id'] ?? '') === $emp, "$c " . json_encode($r));
        [$c, $r] = $call($full, 'POST', '/users', ['email' => "$tag.two@example.com", 'display_name' => "ZZ $tag Two", 'tenant_id' => $aId]);
        $u2 = (int)($r['data']['id'] ?? 0); if ($u2) $userIds[] = $u2;
        ok('(control) one without it has employee_id null', $c === 201 && array_key_exists('employee_id', $r['data'] ?? []) && $r['data']['employee_id'] === null, "$c " . json_encode($r));
        [$c, $r] = $call($full, 'GET', '/users?employee_id=' . urlencode(strtolower($emp)));
        ok('GET /users?employee_id= finds them, ignoring case', $c === 200 && $ids($r) === [$u1], "$c " . json_encode($r));
        [$c, $r] = $call($full, 'GET', "/users/$u1");
        ok('GET /users/{id} shows it', $c === 200 && ($r['data']['employee_id'] ?? '') === $emp, "$c");
        [$c, $r] = $call($full, 'PATCH', "/users/$u2", ['employee_id' => $emp]);
        ok('it is not unique: a second requester may share it', $c === 200 && ($r['data']['employee_id'] ?? '') === $emp && ($r['data']['display_name'] ?? '') === "ZZ $tag Two", "$c " . json_encode($r));
        [$c, $r] = $call($full, 'GET', '/users?employee_id=' . urlencode($emp));
        $got = $ids($r); sort($got);
        ok('...and the filter then finds both', $c === 200 && $got === [$u1, $u2], "$c " . json_encode($r));
        [$c, $r] = $call($full, 'PATCH', "/users/$u2", ['employee_id' => str_repeat('9', 65)]);
        ok('more than 64 characters is refused (422)', $c === 422, "$c " . json_encode($r));
        [$c, $r] = $call($full, 'PATCH', "/users/$u2", ['employee_id' => str_repeat('9', 64)]);
        ok('(control) 64 is fine', $c === 200 && ($r['data']['employee_id'] ?? '') === str_repeat('9', 64), "$c");
        [$c, $r] = $call($full, 'PATCH', "/users/$u2", ['employee_id' => null]);
        ok('null clears it', $c === 200 && array_key_exists('employee_id', $r['data'] ?? []) && $r['data']['employee_id'] === null, "$c " . json_encode($r));
        [$c, $r] = $call($full, 'PATCH', "/users/$u1", ['preferred_name' => 'Zed']);
        ok('a PATCH without it leaves it alone', $c === 200 && ($r['data']['employee_id'] ?? '') === $emp, "$c " . json_encode($r));
        $limitedU = $newKey('limited users', $admin, $allPerms, [$uId]);
        [$c, $r] = $call($limitedU, 'GET', '/users?employee_id=' . urlencode($emp));
        ok('a key for another company finds nobody by it', $c === 200 && $ids($r) === [], "$c " . json_encode($r));
    } else {
        echo "  SKIP  users.employee_id is not there yet - run Database Verification\n";
    }
} finally {
    echo "Removing\n";
    foreach ($userIds as $u) {
        $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$u]);
    }
    $conn->prepare("DELETE FROM users WHERE email LIKE ?")->execute(["$tag.%@example.com"]);
    $conn->prepare("DELETE FROM tenants WHERE name LIKE ? AND is_default = 0")->execute(["ZZ $tag%"]);
    foreach ($keyIds as $k) {
        foreach (['api_key_events', 'api_request_log', 'api_key_rate_limits'] as $t) {
            try { $conn->prepare("DELETE FROM $t WHERE api_key_id = ?")->execute([$k]); } catch (Throwable $e) {}
        }
        $conn->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$k]);
    }
    $left = (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE name LIKE " . $conn->quote("ZZ $tag%"))->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM users WHERE email LIKE " . $conn->quote("$tag.%@example.com"))->fetchColumn()
          + ($keyIds ? (int)$conn->query("SELECT COUNT(*) FROM api_keys WHERE id IN (" . implode(',', $keyIds) . ")")->fetchColumn() : 0);
    ok('nothing it made is left (companies, requesters, keys)', $left === 0, "$left left");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
