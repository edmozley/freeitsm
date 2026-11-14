<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Intune syncs as scheduled jobs (GH #342): the claims and the outcomes, without Graph.
 *
 *   - the device sync claim: turned away by a sync running now; a dead "running"
 *     row (nothing done for 10 minutes, or anything older than an hour) is failed
 *     and no longer blocks;
 *   - the software sync's batches: a dead batch is failed before the "one at a
 *     time" check, so it cannot turn every later run away; candidates come in
 *     order of the last ATTEMPT, never-tried first, and a run never takes a device
 *     twice;
 *   - intuneScheduledAppSync()'s outcome: a batch already running -> skipped; a
 *     batch that failed as a whole -> failed; some devices read and some not ->
 *     partial; all read -> ok.
 *
 * The batches are run by a stand-in that writes what a real batch would - Graph
 * is never called. Everything is ZZ-tagged and removed by id in finally.
 *
 * Run:  FREEITSM_TEST_DATABASE=<throwaway db> php tests/intune-sync-jobs.php
 *
 * @wiki Background-Jobs-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/intune.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nIntune syncs as scheduled jobs\n" . str_repeat('=', 70) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes sync jobs, devices and assets.\n");
}

$made = ['sync_jobs' => [], 'app_jobs' => [], 'assets' => [], 'devices' => []];
$maxSync = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM intune_sync_jobs")->fetchColumn();
$maxApp  = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM intune_app_sync_jobs")->fetchColumn();
$tag = 'zzisj' . bin2hex(random_bytes(3));
$batchSetting = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'intune_app_batch_size'")->fetchColumn();

$syncRow = function (string $status, int $minutesAgo, int $processed) use ($conn, &$made): int {
    $conn->prepare("INSERT INTO intune_sync_jobs (started_datetime, status, total, processed, message)
                    VALUES (UTC_TIMESTAMP() - INTERVAL ? MINUTE, ?, 0, ?, 'ZZ test')")->execute([$minutesAgo, $status, $processed]);
    return $made['sync_jobs'][] = (int)$conn->lastInsertId();
};
$appJob = function (string $status, int $minutesAgo, int $processed, array $assets = []) use ($conn, &$made): int {
    $conn->prepare("INSERT INTO intune_app_sync_jobs (started_datetime, status, total, processed, failed, message)
                    VALUES (UTC_TIMESTAMP() - INTERVAL ? MINUTE, ?, ?, ?, 0, 'ZZ test')")->execute([$minutesAgo, $status, count($assets), $processed]);
    $id = $made['app_jobs'][] = (int)$conn->lastInsertId();
    foreach ($assets as $a) $conn->prepare("INSERT INTO intune_app_sync_job_assets (job_id, asset_id, status) VALUES (?, ?, 'done')")->execute([$id, $a]);
    return $id;
};
$statusOf = function (string $table, int $id) use ($conn): string {
    $s = $conn->prepare("SELECT status FROM $table WHERE id = ?"); $s->execute([$id]);
    return (string)$s->fetchColumn();
};
$asset = function (string $name) use ($conn, &$made, $tag): int {
    $conn->prepare("INSERT INTO assets (hostname) VALUES (?)")->execute(["ZZ-ISJ-$name-$tag"]);
    $id = $made['assets'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO intune_devices (intune_id, asset_id, device_name) VALUES (?, ?, ?)")->execute(["$tag-$name", $id, "ZZ-ISJ-$name"]);
    $made['devices'][] = (int)$conn->lastInsertId();
    return $id;
};
// What a batch writes, without Graph: $how(job id, its asset ids) sets the row.
$stand = function (callable $how) use ($conn): callable {
    return function (PDO $c, int $jobId) use ($conn, $how): void {
        $s = $conn->prepare("SELECT asset_id FROM intune_app_sync_job_assets WHERE job_id = ?"); $s->execute([$jobId]);
        $how($jobId, array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
    };
};
// Each run below starts within a second of the last; a run leaves out devices in a
// batch started since it began, so the earlier runs' batches are moved back a minute.
$age = fn() => $conn->exec("UPDATE intune_app_sync_jobs SET started_datetime = started_datetime - INTERVAL 1 MINUTE WHERE id > $maxApp");
$setJob = function (int $jobId, string $status, int $processed, int $failed, string $msg) use ($conn): void {
    $conn->prepare("UPDATE intune_app_sync_jobs SET status = ?, processed = ?, failed = ?, message = ?, finished_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([$status, $processed, $failed, $msg, $jobId]);
    $conn->prepare("UPDATE intune_app_sync_job_assets SET status = 'done' WHERE job_id = ?")->execute([$jobId]);
};

try {
    echo "The device sync claim\n";
    $live = $syncRow('running', 1, 0);
    ok('a sync running now turns a claim away', intuneClaimDeviceSyncJob($conn) === null);
    $conn->prepare("DELETE FROM intune_sync_jobs WHERE id = ?")->execute([$live]);

    $dead = $syncRow('running', 11, 0);
    $long = $syncRow('running', 61, 500);
    $busy = $syncRow('running', 5, 0);
    ok('POSITIVE CONTROL: a sync that started 5 minutes ago and has not reported yet still blocks', intuneClaimDeviceSyncJob($conn) === null);
    ok('...but nothing done for 10 minutes is failed', $statusOf('intune_sync_jobs', $dead) === 'error');
    ok('...and running for over an hour is failed, whatever it processed', $statusOf('intune_sync_jobs', $long) === 'error');
    $conn->prepare("DELETE FROM intune_sync_jobs WHERE id = ?")->execute([$busy]);
    $claimed = intuneClaimDeviceSyncJob($conn);
    if ($claimed) $made['sync_jobs'][] = $claimed;
    ok('with the dead rows failed, a claim gets its own running row', $claimed !== null && $statusOf('intune_sync_jobs', $claimed) === 'running');
    ok('...and a second claim, a moment later, is turned away', intuneClaimDeviceSyncJob($conn) === null);
    $conn->prepare("UPDATE intune_sync_jobs SET status = 'done' WHERE id = ?")->execute([$claimed]);

    echo "Software sync batches\n";
    $old = $appJob('running', 151, 7);
    $fresh = intuneCreateAppSyncJob($conn);
    if (!$fresh['reused'] && $fresh['job_id']) $made['app_jobs'][] = $fresh['job_id'];
    ok('a batch running for over 150 minutes, whatever it did, is failed - and does not turn the next away',
        $statusOf('intune_app_sync_jobs', $old) === 'error' && !$fresh['reused'], json_encode($fresh));
    if ($fresh['job_id']) $setJob($fresh['job_id'], 'done', 0, 0, 'ZZ');

    // Order by last attempt: never tried first, then the oldest attempt.
    $aNever = $asset('NEVER'); $aOld = $asset('OLD'); $aNew = $asset('NEW');
    $appJob('done', 60 * 24, 1, [$aNew]);
    $appJob('done', 60 * 48, 1, [$aOld]);
    $ids = array_values(array_intersect(array_map(fn($r) => (int)$r['asset_id'], intuneGetAppSyncCandidates($conn, 1000000)), [$aNever, $aOld, $aNew]));
    ok('candidates come never-tried first, then by the oldest attempt', $ids === [$aNever, $aOld, $aNew], json_encode($ids));
    $since = (string)$conn->query("SELECT UTC_TIMESTAMP() - INTERVAL 2 MINUTE")->fetchColumn();
    $appJob('done', 1, 1, [$aNever]);
    $ids = array_map(fn($r) => (int)$r['asset_id'], intuneGetAppSyncCandidates($conn, 1000000, $since));
    ok('a device in a batch started during this run is not taken again', !in_array($aNever, $ids, true) && in_array($aOld, $ids, true), json_encode($ids));

    echo "The scheduled run's outcome\n";
    $pending = $appJob('pending', 0, 0, []);
    $r = intuneScheduledAppSync($conn, 600, $stand(fn() => null));
    ok('a batch already running from the tab: the run is skipped, not ok', $r['outcome'] === 'skipped', json_encode($r));
    $setJob($pending, 'done', 0, 0, 'ZZ');

    $r = intuneScheduledAppSync($conn, 600, $stand(fn(int $j, array $a) => $setJob($j, 'error', 0, 0, 'ZZ token refused')));
    ok('a batch that failed as a whole: the run is failed, with the reason', $r['outcome'] === 'failed' && str_contains($r['summary'], 'ZZ token refused'), json_encode($r));

    $age();
    $r = intuneScheduledAppSync($conn, 600, $stand(fn(int $j, array $a) => $setJob($j, 'done', max(0, count($a) - 1), min(1, count($a)), 'ZZ')));
    ok('some devices read and some not: partial', $r['outcome'] === 'partial' && $r['counts']['failed'] >= 1 && $r['counts']['devices'] >= 1, json_encode($r));

    $age();
    $r = intuneScheduledAppSync($conn, 600, $stand(fn(int $j, array $a) => $setJob($j, 'done', count($a), 0, 'ZZ')));
    ok('POSITIVE CONTROL: every device read: ok', $r['outcome'] === 'ok' && $r['counts']['devices'] >= 3, json_encode($r));
    $s = $conn->prepare("SELECT COUNT(*) FROM intune_app_sync_job_assets ja JOIN intune_app_sync_jobs j ON j.id = ja.job_id
                          WHERE ja.asset_id = ? AND j.id > ?");
    $lastBefore = (int)$conn->query("SELECT MAX(id) FROM intune_app_sync_jobs")->fetchColumn();
    // One device a batch, so the run takes several batches.
    $conn->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('intune_app_batch_size', '1')
                 ON DUPLICATE KEY UPDATE setting_value = '1'");
    $age();
    $r2 = intuneScheduledAppSync($conn, 600, $stand(fn(int $j, array $a) => $setJob($j, 'done', count($a), 0, 'ZZ')));
    $once = true;
    foreach ([$aNever, $aOld, $aNew] as $a) { $s->execute([$a, $lastBefore]); $once = $once && (int)$s->fetchColumn() === 1; }
    ok('a run of one-device batches reads each device once', $once && $r2['counts']['batches'] >= 3, json_encode($r2));
} catch (Throwable $e) {
    ok('the run completed', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($batchSetting === false) $conn->exec("DELETE FROM system_settings WHERE setting_key = 'intune_app_batch_size'");
    else $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'intune_app_batch_size'")->execute([$batchSetting]);
    // Every job this run made (by the tests above or by the code under test), then its rows.
    $app = array_map('intval', $conn->query("SELECT id FROM intune_app_sync_jobs WHERE id > $maxApp")->fetchAll(PDO::FETCH_COLUMN));
    $app = array_unique(array_merge($app, $made['app_jobs']));
    if ($app) {
        $in = implode(',', $app);
        $conn->exec("DELETE FROM intune_app_sync_job_assets WHERE job_id IN ($in)");
        $conn->exec("DELETE FROM intune_app_sync_jobs WHERE id IN ($in)");
    }
    $sync = array_unique(array_merge(array_map('intval', $conn->query("SELECT id FROM intune_sync_jobs WHERE id > $maxSync")->fetchAll(PDO::FETCH_COLUMN)), $made['sync_jobs']));
    if ($sync) $conn->exec("DELETE FROM intune_sync_jobs WHERE id IN (" . implode(',', $sync) . ")");
    foreach ($made['devices'] as $id) $conn->prepare("DELETE FROM intune_devices WHERE id = ?")->execute([$id]);
    foreach ($made['assets'] as $id) {
        $conn->prepare("DELETE FROM asset_history WHERE asset_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM assets WHERE id = ?")->execute([$id]);
    }
}

echo "\n" . str_repeat('=', 70) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
