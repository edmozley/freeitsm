<?php
/**
 * S3 storage tells "the store said no" apart from "the store is down" (PR #431 merge).
 *
 *   php tests/storage-s3-refused.php
 *
 * The recycle bin's purge queue counts only refusals: after 24 of them a key is
 * tried once a day instead of every hour. The S3 driver used to throw the same
 * StorageUnavailable for a 403 from a bucket policy as for a dead endpoint, so on
 * S3 nothing was ever counted and a refused key was retried hourly for ever - the
 * very case #421 was about. This runs S3Driver::delete() against a stand-in S3
 * (PHP's built-in server) that answers each way, and checks the exception class.
 *
 * Needs no database and no real bucket. Port 28461 on 127.0.0.1.
 *
 * The control: a credentials error (InvalidAccessKeyId, SignatureDoesNotMatch)
 * is a 403 too, and must stay StorageUnavailable - an outage of the whole store
 * that, once fixed, every key must leave the queue at once for.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/includes/storage/driver.php';
require_once $root . '/includes/storage/s3_driver.php';
if (!function_exists('sslApplyCurl')) require_once $root . '/includes/ssl.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $what) { global $pass, $fail; if ($c) { $pass++; echo "  ok   $what\n"; } else { $fail++; echo "  FAIL $what\n"; } }

$dir = sys_get_temp_dir() . '/zz_s3refused_' . bin2hex(random_bytes(4));
mkdir($dir);
// The object key picks the answer: .../<case>.bin
file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$case = pathinfo(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), PATHINFO_FILENAME);
$xml = fn($code, $msg) => '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . $code . '</Code><Message>' . $msg . '</Message></Error>';
header('Content-Type: application/xml');
switch ($case) {
    case 'policy':    http_response_code(403); echo $xml('AccessDenied', 'Access Denied'); break;
    case 'badkey':    http_response_code(403); echo $xml('InvalidAccessKeyId', 'The AWS Access Key Id you provided does not exist in our records.'); break;
    case 'badsig':    http_response_code(403); echo $xml('SignatureDoesNotMatch', 'The request signature we calculated does not match.'); break;
    case 'locked':    http_response_code(409); echo $xml('OperationAborted', 'Object is under legal hold'); break;
    case 'slowdown':  http_response_code(429); echo $xml('SlowDown', 'Please reduce your request rate.'); break;
    case 'down':      http_response_code(503); echo $xml('ServiceUnavailable', 'Reduce your request rate.'); break;
    case 'gone':      http_response_code(404); echo $xml('NoSuchKey', 'The specified key does not exist.'); break;
    default:          http_response_code(204);
}
PHP);
$port = 28461;
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $dir . '/router.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port, $e, $es, 0.1); $i++) usleep(100000);

try {
    $s3 = new S3Driver(['endpoint' => "http://127.0.0.1:$port", 'bucket' => 'zz-bucket', 'access_key_id' => 'ZZKEY', 'secret' => 'zzsecret', 'path_style' => true], 'files');
    $kind = function (string $case) use ($s3) {
        try { $s3->delete("zz/$case.bin"); return 'none'; }
        catch (StorageRefused $e) { return 'refused'; }
        catch (StorageUnavailable $e) { return 'unavailable'; }
        catch (Throwable $e) { return get_class($e); }
    };
    echo "\nRefusals - the store answered and said no\n";
    ok($kind('policy') === 'refused', '403 AccessDenied (a bucket policy) is StorageRefused');
    ok($kind('locked') === 'refused', '409 (a legal hold) is StorageRefused');
    ok((new StorageRefused('x')) instanceof StorageUnavailable, 'StorageRefused is still a StorageUnavailable, so every 503 path still catches it');

    echo "\nNot refusals\n";
    ok($kind('badkey') === 'unavailable', '403 InvalidAccessKeyId stays StorageUnavailable (the control)');
    ok($kind('badsig') === 'unavailable', '403 SignatureDoesNotMatch stays StorageUnavailable');
    ok($kind('slowdown') === 'unavailable', '429 SlowDown stays StorageUnavailable');
    ok($kind('down') === 'unavailable', '503 stays StorageUnavailable');
    ok($kind('gone') === 'none', '404 NoSuchKey on delete is "already gone", not an error');
    ok($kind('fine') === 'none', '204 deletes');

    echo "\nThe purge result built from it (files_versions.php)\n";
    $src = (string) file_get_contents($root . '/includes/services/files_versions.php');
    ok(strpos($src, '!($e instanceof StorageRefused)') !== false, 'filesPurgeBytesIfUnused() maps StorageRefused to failed, which the queue counts');
} finally {
    proc_terminate($proc); proc_close($proc);
    @unlink($dir . '/router.php'); @rmdir($dir);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
