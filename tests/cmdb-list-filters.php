<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * CMDB list filters, search and columns (GH #312 follow-up) - api/cmdb/get_objects.php.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/cmdb-list-filters.php
 *
 * A ZZ class with a property of each kind (text, number, date, Yes/No, drop-down,
 * object reference, person, location), three ZZ CIs in the Default company with
 * different values, and one ZZ CI in a ZZ second company. Calls the real endpoint
 * with a forged administrator session and proves:
 *   - every operator returns exactly the right CIs (boundaries included);
 *   - several filters combine with AND;
 *   - a property of ANOTHER class, an unknown operator and an unparseable value are
 *     ignored and counted, never applied;
 *   - an injection-shaped value is only ever a value;
 *   - the search box also matches text values, with % taken literally;
 *   - columns come back with CI and record names;
 *   - the second company's CI never appears, whatever the filter.
 * Everything ZZ is removed in finally.
 *
 * TRAP: the second company is added BEFORE anything asks tenancy a question -
 * isMultiTenant() is cached per process.
 *
 * @wiki CMDB
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/cmdb_record_refs.php';

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE)\n"; exit(0); }
if ($base === '') { echo "(skipped: set FREEITSM_TEST_URL - this test calls the endpoint)\n"; exit(0); }
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
if (!cmdbRecordRefsReady($conn)) { echo "(skipped: run Database Verification first - no cmdb_object_properties.value_ref_id)\n"; exit(0); }

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$tag = 'zz' . bin2hex(random_bytes(3));
$made = ['tenant' => 0, 'classes' => [], 'objects' => [], 'users' => [], 'locations' => [], 'sessions' => []];
$sessDir = rtrim((string)ini_get('session.save_path'), '/\\') ?: sys_get_temp_dir();
try {
    $conn->prepare("INSERT INTO tenants (name, is_default, is_active) VALUES (?, 0, 1)")->execute(["ZZ $tag Globex"]);
    $tB = $made['tenant'] = (int)$conn->lastInsertId();
    $tA = (int)getDefaultTenantId($conn);
    check('the install is multi-company for the run', isMultiTenant($conn));

    $class = function (string $name) use ($conn, $tag, &$made): int {
        $conn->prepare("INSERT INTO cmdb_classes (class_key, name, is_active) VALUES (?, ?, 1)")
             ->execute([$tag . '_' . strtolower($name), "ZZ $tag $name"]);
        return $made['classes'][] = (int)$conn->lastInsertId();
    };
    $prop = function (int $classId, string $key, string $type, ?int $target = null) use ($conn): int {
        $conn->prepare("INSERT INTO cmdb_class_properties (class_id, property_key, label, property_type, target_class_id) VALUES (?, ?, ?, ?, ?)")
             ->execute([$classId, $key, ucfirst($key), $type, $target]);
        return (int)$conn->lastInsertId();
    };
    $obj = function (int $classId, string $name, int $tenant) use ($conn, $tag, &$made): int {
        $conn->prepare("INSERT INTO cmdb_objects (class_id, name, tenant_id) VALUES (?, ?, ?)")->execute([$classId, "$tag $name", $tenant]);
        return $made['objects'][] = (int)$conn->lastInsertId();
    };
    $set = function (int $objectId, int $propId, string $col, $value) use ($conn): void {
        // $col is one of this file's own literals, never input.
        $conn->prepare("INSERT INTO cmdb_object_properties (object_id, property_id, `$col`) VALUES (?, ?, ?)")->execute([$objectId, $propId, $value]);
    };

    $hostClass = $class('Host');
    $srvClass  = $class('Server');
    $pOther = $prop($hostClass, 'rack', 'text');           // a property of ANOTHER class
    $pEnv   = $prop($srvClass, 'environment', 'text');
    $pCpu   = $prop($srvClass, 'cpus', 'number');
    $pWar   = $prop($srvClass, 'warranty', 'date');
    $pMaint = $prop($srvClass, 'maintenance', 'boolean');
    $pTier  = $prop($srvClass, 'tier', 'dropdown');
    $pHost  = $prop($srvClass, 'host', 'object_ref', $hostClass);
    $pOwner = $prop($srvClass, 'owner', 'person');
    $pSite  = $prop($srvClass, 'site', 'location');
    foreach (['Gold', 'Silver'] as $i => $o) {
        $conn->prepare("INSERT INTO cmdb_class_property_options (property_id, option_value, colour, display_order) VALUES (?, ?, ?, ?)")
             ->execute([$pTier, $o, $i ? null : '#b45309', $i]);
    }

    $conn->prepare("INSERT INTO users (email, display_name, tenant_id) VALUES (?, ?, ?)")->execute(["$tag.sarah@example.invalid", "$tag Sarah", $tA]);
    $u1 = $made['users'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id) VALUES (?, ?, ?)")->execute(["$tag.omar@example.invalid", "$tag Omar", $tA]);
    $u2 = $made['users'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO asset_locations (name, tenant_id) VALUES (?, ?)")->execute(["$tag Leeds", $tA]);
    $l1 = $made['locations'][] = (int)$conn->lastInsertId();

    $h1 = $obj($hostClass, 'host-one', $tA);
    $h2 = $obj($hostClass, 'host-two', $tA);
    $set($h1, $pOther, 'value_text', 'Production rack');   // must never make a Server match

    $s1 = $obj($srvClass, 'alpha', $tA);
    $set($s1, $pEnv, 'value_text', 'Production east');
    $set($s1, $pCpu, 'value_number', 8);
    $set($s1, $pWar, 'value_date', '2026-06-30 00:00:00');
    $set($s1, $pMaint, 'value_boolean', 1);
    $set($s1, $pTier, 'value_text', 'Gold');
    $set($s1, $pHost, 'value_object_id', $h1);
    $set($s1, $pOwner, 'value_ref_id', $u1);
    $set($s1, $pSite, 'value_ref_id', $l1);

    $s2 = $obj($srvClass, 'bravo', $tA);
    $set($s2, $pEnv, 'value_text', 'Test');
    $set($s2, $pCpu, 'value_number', 16);
    $set($s2, $pWar, 'value_date', '2027-03-01 00:00:00');
    $set($s2, $pMaint, 'value_boolean', 0);
    $set($s2, $pTier, 'value_text', 'Silver');
    $set($s2, $pHost, 'value_object_id', $h2);
    $set($s2, $pOwner, 'value_ref_id', $u2);

    $s3 = $obj($srvClass, "charlie 100% o'neil", $tA);   // no values at all
    $set($s3, $pEnv, 'value_text', '');                     // an empty string is "empty", not "set"

    $s4 = $obj($srvClass, 'delta', $tB);                     // the OTHER company - must never appear
    $set($s4, $pEnv, 'value_text', 'Production');
    $set($s4, $pCpu, 'value_number', 8);
    $set($s4, $pMaint, 'value_boolean', 1);
    $set($s4, $pTier, 'value_text', 'Gold');
    $set($s4, $pOwner, 'value_ref_id', $u1);

    // The forged administrator session (no active company chosen = the Default company).
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $sid = 'zzcf' . bin2hex(random_bytes(6));
    file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_name|s:5:"zztst";is_admin|i:1;csrf_token|s:64:"' . bin2hex(random_bytes(32)) . '";');
    $made['sessions'][] = $sid;
    $calls = 0; $sawB = false;
    $list = function (array $filters = [], string $search = '', array $cols = []) use ($base, $sid, $srvClass, $s4, &$calls, &$sawB): array {
        $q = 'class_id=' . $srvClass;
        foreach ($filters as $f) $q .= '&' . rawurlencode('f[]') . '=' . rawurlencode($f);
        if ($search !== '') $q .= '&search=' . rawurlencode($search);
        if ($cols) $q .= '&cols=' . implode(',', $cols);
        $ch = curl_init("$base/api/cmdb/get_objects.php?$q");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid"]);
        $r = json_decode((string)curl_exec($ch), true) ?: ['success' => false, 'error' => 'not JSON'];
        $calls++;
        $ids = array_map('intval', array_column($r['objects'] ?? [], 'id'));
        if (in_array($s4, $ids, true)) $sawB = true;
        sort($ids);
        $r['ids'] = $ids;
        return $r;
    };
    $is = function (string $what, array $r, array $want) {
        sort($want);
        check($what, !empty($r['success']) && $r['ids'] === $want,
              json_encode(['want' => $want, 'got' => $r['ids'], 'error' => $r['error'] ?? null, 'ignored' => $r['ignored_filters'] ?? null]));
    };

    echo "No filters\n";
    $is('the class lists its three CIs in this company', $list(), [$s1, $s2, $s3]);

    echo "Text\n";
    $is('contains (any case, part of the value)', $list(["$pEnv~has~production"]), [$s1]);
    $is('a % is a literal character, not a wildcard', $list(["$pEnv~has~%"]), []);
    $is('is set (an empty string does not count)', $list(["$pEnv~set~"]), [$s1, $s2]);
    $is('is empty (no row, or an empty string)', $list(["$pEnv~empty~"]), [$s3]);

    echo "Number\n";
    $is('equals', $list(["$pCpu~eq~8"]), [$s1]);
    $is('at least', $list(["$pCpu~gte~10"]), [$s2]);
    $is('at most (the boundary is in)', $list(["$pCpu~lte~8"]), [$s1]);
    $is('a decimal value works', $list(["$pCpu~gte~15.5"]), [$s2]);

    echo "Date\n";
    $is('on or before (the day itself is in)', $list(["$pWar~lte~2026-06-30"]), [$s1]);
    $is('on or before the day before - nothing', $list(["$pWar~lte~2026-06-29"]), []);
    $is('on or after', $list(["$pWar~gte~2027-01-01"]), [$s2]);
    $is('is empty', $list(["$pWar~empty~"]), [$s3]);

    echo "Yes/No and drop-down\n";
    $is('Yes', $list(["$pMaint~eq~1"]), [$s1]);
    $is('No (a stored No only)', $list(["$pMaint~eq~0"]), [$s2]);
    $is('drop-down is Gold', $list(["$pTier~eq~Gold"]), [$s1]);
    $is('drop-down is set', $list(["$pTier~set~"]), [$s1, $s2]);

    echo "References\n";
    $r = $list(["$pHost~eq~$h1"]);
    $is('object reference is host-one', $r, [$s1]);
    check('and the chip label comes back as the CI name', ($r['filters'][0]['label'] ?? '') === "$tag host-one", json_encode($r['filters'] ?? null));
    $r = $list(["$pOwner~eq~$u2"]);
    $is('person is Omar', $r, [$s2]);
    check('and the chip label comes back as the person', ($r['filters'][0]['label'] ?? '') === "$tag Omar", json_encode($r['filters'] ?? null));
    $is('person is Sarah (the other company\'s CI pointing at her stays out)', $list(["$pOwner~eq~$u1"]), [$s1]);
    $is('location is Leeds', $list(["$pSite~eq~$l1"]), [$s1]);
    $is('location is empty', $list(["$pSite~empty~"]), [$s2, $s3]);
    $is('person is set', $list(["$pOwner~set~"]), [$s1, $s2]);

    echo "Combined\n";
    $is('two filters AND together', $list(["$pCpu~gte~1", "$pMaint~eq~0"]), [$s2]);
    $is('two that no CI meets both of', $list(["$pTier~eq~Gold", "$pMaint~eq~0"]), []);
    $is('a date range from two filters', $list(["$pWar~gte~2026-01-01", "$pWar~lte~2026-12-31"]), [$s1]);

    echo "Refused / ignored\n";
    $r = $list(["$pOther~has~Production"]);
    $is('a property of another class is not applied', $r, [$s1, $s2, $s3]);
    check('and it is counted as ignored, not echoed back', ($r['ignored_filters'] ?? 0) === 1 && ($r['filters'] ?? null) === [], json_encode($r['filters'] ?? null));
    $r = $list(["$pCpu~gt~1"]);
    check('an unknown operator is ignored', ($r['ignored_filters'] ?? 0) === 1 && $r['ids'] === [$s1, $s2, $s3]);
    $r = $list(["$pCpu~has~8", "$pTier~gte~Gold", "$pMaint~eq~yes", "$pWar~lte~2026-02-30", "$pHost~eq~abc"]);
    check('an operator that does not suit the type, or a value that does not parse, is ignored', ($r['ignored_filters'] ?? 0) === 5 && $r['ids'] === [$s1, $s2, $s3], json_encode(['ign' => $r['ignored_filters'] ?? null, 'ids' => $r['ids']]));
    $r = $list(array_fill(0, 12, "$pCpu~gte~1"));
    check('at most ten filters are read', count($r['filters'] ?? []) === 10);

    echo "Injection-shaped values\n";
    $is("text: x' OR 1=1 -- is just text", $list(["$pEnv~has~x' OR 1=1 -- "]), []);
    $is("drop-down: Gold' OR '1'='1 is just text", $list(["$pTier~eq~Gold' OR '1'='1"]), []);
    $r = $list(["$pCpu~eq~1 OR 1=1"]);
    check('number: 1 OR 1=1 does not parse, so it is ignored', ($r['ignored_filters'] ?? 0) === 1 && $r['ids'] === [$s1, $s2, $s3]);
    $is('a value with ~ in it keeps the rest', $list(["$pEnv~has~east~x"]), []);
    $r = $list(["$pEnv~has~'); DROP TABLE cmdb_objects; --"]);
    check('a statement in a value runs nothing', !empty($r['success']) && (int)$conn->query("SELECT COUNT(*) FROM cmdb_objects WHERE id = $s1")->fetchColumn() === 1);

    echo "Search\n";
    $is('the search box matches a text value', $list([], 'production EAST'), [$s1]);
    $is('and still matches names', $list([], 'bravo'), [$s2]);
    $is('a % in the search is literal', $list([], '100%'), [$s3]);
    $is("a quote in the search is literal", $list([], "o'neil"), [$s3]);
    $is('another class\'s value does not match', $list([], 'Production rack'), []);
    $is('search and a filter together', $list(["$pCpu~gte~1"], 'o'), [$s1, $s2]);   // alpha "Production", bravo "bravo"

    echo "Columns\n";
    $r = $list([], '', [$pHost, $pOwner, $pMaint, $pSite]);
    $rows = array_column($r['objects'] ?? [], null, 'id');
    $c1 = $rows[$s1]['columns'] ?? [];
    check('at most three columns are taken', ($r['cols'] ?? null) === [$pHost, $pOwner, $pMaint], json_encode($r['cols'] ?? null));
    check('object reference column shows the CI name', ($c1[$pHost]['name'] ?? '') === "$tag host-one", json_encode($c1));
    check('person column shows the person', ($c1[$pOwner]['name'] ?? '') === "$tag Sarah");
    check('Yes/No column is a boolean', ($c1[$pMaint] ?? null) === true && (($rows[$s2]['columns'][$pMaint] ?? null) === false));
    check('a CI with no value has no entry', empty((array)($rows[$s3]['columns'] ?? [])));
    $r = $list([], '', [$pOther, $pTier]);
    check('a column of another class is not taken', ($r['cols'] ?? null) === [$pTier], json_encode($r['cols'] ?? null));

    echo "Company\n";
    check("the second company's CI never appeared in $calls calls", !$sawB);
} catch (Throwable $x) {
    check('no exception', false, get_class($x) . ': ' . $x->getMessage() . ' @ ' . basename($x->getFile()) . ':' . $x->getLine());
} finally {
    foreach ($made['sessions'] as $sid) @unlink("$sessDir/sess_$sid");
    if ($made['objects']) {
        $ph = implode(',', array_map('intval', $made['objects']));
        $conn->exec("DELETE FROM cmdb_object_properties WHERE object_id IN ($ph)");
        $conn->exec("DELETE FROM cmdb_objects WHERE id IN ($ph)");
    }
    // Every class's properties before any class: Server's "host" points at Host.
    foreach ($made['classes'] as $cid) $conn->exec("DELETE FROM cmdb_class_properties WHERE class_id = " . (int)$cid);   // options cascade
    foreach ($made['classes'] as $cid) $conn->exec("DELETE FROM cmdb_classes WHERE id = " . (int)$cid);
    foreach ($made['users'] as $uid) $conn->exec("DELETE FROM users WHERE id = " . (int)$uid);
    foreach ($made['locations'] as $lid) $conn->exec("DELETE FROM asset_locations WHERE id = " . (int)$lid);
    if ($made['tenant']) $conn->exec("DELETE FROM tenants WHERE id = " . (int)$made['tenant']);
    $like = $conn->quote("%$tag%");
    $left = (int)$conn->query("SELECT COUNT(*) FROM cmdb_objects WHERE name LIKE $like")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM cmdb_classes WHERE name LIKE $like")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM users WHERE display_name LIKE $like")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM asset_locations WHERE name LIKE $like")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM tenants WHERE name LIKE $like")->fetchColumn();
    check('cleaned up', $left === 0);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
