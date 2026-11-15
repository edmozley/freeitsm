<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Being told (storage series N1): pointing storage somewhere new copies every file there,
 * so every administrator gets a bell (and an email, when a mailbox can send) when a
 * storage location changes, a move starts, or a move ends - for Files and for each store
 * on System -> Storage. Not the administrator who did it.
 *
 * A ZZ administrator, settings and notifications in one transaction that is rolled back;
 * a temporary folder. The store half needs FREEITSM_TEST_S3_* (skipped without them).
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/storage-notify.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/entity_links.php";
require_once "$root/includes/i18n.php";
require_once "$root/includes/services/notifications.php";
require_once "$root/includes/services/files_storage.php";
require_once "$root/includes/services/storage.php";
require_once "$root/includes/storage/store_migration.php";

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nStorage changes are told to administrators\n" . str_repeat('=', 72) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (getenv('FREEITSM_TEST_DATABASE') !== (string)$conn->query('SELECT DATABASE()')->fetchColumn()) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes (rolled back).\n");
}

$types = NotificationsService::types();
ok('a notification type for storage changes, on by default', ($types['storage.changed']['default'] ?? null) === true, json_encode($types['storage.changed'] ?? null));
ok('...linking to System -> Storage', entityLink('storage', 1) === 'system/storage/', var_export(entityLink('storage', 1), true));
ok('...offered on Preferences only to administrators (the only ones sent it)', ($types['storage.changed']['admin_only'] ?? null) === true);
foreach (['common.notifications.pref.storage.changed', 'common.notifications.event.storage.changed'] as $k) {
    ok("...with a label: $k", t($k) !== $k && t($k) !== '', t($k));
}

$dir = str_replace('\\', '/', sys_get_temp_dir()) . '/zz-notify-' . bin2hex(random_bytes(4));
mkdir($dir);
$conn->beginTransaction();
try {
    foreach (['files_storage_driver', 'files_storage_root', 'files_storage_previous', 'files_max_upload_mb'] as $k) {
        $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
    }
    filesStorageReset();
    $tag = 'zznotify' . bin2hex(random_bytes(3));
    $mk = function (string $n) use ($conn, $tag): int {
        $conn->prepare("INSERT INTO analysts (username, full_name, email, password_hash, is_active, is_admin) VALUES (?, ?, ?, '', 1, 1)")
             ->execute(["$tag$n", "ZZ $tag $n", "$tag$n@example.test"]);
        return (int)$conn->lastInsertId();
    };
    $actor = $mk('actor');
    $other = $mk('other');
    $bells = function (int $who) use ($conn): int {
        $q = $conn->prepare("SELECT COALESCE(SUM(event_count), 0) FROM notifications WHERE analyst_id = ? AND entity_type IN ('storage', 'files_storage')");
        $q->execute([$who]);
        return (int)$q->fetchColumn();
    };

    echo "\nFiles\n";
    $b = $bells($other);
    FilesStorageService::save($conn, $actor, ['driver' => 'local', 'max_upload_mb' => 100, 'storage_root' => $dir]);
    ok('changing where Files is stored: the other administrators are told', $bells($other) === $b + 1, (string)($bells($other) - $b));
    ok('...not the one who did it', $bells($actor) === 0);
    $b = $bells($other);
    FilesStorageService::save($conn, $actor, ['driver' => 'local', 'max_upload_mb' => 55, 'storage_root' => $dir]);
    ok('changing only the largest upload: nobody is told', $bells($other) === $b);
    $b = $bells($other);
    storageMigrateEnd($conn, 'old_copies_removed', 'zz old place');
    ok('a Files move ending is told', $bells($other) === $b + 1);
    // Finish on the Files tab is someone's own action: they are not told about it.
    $a = $bells($actor);
    storageMigrateEnd($conn, 'finished_kept_old_copies', 'zz old place', $actor);
    ok('...but not to the one who pressed Finish', $bells($actor) === $a);
    ok('the Files tab passes who pressed Finish', str_contains((string)file_get_contents("$root/includes/services/files_storage.php"), "storageMigrateEnd(\$conn, 'finished_kept_old_copies', \$m['from_label'], \$analystId)"));

    echo "\nThe stores\n";
    $ep = getenv('FREEITSM_TEST_S3_ENDPOINT') ?: '';
    if ($ep === '') {
        echo "  SKIP  the stores (set FREEITSM_TEST_S3_*)\n";
    } else {
        $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'storage\\_%'");
        foreach (['endpoint' => $ep, 'region' => 'us-east-1', 'bucket' => (string)getenv('FREEITSM_TEST_S3_BUCKET'), 'access_key_id' => (string)getenv('FREEITSM_TEST_S3_KEY'),
                  'secret' => encryptValue((string)getenv('FREEITSM_TEST_S3_SECRET')), 'prefix' => 'zz-notify-' . bin2hex(random_bytes(3)) . '/', 'path_style' => '1'] as $k => $v) {
            storeSaveSetting($conn, "storage_s3_$k", $v);
        }
        storeReset();
        $b = $bells($other);
        StorageService::setLocation($conn, 'warroom', 's3', $actor);
        ok('switching a store to S3: the other administrators are told', $bells($other) === $b + 1, (string)($bells($other) - $b));
        $b = $bells($other);
        storeMoveEnd($conn, 'warroom', 'finished_old_copies_kept', $actor);
        ok('a store move ending is told', $bells($other) === $b + 1);
    }
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    filesStorageReset();
    storeReset();
    @rmdir($dir);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
