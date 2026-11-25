<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Network Mapper - Knowledge article links (GitHub #433).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/network-mapper-articles.php
 *
 * Proves the rule in includes/services/network_articles.php from the side of an ordinary
 * (non-admin) analyst with the Knowledge module:
 *   - an article they can read gets its title; a RESTRICTED one they cannot read comes back
 *     as null - with the readable one alongside as the positive control;
 *   - they cannot add the unreadable one to a diagram, nor pin it onto a shape by typing its
 *     id into a save - but a save of theirs KEEPS it when a colleague linked it already;
 *   - a new version copies the related list and every item's link, and the article lists
 *     back only the current version.
 * Makes two ZZ articles and a ZZ diagram; all removed in finally. Needs an analyst with both modules who does not manage Knowledge.
 *
 * @wiki Network-Mapper
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/network_mapper.php';
require_once __DIR__ . '/../includes/services/network_articles.php';

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
    else { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$x = 0;
foreach ($conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 0 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $cand) {
    // not a Knowledge manager - they see every article on purpose (the administrator floor)
    if (analystCanAccessModule($conn, (int)$cand, 'knowledge') && analystCanAccessModule($conn, (int)$cand, 'network-mapper')
        && !analystHasCapability($conn, (int)$cand, Cap::KNOWLEDGE_MANAGE)) { $x = (int)$cand; break; }
}
if (!$x) { echo "(skipped: no active analyst with Knowledge and Network Mapper who does not manage Knowledge)\n"; exit(0); }
$ctxX = new ActorContext($x, null, 'ui', 'en', 'ZZ tester');
$ctxA = new ActorContext($admin, null, 'ui', 'en', 'ZZ admin');

$made = []; $arts = [];
try {
    $mk = function (string $title, int $restricted, int $published = 1, int $archived = 0) use ($conn, $admin) {
        $conn->prepare("INSERT INTO knowledge_articles (title, body, author_id, is_published, is_archived, created_datetime, modified_datetime, tenant_id, audience, is_restricted, inherit_permissions)
                        VALUES (?, '<p>ZZ</p>', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, 'internal', ?, 0)")->execute([$title, $admin, $published, $archived, $restricted]);
        return (int)$conn->lastInsertId();
    };
    $open = $mk('ZZ open article', 0);       $arts[] = $open;
    $secret = $mk('ZZ secret article', 1);   $arts[] = $secret;   // restricted, granted to nobody
    $draft = $mk('ZZ draft article', 0, 0);       $arts[] = $draft;      // not published yet
    $old = $mk('ZZ archived article', 0, 1, 1);   $arts[] = $old;        // archived
    $secretDraft = $mk('ZZ secret draft', 1, 0);  $arts[] = $secretDraft;   // a draft, AND restricted
    if (function_exists('knowledgeAclResetCaches')) knowledgeAclResetCaches();

    echo "Who can read what\n";
    $r = NetworkArticleService::readable($conn, $x, [$open, $secret]);
    check('POSITIVE CONTROL: the analyst reads the open article', isset($r[$open]) && $r[$open] === 'ZZ open article', json_encode($r));
    check('...but not the restricted one', !isset($r[$secret]), json_encode($r));
    $found = array_column(NetworkArticleService::search($conn, $x, 'ZZ '), 'id');
    check('the picker offers the open article and not the restricted one', in_array($open, $found, true) && !in_array($secret, $found, true), json_encode($found));
    $rows = NetworkArticleService::readableRows($conn, $x, [$draft, $old, $secretDraft]);
    check('a draft and an archived article are readable, with their state - not "no access"', ($rows[$draft]['state'] ?? null) === 'draft'
        && ($rows[$old]['state'] ?? null) === 'archived' && ($rows[$draft]['title'] ?? null) === 'ZZ draft article', json_encode($rows));
    check('...while a restricted draft is still not readable - the lifecycle is lifted, not the permission', !isset($rows[$secretDraft]));
    $byId = array_column(NetworkArticleService::search($conn, $x, 'ZZ '), 'state', 'id');
    check('the picker offers the draft (marked draft) but not the archived article', ($byId[$draft] ?? null) === 'draft' && !isset($byId[$old])
        && ($byId[$open] ?? null) === 'live', json_encode($byId));

    $d = NetworkMapperService::createDiagram($conn, $ctxA, ['title' => 'ZZ articles ' . bin2hex(random_bytes(3))]);
    $made[] = $d;

    echo "\nRelated articles\n";
    NetworkArticleService::addRelated($conn, $ctxX, $d, $open);
    $refused = false;
    try { NetworkArticleService::addRelated($conn, $ctxX, $d, $secret); } catch (ServiceError $e) { $refused = true; }
    check('the analyst can add the open article, not the restricted one', $refused
        && NetworkArticleService::forDiagram($conn, $x, $d)['related'] === [$open]);
    // a colleague who CAN read it adds it (straight into the table - the admin floor is not what is under test)
    $conn->prepare("INSERT INTO network_diagram_articles (diagram_id, article_id, created_by_analyst_id) VALUES (?, ?, ?)")->execute([$d, $secret, $admin]);
    $f = NetworkArticleService::forDiagram($conn, $x, $d);
    check('the analyst sees both links, the restricted one with NO title', $f['titles'][(string)$open] === 'ZZ open article'
        && array_key_exists((string)$secret, $f['titles']) && $f['titles'][(string)$secret] === null, json_encode($f));
    NetworkArticleService::removeRelated($conn, $d, $secret);
    check('...and may still take it off', NetworkArticleService::forDiagram($conn, $x, $d)['related'] === [$open]);
    NetworkArticleService::addRelated($conn, $ctxX, $d, $draft);
    $conn->prepare("INSERT INTO network_diagram_articles (diagram_id, article_id, created_by_analyst_id) VALUES (?, ?, ?)")->execute([$d, $old, $admin]);
    $f = NetworkArticleService::forDiagram($conn, $x, $d);
    $st = (array)$f['states'];
    check('a linked draft and archived article show their titles and states', $f['titles'][(string)$draft] === 'ZZ draft article'
        && $f['titles'][(string)$old] === 'ZZ archived article' && ($st[(string)$draft] ?? null) === 'draft' && ($st[(string)$old] ?? null) === 'archived'
        && !isset($st[(string)$open]), json_encode($f));
    check('...and the draft lists the diagram back on its own page', in_array($d, array_column(NetworkArticleService::diagramsForArticle($conn, $x, $draft), 'id'), true));
    NetworkArticleService::removeRelated($conn, $d, $draft);
    NetworkArticleService::removeRelated($conn, $d, $old);

    echo "\nLinks on shapes and nodes\n";
    $obj = (int)$conn->query("SELECT id FROM cmdb_objects ORDER BY id LIMIT 1")->fetchColumn();
    $shapesOf = fn() => $conn->query("SELECT uid, article_id FROM network_diagram_shapes WHERE diagram_id = $d ORDER BY uid")->fetchAll(PDO::FETCH_KEY_PAIR);
    NetworkMapperService::saveDiagram($conn, $ctxX, $d, ['shapes' => [
        ['shape_type' => 'rectangle', 'uid' => 'a1', 'x' => 0, 'y' => 0, 'article_id' => $open],
        ['shape_type' => 'rectangle', 'uid' => 'a2', 'x' => 200, 'y' => 0, 'article_id' => $secret],
    ]]);
    $s = $shapesOf();
    check('a save keeps a link to a readable article and drops one typed in for a restricted article', (int)$s['a1'] === $open && $s['a2'] === null, json_encode($s));
    $conn->prepare("UPDATE network_diagram_shapes SET article_id = ? WHERE diagram_id = ? AND uid = 'a2'")->execute([$secret, $d]);   // a colleague linked it
    NetworkMapperService::saveDiagram($conn, $ctxX, $d, ['shapes' => [
        ['shape_type' => 'rectangle', 'uid' => 'a1', 'x' => 10, 'y' => 0, 'article_id' => $open],
        ['shape_type' => 'rectangle', 'uid' => 'a2', 'x' => 210, 'y' => 0, 'article_id' => $secret],
    ]]);
    $s = $shapesOf();
    check('...but keeps a colleague\'s link to it when the analyst saves', (int)$s['a2'] === $secret, json_encode($s));
    if ($obj) {
        NetworkMapperService::saveDiagram($conn, $ctxA, $d, ['nodes' => [['cmdb_object_id' => $obj, 'x' => 0, 'y' => 200, 'article_id' => $open]], 'connectors' => []]);
        check('a node keeps its article', (int)$conn->query("SELECT article_id FROM network_diagram_nodes WHERE diagram_id = $d")->fetchColumn() === $open);
    }

    echo "\nVersions and the way back\n";
    $conn->prepare("INSERT IGNORE INTO network_diagram_articles (diagram_id, article_id) VALUES (?, ?)")->execute([$d, $secret]);
    $v2 = NetworkMapperService::createVersion($conn, $ctxA, $d, ['version_label' => 'v2']);
    $made[] = $v2;
    $f2 = NetworkArticleService::forDiagram($conn, $admin, $v2);
    sort($f2['related']);
    $want = [$open, $secret]; sort($want);
    check('a new version copies the related list', $f2['related'] === $want, json_encode($f2['related']));
    check('...and every shape\'s and node\'s link', (int)$conn->query("SELECT COUNT(*) FROM network_diagram_shapes WHERE diagram_id = $v2 AND article_id IS NOT NULL")->fetchColumn() === 2
        && (!$obj || (int)$conn->query("SELECT article_id FROM network_diagram_nodes WHERE diagram_id = $v2")->fetchColumn() === $open));
    $back = NetworkArticleService::diagramsForArticle($conn, $x, $open);
    check('the article lists the current version only', array_column($back, 'id') === [$v2], json_encode($back));
    check('...and the restricted article lists nothing to someone who cannot read it', NetworkArticleService::diagramsForArticle($conn, $x, $secret) === []);
    $refused = false;
    try { NetworkArticleService::addRelated($conn, $ctxA, $d, $open); } catch (ServiceError $e) { $refused = true; }
    check('an old version\'s list cannot be changed', $refused);

    echo "\nWhen the article goes\n";
    $conn->prepare("DELETE FROM knowledge_articles WHERE id = ?")->execute([$secret]);
    $arts = array_values(array_diff($arts, [$secret]));
    check('its links go too - the shapes stay', (int)$conn->query("SELECT COUNT(*) FROM network_diagram_articles WHERE article_id = $secret")->fetchColumn() === 0
        && (int)$conn->query("SELECT COUNT(*) FROM network_diagram_shapes WHERE diagram_id IN ($d, $v2) AND uid = 'a2' AND article_id IS NULL")->fetchColumn() === 2);
} catch (Throwable $e) {
    check('no unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach (array_reverse($made) as $id) {
        foreach (['network_diagram_articles', 'network_diagram_connectors', 'network_diagram_shapes', 'network_diagram_nodes', 'network_diagram_layers', 'network_diagram_pages'] as $tbl) {
            $conn->prepare("DELETE FROM $tbl WHERE diagram_id = ?")->execute([$id]);
        }
        $conn->prepare("DELETE FROM network_diagrams WHERE id = ?")->execute([$id]);
    }
    foreach ($arts as $a) $conn->prepare("DELETE FROM knowledge_articles WHERE id = ?")->execute([$a]);
    check('cleaned up', (int)$conn->query("SELECT COUNT(*) FROM knowledge_articles WHERE title LIKE 'ZZ % article'")->fetchColumn() === 0);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
