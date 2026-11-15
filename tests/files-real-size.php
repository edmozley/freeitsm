<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A stored file whose real size is not the recorded size_bytes (truncated, or written
 * over) is never sent as if it were whole (PR #318 review R1): a download from the start
 * asks the store for the size and answers 410 when they differ - instead of headers that
 * promise more bytes than follow. A file of the right size is sent as before.
 *
 * Files on a temporary server folder; no database writes.
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/files-real-size.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/files/storage.php";

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nFiles: the real size of a stored file\n" . str_repeat('=', 72) . "\n";
$conn = connectToDatabase();
if (getenv('FREEITSM_TEST_DATABASE') !== (string)$conn->query('SELECT DATABASE()')->fetchColumn()) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at.\n");
}
$dir = str_replace('\\', '/', sys_get_temp_dir()) . '/zz-size-' . bin2hex(random_bytes(4));
mkdir($dir);
filesStorageReset();
FilesStorageCache::$drivers['active'] = new LocalDriver($dir);
FilesStorageCache::$drivers['previous'] = null;

$key = '2026/11/15/' . bin2hex(random_bytes(16)) . '.bin';
@mkdir(dirname("$dir/$key"), 0777, true);
file_put_contents("$dir/$key", 'abcdef');
$serve = function (int $recorded, int $start, int $end) use ($conn, $key): array {
    $began = false;
    ob_start();
    $status = filesServeBytes($conn, ['storage_path' => $key, 'size_bytes' => $recorded], $start, $end, function () use (&$began) { $began = true; });
    return [$status, (string)ob_get_clean(), $began];
};

[$st, $out, $began] = $serve(6, 0, 5);
ok('CONTROL: the right size is sent whole', $st === 0 && $out === 'abcdef' && $began);
[$st, $out, $began] = $serve(10, 0, 9);
ok('recorded bigger than what is stored (truncated): 410, nothing sent, no headers', $st === 410 && $out === '' && !$began, "status $st, '$out'");
[$st, $out, $began] = $serve(4, 0, 3);
ok('recorded smaller (written over): 410 too', $st === 410 && $out === '' && !$began, "status $st");
[$st, $out] = $serve(6, 2, 4);
ok('a range further in is sent as before', $st === 0 && $out === 'cde', "status $st, '$out'");

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
@rmdir($dir);
filesStorageReset();

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
