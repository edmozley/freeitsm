<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * REST API v1 and the Network Mapper's drawing features (GitHub #433): colour, box size,
 * style, pages, layers, groups, shapes, connectors that end on a shape, Knowledge links.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/api-network-mapper-drawing.php
 *
 * Over real HTTP with a temporary key. GET returns all of it; a client that READS a diagram
 * and WRITES IT BACK (full-replace PATCH) keeps all of it - the bug this test was written for:
 * before, GET returned none of it, so a read-then-write reset every node to the plain look on
 * page 0 and lost the shapes' connectors. Then the single-node / single-connector endpoints:
 * the new node fields, a dotted line, a connector between shapes, and the refusals (each with
 * a positive control). A ZZ diagram and the key are removed in finally. Needs two CMDB objects.
 *
 * @wiki REST-API-Network-Mapper
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/network_mapper.php';

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
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
$rest = function (string $method, string $path, string $key, ?array $body = null) use ($base): array {
    $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode($path));
    $h = ['Authorization: Bearer ' . $key];
    if ($body !== null) $h[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 300)]];
};

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ tester');
$objs = array_map('intval', $conn->query("SELECT id FROM cmdb_objects ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
if (count($objs) < 2) { echo "(skipped: needs two CMDB objects)\n"; exit(0); }

$d = 0; $keyId = 0;
try {
    $key = 'fitsm_' . bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime) VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP())')
         ->execute(['ZZ nm drawing key', substr($key, 0, 12), hash('sha256', $key), $admin, json_encode(['network_diagrams' => ['read', 'create', 'update']])]);
    $keyId = (int)$conn->lastInsertId();

    $d = NetworkMapperService::createDiagram($conn, $ctx, ['title' => 'ZZ api drawing ' . bin2hex(random_bytes(3))]);
    NetworkMapperService::saveDiagram($conn, $ctx, $d, [
        'nodes' => [
            ['cmdb_object_id' => $objs[0], 'x' => 0, 'y' => 0, 'ref' => 'a', 'colour_override' => '#dc2626', 'w' => 200, 'h' => 120,
             'style' => ['fill' => 'solid', 'fillColour' => '#fef3c7'], 'page_key' => 1, 'layer_key' => 2, 'group_key' => 'gZZ'],
            ['cmdb_object_id' => $objs[1], 'x' => 300, 'y' => 0, 'ref' => 'b'],
        ],
        'shapes' => [
            ['shape_type' => 'rectangle', 'x' => 0, 'y' => 300, 'w' => 100, 'h' => 50, 'uid' => 'uZZa', 'z' => 1, 'page_key' => 1, 'group_key' => 'gZZ'],
            ['shape_type' => 'cloud', 'x' => 300, 'y' => 300, 'w' => 100, 'h' => 50, 'uid' => 'uZZb', 'z' => 2],
        ],
        'connectors' => [
            ['from_ref' => 'a', 'to_ref' => 'b'],
            ['from_shape_uid' => 'uZZa', 'to_shape_uid' => 'uZZb', 'line_style' => 'dotted', 'style' => ['colour' => '#2563eb', 'arrowEnd' => 'diamond']],
        ],
        'pages' => [['page_key' => 0, 'name' => 'Overview', 'sort' => 0], ['page_key' => 1, 'name' => 'ZZ site', 'sort' => 1]],
        'layers' => [['page_key' => 1, 'layer_key' => 2, 'name' => 'ZZ racks', 'sort' => 2, 'visible' => true, 'locked' => true]],
    ]);

    echo "GET returns the drawing\n";
    [$code, $r] = $rest('GET', "/network-diagrams/$d", $key);
    $g = $r['data'] ?? [];
    $na = array_values(array_filter($g['nodes'] ?? [], fn($n) => $n['object']['id'] === $objs[0]))[0] ?? [];
    check('200, and a node carries its colour, box size, style, page, layer and group', $code === 200
        && ($na['colour_override'] ?? null) === '#dc2626' && ($na['w'] ?? null) === 200 && ($na['h'] ?? null) === 120
        && ($na['style']['fillColour'] ?? null) === '#fef3c7' && ($na['page_key'] ?? null) === 1 && ($na['layer_key'] ?? null) === 2
        && ($na['group_key'] ?? null) === 'gZZ', "$code " . json_encode($na));
    $sc = array_values(array_filter($g['connectors'] ?? [], fn($k) => ($k['from']['shape_uid'] ?? null) === 'uZZa'))[0] ?? null;
    check('the connector between shapes is listed (it was hidden by an inner join), with its style', count($g['connectors'] ?? []) === 2 && $sc
        && $sc['from']['node_id'] === null && $sc['to']['shape_uid'] === 'uZZb' && $sc['line_style'] === 'dotted' && ($sc['style']['arrowEnd'] ?? null) === 'diamond',
        json_encode($g['connectors'] ?? null));
    check('shapes, pages and layers are returned', count($g['shapes'] ?? []) === 2 && count($g['pages'] ?? []) === 2
        && ($g['layers'][0]['locked'] ?? null) === true && ($g['shapes'][0]['uid'] ?? null) === 'uZZa', json_encode([$g['shapes'] ?? null, $g['pages'] ?? null, $g['layers'] ?? null]));
    check('the layout box includes the resized node and the shapes', ($g['layout']['bounds']['max_y'] ?? 0) >= 350 && ($g['layout']['bounds']['max_x'] ?? 0) >= 400, json_encode($g['layout'] ?? null));

    echo "Read it, write it back (full-replace PATCH)\n";
    $nodes = array_map(fn($n) => ['cmdb_object_id' => $n['object']['id'], 'ref' => 'n' . $n['id']]
        + array_intersect_key($n, array_flip(['x', 'y', 'size', 'icon_override', 'colour_override', 'w', 'h', 'style', 'page_key', 'layer_key', 'group_key', 'article_id'])), $g['nodes']);
    $end = fn(array $e, string $p) => $e['shape_uid'] !== null ? ["{$p}_shape_uid" => $e['shape_uid']] : ["{$p}_ref" => 'n' . $e['node_id']];
    $connectors = array_map(fn($k) => $end($k['from'], 'from') + $end($k['to'], 'to') + ['label' => $k['label'], 'line_style' => $k['line_style'], 'style' => $k['style']], $g['connectors']);
    [$code, $r] = $rest('PATCH', "/network-diagrams/$d", $key, ['nodes' => $nodes, 'connectors' => $connectors,
        'shapes' => $g['shapes'], 'pages' => $g['pages'], 'layers' => $g['layers']]);
    $n0 = $conn->query("SELECT * FROM network_diagram_nodes WHERE diagram_id = $d AND cmdb_object_id = {$objs[0]}")->fetch(PDO::FETCH_ASSOC);
    check('200, and the node kept its colour, box size, style, page, layer and group', $code === 200 && $n0['colour_override'] === '#dc2626'
        && (int)$n0['w'] === 200 && json_decode((string)$n0['style'], true)['fillColour'] === '#fef3c7'
        && (int)$n0['page_key'] === 1 && (int)$n0['layer_key'] === 2 && $n0['group_key'] === 'gZZ', "$code " . json_encode([$r, $n0]));
    $kc = $conn->query("SELECT from_shape_uid, to_shape_uid, line_style, style FROM network_diagram_connectors WHERE diagram_id = $d AND from_shape_uid IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    check('...the connector between shapes survived, dotted, with its style', count($kc) === 1 && $kc[0]['to_shape_uid'] === 'uZZb'
        && $kc[0]['line_style'] === 'dotted' && json_decode($kc[0]['style'], true)['colour'] === '#2563eb', json_encode($kc));
    check('...and so did the shapes, pages and the locked layer',
        (int)$conn->query("SELECT COUNT(*) FROM network_diagram_shapes WHERE diagram_id = $d")->fetchColumn() === 2
        && (int)$conn->query("SELECT COUNT(*) FROM network_diagram_pages WHERE diagram_id = $d")->fetchColumn() === 2
        && (int)$conn->query("SELECT locked FROM network_diagram_layers WHERE diagram_id = $d AND page_key = 1 AND layer_key = 2")->fetchColumn() === 1);

    echo "One node at a time\n";
    $nid = (int)$conn->query("SELECT id FROM network_diagram_nodes WHERE diagram_id = $d AND cmdb_object_id = {$objs[1]}")->fetchColumn();
    [$code, $r] = $rest('PATCH', "/network-diagrams/$d/nodes/$nid", $key, ['colour_override' => '#0D9488', 'w' => 180, 'page_key' => 1, 'group_key' => 'gZZ', 'style' => ['bold' => true]]);
    check('PATCH a node sets colour (lower-cased), width, page, group and style', $code === 200 && ($r['data']['colour_override'] ?? null) === '#0d9488'
        && ($r['data']['w'] ?? null) === 180 && ($r['data']['page_key'] ?? null) === 1 && ($r['data']['group_key'] ?? null) === 'gZZ'
        && ($r['data']['style']['bold'] ?? null) === true, "$code " . json_encode($r));
    [$code] = $rest('PATCH', "/network-diagrams/$d/nodes/$nid", $key, ['x' => 10]);
    check('...a PATCH that does not mention them leaves them alone', $code === 200
        && $conn->query("SELECT colour_override FROM network_diagram_nodes WHERE id = $nid")->fetchColumn() === '#0d9488');
    [$code] = $rest('PATCH', "/network-diagrams/$d/nodes/$nid", $key, ['colour_override' => 'rgba(0,0,0,1)']);
    [$code2] = $rest('PATCH', "/network-diagrams/$d/nodes/$nid", $key, ['w' => 5]);
    check('a bad colour and a width under 20 are 422', $code === 422 && $code2 === 422, "$code $code2");
    [$code] = $rest('PATCH', "/network-diagrams/$d/nodes/$nid", $key, ['colour_override' => null, 'w' => null]);
    check('...while null clears them', $code === 200 && $conn->query("SELECT colour_override IS NULL AND w IS NULL FROM network_diagram_nodes WHERE id = $nid")->fetchColumn() == 1);

    echo "One connector at a time\n";
    [$code, $r] = $rest('POST', "/network-diagrams/$d/connectors", $key, ['from_shape_uid' => 'uZZb', 'to_object_id' => $objs[1], 'line_style' => 'dotted', 'style' => ['routing' => 'elbow', 'evil' => 1]]);
    $cid = (int)($r['data']['id'] ?? 0);
    check('POST a connector from a shape to a node, dotted, style cleaned', $code === 201 && ($r['data']['from']['shape_uid'] ?? null) === 'uZZb'
        && ($r['data']['to']['object_id'] ?? null) === $objs[1] && ($r['data']['style'] ?? null) === ['routing' => 'elbow'], "$code " . json_encode($r));
    [$code] = $rest('POST', "/network-diagrams/$d/connectors", $key, ['from_shape_uid' => 'nope', 'to_shape_uid' => 'uZZb']);
    check('a shape that is not on the diagram is 422', $code === 422, (string)$code);
    [$code] = $rest('PATCH', "/network-diagrams/$d/connectors/$cid", $key, ['cmdb_relationship_id' => 'auto']);
    check('a connector that ends on a shape cannot be bound to a CMDB relationship (422)', $code === 422, (string)$code);
    [$code, $r] = $rest('PATCH', "/network-diagrams/$d/connectors/$cid", $key, ['label' => 'ZZ uplink', 'style' => ['colour' => '#16A34A']]);
    check('...while its label and style can change', $code === 200 && ($r['data']['label'] ?? null) === 'ZZ uplink' && ($r['data']['style']['colour'] ?? null) === '#16a34a', "$code " . json_encode($r));
} catch (Throwable $e) {
    check('no unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($d) {
        foreach (['network_diagram_connectors', 'network_diagram_nodes', 'network_diagram_shapes', 'network_diagram_layers', 'network_diagram_pages', 'network_diagram_articles'] as $t) {
            $conn->exec("DELETE FROM $t WHERE diagram_id = $d");
        }
        $conn->exec("DELETE FROM network_diagrams WHERE id = $d");
    }
    if ($keyId) $conn->exec("DELETE FROM api_keys WHERE id = $keyId");
    check('cleaned up', !$d || (int)$conn->query("SELECT COUNT(*) FROM network_diagrams WHERE id = $d")->fetchColumn() === 0);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
