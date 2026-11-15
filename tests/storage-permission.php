<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The "Storage and backups" permission (Cap::FILES_STORAGE_BACKUPS, PR #318 review /
 * Storage series S3): whoever holds it can send every stored file to a bucket of their
 * choosing, past the folder permissions, so it is its own grant -
 *   - listed under Files on System -> Roles, sensitive, and NOT part of "Manage
 *     everything in Files" (the umbrella);
 *   - needed to change where Files is stored (Files -> Settings -> Storage keeps the
 *     rest, such as the largest upload);
 *   - what opens System -> Storage to someone who is not an administrator.
 *
 * ZZ analysts, team and roles in one transaction that is rolled back; a temporary folder.
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/storage-permission.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/rbac.php";
require_once "$root/includes/services/files_storage.php";
require_once "$root/system/includes/page_access.php";

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}
function threw(callable $fn): ?Throwable { try { $fn(); return null; } catch (Throwable $e) { return $e; } }

echo "\nThe Storage and backups permission\n" . str_repeat('=', 72) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (getenv('FREEITSM_TEST_DATABASE') !== (string)$conn->query('SELECT DATABASE()')->fetchColumn()) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes (rolled back).\n");
}

$cap = Cap::tryFrom('files.storage_backups');
ok('the permission exists: files.storage_backups', $cap !== null);
if ($cap === null) { echo "\n$pass passed, $fail failed\n"; exit(1); }
$meta = capRegistry()['files.storage_backups'] ?? null;
ok('...listed under Files, sensitive', $meta && $meta['module'] === 'files' && $meta['sensitive'] === true, json_encode($meta));
$under = capExpandUmbrellas([capKey(Cap::FILES_MANAGE)]);
ok('"Manage everything in Files" does NOT include it', !in_array('files.storage_backups', $under, true) && in_array(capKey(Cap::FILES_STORAGE), $under, true), json_encode($under));
ok('System -> Roles shows it as outside the umbrella', (capGroups()['files']['capabilities']['files.storage_backups']['outside_umbrella'] ?? null) === true);
ok('...with the settings permissions, not everyday work', (capGroups()['files']['capabilities']['files.storage_backups']['everyday'] ?? null) === false);
$labels = capGroups()['files']['capabilities'];
// Named for backups too (#286 adds them to it); what it grants today says nothing about them.
$grants = (string)substr($labels['files.storage_backups']['label'], strpos($labels['files.storage_backups']['label'], ':') + 1);
ok('its label describes only what exists today (no backups page yet)', !str_contains(strtolower($grants), 'backup'), $labels['files.storage_backups']['label']);
ok('Files -> Storage\'s label says changing the location needs Storage and backups', str_contains($labels[capKey(Cap::FILES_STORAGE)]['label'], 'Storage and backups'), $labels[capKey(Cap::FILES_STORAGE)]['label']);

$dir = str_replace('\\', '/', sys_get_temp_dir()) . '/zz-perm-' . bin2hex(random_bytes(4));
mkdir($dir);
$conn->beginTransaction();
try {
    foreach (['files_storage_driver', 'files_storage_root', 'files_storage_previous', 'files_max_upload_mb'] as $k) {
        $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
    }
    filesStorageReset();
    $tag = 'ZZPERM' . bin2hex(random_bytes(3));
    $conn->prepare("INSERT INTO teams (name, is_active, can_access_all_modules, can_access_all_tenants) VALUES (?, 1, 0, 1)")->execute(["$tag team"]);
    $team = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO team_modules (team_id, module_key, access_level) VALUES (?, 'files', 'full')")->execute([$team]);
    /** A non-admin analyst holding exactly $caps (capability keys) through a role of their own team. */
    $analyst = function (string $name, array $caps) use ($conn, $tag, $team): int {
        $conn->prepare("INSERT INTO teams (name, is_active, can_access_all_modules, can_access_all_tenants) VALUES (?, 1, 0, 1)")->execute(["$tag $name"]);
        $t = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO team_modules (team_id, module_key, access_level) VALUES (?, 'files', 'full')")->execute([$t]);
        $conn->prepare("INSERT INTO analysts (username, full_name, email, password_hash, is_active, is_admin) VALUES (?, ?, ?, '', 1, 0)")
             ->execute([strtolower("$tag$name"), "$tag $name", strtolower("$tag$name") . '@example.test']);
        $a = (int)$conn->lastInsertId();
        $conn->prepare("INSERT INTO analyst_teams (analyst_id, team_id) VALUES (?, ?)")->execute([$a, $t]);
        $conn->prepare("INSERT INTO rbac_roles (name, is_active) VALUES (?, 1)")->execute(["$tag $name role"]);
        $r = (int)$conn->lastInsertId();
        foreach ($caps as $c) $conn->prepare("INSERT INTO rbac_role_capabilities (role_id, capability_key) VALUES (?, ?)")->execute([$r, $c]);
        $conn->prepare("INSERT INTO rbac_team_roles (team_id, role_id) VALUES (?, ?)")->execute([$t, $r]);
        return $a;
    };
    $tabOnly  = $analyst('tab', [capKey(Cap::FILES_STORAGE)]);
    $umbrella = $analyst('umb', [capKey(Cap::FILES_MANAGE)]);
    $holder   = $analyst('hold', [capKey(Cap::FILES_STORAGE), capKey($cap)]);

    $moveTo = ['driver' => 'local', 'max_upload_mb' => 100, 'storage_root' => $dir];
    $sizeOnly = ['driver' => 'local', 'max_upload_mb' => 77, 'storage_root' => ''];

    echo "\nChanging where Files is stored\n";
    ok('Files -> Storage alone: the largest upload can still be changed', threw(fn() => FilesStorageService::save($conn, $tabOnly, $sizeOnly)) === null);
    $e = threw(fn() => FilesStorageService::save($conn, $tabOnly, $moveTo));
    ok('...but not the location', $e instanceof InvalidArgumentException && str_contains($e->getMessage(), 'Storage and backups'), $e ? $e->getMessage() : 'saved');
    $e = threw(fn() => FilesStorageService::save($conn, $umbrella, $moveTo));
    ok('"Manage everything in Files" cannot change it either', $e instanceof InvalidArgumentException, $e ? $e->getMessage() : 'saved');
    ok('with Storage and backups it can', threw(fn() => FilesStorageService::save($conn, $holder, $moveTo)) === null);
    ok('...and the location really changed', rtrim(filesSetting($conn, 'files_storage_root'), '/') === $dir);
    ok('describe() tells the page whether the location may be changed',
       (FilesStorageService::describe($conn, $tabOnly)['can_change_location'] ?? null) === false
       && (FilesStorageService::describe($conn, $holder)['can_change_location'] ?? null) === true);

    echo "\nSystem -> Storage\n";
    $grant = ['module' => 'files', 'cap' => $cap];
    ok('the page grants Storage and backups (as Intune\'s pages grant theirs)', str_contains((string)file_get_contents("$root/system/storage/index.php"), 'Cap::FILES_STORAGE_BACKUPS'));
    ok('...a holder may open it', systemPageAllowed($conn, $holder, 'storage', $grant) === true);
    ok('...Files -> Storage alone may not', systemPageAllowed($conn, $tabOnly, 'storage', $grant) === false);
    $api = (string)file_get_contents("$root/api/system/storage.php");
    ok('its API checks the same permission, not administrator-only', str_contains($api, 'requireCapabilityJson(Cap::FILES_STORAGE_BACKUPS') && !str_contains($api, "admin_api_guard.php"));

    // PR #353 review: analyst 0 is what (int) of a missing session id gives, so it must never
    // mean "the system, no checks". The system is null; a person's id below 1 is refused.
    echo "\nWho acts: a person, or the system - never analyst 0\n";
    require_once "$root/includes/services/storage.php";
    $e = threw(fn() => FilesStorageService::save($conn, 0, $moveTo));
    ok('Files -> Storage: analyst 0 cannot change the location', $e instanceof InvalidArgumentException, $e ? $e->getMessage() : 'saved');
    $e = threw(fn() => FilesStorageService::save($conn, 0, $sizeOnly));
    ok('...nor anything else on the tab', $e instanceof InvalidArgumentException, $e ? $e->getMessage() : 'saved');
    foreach (['migrationAction' => fn() => FilesStorageService::migrationAction($conn, 0, 'finish_keep'),
              'testAudited'     => fn() => FilesStorageService::testAudited($conn, 0, ['endpoint' => 'http://127.0.0.1:9', 'bucket' => 'zz'])] as $m => $fn) {
        $e = threw($fn);
        ok("Files -> Storage $m(): analyst 0 is refused as not signed in", $e instanceof InvalidArgumentException && str_contains($e->getMessage(), 'Not signed in'), $e ? $e->getMessage() : 'ran');
    }
    ok('canChangeLocation(0) is no, canChangeLocation(null) - the system - is yes',
       FilesStorageService::canChangeLocation($conn, 0) === false && FilesStorageService::canChangeLocation($conn, null) === true);
    foreach ([
        'saveConnection' => fn() => StorageService::saveConnection($conn, ['endpoint' => 'http://127.0.0.1:9', 'bucket' => 'zz'], 0),
        'setLocation'    => fn() => StorageService::setLocation($conn, 'warroom', 's3', 0),
        'moveAction'     => fn() => StorageService::moveAction($conn, 'warroom', 'finish_keep', 0),
        'test'           => fn() => StorageService::test($conn, ['endpoint' => 'http://127.0.0.1:9', 'bucket' => 'zz'], 0),
    ] as $m => $fn) {
        $e = threw($fn);
        ok("System -> Storage $m(): analyst 0 is refused as not signed in", $e instanceof InvalidArgumentException && str_contains($e->getMessage(), 'Not signed in'), $e ? $e->getMessage() : 'ran');
    }
    echo "
System -> Storage: lookups and transactions
";
    $usage = new ReflectionMethod('StorageService', 'usage');
    ok('a table not created yet holds nothing: 0 files', $usage->invoke(null, $conn, 'SELECT COUNT(*), 0 FROM zz_no_such_table_284') === [0, 0]);
    ok('any other failed count is unknown (null), never "empty"', $usage->invoke(null, $conn, 'SELECT COUNT(*), 0 FROM analysts WHERE zz_no_such_column = 1') === [null, null]);
    // A store going to S3 on the connection Files was given adopts it - inside the caller's
    // transaction when there is one (this test runs in one), never a second BEGIN.
    foreach (['endpoint' => 'http://zz.invalid', 'bucket' => 'zzadopt', 'access_key_id' => 'k', 'secret' => 'x', 'region' => 'us-east-1', 'prefix' => '', 'path_style' => '1'] as $k => $v) {
        $conn->prepare("DELETE FROM system_settings WHERE setting_key IN (?, ?)")->execute(["storage_s3_$k", "files_s3_$k"]);
        $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute(["files_s3_$k", $v]);
    }
    storeReset(); filesStorageReset();
    $adopt = new ReflectionMethod('StorageService', 'adoptFilesConnection');
    $e = threw(fn() => $adopt->invoke(null, $conn));
    ok('adopting the Files connection joins the open transaction', $e === null && $conn->inTransaction() && storeSetting($conn, 'storage_s3_bucket') === 'zzadopt', $e ? $e->getMessage() : '');
    storeReset(); filesStorageReset();

    foreach (['storageMigrateEnd' => 3, 'storeMoveEnd' => 3, 'storageNotifyAdmins' => 4, 'storeMoveAudit' => 1] as $fn => $i) {
        $p = (new ReflectionFunction($fn))->getParameters()[$i];
        ok("$fn(): the system is null, not 0", $p->allowsNull() && (!$p->isOptional() || $p->getDefaultValue() === null));
    }
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    filesStorageReset();
    @rmdir($dir);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
