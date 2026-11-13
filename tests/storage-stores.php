<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The stores layer (#284): ticket attachments, change attachments, documents, screen
 * recordings and war-room attachments, on a server folder or in S3 (a stand-in that can be
 * switched off), and while moving between the two.
 *
 * Writes only system_settings rows it puts back, and temporary folders. Needs
 * FREEITSM_TEST_DATABASE set to the database it may write to.
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/storage/stores.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}
function section(string $t): void { echo "\n$t\n" . str_repeat('-', 72) . "\n"; }

/** S3 stand-in: a folder (not a LocalDriver, so never "the store's own folder") that can be switched off. */
final class ZzStoreFakeS3 implements StorageDriver, StorageLister
{
    public bool $down = false;
    private LocalDriver $d;
    public function __construct(string $dir) { $this->d = new LocalDriver($dir); }
    private function up(): void { if ($this->down) throw new StorageUnavailable('Storage could not be reached: stand-in is down'); }
    public function putFile(string $key, string $localPath, bool $move = false): void { $this->up(); $this->d->putFile($key, $localPath, $move); }
    public function readRange(string $key, int $start, int $end, callable $sink): void { $this->up(); $this->d->readRange($key, $start, $end, $sink); }
    public function readTo(string $key, string $localPath): void { $this->up(); $this->d->readTo($key, $localPath); }
    public function size(string $key): ?int { $this->up(); return $this->d->size($key); }
    public function delete(string $key): void { $this->up(); $this->d->delete($key); }
    public function listKeys(string $prefix): array { $this->up(); return $this->d->listKeys($prefix); }
    public function listObjects(string $prefix): array { $this->up(); return $this->d->listObjects($prefix); }
    public function label(): string { return 's3://zz-fake'; }
}

echo "\nStores\n" . str_repeat('=', 72) . "\n";

section('Keys');
foreach (['0/23/report.pdf', 'a.bin', '12/x y.txt', 'report..final.pdf', 'pending/abc.webm', 'Ünïcødé #1+2.pdf'] as $k) {
    ok("valid: $k", storeKeyIsValid($k));
}
foreach (['', '/abs.pdf', '../up.pdf', 'a/../b', './a', 'a//b', 'a\\b', "a\0b", 'a/'] as $k) {
    ok('refused: ' . json_encode($k), !storeKeyIsValid($k));
}
$tmpd = sys_get_temp_dir() . '/zz-ld-' . bin2hex(random_bytes(4));
mkdir($tmpd);
$ld = new LocalDriver($tmpd);
file_put_contents("$tmpd/x", 'v');
$ld->putFile('report..final.pdf', "$tmpd/x");
ok('the local driver accepts ".." inside a name', $ld->size('report..final.pdf') === 1);
$threw = false;
try { $ld->size('../x'); } catch (InvalidArgumentException $e) { $threw = true; }
ok('...and refuses it as a segment', $threw);
ok('the local driver says its folder', $ld->root() === str_replace('\\', '/', $tmpd));
array_map('unlink', glob("$tmpd/*")); @rmdir($tmpd);

section('Registry');
ok('five stores', array_keys(STORAGE_STORES) === ['tickets', 'changes', 'documents', 'recordings', 'warroom']);
ok('each in its folder', storeLocalRoot('tickets') === str_replace('\\', '/', $root) . '/tickets/attachments'
   && storeLocalRoot('documents') === str_replace('\\', '/', $root) . '/uploads/documents');
$threw = false;
try { storeLocalRoot('nope'); } catch (InvalidArgumentException $e) { $threw = true; }
ok('an unknown store is refused', $threw);

$conn = connectToDatabase();
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName) {
    echo "\nSKIPPED database checks: set FREEITSM_TEST_DATABASE.\n\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}

$work = sys_get_temp_dir() . '/zz-st-' . bin2hex(random_bytes(4));
mkdir($work);
$snap = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'storage\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$restore = function () use ($conn, $snap) {
    $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'storage\\_%'");
    $i = $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($snap as $k => $v) $i->execute([$k, $v]);
    storeReset();
};
$rm = function (string $d) use (&$rm) {
    foreach (glob("$d/{,.}*", GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) continue;
        is_dir($f) && !is_link($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($d);
};

try {
    $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'storage\\_%'");
    storeReset();
    foreach (array_keys(STORAGE_STORES) as $s) StoreCache::$roots[$s] = "$work/local-$s";
    ok('a store with no setting is a server folder', storeLocation($conn, 'tickets') === 'local' && storeIsPlainLocal($conn, 'tickets'));

    foreach (['local', 's3'] as $where) {
        section("Every operation, on " . ($where === 'local' ? 'a server folder' : 'S3 (stand-in)'));
        storeReset();
        $fake = null;
        if ($where === 's3') {
            storeSaveSetting($conn, 'storage_tickets_location', 's3');
            $fake = new ZzStoreFakeS3("$work/s3-tickets");
            StoreCache::$drivers['tickets']['active'] = $fake;
            StoreCache::$drivers['tickets']['previous'] = null;
        }
        $bytes = "%PDF-1.4\n" . str_repeat("line\n", 2000);
        $r = storeSaveBytes($conn, 'tickets', '0/42', $bytes, 'Quarterly report ü.pdf', ATTACHMENT_POLICY_STORE, ['pdf' => ['application/pdf']]);
        ok('save bytes: stored under dir/name', $r['stored'] && preg_match('#^0/42/[0-9a-f]{32}\.pdf$#', (string)$r['key']) === 1, json_encode($r));
        $key = (string)$r['key'];
        if ($where === 'local') {
            ok('...in the same folder as before, with its guard files', is_file("$work/local-tickets/0/42/" . basename($key))
               && is_file("$work/local-tickets/0/42/.htaccess") && $r['path'] !== null);
        } else {
            ok('...in the bucket, nothing left in staging', $fake->size($key) === strlen($bytes) && $r['path'] === null
               && glob("$work/local-tickets/_staging/*.pdf") === []);
        }
        ok('size', storeSize($conn, 'tickets', $key) === strlen($bytes));
        ok('size of a missing file is null', storeSize($conn, 'tickets', '0/42/nope.pdf') === null);
        ok('read as a string', storeGetString($conn, 'tickets', $key) === $bytes);
        $lp = storeLocalPath($conn, 'tickets', $key);
        ok('a local path a library can open', is_file($lp['path']) && file_get_contents($lp['path']) === $bytes
           && $lp['temp'] === ($where === 's3') && ($where === 's3' ? str_ends_with($lp['path'], '.pdf') : true));
        storeReleaseLocal($lp);
        ok('...released: a temporary copy is removed, the real file is not', $where === 's3' ? !is_file($lp['path']) : is_file($lp['path']));
        ob_start();
        $code = storeServe($conn, 'tickets', $key, 10, 19, function () { echo '['; });
        $out = ob_get_clean();
        ok('serve a range (headers only once it answers)', $code === 0 && $out === '[' . substr($bytes, 10, 10), json_encode([$code, $out]));
        ob_start();
        $code = storeServe($conn, 'tickets', '0/42/missing.pdf', 0, 9, function () { echo 'HEADERS'; });
        $out = ob_get_clean();
        ok('serve a missing file: 410, no headers sent', $code === 410 && $out === '', json_encode([$code, $out]));
        ok('serve a bad key: 404', storeServe($conn, 'tickets', '../etc/passwd', 0, 1, fn() => null) === 404);
        storeRename($conn, 'tickets', $key, '0/42/renamed.bin');
        ok('rename', storeSize($conn, 'tickets', $key) === null && storeSize($conn, 'tickets', '0/42/renamed.bin') === strlen($bytes));
        $list = storeList($conn, 'tickets');
        ok('list: the file, no guard files or staging', array_keys($list) === ['0/42/renamed.bin'], json_encode(array_keys($list)));
        ok('delete', storeDelete($conn, 'tickets', '0/42/renamed.bin') && storeSize($conn, 'tickets', '0/42/renamed.bin') === null);
        ok('delete a file already gone is fine', storeDelete($conn, 'tickets', '0/42/renamed.bin'));
        $r = storeSaveBytes($conn, 'tickets', '0/42', 'MZ' . str_repeat("\0", 64), 'tool.exe');
        ok('a refused type is quarantined as before (.bin)', $r['stored'] && $r['quarantined'] && str_ends_with((string)$r['key'], '.bin'));
        if ($where === 's3') {
            $fake->down = true;
            $r = storeSaveBytes($conn, 'tickets', '0/43', '%PDF-1.4 x', 'a.pdf', ATTACHMENT_POLICY_STORE, ['pdf' => ['application/pdf']]);
            ok('S3 down: the save says so, nothing is left in staging', !$r['stored'] && $r['reason'] === 'storage could not be reached'
               && glob("$work/local-tickets/_staging/*.pdf") === [], json_encode($r));
            ob_start();
            $code = storeServe($conn, 'tickets', '0/42/x.pdf', 0, 1, function () { echo 'H'; });
            ob_end_clean();
            ok('S3 down: serving answers 503', $code === 503);
            $threw = null;
            try { storeGetString($conn, 'tickets', '0/42/x.pdf'); } catch (Throwable $e) { $threw = $e; }
            ok('S3 down: reading throws StorageUnavailable', $threw instanceof StorageUnavailable);
            $fake->down = false;
        }
    }

    section('While a store is moving (server folder -> S3)');
    storeReset();
    $fake = new ZzStoreFakeS3("$work/s3-docs");
    StoreCache::$drivers['documents']['active'] = $fake;
    StoreCache::$drivers['documents']['previous'] = new LocalDriver("$work/local-documents");
    @mkdir("$work/local-documents", 0777, true);
    file_put_contents("$work/local-documents/old.pdf", 'old place');
    file_put_contents("$work/new.pdf", 'new place');
    $fake->putFile('new.pdf', "$work/new.pdf");
    ok('a file not copied yet is read from the old place', storeGetString($conn, 'documents', 'old.pdf') === 'old place');
    ok('a copied one from the new place', storeGetString($conn, 'documents', 'new.pdf') === 'new place');
    $r = storeSaveBytes($conn, 'documents', '', '%PDF-1.4 fresh', 'fresh.pdf', ATTACHMENT_POLICY_STORE, ['pdf' => ['application/pdf']]);
    ok('a new file goes to the new place', $fake->size((string)$r['key']) !== null && !is_file("$work/local-documents/" . $r['key']));
    file_put_contents("$work/both.pdf", 'x');
    $fake->putFile('both.pdf', "$work/both.pdf");
    copy("$work/both.pdf", "$work/local-documents/both.pdf");
    storeDelete($conn, 'documents', 'both.pdf');
    ok('a delete removes both copies', $fake->size('both.pdf') === null && !is_file("$work/local-documents/both.pdf"));
    ok('the list covers both places', array_key_exists('old.pdf', storeList($conn, 'documents')) && array_key_exists('new.pdf', storeList($conn, 'documents')));
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $restore();
    StoreCache::$roots = [];
    $rm($work);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
