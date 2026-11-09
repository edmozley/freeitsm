<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The local-folder storage driver - today's behaviour (bytes in a folder on
 * the server) behind the same interface the S3 driver implements.
 *
 * Written when Files storage gained an S3 option: every read and write now goes
 * through a driver, so the local one must behave exactly as the old direct file
 * calls did (byte ranges for resumed downloads and video seeking, a missing
 * file reported as missing) and must refuse a key that climbs out of its root.
 * No database; works in a temporary folder that is removed at the end.
 *
 * Run: php tests/storage-local-driver.php
 */

require_once dirname(__DIR__) . '/includes/storage/driver.php';

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}
function rmTree(string $d): void {
    if (!is_dir($d)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}

echo "\nLocal storage driver\n" . str_repeat('=', 72) . "\n";
$root = sys_get_temp_dir() . '/zz-storage-' . bin2hex(random_bytes(4));
$tmp  = sys_get_temp_dir() . '/zz-src-' . bin2hex(random_bytes(4));
try {
    $d = storageDriver(['driver' => 'local', 'root' => $root], 'files');
    ok('factory gives a LocalDriver', $d instanceof LocalDriver);
    $key = '2026/11/08/' . str_repeat('a', 32) . '.bin';

    file_put_contents($tmp, '0123456789abcdef');
    $d->putFile($key, $tmp);
    ok('put then size', $d->size($key) === 16, var_export($d->size($key), true));
    ok('control: copy keeps the source', is_file($tmp));

    $got = '';
    $d->readRange($key, 5, 9, function (string $c) use (&$got) { $got .= $c; return true; });
    ok('readRange returns exactly bytes 5..9', $got === '56789', $got);

    $out = $tmp . '.out';
    $d->readTo($key, $out);
    ok('readTo copies bytes', file_get_contents($out) === '0123456789abcdef');
    @unlink($out);

    $key2 = '2026/11/08/' . str_repeat('b', 32) . '.bin';
    $d->putFile($key2, $tmp, true);
    ok('move consumes the source', !is_file($tmp) && $d->size($key2) === 16);

    $d->delete($key);
    ok('delete then size is null', $d->size($key) === null);
    $threw = null;
    try { $d->delete($key); } catch (Throwable $e) { $threw = $e; }
    ok('delete of a missing key is fine', $threw === null);

    $threw = null;
    try { $d->readRange($key, 0, 1, fn($c) => true); } catch (Throwable $e) { $threw = $e; }
    ok('missing key readRange throws StorageNotFound', $threw instanceof StorageNotFound, $threw ? get_class($threw) : 'nothing');

    $threw = null;
    try { $d->size('../x'); } catch (Throwable $e) { $threw = $e; }
    ok('traversal key is refused', $threw instanceof InvalidArgumentException);
    $threw = null;
    try { $d->size('/etc/passwd'); } catch (Throwable $e) { $threw = $e; }
    ok('absolute key is refused', $threw instanceof InvalidArgumentException);

    ok('label names the folder', str_contains($d->label(), $root));

    if (is_writable('/dev/full')) {   // Linux: every write fails with "no space left"
        file_put_contents($tmp, 'abc');
        $d->putFile($key, $tmp);
        $threw = null;
        try { $d->readTo($key, '/dev/full'); } catch (Throwable $e) { $threw = $e; }
        ok('readTo into a full disk throws', $threw instanceof StorageUnavailable, $threw ? get_class($threw) : 'reported success');
        ok('control: the failed target is not deleted', file_exists('/dev/full'));
    }
} finally {
    rmTree($root);
    @unlink($tmp);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
