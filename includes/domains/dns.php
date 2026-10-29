<?php
/**
 * Domains — DNS questions, answered one way whatever the resolver.
 *
 * Two resolvers, one shape:
 *
 *   system      PHP's dns_get_record(), i.e. whatever the server itself uses.
 *               Fast and private, but PHP cannot ask for every type on every
 *               platform — on Windows there is no DNS_CAA constant at all, and no
 *               PHP anywhere can ask for DS.
 *   google /    DNS-over-HTTPS JSON (dns.google/resolve, cloudflare-dns.com).
 *   cloudflare  Answers every type, and is what the "system" resolver falls back
 *               to for the types it cannot ask.
 *
 * 🔑 EVERY ANSWER IS NORMALISED: host names lower-case with no trailing dot, TXT
 * records re-joined from their 255-byte chunks, MX as [priority, host]. The
 * checks compare answers from different resolvers and from the registry, so a
 * trailing dot or a chunk boundary must never read as a difference.
 */

require_once __DIR__ . '/lookup.php';   // domainHttpGet()

/**
 * Asks one DNS question through the chosen resolver and returns the normalised
 * answer, cached for the request.
 *
 * 'auto' means DNS-over-HTTPS (Google) on Windows and the system resolver
 * elsewhere; DS, DNSKEY and an unsupported CAA always go over DoH, and a failed
 * system lookup is retried over DoH.
 *
 * @param string $name      the DNS name to ask about, e.g. 'example.com' or '_dmarc.example.com'
 * @param string $type      record type - A, AAAA, NS, CNAME, MX, TXT, CAA, DS or DNSKEY (any case)
 * @param string $resolver  auto | system | system_only | google | cloudflare (the domain_dns_resolver setting); '' means auto.
 *                          system_only (GH #203): this server's resolver and NOTHING else - no DoH, no
 *                          fallback, so an internal name is never sent to Google or Cloudflare. A record
 *                          type that needs DoH comes back unsupported (see the return), not failed.
 *
 * @return array{ok:bool, nxdomain:bool, records:array, error:?string, unsupported?:bool}
 *   unsupported: true when system_only cannot ask this type at all - a check to say "not
 *   checked" about, never a failed lookup (which would hold back the grade) or a missing record.
 *   records: A/AAAA → list of IPs; NS/CNAME → host names; MX → [[pri, host]];
 *   TXT → strings; CAA → [[flags, tag, value]]; DS → [string] (presence is what matters)
 */
function domainDns(string $name, string $type, string $resolver = 'system'): array
{
    static $cache = [];
    $key = "$resolver|$type|$name";
    if (isset($cache[$key])) return $cache[$key];

    $type = strtoupper($type);
    $auto = ($resolver === 'auto' || $resolver === '');
    // GH #203: "this server only" - the system resolver with every public route closed.
    $serverOnly = $resolver === 'system_only';
    if ($serverOnly) $resolver = 'system';
    if ($auto) {
        $resolver = (PHP_OS_FAMILY === 'Windows') ? 'google' : 'system';
    }
    $useDoh = $resolver !== 'system'
           || in_array($type, ['DS', 'DNSKEY'], true)
           || ($type === 'CAA' && !defined('DNS_CAA'));

    if ($serverOnly && $useDoh) {
        // DS, DNSKEY and (where PHP lacks DNS_CAA) CAA can only be asked over DoH. Asked,
        // they would leave this server - so they are not asked.
        return $cache[$key] = ['ok' => false, 'nxdomain' => false, 'records' => [], 'unsupported' => true,
            'error' => "This server's resolver cannot look up $type records, and Domains is set to use only this server."];
    }

    $res = $useDoh
        ? domainDnsDoh($name, $type, $resolver === 'cloudflare' ? 'cloudflare' : 'google')
        : domainDnsSystem($name, $type);

    // Second opinions when the first could not answer - a failure that has
    // nothing to do with the domain. The system resolver falls back to Google,
    // as it always has. Automatic on Windows (DoH first) also tries Cloudflare,
    // then the server's own resolver: a network that blocks one DNS-over-HTTPS
    // service - school and corporate filters often do - must not leave every
    // check answered by a failure. An explicitly chosen DoH service is respected.
    if (!$res['ok'] && !$serverOnly) {   // this-server-only: a failure stays a failure, never asked elsewhere
        $tries = !$useDoh ? ['google'] : ($auto ? ['cloudflare', 'system'] : []);
        foreach ($tries as $alt) {
            $next = $alt === 'system' ? domainDnsSystem($name, $type) : domainDnsDoh($name, $type, $alt);
            if ($next['ok']) { $res = $next; break; }
        }
    }
    return $cache[$key] = $res;
}

/**
 * Answers a DNS question with PHP's dns_get_record() (A, AAAA, MX, TXT, NS, CNAME and CAA where PHP has it), normalised and sorted.
 *
 * @param string $name  the DNS name to ask about
 * @param string $type  record type in upper case; one PHP cannot ask for gives ok false
 */
function domainDnsSystem(string $name, string $type): array
{
    $map = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'NS' => DNS_NS, 'CNAME' => DNS_CNAME];
    if (defined('DNS_CAA')) $map['CAA'] = constant('DNS_CAA');
    if (!isset($map[$type])) return ['ok' => false, 'nxdomain' => false, 'records' => [], 'error' => "Type $type not supported"];

    // The same trace as domainHttpGet() (GH #203): this server's resolver is the one
    // route an internal domain may use, and the tests check it is the only one.
    if (isset($GLOBALS['DOMAIN_HTTP_TRACE']) && is_array($GLOBALS['DOMAIN_HTTP_TRACE'])) $GLOBALS['DOMAIN_HTTP_TRACE'][] = "dns-system://$name/$type";
    $raw = @dns_get_record($name, $map[$type]);
    if ($raw === false) return ['ok' => false, 'nxdomain' => false, 'records' => [], 'error' => 'DNS query failed'];

    $out = [];
    foreach ($raw as $r) {
        switch ($type) {
            case 'A':     if (!empty($r['ip']))   $out[] = $r['ip']; break;
            case 'AAAA':  if (!empty($r['ipv6'])) $out[] = strtolower($r['ipv6']); break;
            case 'NS':
            case 'CNAME': if (!empty($r['target'])) $out[] = strtolower(rtrim($r['target'], '.')); break;
            case 'MX':    if (isset($r['target'])) $out[] = [(int)($r['pri'] ?? 0), strtolower(rtrim($r['target'], '.'))]; break;
            case 'TXT':   $out[] = isset($r['entries']) ? implode('', $r['entries']) : (string)($r['txt'] ?? ''); break;
            case 'CAA':   $out[] = [(int)($r['flags'] ?? 0), strtolower((string)($r['tag'] ?? '')), (string)($r['value'] ?? '')]; break;
        }
    }
    return ['ok' => true, 'nxdomain' => false, 'records' => domainDnsSort($type, $out), 'error' => null];
}

/**
 * Answers a DNS question over DNS-over-HTTPS JSON from Google or Cloudflare, normalised and sorted; NXDOMAIN comes back as ok with nxdomain true.
 *
 * @param string $name      the DNS name to ask about
 * @param string $type      record type in upper case
 * @param string $provider  'cloudflare' for Cloudflare; anything else asks Google
 */
function domainDnsDoh(string $name, string $type, string $provider): array
{
    $url = $provider === 'cloudflare'
        ? 'https://cloudflare-dns.com/dns-query?name=' . rawurlencode($name) . '&type=' . $type
        : 'https://dns.google/resolve?name=' . rawurlencode($name) . '&type=' . $type;
    [$code, $body, $err] = domainHttpGet($url, 'application/dns-json', 8);
    $j = $code === 200 ? json_decode($body, true) : null;
    if (!is_array($j)) return ['ok' => false, 'nxdomain' => false, 'records' => [], 'error' => 'DNS-over-HTTPS query failed' . ($err ? ": $err" : '')];

    $status = (int)($j['Status'] ?? 2);
    if ($status === 3) return ['ok' => true, 'nxdomain' => true, 'records' => [], 'error' => null];
    if ($status !== 0) return ['ok' => false, 'nxdomain' => false, 'records' => [], 'error' => "DNS server failure (status $status)"];

    $typeNum = ['A' => 1, 'NS' => 2, 'CNAME' => 5, 'MX' => 15, 'TXT' => 16, 'AAAA' => 28, 'DS' => 43, 'DNSKEY' => 48, 'CAA' => 257][$type] ?? 0;
    $out = [];
    foreach ($j['Answer'] ?? [] as $a) {
        if ((int)($a['type'] ?? 0) !== $typeNum) continue;   // skip the CNAME hops in a chain
        $d = (string)($a['data'] ?? '');
        switch ($type) {
            case 'A': case 'AAAA': $out[] = strtolower($d); break;
            case 'NS': case 'CNAME': $out[] = strtolower(rtrim($d, '.')); break;
            case 'MX':
                $p = preg_split('/\s+/', trim($d), 2);
                $out[] = [(int)($p[0] ?? 0), strtolower(rtrim($p[1] ?? '', '.'))];
                break;
            case 'TXT':
                // "\"v=spf1 include:a\" \"-all\"" → one string, chunks joined.
                if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $d, $m)) {
                    $out[] = implode('', array_map('stripcslashes', $m[1]));
                } else {
                    $out[] = $d;
                }
                break;
            case 'CAA':
                if (preg_match('/^(\d+)\s+(\S+)\s+"?(.*?)"?$/', $d, $m)) $out[] = [(int)$m[1], strtolower($m[2]), $m[3]];
                break;
            default: $out[] = $d;
        }
    }
    return ['ok' => true, 'nxdomain' => false, 'records' => domainDnsSort($type, $out), 'error' => null];
}

/**
 * Puts records in a stable order so answers compare equal - MX by priority then host, CAA as given, everything else de-duplicated and sorted.
 *
 * @param string $type  record type in upper case - decides how the records are ordered
 * @param array  $recs  the normalised records, in the shape domainDns() describes for that type
 */
function domainDnsSort(string $type, array $recs): array
{
    if ($type === 'MX') {
        usort($recs, fn($a, $b) => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));
        return $recs;
    }
    if ($type === 'CAA') return $recs;
    $recs = array_values(array_unique($recs, SORT_REGULAR));
    sort($recs);
    return $recs;
}

/**
 * The TXT records at $name that start with $prefix (case-insensitive), e.g. "v=spf1".
 *
 * @param string $name      the DNS name whose TXT records to read
 * @param string $prefix    the start to match, ignoring case and leading spaces, e.g. 'v=spf1'
 * @param string $resolver  auto | system | google | cloudflare, as for domainDns()
 */
function domainTxtStartingWith(string $name, string $prefix, string $resolver): array
{
    $r = domainDns($name, 'TXT', $resolver);
    return array_values(array_filter($r['records'], fn($t) => stripos(ltrim($t), $prefix) === 0));
}
