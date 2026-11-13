<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * S3 request signing (AWS Signature V4) and object addressing - the pure part
 * of the S3-compatible storage driver, with no network and no database.
 *
 * Written because FreeITSM ships no AWS SDK: the signer is our own, and a
 * signer that is wrong in one byte fails with nothing more than "403
 * SignatureDoesNotMatch" from the provider. The AWS test-suite vector pins the
 * algorithm; the rest pins how loosely typed settings (a prefix with slashes,
 * an endpoint with a trailing one, a bucket with dots) become a URL.
 *
 * Run: php tests/storage-s3-signing.php
 */

require_once dirname(__DIR__) . '/includes/storage/s3_sign.php';

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}

echo "\nS3 signing and addressing\n" . str_repeat('=', 72) . "\n";

$emptyHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
$now  = gmmktime(12, 36, 0, 8, 30, 2015);
$akid = 'AKIDEXAMPLE';
$sec  = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
$vector = '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31';

// The published get-vanilla case of the AWS SigV4 test suite.
$h = s3SignRequest('GET', 'https://example.amazonaws.com/', ['host' => 'example.amazonaws.com'], $emptyHash, $akid, $sec, 'us-east-1', 'service', $now);
ok('AWS get-vanilla test vector', str_ends_with($h['authorization'] ?? '', 'Signature=' . $vector), $h['authorization'] ?? '(none)');
ok('x-amz-date is the request time', ($h['x-amz-date'] ?? '') === '20150830T123600Z', $h['x-amz-date'] ?? '');

// Control: a different secret must not reproduce the vector.
$hx = s3SignRequest('GET', 'https://example.amazonaws.com/', ['host' => 'example.amazonaws.com'], $emptyHash, $akid, 'x', 'us-east-1', 'service', $now);
ok('control: another secret gives another signature', !str_ends_with($hx['authorization'] ?? '', $vector));

// S3 requests carry the payload hash as a signed header.
$hs = s3SignRequest('PUT', 'http://s3:9000/b/k', ['host' => 's3:9000'], $emptyHash, $akid, $sec, 'us-east-1', 's3', $now);
ok('s3 requests sign x-amz-content-sha256', ($hs['x-amz-content-sha256'] ?? '') === $emptyHash
    && str_contains($hs['authorization'], 'SignedHeaders=host;x-amz-content-sha256;x-amz-date'), $hs['authorization'] ?? '');

$a = s3SignRequest('GET', 'https://h/b?b=2&a=1', ['host' => 'h'], $emptyHash, $akid, $sec, 'us-east-1', 's3', $now);
$b = s3SignRequest('GET', 'https://h/b?a=1&b=2', ['host' => 'h'], $emptyHash, $akid, $sec, 'us-east-1', 's3', $now);
ok('query string is canonicalised', $a['authorization'] === $b['authorization']);

ok('prefix and endpoint are normalised: bare prefix', s3NormalisePrefix('freeitsm') === 'freeitsm/');
ok('prefix and endpoint are normalised: slashes', s3NormalisePrefix('/freeitsm/') === 'freeitsm/');
ok('prefix and endpoint are normalised: nested', s3NormalisePrefix('a//b/') === 'a/b/', s3NormalisePrefix('a//b/'));
ok('prefix and endpoint are normalised: empty', s3NormalisePrefix('') === '' && s3NormalisePrefix('/') === '');
ok('prefix and endpoint are normalised: endpoint slash', s3NormaliseEndpoint('http://minio:9000/') === 'http://minio:9000');
// curl leaves a default port out of the Host header it sends, so a signed ":443" never
// matches; an upper-case scheme or host would dodge the dotted-bucket rule.
ok('endpoint: scheme and host lower-cased, default port dropped', s3NormaliseEndpoint('HTTPS://S3.Example.COM:443/') === 'https://s3.example.com', s3NormaliseEndpoint('HTTPS://S3.Example.COM:443/'));
ok('endpoint: :80 dropped on http', s3NormaliseEndpoint('http://minio:80') === 'http://minio');
ok('endpoint: other ports kept', s3NormaliseEndpoint('http://minio:9000') === 'http://minio:9000');
$u = s3ObjectUrl(['endpoint' => 'HTTPS://s3.example.com', 'bucket' => 'my.bucket', 'path_style' => false], 'k');
ok('upper-case scheme still forces path style for a dotted bucket', $u === 'https://s3.example.com/my.bucket/k', $u);
$threw = false;
try { s3NormaliseEndpoint('minio:9000'); } catch (InvalidArgumentException $e) { $threw = true; }
ok('prefix and endpoint are normalised: scheme required', $threw);

$u = s3ObjectUrl(['endpoint' => 'https://s3.example.com', 'bucket' => 'my.bucket', 'path_style' => false], 'files/2026/01/02/ab.bin');
ok('dotted bucket forces path style over https', $u === 'https://s3.example.com/my.bucket/files/2026/01/02/ab.bin', $u);
$u = s3ObjectUrl(['endpoint' => 'https://s3.example.com', 'bucket' => 'b', 'path_style' => false], 'files/x.bin');
ok('virtual-host style puts the bucket in the host', $u === 'https://b.s3.example.com/files/x.bin', $u);
$u = s3ObjectUrl(['endpoint' => 'http://minio:9000/', 'bucket' => 'b', 'path_style' => true], 'files/x.bin', ['uploads' => '']);
ok('path style keeps the bucket in the path, with query', $u === 'http://minio:9000/b/files/x.bin?uploads=', $u);
$u = s3ObjectUrl(['endpoint' => 'http://minio:9000', 'bucket' => 'b', 'path_style' => true], 'a b/c+d');
ok('key segments are encoded', $u === 'http://minio:9000/b/a%20b/c%2Bd', $u);

// ── Addresses (fix made when merging PR #318): every request checks the host it really
// goes to - IPv4 and IPv6, the bucket's own host name too - and pins what it checked.
echo "\nStorage addresses\n";
require_once dirname(__DIR__) . '/includes/storage/driver.php';
require_once dirname(__DIR__) . '/includes/storage/s3_driver.php';
foreach (['169.254.169.254', '169.254.0.1', '0.0.0.0', 'fe80::1', 'fd00:ec2::254', '::ffff:169.254.169.254', '::'] as $ip) {
    ok("refused: $ip", storageAddressRefusal($ip) !== null);
}
foreach (['127.0.0.1', '10.1.2.3', '192.168.1.5', '::1', '52.95.110.1'] as $ip) {
    ok("allowed: $ip (a MinIO on this box or the LAN, or a real cloud address)", storageAddressRefusal($ip) === null);
}
$why = '';
try { storageCheckedAddress('169.254.169.254'); } catch (StorageUnavailable $e) { $why = $e->getMessage(); }
ok('a metadata address is refused before any connection is made', str_contains($why, 'not allowed'), $why);
// The request itself refuses it - path style, so the host is the endpoint's; it never connects.
$d = new S3Driver(['endpoint' => 'http://169.254.169.254', 'bucket' => 'b', 'access_key_id' => 'k', 'secret' => 's', 'path_style' => true], 'files');
$why = '';
try { $d->size('2026/01/01/' . str_repeat('a', 32) . '.bin'); } catch (StorageUnavailable $e) { $why = $e->getMessage(); }
ok('an S3 request to a metadata address is refused by the driver itself', str_contains($why, 'not allowed'), $why);
// Virtual-hosted: the bucket becomes part of the host, and that is what gets checked.
$d = new S3Driver(['endpoint' => 'http://localhost', 'bucket' => 'zz-no-such-host-' . bin2hex(random_bytes(3)), 'access_key_id' => 'k', 'secret' => 's', 'path_style' => false], 'files');
$why = '';
try { $d->size('2026/01/01/' . str_repeat('a', 32) . '.bin'); } catch (StorageUnavailable $e) { $why = $e->getMessage(); }
ok('a virtual-hosted request checks <bucket>.<host>, not only the endpoint', str_contains($why, '.localhost') && str_contains($why, 'does not resolve'), $why);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
