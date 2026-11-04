<?php
/**
 * Domains - HTTPS endpoints on internal domains (GH #203, slice 2).
 *
 * Slice 1 made an internal domain DNS-only: it never connected to anything. This
 * slice adds the certificate check for the endpoints an administrator lists - a
 * host and a port, switched on per target, never assumed on the domain, its apex
 * or www. It is the first time FreeITSM connects to addresses somebody types in,
 * so every protection arrives with the first probe, as the review on the issue
 * asked (wiki Domains-Internal-Monitoring-Design, section 5):
 *
 *  - ADMINISTRATORS ONLY. Endpoints, trust profiles, networks and ports need
 *    Cap::DOMAINS_INTERNAL; so does probing them from Check now. Everybody who
 *    can see the domain sees the results.
 *  - THE HOST BELONGS TO THE DOMAIN. It is the domain or a name under it
 *    (domainHostBelongsTo), and it must be a name, never an address: the address
 *    comes from DNS.
 *  - THE RESOLVED ADDRESS IS PERMITTED, at every connection. The host is looked up
 *    through this server's resolver only (system_only), and EVERY address it
 *    returns must sit in a permitted network - a name that resolves anywhere else
 *    is refused, not probed. No networks permitted (the default) means nothing is
 *    probed at all.
 *  - THE PORT IS PERMITTED. 443 out of the box; anything else is an administrator's choice.
 *  - ONE PERSPECTIVE. The connection goes to the address just resolved, with the
 *    host name kept for SNI and for the certificate's name check, so the DNS check
 *    and the certificate check can never quietly look at two machines.
 *  - VALIDATION IS NEVER SWITCHED OFF. A trust profile changes which CAs are
 *    trusted, never whether the chain and the name are checked. Untrusted, expired
 *    and wrong name are three different problems, each said as itself.
 *  - NO REDIRECTS. The certificate check is a TLS handshake and nothing more. The
 *    optional HTTP check (GH #243) reads one status line and never follows a redirect.
 *
 * A refusal (address or port not permitted, nothing permitted yet) and a resolver
 * error are NOT CHECKED - they say nothing about the target - and the endpoint's
 * last known result stays on screen beside them. A name with no address, nothing
 * answering on the port, and a bad certificate are confirmed problems: fresh
 * observations, like slice 1's.
 */

require_once __DIR__ . '/names.php';
require_once __DIR__ . '/dns.php';
require_once __DIR__ . '/tls.php';

/** The private ranges offered as tick boxes under Settings - none ticked until an administrator chooses. */
const DOMAIN_PRIVATE_RANGES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

/** The ports offered as tick boxes under Settings: 443 permitted out of the box, 8443 one tick away. */
const DOMAIN_COMMON_TLS_PORTS = [443, 8443];

/** Have Database Verification's endpoint and trust profile tables been made yet? */
function domainEndpointsReady(PDO $conn): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $conn->query("SELECT id FROM domain_endpoints LIMIT 0"); $conn->query("SELECT id FROM domain_trust_profiles LIMIT 0"); return $ok = true; }
    catch (Throwable) { return $ok = false; }
}

/**
 * A CIDR range in its canonical form ("10.20.0.0/16", "fd00::/8"), or null when it
 * is not one. The address part is masked, so "10.20.30.40/16" becomes "10.20.0.0/16".
 *
 * @param string $cidr  as typed
 */
function domainCidrNormalise(string $cidr): ?string
{
    if (!preg_match('#^([0-9a-fA-F:.]+)/(\d{1,3})$#', trim($cidr), $m)) return null;
    $bin = @inet_pton($m[1]);
    if ($bin === false) return null;
    $bits = (int)$m[2];
    $max = strlen($bin) * 8;
    if ($bits > $max) return null;
    return inet_ntop(domainMaskBits($bin, $bits)) . '/' . $bits;
}

/**
 * Is this address inside the range? IPv4 and IPv6; an address of one family is
 * never inside a range of the other.
 *
 * @param string $ip    an address as text
 * @param string $cidr  a range from domainCidrNormalise()
 */
function domainIpInCidr(string $ip, string $cidr): bool
{
    [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, '');
    $a = @inet_pton($ip);
    $n = @inet_pton($net);
    if ($a === false || $n === false || strlen($a) !== strlen($n) || !ctype_digit($bits)) return false;
    return domainMaskBits($a, (int)$bits) === domainMaskBits($n, (int)$bits);
}

/**
 * The first $bits bits of a packed address, the rest zeroed.
 *
 * @param string $bin   inet_pton() output
 * @param int    $bits  how many leading bits to keep
 */
function domainMaskBits(string $bin, int $bits): string
{
    $out = '';
    for ($i = 0, $len = strlen($bin); $i < $len; $i++) {
        $keep = max(0, min(8, $bits - $i * 8));
        $out .= chr(ord($bin[$i]) & (0xFF << (8 - $keep)) & 0xFF);
    }
    return $out;
}

/** The permitted networks, from Domains -> Settings -> Internal monitoring. Empty until an administrator chooses. */
function domainInternalNetworks(array $settings): array
{
    return array_values(array_filter(array_map('trim', explode(',', (string)($settings['domain_internal_networks'] ?? '')))));
}

/** The permitted ports, as integers. */
function domainInternalPorts(array $settings): array
{
    $raw = (string)($settings['domain_internal_ports'] ?? '443');
    return array_values(array_unique(array_map('intval', array_filter(array_map('trim', explode(',', $raw)), 'ctype_digit'))));
}

/**
 * Checks one endpoint's certificate, within every limit above. Nothing is stored here.
 *
 * @param array   $ep        the domain_endpoints row (host, port, warn_days)
 * @param string  $domain    the domain's name - the host must be it or a name under it
 * @param array   $networks  domainInternalNetworks()
 * @param int[]   $ports     domainInternalPorts()
 * @param ?string $caPem     the trust profile's CA certificates, or null for the server's normal bundle
 * @param ?callable $resolve fn(string $host, string $type): array like domainDns() - for tests only,
 *                           which have no internal DNS to point at a local server; null is this server's resolver
 * @return array{completed:bool, status:?string, detail:string, params:array, address:?string, cert:?array}
 *   completed: the check ran to an answer - a confirmed problem included. status, when completed:
 *   healthy | expiring | problem. detail: the message key (domains.endpoint.detail_<detail>).
 */
function domainCheckEndpoint(array $ep, string $domain, array $networks, array $ports, ?string $caPem, ?callable $resolve = null): array
{
    // TRAP: the test resolver is honoured from the command line only, so nothing a web
    // request can reach ever changes where a name resolves.
    if ($resolve === null && PHP_SAPI === 'cli' && isset($GLOBALS['DOMAIN_ENDPOINT_RESOLVER']) && is_callable($GLOBALS['DOMAIN_ENDPOINT_RESOLVER'])) {
        $resolve = $GLOBALS['DOMAIN_ENDPOINT_RESOLVER'];
    }
    $resolve ??= fn(string $h, string $t) => domainDns($h, $t, 'system_only');
    $host = (string)$ep['host'];
    $port = (int)$ep['port'];
    $out = fn(bool $completed, ?string $status, string $detail, array $params = [], ?string $address = null, ?array $cert = null)
        => ['completed' => $completed, 'status' => $status, 'detail' => $detail, 'params' => $params, 'address' => $address, 'cert' => $cert];

    // The host is re-checked here as well as on save: a domain renamed since, or a
    // row written some other way, must not become a route to an arbitrary name.
    if (domainNormaliseHost($host) !== $host || !domainHostBelongsTo($host, $domain)) return $out(false, null, 'host_refused', ['host' => $host]);
    if (!in_array($port, $ports, true)) return $out(false, null, 'port_refused', ['port' => $port]);
    if (!$networks) return $out(false, null, 'no_networks');

    // Through this server's resolver only, whatever the install-wide setting.
    $a  = $resolve($host, 'A');
    $aa = $resolve($host, 'AAAA');
    $addresses = array_merge($a['ok'] ? $a['records'] : [], $aa['ok'] ? $aa['records'] : []);
    if (!$addresses) {
        // The slice 1 rule: an empty A answer is a real answer (Windows fails the AAAA
        // query for a missing name); only a failed A query says nothing.
        return $a['ok'] ? $out(true, 'problem', 'no_address', ['host' => $host]) : $out(false, null, 'dns_failed', ['host' => $host, 'error' => (string)$a['error']]);
    }
    // EVERY address must be permitted: a name that also resolves somewhere else is
    // refused outright, rather than probed at whichever address happened to be allowed.
    foreach ($addresses as $ip) {
        $ok = false;
        foreach ($networks as $cidr) if (domainIpInCidr((string)$ip, $cidr)) { $ok = true; break; }
        if (!$ok) return $out(false, null, 'network_refused', ['host' => $host, 'address' => (string)$ip]);
    }
    $v4 = array_values(array_filter($addresses, fn($ip) => !str_contains((string)$ip, ':')));
    $address = (string)($v4[0] ?? $addresses[0]);

    $cafile = null;
    if ($caPem !== null) {
        $cafile = tempnam(sys_get_temp_dir(), 'fitsm-ca-');
        file_put_contents($cafile, $caPem);
    }
    // The CA file lives until the optional HTTP check below has used it too.
    try {
        $c = domainTlsCertificate($host, $port, 8, $address, $cafile);
        $cert = $c['ok'] ? array_intersect_key($c, array_flip(['subject', 'issuer', 'valid_from', 'valid_to', 'days_left', 'sans', 'hostname_match', 'chain_valid'])) : null;
        $label = $host . ':' . $port;

        if (!$c['ok']) return $out(true, 'problem', 'no_answer', ['endpoint' => $label, 'error' => (string)$c['error']], $address);
        if ($c['days_left'] !== null && $c['days_left'] < 0) return $out(true, 'problem', 'expired', ['endpoint' => $label, 'date' => (string)$c['valid_to']], $address, $cert);
        if ($c['hostname_match'] === false) return $out(true, 'problem', 'wrong_name', ['endpoint' => $label, 'host' => $host], $address, $cert);
        if ($c['chain_valid'] === false) return $out(true, 'problem', 'untrusted', ['endpoint' => $label, 'issuer' => (string)$c['issuer']], $address, $cert);

        // The optional HTTP check (GH #243, slice 3) - only once the certificate itself is
        // good, over a verified connection to the same permitted address.
        $path = trim((string)($ep['http_path'] ?? ''));
        if ($path !== '') {
            $expect = domainHttpExpect($ep['http_expect'] ?? null);
            $h = domainHttpStatus($host, $port, $address, $path, $cafile);
            $where = ['endpoint' => $label, 'path' => $path];
            if (!$h['ok']) return $out(true, 'problem', 'http_no_answer', $where + ['error' => (string)$h['error']], $address, $cert);
            if (!in_array($h['status'], $expect, true)) {
                return $out(true, 'problem', 'http_status', $where + ['status' => $h['status'], 'expected' => implode(', ', $expect)], $address, $cert);
            }
        }
    } finally {
        if ($cafile !== null) @unlink($cafile);
    }
    if ($c['days_left'] !== null && $c['days_left'] <= (int)($ep['warn_days'] ?? 21)) {
        return $out(true, 'expiring', 'expiring', ['endpoint' => $label, 'days' => (int)$c['days_left'], 'date' => (string)$c['valid_to']], $address, $cert);
    }
    return $out(true, 'healthy', 'healthy', ['endpoint' => $label, 'date' => (string)$c['valid_to']], $address, $cert);
}

/**
 * The status codes an HTTP check expects (GH #243): "200, 204" -> [200, 204]. Empty
 * means 200. Anything that is not a status code (100-599) is dropped; saveEndpoint()
 * refuses it before it is stored.
 *
 * @return int[]
 */
function domainHttpExpect(?string $raw): array
{
    $codes = [];
    foreach (preg_split('/[\s,]+/', (string)$raw) as $c) {
        if (preg_match('/^[1-5]\d\d$/', $c)) $codes[] = (int)$c;
    }
    $codes = array_values(array_unique($codes));
    sort($codes);
    return $codes ?: [200];
}

/**
 * The status an endpoint answers one GET with (GH #243, slice 3). Over TLS to the
 * address already resolved and permitted, with the host name for SNI, the Host header
 * and certificate checking - validation on, as for the certificate check itself.
 *
 * TRAP: only the status line is read. Nothing else is fetched, no login is sent, and a
 * redirect is NEVER followed: a 3xx is the answer, which an endpoint can expect. That is
 * the design's "no redirect unless the target is also permitted", at its strictest.
 *
 * @param string  $address  the permitted address to connect to
 * @param string  $path     starts with '/'
 * @param ?string $cafile   the trust profile's CA file, or null for the server's bundle
 * @return array{ok:bool, status:?int, error:?string}
 */
function domainHttpStatus(string $host, int $port, string $address, string $path, ?string $cafile, int $timeout = 8): array
{
    if (isset($GLOBALS['DOMAIN_HTTP_TRACE']) && is_array($GLOBALS['DOMAIN_HTTP_TRACE'])) {
        $GLOBALS['DOMAIN_HTTP_TRACE'][] = "https://$host:$port$path@$address";
    }
    // The path goes into the request line, so it is checked again here, not only on
    // save - a row written some other way must not be able to add a header.
    if (!str_starts_with($path, '/') || strlen($path) > 500 || !preg_match('/^[!-~]+$/', $path)) {
        return ['ok' => false, 'status' => null, 'error' => 'The path is not valid'];
    }
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true];
    // TRAP: the SAME bundle as domainTlsCertificate(), or the certificate could pass and
    // the page check then fail on an install where PHP has no CA store of its own
    // (stock WAMP - see includes/ssl.php).
    $bundle = $cafile ?? (defined('SSL_CA_BUNDLE') ? SSL_CA_BUNDLE : (function_exists('sslResolveCaBundle') ? sslResolveCaBundle() : ''));
    if ($bundle) $ssl['cafile'] = $bundle;
    $target = str_contains($address, ':') ? "[$address]" : $address;
    $s = @stream_socket_client("ssl://$target:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
    if (!$s) return ['ok' => false, 'status' => null, 'error' => $errstr !== '' ? $errstr : 'No answer on port ' . $port];
    stream_set_timeout($s, $timeout);
    $hostHeader = $port === 443 ? $host : "$host:$port";
    fwrite($s, "GET $path HTTP/1.1\r\nHost: $hostHeader\r\nUser-Agent: FreeITSM-monitor\r\nAccept: */*\r\nConnection: close\r\n\r\n");
    $line = (string)fgets($s, 1024);
    fclose($s);
    if (!preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})\b#', $line, $m)) {
        return ['ok' => false, 'status' => null, 'error' => $line === '' ? 'No HTTP answer' : 'Not an HTTP answer'];
    }
    return ['ok' => true, 'status' => (int)$m[1], 'error' => null];
}

/**
 * Reads the CA certificates out of pasted PEM text, for a trust profile.
 *
 * @param string $pem  one or more "-----BEGIN CERTIFICATE-----" blocks
 * @return array{pem:string, summary:string}  the certificates alone, re-joined, and a line per CA
 * @throws InvalidArgumentException when there is no readable certificate, or anything that is not one
 */
function domainTrustProfileParse(string $pem): array
{
    if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $pem)) {
        throw new InvalidArgumentException('That contains a private key. Paste only the certificate authority\'s certificate - never its key.');
    }
    if (!preg_match_all('/-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/', $pem, $m) || !$m[0]) {
        throw new InvalidArgumentException('Paste at least one certificate, starting -----BEGIN CERTIFICATE-----.');
    }
    if (count($m[0]) > 20) throw new InvalidArgumentException('A trust profile holds up to 20 certificates.');
    $lines = [];
    foreach ($m[0] as $block) {
        $info = @openssl_x509_parse($block);
        if (!$info) throw new InvalidArgumentException('One of the certificates could not be read.');
        $name = $info['subject']['CN'] ?? ($info['subject']['O'] ?? '?');
        $to = isset($info['validTo_time_t']) ? gmdate('Y-m-d', $info['validTo_time_t']) : '?';
        $lines[] = (is_array($name) ? implode(' ', $name) : $name) . ' (until ' . $to . ')';
    }
    return ['pem' => implode("\n", array_map('trim', $m[0])) . "\n", 'summary' => mb_substr(implode('; ', $lines), 0, 1000)];
}
