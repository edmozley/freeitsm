<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The S3-compatible storage driver against a real S3 server.
 *
 * Written with the S3 storage option: the signer can be right on paper and
 * still be refused by a real server, and the failures that matter to someone
 * using Files - a dead endpoint hanging every download, a seek in a video
 * returning the wrong bytes, a large file never completing - only show up
 * on the wire.
 *
 * Needs an S3 endpoint to test against (a local MinIO or RustFS is fine):
 *   FREEITSM_TEST_S3_ENDPOINT  e.g. http://localhost:9000
 *   FREEITSM_TEST_S3_KEY / FREEITSM_TEST_S3_SECRET / FREEITSM_TEST_S3_BUCKET (bucket must exist)
 *   FREEITSM_TEST_S3_REGION    optional, default us-east-1
 * Without them the live part is skipped; the unreachable-endpoint check always runs.
 * Everything it writes is under a random zz-<hex>/ prefix and deleted again.
 *
 * Run: php tests/storage-s3-live.php
 */

require_once dirname(__DIR__) . '/includes/storage/driver.php';
require_once dirname(__DIR__) . '/includes/storage/s3_driver.php';

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}

echo "\nS3 storage driver\n" . str_repeat('=', 72) . "\n";

// Always: a dead endpoint fails fast with StorageUnavailable.
$dead = new S3Driver(['endpoint' => 'http://127.0.0.1:9', 'region' => 'us-east-1', 'bucket' => 'b',
                      'access_key_id' => 'k', 'secret' => 's', 'prefix' => '', 'path_style' => true], 'files');
$t0 = microtime(true);
$threw = null;
try { $dead->size('x.bin'); } catch (Throwable $e) { $threw = $e; }
$secs = microtime(true) - $t0;
ok('unreachable endpoint fails fast', $threw instanceof StorageUnavailable && $secs < 7,
   ($threw ? get_class($threw) : 'nothing') . sprintf(' in %.1fs', $secs));

// A host that never answers (packets dropped, not refused) - the provider-outage case.
// One connect timeout (5 s), not one per retry.
$hole = new S3Driver(['endpoint' => 'http://10.255.255.1', 'region' => 'us-east-1', 'bucket' => 'b',
                      'access_key_id' => 'k', 'secret' => 's', 'prefix' => '', 'path_style' => true], 'files');
$t0 = microtime(true);
$threw = null;
try { $hole->size('x.bin'); } catch (Throwable $e) { $threw = $e; }
$secs = microtime(true) - $t0;
ok('silent endpoint fails in one connect timeout', $threw instanceof StorageUnavailable && $secs < 7,
   ($threw ? get_class($threw) : 'nothing') . sprintf(' in %.1fs', $secs));

// A server that accepts the connection and then never answers (a hung store).
// The listening socket is never accept()ed: the kernel completes the handshake,
// nothing ever replies. Every interactive request must give up at the first-byte
// limit (2 s here), not after the 60 s no-progress rule and its retries.
$hung = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
$hungPort = (int)substr(strrchr(stream_socket_get_name($hung, false), ':'), 1);
$h = new S3Driver(['endpoint' => "http://127.0.0.1:$hungPort", 'region' => 'us-east-1', 'bucket' => 'b',
                   'access_key_id' => 'k', 'secret' => 's', 'prefix' => '', 'path_style' => true], 'files', 64 * 1048576, 2);
$small = tempnam(sys_get_temp_dir(), 'zz');
file_put_contents($small, 'x');
foreach (['size' => fn() => $h->size('x.bin'),
          'readRange' => fn() => $h->readRange('x.bin', 0, 1, fn($c) => true),
          'putFile' => fn() => $h->putFile('x.bin', $small)] as $what => $fn) {
    $t0 = microtime(true);
    $threw = null;
    try { $fn(); } catch (Throwable $e) { $threw = $e; }
    $secs = microtime(true) - $t0;
    ok("hung endpoint: $what gives up at the first-byte limit", $threw instanceof StorageUnavailable && $secs < 5,
       ($threw ? get_class($threw) : 'nothing') . sprintf(' in %.1fs', $secs));
}
@unlink($small);
fclose($hung);

// A server that ignores Range and answers 200 with the whole object: the bytes handed
// on must still be exactly the range asked for (Content-Length was promised for it).
$probe = stream_socket_server('tcp://127.0.0.1:0');
$fakePort = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$serverCode = '$s = stream_socket_server("tcp://127.0.0.1:' . $fakePort . '"); $c = stream_socket_accept($s, 10);'
            . ' while (($l = fgets($c)) !== false && trim($l) !== "") {}'
            . ' fwrite($c, "HTTP/1.1 200 OK\r\nContent-Length: 16\r\nConnection: close\r\n\r\n0123456789abcdef"); fclose($c);';
$proc = proc_open([PHP_BINARY, '-r', $serverCode], [], $pipes);
usleep(700000);   // let it start listening (it answers one request, then exits)
$fake = new S3Driver(['endpoint' => "http://127.0.0.1:$fakePort", 'region' => 'us-east-1', 'bucket' => 'b',
                      'access_key_id' => 'k', 'secret' => 's', 'prefix' => '', 'path_style' => true], 'files');
$got = '';
try { $fake->readRange('x.bin', 5, 9, function (string $c) use (&$got) { $got .= $c; return true; }); } catch (Throwable $e) { $got = get_class($e) . ': ' . $e->getMessage(); }
ok('a store that ignores Range still yields exactly bytes 5..9', $got === '56789', $got);
proc_terminate($proc); proc_close($proc);

$ep = getenv('FREEITSM_TEST_S3_ENDPOINT') ?: '';
if ($ep === '') {
    echo "  SKIP (no S3 test endpoint)\n\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}

$cfg = ['endpoint' => $ep, 'region' => getenv('FREEITSM_TEST_S3_REGION') ?: 'us-east-1',
        'bucket' => getenv('FREEITSM_TEST_S3_BUCKET'), 'access_key_id' => getenv('FREEITSM_TEST_S3_KEY'),
        'secret' => getenv('FREEITSM_TEST_S3_SECRET'), 'prefix' => 'zz-' . bin2hex(random_bytes(4)), 'path_style' => true];
$d = new S3Driver($cfg, 'files');
$tmp = sys_get_temp_dir() . '/zz-s3-' . bin2hex(random_bytes(4));
$made = [];
try {
    $key = '2026/11/08/' . str_repeat('a', 32) . '.bin';
    file_put_contents($tmp, '0123456789abcdef');
    $d->putFile($key, $tmp);
    $made[] = $key;
    ok('put then size', $d->size($key) === 16, var_export($d->size($key), true));

    $got = '';
    $d->readRange($key, 5, 9, function (string $c) use (&$got) { $got .= $c; return true; });
    ok('readRange returns exactly bytes 5..9', $got === '56789', $got);

    $d->readTo($key, $tmp . '.out');
    ok('readTo copies bytes', @file_get_contents($tmp . '.out') === '0123456789abcdef');
    @unlink($tmp . '.out');
    if (is_writable('/dev/full')) {
        $threw = null;
        try { $d->readTo($key, '/dev/full'); } catch (Throwable $e) { $threw = $e; }
        ok('readTo into a full disk throws', $threw instanceof StorageUnavailable, $threw ? get_class($threw) : 'reported success');
        ok('control: the failed target is not deleted', file_exists('/dev/full'));
    }

    $threw = null;
    try { $d->readRange('2026/11/08/' . str_repeat('c', 32) . '.bin', 0, 1, fn($c) => true); } catch (Throwable $e) { $threw = $e; }
    ok('missing key throws StorageNotFound', $threw instanceof StorageNotFound, $threw ? get_class($threw) . ': ' . $threw->getMessage() : 'nothing');
    ok('missing key size is null', $d->size('2026/11/08/' . str_repeat('c', 32) . '.bin') === null);

    $d->delete($key);
    ok('delete then size is null', $d->size($key) === null);

    // Multipart: a 12 MiB file with a 5 MiB threshold (5 MiB is S3's smallest part).
    $big = '2026/11/08/' . str_repeat('b', 32) . '.bin';
    $fh = fopen($tmp, 'wb');
    for ($i = 0; $i < 12; $i++) fwrite($fh, random_bytes(1024 * 1024));
    fclose($fh);
    $want = hash_file('sha256', $tmp);
    $mp = new S3Driver($cfg, 'files', 5 * 1024 * 1024);
    $mp->putFile($big, $tmp, true);
    $made[] = $big;
    ok('move consumes the source', !is_file($tmp));
    $mp->readTo($big, $tmp . '.big');
    ok('multipart upload round-trips', @hash_file('sha256', $tmp . '.big') === $want && $mp->size($big) === 12 * 1024 * 1024);
    @unlink($tmp . '.big');

    // listKeys, including pagination (page size forced to 2)
    file_put_contents($tmp, 'x');
    $lk = ['zzlist/1.txt', 'zzlist/2.txt', 'zzlist/3.txt'];
    foreach ($lk as $k) { $d->putFile($k, $tmp); $made[] = $k; }
    ok('listKeys returns the keys under a prefix', $d->listKeys('zzlist/') === $lk, json_encode($d->listKeys('zzlist/')));
    $paged = new S3Driver($cfg, 'files');
    $paged->listPageSize = 2;
    ok('listKeys follows continuation pages', $paged->listKeys('zzlist/') === $lk, json_encode($paged->listKeys('zzlist/')));
    ok('listKeys of an unused prefix is empty', $d->listKeys('zz-none/') === []);

    $bad = new S3Driver(['secret' => 'wrong-' . $cfg['secret']] + $cfg, 'files');
    $threw = null;
    try { $bad->size($big); } catch (Throwable $e) { $threw = $e; }
    ok('wrong secret is StorageUnavailable, not NotFound', $threw instanceof StorageUnavailable, $threw ? get_class($threw) : 'nothing (size returned)');

    ok('control: the label never contains the secret', !str_contains($d->label(), (string)$cfg['secret']) && str_contains($d->label(), $cfg['bucket']), $d->label());
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    foreach ($made as $k) { try { $d->delete($k); } catch (Throwable $ignored) {} }
    @unlink($tmp);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
