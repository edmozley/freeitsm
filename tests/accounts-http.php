<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Chart of accounts (GH #282, slice 1) through the real web server: the System page's
 * own API (save, list, export, a spreadsheet import with its preview, delete), the REST
 * API (/accounts, /accounts/sync, scope, permissions) and the page itself in headless
 * Chrome.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/accounts-http.php
 *
 * Makes ZZ accounts (codes starting ZZACC-), a ZZ API key and a forged session for an
 * existing administrator, and removes all three in finally. Refuses to run unless
 * config.php points at FREEITSM_TEST_DATABASE.
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/spreadsheet.php';
require_once 'includes/tenancy.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$sessDir = rtrim((string)(getenv('FREEITSM_SESS_DIR') ?: 'C:/wamp64/tmp'), '/');
$sid = 'zzacc' . bin2hex(random_bytes(4));
$keyId = 0; $tmp = sys_get_temp_dir() . '/zzacc-' . getmypid();
@mkdir($tmp);
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$tenant = getDefaultTenantId($conn);

/** The System page's API, as the signed-in administrator. */
$ui = function (string $method, string $query = '', $body = null, bool $multipart = false) use ($base, $sid): array {
    $ch = curl_init("$base/api/system/accounts.php$query");
    $h = ['X-CSRF-Token: x', 'Cookie: PHPSESSID=' . $sid, 'Sec-Fetch-Site: same-origin'];
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true];
    if ($method === 'POST') {
        $o[CURLOPT_POST] = true;
        if ($multipart) { $o[CURLOPT_POSTFIELDS] = $body; }
        else { $h[] = 'Content-Type: application/json'; $o[CURLOPT_POSTFIELDS] = json_encode($body); }
    }
    $o[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $o);
    $raw = (string)curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), substr($raw, $hs), substr($raw, 0, $hs)];
};
/** The REST API with a key. */
$rest = function (string $method, string $path, string $key, ?array $body = null) use ($base): array {
    [$p, $q] = array_pad(explode('?', $path, 2), 2, '');   // filters go in the real query string
    $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode($p) . ($q !== '' ? '&' . $q : ''));
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
          CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']];
    if ($body !== null) $o[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $o);
    $raw = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 300)]];
};

try {
    file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_username|s:5:"admin";analyst_name|s:5:"Admin";csrf_token|s:1:"x";');

    echo "The System page's API\n";
    [$st, $b] = $ui('POST', '', ['action' => 'save', 'company_id' => $tenant, 'code' => 'ZZACC-2', 'name' => 'ZZ IT costs', 'kind' => 'expense']);
    $j = json_decode($b, true) ?: [];
    check('save adds an account', $st === 200 && !empty($j['success']) && (int)($j['id'] ?? 0) > 0, $b);
    $parentId = (int)($j['id'] ?? 0);
    [, $b] = $ui('POST', '', ['action' => 'save', 'company_id' => $tenant, 'code' => 'ZZACC-2.4', 'name' => 'ZZ Licences', 'kind' => 'banana']);
    check('save refuses a made-up kind, with the reason', str_contains($b, 'is not a kind of account'), $b);
    [, $b] = $ui('POST', '', ['action' => 'save', 'company_id' => $tenant, 'code' => 'ZZACC-2.4', 'name' => 'ZZ Licences', 'kind' => 'expense', 'parent_id' => $parentId]);
    $childId = (int)((json_decode($b, true) ?: [])['id'] ?? 0);
    [, $b] = $ui('GET', "?action=list&company_id=$tenant");
    $l = json_decode($b, true) ?: [];
    $mine = array_values(array_filter($l['accounts'] ?? [], fn($a) => str_starts_with($a['code'], 'ZZACC-')));
    check('list returns them with kind and parent', count($mine) === 2 && $mine[1]['kind'] === 'expense' && $mine[1]['parent_id'] === $parentId, json_encode($mine));
    check('list offers the four kinds', ($l['kinds'] ?? null) === ['expense', 'fixed_asset', 'payroll', 'income']);

    [$st, $xlsx, $hdr] = $ui('GET', "?action=export&format=xlsx&company_id=$tenant");
    file_put_contents("$tmp/export.xlsx", $xlsx);
    $sheet = $st === 200 ? spreadsheetRead("$tmp/export.xlsx", 'export.xlsx') : [];
    $row = null;
    foreach ($sheet as $r) if (($r[0] ?? '') === 'ZZACC-2.4') $row = $r;
    check('export: an .xlsx with Code, Name, Kind, Description, Parent code, Active', ($sheet[0] ?? []) === ['Code', 'Name', 'Kind', 'Description', 'Parent code', 'Active'], json_encode($sheet[0] ?? null));
    check('export: the kind written as a word, the parent as its code', $row !== null && $row[2] === 'Expense' && $row[4] === 'ZZACC-2', json_encode($row));

    // An import in a finance system's own words: a "Konto" heading and "Kontoart" kinds.
    file_put_contents("$tmp/in.csv", "Konto;Bezeichnung;Kontoart;Parent code\r\nZZACC-3;ZZ Anlagen;Anlagevermögen;\r\nZZACC-3.1;ZZ Computer Equipment;;ZZACC-3\r\nZZACC-2.4;ZZ Licences;Capex;ZZACC-2\r\n");
    $post = fn(string $apply) => ['action' => 'import', 'company_id' => (string)$tenant, 'apply' => $apply, 'deactivate_missing' => '0', 'file' => new CURLFile("$tmp/in.csv", 'text/csv', 'in.csv')];
    [, $b] = $ui('POST', '', $post('0'), true);
    $rep = (json_decode($b, true) ?: [])['report'] ?? [];
    check('import preview: German headings and kinds understood, nothing written yet', ($rep['counts'] ?? null) === ['create' => 2, 'update' => 1, 'unchanged' => 0, 'deactivate' => 0] && empty($rep['applied']), $b);
    check('import preview: the child with no kind takes its parent\'s', ($rep['rows'][1]['kind'] ?? '') === 'fixed_asset', json_encode($rep['rows'] ?? null));
    check('preview wrote nothing', (int)$conn->query("SELECT COUNT(*) FROM accounts WHERE code LIKE 'ZZACC-3%'")->fetchColumn() === 0);
    [, $b] = $ui('POST', '', $post('1'), true);
    check('import applied', !empty(((json_decode($b, true) ?: [])['report'] ?? [])['applied']), $b);
    check('...and the kinds are stored', $conn->query("SELECT GROUP_CONCAT(kind ORDER BY code) FROM accounts WHERE code IN ('ZZACC-2.4','ZZACC-3','ZZACC-3.1')")->fetchColumn() === 'fixed_asset,fixed_asset,fixed_asset');

    [, $b] = $ui('POST', '', ['action' => 'delete', 'id' => $parentId]);
    check('delete refuses an account with one below it', str_contains($b, 'below it'), $b);

    echo "\nThe REST API\n";
    $key = 'fitsm_' . bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime) VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP())')
         ->execute(['ZZACC key', substr($key, 0, 12), hash('sha256', $key), $admin, json_encode(['accounts' => ['read', 'create', 'update', 'delete']])]);
    $keyId = (int)$conn->lastInsertId();
    $readKey = 'fitsm_' . bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime) VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP())')
         ->execute(['ZZACC read key', substr($readKey, 0, 12), hash('sha256', $readKey), $admin, json_encode(['accounts' => ['read']])]);
    $readKeyId = (int)$conn->lastInsertId();

    [$st, $j] = $rest('GET', '/accounts?kind=fixed_asset&q=ZZACC', $key);
    check('GET /accounts?kind=fixed_asset filters by kind', $st === 200 && count($j['data'] ?? []) === 3 && ($j['data'][0]['kind'] ?? '') === 'fixed_asset', json_encode($j));
    [$st, $j] = $rest('GET', '/accounts?kind=misc', $key);
    check('an unknown kind filter is a 400', $st === 400, (string)$st);
    [$st, $j] = $rest('POST', '/accounts', $key, ['code' => 'ZZACC-9', 'name' => 'ZZ Recharges', 'kind' => 'income']);
    check('POST /accounts creates, with kind', $st === 201 && ($j['data']['kind'] ?? '') === 'income' && ($j['data']['company']['id'] ?? 0) === $tenant, json_encode($j));
    $nineId = (int)($j['data']['id'] ?? 0);
    [$st, $j] = $rest('PATCH', "/accounts/$nineId", $key, ['kind' => 'payroll']);
    check('PATCH /accounts/{id} changes the kind', $st === 200 && ($j['data']['kind'] ?? '') === 'payroll', json_encode($j));
    [$st, $j] = $rest('POST', '/accounts/sync', $key, ['dry_run' => true, 'accounts' => [['code' => 'ZZACC-9', 'kind' => 'income'], ['code' => 'ZZACC-10', 'name' => 'ZZ New', 'parent_code' => 'ZZACC-9']]]);
    check('POST /accounts/sync dry run: counts, and the new one takes its parent\'s kind', $st === 200 && ($j['data']['counts'] ?? null) === ['create' => 1, 'update' => 1, 'unchanged' => 0, 'deactivate' => 0] && empty($j['data']['applied'])
        && ($j['data']['changes'][1]['kind'] ?? '') === 'income', json_encode($j));
    [$st, $j] = $rest('POST', '/accounts/sync', $key, ['accounts' => [['code' => 'ZZACC-11', 'name' => 'ZZ orphan']]]);
    check('sync with a new account that has no kind and no parent: 422, nothing written', $st === 422 && ($j['error']['code'] ?? '') === 'invalid_rows'
        && (int)$conn->query("SELECT COUNT(*) FROM accounts WHERE code = 'ZZACC-11'")->fetchColumn() === 0, json_encode($j));
    [$st] = $rest('POST', '/accounts/sync', $readKey, ['accounts' => [['code' => 'ZZACC-9']]]);
    check('a read-only key cannot sync', $st === 403, (string)$st);
    [$st] = $rest('GET', "/accounts/$nineId", $readKey);
    check('a read-only key can read one', $st === 200, (string)$st);
    [$st, $j] = $rest('DELETE', "/accounts/$nineId", $key);
    check('DELETE /accounts/{id}', $st === 200 && !empty($j['data']['deleted']), json_encode($j));
    [$st] = $rest('GET', "/accounts/$nineId", $key);
    check('...and it is gone (404)', $st === 404, (string)$st);

    echo "\nThe page\n";
    $chrome = null;
    foreach (['C:\Program Files\Google\Chrome\Application\chrome.exe', 'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe'] as $c) if (is_file($c)) { $chrome = $c; break; }
    if ($chrome === null) {
        echo "  SKIP  Chrome not found - the page was NOT loaded\n";
    } else {
        // A same-origin harness is not possible from here, so load the page with the session
        // cookie set through a one-off redirect page the test writes and then deletes.
        $harness = 'zzacc_harness_' . bin2hex(random_bytes(3)) . '.php';
        $docroot = getcwd();
        file_put_contents("$docroot/$harness", "<?php if (PHP_SAPI === 'cli') exit; setcookie('PHPSESSID', " . var_export($sid, true) . ", ['path' => '/']); header('Location: system/accounts/');");
        try {
            $cmd = '"' . $chrome . '" --headless --disable-gpu --no-sandbox --no-first-run --user-data-dir="' . $tmp . '/profile" --dump-dom --virtual-time-budget=8000 "' . $base . '/' . $harness . '" 2>&1';
            $dom = (string)shell_exec($cmd);
        } finally {
            @unlink("$docroot/$harness");
        }
        check('the page draws the chart: the ZZ accounts in the table', str_contains($dom, 'ZZACC-3.1') && str_contains($dom, 'ZZ Computer Equipment'), substr(strip_tags($dom), 0, 200));
        check('...with a coloured kind badge', (bool)preg_match('/class="ac-kind fixed_asset"[^>]*>Fixed asset</', $dom));
        // Only the table body: the words themselves are always in the page, in the embedded translations.
        $tbody = preg_match('#<tbody id="acBody">(.*?)</tbody>#s', $dom, $tm) ? $tm[1] : '';
        check('...and no load error in the table', $tbody !== '' && !str_contains($tbody, 'Could not load'));
    }
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $conn->exec("UPDATE accounts SET parent_id = NULL WHERE code LIKE 'ZZACC-%'");
    $conn->exec("DELETE FROM accounts WHERE code LIKE 'ZZACC-%'");
    if ($keyId) $conn->exec("DELETE FROM api_keys WHERE id = $keyId");
    if (!empty($readKeyId)) $conn->exec("DELETE FROM api_keys WHERE id = $readKeyId");
    @unlink("$sessDir/sess_$sid");
    @array_map('unlink', glob("$tmp/*.*") ?: []);
    @exec('rmdir /s /q "' . str_replace('/', '\\', $tmp) . '" 2>nul');
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
