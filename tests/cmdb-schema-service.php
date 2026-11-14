<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * CmdbSchemaService - the CMDB schema's write rules (GitHub #313 slice 2).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/cmdb-schema-service.php
 *
 * The rules used to live in api/cmdb/save_class.php, save_class_property.php and
 * save_relationship_type.php. This proves each one through the service, then that
 * the endpoints - now thin - still answer exactly as before (the settings screen
 * reads those replies). Every refusal has a positive control. Everything made is
 * ZZ-named and removed by id in finally.
 *
 * @wiki CMDB-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/cmdb_schema.php';

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
/** Runs $fn and returns the ServiceError message, or '' when it did not throw. */
function refused(callable $fn): string
{
    try { $fn(); return ''; } catch (ServiceError $e) { return $e->getMessage() ?: 'refused'; }
}

$tag = 'zz' . bin2hex(random_bytes(3));
$TAG = strtoupper($tag);
$m = ['classes' => [], 'rel' => [], 'objects' => [], 'sessions' => []];
$sessDir = rtrim((string)ini_get('session.save_path'), '/\\') ?: sys_get_temp_dir();
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ tester');
$no = ['emit' => false];
$classIds = function () use ($conn, $tag): array {
    return array_map('intval', $conn->query("SELECT id FROM cmdb_classes WHERE class_key LIKE " . $conn->quote("{$tag}%"))->fetchAll(PDO::FETCH_COLUMN));
};

try {
    echo "The one list of property types\n";
    $types = cmdbPropertyTypes();
    check('has the six basic types and the seven record types', count($types) === 13
        && !array_diff(['text', 'number', 'date', 'boolean', 'dropdown', 'object_ref', 'person', 'analyst', 'team', 'location', 'company', 'supplier', 'contract'], $types), implode(',', $types));
    check('the old per-file lists are gone', !defined('VALID_PROPERTY_TYPES') && !defined('CMDB_AI_PROPERTY_TYPES'));
    $src = file_get_contents(__DIR__ . '/../api/cmdb/ai_suggest_properties.php') . file_get_contents(__DIR__ . '/../api/cmdb/_ai_helpers.php');
    check('the AI wizard uses cmdbPropertyTypes()', strpos($src, 'cmdbPropertyTypes()') !== false && strpos($src, 'CMDB_AI_PROPERTY_TYPES') === false);

    echo "Classes\n";
    $a = CmdbSchemaService::createClass($conn, $ctx, ['name' => "$TAG Web Server", 'is_active' => 1], $no);
    $m['classes'][] = $a['id'];
    check('a blank key comes from the name', $a['class_key'] === "{$tag}_web_server", $a['class_key']);
    check('...and it reports created, with the event held back (emit off)', $a['created'] && ($a['events'][0] ?? [])[0] === 'cmdb_class');
    $b = CmdbSchemaService::createClass($conn, $ctx, ['name' => "$TAG Web Server"], $no);
    $m['classes'][] = $b['id'];
    check('a key already in use gets _2', $b['class_key'] === "{$tag}_web_server_2", $b['class_key']);
    $c = CmdbSchemaService::createClass($conn, $ctx, ['name' => "$TAG other", 'class_key' => "{$tag}_web_server"], $no);
    $m['classes'][] = $c['id'];
    check('...an explicit key too (_3)', $c['class_key'] === "{$tag}_web_server_3", $c['class_key']);
    check('a blank name is refused', refused(fn() => CmdbSchemaService::createClass($conn, $ctx, ['name' => '  '])) === 'Name is required');
    check('a 151-character name is refused', refused(fn() => CmdbSchemaService::createClass($conn, $ctx, ['name' => str_repeat('x', 151)])) === 'Name too long (max 150 chars)');
    check('a key with capitals is refused', refused(fn() => CmdbSchemaService::createClass($conn, $ctx, ['name' => 'x', 'class_key' => 'Bad Key'])) !== '');
    check('a 101-character key is refused', refused(fn() => CmdbSchemaService::createClass($conn, $ctx, ['name' => 'x', 'class_key' => str_repeat('k', 101)])) === 'Key too long (max 100 chars)');
    check('an update to another class\'s key is refused', refused(fn() => CmdbSchemaService::saveClass($conn, $ctx, ['id' => $b['id'], 'name' => 'x', 'class_key' => $a['class_key']])) === 'Another class already uses that key');
    $u = CmdbSchemaService::saveClass($conn, $ctx, ['id' => $b['id'], 'name' => "$TAG Renamed", 'class_key' => $b['class_key'], 'is_active' => 1], $no);
    check('...while a rename keeping its own key is fine', !$u['created'] && $conn->query("SELECT name FROM cmdb_classes WHERE id = {$b['id']}")->fetchColumn() === "$TAG Renamed");

    echo "Property definitions\n";
    $cls = $a['id'];
    $P = fn(array $in) => CmdbSchemaService::saveProperty($conn, $ctx, $in + ['class_id' => $cls], ['emit' => false]);
    check('no class is refused', refused(fn() => CmdbSchemaService::saveProperty($conn, $ctx, ['label' => 'x', 'property_type' => 'text'])) === 'class_id is required');
    check('no label is refused', refused(fn() => $P(['label' => '', 'property_type' => 'text'])) === 'Label is required');
    check('an unknown type is refused', refused(fn() => $P(['label' => 'x', 'property_type' => 'colour'])) === 'Invalid property type');
    check('an object reference without a target is refused', refused(fn() => $P(['label' => 'x', 'property_type' => 'object_ref'])) === 'Object reference properties need a target class');
    $p1 = $P(['label' => 'Owner', 'property_type' => 'text', 'spreads_impact' => 1, 'target_class_id' => $b['id']]);
    $row = $conn->query("SELECT property_key, spreads_impact, target_class_id FROM cmdb_class_properties WHERE id = {$p1['id']}")->fetch(PDO::FETCH_ASSOC);
    check('a text property drops spreads_impact and the target', (int)$row['spreads_impact'] === 0 && $row['target_class_id'] === null, json_encode($row));
    $p2 = $P(['label' => 'Owner', 'property_type' => 'person']);
    check('a property key already in the class gets _2', $p2['property_key'] === 'owner_2', $p2['property_key']);
    $p3 = $P(['label' => 'Host', 'property_type' => 'object_ref', 'target_class_id' => $b['id'], 'spreads_impact' => 1]);
    check('an object reference keeps its target and dependency flag', (int)$conn->query("SELECT spreads_impact FROM cmdb_class_properties WHERE id = {$p3['id']}")->fetchColumn() === 1);
    $p4 = $P(['label' => 'Tier', 'property_type' => 'dropdown', 'options' => ['Gold', ['value' => 'Silver', 'colour' => '#ccc'], '', ' Bronze ']]);
    $opts = $conn->query("SELECT option_value, colour FROM cmdb_class_property_options WHERE property_id = {$p4['id']} ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);
    check('dropdown options are saved in order, blanks skipped, trimmed, with a colour', array_column($opts, 'option_value') === ['Gold', 'Silver', 'Bronze'] && $opts[1]['colour'] === '#ccc', json_encode($opts));
    $before = (int)$conn->query("SELECT COUNT(*) FROM cmdb_class_properties WHERE class_id = $cls")->fetchColumn();
    check('a bad colour is refused', refused(fn() => $P(['label' => 'Bad', 'property_type' => 'dropdown', 'options' => [['value' => 'A', 'colour' => 'red']]])) !== '');
    check('...and the property it was part of is rolled back', (int)$conn->query("SELECT COUNT(*) FROM cmdb_class_properties WHERE class_id = $cls")->fetchColumn() === $before);
    $P(['id' => $p4['id'], 'label' => 'Tier', 'property_key' => 'tier', 'property_type' => 'text']);
    check('a dropdown changed to text loses its options', (int)$conn->query("SELECT COUNT(*) FROM cmdb_class_property_options WHERE property_id = {$p4['id']}")->fetchColumn() === 0);
    check('an update to another property\'s key is refused', refused(fn() => $P(['id' => $p4['id'], 'label' => 'Tier', 'property_key' => 'owner', 'property_type' => 'text'])) === 'Another property in this class already uses that key');

    // The #312 type-change guard: a person property with a stored value
    $conn->prepare("INSERT INTO cmdb_objects (class_id, name) VALUES (?, ?)")->execute([$cls, "$TAG obj"]);
    $obj = $m['objects'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO cmdb_object_properties (object_id, property_id, value_text) VALUES (?, ?, ?)")->execute([$obj, $p1['id'], 'Alice']);
    check('text -> dropdown with values is fine (same home)', refused(fn() => $P(['id' => $p1['id'], 'label' => 'Owner', 'property_key' => 'owner', 'property_type' => 'dropdown', 'options' => ['Alice']])) === '');
    check('dropdown -> number with values is refused', refused(fn() => $P(['id' => $p1['id'], 'label' => 'Owner', 'property_key' => 'owner', 'property_type' => 'number'])) === 'This property already has values, so its type cannot change to one that stores them differently. Add a new property instead.');
    check('person -> team without values is fine', refused(fn() => $P(['id' => $p2['id'], 'label' => 'Owner', 'property_key' => 'owner_2', 'property_type' => 'team'])) === '');

    echo "Nesting inside a caller's transaction\n";
    $conn->beginTransaction();
    $nested = $P(['label' => 'Nested', 'property_type' => 'text']);
    check('saveProperty does not commit a transaction it did not open', $conn->inTransaction());
    $conn->rollBack();
    check('...so the caller\'s rollback removes it', (int)$conn->query("SELECT COUNT(*) FROM cmdb_class_properties WHERE id = {$nested['id']}")->fetchColumn() === 0);

    echo "Relationship types\n";
    $R = fn(array $in) => CmdbSchemaService::saveRelationshipType($conn, $ctx, $in, ['emit' => false]);
    $r1 = $R(['verb' => "$tag hosts", 'inverse_verb' => "$tag is hosted on", 'impact_direction' => 'sideways', 'is_active' => 1]);
    $m['rel'][] = $r1['id'];
    check('an unknown impact direction is stored as none', $conn->query("SELECT impact_direction FROM cmdb_relationship_types WHERE id = {$r1['id']}")->fetchColumn() === 'none');
    check('a verb already used is refused', refused(fn() => $R(['verb' => "$tag hosts", 'inverse_verb' => 'x'])) === 'Another relationship type already uses that verb');
    check('no verb is refused', refused(fn() => $R(['verb' => '', 'inverse_verb' => 'x'])) === 'Verb is required');
    check('no inverse verb is refused', refused(fn() => $R(['verb' => 'x', 'inverse_verb' => ''])) === 'Inverse verb is required');
    check('a 101-character verb is refused', refused(fn() => $R(['verb' => str_repeat('v', 101), 'inverse_verb' => 'x'])) === 'Verb too long (max 100 chars)');
    check('a redundancy rule without an impact direction is refused', refused(fn() => $R(['id' => $r1['id'], 'verb' => "$tag hosts", 'inverse_verb' => 'x', 'redundancy' => 'membership'])) !== '');
    $r2 = $R(['id' => $r1['id'], 'verb' => "$tag hosts", 'inverse_verb' => "$tag is hosted on", 'impact_direction' => 'from_to', 'redundancy' => 'membership', 'is_active' => 1]);
    $row = $conn->query("SELECT impact_direction, redundancy FROM cmdb_relationship_types WHERE id = {$r1['id']}")->fetch(PDO::FETCH_ASSOC);
    check('...and with one it saves (updating its own verb is not a duplicate)', !$r2['created'] && $row['impact_direction'] === 'from_to' && $row['redundancy'] === 'membership', json_encode($row));

    if ($base !== '') {
        echo "The endpoints answer as before (over HTTP)\n";
        $sid = $m['sessions'][] = 'zzcs' . bin2hex(random_bytes(6));
        $csrf = bin2hex(random_bytes(32));
        file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_name|s:5:"zztst";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');
        $http = function (string $path, array $post) use ($base, $sid, $csrf): string {
            $ch = curl_init("$base/$path");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid",
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post), CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $csrf"]]);
            return (string)curl_exec($ch);
        };
        $raw = $http('api/cmdb/save_class.php', ['name' => "$TAG Http", 'is_active' => true]);
        $j = json_decode($raw, true) ?: [];
        if (!empty($j['id'])) $m['classes'][] = (int)$j['id'];
        check('save_class create: {success, id, class_key}', $raw === json_encode(['success' => true, 'id' => (int)($j['id'] ?? 0), 'class_key' => "{$tag}_http"]), $raw);
        $raw = $http('api/cmdb/save_class.php', ['id' => $j['id'] ?? 0, 'name' => "$TAG Http2", 'class_key' => "{$tag}_http", 'is_active' => true]);
        check('save_class update: {success, id}', $raw === json_encode(['success' => true, 'id' => (int)($j['id'] ?? 0)]), $raw);
        $raw = $http('api/cmdb/save_class.php', ['name' => '']);
        check('save_class error: the same message', $raw === '{"success":false,"error":"Name is required"}', $raw);
        $raw = $http('api/cmdb/save_class.php', ['name' => 'x', 'class_key' => str_repeat('k', 101)]);
        check('save_class error: the length message', $raw === '{"success":false,"error":"Key too long (max 100 chars)"}', $raw);

        $raw = $http('api/cmdb/save_class_property.php', ['class_id' => $cls, 'label' => 'Http prop', 'property_type' => 'dropdown', 'options' => [['value' => 'A', 'colour' => '#fff']]]);
        $j = json_decode($raw, true) ?: [];
        check('save_class_property: {success, id, property_key}', $raw === json_encode(['success' => true, 'id' => (int)($j['id'] ?? 0), 'property_key' => 'http_prop']), $raw);
        $raw = $http('api/cmdb/save_class_property.php', ['class_id' => $cls, 'label' => 'x', 'property_type' => 'nope']);
        check('save_class_property error: the same message', $raw === '{"success":false,"error":"Invalid property type"}', $raw);
        $raw = $http('api/cmdb/save_class_property.php', ['id' => $p1['id'], 'class_id' => $cls, 'label' => 'Owner', 'property_key' => 'owner', 'property_type' => 'date']);
        check('save_class_property: the type-change refusal comes through', strpos($raw, 'already has values') !== false, $raw);

        $raw = $http('api/cmdb/save_relationship_type.php', ['verb' => "$tag feeds", 'inverse_verb' => "$tag is fed by", 'impact_direction' => 'to_from', 'is_active' => true]);
        $j = json_decode($raw, true) ?: [];
        if (!empty($j['id'])) $m['rel'][] = (int)$j['id'];
        check('save_relationship_type: {success, id}', $raw === json_encode(['success' => true, 'id' => (int)($j['id'] ?? 0)]), $raw);
        $raw = $http('api/cmdb/save_relationship_type.php', ['verb' => "$tag feeds", 'inverse_verb' => 'x']);
        check('save_relationship_type error: the same message', $raw === '{"success":false,"error":"Another relationship type already uses that verb"}', $raw);
    }
} catch (Throwable $x) {
    check('no exception', false, get_class($x) . ': ' . $x->getMessage() . ' @ ' . basename($x->getFile()) . ':' . $x->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    foreach ($m['sessions'] as $sid) @unlink("$sessDir/sess_$sid");
    foreach ($m['objects'] as $id) { $conn->exec("DELETE FROM cmdb_object_properties WHERE object_id = " . (int)$id); $conn->exec("DELETE FROM cmdb_objects WHERE id = " . (int)$id); }
    $ids = array_unique(array_merge($m['classes'], $classIds()));
    foreach ($ids as $id) {
        $id = (int)$id;
        $conn->exec("DELETE FROM cmdb_class_property_options WHERE property_id IN (SELECT id FROM cmdb_class_properties WHERE class_id = $id)");
        $conn->exec("DELETE FROM cmdb_class_properties WHERE class_id = $id");
        $conn->exec("DELETE FROM cmdb_classes WHERE id = $id");
    }
    foreach ($m['rel'] as $id) $conn->exec("DELETE FROM cmdb_relationship_types WHERE id = " . (int)$id);
    $conn->exec("DELETE FROM cmdb_relationship_types WHERE verb LIKE " . $conn->quote("$tag %"));
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM cmdb_classes WHERE class_key LIKE " . $conn->quote("$tag%") . ")
                                    + (SELECT COUNT(*) FROM cmdb_relationship_types WHERE verb LIKE " . $conn->quote("$tag %") . ")")->fetchColumn();
    check('cleaned up', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
