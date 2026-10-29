<?php
/**
 * Domains — reading the certificate a web server presents.
 *
 * Two handshakes per host, on purpose:
 *
 *   1. verification OFF, to read the certificate whatever state it is in — an
 *      expired or self-signed certificate is exactly the one somebody needs to
 *      hear about, and a verifying handshake would refuse to show it;
 *   2. verification ON, against the same CA bundle the rest of FreeITSM trusts,
 *      to answer "would a browser accept this chain?".
 *
 * Only ever called for the domain itself, www, and the extra hosts on the
 * domain's own record — which domainParseSslHosts() restricts to sub-domains of
 * that domain, so this cannot be pointed at an arbitrary address.
 */

if (file_exists(__DIR__ . '/../ssl.php')) require_once __DIR__ . '/../ssl.php';

/**
 * Reads the certificate a host presents - subject, issuer, dates, days left and
 * names - then says whether it matches the host name and whether the chain
 * verifies against the trusted CA bundle.
 *
 * @param string $host     the host name to connect to and to send as SNI
 * @param int    $port     the TCP port to try
 * @param int    $timeout  connect timeout in seconds, for each of the two handshakes
 *
 * @return array{ok:bool, host:string, error:?string, subject:?string, issuer:?string,
 *               valid_from:?string, valid_to:?string, days_left:?int, sans:array,
 *               hostname_match:?bool, chain_valid:?bool}
 */
function domainTlsCertificate(string $host, int $port = 443, int $timeout = 8): array
{
    $out = ['ok' => false, 'host' => $host, 'error' => null, 'subject' => null, 'issuer' => null,
            'valid_from' => null, 'valid_to' => null, 'days_left' => null, 'sans' => [],
            'hostname_match' => null, 'chain_valid' => null];

    $ctx = stream_context_create(['ssl' => [
        'capture_peer_cert' => true,
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'SNI_enabled'       => true,
        'peer_name'         => $host,
    ]]);
    // The same trace as domainHttpGet() (GH #203): a test sees every connection Domains makes.
    if (isset($GLOBALS['DOMAIN_HTTP_TRACE']) && is_array($GLOBALS['DOMAIN_HTTP_TRACE'])) $GLOBALS['DOMAIN_HTTP_TRACE'][] = "tls://$host:$port";
    $s = @stream_socket_client("ssl://$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) {
        $out['error'] = $errstr !== '' ? $errstr : 'No HTTPS answer on port ' . $port;
        return $out;
    }
    $params = stream_context_get_params($s);
    fclose($s);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    $info = $cert ? openssl_x509_parse($cert) : false;
    if (!$info) {
        $out['error'] = 'The server did not present a readable certificate.';
        return $out;
    }

    $out['ok']         = true;
    $out['subject']    = $info['subject']['CN'] ?? null;
    $out['issuer']     = $info['issuer']['O'] ?? ($info['issuer']['CN'] ?? null);
    $out['valid_from'] = isset($info['validFrom_time_t']) ? gmdate('Y-m-d', $info['validFrom_time_t']) : null;
    $out['valid_to']   = isset($info['validTo_time_t'])   ? gmdate('Y-m-d', $info['validTo_time_t'])   : null;
    if (isset($info['validTo_time_t'])) {
        $out['days_left'] = (int)floor(($info['validTo_time_t'] - time()) / 86400);
    }

    $sans = [];
    foreach (explode(',', (string)($info['extensions']['subjectAltName'] ?? '')) as $san) {
        $san = trim($san);
        if (stripos($san, 'DNS:') === 0) $sans[] = strtolower(substr($san, 4));
    }
    if (!$sans && $out['subject']) $sans[] = strtolower($out['subject']);
    $out['sans'] = array_values(array_unique($sans));
    $out['hostname_match'] = domainTlsNameMatches($host, $out['sans']);

    // Second handshake: does the chain verify against the trusted bundle?
    $bundle = defined('SSL_CA_BUNDLE') ? SSL_CA_BUNDLE : (function_exists('sslResolveCaBundle') ? sslResolveCaBundle() : '');
    $vopts = ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host];
    if ($bundle) $vopts['cafile'] = $bundle;
    $v = @stream_socket_client("ssl://$host:$port", $e2, $s2, $timeout, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $vopts]));
    if ($v) { $out['chain_valid'] = true; fclose($v); }
    else    { $out['chain_valid'] = $out['hostname_match'] === false ? null : false; }

    return $out;
}

/**
 * Does $host appear in the certificate's names (with one-label wildcards)?
 *
 * @param string   $host  the host name that was asked for
 * @param string[] $sans  the certificate's DNS names, lower case; "*.example.com" matches one label
 */
function domainTlsNameMatches(string $host, array $sans): bool
{
    $host = strtolower($host);
    foreach ($sans as $n) {
        if ($n === $host) return true;
        if (strncmp($n, '*.', 2) === 0) {
            $suffix = substr($n, 1);                 // ".example.com"
            $rest = substr($host, 0, -strlen($suffix));
            if (substr($host, -strlen($suffix)) === $suffix && $rest !== '' && !str_contains($rest, '.')) return true;
        }
    }
    return false;
}
