<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Retired assets: a status ticked Retired takes an asset out of the estate - stamped
 * with the date, out of the lists, counts and alerts, left alone by device syncs,
 * and listed by the "Retired assets" report pack block.
 *
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/asset-retirement.php
 *
 * Writes statuses and assets, so it runs only when FREEITSM_TEST_DATABASE names the
 * database config.php points at. Everything it makes is named ZZ-RET-... and removed.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/assets.php';
require_once __DIR__ . '/../includes/report_packs/blocks.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

$conn = connectToDatabase();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes assets.\n");
}
if (!assetRetirementReady($conn)) exit("Run Database Verification on this database first.\n");

echo "\nRetired assets\n" . str_repeat('=', 72) . "\n";
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx = new ActorContext(actorId: $admin, companyScope: null, source: 'ui', actorName: 'test');
$tag = bin2hex(random_bytes(3));
$made = ['assets' => [], 'statuses' => []];
$row = fn(int $id) => $conn->query("SELECT * FROM assets WHERE id = $id")->fetch(PDO::FETCH_ASSOC);

try {
    $conn->prepare("INSERT INTO asset_status_types (name, is_active, is_retired) VALUES (?, 1, 1)")->execute(["ZZ-RET-gone-$tag"]);
    $retiredStatus = (int)$conn->lastInsertId(); $made['statuses'][] = $retiredStatus;
    $conn->prepare("INSERT INTO asset_status_types (name, is_active, is_retired) VALUES (?, 1, 0)")->execute(["ZZ-RET-live-$tag"]);
    $liveStatus = (int)$conn->lastInsertId(); $made['statuses'][] = $liveStatus;

    $a = AssetsService::createAsset($conn, $ctx, ['hostname' => "ZZ-RET-A-$tag", 'asset_status_id' => $liveStatus, 'service_tag' => "ZZSER$tag"], 'test');
    $made['assets'][] = $a;
    ok('a new asset in an ordinary status is not retired', $row($a)['retired_datetime'] === null);

    AssetsService::updateFields($conn, $ctx, $a, ['asset_status_id' => $retiredStatus]);
    $r = $row($a);
    ok('moved into a Retired status: stamped with the date', $r['retired_datetime'] !== null);
    AssetsService::updateFields($conn, $ctx, $a, ['retired_reason' => 'Transferred to the Ministry of Education']);
    ok('...the reason can be recorded', $row($a)['retired_reason'] === 'Transferred to the Ministry of Education');

    // Out of the estate figures; still found by id.
    $counted = (int)$conn->query("SELECT COUNT(*) FROM assets a WHERE a.id = $a" . assetNotRetiredSql($conn, 'a'))->fetchColumn();
    ok('left out of lists and counts (assetNotRetiredSql)', $counted === 0);

    // A device still reporting: matched (no duplicate), flagged, not renamed.
    $res = AssetsService::reconcileAsset($conn, ActorContext::system('test agent'), ['service_tag' => "ZZSER$tag", 'hostname' => "ZZ-RET-RENAMED-$tag"], null, 'test');
    ok('a retired machine still reporting is matched, not duplicated', (int)$res['asset_id'] === $a && $res['retired'] === true, json_encode($res));
    ok('...and is not renamed', $row($a)['hostname'] === "ZZ-RET-A-$tag", $row($a)['hostname']);

    // The report pack block lists it in the period.
    $range = ['from_utc' => gmdate('Y-m-d H:i:s', time() - 3600), 'to_utc' => gmdate('Y-m-d H:i:s', time() + 3600),
              'from_date' => gmdate('Y-m-d'), 'to_date' => gmdate('Y-m-d')];
    $block = rpAssetsRetired($conn, $admin, ['limit' => 2000], $range, 'all');
    $hit = array_values(array_filter($block['rows'], fn($x) => str_starts_with($x['asset'], "ZZ-RET-A-$tag")));
    ok('the Retired assets block lists it, with serial and reason',
       $hit && $hit[0]['serial'] === "ZZSER$tag" && $hit[0]['reason'] === 'Transferred to the Ministry of Education', json_encode($hit));

    // Back into service: date and reason cleared.
    AssetsService::updateFields($conn, $ctx, $a, ['asset_status_id' => $liveStatus]);
    $r = $row($a);
    ok('moved out of the Retired status: date and reason cleared', $r['retired_datetime'] === null && $r['retired_reason'] === null);

    // Ticking a status that assets are already in re-stamps them; unticking clears.
    $b = AssetsService::createAsset($conn, $ctx, ['hostname' => "ZZ-RET-B-$tag", 'asset_status_id' => $liveStatus], 'test');
    $made['assets'][] = $b;
    $conn->prepare("UPDATE asset_status_types SET is_retired = 1 WHERE id = ?")->execute([$liveStatus]);
    $n = assetRetirementRestamp($conn, $liveStatus, true);
    ok('ticking Retired on a status in use stamps the assets already in it', $n >= 2 && $row($b)['retired_datetime'] !== null, "n=$n");
    $conn->prepare("UPDATE asset_status_types SET is_retired = 0 WHERE id = ?")->execute([$liveStatus]);
    assetRetirementRestamp($conn, $liveStatus, false);
    ok('...and unticking it clears them again', $row($b)['retired_datetime'] === null);

    // Created straight into a retired status (an import of disposed kit).
    $c = AssetsService::createAsset($conn, $ctx, ['hostname' => "ZZ-RET-C-$tag", 'asset_status_id' => $retiredStatus], 'test');
    $made['assets'][] = $c;
    ok('an asset created in a Retired status is retired from the start', $row($c)['retired_datetime'] !== null);

    // The Change Management blocks run and have the right shape.
    $chart = rpChangesBreakdown($conn, $admin, ['by' => 'risk', 'basis' => 'created', 'limit' => 12, 'chart' => 'bar'], $range, 'all');
    ok('Changes by risk returns a chart', ($chart['chart'] ?? null) === 'bar' && isset($chart['labels']), json_encode(array_keys($chart)));
    $list = rpChangesList($conn, $admin, ['basis' => 'scheduled', 'limit' => 50], $range, 'all');
    ok('the change list returns a table', ($list['kind'] ?? null) === 'table' && count($list['columns']) === 7);
} finally {
    foreach ($made['assets'] as $id) {
        $conn->prepare("DELETE FROM asset_history WHERE asset_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM assets WHERE id = ? AND hostname LIKE 'ZZ-RET-%'")->execute([$id]);
    }
    foreach ($made['statuses'] as $id) $conn->prepare("DELETE FROM asset_status_types WHERE id = ? AND name LIKE 'ZZ-RET-%'")->execute([$id]);
    echo "  (cleaned up: " . count($made['assets']) . " assets, " . count($made['statuses']) . " statuses)\n";
}

echo "\n" . str_repeat('=', 72) . "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
