<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * REST API: internal monitoring on domains (GH #243, #203 slice 3) - through real HTTP.
 *
 *   FREEITSM_TEST_DATABASE=<db> FREEITSM_BASE_URL=http://localhost/<that install> php tests/api-domain-monitoring.php
 *
 * BOTH are required: the database this writes to, and the install it calls - which
 * must be the same one. Without FREEITSM_BASE_URL it refuses to run rather than call
 * another install with keys that only exist here.
 *
 * Proves: reading needs only domains.read; changing needs domains.internal AND the key's
 * analyst holding Internal monitoring; a company-limited key cannot change trust profiles
 * and cannot see another company's domain; PATCH is partial; the same validation as the
 * screens (422) applies. Its keys, domain and profile are created here and removed at the end.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/domains.php';
require_once __DIR__ . '/../includes/capabilities.php';
require_once __DIR__ . '/../includes/rbac.php';

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
$base = rtrim((string)getenv('FREEITSM_BASE_URL'), '/');
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName || $base === '') {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE (this database is $dbName) and FREEITSM_BASE_URL to the install that uses it.\n";
    exit(0);
}
if (!domainEndpointsReady($conn) || !DomainsService::expectedReady($conn)) { echo "SKIPPED: run Database Verification first.\n"; exit(0); }

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!analystHasCapability($conn, $admin, Cap::DOMAINS_INTERNAL)) { echo "SKIPPED: analyst $admin lacks Internal monitoring.\n"; exit(0); }
$plain = null;
foreach ($conn->query("SELECT id FROM analysts WHERE is_active = 1 AND id <> $admin ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $aid) {
    if (!analystHasCapability($conn, (int)$aid, Cap::DOMAINS_INTERNAL)) { $plain = (int)$aid; break; }
}

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

$ctx = new ActorContext(actorId: $admin, companyScope: null, source: 'ui', actorName: 'test');
$made = []; $profileId = null;
try {
    $iname = 'zz-api-' . bin2hex(random_bytes(3)) . '.test';
    $id = DomainsService::createDomain($conn, $ctx, ['domain_name' => $iname, 'scope' => 'internal'], null);
    $made[] = $id;
    $full = $newKey('full', $admin, ['domains' => ['read', 'internal']], null);
    $read = $newKey('read', $admin, ['domains' => ['read']], null);

    echo "Endpoints\n";
    [$c, $r] = $call($read, 'GET', "/domains/$id/endpoints");
    ok('domains.read lists endpoints (none yet)', $c === 200 && ($r['data'] ?? null) === [], "$c " . json_encode($r));
    [$c, $r] = $call($read, 'POST', "/domains/$id/endpoints", ['host' => "app.$iname"]);
    ok('domains.read alone cannot add one (403)', $c === 403, "$c");
    [$c, $r] = $call($full, 'POST', "/domains/$id/endpoints", ['host' => "app.$iname", 'port' => 443, 'http_path' => '/health', 'http_expect' => '204 200']);
    $ep = $r['data'] ?? [];
    ok('domains.internal adds one, through the same rules (codes tidied)', $c === 201 && ($ep['host'] ?? '') === "app.$iname" && ($ep['http_expect'] ?? '') === '200, 204', "$c " . json_encode($r));
    [$c, $r] = $call($full, 'POST', "/domains/$id/endpoints", ['host' => 'example.org']);
    ok('a host outside the domain is refused (422)', $c === 422, "$c");
    [$c, $r] = $call($full, 'PATCH', "/domains/$id/endpoints/{$ep['id']}", ['warn_days' => 30]);
    ok('PATCH is partial: the warning window changes, the rest stays', $c === 200 && ($r['data']['warn_days'] ?? 0) === 30 && ($r['data']['http_path'] ?? '') === '/health', "$c " . json_encode($r));

    echo "Expected records\n";
    [$c, $r] = $call($full, 'POST', "/domains/$id/expected-records", ['host' => "app.$iname", 'record_type' => 'A', 'expected_values' => ['10.0.0.6', '10.0.0.5']]);
    $x = $r['data'] ?? [];
    ok('added, its values as a sorted list', $c === 201 && ($x['expected_values'] ?? null) === ['10.0.0.5', '10.0.0.6'], "$c " . json_encode($r));
    [$c, $r] = $call($full, 'POST', "/domains/$id/expected-records", ['host' => $iname, 'record_type' => 'A', 'expected_values' => ['10.0.0.999']]);
    ok('an invalid value is refused (422)', $c === 422, "$c");
    [$c, $r] = $call($full, 'PATCH', "/domains/$id/expected-records/{$x['id']}", ['expected_values' => []]);
    ok('PATCH can clear the values: the type only has to exist', $c === 200 && ($r['data']['expected_values'] ?? null) === [] && ($r['data']['host'] ?? '') === "app.$iname", "$c " . json_encode($r));
    [$c, $r] = $call($read, 'GET', "/domains/$id/expected-records");
    ok('domains.read lists them', $c === 200 && count($r['data'] ?? []) === 1);

    echo "Trust profiles\n";
    [$c, $r] = $call($read, 'GET', '/domain-trust-profiles');
    ok('they need domains.internal, even to list (403)', $c === 403, "$c");
    [$c, $r] = $call($full, 'GET', '/domain-trust-profiles');
    ok('domains.internal lists them', $c === 200 && is_array($r['data'] ?? null), "$c");

    echo "Who the key acts as, and its companies\n";
    if ($plain !== null) {
        $noCap = $newKey('nocap', $plain, ['domains' => ['read', 'internal']], null);
        [$c, $r] = $call($noCap, 'POST', "/domains/$id/expected-records", ['host' => $iname, 'record_type' => 'NS']);
        ok('a key whose analyst lacks Internal monitoring cannot change anything (403)', $c === 403 && str_contains((string)($r['error']['message'] ?? ''), 'Internal monitoring'), "$c " . json_encode($r));
    } else {
        echo "  SKIP  no analyst without Internal monitoring to act as\n";
    }
    if (isMultiTenant($conn)) {
        $limited = $newKey('limited', $admin, ['domains' => ['read', 'internal']], [(int)$conn->query("SELECT id FROM tenants ORDER BY id DESC LIMIT 1")->fetchColumn()]);
        [$c, $r] = $call($limited, 'POST', '/domain-trust-profiles', ['name' => 'zz', 'ca_pem' => 'x']);
        ok('a company-limited key cannot change trust profiles (403)', $c === 403, "$c");
    } else {
        echo "  SKIP  single-company install: the company limits do not apply\n";
    }

    echo "Removing\n";
    [$c, $r] = $call($full, 'DELETE', "/domains/$id/expected-records/{$x['id']}");
    ok('an expected record is removed', $c === 200 && ($r['data']['deleted'] ?? false) === true, "$c");
    [$c, $r] = $call($full, 'DELETE', "/domains/$id/endpoints/{$ep['id']}");
    ok('an endpoint is removed', $c === 200 && ($r['data']['deleted'] ?? false) === true, "$c");
    [$c, $r] = $call($full, 'DELETE', "/domains/$id/endpoints/{$ep['id']}");
    ok('...and is then not found (404)', $c === 404, "$c");
    ok('every change is in the domain\'s history', (int)$conn->query("SELECT COUNT(*) FROM domain_audit WHERE domain_id = $id AND field_name IN ('endpoint', 'expected_record') AND source = 'api'")->fetchColumn() >= 5);
} finally {
    foreach ($made as $m) {
        $conn->prepare("DELETE FROM domain_expected_records WHERE domain_id = ?")->execute([$m]);
        $conn->prepare("DELETE FROM domain_endpoints WHERE domain_id = ?")->execute([$m]);
        DomainsService::deleteDomain($conn, $ctx, $m);
    }
    foreach ($keyIds as $k) {
        try { $conn->prepare("DELETE FROM api_key_rate_limits WHERE key_id = ?")->execute([$k]); } catch (Throwable) {}
        $conn->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$k]);
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
