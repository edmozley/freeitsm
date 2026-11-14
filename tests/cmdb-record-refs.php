<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * CMDB properties that point at real records (GitHub #312).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/cmdb-record-refs.php
 *
 * A ZZ second company (so the install is multi-company), a ZZ class with a person,
 * analyst, team, location, company, supplier and contract property, and records in
 * both companies. Proves, through CmdbService (the UI and the REST API both save
 * through it):
 *   - values go in value_ref_id, never value_object_id;
 *   - the company rules: the CI's own people / locations / contracts (or a shared
 *     location), any reachable company, never another company's person;
 *   - an inactive team is refused as a new value but kept when already stored;
 *   - a removed record reads back as 'removed', an unreachable company as 'hidden';
 *   - the picker, the delete summary, Data quality and the analyst leaving checks;
 *   - a property's type cannot change once values exist if that would misread them.
 * Every refusal has a positive control beside it. Everything ZZ is removed in finally.
 *
 * TRAP: the second company is added BEFORE anything asks tenancy a question -
 * isMultiTenant() is cached per process.
 *
 * @wiki CMDB-Record-References-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/cmdb.php';
require_once __DIR__ . '/../includes/cmdb_audit.php';
require_once __DIR__ . '/../includes/analyst_lifecycle.php';

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE)\n"; exit(0); }
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
if (!cmdbRecordRefsReady($conn)) { echo "(skipped: run Database Verification - cmdb_object_properties.value_ref_id is missing)\n"; exit(0); }

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}
/** Runs $fn and returns the ServiceError message, or '' when it did not throw. */
function refused(callable $fn): string
{
    try { $fn(); return ''; } catch (ServiceError $e) { return $e->getMessage() ?: 'refused'; }
}

$tag = 'zz' . bin2hex(random_bytes(3));
$m = ['tenant' => 0, 'class' => 0, 'objects' => [], 'users' => [], 'locations' => [], 'team' => 0,
      'supplier' => 0, 'contracts' => [], 'sessions' => []];
$sessDir = rtrim((string)ini_get('session.save_path'), '/\\') ?: sys_get_temp_dir();
try {
    $conn->prepare("INSERT INTO tenants (name, is_default, is_active) VALUES (?, 0, 1)")->execute(["ZZ $tag Globex"]);
    $tB = $m['tenant'] = (int)$conn->lastInsertId();
    $tA = getDefaultTenantId($conn);
    check('the install is multi-company for the run', isMultiTenant($conn));

    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $all = new ActorContext($admin, null, 'ui', 'en', 'ZZ tester');      // reaches every company
    $onlyA = new ActorContext($admin, [$tA], 'ui', 'en', 'ZZ tester');  // reaches the Default company only

    // Records
    $u = $conn->prepare("INSERT INTO users (email, display_name, tenant_id) VALUES (?, ?, ?)");
    $u->execute(["a@{$tag}.example", "$tag Alice", $tA]); $userA = $m['users'][] = (int)$conn->lastInsertId();
    $u->execute(["b@{$tag}.example", "$tag Bob", $tB]);   $userB = $m['users'][] = (int)$conn->lastInsertId();
    $l = $conn->prepare("INSERT INTO asset_locations (name, parent_id, tenant_id, is_shared) VALUES (?, ?, ?, ?)");
    $l->execute(["$tag Site A", null, $tA, 0]);    $locA = $m['locations'][] = (int)$conn->lastInsertId();
    $l->execute(["$tag Floor 2", $locA, $tA, 0]);  $locA2 = $m['locations'][] = (int)$conn->lastInsertId();
    $l->execute(["$tag Site B", null, $tB, 0]);    $locB = $m['locations'][] = (int)$conn->lastInsertId();
    $l->execute(["$tag DC", null, null, 1]);       $locDc = $m['locations'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO teams (name, is_active) VALUES (?, 0)")->execute(["$tag Old team"]);
    $teamOld = $m['team'] = (int)$conn->lastInsertId();
    $team = (int)$conn->query("SELECT id FROM teams WHERE COALESCE(is_active,1) = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $conn->prepare("INSERT INTO suppliers (legal_name, trading_name, is_active) VALUES (?, ?, 1)")->execute(["$tag Acme Ltd", "$tag Acme"]);
    $supplier = $m['supplier'] = (int)$conn->lastInsertId();
    $c = $conn->prepare("INSERT INTO contracts (tenant_id, contract_number, title, is_active) VALUES (?, ?, ?, 1)");
    $c->execute([$tA, "$tag-1", "$tag Support A"]); $conA = $m['contracts'][] = (int)$conn->lastInsertId();
    $c->execute([$tB, "$tag-2", "$tag Support B"]); $conB = $m['contracts'][] = (int)$conn->lastInsertId();

    // A class with one property of each record type
    $conn->prepare("INSERT INTO cmdb_classes (class_key, name, is_active) VALUES (?, ?, 1)")->execute(["{$tag}_server", "$tag Server"]);
    $cls = $m['class'] = (int)$conn->lastInsertId();
    $pp = $conn->prepare("INSERT INTO cmdb_class_properties (class_id, property_key, label, property_type) VALUES (?, ?, ?, ?)");
    foreach (['person', 'analyst', 'team', 'location', 'company', 'supplier', 'contract'] as $t) {
        $pp->execute([$cls, $t, ucfirst($t), $t]);
    }
    $pid = fn(string $key) => (int)$conn->query("SELECT id FROM cmdb_class_properties WHERE class_id = $cls AND property_key = " . $conn->quote($key))->fetchColumn();

    echo "Saving, and the company rules\n";
    $ciA = $m['objects'][] = CmdbService::saveObject($conn, $all, ['class_id' => $cls, 'name' => "$tag WEB01", 'tenant_id' => $tA,
        'properties' => ['person' => $userA, 'analyst' => $admin, 'team' => $team, 'location' => $locA2,
                         'company' => $tB, 'supplier' => $supplier, 'contract' => $conA]])['id'];
    check('a CI saves with all seven kinds of record', $ciA > 0);
    $row = $conn->query("SELECT value_ref_id, value_object_id FROM cmdb_object_properties WHERE object_id = $ciA AND property_id = {$pid('person')}")->fetch(PDO::FETCH_ASSOC);
    check('the value is in value_ref_id, not value_object_id', (int)$row['value_ref_id'] === $userA && $row['value_object_id'] === null, json_encode($row));
    $tenantOfA = (int)($conn->query("SELECT COALESCE(tenant_id, $tA) FROM cmdb_objects WHERE id = $ciA")->fetchColumn());
    check('the CI is in the Default company', $tenantOfA === $tA);

    $upd = fn(ActorContext $ctx, array $props) => CmdbService::saveObject($conn, $ctx, ['id' => $ciA, 'properties' => $props]);
    check("another company's person is refused", refused(fn() => $upd($all, ['person' => $userB])) !== '');
    check("another company's location is refused", refused(fn() => $upd($all, ['location' => $locB])) !== '');
    check('a shared location is allowed', refused(fn() => $upd($all, ['location' => $locDc])) === '');
    check("another company's contract is refused", refused(fn() => $upd($all, ['contract' => $conB])) !== '');
    check('a company you can reach is allowed', refused(fn() => $upd($all, ['company' => $tB])) === '');
    check("a company you cannot reach is refused", refused(fn() => $upd($onlyA, ['company' => $tB, 'person' => $userA])) !== '');
    check('a record that does not exist is refused', refused(fn() => $upd($all, ['supplier' => 999999999])) !== '');
    check('an inactive team is refused as a new value', refused(fn() => $upd($all, ['team' => $teamOld])) !== '');
    $conn->prepare("UPDATE cmdb_object_properties SET value_ref_id = ? WHERE object_id = ? AND property_id = ?")->execute([$teamOld, $ciA, $pid('team')]);
    check('...but kept when it is the value already stored', refused(fn() => $upd($all, ['team' => $teamOld, 'person' => $userA])) === '');

    echo "Reading back\n";
    $loc = cmdbRecordResolve($conn, 'location', [$locA2], null);
    check('a location reads as its full path', ($loc[$locA2]['name'] ?? '') === "$tag Site A > $tag Floor 2", json_encode($loc));
    $t = cmdbRecordResolve($conn, 'team', [$teamOld], null);
    check('an inactive team reads as inactive', ($t[$teamOld]['state'] ?? '') === 'inactive');
    $co = cmdbRecordResolve($conn, 'company', [$tB], [$tA]);
    check("a company the viewer cannot reach is hidden, not named", ($co[$tB]['state'] ?? '') === 'hidden' && $co[$tB]['name'] === null, json_encode($co));
    $co = cmdbRecordResolve($conn, 'company', [$tB], null);
    check('...and named for someone who can reach it', ($co[$tB]['name'] ?? '') === "ZZ $tag Globex");
    $pe = cmdbRecordResolve($conn, 'person', [$userA], null);
    check('a person links to their People page', str_contains((string)($pe[$userA]['url'] ?? ''), 'people/person.php?id=' . $userA));

    echo "The picker\n";
    $names = fn(array $rows) => array_column($rows, 'name');
    $p = $names(cmdbRecordSearch($conn, 'person', $tag, $tA, null));
    check("offers the CI's own company's people", in_array("$tag Alice", $p, true) && !in_array("$tag Bob", $p, true), json_encode($p));
    $p = $names(cmdbRecordSearch($conn, 'location', $tag, $tB, null));
    check("a company B CI is offered B's sites and the shared one", in_array("$tag Site B", $p, true) && in_array("$tag DC", $p, true) && !in_array("$tag Site A", $p, true), json_encode($p));
    $p = $names(cmdbRecordSearch($conn, 'team', $tag, $tA, null));
    check('an inactive team is not offered', !in_array("$tag Old team", $p, true));
    $p = $names(cmdbRecordSearch($conn, 'company', 'ZZ ' . $tag, $tA, [$tA]));
    check('a company outside your reach is not offered', !in_array("ZZ $tag Globex", $p, true));

    echo "Deleting a record a CI points at\n";
    $refs = cmdbRecordReferences($conn, 'person', $userA, null);
    check('the delete summary names the CI', $refs['count'] === 1 && ($refs['items'][0]['name'] ?? '') === "$tag WEB01", json_encode($refs));
    $refs = cmdbRecordReferences($conn, 'person', $userA, [$tB]);
    check("...but only counts it for someone who cannot reach that company", $refs['count'] === 1 && $refs['hidden'] === 1 && !$refs['items'], json_encode($refs));
    $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$userA]);
    $pe = cmdbRecordResolve($conn, 'person', [$userA], null);
    check('the CI then shows the person as removed', ($pe[$userA]['state'] ?? '') === 'removed');
    $audit = cmdbAuditRemovedRecords($conn, $admin);
    $hit = array_filter($audit['items'], fn($i) => (int)$i['object_id'] === $ciA);
    check('Data quality lists it', $audit['count'] >= 1 && $hit, json_encode($audit['count']));

    echo "The analyst leaving checks\n";
    $ref = array_values(array_filter(analystReferenceRegistry(), fn($r) => $r[0] === 'cmdb_object_properties' && $r[1] === 'value_ref_id'))[0] ?? null;
    check('the analyst property is registered', $ref !== null && $ref[2] === 'setup');
    check('...and counts this CI for the admin', $ref && analystLcCount($conn, $ref, $admin) >= 1);
    $wrong = $ref ? analystLcCount($conn, $ref, $supplier) : -1;
    $supplierAsAnalyst = (int)$conn->query("SELECT COUNT(*) FROM cmdb_object_properties v JOIN cmdb_class_properties p ON p.id = v.property_id AND p.property_type = 'analyst' WHERE v.value_ref_id = $supplier")->fetchColumn();
    check("...and never counts another type's id (the supplier id is not read as an analyst)", $wrong === $supplierAsAnalyst);

    if ($base !== '') {
        echo "Over HTTP\n";
        $sid = $m['sessions'][] = 'zzrr' . bin2hex(random_bytes(6));
        $csrf = bin2hex(random_bytes(32));
        file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_name|s:5:"zztst";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');
        $http = function (string $path, ?array $post = null) use ($base, $sid, $csrf) {
            $ch = curl_init("$base/$path");
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid"];
            if ($post !== null) $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post), CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $csrf"]];
            curl_setopt_array($ch, $o);
            return json_decode((string)curl_exec($ch), true) ?: [];
        };
        $r = $http("api/cmdb/get_object.php?id=$ciA");
        $props = array_column($r['object']['properties'] ?? $r['properties'] ?? [], null, 'property_key');
        check('get_object returns the removed person as removed', ($props['person']['value_record']['state'] ?? '') === 'removed', json_encode($props['person'] ?? null));
        check('...the location as its path', ($props['location']['value_record']['name'] ?? '') === "$tag Site A > $tag Floor 2" || ($props['location']['value_record']['name'] ?? '') === "$tag DC", json_encode($props['location'] ?? null));
        check('...and spreads_impact is returned (the cog no longer clears it)', array_key_exists('spreads_impact', $props['person'] ?? []));
        $r = $http("api/cmdb/search_records.php?type=contract&object_id=$ciA&q=$tag");
        $n = array_column($r['results'] ?? [], 'name');
        check('the picker endpoint offers only this company\'s contracts', in_array("$tag Support A", $n, true) && !in_array("$tag Support B", $n, true), json_encode($r));
        $r = $http("api/cmdb/record_references.php?type=supplier&id=$supplier");
        check('the delete summary endpoint counts the CI', ($r['count'] ?? 0) === 1, json_encode($r));
        $r = $http('api/cmdb/save_class_property.php', ['id' => $pid('person'), 'class_id' => $cls, 'label' => 'Person', 'property_key' => 'person', 'property_type' => 'team']);
        check('a type change that would misread stored values is refused', empty($r['success']), json_encode($r));
        $r = $http('api/cmdb/save_class_property.php', ['id' => $pid('person'), 'class_id' => $cls, 'label' => 'Main contact', 'property_key' => 'person', 'property_type' => 'person']);
        check('...while a rename keeping the type is fine', !empty($r['success']), json_encode($r));
    }
} catch (Throwable $x) {
    check('no exception', false, get_class($x) . ': ' . $x->getMessage() . ' @ ' . basename($x->getFile()) . ':' . $x->getLine());
} finally {
    foreach ($m['sessions'] as $sid) @unlink("$sessDir/sess_$sid");
    foreach ($m['objects'] as $id) { $conn->exec("DELETE FROM cmdb_object_properties WHERE object_id = " . (int)$id); $conn->exec("DELETE FROM cmdb_objects WHERE id = " . (int)$id); }
    $conn->exec("DELETE FROM cmdb_objects WHERE name LIKE " . $conn->quote("$tag %"));
    if ($m['class']) { $conn->exec("DELETE FROM cmdb_class_properties WHERE class_id = " . (int)$m['class']); $conn->exec("DELETE FROM cmdb_classes WHERE id = " . (int)$m['class']); }
    if ($m['contracts']) $conn->exec("DELETE FROM contracts WHERE id IN (" . implode(',', array_map('intval', $m['contracts'])) . ")");
    if ($m['supplier']) $conn->exec("DELETE FROM suppliers WHERE id = " . (int)$m['supplier']);
    if ($m['team']) $conn->exec("DELETE FROM teams WHERE id = " . (int)$m['team']);
    foreach (array_reverse($m['locations']) as $id) $conn->exec("DELETE FROM asset_locations WHERE id = " . (int)$id);
    if ($m['users']) $conn->exec("DELETE FROM users WHERE id IN (" . implode(',', array_map('intval', $m['users'])) . ")");
    if ($m['tenant']) $conn->exec("DELETE FROM tenants WHERE id = " . (int)$m['tenant']);
    $left = 0;
    foreach (["cmdb_classes WHERE name LIKE " . $conn->quote("$tag %"), "users WHERE email LIKE " . $conn->quote("%@$tag.example"),
              "asset_locations WHERE name LIKE " . $conn->quote("$tag %"), "contracts WHERE title LIKE " . $conn->quote("$tag %"),
              "suppliers WHERE legal_name LIKE " . $conn->quote("$tag %"), "teams WHERE name LIKE " . $conn->quote("$tag %"),
              "tenants WHERE name LIKE " . $conn->quote("ZZ $tag%")] as $w) {
        $left += (int)$conn->query("SELECT COUNT(*) FROM $w")->fetchColumn();
    }
    check('cleaned up', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
