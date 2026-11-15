<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The Files recycle bin's safety rules (GH #293, decided 16 Nov 2026):
 *
 *   - THE CLOCK: something already in the bin when purging is switched on gets the
 *     whole period from then - never "deleted within the hour".
 *   - HELD: the retention job never purges a folder holding anything its deleter could
 *     not have deleted by hand; it is listed for an administrator instead.
 *   - DELETED ALONG WITH A FOLDER ABOVE IT: someone who can see a subfolder of a
 *     deleted folder, but not the folder, sees their folder in the bin - without the
 *     hidden name - and can restore it on its own, with the same people able to open it.
 *   - THE RACE: the job purges only the stay in the bin it listed.
 *   - WHO: a person's id is never 0; only the job (null) acts without permission.
 *
 * ZZ folders and ids nobody has; storage is a temporary folder; everything runs in ONE
 * transaction that is rolled back, settings included.
 *
 *   FREEITSM_TEST_DATABASE=<db> php tests/files-recycle-held.php
 */
$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/services/files_recycle.php";
require_once "$root/system/maintenance/includes/tools.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %s\n", $label); }
    else       { $fail++; printf("  FAIL %s  %s\n", $label, $detail); }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the database this may write to (this one is $dbName).\n";
    exit(0);
}

$setting = function (string $k, ?string $v) use ($conn) {
    $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
    if ($v !== null) $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
    filesStorageReset();
};
$folder = function (?int $parent, string $name, bool $inherit = true) use ($conn): int {
    $conn->prepare("INSERT INTO files_folders (parent_id, name, inherit_permissions) VALUES (?, ?, ?)")->execute([$parent, $name, $inherit ? 1 : 0]);
    FilesAcl::reset();
    return (int)$conn->lastInsertId();
};
$grant = function (int $f, int $who, int $lvl) use ($conn) {
    $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level) VALUES (?, 'analyst', ?, ?)
                    ON DUPLICATE KEY UPDATE level = VALUES(level)")->execute([$f, $who, $lvl]);
    FilesAcl::reset();
};
$binIt = function (int $f, ?int $by, int $daysAgo) use ($conn) {
    $conn->prepare("UPDATE files_folders SET deleted_by = ?, deleted_datetime = UTC_TIMESTAMP() - INTERVAL $daysAgo DAY WHERE id = ?")->execute([$by, $f]);
    FilesAcl::reset();
};
$exists = function (int $f) use ($conn): bool {
    $s = $conn->prepare("SELECT 1 FROM files_folders WHERE id = ?");
    $s->execute([$f]);
    return (bool)$s->fetchColumn();
};
$heldIds = fn() => array_column(FilesRecycleService::heldEntries($conn), 'id');

$U1 = 990000101;   // deletes things
$U2 = 990000102;   // owns a folder inside U1's folder, cannot see U1's folder
$U3 = 990000103;   // nothing at all
$tag = 'ZZheld' . bin2hex(random_bytes(3));
$store = sys_get_temp_dir() . '/zz-held-' . bin2hex(random_bytes(4));
mkdir($store);

echo "\nFiles recycle bin: clock, held, rescue, race\n" . str_repeat('=', 60) . "\n";
$conn->beginTransaction();
try {
    foreach (['files_storage_driver', 'files_storage_previous', 'files_storage_cleanup', 'files_recycle_bin_days', 'files_recycle_bin_since'] as $k) $setting($k, null);
    $setting('files_storage_root', $store);
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();

    echo "The clock\n";
    $old = $folder(null, "$tag Old");   $grant($old, $U1, FilesAcl::MODIFY);
    $binIt($old, $U1, 100);
    $d = FilesRecycleService::describe($conn, 30);
    ok('asking about 30 days while purging is off: the clock would start now, so nothing is due', $d['due'] === 0, json_encode($d));
    FilesRecycleService::saveRetention($conn, $admin, 30);
    ok('saving switches the clock on now', FilesRecycleService::clockSince($conn) !== null && FilesRecycleService::clockSince($conn) >= gmdate('Y-m-d H:i:s', time() - 60));
    FilesRecycleService::purgeExpired($conn, time() + 60);
    ok('a folder binned 100 days ago is NOT purged on the first run', $exists($old));
    $rows = array_values(array_filter(FilesRecycleService::entries($conn, $U1), fn($r) => $r['id'] === $old));
    ok('its date is 30 days from the switch-on, not from the delete', $rows && substr($rows[0]['purge_at'], 0, 10) === gmdate('Y-m-d', time() + 30 * 86400), json_encode($rows[0] ?? null));
    FilesRecycleService::saveRetention($conn, $admin, 60);
    ok('changing the period keeps the clock', FilesRecycleService::clockSince($conn) >= gmdate('Y-m-d H:i:s', time() - 60));
    $setting('files_recycle_bin_since', gmdate('Y-m-d H:i:s', time() - 61 * 86400));
    FilesRecycleService::purgeExpired($conn, time() + 60);
    ok('CONTROL: once the period has run from the switch-on, it is purged', !$exists($old));
    FilesRecycleService::saveRetention($conn, $admin, 0);
    ok('0 (keep for ever) switches the clock off', FilesRecycleService::clockSince($conn) === null);
    FilesRecycleService::saveRetention($conn, $admin, 30);
    ok('and on again starts it again from now', FilesRecycleService::clockSince($conn) >= gmdate('Y-m-d H:i:s', time() - 60));
    $setting('files_recycle_bin_since', gmdate('Y-m-d H:i:s', time() - 365 * 86400));   // long ago, for the rest

    echo "Held: never purge what its deleter could not have deleted by hand\n";
    $a = $folder(null, "$tag Projects");   $grant($a, $U1, FilesAcl::MODIFY);
    $hidden = $folder($a, "$tag Payroll", false);   $grant($hidden, $U2, FilesAcl::MODIFY);
    $shared = $folder($a, "$tag Shared");            // inherits: U1 has Modify here
    $binIt($a, $U1, 100);
    $r = FilesRecycleService::purgeExpired($conn, time() + 60);
    ok('the job keeps it', $exists($a) && $exists($hidden));
    ok('and counts it as held', $r['held'] >= 1, json_encode($r));
    $h = array_values(array_filter(FilesRecycleService::heldEntries($conn), fn($x) => $x['id'] === $a));
    ok('the administrator list names it, past its date, and the folder that blocks it',
       $h && $h[0]['due'] && $h[0]['blocking_count'] === 1 && str_ends_with($h[0]['blocking'][0], "$tag Payroll"), json_encode($h));
    ok('the Maintenance card counts it', maintenanceCountFilesHeld($conn) >= 1);
    ok('the settings tab counts it', FilesRecycleService::describe($conn)['held'] >= 1);
    $u1rows = array_values(array_filter(FilesRecycleService::entries($conn, $U1), fn($x) => $x['id'] === $a));
    ok('in the deleter\'s bin it shows no purge date', $u1rows && $u1rows[0]['purge_at'] === null, json_encode($u1rows));

    $unk = $folder(null, "$tag Unknown");     $folder($unk, "$tag Locked", false);
    $binIt($unk, null, 100);
    $unk2 = $folder(null, "$tag Unknown2");   $folder($unk2, "$tag Plain");
    $binIt($unk2, null, 100);
    FilesRecycleService::purgeExpired($conn, time() + 60);
    ok('deleter not recorded + something inside stops inheriting: kept', $exists($unk) && in_array($unk, $heldIds(), true));
    ok('deleter not recorded, everything inherits: purged as before', !$exists($unk2));

    echo "Deleted along with a folder above it\n";
    $mine = array_values(array_filter(FilesRecycleService::entries($conn, $U2), fn($x) => $x['id'] === $hidden));
    ok('the owner of the subfolder sees it in their bin', count($mine) === 1 && $mine[0]['rescue'] === true);
    $json = json_encode(FilesRecycleService::entries($conn, $U2));
    ok('without the name of the folder above it', !str_contains($json, 'Projects') && $mine[0]['location'] === '', $json);
    ok('they can restore it, not delete it for good', $mine[0]['can_modify'] && !$mine[0]['can_purge']);
    ok('nobody else is shown it', !array_filter(FilesRecycleService::entries($conn, $U3), fn($x) => $x['id'] === $hidden));
    try {
        FilesRecycleService::restore($conn, $U3, 'folder', $hidden);
        ok('someone with no access cannot restore it', false);
    } catch (InvalidArgumentException $e) { ok('someone with no access cannot restore it', true); }

    // An inheriting folder: brought out, the SAME people must be able to open it.
    $c = $folder($a, "$tag Plans");   $grant($c, $U2, FilesAcl::MODIFY);   // U1 has Modify on it through Projects
    $before1 = FilesAcl::levelsIncludingDeleted($conn, $U1)[$c] ?? 0;
    $res = FilesRecycleService::restore($conn, $U2, 'folder', $c);
    FilesAcl::reset();
    $row = $conn->query("SELECT parent_id, inherit_permissions, deleted_datetime FROM files_folders WHERE id = $c")->fetch(PDO::FETCH_ASSOC);
    ok('it comes back on its own, to where the deleted folder was', $row['parent_id'] === null && $row['deleted_datetime'] === null && isset(FilesAcl::tree($conn)[$c]), json_encode($row));
    ok('its permissions are frozen: it stops inheriting', (int)$row['inherit_permissions'] === 0);
    ok('the person who had access through the folder above still has it', (FilesAcl::levels($conn, $U1)[$c] ?? 0) === $before1 && $before1 === FilesAcl::MODIFY);
    ok('its owner still has it', (FilesAcl::levels($conn, $U2)[$c] ?? 0) === FilesAcl::MODIFY);
    ok('and nobody gained it', (FilesAcl::levels($conn, $U3)[$c] ?? 0) === 0);
    ok('the deleted folder stays in the bin', !isset(FilesAcl::tree($conn)[$a]) && $exists($a));

    echo "The race: the job purges only the stay it listed\n";
    $r1 = $folder(null, "$tag Race");   $grant($r1, $U1, FilesAcl::MODIFY);
    $binIt($r1, $U1, 100);
    $m = new ReflectionMethod(FilesRecycleService::class, 'purgeAs');
    try {
        $m->invoke(null, $conn, null, 'folder', $r1, time() + 60, '2001-01-01 00:00:00');
        ok('a different deleted_datetime (restored and deleted again since) is not purged', false);
    } catch (InvalidArgumentException $e) {
        ok('a different deleted_datetime (restored and deleted again since) is not purged', $exists($r1));
    }
    $at = (string)$conn->query("SELECT deleted_datetime FROM files_folders WHERE id = $r1")->fetchColumn();
    $m->invoke(null, $conn, null, 'folder', $r1, time() + 60, $at);
    ok('CONTROL: the same one, with the stay it listed, is purged', !$exists($r1));

    echo "CONTROL: give the deleter Modify on what blocked it\n";
    $grant($hidden, $U1, FilesAcl::MODIFY);
    ok('it is no longer held', !in_array($a, $heldIds(), true));
    FilesRecycleService::purgeExpired($conn, time() + 60);
    ok('and the job purges it, everything inside included', !$exists($a) && !$exists($hidden) && !$exists($shared));

    echo "Who acts\n";
    $w = $folder(null, "$tag Who");   $binIt($w, $U1, 1);
    foreach (['purge', 'restore'] as $fn) {
        try {
            FilesRecycleService::$fn($conn, 0, 'folder', $w);
            ok("$fn as analyst 0 is refused, never the system", false);
        } catch (InvalidArgumentException $e) {
            ok("$fn as analyst 0 is refused, never the system", $e->getMessage() === 'Not signed in.' && $exists($w), $e->getMessage());
        }
    }
} catch (Throwable $e) {
    ok('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    FilesAcl::reset();
    filesStorageReset();
    foreach (glob("$store/*") ?: [] as $x) @unlink($x);
    @rmdir($store);
    $left = (int)$conn->query("SELECT COUNT(*) FROM files_folders WHERE name LIKE " . $conn->quote("$tag%"))->fetchColumn();
    ok('rolled back: nothing left', $left === 0);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
