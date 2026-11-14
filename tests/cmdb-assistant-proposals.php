<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The CMDB assistant's proposals (GH #313 slices 6 and 7; includes/cmdb/assistant_proposals.php,
 * the preview / apply actions of api/cmdb/assistant.php, the Review dialogs).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/cmdb-assistant-proposals.php
 *
 * Against the stand-in OpenAI-compatible server (tests/ai-compatible/mock.php, model
 * 'script': a "CALL tool {json}" line in a message is called as a tool):
 *   - propose_schema: a card; the preview re-resolves against the live CMDB; apply
 *     creates the ticked lines only, and the plan's links become convention facts; the
 *     same plan again creates nothing new; a plan with nothing to create is refused and
 *     the reason goes back to the model; relationship-type lines without that capability
 *     are skipped; an injected failure rolls everything back (failed, then retried);
 *     dismiss;
 *   - propose_objects: always in the active company (a ZZ second company, with a
 *     positive control); apply creates CIs with typed properties, a parent and a
 *     relationship; one already there is skipped; a bad property is refused; an
 *     injected failure rolls back; refused without CMDB module access; another
 *     company's proposal cannot be applied; a CI in an old "Person" class is refused
 *     (people are records a CI points at), with an ordinary class beside it as control;
 *   - over HTTP: the preview GET writes nothing, POST without the CSRF token is refused,
 *     apply and dismiss work;
 *   - headless Chrome: the card's Review opens each preview (screenshots kept for a
 *     person to look at), Apply creates and the card says Applied; then the Continue
 *     chip comes first, sends "carry on", the model's history says "applied", and the
 *     chip goes.
 * Everything made is removed by id in finally; the CMDB AI settings are put back exactly.
 *
 * @wiki CMDB-Developer-Guide
 */
chdir(__DIR__ . '/..');
$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
$shots = rtrim((string)getenv('FREEITSM_SHOT_DIR'), '/\\');   // where the preview screenshots go (optional)
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/i18n.php';
require_once 'includes/rbac.php';
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
$LOG = __DIR__ . '/ai-compatible/last-request.json';

$tag = 'zz' . bin2hex(random_bytes(3));
$made = ['tenant' => 0, 'analysts' => [], 'roles' => [], 'sessions' => [], 'frames' => []];
$sessDir = rtrim((string)ini_get('session.save_path'), '/\\') ?: sys_get_temp_dir();
$firstFact = 0;

$conn->prepare("INSERT INTO tenants (name, slug, is_default, is_active) VALUES (?, ?, 0, 1)")->execute(["ZZ $tag Co", $tag]);
$made['tenant'] = $zz = (int)$conn->lastInsertId();

require_once 'includes/cmdb/assistant.php';
$keys = aiSettingsKeys('cmdb_ai');
$place = implode(',', array_fill(0, count($keys), '?'));
$st = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($place)");
$st->execute(array_values($keys));
$snapshot = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$port = 19400 + random_int(0, 299);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", 'tests/ai-compatible/mock.php'],
    [1 => ['file', sys_get_temp_dir() . "/cmdb-prop-$port.out", 'w'], 2 => ['file', sys_get_temp_dir() . "/cmdb-prop-$port.err", 'w']], $pipes, getcwd());

/** A role with these capabilities. */
$role = function (array $caps) use ($conn, $tag, &$made) {
    $conn->prepare("INSERT INTO rbac_roles (name, description, is_active) VALUES (?, 'temporary', 1)")->execute(["ZZ $tag role " . count($made['roles'])]);
    $id = $made['roles'][] = (int)$conn->lastInsertId();
    foreach ($caps as $c) $conn->prepare("INSERT INTO rbac_role_capabilities (role_id, capability_key) VALUES (?, ?)")->execute([$id, capKey($c)]);
    return $id;
};
/** A ZZ analyst with a role; $modules false = no module access at all. */
$analyst = function (string $who, int $roleId, bool $modules = true) use ($conn, $tag, &$made) {
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, 'x', ?, ?, 1, 0, ?, 1)")
         ->execute(["{$tag}_$who", "ZZ $tag $who", "{$tag}$who@example.invalid", $modules ? 1 : 0]);
    $id = $made['analysts'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO rbac_analyst_roles (analyst_id, role_id) VALUES (?, ?)")->execute([$id, $roleId]);
    return $id;
};
/** In-process: act as an analyst in a company. */
$as = function (int $id, int $activeTenant) use ($conn): array {
    $_SESSION = ['analyst_id' => $id, 'analyst_name' => 'ZZ', 'is_admin' => 0, 'active_tenant_id' => $activeTenant];
    return [new ActorContext($id, null, 'ui', 'en', 'ZZ'), cmdbAssistCompany($conn, $id)];
};
/** One turn that calls a tool; the assistant message. */
$call = function (ActorContext $ctx, array $co, string $tool, array $args) use ($conn): array {
    $r = cmdbAssistTurn($conn, $ctx, $co, 'chat', "Please.\nCALL $tool " . json_encode($args));
    return end($r['messages']);
};
$one = function (string $sql, array $p = []) use ($conn) { $s = $conn->prepare($sql); $s->execute($p); return $s->fetchColumn(); };
$classId = fn(string $key) => (int)$one("SELECT id FROM cmdb_classes WHERE class_key = ?", [$key]);
$propIds = fn(int $cid) => array_map('intval', $conn->query("SELECT id FROM cmdb_class_properties WHERE class_id = $cid")->fetchAll(PDO::FETCH_COLUMN));
$stored = function (int $msgId) use ($one) { return json_decode((string)$one("SELECT proposals FROM cmdb_ai_messages WHERE id = ?", [$msgId]), true) ?: []; };
$ciCount = fn() => (int)$one("SELECT COUNT(*) FROM cmdb_objects WHERE name LIKE ?", ["$tag-%"]);
$ci = function (string $name) use ($conn) { $s = $conn->prepare("SELECT * FROM cmdb_objects WHERE name = ?"); $s->execute([$name]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; };
$val = function (int $obj, string $key) use ($conn) {
    $s = $conn->prepare("SELECT v.* FROM cmdb_object_properties v JOIN cmdb_class_properties p ON p.id = v.property_id WHERE v.object_id = ? AND p.property_key = ?");
    $s->execute([$obj, $key]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
};

$SVC = "{$tag}_svc"; $HOST = "{$tag}_host"; $VERB = "$tag hosts"; $INV = "$tag hosted by";
$plan = [
    'title' => "$tag services",
    'relationship_types' => [['verb' => $VERB, 'inverse_verb' => $INV, 'impact_direction' => 'from_to', 'why' => 'A host failing takes its services down']],
    'classes' => [
        ['key' => $SVC, 'name' => "ZZ $tag Service", 'why' => 'What the business relies on', 'properties' => [
            ['label' => 'Tier', 'key' => 'tier', 'type' => 'dropdown', 'options' => ['Gold', 'Silver'], 'required' => true],
            ['label' => 'Owner', 'key' => 'owner', 'type' => 'person'],
            ['label' => 'Runs on', 'key' => 'runs_on', 'type' => 'object_ref', 'target' => $HOST, 'spreads_impact' => true],
            ['label' => 'Notes', 'key' => 'notes', 'type' => 'text'],
        ]],
        ['key' => $HOST, 'name' => "ZZ $tag Host", 'properties' => [
            ['label' => 'Operating system', 'key' => 'os', 'type' => 'text'],
            ['label' => 'Memory GB', 'key' => 'ram_gb', 'type' => 'number'],
            ['label' => 'Bought', 'key' => 'bought', 'type' => 'date'],
            ['label' => 'Live', 'key' => 'live', 'type' => 'boolean'],
        ]],
    ],
    'usage' => [['from' => $SVC, 'verb' => $INV, 'to' => $HOST]],
    'parent_child' => [['parent' => $HOST, 'child' => $SVC]],
];

try {
    if (!cmdbAssistReady($conn)) { check('the assistant\'s tables exist (run Database Verification)', false); throw new RuntimeException('not ready'); }
    for ($i = 0; $i < 40 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
    check('the stand-in server started', (bool)@fsockopen('127.0.0.1', $port));
    @unlink($LOG);
    aiSettingsSave($conn, 'cmdb_ai', ['provider' => 'openai_compatible', 'model' => 'script', 'api_key' => '', 'clear_api_key' => true, 'compatible_base_url' => "http://127.0.0.1:$port/v1"]);
    $firstFact = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM cmdb_ai_facts")->fetchColumn();
    $def = getDefaultTenantId($conn);

    $rFull = $role([Cap::CMDB_CLASSES, Cap::CMDB_RELATIONSHIP_TYPES]);
    $rCls = $role([Cap::CMDB_CLASSES]);
    $A = $analyst('a', $rFull);
    $R = $analyst('cls', $rCls);
    $N = $analyst('nomod', $rFull, false);
    $H = $analyst('hl', $rFull);

    // ================================================================ schema
    echo "propose_schema\n";
    [$ctxA, $coA] = $as($A, $def);
    $m = $call($ctxA, $coA, 'propose_schema', $plan);
    $p = $m['proposals'][0] ?? [];
    check('a proposal is stored on the message, pending', ($p['type'] ?? '') === 'schema' && ($p['status'] ?? '') === 'pending', json_encode($m['proposals']));
    check('...its card line counts what it would add', ($p['summary'] ?? '') === "$tag services: 2 new classes, 8 properties, 1 relationship type", $p['summary'] ?? '');
    check('...the model is told it is on a card', str_contains($m['content'], 'Proposed (on a card'), $m['content']);
    check('...the panel gets no arguments, only what it is', !isset($p['args']) && isset($stored($m['id'])[0]['args']['plan']));
    check('...and nothing is created yet', $classId($SVC) === 0 && $classId($HOST) === 0);
    $all = json_decode((string)file_get_contents($LOG), true) ?: [];
    $req = end($all) ?: [];
    check('both propose tools are offered to the model', in_array('propose_schema', $req['tools'] ?? [], true) && in_array('propose_objects', $req['tools'] ?? [], true), json_encode($req['tools'] ?? []));
    $sys = cmdbAssistSystem($conn, $coA, $A, cmdbAssistCoverage($conn, $def), null);
    foreach ([
        'add-only, changes are advice' => 'It is add-only: it never changes or removes anything already there',
        'one plan per proposal'        => 'ONE plan per call',
        'record property types'        => 'Use record property types (person, analyst, team, location, company, supplier, contract)',
        'no class named after a company' => 'Never a class named after a company, person, team, location or supplier',
        'CIs only from what it was told' => 'never invent a name',
        'CIs in this company only'     => 'in THIS company only',
        'ask before proposing CIs'     => 'Ask before proposing',
    ] as $what => $needle) check("the prompt says: $what", str_contains($sys, $needle));

    // The preview re-resolves: a Host class made since counts as there.
    $pv = cmdbAssistPreview($conn, $ctxA, $coA, $m['id'], 0);
    check('the preview resolves the stored plan', $pv['type'] === 'schema' && $pv['resolved']['counts']['create'] === 11 && $pv['can']['relationship_types'] === true, json_encode($pv['resolved']['counts']));
    $conn->prepare("INSERT INTO cmdb_classes (class_key, name, is_active) VALUES (?, ?, 1)")->execute([$HOST, "ZZ $tag Host"]);
    $pv = cmdbAssistPreview($conn, $ctxA, $coA, $m['id'], 0);
    $hostLine = null; foreach ($pv['resolved']['classes'] as $c) if ($c['key'] === $HOST) $hostLine = $c;
    check('...again against the live CMDB: a class made since is Existing', $hostLine && $hostLine['action'] === 'exists' && $pv['resolved']['counts']['create'] === 10, json_encode($pv['resolved']['counts']));
    [$ctxB, $coB] = $as($R, $def);
    $other = ''; try { cmdbAssistPreview($conn, $ctxB, $coB, $m['id'], 0); } catch (ServiceError $e) { $other = $e->kind; }
    check('...and another analyst cannot preview it', $other === 'not_found');
    [$ctxA, $coA] = $as($A, $def);

    // Apply with one line unticked
    $sel = [];
    foreach ($pv['resolved']['relationship_types'] as $r) if ($r['action'] === 'create') $sel[] = $r['line'];
    foreach ($pv['resolved']['classes'] as $c) {
        if ($c['action'] === 'create') $sel[] = $c['line'];
        foreach ($c['properties'] as $pp) if ($pp['action'] === 'create' && $pp['key'] !== 'notes') $sel[] = $pp['line'];
    }
    $props = cmdbAssistApply($conn, $ctxA, $coA, $m['id'], [0], false, $sel);
    $res = $props[0]['result'] ?? [];
    check('apply marks it applied, with what it made', $props[0]['status'] === 'applied' && ($res['counts'] ?? []) === ['relationship_types' => 1, 'classes' => 1, 'properties' => 7], json_encode($props[0]));
    $svcId = $classId($SVC); $hostId = $classId($HOST);
    $svcKeys = $conn->query("SELECT property_key FROM cmdb_class_properties WHERE class_id = $svcId ORDER BY property_key")->fetchAll(PDO::FETCH_COLUMN);
    check('...the ticked lines are created', $svcId > 0 && $svcKeys === ['owner', 'runs_on', 'tier'] && count($propIds($hostId)) === 4, json_encode($svcKeys));
    check('...the unticked one is not', !in_array('notes', $svcKeys, true));
    check('...the object reference points at the existing Host class and spreads impact',
        (int)$one("SELECT target_class_id FROM cmdb_class_properties WHERE class_id = ? AND property_key = 'runs_on' AND spreads_impact = 1", [$svcId]) === $hostId);
    check('...the relationship type is created', (int)$one("SELECT COUNT(*) FROM cmdb_relationship_types WHERE verb = ? AND inverse_verb = ? AND impact_direction = 'from_to'", [$VERB, $INV]) === 1);
    $conv = $conn->query("SELECT subject, statement FROM cmdb_ai_facts WHERE tenant_id = $def AND topic = 'convention' AND id > $firstFact")->fetchAll(PDO::FETCH_KEY_PAIR);
    check('...the plan\'s links are kept as convention facts', isset($conv["Link: ZZ $tag Service $INV ZZ $tag Host"]) && isset($conv["Parent: ZZ $tag Host > ZZ $tag Service"]), json_encode($conv));
    check('...and the card shows Applied with counts', (cmdbAssistConversation($conn, $def, $A)['messages'][count(cmdbAssistConversation($conn, $def, $A)['messages']) - 1]['proposals'][0]['counts']['classes'] ?? 0) === 1);
    $again = cmdbAssistApply($conn, $ctxA, $coA, $m['id'], [0]);
    check('applying an applied proposal again does nothing', $again[0]['status'] === 'applied' && count($propIds($svcId)) === 3);

    $m2 = $call($ctxA, $coA, 'propose_schema', $plan);
    check('the same plan again proposes only what is still missing (the unticked line)', str_ends_with($m2['proposals'][0]['summary'] ?? '', ': 1 property'), $m2['proposals'][0]['summary'] ?? json_encode($m2));
    cmdbAssistApply($conn, $ctxA, $coA, $m2['id'], [0]);
    check('...and applying it adds just that', count($propIds($svcId)) === 4 && count($propIds($hostId)) === 4 && (int)$one("SELECT COUNT(*) FROM cmdb_classes WHERE class_key IN (?, ?)", [$SVC, $HOST]) === 2);
    $m3 = $call($ctxA, $coA, 'propose_schema', $plan);
    check('a plan with nothing to create is refused, and the model is told why', !$m3['proposals'] && str_contains($m3['content'], 'Not proposed: nothing in it would be created'), $m3['content']);
    $bad = ['title' => 'x', 'classes' => [['name' => 'Person', 'properties' => [['label' => 'A', 'type' => 'text']]]]];
    $m4 = $call($ctxA, $coA, 'propose_schema', $bad);
    check('...a class named like a record: refused with the reason to use a property', !$m4['proposals'] && str_contains($m4['content'], 'use a Person property'), $m4['content']);

    echo "Capabilities, failure, dismiss\n";
    [$ctxR, $coR] = $as($R, $def);
    $pr = ['title' => "$tag more", 'relationship_types' => [['verb' => "$tag feeds", 'inverse_verb' => "$tag fed by", 'impact_direction' => 'to_from']],
           'classes' => [['key' => "{$tag}_db", 'name' => "ZZ $tag Database", 'properties' => [['label' => 'Engine', 'type' => 'text']]]]];
    $mr = $call($ctxR, $coR, 'propose_schema', $pr);
    $out = cmdbAssistApply($conn, $ctxR, $coR, $mr['id'], [0]);
    check('without the relationship-types capability that line is skipped, the rest applied',
        $out[0]['status'] === 'applied' && ($out[0]['result']['counts']['relationship_types'] ?? -1) === 0 && $classId("{$tag}_db") > 0
        && in_array('rt:0', array_column($out[0]['result']['skipped'] ?? [], 'line'), true) && (int)$one("SELECT COUNT(*) FROM cmdb_relationship_types WHERE verb = ?", ["$tag feeds"]) === 0,
        json_encode($out[0]));

    $pf = ['title' => "$tag fail", 'classes' => [['key' => "{$tag}_app", 'name' => "ZZ $tag Application", 'properties' => [['label' => 'Vendor', 'type' => 'text'], ['label' => 'Version', 'type' => 'text']]]]];
    $mf = $call($ctxA, $coA, 'propose_schema', $pf);
    $out = cmdbAssistApply($conn, $ctxA, $coA, $mf['id'], [0], false, null, ['on_item' => function ($kind, $line) { if ($kind === 'property') throw new ServiceError('validation', 'invalid_field', 'Injected failure'); }]);
    check('a failure part-way marks it failed, with the message', $out[0]['status'] === 'failed' && $out[0]['error'] === 'Injected failure', json_encode($out[0]));
    check('...and rolls everything back (the class too)', $classId("{$tag}_app") === 0);
    $out = cmdbAssistApply($conn, $ctxA, $coA, $mf['id'], [0]);
    check('...a failed one can be tried again', $out[0]['status'] === 'applied' && $classId("{$tag}_app") > 0 && count($propIds($classId("{$tag}_app"))) === 2, json_encode($out[0]));

    $pd = ['title' => "$tag dismissed", 'classes' => [['key' => "{$tag}_dis", 'name' => "ZZ $tag Dismissed"]]];
    $md = $call($ctxA, $coA, 'propose_schema', $pd);
    $out = cmdbAssistApply($conn, $ctxA, $coA, $md['id'], [0], true);
    check('dismiss marks it dismissed and creates nothing', $out[0]['status'] === 'dismissed' && $classId("{$tag}_dis") === 0);
    $out = cmdbAssistApply($conn, $ctxA, $coA, $md['id'], [0]);
    check('...and a dismissed one cannot be applied after', $out[0]['status'] === 'dismissed' && $classId("{$tag}_dis") === 0);

    // ================================================================ objects
    echo "propose_objects\n";
    $legacy = CmdbService::saveObject($conn, $ctxA, ['class_id' => $hostId, 'name' => "$tag-legacy", 'tenant_id' => $def])['id'];
    $objs = ['title' => "$tag payroll", 'objects' => [
        ['class_key' => $SVC, 'name' => "$tag-payroll", 'properties' => ['tier' => 'gold', 'runs_on' => "$tag-h1", 'owner' => "Nobody $tag", 'notes' => 'Monthly'],
         'relationships' => [['verb' => $INV, 'to' => "$tag-h1"]]],
        ['class_key' => $HOST, 'name' => "$tag-h1", 'description' => 'The payroll box', 'properties' => ['os' => 'Windows Server', 'ram_gb' => '16', 'bought' => '2024-03-01', 'live' => 'yes']],
        ['class_key' => $HOST, 'name' => "$tag-h1-disk", 'parent' => "$tag-h1"],
    ]];
    [$ctxZ, $coZ] = $as($A, $zz);
    check('switching company makes the ZZ company the assistant\'s', $coZ['id'] === $zz);
    $mz = $call($ctxZ, $coZ, 'propose_objects', $objs);
    $pz = $mz['proposals'][0] ?? [];
    check('a CI proposal is stored, naming the active company', ($pz['type'] ?? '') === 'objects' && ($pz['summary'] ?? '') === "$tag payroll: 3 configuration items and 1 relationship in ZZ $tag Co", $pz['summary'] ?? $mz['content']);
    check('...the stored company is the active one', ($stored($mz['id'])[0]['args']['company_id'] ?? 0) === $zz);
    check('...a person that matches nothing is left empty, and the model is told', str_contains($mz['content'], 'Left empty') && str_contains($mz['content'], "Nobody $tag"), $mz['content']);
    $pv = cmdbAssistPreview($conn, $ctxZ, $coZ, $mz['id'], 0);
    check('the preview names the company and resolves each CI', $pv['type'] === 'objects' && $pv['resolved']['company']['id'] === $zz && count($pv['resolved']['objects']) === 3 && $pv['resolved']['counts']['create'] === 4, json_encode($pv['resolved']['counts']));
    [$ctxD, $coD] = $as($A, $def);
    $cross = ''; try { cmdbAssistApply($conn, $ctxD, $coD, $mz['id'], [0]); } catch (ServiceError $e) { $cross = $e->kind; }
    check('from another company the ZZ proposal cannot be applied (not found)', $cross === 'not_found' && $ciCount() === 1);
    [$ctxZ, $coZ] = $as($A, $zz);

    $out = cmdbAssistApply($conn, $ctxZ, $coZ, $mz['id'], [0], false, null, ['on_item' => function ($kind) { if ($kind === 'relationship') throw new ServiceError('validation', 'invalid_field', 'Injected CI failure'); }]);
    check('an injected failure at the relationship marks it failed', $out[0]['status'] === 'failed' && $out[0]['error'] === 'Injected CI failure');
    check('...and rolls back every CI already made in that apply', $ciCount() === 1 && !$ci("$tag-h1"));

    $out = cmdbAssistApply($conn, $ctxZ, $coZ, $mz['id'], [0]);
    $h1 = $ci("$tag-h1"); $pay = $ci("$tag-payroll"); $disk = $ci("$tag-h1-disk");
    check('apply creates the CIs', $out[0]['status'] === 'applied' && $h1 && $pay && $disk && ($out[0]['result']['counts'] ?? []) === ['objects' => 3, 'relationships' => 1], json_encode($out[0]));
    check('...in the active (ZZ) company', $h1 && (int)$h1['tenant_id'] === $zz && (int)$pay['tenant_id'] === $zz && (int)$disk['tenant_id'] === $zz);
    check('...with typed property values (text, number, date, yes/no, a dropdown matched to its option)',
        ($val((int)$h1['id'], 'os')['value_text'] ?? '') === 'Windows Server' && (float)($val((int)$h1['id'], 'ram_gb')['value_number'] ?? 0) === 16.0
        && str_starts_with((string)($val((int)$h1['id'], 'bought')['value_date'] ?? ''), '2024-03-01') && (int)($val((int)$h1['id'], 'live')['value_boolean'] ?? 0) === 1
        && ($val((int)$pay['id'], 'tier')['value_text'] ?? '') === 'Gold');
    check('...an object reference to a CI made in the same apply', (int)($val((int)$pay['id'], 'runs_on')['value_object_id'] ?? 0) === (int)$h1['id']);
    check('...the person left empty', $val((int)$pay['id'], 'owner') === null || empty($val((int)$pay['id'], 'owner')['value_ref_id']));
    check('...the parent, made in the same apply', (int)$disk['parent_id'] === (int)$h1['id']);
    $rtId = (int)$one("SELECT id FROM cmdb_relationship_types WHERE verb = ?", [$VERB]);
    check('...the relationship, the inverse verb read the right way round (host -> service)',
        (int)$one("SELECT COUNT(*) FROM cmdb_object_relationships WHERE from_object_id = ? AND to_object_id = ? AND relationship_type_id = ?", [(int)$h1['id'], (int)$pay['id'], $rtId]) === 1);
    check('...and nothing in the Default company (only the control CI there)', (int)$one("SELECT COUNT(*) FROM cmdb_objects WHERE name LIKE ? AND (tenant_id IS NULL OR tenant_id = ?)", ["$tag-%", $def]) === 1);

    $twice = cmdbAssistObjectsApply($conn, $ctxZ, $coZ, $stored($mz['id'])[0]['args']);
    check('a second apply of the same CIs finds them there and creates nothing', $twice['counts'] === ['objects' => 0, 'relationships' => 0] && $twice['existing'] === 4 && $ciCount() === 4, json_encode($twice));
    $mz2 = $call($ctxZ, $coZ, 'propose_objects', $objs);
    check('...and proposing them again is refused: all already there', !$mz2['proposals'] && str_contains($mz2['content'], 'nothing to create - they are all already in this company'), $mz2['content']);
    $mix = ['title' => "$tag mix", 'objects' => [['class_key' => $HOST, 'name' => "$tag-h1"], ['class_key' => $HOST, 'name' => "$tag-h2", 'relationships' => [['verb' => $VERB, 'to' => "$tag-payroll"]]]]];
    $mm = $call($ctxZ, $coZ, 'propose_objects', $mix);
    $pv = cmdbAssistPreview($conn, $ctxZ, $coZ, $mm['id'], 0);
    $acts = array_column($pv['resolved']['objects'], 'action', 'name');
    check('one already there is Existing (skipped), the new one New', ($acts["$tag-h1"] ?? '') === 'exists' && ($acts["$tag-h2"] ?? '') === 'create' && str_contains($mm['content'], "Already in this company, skipped: $tag-h1"), json_encode($acts));
    $out = cmdbAssistApply($conn, $ctxZ, $coZ, $mm['id'], [0], false, ['obj:1']);
    check('...apply with the relationship unticked: the CI only', $out[0]['status'] === 'applied' && ($out[0]['result']['counts'] ?? []) === ['objects' => 1, 'relationships' => 0] && $ciCount() === 5, json_encode($out[0]['result'] ?? null));

    foreach ([
        'an unknown property'          => [['class_key' => $HOST, 'name' => "$tag-x1", 'properties' => ['colour' => 'red']], 'has no property "colour"'],
        'a number that is not one'     => [['class_key' => $HOST, 'name' => "$tag-x2", 'properties' => ['ram_gb' => 'lots']], 'Memory GB needs a number'],
        'a dropdown value not offered' => [['class_key' => $SVC, 'name' => "$tag-x3", 'properties' => ['tier' => 'Bronze']], 'Tier must be one of: Gold, Silver'],
        'a required property missing'  => [['class_key' => $SVC, 'name' => "$tag-x4"], 'Tier is required'],
        'an unknown class'             => [['class_key' => "{$tag}_nope", 'name' => "$tag-x5"], 'it needs adding first'],
        'an unknown verb'              => [['class_key' => $HOST, 'name' => "$tag-x6", 'relationships' => [['verb' => 'befriends', 'to' => "$tag-h1"]]], 'no relationship type "befriends"'],
        'a parent in another company'  => [['class_key' => $HOST, 'name' => "$tag-x7", 'parent' => "$tag-legacy"], "No configuration item called \"$tag-legacy\""],
    ] as $what => [$o, $needle]) {
        $mb = $call($ctxZ, $coZ, 'propose_objects', ['title' => 't', 'objects' => [$o]]);
        check("refused, with the reason back to the model: $what", !$mb['proposals'] && str_contains($mb['content'], 'Not proposed: fix these') && str_contains($mb['content'], $needle), $mb['content']);
    }
    // Ed's transcript: "2 people: David Jones and Lucy Smith" proposed as CIs of the old demo Person class.
    $conn->prepare("INSERT INTO cmdb_classes (class_key, name, is_active) VALUES (?, 'Person', 1)")->execute(["{$tag}_person"]);
    $mp = $call($ctxZ, $coZ, 'propose_objects', ['title' => 'people', 'objects' => [['class_key' => "{$tag}_person", 'name' => "$tag-David Jones"], ['class_key' => $HOST, 'name' => "$tag-pc-ok"]]]);
    check('a CI in an old Person class is refused, with the reason to use a person property', !$mp['proposals'] && str_contains($mp['content'], "\"$tag-David Jones\": \"Person\" is not a class for configuration items") && str_contains($mp['content'], 'with a Person property'), $mp['content']);
    $pr = cmdbAssistObjectsResolve($conn, $coZ, cmdbAssistObjectsClean(['objects' => [['class_key' => "{$tag}_person", 'name' => "$tag-David Jones"], ['class_key' => $HOST, 'name' => "$tag-pc-ok"]]]));
    check('...only that one: a CI of an ordinary class beside it would be created (control)', array_column($pr['objects'], 'action') === ['invalid', 'create'], json_encode(array_column($pr['objects'], 'action')));
    $conn->prepare("DELETE FROM cmdb_classes WHERE class_key = ?")->execute(["{$tag}_person"]);
    check('...and nothing was made', !$ci("$tag-David Jones") && !$ci("$tag-pc-ok"));

    [$ctxD, $coD] = $as($A, $def);
    $mc = $call($ctxD, $coD, 'propose_objects', ['title' => 'ctl', 'objects' => [['class_key' => $HOST, 'name' => "$tag-x7", 'parent' => "$tag-legacy"]]]);
    check('...positive control: in the Default company the same parent is found and it is proposed', count($mc['proposals']) === 1 && str_contains($mc['proposals'][0]['summary'], "in Default"), $mc['content']);
    cmdbAssistApply($conn, $ctxD, $coD, $mc['id'], [0], true);
    check('more than 25 in one proposal is refused', (function () use ($call, $ctxZ, $coZ, $HOST, $tag) {
        $many = []; for ($i = 0; $i < 26; $i++) $many[] = ['class_key' => $HOST, 'name' => "$tag-m$i"];
        $mx = $call($ctxZ, $coZ, 'propose_objects', ['title' => 'many', 'objects' => $many]);
        return !$mx['proposals'] && str_contains($mx['content'], 'at most 25');
    })());

    [$ctxN, $coN] = $as($N, $def);
    $mn = $call($ctxN, $coN, 'propose_objects', ['title' => 'n', 'objects' => [['class_key' => $HOST, 'name' => "$tag-nomod"]]]);
    $out = cmdbAssistApply($conn, $ctxN, $coN, $mn['id'], [0]);
    check('without CMDB module access the CIs are refused (failed, nothing made)', count($mn['proposals']) === 1 && $out[0]['status'] === 'failed' && $out[0]['error'] === t('cmdb.assistant.err_no_ci_access') && !$ci("$tag-nomod"), json_encode($out[0]));

    // ================================================================ HTTP
    if ($base !== '') {
        echo "Over HTTP\n";
        $csrf = bin2hex(random_bytes(32));
        $sess = function (int $id, int $tenant) use ($sessDir, $csrf, &$made) {
            $sid = $made['sessions'][] = 'zzcp' . bin2hex(random_bytes(6));
            file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $id . ';analyst_name|s:5:"zztst";csrf_token|s:64:"' . $csrf . '";active_tenant_id|i:' . $tenant . ';');
            return $sid;
        };
        $http = function (string $s, ?array $post = null, bool $token = true, string $q = '') use ($base, $csrf): array {
            $ch = curl_init("$base/api/cmdb/assistant.php" . $q);
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIE => "PHPSESSID=$s"];
            if ($post !== null) $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post),
                CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $token ? ["X-CSRF-Token: $csrf"] : [])];
            curl_setopt_array($ch, $o);
            $body = (string)curl_exec($ch);
            return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($body, true) ?: ['raw' => substr($body, 0, 300)]];
        };
        $rows = fn() => $conn->query("SELECT (SELECT COUNT(*) FROM cmdb_classes), (SELECT COUNT(*) FROM cmdb_class_properties), (SELECT COUNT(*) FROM cmdb_objects), (SELECT COUNT(*) FROM cmdb_object_relationships), (SELECT COUNT(*) FROM cmdb_ai_messages), (SELECT COUNT(*) FROM cmdb_ai_facts), (SELECT MD5(GROUP_CONCAT(COALESCE(proposals, '') ORDER BY id)) FROM cmdb_ai_messages)")->fetch(PDO::FETCH_NUM);
        [$ctxA, $coA] = $as($A, $def);
        $ph = ['title' => "$tag http", 'classes' => [['key' => "{$tag}_http", 'name' => "ZZ $tag Http", 'properties' => [['label' => 'One', 'type' => 'text'], ['label' => 'Two', 'type' => 'text']]]]];
        $mh = $call($ctxA, $coA, 'propose_schema', $ph);
        $sA = $sess($A, $def); $sR = $sess($R, $def);
        $before = $rows();
        [$code, $j] = $http($sA, null, true, '?action=preview&message_id=' . $mh['id'] . '&index=0');
        check('GET preview answers the re-resolved plan', $code === 200 && !empty($j['success']) && ($j['type'] ?? '') === 'schema' && ($j['resolved']['counts']['create'] ?? 0) === 3, "$code " . json_encode($j));
        [$code, $j] = $http($sA, null, true, '?action=preview&message_id=' . $mz['id'] . '&index=0');
        check('...a CI preview from another company\'s conversation is not found', empty($j['success']));
        [$code, $j] = $http($sR, null, true, '?action=preview&message_id=' . $mh['id'] . '&index=0');
        check('...another analyst\'s is not found', empty($j['success']));
        check('...and GET writes nothing', $rows() === $before);
        [$code, $j] = $http($sA, ['action' => 'apply', 'message_id' => $mh['id'], 'items' => [0], 'selection' => ["class:{$tag}_http", "prop:{$tag}_http:one"]], false);
        check('POST apply without the CSRF token is refused, nothing made', empty($j['success']) && $code >= 400 && $rows() === $before, "$code " . json_encode($j));
        [$code, $j] = $http($sA, ['action' => 'apply', 'message_id' => $mh['id'], 'items' => [0], 'selection' => ["class:{$tag}_http", "prop:{$tag}_http:one"]]);
        $hp = $j['proposals'][0] ?? [];
        check('POST apply with a selection creates just those lines', !empty($j['success']) && ($hp['status'] ?? '') === 'applied' && ($hp['result']['counts'] ?? []) === ['relationship_types' => 0, 'classes' => 1, 'properties' => 1]
            && count($propIds($classId("{$tag}_http"))) === 1 && !isset($hp['args']), json_encode($j['proposals'] ?? $j));
        check('...and the conversation comes back with it applied', ($j['messages'][count($j['messages']) - 1]['proposals'][0]['status'] ?? '') === 'applied');
        $md2 = $call($ctxA, $coA, 'propose_schema', ['title' => 'd', 'classes' => [['key' => "{$tag}_d2", 'name' => "ZZ $tag D2"]]]);
        [$code, $j] = $http($sA, ['action' => 'dismiss', 'message_id' => $md2['id'], 'items' => [0]]);
        check('POST dismiss', !empty($j['success']) && ($j['proposals'][0]['status'] ?? '') === 'dismissed' && $classId("{$tag}_d2") === 0, json_encode($j));

        // ================================================================ headless
        $chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
        foreach (['/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $alt) if (!is_file($chrome) && is_file($alt)) $chrome = $alt;
        if (is_file($chrome)) {
            echo "In headless Chrome\n";
            [$ctxH, $coH] = $as($H, $def);
            $hs = $call($ctxH, $coH, 'propose_schema', ['title' => "$tag headless", 'classes' => [
                ['key' => "{$tag}_hl", 'name' => "ZZ $tag Headless App", 'why' => 'The applications payroll uses', 'properties' => [
                    ['label' => 'Vendor', 'type' => 'supplier'], ['label' => 'Hosted on', 'type' => 'object_ref', 'target' => $HOST, 'spreads_impact' => true],
                    ['label' => 'Licence', 'type' => 'dropdown', 'options' => ['Per user', 'Site']]]],
                ['key' => $HOST, 'name' => "ZZ $tag Host", 'properties' => [['label' => 'Rack', 'type' => 'text'], ['label' => 'Operating system', 'key' => 'os', 'type' => 'text']]],
            ], 'usage' => [['from' => "{$tag}_hl", 'verb' => $INV, 'to' => $HOST]]]);
            $ho = $call($ctxH, $coH, 'propose_objects', ['title' => "$tag headless CIs", 'objects' => [
                ['class_key' => $HOST, 'name' => "$tag-hl-box", 'description' => 'Runs the payroll app', 'properties' => ['os' => 'Ubuntu 24.04', 'ram_gb' => 32, 'live' => true]],
                ['class_key' => $SVC, 'name' => "$tag-hl-payroll", 'properties' => ['tier' => 'Silver', 'runs_on' => "$tag-hl-box"], 'relationships' => [['verb' => $INV, 'to' => "$tag-hl-box"]]],
                ['class_key' => $HOST, 'name' => "$tag-legacy"],
            ]]);
            check('headless setup: two pending proposals on the analyst\'s conversation', count($hs['proposals']) === 1 && count($ho['proposals']) === 1, $hs['content'] . ' / ' . $ho['content']);
            $sid = $sess($H, $def);
            $runChrome = function (string $mode, string $shotFile = '') use ($chrome, $base, $sid, $tag, &$made): array {
                $frame = $made['frames'][] = dirname(__DIR__) . '/zz_cp_' . bin2hex(random_bytes(3)) . '.php';
                $js = <<<JS
const f=document.getElementById('f');const out=o=>{document.getElementById('out').textContent=JSON.stringify(o)};const MODE='$mode';
f.addEventListener('load',()=>{const w=f.contentWindow,d=f.contentDocument;const o={mode:MODE};
 const st=d.createElement('style');st.textContent='*{transition:none!important;animation:none!important}';d.head.appendChild(st);
 const reviews=()=>[...d.querySelectorAll('[data-ca-review]')];
 setTimeout(()=>{try{d.getElementById('cmdbAssistBtn').click();}catch(e){o.err=String(e);out(o);return;}
 setTimeout(()=>{try{o.cards=d.querySelectorAll('.ca-card').length;o.reviews=reviews().length;o.cardText=[...d.querySelectorAll('.ca-card')].map(c=>c.innerText);
   const rs=reviews();(MODE.indexOf('objects')>=0?rs[rs.length-1]:rs[0]).click();}catch(e){o.err=String(e);out(o);return;}
 setTimeout(()=>{try{const id=MODE.indexOf('objects')>=0?'cmdbObjectPreview':'cmdbSchemaPreview';const m=d.getElementById(id);
   o.preview=!!(m&&m.classList.contains('active'));o.previewText=m?m.innerText:'';o.ticks=m?m.querySelectorAll('input[type=checkbox][data-line]:checked').length:0;
   o.z=m?w.getComputedStyle(m).zIndex:'';
   if(MODE.indexOf('shot')===0){out(o);return;}
   m.querySelector('[data-csp=apply],[data-cop=apply]').click();}catch(e){o.err=String(e);out(o);return;}
 setTimeout(()=>{try{o.closed=!d.querySelector('#cmdbSchemaPreview.active,#cmdbObjectPreview.active');o.after=[...d.querySelectorAll('.ca-card')].map(c=>c.innerText);
   o.applied=d.querySelectorAll('.ca-card li.ca-applied').length;o.table=(d.getElementById('classesTableBody')||{}).innerText||'';
   o.unresolved=(d.body.innerText.match(/cmdb\.[a-z_]+\.[a-z_.]+/g)||[]);
   const keys=()=>[...d.querySelectorAll('#caChips [data-ca-chip]')].map(b=>b.dataset.caChip);o.chipKeys=keys();
   const cc=d.querySelector('[data-ca-chip="chip_continue"]');o.chipLabel=cc?cc.innerText:'';
   if(MODE!=='flow_schema'||!cc){out(o);return;}
   cc.click();
 setTimeout(()=>{try{const u=[...d.querySelectorAll('#caMessages .ai-chat-message.user')];o.sent=u.length?u[u.length-1].innerText:'';
   const a=[...d.querySelectorAll('#caMessages .ai-chat-message.assistant')];o.answer=a.length?a[a.length-1].innerText:'';o.chipKeysAfter=keys();out(o);}catch(e){o.err=String(e);out(o);}},4000);
 }catch(e){o.err=String(e);out(o);}},4000);},3000);},4000);},2500);});
JS;
                file_put_contents($frame, "<?php setcookie('PHPSESSID', '$sid', 0, '/'); ?><!doctype html><html><body style=\"margin:0\"><pre id=\"out\" style=\"position:absolute;left:-9999px\">waiting</pre>"
                    . "<iframe id=\"f\" src=\"cmdb/settings/\" style=\"width:1400px;height:900px;border:0\"></iframe><script>$js</script></body></html>");
                $prof = sys_get_temp_dir() . '/zz_cp_chrome_' . getmypid() . '_' . $mode;
                $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
                $cmd = '"' . $chrome . '" --headless=new --disable-gpu --hide-scrollbars --window-size=1400,900 --user-data-dir="' . $prof . '" --virtual-time-budget=' . ($shotFile ? 16000 : 25000);
                $j = [];
                if ($shotFile) {
                    shell_exec($cmd . ' --screenshot="' . $shotFile . '" "' . $base . '/' . basename($frame) . '" 2>' . $null);
                } else {
                    $dump = (string)shell_exec($cmd . ' --dump-dom "' . $base . '/' . basename($frame) . '" 2>' . $null);
                    $j = preg_match('#<pre id="out"[^>]*>(.*?)</pre>#s', $dump, $mm) ? (json_decode(html_entity_decode($mm[1]), true) ?: []) : [];
                }
                @unlink($frame);
                return $j;
            };
            $dir = $shots !== '' ? $shots : sys_get_temp_dir();
            $runChrome('shot_schema', "$dir/cmdb-assistant-schema-preview.png");
            $runChrome('shot_objects', "$dir/cmdb-assistant-objects-preview.png");
            check('screenshots of both previews were taken', is_file("$dir/cmdb-assistant-schema-preview.png") && is_file("$dir/cmdb-assistant-objects-preview.png"), $dir);
            echo "  (screenshots: $dir/cmdb-assistant-schema-preview.png, $dir/cmdb-assistant-objects-preview.png)\n";
            check('...and nothing was applied by taking them', $classId("{$tag}_hl") === 0 && !$ci("$tag-hl-box"));

            $j = $runChrome('flow_schema');
            check('the card shows both suggestions with Review', ($j['reviews'] ?? 0) === 2 && str_contains(implode(' ', $j['cardText'] ?? []), "$tag headless: 1 new class, 4 properties"), json_encode($j));
            check('Review opens the schema preview above the panel, every new line ticked', !empty($j['preview']) && ($j['ticks'] ?? 0) === 5 && (int)($j['z'] ?? 0) > 3100
                && str_contains($j['previewText'] ?? '', "ZZ $tag Headless App") && str_contains($j['previewText'] ?? '', 'Existing'), json_encode($j));
            check('Apply creates, closes the preview, the card says Applied and the Classes table shows the class', !empty($j['closed']) && ($j['applied'] ?? 0) === 1 && $classId("{$tag}_hl") > 0
                && count($propIds($classId("{$tag}_hl"))) === 3 && str_contains($j['table'] ?? '', "ZZ $tag Headless App"), json_encode($j));
            check('no unresolved string keys (schema)', isset($j['unresolved']) && !$j['unresolved'], json_encode($j['unresolved'] ?? null));
            // An Apply starts no turn: the Continue chip lets the assistant carry on (propose the CIs it promised).
            check('after Apply a Continue chip comes first', ($j['chipKeys'][0] ?? '') === 'chip_continue' && ($j['chipLabel'] ?? '') === 'Continue', json_encode([$j['chipKeys'] ?? null, $j['chipLabel'] ?? null]));
            check('...clicking it sends "carry on" and the assistant answers', str_contains($j['sent'] ?? '', 'applied it - carry on') && str_contains($j['answer'] ?? '', 'REPLY:'), json_encode([$j['sent'] ?? null, $j['answer'] ?? null]));
            check('...after which the chip is gone', isset($j['chipKeysAfter']) && !in_array('chip_continue', $j['chipKeysAfter'], true), json_encode($j['chipKeysAfter'] ?? null));
            $reqs = json_decode((string)@file_get_contents($LOG), true) ?: [];
            $lastReq = end($reqs) ?: [];
            check('...and that turn\'s history tells the model the schema was applied', str_contains(implode("\n", (array)($lastReq['texts'] ?? [])), "$tag headless: 1 new class, 4 properties - applied"), mb_substr(implode("\n", (array)($lastReq['texts'] ?? [])), -600));

            $j = $runChrome('flow_objects');
            check('Review opens the CI preview naming the company, Existing for one already there', !empty($j['preview']) && str_contains($j['previewText'] ?? '', 'These will be created in Default')
                && str_contains($j['previewText'] ?? '', "$tag-hl-box") && str_contains($j['previewText'] ?? '', 'Existing') && ($j['ticks'] ?? 0) === 3, json_encode($j));
            $box = $ci("$tag-hl-box"); $hp = $ci("$tag-hl-payroll");
            check('Apply creates the CIs in the active company and the card says Applied', !empty($j['closed']) && $box && $hp && $box['tenant_id'] === null
                && (int)($val((int)$hp['id'], 'runs_on')['value_object_id'] ?? 0) === (int)$box['id'] && ($j['applied'] ?? 0) === 2, json_encode($j));
            check('no unresolved string keys (CIs)', isset($j['unresolved']) && !$j['unresolved'], json_encode($j['unresolved'] ?? null));
        } else {
            echo "(headless part skipped: no Chrome)\n";
        }
    }
} catch (Throwable $e) {
    if ($e->getMessage() !== 'not ready') check('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($made['frames'] as $f) @unlink($f);
    $ids = implode(',', array_map('intval', $made['analysts'])) ?: '0';
    $q = fn(string $sql) => array_map('intval', $conn->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    $in = fn(array $a) => implode(',', $a ?: [0]);
    $like = $conn->quote("$tag%");
    // CIs and their values / links, by id
    $objIds = $q("SELECT id FROM cmdb_objects WHERE name LIKE " . $conn->quote("$tag-%"));
    if ($objIds) {
        $conn->exec("DELETE FROM cmdb_object_relationships WHERE from_object_id IN (" . $in($objIds) . ") OR to_object_id IN (" . $in($objIds) . ")");
        $conn->exec("DELETE FROM cmdb_object_properties WHERE object_id IN (" . $in($objIds) . ")");
        $conn->exec("UPDATE cmdb_objects SET parent_id = NULL WHERE id IN (" . $in($objIds) . ")");
        $conn->exec("DELETE FROM cmdb_objects WHERE id IN (" . $in($objIds) . ")");
    }
    // Classes, properties, options, relationship types, by id
    $clsIds = $q("SELECT id FROM cmdb_classes WHERE class_key LIKE $like");
    $propIdsAll = $q("SELECT id FROM cmdb_class_properties WHERE class_id IN (" . $in($clsIds) . ")");
    if ($propIdsAll) { $conn->exec("DELETE FROM cmdb_class_property_options WHERE property_id IN (" . $in($propIdsAll) . ")"); $conn->exec("DELETE FROM cmdb_class_properties WHERE id IN (" . $in($propIdsAll) . ")"); }
    if ($clsIds) $conn->exec("DELETE FROM cmdb_classes WHERE id IN (" . $in($clsIds) . ")");
    $rtIds = $q("SELECT id FROM cmdb_relationship_types WHERE verb LIKE $like");
    if ($rtIds) $conn->exec("DELETE FROM cmdb_relationship_types WHERE id IN (" . $in($rtIds) . ")");
    // Conversations and facts
    $tids = $q("SELECT id FROM cmdb_ai_threads WHERE analyst_id IN ($ids) OR tenant_id = " . (int)$made['tenant']);
    if ($tids) { $conn->exec("DELETE FROM cmdb_ai_messages WHERE thread_id IN (" . $in($tids) . ")"); $conn->exec("DELETE FROM cmdb_ai_threads WHERE id IN (" . $in($tids) . ")"); }
    $fids = $q("SELECT id FROM cmdb_ai_facts WHERE id > " . (int)$firstFact . " AND (tenant_id = " . (int)$made['tenant'] . " OR created_by_analyst_id IN ($ids))");
    if ($fids) $conn->exec("DELETE FROM cmdb_ai_facts WHERE id IN (" . $in($fids) . ")");
    $conn->exec("DELETE FROM rbac_analyst_roles WHERE analyst_id IN ($ids)");
    foreach ($made['roles'] as $r) { $conn->exec("DELETE FROM rbac_role_capabilities WHERE role_id = " . (int)$r); $conn->exec("DELETE FROM rbac_roles WHERE id = " . (int)$r); }
    $conn->exec("DELETE FROM analyst_tenant_access WHERE analyst_id IN ($ids)");
    $conn->exec("DELETE FROM analysts WHERE id IN ($ids)");
    if ($made['tenant']) $conn->exec("DELETE FROM tenants WHERE id = " . (int)$made['tenant']);
    foreach ($made['sessions'] as $s) @unlink("$sessDir/sess_$s");
    $conn->prepare("DELETE FROM system_settings WHERE setting_key IN ($place)")->execute(array_values($keys));
    $re = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($snapshot as $k => $v) $re->execute([$k, $v]);
    if (is_resource($server)) proc_terminate($server);
    @unlink($LOG);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM cmdb_objects WHERE name LIKE " . $conn->quote("$tag-%") . ") + (SELECT COUNT(*) FROM cmdb_classes WHERE class_key LIKE $like)"
        . " + (SELECT COUNT(*) FROM cmdb_relationship_types WHERE verb LIKE $like) + (SELECT COUNT(*) FROM analysts WHERE username LIKE " . $conn->quote("{$tag}_%") . ")"
        . " + (SELECT COUNT(*) FROM tenants WHERE slug = " . $conn->quote($tag) . ") + (SELECT COUNT(*) FROM cmdb_ai_facts WHERE subject LIKE " . $conn->quote("%ZZ $tag%") . ")")->fetchColumn();
    check('cleanup: nothing ZZ is left', $left === 0, (string)$left);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
