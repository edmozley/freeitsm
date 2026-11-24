<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Network Mapper - freestanding shapes and the style JSON (GitHub #433).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/network-mapper-shapes.php
 *
 * network_diagram_shapes and network_diagram_nodes.style through NetworkMapperService:
 * sanitiseStyle keeps only known keys with sane values; shapes are saved, refused when
 * malformed (each refusal with a positive control), LEFT ALONE by a save that does not send
 * them, copied by createVersion and removed by deleteDiagram. Everything made is a ZZ
 * diagram, deleted in finally. Needs two CMDB objects on the install.
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
/** Runs $fn and returns the ServiceError message, or '' when it did not throw. */
function refused(callable $fn): string
{
    try { $fn(); return ''; } catch (ServiceError $e) { return $e->getMessage() ?: 'refused'; }
}

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ tester');
$objs = array_map('intval', $conn->query("SELECT id FROM cmdb_objects ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
if (count($objs) < 2) { echo "(skipped: needs two CMDB objects)\n"; exit(0); }
$made = [];
$shapesOf = function (int $id) use ($conn): array {
    $st = $conn->prepare("SELECT shape_type, x, y, w, h, rotation, text, style, layer, z FROM network_diagram_shapes WHERE diagram_id = ? ORDER BY z, id");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};

try {
    echo "sanitiseStyle\n";
    $j = NetworkMapperService::sanitiseStyle(['fill' => 'gradient', 'fillColour' => '#ABCDEF', 'fillColour2' => 'red', 'stroke' => 'dashed',
        'strokeWidth' => 2.25, 'opacity' => 150, 'shadow' => true, 'font' => 'comic', 'fontSize' => 14, 'evil' => '<script>', 'bold' => 'yes']);
    $st = json_decode((string)$j, true);
    check('keeps the known keys with good values (colour lower-cased, width rounded)', $st['fill'] === 'gradient' && $st['fillColour'] === '#abcdef'
        && $st['stroke'] === 'dashed' && $st['strokeWidth'] == 2.3 && $st['shadow'] === true && $st['font'] === 'comic' && $st['fontSize'] === 14, (string)$j);
    check('...and drops unknown keys, bad colours, out-of-range numbers and non-booleans', !isset($st['evil']) && !isset($st['fillColour2']) && !isset($st['opacity']) && !isset($st['bold']));
    check('nothing usable is NULL', NetworkMapperService::sanitiseStyle(['nope' => 1]) === null && NetworkMapperService::sanitiseStyle(null) === null);
    check('a JSON string is read too', json_decode((string)NetworkMapperService::sanitiseStyle('{"fill":"none"}'), true) === ['fill' => 'none']);

    $d = NetworkMapperService::createDiagram($conn, $ctx, ['title' => 'ZZ shapes ' . bin2hex(random_bytes(3))]);
    $made[] = $d;
    $nodes = [
        ['cmdb_object_id' => $objs[0], 'x' => 100, 'y' => 100, 'ref' => 'a', 'style' => ['fill' => 'solid', 'fillColour' => '#dcfce7', 'evil' => 1]],
        ['cmdb_object_id' => $objs[1], 'x' => 400, 'y' => 100, 'ref' => 'b'],
    ];
    $shapes = [
        ['shape_type' => 'zone', 'x' => 40, 'y' => 40, 'w' => 600, 'h' => 300, 'text' => "Site A\r\nRack 1", 'style' => ['stroke' => 'dashed'], 'layer' => 'back', 'z' => 1],
        ['shape_type' => 'cloud', 'x' => 700, 'y' => 60, 'w' => 140, 'h' => 90, 'rotation' => 15, 'text' => 'Internet', 'layer' => 'front', 'z' => 2],
        ['shape_type' => 'some_future_shape', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 99999, 'z' => 3],
    ];

    echo "Saving\n";
    NetworkMapperService::saveDiagram($conn, $ctx, $d, ['nodes' => $nodes, 'connectors' => [], 'shapes' => $shapes]);
    $rows = $shapesOf($d);
    check('three shapes saved in z order', count($rows) === 3 && $rows[0]['shape_type'] === 'zone' && $rows[1]['shape_type'] === 'cloud', json_encode(array_column($rows, 'shape_type')));
    check('text keeps its line break (CRLF made LF), style sanitised', $rows[0]['text'] === "Site A\nRack 1" && json_decode($rows[0]['style'], true) === ['stroke' => 'dashed']);
    check('rotation and layer kept', (int)$rows[1]['rotation'] === 15 && $rows[1]['layer'] === 'front' && $rows[0]['layer'] === 'back');
    check('an unknown but well-formed shape key is kept; sizes clamped to 10..5000', $rows[2]['shape_type'] === 'some_future_shape' && (int)$rows[2]['w'] === 10 && (int)$rows[2]['h'] === 5000);
    $ns = $conn->query("SELECT style FROM network_diagram_nodes WHERE diagram_id = $d ORDER BY cmdb_object_id")->fetchAll(PDO::FETCH_COLUMN);
    check('a node style is saved, sanitised; an unstyled node stays NULL', json_decode((string)$ns[0], true) === ['fill' => 'solid', 'fillColour' => '#dcfce7'] && $ns[1] === null, json_encode($ns));

    echo "Refusals, each with a positive control\n";
    check('a shape_type with capitals or spaces is refused', refused(fn() => NetworkMapperService::saveDiagram($conn, $ctx, $d, ['shapes' => [['shape_type' => 'Bad Type']]])) !== '');
    check('...a missing shape_type too', refused(fn() => NetworkMapperService::saveDiagram($conn, $ctx, $d, ['shapes' => [['x' => 1]]])) !== '');
    check('...a shape that is not an object too', refused(fn() => NetworkMapperService::saveDiagram($conn, $ctx, $d, ['shapes' => ['rectangle']])) !== '');
    check('the refused saves changed nothing', count($shapesOf($d)) === 3);
    check('...while a good shape list saves', refused(fn() => NetworkMapperService::saveDiagram($conn, $ctx, $d, ['shapes' => $shapes])) === '' && count($shapesOf($d)) === 3);

    echo "Saves that do not send shapes leave them alone\n";
    NetworkMapperService::saveDiagram($conn, $ctx, $d, ['title' => 'ZZ shapes renamed']);
    check('a metadata-only save keeps the shapes', count($shapesOf($d)) === 3);
    NetworkMapperService::saveDiagram($conn, $ctx, $d, ['nodes' => $nodes, 'connectors' => []]);
    check('a nodes-and-connectors save keeps the shapes', count($shapesOf($d)) === 3);
    NetworkMapperService::saveDiagram($conn, $ctx, $d, ['shapes' => []]);
    check('...while an empty shapes list clears them', count($shapesOf($d)) === 0);
    NetworkMapperService::saveDiagram($conn, $ctx, $d, ['shapes' => $shapes]);

    echo "Versions and delete\n";
    $v2 = NetworkMapperService::createVersion($conn, $ctx, $d, ['version_label' => 'v2']);
    $made[] = $v2;
    $copied = $shapesOf($v2);
    check('a new version copies the shapes', count($copied) === 3 && $copied[1]['text'] === 'Internet' && (int)$copied[1]['rotation'] === 15);
    $ns2 = $conn->query("SELECT style FROM network_diagram_nodes WHERE diagram_id = $v2 AND style IS NOT NULL")->fetchColumn();
    check('...and the node style', json_decode((string)$ns2, true) === ['fill' => 'solid', 'fillColour' => '#dcfce7']);
    NetworkMapperService::deleteDiagram($conn, $ctx, $v2);
    $made = array_values(array_diff($made, [$v2]));
    check('deleting a version removes its shapes', (int)$conn->query("SELECT COUNT(*) FROM network_diagram_shapes WHERE diagram_id = $v2")->fetchColumn() === 0
        && count($shapesOf($d)) === 3);
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
