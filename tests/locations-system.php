<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Locations in System (GitHub #311).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/locations-system.php
 *
 * A ZZ second company (so the install is multi-company for the run) and a small tree:
 * the Default company's "UK > London > Floor 2", a SHARED "Data centre", and the second
 * company's own "Head office". Proves:
 *   - the Forms "location" lookup: path labels, the company's own plus shared, never
 *     another company's, the submit-time guard agreeing with the search;
 *   - the endpoints' gate: an administrator in, a session with no Assets refused;
 *   - the two screens: System -> Locations draws the tree, Assets -> Settings ->
 *     Locations draws the same tree with the signpost (needs Chrome; skipped without).
 * Everything ZZ is removed in finally.
 *
 * TRAP: the second company is added BEFORE anything asks tenancy a question -
 * isMultiTenant() is cached per process.
 *
 * @wiki Locations-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/forms.php';

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
$made = ['tenant' => 0, 'locations' => [], 'sessions' => [], 'files' => []];
$sessDir = rtrim((string)ini_get('session.save_path'), '/\\') ?: sys_get_temp_dir();
try {
    $conn->prepare("INSERT INTO tenants (name, is_default, is_active) VALUES (?, 0, 1)")->execute(["ZZ $tag Globex"]);
    $tB = $made['tenant'] = (int)$conn->lastInsertId();
    $tA = getDefaultTenantId($conn);
    check('the install is multi-company for the run', isMultiTenant($conn));

    // Locations go in leaf-last order, so the clean-up (reverse order) removes children first.
    $loc = function (string $name, ?int $tenant, ?int $parent, int $shared = 0) use ($conn, $tag, &$made): int {
        $conn->prepare("INSERT INTO asset_locations (name, parent_id, tenant_id, is_shared) VALUES (?, ?, ?, ?)")
             ->execute(["$tag $name", $parent, $tenant, $shared]);
        return $made['locations'][] = (int)$conn->lastInsertId();
    };
    $uk = $loc('UK', null, null);
    $london = $loc('London', null, $uk);
    $floor = $loc('Floor 2', null, $london);
    $dc = $loc('Data centre', null, null, 1);
    $hq = $loc('Head office', $tB, null);

    echo "The Forms lookup source\n";
    $labels = fn(array $rows) => array_column($rows, 'label', 'id');
    $a = $labels(FormsService::lookupSearch($conn, 'location', $tag, [$tA], 50));
    check('the Default company finds its own locations', isset($a[$uk], $a[$london], $a[$floor]), json_encode($a));
    check('a location reads as its full path', ($a[$floor] ?? '') === "$tag UK > $tag London > $tag Floor 2", $a[$floor] ?? 'missing');
    check('a shared location is offered', isset($a[$dc]));
    check("another company's location is not", !isset($a[$hq]));
    $b = $labels(FormsService::lookupSearch($conn, 'location', $tag, [$tB], 50));
    check('the second company finds its own and the shared one', isset($b[$hq], $b[$dc]), json_encode($b));
    check("and none of the Default company's", !isset($b[$uk]) && !isset($b[$floor]));
    $all = $labels(FormsService::lookupSearch($conn, 'location', $tag, null, 50));
    check('unrestricted finds all five', count(array_intersect_key($all, array_flip($made['locations']))) === 5);

    check('submit guard: own location accepted', FormsService::lookupValueAllowed($conn, 'location', $floor, [$tA]));
    check('submit guard: shared location accepted from the other company', FormsService::lookupValueAllowed($conn, 'location', $dc, [$tB]));
    check("submit guard: another company's location refused", !FormsService::lookupValueAllowed($conn, 'location', $hq, [$tA]));
    check('the source is portal-safe (a customer may say which office)', FormsService::lookupPortalAllowed(['config' => ['lookup_source' => 'location', 'portal_lookup' => true]]));

    if ($base !== '') {
        echo "The endpoints\n";
        $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
        $session = function (int $analystId, int $isAdmin) use ($sessDir, &$made): string {
            $sid = 'zzlc' . bin2hex(random_bytes(6));
            $csrf = bin2hex(random_bytes(32));
            file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $analystId . ';analyst_name|s:5:"zztst";is_admin|i:' . $isAdmin . ';csrf_token|s:64:"' . $csrf . '";');
            $made['sessions'][] = $sid;
            return $sid;
        };
        $http = function (string $sid, string $path, ?array $post = null) use ($base) {
            $ch = curl_init("$base/$path");
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid"];
            if ($post !== null) {
                $csrf = '';
                $raw = @file_get_contents(rtrim((string)ini_get('session.save_path'), '/\\') . "/sess_$sid");
                if ($raw && preg_match('/csrf_token\|s:64:"([0-9a-f]{64})"/', $raw, $m)) $csrf = $m[1];
                $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post),
                       CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $csrf"]];
            }
            curl_setopt_array($ch, $o);
            return json_decode((string)curl_exec($ch), true) ?: [];
        };
        $sAdmin = $session($admin, 1);
        $r = $http($sAdmin, 'api/assets/get_asset_locations.php');
        $ids = array_column($r['locations'] ?? [], 'id');
        check('an administrator reads the list', !empty($r['success']) && in_array($floor, $ids, true), json_encode(array_slice($r, 0, 2)));
        $r = $http($sAdmin, 'api/assets/save_asset_location.php', ['name' => "$tag Floor 3", 'parent_id' => $london]);
        if (!empty($r['success'])) {
            $new = (int)$conn->query("SELECT id FROM asset_locations WHERE name = " . $conn->quote("$tag Floor 3"))->fetchColumn();
            if ($new) $made['locations'][] = $new;   // a leaf: the clean-up runs in reverse, so it goes first
        }
        check('an administrator adds a location', !empty($r['success']), json_encode($r));

        // Nobody: an analyst id with no row has neither admin nor Assets.
        $sNone = $session(999999999, 0);
        $r = $http($sNone, 'api/assets/get_asset_locations.php');
        check('without Assets or admin, reading is refused', empty($r['success']), json_encode($r));
        $r = $http($sNone, 'api/assets/save_asset_location.php', ['name' => "$tag nope"]);
        check('and adding is refused', empty($r['success']), json_encode($r));
        check('and nothing was added', (int)$conn->query("SELECT COUNT(*) FROM asset_locations WHERE name = " . $conn->quote("$tag nope"))->fetchColumn() === 0);

        // The screens, in headless Chrome inside an iframe (the page sets the cookie, then frames the real page).
        $chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
        if (is_file($chrome)) {
            echo "The screens\n";
            $root = dirname(__DIR__);
            $render = function (string $page, string $probe) use ($root, $base, $chrome, $sAdmin, $tag, &$made): string {
                $name = 'zz_' . $tag . '_' . count($made['files']) . '.php';
                $made['files'][] = "$root/$name";
                file_put_contents("$root/$name", "<?php setcookie('PHPSESSID', '$sAdmin', 0, '/'); ?><!doctype html><html><body><pre id=\"out\">waiting</pre>"
                    . "<iframe id=\"f\" src=\"$page\" style=\"width:1300px;height:900px\"></iframe><script>"
                    . "const f=document.getElementById('f'),errs=[];f.addEventListener('load',()=>{f.contentWindow.addEventListener('error',e=>errs.push(e.message));"
                    . "setTimeout(()=>{const d=f.contentDocument;document.getElementById('out').textContent=JSON.stringify({ $probe ,errors:errs});},3500);});</script></body></html>");
                $prof = sys_get_temp_dir() . '/zz_loc_chrome_' . $tag;
                $out = (string)shell_exec('"' . $chrome . '" --headless=new --disable-gpu --user-data-dir="' . $prof . '" --virtual-time-budget=12000 --dump-dom "' . $base . '/' . $name . '" 2>NUL');
                return preg_match('#<pre id="out">(.*?)</pre>#s', $out, $m) ? html_entity_decode($m[1]) : '';
            };
            $j = json_decode($render('system/locations/', "tree:(d.getElementById('locations-tree')||{}).textContent||'',add:!!d.getElementById('lcAddBtn')"), true) ?: [];
            check('System -> Locations draws the tree', str_contains($j['tree'] ?? '', "$tag Floor 2") && str_contains($j['tree'] ?? '', "$tag Data centre"), substr(json_encode($j), 0, 300));
            check('with no script errors', ($j['errors'] ?? ['?']) === []);
            $j = json_decode($render('asset-management/settings/?tab=locations', "tree:(d.getElementById('locations-tree')||{}).textContent||'',sign:(d.querySelector('.loc-signpost')||{}).textContent||'',link:!!d.querySelector('.loc-signpost a[href*=\"system/locations\"]')"), true) ?: [];
            check('Assets -> Settings -> Locations draws the same tree', str_contains($j['tree'] ?? '', "$tag Floor 2"), substr(json_encode($j), 0, 300));
            check('with the signpost and, for an administrator, the link to System', trim($j['sign'] ?? '') !== '' && !empty($j['link']));
            check('with no script errors', ($j['errors'] ?? ['?']) === [], json_encode($j['errors'] ?? null));
        } else {
            echo "  (screens skipped: no Chrome)\n";
        }
    }
} catch (Throwable $x) {
    check('no exception', false, get_class($x) . ': ' . $x->getMessage() . ' @ ' . basename($x->getFile()) . ':' . $x->getLine());
} finally {
    foreach ($made['files'] as $f) @unlink($f);
    foreach ($made['sessions'] as $sid) @unlink("$sessDir/sess_$sid");
    foreach (array_reverse($made['locations']) as $id) {
        try { $conn->exec("DELETE FROM asset_locations WHERE id = " . (int)$id); } catch (Throwable $e) { echo "  (could not remove location $id: {$e->getMessage()})\n"; }
    }
    // Anything the run added under the tag that the list missed (children first by id).
    $conn->exec("DELETE FROM asset_locations WHERE name LIKE " . $conn->quote("$tag %") . " AND parent_id IS NOT NULL ORDER BY id DESC");
    $conn->exec("DELETE FROM asset_locations WHERE name LIKE " . $conn->quote("$tag %"));
    if ($made['tenant']) $conn->exec("DELETE FROM tenants WHERE id = " . (int)$made['tenant']);
    $left = (int)$conn->query("SELECT COUNT(*) FROM asset_locations WHERE name LIKE " . $conn->quote("$tag %"))->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE name LIKE " . $conn->quote("ZZ $tag%"))->fetchColumn();
    check('cleaned up', $left === 0);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
