<?php
/**
 * vCenter connection test (GH #445) - every step between FreeITSM and a vCenter
 * Server, each with what was sent and what came back.
 *
 * Asset Management -> Settings -> vCenter -> Test calls this through
 * api/assets/test_vcenter.php. Before it, a wrong address, a self-signed
 * certificate, an older vCenter and a wrong password all looked the same from the
 * Servers page: the sync failed, or found nothing, and said little about why.
 *
 * Every check has the shape the Slack health check uses (api/messaging/slack_diagnose.php):
 *   ['key', 'label', 'status' => ok|warn|fail|skip, 'detail', 'fix', 'exchanges' => [...]]
 * `fix` says what to DO. `exchanges` is each HTTP request made for the check -
 * method, URL, status, time, headers and the start of the body - so the person can
 * see the response for themselves, or paste it into an issue.
 *
 * READ-ONLY on vCenter: it signs in, reads lists and versions, and signs out.
 * Writes nothing to the database.
 *
 * TRAP: secrets never reach an exchange. The Authorization header, the session
 * id vCenter hands back and the vmware-api-session-id header are replaced with
 * "(hidden)" before an exchange is stored - see vcDiagRedact(). The page shows
 * exchanges to whoever runs the test, and people paste them into GitHub issues.
 */

require_once __DIR__ . '/ssl.php';

/** How much of each response body the test keeps for the page. */
const VCDIAG_BODY_LIMIT = 4000;

/**
 * Turns what was typed in the vCenter server box into the https base URL the
 * sync and the test call, or null when it is not a usable address.
 *
 * TRAP: people paste the address from their browser ("https://vc.example.com/ui/").
 * The sync used to put "https://" in front of whatever was saved, so that became
 * "https://https://vc.example.com/ui/" and failed with a DNS error naming a host
 * called "https". Scheme, path, query and trailing slash are dropped here; a port
 * is kept.
 *
 * @param string $server  the saved or typed vCenter server, with or without scheme
 * @return array{host:string,port:int,base:string,changed:bool}|null
 */
function vcenterNormaliseServer(string $server): ?array
{
    $raw = trim($server);
    if ($raw === '') return null;
    $withScheme = preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw) ? $raw : 'https://' . $raw;
    $parts = parse_url($withScheme);
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '' || !preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)$/i', $host)) return null;
    $port = isset($parts['port']) ? (int)$parts['port'] : 443;
    $base = 'https://' . $host . ($port !== 443 ? ':' . $port : '');
    $changed = strcasecmp($raw, $host . ($port !== 443 ? ':' . $port : '')) !== 0;
    return ['host' => $host, 'port' => $port, 'base' => $base, 'changed' => $changed];
}

/**
 * The https base URL for a saved vCenter server ("https://host[:port]"), or the
 * old "https://<as saved>" when it cannot be read, so a sync still fails the way
 * it used to rather than silently doing nothing.
 *
 * @param string $server  the vcenter_server setting, decrypted
 */
function vcenterBaseUrl(string $server): string
{
    $n = vcenterNormaliseServer($server);
    return $n ? $n['base'] : 'https://' . trim($server);
}

/**
 * Hides secrets in one exchange's headers and body. See the TRAP at the top.
 *
 * @param string $text  a header block or response body
 * @param string[] $secrets  exact strings to hide wherever they appear (session ids, the password)
 */
function vcDiagRedact(string $text, array $secrets): string
{
    $text = preg_replace('/^(authorization|vmware-api-session-id|set-cookie)\s*:.*$/im', '$1: (hidden)', $text);
    foreach ($secrets as $s) {
        if (is_string($s) && strlen($s) >= 4) $text = str_replace($s, '(hidden)', $text);
    }
    return $text;
}

/**
 * Makes one HTTP request to vCenter and keeps everything the page needs to show it.
 *
 * @param string $method  GET, POST or DELETE
 * @param string $url  the full https URL
 * @param array $headers  request headers as "Name: value" lines
 * @param string|null $basicAuth  "user:password" for HTTP Basic, or null
 * @param bool $verify  false only for reading a certificate the normal request refused
 * @return array{status:int,errno:int,error:string,body:string,json:mixed,time_ms:int,exchange:array,cert:?array}
 */
function vcDiagRequest(string $method, string $url, array $headers = [], ?string $basicAuth = null, bool $verify = true): array
{
    $ch = curl_init($url);
    $respHeaders = '';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 30,
        // A vCenter that redirects an API call is a proxy or a login page; follow
        // nothing, show the redirect.
        CURLOPT_FOLLOWLOCATION => false,
        CURLINFO_HEADER_OUT    => true,
        CURLOPT_CERTINFO       => true,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) { $respHeaders .= $line; return strlen($line); },
    ]);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    if ($basicAuth !== null) curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
    if ($verify) {
        sslApplyCurl($ch);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
    $t0 = microtime(true);
    $body = curl_exec($ch);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $sent = (string)curl_getinfo($ch, CURLINFO_HEADER_OUT);
    $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
    curl_close($ch);
    $body = is_string($body) ? $body : '';

    $cert = null;
    if (is_array($certInfo) && !empty($certInfo[0])) {
        $c0 = $certInfo[0];
        $cert = [
            'subject' => (string)($c0['Subject'] ?? ''),
            'issuer'  => (string)($c0['Issuer'] ?? ''),
            'expires' => (string)($c0['Expire date'] ?? ''),
        ];
    }

    $exchange = [
        'method'           => $method,
        'url'              => $url,
        'status'           => $status,
        'time_ms'          => $ms,
        'error'            => $errno ? ('cURL ' . $errno . ': ' . $error) : '',
        'request_headers'  => trim($sent),
        'response_headers' => trim($respHeaders),
        'body'             => mb_strlen($body) > VCDIAG_BODY_LIMIT ? mb_substr($body, 0, VCDIAG_BODY_LIMIT) . "\n... (" . strlen($body) . ' bytes in all)' : $body,
    ];
    return [
        'status' => $status, 'errno' => $errno, 'error' => $error, 'body' => $body,
        'json' => json_decode($body, true), 'time_ms' => $ms, 'exchange' => $exchange, 'cert' => $cert,
    ];
}

/**
 * Runs the whole test and returns ['overall' => ok|warn|fail, 'checks' => [...], 'summary' => [...]].
 *
 * Stops at the first step nothing after it can get past (no address, no DNS, no
 * connection, no sign-in) and marks the rest "skip", so the first red line is the
 * one to fix.
 *
 * @param string $server  the vCenter server as typed (with or without https://)
 * @param string $user  the vCenter user, e.g. administrator@vsphere.local
 * @param string $pass  that user's password
 */
function vcenterDiagnose(string $server, string $user, string $pass): array
{
    $checks = [];
    $secrets = [$pass];
    $add = function (string $key, string $status, string $detail, string $fix = '', array $exchanges = []) use (&$checks, &$secrets) {
        foreach ($exchanges as &$x) {
            $x['request_headers']  = vcDiagRedact($x['request_headers'], $secrets);
            $x['response_headers'] = vcDiagRedact($x['response_headers'], $secrets);
            $x['body']             = vcDiagRedact($x['body'], $secrets);
        }
        unset($x);
        $checks[] = [
            'key' => $key, 'label' => t('asset-management.vcenter_test.check_' . $key),
            'status' => $status, 'detail' => $detail, 'fix' => $fix, 'exchanges' => $exchanges,
        ];
    };
    $skipRest = function (array $keys) use ($add) {
        foreach ($keys as $k) $add($k, 'skip', t('asset-management.vcenter_test.skipped'));
    };
    $all = ['address', 'dns', 'connect', 'certificate', 'api', 'signin', 'version', 'inventory', 'signout'];
    $summary = null;

    // 1. The address
    $n = vcenterNormaliseServer($server);
    if (!$n) {
        $add('address', 'fail', t('asset-management.vcenter_test.address_bad', ['value' => $server]), t('asset-management.vcenter_test.address_bad_fix'));
        $skipRest(array_slice($all, 1));
        return vcDiagResult($checks, $summary);
    }
    $base = $n['base'];
    $add('address', $n['changed'] ? 'warn' : 'ok',
        t('asset-management.vcenter_test.address_ok', ['url' => $base]),
        $n['changed'] ? t('asset-management.vcenter_test.address_changed_fix', ['host' => $n['host'] . ($n['port'] !== 443 ? ':' . $n['port'] : '')]) : '');

    // 2. DNS
    $host = trim($n['host'], '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $add('dns', 'ok', t('asset-management.vcenter_test.dns_ip'));
        $ip = $host;
    } else {
        $ips = @gethostbynamel($host) ?: [];
        if (!$ips) {
            $add('dns', 'fail', t('asset-management.vcenter_test.dns_fail', ['host' => $host]), t('asset-management.vcenter_test.dns_fail_fix'));
            $skipRest(array_slice($all, 2));
            return vcDiagResult($checks, $summary);
        }
        $ip = $ips[0];
        $add('dns', 'ok', t('asset-management.vcenter_test.dns_ok', ['host' => $host, 'ips' => implode(', ', $ips)]));
    }

    // 3. TCP connect
    $t0 = microtime(true);
    $errno = 0; $errstr = '';
    $sock = @fsockopen((strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip), $n['port'], $errno, $errstr, 6);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if (!$sock) {
        $add('connect', 'fail', t('asset-management.vcenter_test.connect_fail', ['ip' => $ip, 'port' => $n['port'], 'error' => trim($errstr) ?: ('error ' . $errno)]),
            t('asset-management.vcenter_test.connect_fail_fix', ['port' => $n['port']]));
        $skipRest(array_slice($all, 3));
        return vcDiagResult($checks, $summary);
    }
    fclose($sock);
    $add('connect', 'ok', t('asset-management.vcenter_test.connect_ok', ['ip' => $ip, 'port' => $n['port'], 'ms' => $ms]));

    // 4. Certificate - the first real HTTPS request. Unauthenticated, to the
    //    new API's session URL: vCenter answers 401 there, which also tells us
    //    the API exists (step 5 reads it).
    $probe = vcDiagRequest('POST', $base . '/api/session');
    if ($probe['errno'] !== 0) {
        $isCert = in_array($probe['errno'], [51, 58, 60, 77, 83, 90, 91], true) || stripos($probe['error'], 'certificate') !== false || stripos($probe['error'], 'SSL') !== false;
        if ($isCert) {
            // Read the certificate without verifying, only to say whose it is. No
            // credentials go on this request.
            $peek = vcDiagRequest('POST', $base . '/api/session', [], null, false);
            $c = $peek['cert'];
            $who = $c ? t('asset-management.vcenter_test.cert_who', ['subject' => $c['subject'], 'issuer' => $c['issuer'], 'expires' => $c['expires']]) : '';
            $bundle = defined('SSL_CA_BUNDLE') ? (string)SSL_CA_BUNDLE : sslResolveCaBundle();
            $add('certificate', 'fail', trim(t('asset-management.vcenter_test.cert_fail', ['error' => $probe['error']]) . ' ' . $who),
                t('asset-management.vcenter_test.cert_fail_fix', ['url' => $base . '/certs/download.zip', 'bundle' => $bundle !== '' ? $bundle : t('asset-management.vcenter_test.cert_system_store')]),
                [$probe['exchange']]);
        } else {
            $add('certificate', 'fail', t('asset-management.vcenter_test.https_fail', ['error' => $probe['error']]), t('asset-management.vcenter_test.https_fail_fix'), [$probe['exchange']]);
        }
        $skipRest(array_slice($all, 4));
        return vcDiagResult($checks, $summary);
    }
    $verifying = defined('SSL_VERIFY_PEER') ? (bool)SSL_VERIFY_PEER : true;
    $c = $probe['cert'];
    $add('certificate', $verifying ? 'ok' : 'warn',
        trim(($verifying ? t('asset-management.vcenter_test.cert_ok') : t('asset-management.vcenter_test.cert_not_verified')) . ' '
            . ($c ? t('asset-management.vcenter_test.cert_who', ['subject' => $c['subject'], 'issuer' => $c['issuer'], 'expires' => $c['expires']]) : '')),
        $verifying ? '' : t('asset-management.vcenter_test.cert_not_verified_fix'));

    // 5. Which API. /api arrived in vSphere 7.0 Update 2; before that only the
    //    older /rest API exists. The sync uses /api.
    $flavour = null;
    $apiExchanges = [$probe['exchange']];
    if (in_array($probe['status'], [401, 403], true)) {
        $flavour = 'api';
    } else {
        $old = vcDiagRequest('POST', $base . '/rest/com/vmware/cis/session');
        $apiExchanges[] = $old['exchange'];
        if (in_array($old['status'], [401, 403], true)) $flavour = 'rest';
    }
    if ($flavour === 'api') {
        $add('api', 'ok', t('asset-management.vcenter_test.api_new'), '', $apiExchanges);
    } elseif ($flavour === 'rest') {
        $add('api', 'fail', t('asset-management.vcenter_test.api_old'), t('asset-management.vcenter_test.api_old_fix'), $apiExchanges);
    } else {
        $add('api', 'fail', t('asset-management.vcenter_test.api_none', ['status' => $probe['status']]), t('asset-management.vcenter_test.api_none_fix'), $apiExchanges);
        $skipRest(array_slice($all, 5));
        return vcDiagResult($checks, $summary);
    }

    // 6. Sign in
    $sessionPath = $flavour === 'api' ? '/api/session' : '/rest/com/vmware/cis/session';
    $login = vcDiagRequest('POST', $base . $sessionPath, ['Content-Type: application/json'], $user . ':' . $pass);
    $token = null;
    if (in_array($login['status'], [200, 201], true)) {
        $j = $login['json'];
        $token = is_string($j) ? $j : (is_array($j) && is_string($j['value'] ?? null) ? $j['value'] : null);
    }
    if ($token === null) {
        $secrets[] = (string)$login['body'];
        if ($login['status'] === 401) {
            $add('signin', 'fail', t('asset-management.vcenter_test.signin_401', ['user' => $user]), t('asset-management.vcenter_test.signin_401_fix'), [$login['exchange']]);
        } elseif ($login['status'] === 503) {
            $add('signin', 'fail', t('asset-management.vcenter_test.signin_503'), t('asset-management.vcenter_test.signin_503_fix'), [$login['exchange']]);
        } else {
            $add('signin', 'fail', t('asset-management.vcenter_test.signin_other', ['status' => $login['status'] ?: $login['error']]), t('asset-management.vcenter_test.signin_other_fix'), [$login['exchange']]);
        }
        $skipRest(array_slice($all, 6));
        return vcDiagResult($checks, $summary);
    }
    $secrets[] = $token;
    $add('signin', 'ok', t('asset-management.vcenter_test.signin_ok', ['user' => $user]), '', [$login['exchange']]);
    $auth = ['vmware-api-session-id: ' . $token];
    $p = $flavour === 'api' ? '/api' : '/rest';
    $unwrap = function ($j) { return (is_array($j) && array_key_exists('value', $j) && count($j) === 1) ? $j['value'] : $j; };

    // 7. Version - needs a user allowed to read the appliance; a warning only.
    $ver = vcDiagRequest('GET', $base . $p . '/appliance/system/version', $auth);
    $v = $unwrap($ver['json']);
    if ($ver['status'] === 200 && is_array($v)) {
        $add('version', 'ok', t('asset-management.vcenter_test.version_ok', ['product' => (string)($v['product'] ?? 'vCenter Server'), 'version' => (string)($v['version'] ?? '?'), 'build' => (string)($v['build'] ?? '?')]), '', [$ver['exchange']]);
    } else {
        $add('version', 'warn', t('asset-management.vcenter_test.version_unknown', ['status' => $ver['status'] ?: $ver['error']]), t('asset-management.vcenter_test.version_unknown_fix'), [$ver['exchange']]);
    }

    // 8. Inventory - the same lists the sync reads, counted.
    $lists = [];
    $invEx = [];
    $failed = [];
    foreach (['host' => 'host', 'cluster' => 'cluster', 'datastore' => 'datastore', 'vm' => 'vm'] as $k => $path) {
        $r = vcDiagRequest('GET', $base . $p . '/vcenter/' . $path, $auth);
        $invEx[] = $r['exchange'];
        $val = $unwrap($r['json']);
        if ($r['status'] === 200 && is_array($val)) {
            $lists[$k] = $val;
        } else {
            $failed[$k] = $r['status'] ?: $r['error'];
        }
    }
    if ($lists) {
        $vms = $lists['vm'] ?? [];
        $on = 0; $cpu = 0; $memMiB = 0;
        foreach ($vms as $vm) {
            if (($vm['power_state'] ?? '') === 'POWERED_ON') $on++;
            $cpu += (int)($vm['cpu_count'] ?? 0);
            $memMiB += (int)($vm['memory_size_MiB'] ?? 0);
        }
        $dsCap = 0; $dsFree = 0;
        foreach ($lists['datastore'] ?? [] as $ds) {
            $dsCap += (float)($ds['capacity'] ?? 0);
            $dsFree += (float)($ds['free_space'] ?? 0);
        }
        $summary = [
            'hosts' => isset($lists['host']) ? count($lists['host']) : null,
            'clusters' => isset($lists['cluster']) ? count($lists['cluster']) : null,
            'datastores' => isset($lists['datastore']) ? count($lists['datastore']) : null,
            'vms' => isset($lists['vm']) ? count($vms) : null,
            'vms_on' => $on, 'vcpus' => $cpu, 'memory_gb' => round($memMiB / 1024, 1),
            'storage_tb' => round($dsCap / 1099511627776, 2), 'storage_free_tb' => round($dsFree / 1099511627776, 2),
        ];
    }
    if (isset($failed['vm']) && (string)$failed['vm'] === '400') {
        // The VM list refuses to return more than 4,000 VMs in one call; the sync
        // reads per host and per cluster, so this is not an error for it.
        $add('inventory', 'warn', t('asset-management.vcenter_test.inventory_too_many'), '', $invEx);
    } elseif ($failed) {
        $parts = [];
        foreach ($failed as $k => $s) $parts[] = $k . ' (' . $s . ')';
        $add('inventory', 'fail', t('asset-management.vcenter_test.inventory_fail', ['lists' => implode(', ', $parts)]), t('asset-management.vcenter_test.inventory_fail_fix'), $invEx);
    } elseif (($summary['vms'] ?? 0) === 0) {
        $add('inventory', 'warn', t('asset-management.vcenter_test.inventory_empty'), t('asset-management.vcenter_test.inventory_empty_fix'), $invEx);
    } else {
        $add('inventory', 'ok', t('asset-management.vcenter_test.inventory_ok', [
            'vms' => $summary['vms'], 'on' => $summary['vms_on'], 'hosts' => $summary['hosts'] ?? 0,
            'clusters' => $summary['clusters'] ?? 0, 'datastores' => $summary['datastores'] ?? 0,
        ]), '', $invEx);
    }

    // 9. Sign out, so the test leaves no session behind on vCenter.
    $out = vcDiagRequest('DELETE', $base . $sessionPath, $auth);
    $add('signout', in_array($out['status'], [200, 204], true) ? 'ok' : 'warn',
        in_array($out['status'], [200, 204], true) ? t('asset-management.vcenter_test.signout_ok') : t('asset-management.vcenter_test.signout_warn', ['status' => $out['status'] ?: $out['error']]),
        '', [$out['exchange']]);

    return vcDiagResult($checks, $summary);
}

/**
 * The overall result from the checks: fail if any failed, warn if any warned.
 *
 * @param array $checks  the checks built by vcenterDiagnose()
 * @param array|null $summary  the inventory totals, when sign-in got that far
 */
function vcDiagResult(array $checks, ?array $summary): array
{
    $overall = 'ok';
    foreach ($checks as $c) {
        if ($c['status'] === 'fail') { $overall = 'fail'; break; }
        if ($c['status'] === 'warn') $overall = 'warn';
    }
    return ['overall' => $overall, 'checks' => $checks, 'summary' => $summary];
}
