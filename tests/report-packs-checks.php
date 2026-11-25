<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Report Packs: the Morning Checks blocks (5.11.0) give the right figures.
 *
 *   - a check's days count only when it was DUE (its schedule), from the day it
 *     was made, up to today; a result on a day it was not due still counts;
 *   - "not checked" = a due day with no result, for checks that are active now; a
 *     check switched off keeps its results but its empty days are not counted, and
 *     one switched off with no results in the period is left out;
 *   - exceptions are the results whose status asks for notes, with the notes as
 *     text (tags stripped), oldest first, plus the missed days when asked;
 *   - the chart counts by status in the status's colour, with Not checked;
 *   - a result outside the period is not counted.
 *
 * Seeds its own checks and results in March 2001 (so no real result falls in the
 * period) inside one transaction that is always rolled back.
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/report-packs-checks.php
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
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes checks and results (rolled back).\n");
}
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$okSt  = (int)$conn->query("SELECT StatusID FROM morningChecks_Statuses WHERE IsActive = 1 AND RequiresNotes = 0 ORDER BY SortOrder LIMIT 1")->fetchColumn();
$bad   = array_map('intval', $conn->query("SELECT StatusID FROM morningChecks_Statuses WHERE IsActive = 1 AND RequiresNotes = 1 ORDER BY SortOrder LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
if (!$admin || !$okSt || count($bad) < 2) exit("Needs an active administrator, a status that does not ask for notes and two that do.\n");
[$amber, $red] = $bad;
$label = fn(int $id) => (string)$conn->query("SELECT Label FROM morningChecks_Statuses WHERE StatusID = $id")->fetchColumn();

echo "\nReport Packs: Morning Checks blocks\n" . str_repeat('=', 72) . "\n";
$range = ['from_date' => '2001-03-01', 'to_date' => '2001-03-31'];   // 1 March 2001 is a Thursday
$opts = fn(string $h, array $o = []) => rpCleanOpts($h, $o);

$conn->beginTransaction();
try {
    $check = function (string $name, string $made, int $active = 1, ?string $type = null, ?string $days = null) use ($conn): int {
        $conn->prepare("INSERT INTO morningChecks_Checks (CheckName, IsActive, CreatedDate, ScheduleType, ScheduleDays) VALUES (?, ?, ?, ?, ?)")
             ->execute([$name, $active, $made, $type, $days]);
        return (int)$conn->lastInsertId();
    };
    $result = function (int $check, string $day, int $status, string $notes = '') use ($conn) {
        $conn->prepare("INSERT INTO morningChecks_Results (CheckID, CheckDate, StatusID, Notes, CreatedBy) VALUES (?, ?, ?, ?, 'ZZ tester')")
             ->execute([$check, "$day 00:00:00", $status, $notes ?: null]);
    };
    $daily  = $check('ZZ-CHK daily', '2001-01-01');
    $weekly = $check('ZZ-CHK weekly', '2001-01-01', 1, 'weekly', '1');     // Mondays: 5, 12, 19, 26 March
    $old    = $check('ZZ-CHK old', '2001-01-01', 0);
    $check('ZZ-CHK gone', '2001-01-01', 0);                                   // off, nothing in the period
    $check('ZZ-CHK new', '2001-03-20 09:00:00');                              // made part-way through
    $result($daily, '2001-03-01', $okSt);
    $result($daily, '2001-03-02', $amber, 'Disk at 85%');
    $result($daily, '2001-03-05', $red, '<p>The <b>backup</b> failed</p>');
    $result($daily, '2001-04-01', $red, 'outside the period');
    $result($weekly, '2001-03-05', $okSt);
    $result($weekly, '2001-03-07', $okSt);                                    // a Wednesday: not due, still counts
    $result($old, '2001-03-10', $red, 'Old check, red');

    echo "\n1. Summary\n";
    $s = rpChecksSummary($conn, $admin, $opts('checks.summary'), $range, 'all');
    $rows = array_column($s['rows'], null, 'check');
    $keys = array_column($s['columns'], 'key');
    ok('a column per status and Not checked', in_array('s' . $okSt, $keys, true) && in_array('s' . $red, $keys, true) && end($keys) === 'missed', json_encode($keys));
    $d = $rows['ZZ-CHK daily'] ?? [];
    ok('daily: 1 / 1 / 1 by status, 28 not checked (the April result left out)', ($d['s' . $okSt] ?? '') === '1' && ($d['s' . $amber] ?? '') === '1' && ($d['s' . $red] ?? '') === '1' && ($d['missed'] ?? '') === '28', json_encode($d));
    $w = $rows['ZZ-CHK weekly'] ?? [];
    ok('weekly: a day it was not due still counts; only its 3 empty Mondays are missed', ($w['s' . $okSt] ?? '') === '2' && ($w['missed'] ?? '') === '3', json_encode($w));
    ok('a check switched off keeps its results, no missed days', ($rows['ZZ-CHK old']['s' . $red] ?? '') === '1' && ($rows['ZZ-CHK old']['missed'] ?? '') === '-');
    ok('one switched off with nothing in the period is left out', !isset($rows['ZZ-CHK gone']));
    ok('a check made part-way counts from that day (12 missed)', ($rows['ZZ-CHK new']['missed'] ?? '') === '12', json_encode($rows['ZZ-CHK new'] ?? null));
    $s2 = rpChecksSummary($conn, $admin, $opts('checks.summary', ['group' => false]), $range, 'all');
    ok('the group column can be hidden', !in_array('group', array_column($s2['columns'], 'key'), true) && in_array('group', $keys, true));

    echo "\n2. Exceptions\n";
    $e = rpChecksExceptions($conn, $admin, $opts('checks.exceptions', ['not_checked' => false]), $range, 'all');
    $mine = array_values(array_filter($e['rows'], fn($r) => str_starts_with($r['check'], 'ZZ-CHK')));
    ok('the results whose status asks for notes, oldest first', array_column($mine, 'status') === [$label($amber), $label($red), $label($red)]
       && array_column($mine, 'check') === ['ZZ-CHK daily', 'ZZ-CHK daily', 'ZZ-CHK old'], json_encode($mine));
    ok('notes as text, tags stripped', ($mine[1]['notes'] ?? '') === 'The backup failed', $mine[1]['notes'] ?? '');
    ok('who set it', ($mine[0]['who'] ?? '') === 'ZZ tester');
    $e2 = rpChecksExceptions($conn, $admin, $opts('checks.exceptions'), $range, 'all');
    $missed = array_filter($e2['rows'], fn($r) => str_starts_with($r['check'], 'ZZ-CHK') && $r['status'] === t('reporting.packs.checks.not_checked'));
    ok('with the days nobody checked: 28 + 3 + 12', count($missed) === 43, (string)count($missed));
    $e3 = rpChecksExceptions($conn, $admin, $opts('checks.exceptions', ['limit' => 10]), $range, 'all');
    ok('the row limit holds, and says so', count($e3['rows']) === 10 && $e3['note'] !== null);

    echo "\n3. Chart\n";
    $c = rpChecksByStatus($conn, $admin, $opts('checks.by_status'), $range, 'all');
    $byLabel = array_combine($c['labels'], $c['series'][0]['values']);
    ok('by status, Not checked included', ($byLabel[$label($okSt)] ?? 0) === 3 && ($byLabel[$label($amber)] ?? 0) === 1 && ($byLabel[$label($red)] ?? 0) === 2
       && ($byLabel[t('reporting.packs.checks.not_checked')] ?? 0) === 43, json_encode($byLabel));
    $okColour = (string)$conn->query("SELECT Colour FROM morningChecks_Statuses WHERE StatusID = $okSt")->fetchColumn();
    ok("in the statuses' own colours", ($c['colours'][array_search($label($okSt), $c['labels'], true)] ?? '') === $okColour, json_encode($c['colours']));
    $c2 = rpChecksByStatus($conn, $admin, $opts('checks.by_status', ['not_checked' => false]), $range, 'all');
    ok('Not checked can be left out', !in_array(t('reporting.packs.checks.not_checked'), $c2['labels'], true));

    echo "\n4. Registered\n";
    $tools = array_column(rpToolboxItems(), 'handler');
    ok('three blocks in the toolbox, in Morning Checks', count(array_intersect(['checks.summary', 'checks.exceptions', 'checks.by_status'], $tools)) === 3
       && rpToolboxItems()['checks_summary']['area'] === 'checks');
    ok('gated by the Morning Checks module', rpHandlers()['checks.summary']['module'] === 'morning-checks');
} finally {
    $conn->rollBack();
}
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
