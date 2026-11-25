<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Network Mapper - a node's own colour and box size (GitHub #433).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/network-mapper-node-style.php
 *
 * colour_override / w / h on network_diagram_nodes: saved through
 * NetworkMapperService::saveDiagram, refused when malformed (each refusal has a positive
 * control), and carried into a new version by createVersion. Everything made is a ZZ
 * diagram, deleted (with its nodes) in finally. Needs two CMDB objects on the install.
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

$nodesOf = function (int $id) use ($conn): array {
    $st = $conn->prepare("SELECT cmdb_object_id, colour_override, w, h FROM network_diagram_nodes WHERE diagram_id = ? ORDER BY cmdb_object_id");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};

try {
    $d = NetworkMapperService::createDiagram($conn, $ctx, ['title' => 'ZZ node style ' . bin2hex(random_bytes(3))]);
    $made[] = $d;
    $save = fn(array $first) => NetworkMapperService::saveDiagram($conn, $ctx, $d, ['nodes' => [
        $first + ['cmdb_object_id' => $objs[0], 'x' => 100, 'y' => 100, 'ref' => 'a'],
        ['cmdb_object_id' => $objs[1], 'x' => 400, 'y' => 100, 'ref' => 'b'],
    ], 'connectors' => []]);

    echo "Saving\n";
    $save(['colour_override' => '#2563EB', 'w' => 200, 'h' => 160]);
    $rows = $nodesOf($d);
    check('a colour and box size are saved (colour lower-cased)', $rows[0]['colour_override'] === '#2563eb' && (int)$rows[0]['w'] === 200 && (int)$rows[0]['h'] === 160, json_encode($rows[0]));
    check('...and a node without them stays NULL', $rows[1]['colour_override'] === null && $rows[1]['w'] === null && $rows[1]['h'] === null);

    echo "Refusals, each with a positive control\n";
    check('a colour that is not #rrggbb is refused', refused(fn() => $save(['colour_override' => 'red'])) !== '');
    check('...an rgba() colour too', refused(fn() => $save(['colour_override' => 'rgba(0,0,0,0.5)'])) !== '');
    check('...while #0d9488 is fine', refused(fn() => $save(['colour_override' => '#0d9488'])) === '');
    check('a width under 20 is refused', refused(fn() => $save(['w' => 10])) !== '');
    check('a height over 2000 is refused', refused(fn() => $save(['h' => 2001])) !== '');
    check('...while 20 and 2000 are fine', refused(fn() => $save(['w' => 20, 'h' => 2000])) === '');
    check('an empty colour means none', refused(fn() => $save(['colour_override' => '', 'w' => '', 'h' => null])) === ''
        && $nodesOf($d)[0]['colour_override'] === null && $nodesOf($d)[0]['w'] === null);

    echo "New version\n";
    $save(['colour_override' => '#7c3aed', 'w' => 240, 'h' => 120]);
    $v2 = NetworkMapperService::createVersion($conn, $ctx, $d, ['version_label' => 'v2']);
    $made[] = $v2;
    $rows = $nodesOf($v2);
    check('a new version carries the colour and box size across', $rows[0]['colour_override'] === '#7c3aed' && (int)$rows[0]['w'] === 240 && (int)$rows[0]['h'] === 120, json_encode($rows[0]));
} catch (Throwable $e) {
    check('no unexpected error', false, $e->getMessage());
} finally {
    foreach (array_reverse($made) as $id) {
        $conn->prepare("DELETE FROM network_diagram_connectors WHERE diagram_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM network_diagram_nodes WHERE diagram_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM network_diagram_shapes WHERE diagram_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM network_diagrams WHERE id = ?")->execute([$id]);
    }
    $left = $made ? (int)$conn->query("SELECT COUNT(*) FROM network_diagrams WHERE id IN (" . implode(',', $made) . ")")->fetchColumn() : 0;
    check('cleaned up', $left === 0);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
