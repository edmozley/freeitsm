<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * CMDB schema plans and Starter classes (GitHub #313 slice 3).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/cmdb-starter-classes.php
 *
 * Proves the plan engine (includes/cmdb/schema_plan.php) that Starter classes and,
 * later, the CMDB AI assistant use:
 *   - the starter plan adds what is missing; a second run adds nothing;
 *   - a class already there as "Servers" is reused and gets only the new properties;
 *   - a property already there with another type is a conflict and left alone;
 *   - a relationship type whose verb is an existing INVERSE verb counts as there;
 *   - a person-like class, a dangling object reference and an empty dropdown are refused;
 *   - record-type properties are refused before Database Verification;
 *   - a failure part-way rolls everything back;
 *   - without the relationship-types capability those lines are skipped, the rest applied;
 *   - over HTTP: GET writes nothing, POST without the CSRF token is refused, an
 *     analyst without the CMDB is refused;
 *   - in headless Chrome: the settings page opens the preview, unticking a class
 *     disables what needs it, and Apply adds the ticked lines.
 * Everything made is removed by id in finally (rows the starter plan adds to a class
 * that was already there are removed by their own ids, the class left as it was).
 *
 * @wiki CMDB-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/cmdb/schema_plan.php';
require_once __DIR__ . '/../includes/cmdb/starter_classes.php';

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
/** Every line of a resolved plan, by line id. */
function planLines(array $r): array
{
    $out = [];
    foreach ($r['relationship_types'] as $l) $out[$l['line']] = $l;
    foreach ($r['classes'] as $c) { $out[$c['line']] = $c; foreach ($c['properties'] as $p) $out[$p['line']] = $p; }
    return $out;
}
/** The line ids a preview would tick: every 'create'. */
function creatable(array $r): array
{
    return array_keys(array_filter(planLines($r), fn($l) => $l['action'] === 'create'));
}

$tag = 'zz' . bin2hex(random_bytes(3));
$TAG = ucfirst($tag);
$made = ['classes' => [], 'properties' => [], 'relationship_types' => [], 'analysts' => [], 'role' => 0, 'sessions' => [], 'frame' => ''];
$keep = function (array $res) use (&$made) { foreach ($res['created'] as $k => $ids) $made[$k] = array_merge($made[$k], $ids); };
$sessDir = rtrim((string)ini_get('session.save_path'), '/\\') ?: sys_get_temp_dir();
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ tester');
$counts = fn() => $conn->query("SELECT (SELECT COUNT(*) FROM cmdb_classes), (SELECT COUNT(*) FROM cmdb_class_properties), (SELECT COUNT(*) FROM cmdb_relationship_types), (SELECT COUNT(*) FROM cmdb_class_property_options)")->fetch(PDO::FETCH_NUM);

try {
    echo "A ZZ plan against a ZZ class already there\n";
    $conn->prepare("INSERT INTO cmdb_classes (class_key, name, is_active) VALUES (?, ?, 1)")->execute(["{$tag}_servers", "$TAG Servers"]);
    $servers = $made['classes'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO cmdb_class_properties (class_id, property_key, label, property_type) VALUES (?, 'role', 'Role', 'number')")->execute([$servers]);
    $made['properties'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO cmdb_relationship_types (verb, inverse_verb, impact_direction) VALUES (?, ?, 'to_from')")->execute(["$tag is cooled by", "$tag cools"]);
    $made['relationship_types'][] = (int)$conn->lastInsertId();

    $plan = [
        'title' => 'ZZ',
        'relationship_types' => [
            ['verb' => "$tag cools", 'inverse_verb' => "$tag is chilled by", 'impact_direction' => 'from_to'],   // its verb is an existing inverse
            ['verb' => "$tag hosts", 'inverse_verb' => "$tag is hosted on", 'impact_direction' => 'from_to'],
        ],
        'classes' => [
            ['key' => "{$tag}_server", 'name' => "$TAG Server", 'properties' => [
                ['label' => 'Role', 'type' => 'text'],                                 // there as number
                ['label' => 'Rack', 'type' => 'text'],
            ]],
            ['key' => "{$tag}_vm", 'name' => "$TAG VM", 'properties' => [
                ['label' => 'Host', 'type' => 'object_ref', 'target' => "{$tag}_server", 'spreads_impact' => true],
                ['label' => 'Cluster', 'type' => 'object_ref', 'target' => "$TAG Nowhere"],
                ['label' => 'Owner', 'type' => 'object_ref', 'target' => 'Person'],
                ['label' => 'Tier', 'type' => 'dropdown', 'options' => []],
                ['label' => 'Size', 'type' => 'dropdown', 'options' => ['S', 'M', ['value' => 'L'], 'S']],
                ['label' => 'Team', 'type' => 'team'],
            ]],
            ['key' => "{$tag}_person", 'name' => 'People'],
        ],
        'usage' => [['from' => "{$tag}_vm", 'verb' => "$tag hosts", 'to' => "{$tag}_server"]],
    ];
    $before = $counts();
    $r = cmdbPlanResolve($conn, $plan);
    $L = planLines($r);
    check('resolving writes nothing', $counts() === $before);
    check('"Servers" already there is reused for "Server"', $L["class:{$tag}_server"]['action'] === 'exists' && $L["class:{$tag}_server"]['id'] === $servers, json_encode($L["class:{$tag}_server"]['action']));
    check('a property there with another type is a conflict', $L["prop:{$tag}_server:role"]['action'] === 'conflict' && stripos($L["prop:{$tag}_server:role"]['reason'], 'number') !== false, $L["prop:{$tag}_server:role"]['reason']);
    check('a new property on that class is to be created', $L["prop:{$tag}_server:rack"]['action'] === 'create');
    check('a verb that is an existing inverse verb counts as there', $L['rt:0']['action'] === 'exists', $L['rt:0']['action']);
    check('a new relationship type is to be created', $L['rt:1']['action'] === 'create');
    check('an object reference to a class in the plan (there already) resolves', $L["prop:{$tag}_vm:host"]['action'] === 'create' && $L["prop:{$tag}_vm:host"]['target_id'] === $servers);
    check('an object reference to nothing is refused', $L["prop:{$tag}_vm:cluster"]['action'] === 'invalid');
    check('an object reference to "Person" says use the record type', $L["prop:{$tag}_vm:owner"]['action'] === 'invalid' && strpos($L["prop:{$tag}_vm:owner"]['reason'], 'Person') !== false, $L["prop:{$tag}_vm:owner"]['reason']);
    check('a dropdown without options is refused', $L["prop:{$tag}_vm:tier"]['action'] === 'invalid');
    check('dropdown options are de-duplicated', $L["prop:{$tag}_vm:size"]['options'] === ['S', 'M', 'L'], json_encode($L["prop:{$tag}_vm:size"]['options']));
    check('a class named like a record ("People") is refused', $L["class:{$tag}_person"]['action'] === 'invalid', $L["class:{$tag}_person"]['reason']);
    check('the usage notes come back for display', count($r['usage']) === 1);
    $nr = planLines(cmdbPlanResolve($conn, $plan, ['records_ready' => false]));
    check('before Database Verification a Team property is refused, saying so', $nr["prop:{$tag}_vm:team"]['action'] === 'invalid' && strpos($nr["prop:{$tag}_vm:team"]['reason'], 'Database Verification') !== false);
    check('...while a text property is still fine', $nr["prop:{$tag}_server:rack"]['action'] === 'create');

    echo "Caps\n";
    $big = ['classes' => array_map(fn($i) => ['name' => "$TAG C$i", 'properties' => array_map(fn($j) => ['label' => "P$j"], range(1, 16))], range(1, 13)),
            'relationship_types' => array_map(fn($i) => ['verb' => "$tag v$i", 'inverse_verb' => "$tag iv$i"], range(1, 7))];
    $bl = cmdbPlanResolve($conn, $big);
    check('the 13th class is refused (at most 12)', $bl['classes'][12]['action'] === 'invalid' && $bl['classes'][11]['action'] === 'create');
    check('the 16th property is refused (at most 15)', $bl['classes'][0]['properties'][15]['action'] === 'invalid' && $bl['classes'][0]['properties'][14]['action'] === 'create');
    check('the 7th relationship type is refused (at most 6)', $bl['relationship_types'][6]['action'] === 'invalid' && $bl['relationship_types'][5]['action'] === 'create');

    echo "A failure part-way rolls everything back\n";
    $before = $counts();
    $n = 0; $threw = false;
    try {
        cmdbPlanApply($conn, $ctx, $plan, creatable($r), ['on_item' => function ($kind, $line) use (&$n) {
            if (++$n === 3) throw new ServiceError('validation', 'invalid_field', 'ZZ injected failure');
        }]);
    } catch (ServiceError $e) { $threw = $e->getMessage() === 'ZZ injected failure'; }
    check('the failure comes back to the caller', $threw);
    check('...and nothing was created (relationship type, class or property)', $counts() === $before, json_encode([$before, $counts()]));

    echo "Without the relationship-types capability\n";
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules) VALUES (?, 'x', ?, ?, 1, 0, 1)")
         ->execute(["{$tag}_cls", "ZZ $tag classes only", "{$tag}cls@example.invalid"]);
    $clsOnly = $made['analysts'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO rbac_roles (name, description, is_active) VALUES (?, 'temporary', 1)")->execute(["ZZ $tag cmdb classes"]);
    $made['role'] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO rbac_role_capabilities (role_id, capability_key) VALUES (?, ?)")->execute([$made['role'], capKey(Cap::CMDB_CLASSES)]);
    $conn->prepare("INSERT INTO rbac_analyst_roles (analyst_id, role_id) VALUES (?, ?)")->execute([$clsOnly, $made['role']]);
    $res = cmdbPlanApply($conn, new ActorContext($clsOnly, null, 'ui', 'en', 'ZZ'), $plan, creatable($r));
    $keep($res);
    $skippedLines = array_column($res['skipped'], 'reason', 'line');
    check('the relationship-type line is skipped as not permitted', isset($skippedLines['rt:1']) && $res['counts']['relationship_types'] === 0, json_encode($res['skipped']));
    check('...the classes and properties are still added', $res['counts']['classes'] === 1 && $res['counts']['properties'] === 4, json_encode($res['counts']));
    $vm = (int)$conn->query("SELECT id FROM cmdb_classes WHERE class_key = " . $conn->quote("{$tag}_vm"))->fetchColumn();
    $host = $conn->query("SELECT target_class_id, spreads_impact FROM cmdb_class_properties WHERE class_id = $vm AND property_key = 'host'")->fetch(PDO::FETCH_ASSOC);
    check('the object reference points at the reused class and spreads impact', (int)$host['target_class_id'] === $servers && (int)$host['spreads_impact'] === 1, json_encode($host));
    check('the conflicting property is left as it was', $conn->query("SELECT property_type FROM cmdb_class_properties WHERE class_id = $servers AND property_key = 'role'")->fetchColumn() === 'number');
    $res = cmdbPlanApply($conn, $ctx, $plan, creatable($r));
    $keep($res);
    check('an admin then adds only the relationship type', $res['counts'] === ['relationship_types' => 1, 'classes' => 0, 'properties' => 0], json_encode($res['counts']));

    echo "Unticked lines\n";
    $p2 = ['classes' => [['key' => "{$tag}_a", 'name' => "$TAG Aa", 'properties' => [['label' => 'X']]],
                         ['key' => "{$tag}_b", 'name' => "$TAG Bb", 'properties' => [['label' => 'Link', 'type' => 'object_ref', 'target' => "{$tag}_a"]]]]];
    $res = cmdbPlanApply($conn, $ctx, $p2, ["prop:{$tag}_a:x", "class:{$tag}_b", "prop:{$tag}_b:link"]);
    $keep($res);
    check('a property whose new class or target is unticked is skipped, not created', $res['counts'] === ['relationship_types' => 0, 'classes' => 1, 'properties' => 0] && count($res['skipped']) === 2, json_encode($res));

    if ($base !== '') {
        echo "Over HTTP\n";
        $sid = $made['sessions'][] = 'zzsc' . bin2hex(random_bytes(6));
        $csrf = bin2hex(random_bytes(32));
        file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_name|s:5:"zztst";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules) VALUES (?, 'x', ?, ?, 1, 0, 0)")
             ->execute(["{$tag}_nocmdb", "ZZ $tag no CMDB", "{$tag}no@example.invalid"]);
        $noCmdb = $made['analysts'][] = (int)$conn->lastInsertId();
        $sid2 = $made['sessions'][] = 'zzsc' . bin2hex(random_bytes(6));
        file_put_contents("$sessDir/sess_$sid2", 'analyst_id|i:' . $noCmdb . ';analyst_name|s:5:"zztst";csrf_token|s:64:"' . $csrf . '";');
        $http = function (string $s, string $path, ?array $post = null, bool $token = true) use ($base, $csrf): array {
            $ch = curl_init("$base/$path");
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$s"];
            if ($post !== null) $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post),
                CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $token ? ["X-CSRF-Token: $csrf"] : [])];
            curl_setopt_array($ch, $o);
            $body = (string)curl_exec($ch);
            return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($body, true) ?: ['raw' => substr($body, 0, 300)]];
        };
        $before = $counts();
        [$code, $j] = $http($sid, 'api/cmdb/schema_plan.php?plan=starter');
        check('GET returns the resolved starter plan', $code === 200 && !empty($j['success']) && count($j['resolved']['classes'] ?? []) === 9, json_encode(array_slice($j, 0, 2)));
        check('...and writes nothing', $counts() === $before);
        [$code, $j] = $http($sid, 'api/cmdb/schema_plan.php', ['plan' => 'starter', 'selection' => ['class:subscription']], false);
        check('POST without the CSRF token is refused', empty($j['success']) && $code >= 400, "$code " . json_encode($j));
        check('...and writes nothing', $counts() === $before);
        [$code, $j] = $http($sid2, 'api/cmdb/schema_plan.php?plan=starter');
        check('an analyst without the CMDB is refused', $code === 403 && empty($j['success']), "$code " . json_encode($j));
        [$code, $j] = $http($sid, 'api/cmdb/schema_plan.php', ['plan' => 'nope']);
        check('an unknown plan is refused', empty($j['success']) && $counts() === $before);
    }

    echo "The starter plan\n";
    $sp = cmdbStarterPlan();
    check('nine classes and two relationship types', count($sp['classes']) === 9 && count($sp['relationship_types']) === 2);
    $sr = cmdbPlanResolve($conn, $sp);
    check('every line is valid on this install', $sr['counts']['invalid'] === 0, json_encode(array_values(array_filter(array_map(fn($l) => $l['action'] === 'invalid' ? $l['line'] . ': ' . $l['reason'] : null, planLines($sr))))));
    $toCreate = creatable($sr);
    $res = cmdbPlanApply($conn, $ctx, $sp, array_keys(planLines($sr)));
    $keep($res);
    $n = array_sum($res['counts']);
    check("the first run creates what was missing ($n)", $n === count($toCreate) && $n > 0, count($toCreate) . ' expected');
    check('...and the existing lines count as there', $res['existing'] === $sr['counts']['exists'], $res['existing'] . ' vs ' . $sr['counts']['exists']);
    $res2 = cmdbPlanApply($conn, $ctx, $sp, array_keys(planLines($sr)));
    $keep($res2);
    check('a second run creates nothing', array_sum($res2['counts']) === 0, json_encode($res2['counts']));
    check('...and resolves as all there', creatable(cmdbPlanResolve($conn, $sp)) === []);
    $vmCls = (int)$conn->query("SELECT id FROM cmdb_classes WHERE class_key = 'virtual_machine'")->fetchColumn();
    $host = $conn->query("SELECT p.spreads_impact, c.class_key FROM cmdb_class_properties p JOIN cmdb_classes c ON c.id = p.target_class_id WHERE p.class_id = $vmCls AND p.property_key = 'host'")->fetch(PDO::FETCH_ASSOC);
    check('Virtual machine -> Host points at a Server class as a dependency', $host && (int)$host['spreads_impact'] === 1, json_encode($host));
    $rt = $conn->query("SELECT impact_direction FROM cmdb_relationship_types WHERE verb = 'runs on' OR inverse_verb = 'runs on'")->fetchColumn();
    check('"runs on" exists with an impact direction', in_array($rt, ['to_from', 'from_to'], true), (string)$rt);

    // Headless: the settings page, the preview, unticking, Apply.
    $chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
    foreach (['/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $alt) if (!is_file($chrome) && is_file($alt)) $chrome = $alt;
    if ($base !== '' && is_file($chrome)) {
        echo "In headless Chrome\n";
        // Take the starter Subscription class back out (it is ours) so the preview has something to add.
        $sub = (int)$conn->query("SELECT id FROM cmdb_classes WHERE class_key = 'subscription'")->fetchColumn();
        $ours = $sub && in_array($sub, $made['classes'], true);
        if ($ours) {
            $pids = array_map('intval', $conn->query("SELECT id FROM cmdb_class_properties WHERE class_id = $sub")->fetchAll(PDO::FETCH_COLUMN));
            if ($pids) $conn->exec("DELETE FROM cmdb_class_property_options WHERE property_id IN (" . implode(',', $pids) . ")");
            $conn->exec("DELETE FROM cmdb_class_properties WHERE class_id = $sub");
            $conn->exec("DELETE FROM cmdb_classes WHERE id = $sub");
        }
        $root = dirname(__DIR__);
        $made['frame'] = $frame = $root . '/zz_sc_' . bin2hex(random_bytes(3)) . '.php';
        $sid = $made['sessions'][] = 'zzsc' . bin2hex(random_bytes(6));
        file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_name|s:5:"zztst";is_admin|i:1;csrf_token|s:64:"' . bin2hex(random_bytes(32)) . '";');
        $js = <<<'JS'
const f=document.getElementById('f');const out=o=>{document.getElementById('out').textContent=JSON.stringify(o)};
f.addEventListener('load',()=>{const w=f.contentWindow,d=f.contentDocument;const o={};
 setTimeout(()=>{try{o.parsed=typeof w.CmdbSchemaPreview==='object'&&typeof w.openStarterClasses==='function';o.btn=!!d.getElementById('starterClassesBtn');w.openStarterClasses();}catch(e){o.err=String(e);out(o);return;}
 setTimeout(()=>{try{const m=d.getElementById('cmdbSchemaPreview');o.open=!!(m&&m.classList.contains('active'));
   const boxes=[...m.querySelectorAll('input[type=checkbox][data-line]')];o.boxes=boxes.length;o.lines=m.querySelectorAll('.csp-line').length;
   o.unresolved=(m.innerText.match(/cmdb\.[a-z_.]+/g)||[]);
   const cls=boxes.find(b=>b.dataset.line==='class:subscription');o.hasSub=!!cls;
   if(cls){cls.checked=false;cls.dispatchEvent(new Event('change',{bubbles:true}));
     const dep=boxes.filter(b=>(b.dataset.deps||'').split(' ').includes('class:subscription'));o.depsDisabled=dep.length>0&&dep.every(b=>b.disabled&&!b.checked);
     cls.checked=true;cls.dispatchEvent(new Event('change',{bubbles:true}));o.depsBack=dep.every(b=>!b.disabled&&b.checked);}
   boxes.forEach(b=>{if(b.dataset.line!=='class:subscription'&&!(b.dataset.deps||'').includes('class:subscription')){b.checked=false;}});
   m.dispatchEvent(new Event('change'));
   o.ticked=boxes.filter(b=>b.checked).length;
   m.querySelector('[data-csp=apply]').click();}catch(e){o.err=String(e);out(o);return;}
  setTimeout(()=>{o.closed=!d.getElementById('cmdbSchemaPreview')||!d.getElementById('cmdbSchemaPreview').classList.contains('active');
   o.table=d.getElementById('classesTableBody').innerText.indexOf('Subscription')>=0;out(o);},3000);},3000);},2000);});
JS;
        file_put_contents($frame, "<?php setcookie('PHPSESSID', '$sid', 0, '/'); ?><!doctype html><html><body style=\"margin:0\"><pre id=\"out\">waiting</pre>"
            . "<iframe id=\"f\" src=\"cmdb/settings/\" style=\"width:1400px;height:900px;border:0\"></iframe><script>$js</script></body></html>");
        $dump = (string)shell_exec('"' . $chrome . '" --headless=new --disable-gpu --window-size=1500,1000 --user-data-dir="' . sys_get_temp_dir() . '/zz_sc_chrome_' . getmypid() . '" --virtual-time-budget=20000 --dump-dom "' . $base . '/' . basename($frame) . '" 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
        $j = preg_match('#<pre id="out">(.*?)</pre>#s', $dump, $mm) ? (json_decode(html_entity_decode($mm[1]), true) ?: []) : [];
        check('the page loaded both scripts and shows the Starter classes button', !empty($j['parsed']) && !empty($j['btn']), json_encode($j));
        check('the preview opens with lines to tick', !empty($j['open']) && ($j['boxes'] ?? 0) > 0 && ($j['lines'] ?? 0) > ($j['boxes'] ?? 0), json_encode($j));
        check('no unresolved string keys in the preview', isset($j['unresolved']) && !$j['unresolved'], json_encode($j['unresolved'] ?? null));
        if ($ours) {
            check('unticking a new class disables its properties', !empty($j['depsDisabled']), json_encode($j));
            check('...and ticking it again brings them back', !empty($j['depsBack']));
            $sub = (int)$conn->query("SELECT id FROM cmdb_classes WHERE class_key = 'subscription'")->fetchColumn();
            if ($sub) $made['classes'][] = $sub;
            foreach ($conn->query("SELECT id FROM cmdb_class_properties WHERE class_id = " . (int)$sub)->fetchAll(PDO::FETCH_COLUMN) as $pid) $made['properties'][] = (int)$pid;
            check('Apply added the ticked class and its five properties', $sub > 0 && (int)$conn->query("SELECT COUNT(*) FROM cmdb_class_properties WHERE class_id = $sub")->fetchColumn() === 5);
            check('...closed the preview and refreshed the Classes table', !empty($j['closed']) && !empty($j['table']), json_encode($j));
        }
    } else {
        echo "(headless part skipped: no Chrome or no FREEITSM_TEST_URL)\n";
    }
} catch (Throwable $x) {
    check('no exception', false, get_class($x) . ': ' . $x->getMessage() . ' @ ' . basename($x->getFile()) . ':' . $x->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    if ($made['frame']) @unlink($made['frame']);
    foreach ($made['sessions'] as $sid) @unlink("$sessDir/sess_$sid");
    // Properties first (some sit on classes that were already there), then classes, then verbs.
    $classIds = array_unique(array_map('intval', array_merge($made['classes'],
        $conn->query("SELECT id FROM cmdb_classes WHERE class_key LIKE " . $conn->quote("{$tag}%"))->fetchAll(PDO::FETCH_COLUMN))));
    $propIds = array_map('intval', $made['properties']);
    if ($classIds) $propIds = array_merge($propIds, array_map('intval', $conn->query("SELECT id FROM cmdb_class_properties WHERE class_id IN (" . implode(',', $classIds) . ")")->fetchAll(PDO::FETCH_COLUMN)));
    $propIds = array_unique($propIds);
    if ($propIds) {
        $in = implode(',', $propIds);
        $conn->exec("DELETE FROM cmdb_class_property_options WHERE property_id IN ($in)");
        $conn->exec("DELETE FROM cmdb_class_properties WHERE id IN ($in)");
    }
    if ($classIds) $conn->exec("DELETE FROM cmdb_classes WHERE id IN (" . implode(',', $classIds) . ")");
    if ($made['relationship_types']) $conn->exec("DELETE FROM cmdb_relationship_types WHERE id IN (" . implode(',', array_map('intval', $made['relationship_types'])) . ")");
    $conn->exec("DELETE FROM cmdb_relationship_types WHERE verb LIKE " . $conn->quote("$tag %"));
    if ($made['role']) {
        $conn->exec("DELETE FROM rbac_analyst_roles WHERE role_id = " . (int)$made['role']);
        $conn->exec("DELETE FROM rbac_role_capabilities WHERE role_id = " . (int)$made['role']);
        $conn->exec("DELETE FROM rbac_roles WHERE id = " . (int)$made['role']);
    }
    foreach ($made['analysts'] as $id) $conn->exec("DELETE FROM analysts WHERE id = " . (int)$id . " AND username LIKE " . $conn->quote("$tag%"));
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM cmdb_classes WHERE class_key LIKE " . $conn->quote("$tag%") . ")
        + (SELECT COUNT(*) FROM cmdb_relationship_types WHERE verb LIKE " . $conn->quote("$tag %") . ")
        + (SELECT COUNT(*) FROM analysts WHERE username LIKE " . $conn->quote("$tag%") . ")")->fetchColumn();
    check('cleaned up', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
