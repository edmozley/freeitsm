<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Network Mapper - connectors between anything, with their own look (GitHub #433).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/network-mapper-connectors.php
 *
 * A connector end is a node (from_node_id / to_node_id) or a shape (from_shape_uid /
 * to_shape_uid). Proves through NetworkMapperService: node-node, shape-shape and shape-node
 * connectors save; the style JSON is cleaned; dotted is a line style; a connector to itself
 * and one with no usable end are refused (each with a positive control); a CMDB relationship
 * is never kept on a shape end; shape uids and shape connectors survive createVersion.
 * Everything made is a ZZ diagram, deleted in finally. Needs two CMDB objects.
 *
 * @wiki Network-Mapper
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/network_mapper.php';

$expected = getenv('FREEITSM_TEST_DATABASE');
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
function refused(callable $fn): string
{
    try { $fn(); return ''; } catch (ServiceError $e) { return $e->getMessage() ?: 'refused'; }
}

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ tester');
$objs = array_map('intval', $conn->query("SELECT id FROM cmdb_objects ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
if (count($objs) < 2) { echo "(skipped: needs two CMDB objects)\n"; exit(0); }
$made = [];
$rows = function (int $id) use ($conn) {
    return $conn->query("SELECT from_node_id, to_node_id, from_shape_uid, to_shape_uid, cmdb_relationship_id, line_style, style FROM network_diagram_connectors WHERE diagram_id = $id ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
};

try {
    $d = NetworkMapperService::createDiagram($conn, $ctx, ['title' => 'ZZ connectors ' . bin2hex(random_bytes(3))]);
    $made[] = $d;
    $nodes = [
        ['cmdb_object_id' => $objs[0], 'x' => 0, 'y' => 0, 'ref' => 'a'],
        ['cmdb_object_id' => $objs[1], 'x' => 300, 'y' => 0, 'ref' => 'b'],
    ];
    $shapes = [
        ['shape_type' => 'rectangle', 'x' => 0, 'y' => 200, 'w' => 100, 'h' => 50, 'uid' => 'uZZa', 'z' => 1],
        ['shape_type' => 'cloud', 'x' => 300, 'y' => 200, 'w' => 100, 'h' => 50, 'uid' => 'uZZb', 'z' => 2],
    ];
    $save = fn(array $connectors) => NetworkMapperService::saveDiagram($conn, $ctx, $d, ['nodes' => $nodes, 'connectors' => $connectors, 'shapes' => $shapes]);

    echo "Saving\n";
    $save([
        ['from_ref' => 'a', 'to_ref' => 'b', 'line_style' => 'solid'],
        ['from_shape_uid' => 'uZZa', 'to_shape_uid' => 'uZZb', 'line_style' => 'dotted', 'label' => 'backup',
         'style' => ['colour' => '#DC2626', 'width' => 4, 'arrowStart' => 'diamond', 'arrowEnd' => 'open', 'routing' => 'elbow', 'evil' => 1, 'width2' => 99]],
        ['from_shape_uid' => 'uZZb', 'to_ref' => 'b', 'cmdb_relationship_id' => 'auto'],
    ]);
    $r = $rows($d);
    check('three connectors: node-node, shape-shape, shape-node', count($r) === 3 && $r[0]['from_node_id'] !== null && $r[1]['from_shape_uid'] === 'uZZa' && $r[2]['to_node_id'] !== null && $r[2]['from_shape_uid'] === 'uZZb', json_encode($r));
    check('a shape end has no node, and dotted is a line style', $r[1]['from_node_id'] === null && $r[1]['to_node_id'] === null && $r[1]['line_style'] === 'dotted');
    check('the style is kept, colour lower-cased, unknown keys dropped', json_decode($r[1]['style'], true) === ['colour' => '#dc2626', 'width' => 4, 'arrowStart' => 'diamond', 'arrowEnd' => 'open', 'routing' => 'elbow'], (string)$r[1]['style']);
    check('a CMDB relationship is never kept on a shape end', $r[2]['cmdb_relationship_id'] === null);

    // DB Verify's orphan sweep, run as the SELECT it would delete: its own SQL, read from
    // api/system/db_verify.php, must not count a shape end (NULL node id) as an orphan.
    preg_match_all('~"(DELETE c FROM network_diagram_connectors c LEFT JOIN network_diagram_nodes[^"]+)"~', (string)file_get_contents(__DIR__ . '/../api/system/db_verify.php'), $m);
    $wouldGo = 0;
    foreach ($m[1] as $sql) {
        $wouldGo += (int)$conn->query(preg_replace('~^DELETE c FROM~', 'SELECT COUNT(*) FROM', $sql) . " AND c.diagram_id = " . (int)$d)->fetchColumn();
    }
    check("DB Verify's orphan sweep leaves connectors that end on a shape alone", count($m[1]) === 2 && $wouldGo === 0, count($m[1]) . ' sweep queries, ' . $wouldGo . ' would be deleted');
    check('shapes keep their uid', $conn->query("SELECT GROUP_CONCAT(uid ORDER BY z) FROM network_diagram_shapes WHERE diagram_id = $d")->fetchColumn() === 'uZZa,uZZb');

    echo "Refusals, each with a positive control\n";
    check('a shape connected to itself is refused', refused(fn() => $save([['from_shape_uid' => 'uZZa', 'to_shape_uid' => 'uZZa']])) !== '');
    check('an end with no usable node or shape is refused', refused(fn() => $save([['from_shape_uid' => 'not valid!', 'to_shape_uid' => 'uZZb']])) !== '');
    check('an unknown line style is refused', refused(fn() => $save([['from_ref' => 'a', 'to_ref' => 'b', 'line_style' => 'wavy']])) !== '');
    check('...while a good list saves', refused(fn() => $save([['from_shape_uid' => 'uZZa', 'to_ref' => 'a']])) === '' && count($rows($d)) === 1);
    $save([['from_ref' => 'a', 'to_ref' => 'b'], ['from_shape_uid' => 'uZZa', 'to_shape_uid' => 'uZZb', 'style' => ['routing' => 'curved']]]);

    echo "Versions\n";
    $v2 = NetworkMapperService::createVersion($conn, $ctx, $d, ['version_label' => 'v2']);
    $made[] = $v2;
    $r2 = $rows($v2);
    check('a new version copies both connectors, the shape one by uid with its style', count($r2) === 2 && $r2[0]['from_node_id'] !== null
        && $r2[1]['from_shape_uid'] === 'uZZa' && json_decode($r2[1]['style'], true) === ['routing' => 'curved'], json_encode($r2));
    check('...and the shapes keep their uids', $conn->query("SELECT GROUP_CONCAT(uid ORDER BY z) FROM network_diagram_shapes WHERE diagram_id = $v2")->fetchColumn() === 'uZZa,uZZb');
    check('...the node connector points at the NEW version\'s nodes', (int)$conn->query("SELECT diagram_id FROM network_diagram_nodes WHERE id = " . (int)$r2[0]['from_node_id'])->fetchColumn() === $v2);
} catch (Throwable $e) {
    check('no unexpected error', false, $e->getMessage());
} finally {
    foreach (array_reverse($made) as $id) {
        foreach (['network_diagram_connectors', 'network_diagram_shapes', 'network_diagram_nodes', 'network_diagram_layers', 'network_diagram_pages'] as $tbl) {
            $conn->prepare("DELETE FROM $tbl WHERE diagram_id = ?")->execute([$id]);
        }
        $conn->prepare("DELETE FROM network_diagrams WHERE id = ?")->execute([$id]);
    }
    $left = $made ? (int)$conn->query("SELECT COUNT(*) FROM network_diagrams WHERE id IN (" . implode(',', $made) . ")")->fetchColumn() : 0;
    check('cleaned up', $left === 0);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
