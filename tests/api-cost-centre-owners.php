<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * REST API - a cost centre's owner and deputy (GH #160 Part B), over HTTP.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/api-cost-centre-owners.php
 *
 * PATCH by email, GET shows them, a people group by name clears the owner person,
 * another company's person is 422 and changes nothing, POST /cost-centres/sync sets
 * and clears them, and a sync without the fields leaves them. Rows named zz- are
 * removed at the end.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base || $expected !== $conn->query('SELECT DATABASE()')->fetchColumn()) { echo "SKIPPED: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL.\n"; exit(0); }

$tag = 'zz' . bin2hex(random_bytes(3));
$made = ['users' => [], 'cc' => [], 'group' => 0, 'key' => 0, 'tenant' => 0];
try {
    $home = (int)getDefaultTenantId($conn);
    $conn->prepare('INSERT INTO tenants (name, slug) VALUES (?, ?)')->execute(["$tag co", $tag]);
    $made['tenant'] = (int)$conn->lastInsertId();
    $u = function (string $n, int $t) use ($conn, $tag, &$made) {
        $conn->prepare('INSERT INTO users (email, display_name, tenant_id, is_active) VALUES (?, ?, ?, 1)')->execute(["$n.$tag@example.test", "$n $tag", $t]);
        return $made['users'][] = (int)$conn->lastInsertId();
    };
    $priya = $u('priya', $home); $sam = $u('sam', $home); $out = $u('out', $made['tenant']);
    $conn->prepare('INSERT INTO knowledge_user_groups (name, is_active) VALUES (?, 1)')->execute(["Controlling $tag"]);
    $made['group'] = (int)$conn->lastInsertId();
    $conn->prepare('INSERT INTO cost_centres (tenant_id, code, name) VALUES (?, ?, ?)')->execute([$home, "$tag-1", 'Logistics']);
    $cc = $made['cc'][] = (int)$conn->lastInsertId();

    $admin = (int)$conn->query('SELECT MIN(id) FROM analysts WHERE is_admin = 1 AND is_active = 1')->fetchColumn();
    $key = 'fitsm_' . bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime) VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP())')
         ->execute(["ZZ $tag", substr($key, 0, 12), hash('sha256', $key), $admin, json_encode(['cost_centres' => ['read', 'create', 'update']])]);
    $made['key'] = (int)$conn->lastInsertId();
    $api = function (string $method, string $path, ?array $json = null) use ($base, $key) {
        $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode($path));
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']];
        if ($json !== null) $o[CURLOPT_POSTFIELDS] = json_encode($json);
        curl_setopt_array($ch, $o);
        $body = json_decode((string)curl_exec($ch), true) ?: [];
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $body];
    };

    [$c, $r] = $api('PATCH', "/cost-centres/$cc", ['owner_email' => "priya.$tag@example.test", 'deputy_email' => "sam.$tag@example.test"]);
    check('PATCH by email sets owner and deputy, and returns them', $c === 200 && ($r['data']['owner']['id'] ?? 0) === $priya && ($r['data']['deputy']['email'] ?? '') === "sam.$tag@example.test" && ($r['data']['owner']['active'] ?? null) === true, "$c " . json_encode($r['data']['owner'] ?? $r));
    [$c, $r] = $api('GET', "/cost-centres/$cc");
    check('GET shows them', $c === 200 && ($r['data']['owner']['name'] ?? '') === "priya $tag" && $r['data']['owner_group'] === null);
    [$c, $r] = $api('PATCH', "/cost-centres/$cc", ['owner_group' => "Controlling $tag"]);
    check('a people group by name clears the owner person', $c === 200 && ($r['data']['owner_group']['id'] ?? 0) === $made['group'] && $r['data']['owner'] === null, "$c " . json_encode($r['data'] ?? $r));
    [$c, $r] = $api('PATCH', "/cost-centres/$cc", ['deputy_user_id' => $out]);
    check('another company\'s person is 422, and changes nothing', $c === 422 && (int)$conn->query("SELECT deputy_user_id FROM cost_centres WHERE id = $cc")->fetchColumn() === $sam, "$c");
    [$c, $r] = $api('POST', '/cost-centres/sync', ['company_id' => $home, 'cost_centres' => [['code' => "$tag-1", 'owner_email' => "priya.$tag@example.test", 'deputy_email' => '']]]);
    $row = $conn->query("SELECT owner_user_id, owner_group_id, deputy_user_id FROM cost_centres WHERE id = $cc")->fetch(PDO::FETCH_ASSOC);
    check('sync sets the owner and clears the deputy', $c === 200 && (int)$row['owner_user_id'] === $priya && $row['owner_group_id'] === null && $row['deputy_user_id'] === null, "$c " . json_encode($r['error'] ?? $row));
    [$c] = $api('POST', '/cost-centres/sync', ['company_id' => $home, 'cost_centres' => [['code' => "$tag-1", 'name' => 'Logistics renamed']]]);
    check('a sync without the fields leaves them', $c === 200 && (int)$conn->query("SELECT owner_user_id FROM cost_centres WHERE id = $cc")->fetchColumn() === $priya);
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($made['cc']) $conn->exec('DELETE FROM cost_centres WHERE id IN (' . implode(',', $made['cc']) . ')');
    if ($made['users']) $conn->exec('DELETE FROM users WHERE id IN (' . implode(',', $made['users']) . ')');
    if ($made['group']) $conn->exec('DELETE FROM knowledge_user_groups WHERE id = ' . $made['group']);
    if ($made['key']) { try { $conn->exec('DELETE FROM api_key_rate_limits WHERE api_key_id = ' . $made['key']); } catch (Throwable $e) {} $conn->exec('DELETE FROM api_keys WHERE id = ' . $made['key']); }
    if ($made['tenant']) $conn->exec('DELETE FROM tenants WHERE id = ' . $made['tenant']);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM cost_centres WHERE code LIKE 'zz%-%') + (SELECT COUNT(*) FROM tenants WHERE slug LIKE '$tag')")->fetchColumn();
    check('cleanup', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
