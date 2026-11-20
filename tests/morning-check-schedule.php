<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * When a morning check falls due (GH #383) - includes/morning_check_schedule.php.
 * Pure date logic: no database, no web server.
 *
 *   php tests/morning-check-schedule.php
 *
 * @wiki Morning-Check-Schedules-Developer-Guide
 */
require dirname(__DIR__) . '/includes/morning_check_schedule.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}
function threw(callable $fn): ?string { try { $fn(); return null; } catch (InvalidArgumentException $e) { return $e->getMessage(); } }
/** The days in [$from, $to] a row is due, as Y-m-d. */
function dueBetween(array $row, string $from, string $to): array {
    $out = []; $d = new DateTimeImmutable($from); $end = new DateTimeImmutable($to);
    while ($d <= $end) { if (MorningCheckSchedule::isDue($row, $d->format('Y-m-d'))) $out[] = $d->format('Y-m-d'); $d = $d->modify('+1 day'); }
    return $out;
}
$row = fn(array $s) => MorningCheckSchedule::columns($s);

echo "\nMorning check schedules (GH #383)\n" . str_repeat('=', 72) . "\n";

// 2026-11-16 is a Monday.
echo "Every day - every check that existed before\n";
ok('a check with no schedule columns is due every day', count(dueBetween([], '2026-11-16', '2026-11-22')) === 7);
ok('columns(null) is every day, stored as NULL', $row([]) === ['ScheduleType' => null, 'ScheduleDays' => null, 'ScheduleMonthWhen' => null, 'ScheduleMonthDay' => null]);
ok("type 'daily' is stored as NULL too", $row(['type' => 'daily'])['ScheduleType'] === null);

echo "Every weekday\n";
$wd = $row(['type' => 'weekdays']);
ok('Monday to Friday, not the weekend', dueBetween($wd, '2026-11-16', '2026-11-22') === ['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20']);
ok('from Friday the next is Monday', MorningCheckSchedule::nextDue($wd, '2026-11-20') === '2026-11-23');
ok('from Sunday the previous is Friday', MorningCheckSchedule::previousDue($wd, '2026-11-22') === '2026-11-20');

echo "Weekly\n";
$wk = $row(['type' => 'weekly', 'days' => [4, 1, 1]]);
ok('days are tidied: sorted, no repeats', $wk['ScheduleDays'] === '1,4');
ok('Monday and Thursday only', dueBetween($wk, '2026-11-16', '2026-11-29') === ['2026-11-16', '2026-11-19', '2026-11-23', '2026-11-26']);
ok("days may come as '1,4'", $row(['type' => 'weekly', 'days' => '4,1'])['ScheduleDays'] === '1,4');
ok('no day is refused', threw(fn() => $row(['type' => 'weekly', 'days' => []])) !== null);
ok('a day outside 1-7 is dropped, and none left is refused', threw(fn() => $row(['type' => 'weekly', 'days' => [0, 8]])) !== null);
ok('next after Thursday is Monday', MorningCheckSchedule::nextDue($wk, '2026-11-19') === '2026-11-23');

echo "Monthly\n";
$m15 = $row(['type' => 'monthly', 'month_when' => 'day', 'month_day' => 15]);
ok('on the 15th', dueBetween($m15, '2026-11-01', '2026-12-31') === ['2026-11-15', '2026-12-15']);
$m31 = $row(['type' => 'monthly', 'month_when' => 'day', 'month_day' => 31]);
ok('the 31st falls on the last day of a short month', dueBetween($m31, '2026-11-01', '2027-02-28') === ['2026-11-30', '2026-12-31', '2027-01-31', '2027-02-28']);
ok('the 29th in a leap February', MorningCheckSchedule::isDue($row(['type' => 'monthly', 'month_day' => 29]), '2028-02-29'));
ok('day 0 is refused', threw(fn() => $row(['type' => 'monthly', 'month_when' => 'day', 'month_day' => 0])) !== null);
ok('day 32 is refused', threw(fn() => $row(['type' => 'monthly', 'month_when' => 'day', 'month_day' => 32])) !== null);
$fw = $row(['type' => 'monthly', 'month_when' => 'first_weekday']);
ok('first weekday: Mon 2 Nov 2026 (the 1st is a Sunday)', dueBetween($fw, '2026-11-01', '2026-11-30') === ['2026-11-02']);
$lw = $row(['type' => 'monthly', 'month_when' => 'last_weekday']);
ok('last weekday: Fri 29 Jan 2027 (the 31st is a Sunday)', dueBetween($lw, '2027-01-01', '2027-01-31') === ['2027-01-29']);
ok('last weekday: Mon 30 Nov 2026', dueBetween($lw, '2026-11-01', '2026-11-30') === ['2026-11-30']);
$ld = $row(['type' => 'monthly', 'month_when' => 'last_day']);
ok('last day of February 2027 is the 28th', dueBetween($ld, '2027-02-01', '2027-02-28') === ['2027-02-28']);
ok('next for a monthly check crosses into the next month', MorningCheckSchedule::nextDue($m15, '2026-11-15') === '2026-12-15');
ok('previous for a monthly check goes back a month', MorningCheckSchedule::previousDue($m15, '2026-12-14') === '2026-11-15');
ok('an unknown month_when is refused', threw(fn() => $row(['type' => 'monthly', 'month_when' => 'second_tuesday'])) !== null);

echo "Never hide a check by accident\n";
ok('an unknown type is refused on save', threw(fn() => $row(['type' => 'hourly'])) !== null);
ok('...and a stored unknown type is due every day', MorningCheckSchedule::isDue(['ScheduleType' => 'hourly'], '2026-11-21'));
ok('a weekly row with no days is due every day', MorningCheckSchedule::isDue(['ScheduleType' => 'weekly', 'ScheduleDays' => ''], '2026-11-21'));
ok('a monthly row with no day is due every day', MorningCheckSchedule::isDue(['ScheduleType' => 'monthly', 'ScheduleMonthWhen' => 'day', 'ScheduleMonthDay' => null], '2026-11-21'));
ok('a bad date is treated as due', MorningCheckSchedule::isDue($wk, 'not-a-date'));

echo "As the browser sees it\n";
ok('toArray weekly', MorningCheckSchedule::toArray($wk) === ['type' => 'weekly', 'days' => [1, 4], 'month_when' => null, 'month_day' => null]);
ok('toArray monthly on a day', MorningCheckSchedule::toArray($m15) === ['type' => 'monthly', 'days' => [], 'month_when' => 'day', 'month_day' => 15]);
ok('toArray every day', MorningCheckSchedule::toArray([]) === ['type' => 'daily', 'days' => [], 'month_when' => null, 'month_day' => null]);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
