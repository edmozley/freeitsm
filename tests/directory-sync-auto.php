<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Automatic directory imports - when a source is due, and that only one run ever starts.
 *
 * With no scheduled task, every open analyst tab's notification poll can start
 * a due import (includes/directory_sync_auto.php), and the scheduled task can
 * start the same one in the same minute. Ten triggers must still mean ONE run,
 * and a source set to "only when the scheduled task runs" must never start on
 * its own - that is how every source behaved before the setting existed, so it
 * is what an upgraded install must keep doing.
 *
 * Never calls dsyncKickDue(): that would start a real background process. It
 * tests the pieces that process depends on - the due rule, the claim, the lock
 * and which errors may be quiet - against the real auth_providers table.
 *
 * Source rows are created inside a transaction that is ALWAYS rolled back. The
 * named lock is per connection and outside any transaction, so it is released
 * by closing the connection that holds it.
 *
 * Run:  FREEITSM_TEST_DATABASE=<throwaway db> php tests/directory-sync-auto.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/directory_sync_auto.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nAutomatic directory imports\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
// Writes (inside a transaction that is rolled back, but writes), so it runs only
// against the throwaway database it was pointed at - never a live install by accident.
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes people sources and takes their import locks.\n");
}
try { $conn->query("SELECT sync_interval_minutes, sync_last_attempt_datetime FROM auth_providers LIMIT 0"); }
catch (PDOException $e) {
    ok('POSITIVE CONTROL: a missing column reads as "not set up yet", not a fault', dsyncAutoNotReady($e));
    echo "  SKIP  run Database Verification first\n"; exit($fail ? 1 : 0);
}

$conn->beginTransaction();
try {
    $ago = fn(int $min) => gmdate('Y-m-d H:i:s', time() - $min * 60);
    $src = function (string $protocol, ?string $issuer, int $interval, ?string $attempt, ?string $run, int $enabled = 1) use ($conn): int {
        $conn->prepare("INSERT INTO auth_providers (protocol, display_name, issuer_url, client_id, enabled, sync_enabled,
                               sync_interval_minutes, sync_last_attempt_datetime, sync_last_run_datetime)
                        VALUES (?, 'ZZ auto test', ?, 'zz-test', ?, 1, ?, ?, ?)")
             ->execute([$protocol, (string)$issuer, $enabled, $interval, $attempt, $run]);
        return (int)$conn->lastInsertId();
    };
    $entra = 'https://login.microsoftonline.com/zz-test/v2.0';
    $never     = $src('oidc', $entra, 60, null, null);
    $taskOnly  = $src('oidc', $entra, 0, null, null);
    $justRan   = $src('oidc', $entra, 60, null, $ago(10));
    $overdue   = $src('ldap', null, 60, $ago(120), $ago(120));
    $disabled  = $src('oidc', $entra, 60, null, null, 0);
    $google    = $src('oidc', 'https://accounts.google.com', 60, null, null);
    $started   = $src('carddav', null, 1440, $ago(30), null);
    $oldIssuer = $src('oidc', 'https://sts.windows.net/zz-test/', 240, null, null);
    $mine = implode(',', [$never, $taskOnly, $justRan, $overdue, $disabled, $google, $started, $oldIssuer]);

    echo "\nWhich sources are due:\n";
    $due = array_map('intval', $conn->query("SELECT id FROM auth_providers WHERE id IN ($mine) AND " . DSYNC_AUTO_DUE_SQL
        . " AND " . DSYNC_AUTO_SOURCES_SQL . " ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    ok('POSITIVE CONTROL: never imported, overdue, and an old-style Entra issuer are due', $due === [$never, $overdue, $oldIssuer], json_encode($due));
    ok('"only when the scheduled task runs" is never due on its own', !in_array($taskOnly, $due, true));
    ok('an import that finished within the interval is not due', !in_array($justRan, $due, true));
    ok('an import started within the interval is not due', !in_array($started, $due, true));
    ok('a switched-off source is not due', !in_array($disabled, $due, true));
    ok('an OpenID Connect provider that is not Entra is not a people source', !in_array($google, $due, true));

    echo "\nOne claim per interval:\n";
    ok('POSITIVE CONTROL: the first claim of a due source wins', dsyncClaimDue($conn, $never));
    ok('the second claim of it loses', !dsyncClaimDue($conn, $never));
    ok('a claim of a source that is not due loses', !dsyncClaimDue($conn, $taskOnly) && !dsyncClaimDue($conn, $justRan));
    dsyncMarkAttempt($conn, $oldIssuer);
    ok('Import now (marking an attempt) postpones the automatic run', !dsyncClaimDue($conn, $oldIssuer));
    $conn->prepare("UPDATE auth_providers SET sync_last_attempt_datetime = ? WHERE id = ?")->execute([$ago(61), $never]);
    ok('a full interval later it is due again', dsyncClaimDue($conn, $never));

    echo "\nOne import at a time:\n";
    $other = connectToDatabase();
    $lockId = $never;   // the lock is by name, so it needs no committed row
    ok('POSITIVE CONTROL: the first process takes the lock', dsyncLock($conn, $lockId));
    ok('a second process is refused while the first holds it', !dsyncLock($other, $lockId));
    ok('...but may take the lock of a different source', dsyncLock($other, $lockId + 1000000));
    $holder = connectToDatabase();
    ok('a fresh process takes a free lock', dsyncLock($holder, $lockId + 2000000));
    $holder = null;   // the process exits
    ok('...and the lock is free again once it has gone', dsyncLock($other, $lockId + 2000000));
    $other = null;

    echo "\nWhich errors may be quiet:\n";
    $pe = function (string $state): PDOException { $e = new PDOException('x'); $e->errorInfo = [$state, 0, 'x']; return $e; };
    ok('an unknown column is "not set up yet"', dsyncAutoNotReady($pe('42S22')));
    ok('an unknown table is "not set up yet"', dsyncAutoNotReady($pe('42S02')));
    ok('CONTROL: a lost connection is a real fault, never quiet', !dsyncAutoNotReady($pe('HY000')));
    ok('CONTROL: a non-database error is a real fault', !dsyncAutoNotReady(new RuntimeException('x')));

    ok('the offered intervals stop at a day (the job health expects one a day)', max(DSYNC_AUTO_INTERVALS) === 1440 && in_array(0, DSYNC_AUTO_INTERVALS, true));
} finally {
    $conn->rollBack();
}

echo "\n" . str_repeat('=', 70) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
