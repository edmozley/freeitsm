<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * System -> Docker -> Behind a reverse proxy (GH #410): the verdicts the Test button
 * gives, and the warnings about the proxy list. Pure functions - no database, no Docker.
 *
 *   php tests/docker-proxy-check.php
 *
 * Control: make dockerProxyCheck() always answer 'working' and the "still the proxy",
 * "off behind a proxy" and range checks fail; drop the /24 rule in dockerProxyListFacts()
 * and the wide-range checks fail.
 */
require_once __DIR__ . '/../includes/docker_proxy.php';

$pass = 0; $fail = 0;
/** Records and prints one PASS or FAIL line. */
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
/** A state as dockerProxyState() would build it. */
function st(string $state, array $list = [], string $header = 'X-Forwarded-For'): array
{
    return ['state' => $state, 'header' => $header] + dockerProxyListFacts($list);
}

echo "1. Off\n";
$r = dockerProxyCheck(st('off'), ['REMOTE_ADDR' => '192.168.1.20']);
check('no proxy header: direct, nothing to set', $r['verdict'] === 'direct' && $r['suggest'] === null, $r['verdict']);
$r = dockerProxyCheck(st('off'), ['REMOTE_ADDR' => '172.18.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7']);
check('a proxy header arrived: everyone is recorded as the proxy', $r['verdict'] === 'off_proxy', $r['verdict']);
check('  and it suggests the proxy\'s own address, alone', $r['suggest'] === '172.18.0.5', (string)$r['suggest']);
check('  and says which header arrived', array_keys($r['headers']) === ['X-Forwarded-For']);
$r = dockerProxyCheck(st('off'), ['REMOTE_ADDR' => '172.18.0.5', 'HTTP_CF_CONNECTING_IP' => '203.0.113.7']);
check('a Cloudflare header counts too', $r['verdict'] === 'off_proxy' && isset($r['headers']['CF-Connecting-IP']));

echo "\n2. On\n";
$on = st('on', ['172.18.0.5']);
$r = dockerProxyCheck($on, ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7']);
check('recorded address is not the proxy: working', $r['verdict'] === 'working', $r['verdict']);
$r = dockerProxyCheck($on, ['REMOTE_ADDR' => '172.18.0.5']);
check('recorded address IS the proxy: still the proxy (no usable header)', $r['verdict'] === 'still_proxy', $r['verdict']);
$r = dockerProxyCheck(st('on', ['172.18.0.0/28']), ['REMOTE_ADDR' => '172.18.0.9']);
check('  also when the proxy is listed as a small range', $r['verdict'] === 'still_proxy', $r['verdict']);
$r = dockerProxyCheck(st('on', ['2001:db8::10']), ['REMOTE_ADDR' => '2001:db8::10']);
check('  and for IPv6', $r['verdict'] === 'still_proxy', $r['verdict']);
$r = dockerProxyCheck(st('on', ['localhost']), ['REMOTE_ADDR' => '127.0.0.1']);
check('a host name is looked up and matched', $r['verdict'] === 'still_proxy', $r['verdict']);

echo "\n3. Set but refused\n";
$r = dockerProxyCheck(st('refused', ['not valid!']), ['REMOTE_ADDR' => '172.18.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7']);
check('refused: says so, suggests nothing', $r['verdict'] === 'refused' && $r['suggest'] === null, $r['verdict']);

echo "\n4. Warnings about the list\n";
$f = dockerProxyListFacts(['10.0.0.0/8', '172.18.0.5', '192.168.1.0/24', '2001:db8::/48', '2001:db8:1::/64', 'proxy']);
check('IPv4 wider than /24 is flagged', in_array('10.0.0.0/8', $f['wide'], true));
check('IPv4 /24 is not', !in_array('192.168.1.0/24', $f['wide'], true));
check('IPv6 wider than /64 is flagged', in_array('2001:db8::/48', $f['wide'], true));
check('IPv6 /64 is not', !in_array('2001:db8:1::/64', $f['wide'], true));
check('a single address is not', !in_array('172.18.0.5', $f['wide'], true));
check('a host name is noted', $f['names'] === ['proxy']);

echo "\n5. Headers are cut short\n";
$r = dockerProxyCheck(st('off'), ['REMOTE_ADDR' => '172.18.0.5', 'HTTP_X_FORWARDED_FOR' => str_repeat('1.2.3.4, ', 200)]);
check('a huge header is shown cut to 300 characters', strlen($r['headers']['X-Forwarded-For']) === 300);

echo "\n" . str_repeat('-', 70) . "\n  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
