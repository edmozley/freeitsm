<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Forms - a cost centre lookup (GH #160 Part A).
 *
 *   FREEITSM_TEST_DATABASE=<a throwaway database> [FREEITSM_TEST_URL=http://localhost/<that install>] php tests/forms-lookup-cost-centre.php
 *
 *   - the search offers the requester's company's ACTIVE cost centres, labelled
 *     "code - name", found by code or by name; never another company's, never an
 *     inactive one;
 *   - a submitted answer is checked the same way, and stored with the record's own
 *     label, not the posted one;
 *   - "Start on the requester's own cost centre" starts on theirs - but not when it
 *     is inactive, in another company, or the tick is off;
 *   - the tick is kept only on a cost centre lookup;
 *   - over HTTP (with FREEITSM_TEST_URL): the portal's form carries the starting
 *     answer, and the portal search offers only the requester's company.
 *
 * Rows are named zz-... and removed at the end.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/forms.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the throwaway database config.php points at (this one is {$dbName}).\n";
    exit(0);
}
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');

$tag = 'zz-' . bin2hex(random_bytes(3));
$default = (int)getDefaultTenantId($conn);
$conn->prepare("INSERT INTO tenants (name, slug) VALUES (?, ?)")->execute([$tag . ' company', $tag]);
$other = (int)$conn->lastInsertId();
$made = ['cc' => [], 'users' => [], 'form' => 0, 'sessions' => []];

$cc = function (string $code, string $name, int $tenant, int $active = 1) use ($conn, &$made): int {
    $conn->prepare("INSERT INTO cost_centres (tenant_id, code, name, is_active) VALUES (?, ?, ?, ?)")->execute([$tenant, $code, $name, $active]);
    return $made['cc'][] = (int)$conn->lastInsertId();
};
$person = function (string $name, int $tenant, ?int $ccId) use ($conn, &$made, $tag): int {
    $conn->prepare("INSERT INTO users (email, display_name, tenant_id, cost_centre_id) VALUES (?, ?, ?, ?)")
         ->execute(["$name.$tag@example.test", "$name $tag", $tenant, $ccId]);
    return $made['users'][] = (int)$conn->lastInsertId();
};

try {
    $mine     = $cc($tag . '-0010', 'Logistics ' . $tag, $default);
    $mine2    = $cc($tag . '-0020', 'Finance ' . $tag, $default);
    $inactive = $cc($tag . '-0030', 'Old project ' . $tag, $default, 0);
    $theirs   = $cc($tag . '-0010X', 'Their logistics ' . $tag, $other);

    echo "\n1. The search\n";
    $hits = FormsService::lookupSearch($conn, 'cost_centre', $tag, [$default]);
    $labels = array_column($hits, 'label');
    check('the company\'s active cost centres are offered, labelled "code - name"',
        in_array("$tag-0010 - Logistics $tag", $labels, true) && in_array("$tag-0020 - Finance $tag", $labels, true), json_encode($labels));
    check('an inactive one is not offered', !in_array($inactive, array_column($hits, 'id'), true));
    check('another company\'s is not offered', !in_array($theirs, array_column($hits, 'id'), true));
    check('found by code', in_array($mine2, array_column(FormsService::lookupSearch($conn, 'cost_centre', "$tag-0020", [$default]), 'id'), true));
    check('found by name', in_array($mine, array_column(FormsService::lookupSearch($conn, 'cost_centre', "Logistics $tag", [$default]), 'id'), true));
    check('CONTROL: the other company finds its own', in_array($theirs, array_column(FormsService::lookupSearch($conn, 'cost_centre', $tag, [$other]), 'id'), true));

    echo "\n2. A submitted answer\n";
    check('its own company\'s active one is allowed', FormsService::lookupValueAllowed($conn, 'cost_centre', $mine, [$default]));
    check('an inactive one is refused', !FormsService::lookupValueAllowed($conn, 'cost_centre', $inactive, [$default]));
    check('another company\'s is refused', !FormsService::lookupValueAllowed($conn, 'cost_centre', $theirs, [$default]));

    $conn->prepare("INSERT INTO forms (title, is_active, is_portal_visible, created_date, modified_date) VALUES (?, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([$tag . ' cost centre form']);
    $formId = $made['form'] = (int)$conn->lastInsertId();
    $cfg = ['lookup_source' => 'cost_centre', 'portal_lookup' => true, 'lookup_start_own' => true];
    $conn->prepare("INSERT INTO form_fields (form_id, field_type, label, is_required, sort_order, config) VALUES (?, 'lookup', 'Cost centre', 0, 1, ?)")->execute([$formId, json_encode($cfg)]);
    $fieldId = (int)$conn->lastInsertId();
    $requester = $person('req', $default, $mine);
    try {
        $sub = FormsService::submitForm($conn, ActorContext::fromPortalUser('test'), $formId, [$fieldId => json_encode(['id' => $mine, 'label' => 'Forged'])], $requester);
        $q = $conn->prepare('SELECT field_value FROM form_submission_data WHERE submission_id = ? AND field_id = ?');
        $q->execute([(int)$sub, $fieldId]);
        $row = json_decode((string)$q->fetchColumn(), true);
        check('it is stored with the cost centre\'s own label, not the posted one', ($row['label'] ?? '') === "$tag-0010 - Logistics $tag", json_encode($row));
    } catch (ServiceError $e) {
        check('the portal submit accepts its own cost centre', false, $e->getMessage());
    }
    $refused = false;
    try { FormsService::submitForm($conn, ActorContext::fromPortalUser('test'), $formId, [$fieldId => json_encode(['id' => $theirs, 'label' => 'x'])], $requester); }
    catch (ServiceError $e) { $refused = true; }
    check('posting another company\'s cost centre is refused', $refused);

    echo "\n3. Start on the requester's own cost centre\n";
    $field = ['field_type' => 'lookup', 'config' => $cfg];
    $d = FormsService::lookupDefaultFor($conn, $field, $requester);
    check('it starts on theirs, labelled', ($d['id'] ?? 0) === $mine && ($d['label'] ?? '') === "$tag-0010 - Logistics $tag", json_encode($d));
    check('not when the tick is off', FormsService::lookupDefaultFor($conn, ['field_type' => 'lookup', 'config' => ['lookup_source' => 'cost_centre']], $requester) === null);
    check('not when their cost centre is inactive', FormsService::lookupDefaultFor($conn, $field, $person('old', $default, $inactive)) === null);
    check('not when their cost centre is another company\'s', FormsService::lookupDefaultFor($conn, $field, $person('odd', $default, $theirs)) === null);
    check('not when they have none', FormsService::lookupDefaultFor($conn, $field, $person('none', $default, null)) === null);
    check('not on another kind of lookup', FormsService::lookupDefaultFor($conn, ['field_type' => 'lookup', 'config' => ['lookup_source' => 'asset', 'lookup_start_own' => true]], $requester) === null);

    echo "\n4. Saving the tick\n";
    $ref = new ReflectionMethod('FormsService', 'validateFieldConfig');
    $keep = fn(array $c) => array_key_exists('lookup_start_own', (array)$ref->invoke(null, $c, 0, [], [], 'lookup'));
    check('kept on a cost centre lookup', $keep(['lookup_source' => 'cost_centre', 'lookup_start_own' => true]));
    check('dropped on any other lookup', !$keep(['lookup_source' => 'asset', 'lookup_start_own' => true]));

    if ($base !== '') {
        echo "\n5. Over HTTP, on the portal\n";
        $sessDir = rtrim(ini_get('session.save_path') ?: 'C:/wamp64/tmp', '/\\');
        $sid = 'zzcc' . bin2hex(random_bytes(6));
        file_put_contents("$sessDir/sess_$sid", 'ss_user_id|i:' . $requester . ';ss_user_name|s:4:"ZZCC";csrf_token|s:1:"x";');
        $made['sessions'][] = "$sessDir/sess_$sid";
        $get = function (string $path) use ($base, $sid) {
            $ch = curl_init("$base/$path");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid"]);
            return json_decode((string)curl_exec($ch), true) ?: [];
        };
        $f = $get("api/self-service/get_catalogue_form.php?id=$formId");
        $lf = null;
        foreach (($f['form']['fields'] ?? []) as $x) if ((int)$x['id'] === $fieldId) $lf = $x;
        check('the portal\'s form carries the starting answer', ($lf['lookup_default']['id'] ?? 0) === $mine, json_encode($lf['lookup_default'] ?? $f));
        $s = $get("api/forms/lookup_search.php?field_id=$fieldId&q=" . urlencode($tag));
        $ids = array_column($s['results'] ?? [], 'id');
        check('the portal search offers only the requester\'s company, active ones', in_array($mine, $ids, true) && !in_array($theirs, $ids, true) && !in_array($inactive, $ids, true), json_encode($s));
    }
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($made['form']) {
        $f = $made['form'];
        $conn->exec("DELETE d FROM form_submission_data d JOIN form_submissions s ON s.id = d.submission_id WHERE s.form_id = $f");
        foreach (['form_submissions', 'form_fields', 'form_versions'] as $t) { try { $conn->exec("DELETE FROM $t WHERE form_id = $f"); } catch (Throwable $e) {} }
        $conn->exec("DELETE FROM forms WHERE id = $f");
    }
    if ($made['users']) $conn->exec('DELETE FROM users WHERE id IN (' . implode(',', $made['users']) . ')');
    if ($made['cc']) $conn->exec('DELETE FROM cost_centres WHERE id IN (' . implode(',', $made['cc']) . ')');
    $conn->prepare('DELETE FROM tenants WHERE id = ?')->execute([$other]);
    foreach ($made['sessions'] as $s) @unlink($s);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM cost_centres WHERE code LIKE 'zz-%') + (SELECT COUNT(*) FROM tenants WHERE slug LIKE 'zz-%')")->fetchColumn();
    check('cleanup: nothing named zz- left', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
