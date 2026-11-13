<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Moving a store (#284) between its server folder and S3 (a stand-in that can be switched
 * off): queue, copy and check, reads and writes during the move, late arrivals, failures,
 * retry, skip, remove old copies, finish and keep, an outage, and moving back.
 *
 * Writes storage_* settings and storage_migration_items rows for the five stores (put back
 * and removed at the end). Needs FREEITSM_TEST_DATABASE.
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/storage/stores.php";
require_once "$root/includes/storage/store_migration.php";
require_once "$root/includes/job_registry.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}
function section(string $t): void { echo "\n$t\n" . str_repeat('-', 72) . "\n"; }

/** S3 stand-in: a folder per store that can be switched off, or refuse one key. */
final class ZzMoveFakeS3 implements StorageDriver, StorageLister
{
    public static bool $down = false;
    public static ?string $refuse = null;
    private LocalDriver $d;
    public function __construct(string $dir) { $this->d = new LocalDriver($dir); }
    private function up(): void { if (self::$down) throw new StorageUnavailable('Storage could not be reached: stand-in is down'); }
    public function putFile(string $key, string $localPath, bool $move = false): void {
        $this->up();
        if ($key === self::$refuse) throw new RuntimeException('the stand-in refuses this key');
        $this->d->putFile($key, $localPath, $move);
    }
    public function readRange(string $key, int $start, int $end, callable $sink): void { $this->up(); $this->d->readRange($key, $start, $end, $sink); }
    public function readTo(string $key, string $localPath): void { $this->up(); $this->d->readTo($key, $localPath); }
    public function size(string $key): ?int { $this->up(); return $this->d->size($key); }
    public function delete(string $key): void { $this->up(); $this->d->delete($key); }
    public function listKeys(string $prefix): array { $this->up(); return $this->d->listKeys($prefix); }
    public function listObjects(string $prefix): array { $this->up(); return $this->d->listObjects($prefix); }
    public function label(): string { return 's3://zz-fake'; }
}

echo "\nMoving a store\n" . str_repeat('=', 72) . "\n";
$conn = connectToDatabase();
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName) {
    echo "\nSKIPPED: set FREEITSM_TEST_DATABASE.\n\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
$work = sys_get_temp_dir() . '/zz-mv-' . bin2hex(random_bytes(4));
mkdir($work);
$snap = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'storage\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$admin = (int)$conn->query('SELECT id FROM analysts ORDER BY id LIMIT 1')->fetchColumn();
$rm = function (string $d) use (&$rm) {
    foreach (glob("$d/{,.}*", GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) continue;
        is_dir($f) && !is_link($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($d);
};
$batch = fn(string $store) => storeMoveBatch($conn, $store, time() + 30);
$items = fn(string $store) => $conn->query("SELECT object_key, state FROM storage_migration_items WHERE store = " . $conn->quote($store) . " ORDER BY object_key")->fetchAll(PDO::FETCH_KEY_PAIR);

try {
    $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'storage\\_%'");
    $conn->exec("DELETE FROM storage_migration_items WHERE store IN ('tickets','changes','documents','recordings','warroom')");
    foreach (array_keys(STORAGE_STORES) as $s) StoreCache::$roots[$s] = "$work/local-$s";
    StoreCache::$factory = function (array $cfg, string $store) use ($work): StorageDriver {
        return ($cfg['driver'] ?? 'local') === 's3' ? new ZzMoveFakeS3("$work/s3-$store") : new LocalDriver((string)$cfg['root']);
    };
    foreach (['endpoint' => 'http://zz-fake.invalid', 'bucket' => 'zz', 'access_key_id' => 'k', 'secret' => encryptValue('s'), 'prefix' => '', 'path_style' => '1', 'region' => 'us-east-1'] as $k => $v) {
        storeSaveSetting($conn, "storage_s3_$k", $v);
    }
    storeReset();

    // Files on the server folder, guard files included.
    $mk = function (string $store, string $key, string $bytes) use ($work) {
        $p = "$work/local-$store/$key";
        @mkdir(dirname($p), 0777, true);
        file_put_contents($p, $bytes);
    };
    $mk('tickets', '0/7/a.pdf', 'first');
    $mk('tickets', '0/7/rapport final é.pdf', 'second, odd name');
    $mk('tickets', '0/8/c.bin', str_repeat('x', 70000));
    $mk('tickets', '0/7/.htaccess', 'Require all denied');
    $mk('tickets', 'web.config', '<configuration/>');

    section('Starting a move');
    $threw = null;
    try { storeMoveStart($conn, 'tickets', 'nowhere', $admin); } catch (InvalidArgumentException $e) { $threw = $e; }
    ok('an unknown location is refused', $threw !== null);
    ok('moving to where it already is: nothing to do', storeMoveStart($conn, 'tickets', 'local', $admin) === 0 && storeMoveSummary($conn, 'tickets') === null);
    $n = storeMoveStart($conn, 'tickets', 's3', $admin);
    ok('to S3: every file is queued (guard files are not files)', $n === 3 && count($items('tickets')) === 3, (string)$n . ' ' . json_encode($items('tickets')));
    ok('the switch now points at S3, the old place is remembered', storeLocation($conn, 'tickets') === 's3' && storePreviousConfig($conn, 'tickets')['driver'] === 'local');
    $threw = null;
    try { storeMoveStart($conn, 'tickets', 'local', $admin); } catch (InvalidArgumentException $e) { $threw = $e; }
    ok('a second move while one runs is refused', $threw !== null);
    ok('the job is needed while a store moves', jobRegistry()['storage_migrate']['needed']($conn) === true);
    $s = storeMoveSummary($conn, 'tickets');
    ok('progress: 3 to copy', $s !== null && $s['pending'] === 3 && $s['from'] === 'local' && $s['to'] === 's3', json_encode($s));

    section('During the move');
    ok('a file not copied yet still reads (from the old place)', storeGetString($conn, 'tickets', '0/7/a.pdf') === 'first');
    $r = storeSaveBytes($conn, 'tickets', '0/9', '%PDF-1.4 new', 'new.pdf', ATTACHMENT_POLICY_STORE, ['pdf' => ['application/pdf']]);
    ok('a new file goes to S3', is_file("$work/s3-tickets/" . $r['key']) && !is_file("$work/local-tickets/" . $r['key']));
    $mk('tickets', '0/10/late.pdf', 'written to the old place after the queue was made');

    section('Copying');
    $b = $batch('tickets');
    ok('the batch copied the queued files', $b['copied'] === 3 && $b['failed'] === 0, json_encode($b));
    ok('...each checked and marked verified', array_values(array_unique(array_values(array_intersect_key($items('tickets'), array_flip(['0/7/a.pdf', '0/8/c.bin'])))))  === ['verified']);
    ok('...the odd name too', is_file("$work/s3-tickets/0/7/rapport final é.pdf") && file_get_contents("$work/s3-tickets/0/7/rapport final é.pdf") === 'second, odd name');
    $b = $batch('tickets');
    ok('a file that reached the old place late is found and copied', isset($items('tickets')['0/10/late.pdf']) && is_file("$work/s3-tickets/0/10/late.pdf"), json_encode([$b, $items('tickets')]));
    ok('the copy is read from S3 now', storeGetString($conn, 'tickets', '0/7/a.pdf') === 'first');
    ok('...and the old copy is still there (nothing deleted yet)', is_file("$work/local-tickets/0/7/a.pdf"));

    section('Removing the old copies');
    storeMoveAction($conn, 'tickets', 'remove_old', $admin);
    $b = $batch('tickets');
    ok('old copies removed, the move is over', !is_file("$work/local-tickets/0/7/a.pdf") && !is_file("$work/local-tickets/0/10/late.pdf")
       && storeMoveSummary($conn, 'tickets') === null && $items('tickets') === [] && storePreviousConfig($conn, 'tickets') === null, json_encode($b));
    ok('the folder\'s guard files are left alone', is_file("$work/local-tickets/0/7/.htaccess") && is_file("$work/local-tickets/web.config"));
    ok('everything still reads', storeGetString($conn, 'tickets', '0/8/c.bin') === str_repeat('x', 70000));
    ok('the job is no longer needed', jobRegistry()['storage_migrate']['needed']($conn) === false);

    section('Failures, retry, skip');
    $mk('warroom', 'w1.txt', 'one');
    $mk('warroom', 'w2.txt', 'two');
    $mk('warroom', 'w3.txt', 'three');
    storeMoveStart($conn, 'warroom', 's3', $admin);
    ZzMoveFakeS3::$down = true;
    $b = $batch('warroom');
    ok('S3 down: the batch stops, no attempt is charged', !empty($b['unavailable']) && $b['copied'] === 0
       && (int)$conn->query("SELECT MAX(attempts) FROM storage_migration_items WHERE store = 'warroom'")->fetchColumn() === 0, json_encode($b));
    ZzMoveFakeS3::$down = false;
    ZzMoveFakeS3::$refuse = 'w2.txt';
    for ($i = 0; $i < 6; $i++) $batch('warroom');
    $st = $items('warroom');
    ok('a file that keeps failing is marked failed after 5 tries', ($st['w2.txt'] ?? '') === 'failed' && $st['w1.txt'] === 'verified', json_encode($st));
    $threw = null;
    try { storeMoveAction($conn, 'warroom', 'remove_old', $admin); } catch (InvalidArgumentException $e) { $threw = $e; }
    ok('old copies cannot be removed while a file failed', $threw !== null);
    ZzMoveFakeS3::$refuse = null;
    storeMoveAction($conn, 'warroom', 'retry_failed', $admin);
    $batch('warroom');
    ok('retry: it is copied', ($items('warroom')['w2.txt'] ?? '') === 'verified');
    $mk('changes', '5/e.pdf', 'evidence');
    $mk('changes', '5/f.pdf', 'more evidence');
    storeMoveStart($conn, 'changes', 's3', $admin);
    ZzMoveFakeS3::$refuse = '5/f.pdf';
    for ($i = 0; $i < 6; $i++) $batch('changes');
    unlink("$work/local-changes/5/f.pdf");   // its old copy is gone: it can never be copied
    ZzMoveFakeS3::$refuse = null;
    storeMoveAction($conn, 'changes', 'skip_missing', $admin);
    ok('skip missing: a file whose old copy is gone is set aside', ($items('changes')['5/f.pdf'] ?? '') === 'skipped');
    storeMoveAction($conn, 'changes', 'finish_keep', $admin);
    ok('finish, keep old copies: the move ends, nothing is deleted', storeMoveSummary($conn, 'changes') === null && is_file("$work/local-changes/5/e.pdf")
       && storeLocation($conn, 'changes') === 's3' && storeGetString($conn, 'changes', '5/e.pdf') === 'evidence');

    section('A long key (an older attachment row with the sender\'s own long name)');
    $longKey = '0/77/' . str_repeat('d', 120) . '/' . str_repeat('e', 150) . '.pdf';
    $mk('recordings', $longKey, 'long name');
    storeMoveStart($conn, 'recordings', 's3', $admin);
    $batch('recordings');
    ok('a key over 255 characters is queued and copied', strlen($longKey) > 255 && ($items('recordings')[$longKey] ?? '') === 'verified', (string)strlen($longKey));
    storeMoveAction($conn, 'recordings', 'finish_keep', $admin);

    section('Moving back to the server folder');
    @unlink("$work/local-changes/5/e.pdf");
    $n = storeMoveStart($conn, 'changes', 'local', $admin);
    $batch('changes');
    ok('S3 -> server folder: queued from the bucket and copied back', $n === 1 && file_get_contents("$work/local-changes/5/e.pdf") === 'evidence', (string)$n);
    storeMoveAction($conn, 'changes', 'remove_old', $admin);
    $batch('changes');
    ok('...and the bucket copies removed', !is_file("$work/s3-changes/5/e.pdf") && storeMoveSummary($conn, 'changes') === null && storeLocation($conn, 'changes') === 'local');
    $threw = null;
    storeSaveSetting($conn, 'storage_s3_bucket', null);
    storeSaveSetting($conn, 'files_s3_bucket', null);
    storeReset();
    try { storeMoveStart($conn, 'documents', 's3', $admin); } catch (InvalidArgumentException $e) { $threw = $e; }
    ok('to S3 with no connection saved: refused', $threw !== null && storeLocation($conn, 'documents') === 'local');
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $conn->exec("DELETE FROM storage_migration_items WHERE store IN ('tickets','changes','documents','recordings','warroom')");
    $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'storage\\_%'");
    $i = $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($snap as $k => $v) $i->execute([$k, $v]);
    StoreCache::$roots = [];
    StoreCache::$factory = null;
    storeReset();
    $rm($work);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
