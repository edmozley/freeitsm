<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Network Mapper - pages (tabs) and layers (GitHub #433).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/network-mapper-pages.php
 *
 * network_diagram_pages / network_diagram_layers and the page_key / layer_key every node and
 * shape carries, through NetworkMapperService: saved, cleaned (a repeated key, a layer of a page
 * that does not exist), refused with no page at all (with a positive control), LEFT ALONE by a
 * save that does not send them, copied by createVersion and removed by deleteDiagram.
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
$q = function (string $sql) use ($conn) { return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC); };

try {
    $d = NetworkMapperService::createDiagram($conn, $ctx, ['title' => 'ZZ pages ' . bin2hex(random_bytes(3))]);
    $made[] = $d;
    $in = [
        'nodes' => [
            ['cmdb_object_id' => $objs[0], 'x' => 10, 'y' => 10, 'ref' => 'a', 'page_key' => 0, 'layer_key' => 0],
            ['cmdb_object_id' => $objs[1], 'x' => 10, 'y' => 10, 'ref' => 'b', 'page_key' => 1, 'layer_key' => 3],
        ],
        'connectors' => [],
        'shapes' => [['shape_type' => 'zone', 'x' => 0, 'y' => 0, 'w' => 200, 'h' => 100, 'page_key' => 1, 'layer_key' => 0, 'z' => 1]],
        'pages' => [
            ['page_key' => 0, 'name' => 'Overview', 'sort' => 0],
            ['page_key' => 1, 'name' => str_repeat('L', 140), 'sort' => 1],
            ['page_key' => 1, 'name' => 'duplicate key - dropped', 'sort' => 2],
        ],
        'layers' => [
            ['page_key' => 0, 'layer_key' => 0, 'name' => 'Base', 'sort' => 0],
            ['page_key' => 1, 'layer_key' => 0, 'name' => 'Base', 'sort' => 0],
            ['page_key' => 1, 'layer_key' => 3, 'name' => 'Servers', 'sort' => 1, 'visible' => false, 'locked' => true],
            ['page_key' => 9, 'layer_key' => 0, 'name' => 'Orphan - dropped', 'sort' => 0],
        ],
    ];
    echo "Saving\n";
    NetworkMapperService::saveDiagram($conn, $ctx, $d, $in);
    $pages = $q("SELECT page_key, name FROM network_diagram_pages WHERE diagram_id = $d ORDER BY sort");
    check('two pages saved; a repeated key is dropped; a long name is cut to 100', count($pages) === 2 && $pages[0]['name'] === 'Overview' && mb_strlen($pages[1]['name']) === 100, json_encode($pages));
    $layers = $q("SELECT page_key, layer_key, name, visible, locked FROM network_diagram_layers WHERE diagram_id = $d ORDER BY page_key, sort");
    check('layers saved with hidden / locked; a layer of a missing page is dropped', count($layers) === 3 && (int)$layers[2]['visible'] === 0 && (int)$layers[2]['locked'] === 1, json_encode($layers));
    $nodes = $q("SELECT page_key, layer_key FROM network_diagram_nodes WHERE diagram_id = $d ORDER BY page_key");
    check('nodes keep their page and layer', (int)$nodes[1]['page_key'] === 1 && (int)$nodes[1]['layer_key'] === 3, json_encode($nodes));
    check('shapes keep theirs', (int)$q("SELECT page_key FROM network_diagram_shapes WHERE diagram_id = $d")[0]['page_key'] === 1);

    echo "Refusals, with a positive control\n";
    check('a diagram with no pages at all is refused', refused(fn() => NetworkMapperService::saveDiagram($conn, $ctx, $d, ['pages' => []])) === ''
        ? false : true);
    check('...the refusal changed nothing', count($q("SELECT id FROM network_diagram_pages WHERE diagram_id = $d")) === 2);
    check('...while a single page saves', refused(fn() => NetworkMapperService::saveDiagram($conn, $ctx, $d, ['pages' => [['page_key' => 0, 'name' => 'Only']], 'layers' => []])) === ''
        && count($q("SELECT id FROM network_diagram_pages WHERE diagram_id = $d")) === 1);
    NetworkMapperService::saveDiagram($conn, $ctx, $d, $in);

    echo "Saves that do not send pages leave them alone\n";
    NetworkMapperService::saveDiagram($conn, $ctx, $d, ['title' => 'ZZ pages renamed']);
    check('a metadata-only save keeps pages and layers', count($q("SELECT id FROM network_diagram_pages WHERE diagram_id = $d")) === 2
        && count($q("SELECT id FROM network_diagram_layers WHERE diagram_id = $d")) === 3);

    echo "Versions and delete\n";
    $v2 = NetworkMapperService::createVersion($conn, $ctx, $d, ['version_label' => 'v2']);
    $made[] = $v2;
    check('a new version copies the pages and layers', count($q("SELECT id FROM network_diagram_pages WHERE diagram_id = $v2")) === 2
        && count($q("SELECT id FROM network_diagram_layers WHERE diagram_id = $v2 AND locked = 1")) === 1);
    check('...and every item keeps its page and layer', count($q("SELECT id FROM network_diagram_nodes WHERE diagram_id = $v2 AND page_key = 1 AND layer_key = 3")) === 1
        && count($q("SELECT id FROM network_diagram_shapes WHERE diagram_id = $v2 AND page_key = 1")) === 1);
    NetworkMapperService::deleteDiagram($conn, $ctx, $v2);
    $made = array_values(array_diff($made, [$v2]));
    check('deleting a version removes its pages and layers', count($q("SELECT id FROM network_diagram_pages WHERE diagram_id = $v2")) === 0
        && count($q("SELECT id FROM network_diagram_layers WHERE diagram_id = $v2")) === 0);
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
