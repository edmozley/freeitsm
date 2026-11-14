<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Companies: an outside reference and tax ID, one set of rules, and mail from a
 * known person (GitHub #279).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/companies-279.php
 *
 * CompaniesService (shared by System -> Companies and the REST API): required name,
 * unique outside reference / tax ID / ticket code (ignoring case and spaces), the
 * Default company stays active, an update changes only what is sent, upsert by
 * outside reference. Inbound email routing: a person's company catches their mail
 * when nothing more specific does, and only then. Then System -> Companies' own save
 * over HTTP. Every refusal has a positive control. ZZ rows removed in finally.
 *
 * TRAP: the ZZ companies are added BEFORE anything asks tenancy a question -
 * isMultiTenant() is cached per process.
 *
 * @wiki Companies-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/companies.php';

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE)\n"; exit(0); }
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
if (!CompaniesService::refsReady($conn)) { echo "(skipped: run Database Verification - tenants.external_ref is missing)\n"; exit(0); }

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}
/** The ServiceError kind thrown by $fn, or '' when it did not throw. */
function refusal(callable $fn): string
{
    try { $fn(); return ''; } catch (ServiceError $e) { return $e->kind; }
}

$tag = 'zz' . bin2hex(random_bytes(3));
$TAG = strtoupper($tag);
$made = ['tenants' => [], 'users' => [], 'domains' => [], 'sessions' => []];
$sessDir = rtrim((string)ini_get('session.save_path'), '/\\') ?: sys_get_temp_dir();
try {
    $a = CompaniesService::save($conn, ['name' => "ZZ $tag Alpha", 'external_ref' => "ERP-$tag-1", 'tax_id' => "EL$tag"]);
    $made['tenants'][] = $a['id'];
    check('a company is created with an outside reference and tax ID', $a['created'] && $a['id'] > 0);
    $row = CompaniesService::get($conn, $a['id']);
    check('both are stored', $row['external_ref'] === "ERP-$tag-1" && $row['tax_id'] === "EL$tag", json_encode($row));
    check('multi-company for the run', isMultiTenant($conn) || count(getAllTenants($conn)) > 1);

    echo "The rules\n";
    check('a name is required', refusal(fn() => CompaniesService::save($conn, ['name' => '  '])) === 'validation');
    check('the same outside reference is refused (ignoring case and spaces)',
        refusal(fn() => CompaniesService::save($conn, ['name' => "ZZ $tag Beta", 'external_ref' => "  erp-$tag-1 "])) === 'conflict');
    check('the same tax ID is refused', refusal(fn() => CompaniesService::save($conn, ['name' => "ZZ $tag Beta", 'tax_id' => strtolower("EL$tag")])) === 'conflict');
    $b = CompaniesService::save($conn, ['name' => "ZZ $tag Beta", 'external_ref' => "ERP-$tag-2"]);
    $made['tenants'][] = $b['id'];
    check('...while a different one is fine', $b['created']);
    check("an update cannot take another company's reference", refusal(fn() => CompaniesService::save($conn, ['external_ref' => "ERP-$tag-1"], $b['id'])) === 'conflict');
    CompaniesService::save($conn, ['tax_id' => "TX$tag"], $b['id']);
    $rb = CompaniesService::get($conn, $b['id']);
    check('an update changes only what is sent', $rb['tax_id'] === "TX$tag" && $rb['name'] === "ZZ $tag Beta" && $rb['external_ref'] === "ERP-$tag-2", json_encode($rb));
    CompaniesService::save($conn, ['external_ref' => ''], $b['id']);
    check('an empty value clears it', CompaniesService::get($conn, $b['id'])['external_ref'] === null);
    $def = getDefaultTenantId($conn);
    check('the Default company cannot be made inactive', refusal(fn() => CompaniesService::save($conn, ['is_active' => 0], $def)) === 'validation');
    check('an unknown company is not found', refusal(fn() => CompaniesService::save($conn, ['name' => 'x'], 999999999)) === 'not_found');
    check('a ticket code another company uses is refused', refusal(fn() => CompaniesService::save($conn, ['ticket_code' => "Q$TAG"], $a['id'])) === ''
        && refusal(fn() => CompaniesService::save($conn, ['ticket_code' => "q$tag"], $b['id'])) === 'conflict');

    echo "Upsert by outside reference\n";
    $u1 = CompaniesService::upsert($conn, ['external_ref' => "ERP-$tag-9", 'name' => "ZZ $tag Gamma"]);
    $made['tenants'][] = $u1['id'];
    $u2 = CompaniesService::upsert($conn, ['external_ref' => "erp-$tag-9", 'name' => "ZZ $tag Gamma Ltd", 'tax_id' => "G$tag"]);
    check('the first call creates', $u1['created']);
    check('the second finds the same company and updates it', !$u2['created'] && $u2['id'] === $u1['id'] && CompaniesService::get($conn, $u1['id'])['name'] === "ZZ $tag Gamma Ltd");
    check('upsert without an outside reference is refused', refusal(fn() => CompaniesService::upsert($conn, ['name' => 'x'])) === 'validation');

    echo "Mail from a known person\n";
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id) VALUES (?, ?, ?)")->execute(["jane.$tag@gmail.com", "$tag Jane", $a['id']]);
    $made['users'][] = (int)$conn->lastInsertId();
    check("a known person's Gmail address files with their company", resolveTicketTenantForEmail($conn, null, "Jane.$tag@gmail.com") === $a['id']);
    check('an unknown sender still goes to triage', resolveTicketTenantForEmail($conn, null, "nobody.$tag@gmail.com") === null);
    $conn->prepare("INSERT INTO tenant_domains (tenant_id, domain) VALUES (?, ?)")->execute([$b['id'], "$tag.example"]);
    $made['domains'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id) VALUES (?, ?, ?)")->execute(["bob@$tag.example", "$tag Bob", $a['id']]);
    $made['users'][] = (int)$conn->lastInsertId();
    check("a company's mapped domain still wins over the person's company", resolveTicketTenantForEmail($conn, null, "bob@$tag.example") === $b['id']);
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id) VALUES (?, ?, NULL)")->execute(["nocompany.$tag@gmail.com", "$tag Nocompany"]);
    $made['users'][] = (int)$conn->lastInsertId();
    check('a known person with no company still goes to triage', resolveTicketTenantForEmail($conn, null, "nocompany.$tag@gmail.com") === null);

    if ($base !== '') {
        echo "System -> Companies over HTTP\n";
        $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
        $sid = $made['sessions'][] = 'zzco' . bin2hex(random_bytes(6));
        $csrf = bin2hex(random_bytes(32));
        file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_name|s:5:"zztst";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');
        $http = function (string $path, ?array $post = null) use ($base, $sid, $csrf) {
            $ch = curl_init("$base/$path");
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid"];
            if ($post !== null) $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post), CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $csrf"]];
            curl_setopt_array($ch, $o);
            return json_decode((string)curl_exec($ch), true) ?: [];
        };
        $r = $http('api/system/save_tenant.php', ['id' => $a['id'], 'name' => "ZZ $tag Alpha", 'is_active' => 1, 'ticket_code' => '', 'external_ref' => "ERP-$tag-2b", 'tax_id' => "EL$tag"]);
        check('the page saves both fields', !empty($r['success']) && CompaniesService::get($conn, $a['id'])['external_ref'] === "ERP-$tag-2b", json_encode($r));
        $r = $http('api/system/save_tenant.php', ['id' => $b['id'], 'name' => "ZZ $tag Beta", 'is_active' => 1, 'ticket_code' => '', 'external_ref' => '', 'tax_id' => "el$tag"]);
        check('...and says when a tax ID is taken', empty($r['success']) && str_contains((string)($r['error'] ?? ''), "ZZ $tag Alpha"), json_encode($r));
        $r = $http('api/system/get_tenants.php');
        $row = array_values(array_filter($r['companies'] ?? [], fn($c) => (int)$c['id'] === $a['id']))[0] ?? [];
        check('the list returns them', ($row['tax_id'] ?? '') === "EL$tag" && ($row['external_ref'] ?? '') === "ERP-$tag-2b", json_encode($row));
    }
} catch (Throwable $x) {
    check('no exception', false, get_class($x) . ': ' . $x->getMessage() . ' @ ' . basename($x->getFile()) . ':' . $x->getLine());
} finally {
    foreach ($made['sessions'] as $sid) @unlink("$sessDir/sess_$sid");
    if ($made['domains']) $conn->exec("DELETE FROM tenant_domains WHERE id IN (" . implode(',', $made['domains']) . ")");
    if ($made['users']) $conn->exec("DELETE FROM users WHERE id IN (" . implode(',', $made['users']) . ")");
    if ($made['tenants']) $conn->exec("DELETE FROM tenants WHERE id IN (" . implode(',', $made['tenants']) . ")");
    $left = (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE name LIKE " . $conn->quote("ZZ $tag%"))->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM users WHERE display_name LIKE " . $conn->quote("$tag %"))->fetchColumn();
    check('cleaned up', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
