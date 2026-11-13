<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Report Packs: the Starters & leavers blocks (GH #324) give the right figures.
 *
 *   - a case is in the period by its DATE (first day / move date / last day), not
 *     when it was opened; cancelled cases are left out of the summary and the list
 *     but counted in the status chart;
 *   - "ready on the day" counts a case only when every step was closed by the end
 *     of its date - a step closed the day after, or still open, makes it not ready;
 *     a case with no steps is not counted either way;
 *   - overdue steps are open steps of OPEN cases past their due date, now;
 *   - the list's status option and the kind option narrow as they say.
 *
 * Seeds its own cases in 2001 (so no real case falls in the test's period) inside
 * one transaction that is always rolled back.
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/report-packs-starters.php
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
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes cases and tasks (rolled back).\n");
}

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$closed = (int)$conn->query("SELECT id FROM task_statuses WHERE is_closed = 1 ORDER BY id LIMIT 1")->fetchColumn();
$openSt = (int)$conn->query("SELECT id FROM task_statuses WHERE is_closed = 0 ORDER BY id LIMIT 1")->fetchColumn();
if (!$admin || !$closed || !$openSt) exit("Needs an active administrator and an open and a closed task status.\n");

// The period: March 2001. Every company the admin reaches, so the company rule is not what is tested here.
$range = ['from_date' => '2001-03-01', 'to_date' => '2001-03-31'];
$tenant = 'all';
$future = gmdate('Y-m-d', time() + 30 * 86400);
$past   = gmdate('Y-m-d', time() - 10 * 86400);

$beforeKpi = rpStartersKpis($conn, $admin, [], $range, $tenant);
$tile = fn(array $k, int $i) => $k['tiles'][$i]['value'];
$overdueBefore = (int)str_replace(',', '', $tile($beforeKpi, 5));

$conn->beginTransaction();
try {
    /** A case with steps: each step [closed?, completed_datetime or null, due_date or null]. */
    $case = function (string $kind, string $date, string $status, array $steps, string $name) use ($conn, $admin, $closed, $openSt): int {
        $conn->prepare("INSERT INTO tasks (title, created_by_id) VALUES (?, ?)")->execute(["ZZ-RP-JML parent $name", $admin]);
        $parent = (int)$conn->lastInsertId();
        foreach ($steps as $i => [$done, $completed, $due]) {
            $conn->prepare("INSERT INTO tasks (title, created_by_id, parent_task_id, status_id, completed_datetime, due_date) VALUES (?, ?, ?, ?, ?, ?)")
                 ->execute(["ZZ-RP-JML step $i of $name", $admin, $parent, $done ? $closed : $openSt, $completed, $due]);
        }
        $conn->prepare("INSERT INTO jml_cases (kind, person_name, effective_date, status, parent_task_id) VALUES (?, ?, ?, ?, ?)")
             ->execute([$kind, "ZZ-RP-JML $name", $date, $status, $parent]);
        return (int)$conn->lastInsertId();
    };

    // In March 2001:
    $case('starter', '2001-03-05', 'done', [[true, '2001-03-04 10:00:00', null], [true, '2001-03-05 23:00:00', null]], 'ready starter');      // ready (closed on the day)
    $case('starter', '2001-03-12', 'done', [[true, '2001-03-10 10:00:00', null], [true, '2001-03-13 09:00:00', null]], 'late starter');       // not ready (a step the day after)
    $case('leaver',  '2001-03-20', 'open', [[true, '2001-03-19 10:00:00', null], [false, null, $past]], 'open leaver');                      // not ready; one overdue step now
    $case('mover',   '2001-03-25', 'open', [], 'stepless mover');                                                                           // no steps: not counted for ready
    $case('starter', '2001-03-28', 'cancelled', [[false, null, $past]], 'cancelled starter');                                               // left out; its overdue step does not count
    // Outside the period, but open with one overdue and one not-yet-due step:
    $case('starter', '2001-05-01', 'open', [[false, null, $past], [false, null, $future]], 'later starter');

    echo "\nSummary\n";
    $k = rpStartersKpis($conn, $admin, [], $range, $tenant);
    ok('starters dated in the period: 2 (the cancelled one left out)', $tile($k, 0) === '2', $tile($k, 0));
    ok('movers: 1', $tile($k, 1) === '1', $tile($k, 1));
    ok('leavers: 1', $tile($k, 2) === '1', $tile($k, 2));
    ok('ready on the day: 1 of 3 (33%) - the stepless case not counted', $tile($k, 3) === '33%' && str_contains($k['tiles'][3]['hint'], '1 of 3'), $tile($k, 3) . ' / ' . $k['tiles'][3]['hint']);
    $overdueAfter = (int)str_replace(',', '', $tile($k, 5));
    ok('overdue now: +2 (open cases only; the cancelled case\'s step and the not-yet-due step are not counted)', $overdueAfter - $overdueBefore === 2, "before $overdueBefore, after $overdueAfter");

    echo "\nChart\n";
    $byKind = rpStartersBreakdown($conn, $admin, ['by' => 'kind', 'chart' => 'doughnut', 'limit' => 12], $range, $tenant);
    $map = array_combine($byKind['labels'], $byKind['series'][0]['values']);
    ok('by kind: Starters 2, Movers 1, Leavers 1', ($map['Starters'] ?? 0) === 2 && ($map['Movers'] ?? 0) === 1 && ($map['Leavers'] ?? 0) === 1, json_encode($map));
    $byStatus = rpStartersBreakdown($conn, $admin, ['by' => 'status', 'chart' => 'bar', 'limit' => 12], $range, $tenant);
    $map = array_combine($byStatus['labels'], $byStatus['series'][0]['values']);
    ok('by status keeps the cancelled case: Done 2, Open 2, Cancelled 1', ($map['Done'] ?? 0) === 2 && ($map['Open'] ?? 0) === 2 && ($map['Cancelled'] ?? 0) === 1, json_encode($map));
    $byTpl = rpStartersBreakdown($conn, $admin, ['by' => 'template', 'chart' => 'bar', 'limit' => 12], $range, $tenant);
    ok('by template: cases with none are labelled "No template"', in_array('No template', $byTpl['labels'], true), json_encode($byTpl['labels']));

    echo "\nList\n";
    $all = rpStartersList($conn, $admin, ['kind' => 'all', 'status' => 'all', 'owner' => true, 'limit' => 200], $range, $tenant);
    ok('lists the 4 cases dated in the period, in date order', array_column($all['rows'], 'person') === ['ZZ-RP-JML ready starter', 'ZZ-RP-JML late starter', 'ZZ-RP-JML open leaver', 'ZZ-RP-JML stepless mover'], json_encode(array_column($all['rows'], 'person')));
    ok('progress reads from the steps: the leaver is 50% (1/2), the stepless mover "-"', $all['rows'][2]['progress'] === '50% (1/2)' && $all['rows'][3]['progress'] === '-', $all['rows'][2]['progress'] . ' / ' . $all['rows'][3]['progress']);
    $open = rpStartersList($conn, $admin, ['kind' => 'all', 'status' => 'open', 'owner' => false, 'limit' => 200], $range, $tenant);
    ok('"Open only" gives the 2 open cases, and no owner column', count($open['rows']) === 2 && !in_array('owner', array_column($open['columns'], 'key'), true));
    $st = rpStartersList($conn, $admin, ['kind' => 'starter', 'status' => 'all', 'owner' => true, 'limit' => 200], $range, $tenant);
    ok('kind Starters gives the 2 starters', count($st['rows']) === 2);
    $one = rpStartersList($conn, $admin, ['kind' => 'all', 'status' => 'all', 'owner' => true, 'limit' => 1], $range, $tenant);
    ok('a row limit says the list was cut short', count($one['rows']) === 1 && !empty($one['note']));

    echo "\nOverdue steps\n";
    $od = rpStartersOverdue($conn, $admin, ['kind' => 'all', 'limit' => 2000], $range, $tenant);
    $mine = array_values(array_filter($od['rows'], fn($r) => str_starts_with($r['person'], 'ZZ-RP-JML')));
    ok('2 of ours: the open leaver\'s and the later starter\'s, not the cancelled case\'s', count($mine) === 2, json_encode(array_column($mine, 'person')));
    ok('says how late: 10 days', ($mine[0]['late']['pill'] ?? '') === '10 days', $mine[0]['late']['pill'] ?? '');
    $odL = rpStartersOverdue($conn, $admin, ['kind' => 'leaver', 'limit' => 2000], $range, $tenant);
    $mineL = array_filter($odL['rows'], fn($r) => str_starts_with($r['person'], 'ZZ-RP-JML'));
    ok('kind Leavers gives only the leaver\'s step', count($mineL) === 1);
} finally {
    $conn->rollBack();
}

$left = (int)$conn->query("SELECT COUNT(*) FROM jml_cases WHERE person_name LIKE 'ZZ-RP-JML%'")->fetchColumn();
ok('nothing left behind', $left === 0, "$left cases");
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
