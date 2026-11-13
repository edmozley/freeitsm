<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Report Packs: the assets-and-tickets blocks (GH #310) give the right figures.
 *
 *   - the top-N table counts each asset's tickets in the period, most first, and
 *     "By name" lists every asset with a ticket;
 *   - a ticket on two assets counts for each asset in the table, but ONCE per group
 *     in the charts (two laptops of one model on one ticket = one ticket for it);
 *   - the summary's share is tickets that name an asset out of all the period's
 *     tickets; the busiest asset is named;
 *   - a ticket created outside the period, or binned, is not counted.
 *
 * Seeds its own tickets in March 2001 (so no real ticket falls in the period) inside
 * one transaction that is always rolled back.
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/report-packs-asset-tickets.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/report_packs/design.php';
I18n::setLocale('en');

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes tickets and assets (rolled back).\n");
}
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$admin) exit("Needs an active administrator.\n");

// March 2001, every company the admin reaches; the range as rpResolveRange() gives it.
$range = ['from_date' => '2001-03-01', 'to_date' => '2001-03-31', 'from_utc' => '2001-03-01 00:00:00', 'to_utc' => '2001-04-01 00:00:00'];
$tenant = 'all';

$conn->beginTransaction();
try {
    $asset = function (string $host, string $make, string $model) use ($conn): int {
        $conn->prepare("INSERT INTO assets (hostname, manufacturer, model) VALUES (?, ?, ?)")->execute([$host, $make, $model]);
        return (int)$conn->lastInsertId();
    };
    $n = 0;
    $ticket = function (string $created, array $assetIds, bool $binned = false) use ($conn, &$n): int {
        $n++;
        $conn->prepare("INSERT INTO tickets (ticket_number, subject, created_datetime, deleted_datetime) VALUES (?, ?, ?, ?)")
             ->execute(['ZZRPAT' . $n . bin2hex(random_bytes(3)), "ZZ-RP-AT ticket $n", $created, $binned ? '2001-03-20 10:00:00' : null]);
        $id = (int)$conn->lastInsertId();
        foreach ($assetIds as $a) $conn->prepare("INSERT INTO ticket_assets (ticket_id, asset_id) VALUES (?, ?)")->execute([$id, $a]);
        return $id;
    };

    $l1 = $asset('ZZ-RP-AT-LAP1', 'Zedcorp', 'Book 14');
    $l2 = $asset('ZZ-RP-AT-LAP2', 'Zedcorp', 'Book 14');
    $p1 = $asset('ZZ-RP-AT-PRN1', 'Inkco', 'Jet 5');

    $ticket('2001-03-02 09:00:00', [$l1]);
    $ticket('2001-03-03 09:00:00', [$l1]);
    $ticket('2001-03-04 09:00:00', [$l1, $l2]);   // one ticket, two laptops of one model
    $ticket('2001-03-05 09:00:00', [$p1]);
    $ticket('2001-03-06 09:00:00', []);           // names no asset
    $ticket('2001-02-27 09:00:00', [$p1]);        // before the period
    $ticket('2001-03-07 09:00:00', [$p1], true);  // binned

    echo "\nTop assets\n";
    $top = rpAssetTicketsList($conn, $admin, ['basis' => 'created', 'sort' => 'count', 'model' => true, 'location' => true, 'limit' => 10], $range, $tenant);
    $map = array_column($top['rows'], 'tickets', 'asset');
    ok('LAP1 3, LAP2 1, PRN1 1 - the ticket outside the period and the binned one are not counted',
       ($map['ZZ-RP-AT-LAP1'] ?? '') === '3' && ($map['ZZ-RP-AT-LAP2'] ?? '') === '1' && ($map['ZZ-RP-AT-PRN1'] ?? '') === '1', json_encode($map));
    ok('most tickets first', ($top['rows'][0]['asset'] ?? '') === 'ZZ-RP-AT-LAP1');
    ok('the model is "manufacturer model"', ($top['rows'][0]['model'] ?? '') === 'Zedcorp Book 14');
    $one = rpAssetTicketsList($conn, $admin, ['basis' => 'created', 'sort' => 'count', 'model' => false, 'location' => false, 'limit' => 1], $range, $tenant);
    ok('a limit of 1 gives the busiest asset and says the list was cut short', count($one['rows']) === 1 && !empty($one['note']));
    ok('model and location columns can be switched off', !in_array('model', array_column($one['columns'], 'key'), true) && !in_array('location', array_column($one['columns'], 'key'), true));
    $all = rpAssetTicketsList($conn, $admin, ['basis' => 'created', 'sort' => 'name', 'model' => true, 'location' => true, 'limit' => 500], $range, $tenant);
    ok('"By name" lists every asset with a ticket, in name order', array_column($all['rows'], 'asset') === ['ZZ-RP-AT-LAP1', 'ZZ-RP-AT-LAP2', 'ZZ-RP-AT-PRN1'], json_encode(array_column($all['rows'], 'asset')));

    echo "\nCharts\n";
    $byModel = rpAssetTicketsBreakdown($conn, $admin, ['by' => 'model', 'basis' => 'created', 'chart' => 'hbar', 'limit' => 10], $range, $tenant);
    $m = array_combine($byModel['labels'], $byModel['series'][0]['values']);
    ok('by model: Zedcorp Book 14 = 3 tickets (the two-laptop ticket once), Inkco Jet 5 = 1', ($m['Zedcorp Book 14'] ?? 0) === 3 && ($m['Inkco Jet 5'] ?? 0) === 1, json_encode($m));
    $byMake = rpAssetTicketsBreakdown($conn, $admin, ['by' => 'manufacturer', 'basis' => 'created', 'chart' => 'bar', 'limit' => 10], $range, $tenant);
    $m = array_combine($byMake['labels'], $byMake['series'][0]['values']);
    ok('by manufacturer: Zedcorp 3, Inkco 1', ($m['Zedcorp'] ?? 0) === 3 && ($m['Inkco'] ?? 0) === 1, json_encode($m));
    $byCat = rpAssetTicketsBreakdown($conn, $admin, ['by' => 'category', 'basis' => 'created', 'chart' => 'hbar', 'limit' => 10], $range, $tenant);
    $m = array_combine($byCat['labels'], $byCat['series'][0]['values']);
    ok('by ticket category: the 4 asset tickets, none categorised', ($m['Not categorised'] ?? 0) === 4, json_encode($m));
    foreach (['type', 'location'] as $by) {
        $c = rpAssetTicketsBreakdown($conn, $admin, ['by' => $by, 'basis' => 'created', 'chart' => 'bar', 'limit' => 10], $range, $tenant);
        ok("by $by runs and adds up to 4", array_sum($c['series'][0]['values']) === 4, json_encode($c['labels']));
    }

    echo "\nSummary\n";
    $k = rpAssetTicketsKpis($conn, $admin, ['basis' => 'created'], $range, $tenant);
    $v = array_column($k['tiles'], 'value');
    ok('tickets on assets 4, share 80% (4 of 5), assets with tickets 3, busiest LAP1', $v === ['4', '80%', '3', 'ZZ-RP-AT-LAP1'], json_encode($v));
    ok('the busiest asset\'s tile says how many', str_contains($k['tiles'][3]['hint'], '3'), $k['tiles'][3]['hint']);
} finally {
    $conn->rollBack();
}
$left = (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZ-RP-AT-%'")->fetchColumn();
ok('nothing left behind', $left === 0, "$left assets");
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
