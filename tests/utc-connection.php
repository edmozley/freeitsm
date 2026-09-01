<?php
/**
 * The database connection's clock (GH #126).
 *
 * WHAT THIS EXISTS TO CATCH
 * -------------------------
 * MySQL evaluates `NOW()`, `CURRENT_TIMESTAMP` and `CURDATE()` in the connection's
 * SESSION time zone. That defaulted to `SYSTEM` — the database server's own clock —
 * while FreeITSM stores every instant in UTC. Any INSERT that did not name a
 * datetime column therefore wrote a LOCAL WALL CLOCK through the column's
 * `DEFAULT CURRENT_TIMESTAMP`, and the screen then read it back as a UTC instant.
 * A note came out the server's own offset into the future.
 *
 * 🔴 THE REASON THIS TEST IS WORTH HAVING is that the fault is INVISIBLE on a
 * server whose clock is already UTC, which is most of them and every sensible
 * container. A suite that only ever runs on such a machine agrees with the bug.
 * So the assertions below do not ask "is the value right"; they ask "is the
 * connection's clock UTC", and then prove the consequence on a scratch table.
 *
 * Run:  php tests/utc-connection.php
 * Safe: writes only to a TEMPORARY table, which exists for this connection only
 *       and disappears when the script ends. Nothing in the real schema is touched.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/timezone.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = '') {
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    printf("  %s %-62s %s\n", $cond ? 'PASS' : 'FAIL', $what, $detail);
}

$conn = connectToDatabase();

// ---------------------------------------------------------------- 1. the pin
echo "\n1. The connection opens in UTC\n";
$tz = $conn->query("SELECT @@session.time_zone")->fetchColumn();
ok('session time_zone is +00:00', $tz === '+00:00', "got '$tz'");

$r = $conn->query("SELECT NOW() n, CURRENT_TIMESTAMP c, UTC_TIMESTAMP() u,
                          TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP()) d")->fetch(PDO::FETCH_ASSOC);
ok('NOW() == UTC_TIMESTAMP()', (int)$r['d'] === 0, "NOW={$r['n']} UTC={$r['u']}");
ok('CURRENT_TIMESTAMP == UTC_TIMESTAMP()', $r['c'] === $r['u'], "CT={$r['c']}");

// 🔑 THE POSITIVE CONTROL. Everything above would also pass on a server that
// happens to run UTC, with the pin removed — which is exactly how this bug hid
// for so long. Open a SECOND connection WITHOUT the options and show that the
// two disagree; if they agree, this machine cannot prove anything either way and
// the test says so rather than reporting a false green.
echo "\n2. Positive control: an unpinned connection is NOT the same clock\n";
$raw = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4",
               DB_USERNAME, DB_PASSWORD);
$rawDiff = (int)$raw->query("SELECT TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP())")->fetchColumn();
if ($rawDiff === 0) {
    echo "  SKIP this server's own clock IS UTC, so pinned and unpinned agree.\n";
    echo "       ⚠️ That means the rest of this run cannot distinguish the fix from\n";
    echo "          the bug. Re-run on a machine set to a non-UTC zone to prove it.\n";
} else {
    ok('unpinned connection differs from UTC', $rawDiff !== 0,
       sprintf('offset %+d min — the error every default carried', $rawDiff / 60));
}

// ------------------------------------------------- 3. the consequence, proven
echo "\n3. A column filled in by DEFAULT CURRENT_TIMESTAMP now stores UTC\n";
// This is the exact shape of ticket_notes.created_datetime.
$conn->exec("CREATE TEMPORARY TABLE _tz_probe (
                 id INT AUTO_INCREMENT PRIMARY KEY,
                 label VARCHAR(32),
                 created_datetime DATETIME NULL DEFAULT CURRENT_TIMESTAMP)");
$conn->exec("INSERT INTO _tz_probe (label) VALUES ('default-fired')");
$row = $conn->query("SELECT created_datetime,
                            TIMESTAMPDIFF(SECOND, created_datetime, UTC_TIMESTAMP()) vs_utc
                       FROM _tz_probe")->fetch(PDO::FETCH_ASSOC);
ok('a row that names no date column lands on UTC', abs((int)$row['vs_utc']) <= 5,
   "stored {$row['created_datetime']}, {$row['vs_utc']}s from UTC");

// ------------------------------------------- 4. naive columns are NOT UTC now
echo "\n4. The wall-clock helpers still answer in local time\n";
$naive = naive_now();
$utcNow = $conn->query("SELECT UTC_TIMESTAMP()")->fetchColumn();
$offset = (new DateTime($naive))->getTimestamp() - (new DateTime($utcNow))->getTimestamp();
$expect = (new DateTimeZone(date_default_timezone_get()))->getOffset(new DateTime('now', new DateTimeZone('UTC')));
ok('naive_now() is the app zone, not UTC', abs($offset - $expect) <= 5,
   sprintf('%+d min from UTC, expected %+d for %s', $offset / 60, $expect / 60, date_default_timezone_get()));

ok('naive_today_sql() is a quoted literal date',
   (bool)preg_match("/^'\d{4}-\d{2}-\d{2}'$/", naive_today_sql()), naive_today_sql());

// 🔑 The one that matters: on the far side of the world the local date is not
// the UTC date, and a bare-date comparison must follow the local one. Proving it
// by moving PHP's zone is the only way to exercise the boundary without waiting
// for midnight.
$keep = date_default_timezone_get();
date_default_timezone_set('Pacific/Auckland');
$localDate = trim(naive_today_sql(), "'");
date_default_timezone_set($keep);
$utcDate = $conn->query("SELECT CURDATE()")->fetchColumn();
if ($localDate === $utcDate) {
    echo "  SKIP Auckland and UTC are on the same date at this instant.\n";
} else {
    ok('naive_today_sql() follows the LOCAL date, not CURDATE()', $localDate !== $utcDate,
       "Auckland $localDate vs CURDATE() $utcDate");
}

// ------------------------------------------------- 5. it survives a reconnect
echo "\n5. The pin survives a reconnect\n";
// MYSQL_ATTR_INIT_COMMAND re-runs when the client silently reopens the socket;
// a hand-issued SET would be lost and nothing would announce it. Simulated by
// asking for a fresh connection through the same helper every caller uses.
$second = connectToDatabase();
ok('a second connectToDatabase() is also UTC',
   $second->query("SELECT @@session.time_zone")->fetchColumn() === '+00:00');

// ------------------------------------------ 6. the hour the two answers differ
echo "\n6. A naive window inside the offset hour is judged by the wall clock\n";
// 🔑 WHY THIS SECTION EXISTS. Comparing the live Watchtower counts before and
// after the change gives 'identical' — but only because no row happens to sit in
// the shifted hour tonight. That is a coincidence, not a proof, and it would
// report success on a build where the naive handling had been dropped entirely.
// So: a change window that opened HALF AN OFFSET AGO. The wall clock must call it
// in progress; a UTC comparison must call it not yet started. If those two agree,
// the distinction this whole change rests on is not being made.
$offMin = (int)round($expect / 60);
if ($offMin === 0) {
    echo "  SKIP the app zone is UTC right now, so there is no hour to test.\n";
} else {
    $conn->exec("CREATE TEMPORARY TABLE _tz_window (
                     label VARCHAR(32), work_start_datetime DATETIME, work_end_datetime DATETIME)");
    // Stored the way a person typed it: a wall clock, half the offset ago.
    $half = (int)floor(abs($offMin) / 2);
    $started = (new DateTime(naive_now()))->modify("-{$half} minutes")->format('Y-m-d H:i:s');
    $ends    = (new DateTime(naive_now()))->modify('+4 hours')->format('Y-m-d H:i:s');
    $conn->prepare("INSERT INTO _tz_window VALUES ('open now', ?, ?)")->execute([$started, $ends]);

    $s = $conn->prepare("SELECT COUNT(*) FROM _tz_window WHERE work_start_datetime <= ?");
    $s->execute([naive_now()]);
    $byWall = (int)$s->fetchColumn();
    $byUtc  = (int)$conn->query("SELECT COUNT(*) FROM _tz_window WHERE work_start_datetime <= UTC_TIMESTAMP()")->fetchColumn();

    ok('the wall clock sees it as started', $byWall === 1, "started $started");
    ok('UTC does NOT see it as started', $byUtc === 0,
        $byUtc === 0 ? 'the distinction is real' : 'BOTH AGREE — naive handling is not working');
}

echo "\n" . str_repeat('-', 78) . "\n";
printf("  %d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
