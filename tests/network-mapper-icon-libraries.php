<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Network Mapper - icon libraries from icons.diagrams.net (GitHub #433).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/network-mapper-icon-libraries.php
 *
 * Proves the parts that do not need the outside site: the SVG cleaner (script, handlers,
 * foreignObject, outside references and entity tricks all go; a plain icon survives), which
 * image types are accepted, that an 'icon' shape keeps only an icon that exists, that a new
 * version keeps it, and that removing a library leaves the shapes with no picture rather than
 * deleting them. The library here is made by hand (ZZ slug), removed in finally. Needs two
 * CMDB objects.
 *
 * @wiki Network-Mapper
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/network_mapper.php';
require_once __DIR__ . '/../includes/services/network_icons.php';

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

echo "The SVG cleaner\n";
$plain = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><defs><linearGradient id="g"/></defs><rect width="10" height="10" fill="url(#g)"/></svg>';
$c = NetworkIconService::cleanSvg($plain);
check('a plain icon survives, local gradient reference kept', $c !== null && strpos($c, 'url(#g)') !== false && strpos($c, '<rect') !== false, (string)$c);
$evil = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">'
    . '<script>alert(2)</script><foreignObject><body xmlns="http://www.w3.org/1999/xhtml">x</body></foreignObject>'
    . '<a xlink:href="javascript:alert(3)"><rect onclick="alert(4)" style="fill:url(https://evil.example/x)"/></a>'
    . '<image href="https://evil.example/track.png"/><use href="#ok"/><style>@import url(https://evil.example/s.css); .a{fill:url(https://evil.example/y)}</style></svg>';
$c = (string)NetworkIconService::cleanSvg($evil);
check('script, foreignObject and every on* handler are gone', stripos($c, 'script') === false && stripos($c, 'foreignObject') === false && stripos($c, 'onload') === false && stripos($c, 'onclick') === false, $c);
check('javascript: and outside addresses are gone, a local #ref stays', stripos($c, 'javascript') === false && strpos($c, 'evil.example') === false && strpos($c, 'href="#ok"') !== false, $c);
check('an entity / DOCTYPE trick is refused', NetworkIconService::cleanSvg('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>') === null);
check('something that is not an SVG is refused', NetworkIconService::cleanSvg('<html><body>hi</body></html>') === null && NetworkIconService::cleanSvg('not xml <svg') === null);

echo "Accepted image types\n";
$png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 20);
check('PNG by its bytes', (NetworkIconService::safeImage($png)['mime'] ?? '') === 'image/png');
check('an SVG comes back cleaned', (NetworkIconService::safeImage($evil)['mime'] ?? '') === 'image/svg+xml' && strpos(NetworkIconService::safeImage($evil)['data'], 'script') === false);
check('HTML pretending to be an icon is refused', NetworkIconService::safeImage('<html><script>x</script></html>') === null);
check('...and nothing at all is refused', NetworkIconService::safeImage(null) === null && NetworkIconService::safeImage('') === null);

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZ tester');
$slug = 'zz-test-' . bin2hex(random_bytes(3));
$made = []; $libId = 0;
try {
    $conn->prepare("INSERT INTO network_icon_libraries (slug, title, licence, author, icon_total, icon_count, status) VALUES (?, 'ZZ icons', 'MIT', 'ZZ', 2, 2, 'ready')")->execute([$slug]);
    $libId = (int)$conn->lastInsertId();
    $ins = $conn->prepare("INSERT INTO network_icon_library_icons (library_id, sort, title, mime, data, w, h) VALUES (?, ?, ?, 'image/svg+xml', ?, 48, 48)");
    $ins->execute([$libId, 0, 'One', $plain]);
    $icon1 = (int)$conn->lastInsertId();
    $ins->execute([$libId, 1, 'Two', $plain]);
    check('a retried batch cannot duplicate an icon (library_id + sort is unique)', (function () use ($conn, $libId, $plain) {
        try { $conn->prepare("INSERT INTO network_icon_library_icons (library_id, sort, title, mime, data) VALUES (?, 0, 'dup', 'image/svg+xml', ?)")->execute([$libId, $plain]); return false; }
        catch (PDOException $e) { return true; }
    })());
    check('iconData returns the picture', (NetworkIconService::iconData($conn, $icon1)['mime'] ?? '') === 'image/svg+xml');
    check('the palette lists the ready library\'s icons without their data', (function () use ($conn, $libId) {
        $rows = array_filter(NetworkIconService::paletteIcons($conn), fn($r) => $r['library_id'] === $libId);
        return count($rows) === 2 && !array_key_exists('data', reset($rows));
    })());

    $d = NetworkMapperService::createDiagram($conn, $ctx, ['title' => 'ZZ icons ' . bin2hex(random_bytes(3))]);
    $made[] = $d;
    NetworkMapperService::saveDiagram($conn, $ctx, $d, ['shapes' => [
        ['shape_type' => 'icon', 'x' => 10, 'y' => 10, 'w' => 64, 'h' => 64, 'icon_id' => $icon1, 'text' => 'Web', 'z' => 1],
        ['shape_type' => 'icon', 'x' => 90, 'y' => 10, 'w' => 64, 'h' => 64, 'icon_id' => 999999999, 'z' => 2],
    ]]);
    $rows = $conn->query("SELECT icon_id FROM network_diagram_shapes WHERE diagram_id = $d ORDER BY z")->fetchAll(PDO::FETCH_COLUMN);
    check('an icon shape keeps a real icon; an unknown icon id saves as none', (int)$rows[0] === $icon1 && $rows[1] === null, json_encode($rows));
    $v2 = NetworkMapperService::createVersion($conn, $ctx, $d, ['version_label' => 'v2']);
    $made[] = $v2;
    check('a new version keeps the icon', (int)$conn->query("SELECT icon_id FROM network_diagram_shapes WHERE diagram_id = $v2 AND icon_id IS NOT NULL")->fetchColumn() === $icon1);
    $used = array_values(array_filter(NetworkIconService::libraries($conn), fn($l) => $l['id'] === $libId));
    check('the library knows how many shapes use it', ($used[0]['used'] ?? 0) === 2, json_encode($used));

    NetworkIconService::deleteLibrary($conn, $libId);
    $after = $conn->query("SELECT COUNT(*) AS n, SUM(icon_id IS NULL) AS none FROM network_diagram_shapes WHERE diagram_id IN ($d, $v2) AND shape_type = 'icon'")->fetch(PDO::FETCH_ASSOC);
    check('removing the library keeps the shapes, with no picture', (int)$after['n'] === 4 && (int)$after['none'] === 4, json_encode($after));
    check('...and its icons are gone', (int)$conn->query("SELECT COUNT(*) FROM network_icon_library_icons WHERE library_id = $libId")->fetchColumn() === 0);
    $libId = 0;
} catch (Throwable $e) {
    check('no unexpected error', false, $e->getMessage());
} finally {
    foreach (array_reverse($made) as $id) {
        foreach (['network_diagram_connectors', 'network_diagram_shapes', 'network_diagram_nodes', 'network_diagram_layers', 'network_diagram_pages'] as $tbl) {
            $conn->prepare("DELETE FROM $tbl WHERE diagram_id = ?")->execute([$id]);
        }
        $conn->prepare("DELETE FROM network_diagrams WHERE id = ?")->execute([$id]);
    }
    if ($libId) {
        $conn->prepare("DELETE FROM network_icon_library_icons WHERE library_id = ?")->execute([$libId]);
        $conn->prepare("DELETE FROM network_icon_libraries WHERE id = ?")->execute([$libId]);
    }
    check('cleaned up', (int)$conn->query("SELECT COUNT(*) FROM network_icon_libraries WHERE slug = " . $conn->quote($slug))->fetchColumn() === 0);
}

echo "\nMy images (uploads and pastes)\n";
$hadMine = (bool)$conn->query("SELECT COUNT(*) FROM network_icon_libraries WHERE slug = 'my-images'")->fetchColumn();
$upIds = []; $d3 = 0;
try {
    $dot = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $ic = NetworkIconService::addUpload($conn, $ctx, $dot, 'ZZ server rack.png');
    $upIds[] = $ic['id'];
    check('a PNG goes into My images with its size and a readable title', $ic['w'] === 1 && $ic['h'] === 1 && $ic['title'] === 'ZZ server rack'
        && $conn->query("SELECT slug FROM network_icon_libraries WHERE id = " . (int)$ic['library_id'])->fetchColumn() === 'my-images', json_encode($ic));
    $refused = function (string $bytes) use ($conn, $ctx) {
        try { NetworkIconService::addUpload($conn, $ctx, $bytes, 'x.png'); return false; } catch (ServiceError $e) { return true; }
    };
    check('an SVG is refused, even a clean one', $refused($plain));
    check('HTML named .png is refused', $refused('<html><script>alert(1)</script></html>'));
    check('a PNG header with no real picture behind it is refused', $refused($png));
    check('over 4 MB is refused', $refused($dot . str_repeat("\0", 4 * 1024 * 1024)));

    $d3 = NetworkMapperService::createDiagram($conn, $ctx, ['title' => 'ZZ my images ' . bin2hex(random_bytes(3))]);
    NetworkMapperService::saveDiagram($conn, $ctx, $d3, ['shapes' => [
        ['shape_type' => 'icon', 'x' => 10, 'y' => 10, 'w' => 200, 'h' => 200, 'icon_id' => $ic['id'], 'z' => 1],
    ]]);
    check('a diagram can use the picture', (int)$conn->query("SELECT icon_id FROM network_diagram_shapes WHERE diagram_id = $d3")->fetchColumn() === $ic['id']);
    $other = (int)$conn->query("SELECT i.id FROM network_icon_library_icons i JOIN network_icon_libraries l ON l.id = i.library_id WHERE l.slug <> 'my-images' LIMIT 1")->fetchColumn();
    if ($other) {
        check('deleteUpload will not touch an icon from an imported set', (function () use ($conn, $other) {
            try { NetworkIconService::deleteUpload($conn, $other); return false; } catch (ServiceError $e) { return true; }
        })());
    }
    NetworkIconService::deleteUpload($conn, $ic['id']);
    $upIds = [];
    check('removing the picture keeps the shape, with no picture', $conn->query("SELECT icon_id FROM network_diagram_shapes WHERE diagram_id = $d3")->fetchColumn() === null);
} catch (Throwable $e) {
    check('no unexpected error (My images)', false, $e->getMessage());
} finally {
    foreach ($upIds as $id) $conn->prepare("DELETE FROM network_icon_library_icons WHERE id = ?")->execute([$id]);
    if ($d3) {
        $conn->prepare("DELETE FROM network_diagram_shapes WHERE diagram_id = ?")->execute([$d3]);
        foreach (['network_diagram_layers', 'network_diagram_pages'] as $tbl) $conn->prepare("DELETE FROM $tbl WHERE diagram_id = ?")->execute([$d3]);
        $conn->prepare("DELETE FROM network_diagrams WHERE id = ?")->execute([$d3]);
    }
    if (!$hadMine) $conn->exec("DELETE FROM network_icon_libraries WHERE slug = 'my-images' AND icon_count = 0");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
