<?php
/**
 * The one HTTP door for the hypervisor syncs (Proxmox VE, VMware Cloud Director).
 *
 * Added when PR #167 was merged. Each engine had its own cURL block; they now
 * share this one, for three reasons:
 *
 *   1. HTTPS ONLY. Every request carries a credential - a Proxmox API token
 *      secret, or a Director password in Basic auth - so plain http would send
 *      it across the network readable by anyone on the path. validateHost()
 *      refuses http:// when a server is saved, and request() refuses it again
 *      in case a row was written some other way.
 *
 *   2. THE CA BUNDLE. With "Verify certificate" ticked, the PR set
 *      CURLOPT_SSL_VERIFYPEER but no CURLOPT_CAINFO, so on WAMP and some
 *      Docker and Linux hosts a perfectly valid certificate failed with
 *      "unable to get local issuer certificate" - GH #129 again. A ticked box
 *      now goes through sslApplyCurl($ch, true), the same helper as every other
 *      integration: verification forced on, with the install's bundle.
 *
 *   3. TESTS. Neither product can be run in CI. $testTransport lets
 *      tests/hypervisor-sync.php answer as a stand-in Proxmox or Director and
 *      drive the real sync code - including every rule about what gets deleted.
 */

require_once __DIR__ . '/ssl.php';

final class HypervisorHttp
{
    /**
     * Tests only: fn(string $method, string $url, array $headers, ?string $body): array
     * returning [int $code, string $body, array $responseHeaders (lower-case names)].
     * Never set outside tests/.
     */
    public static $testTransport = null;

    /**
     * Normalise and check a server address as typed: https://host or
     * https://host:port, nothing after it. Throws a readable message.
     *
     * TRAP: keep this https-only. The credential travels with every request.
     *
     * @param string $host     the server address as the administrator typed it; a trailing slash is dropped
     * @param string $example  a sample address for the error message, e.g. 'https://pve.example.com:8006'
     */
    public static function validateHost(string $host, string $example): string
    {
        $host = rtrim(trim($host), '/');
        if (preg_match('#^http://#i', $host)) {
            throw new Exception('Use an https:// address. FreeITSM sends this server\'s password or API token with every request, so it never connects over plain http.');
        }
        if (!preg_match('#^https://[^\s/:]+(:\d{1,5})?$#i', $host)) {
            throw new Exception('The server address must look like ' . $example . ' - https://, the host name, and the port if it is not 443.');
        }
        return $host;
    }

    /**
     * One request. Returns [code, body, headers]. Throws only when the server
     * could not be reached; an HTTP error status is returned for the caller to judge.
     *
     * @param string $method   the HTTP method, e.g. 'GET' or 'POST'
     * @param string $url      the full URL; anything not https:// is refused
     * @param array $headers   request headers as 'Name: value' strings, including the credential
     * @param ?string $body    the request body; null sends none
     * @param bool $verify     false skips certificate checks - the server's "Verify certificate" box unticked
     * @param int $timeout     the whole request's time limit, in seconds
     */
    public static function request(string $method, string $url, array $headers, ?string $body, bool $verify, int $timeout = 60): array
    {
        if (stripos($url, 'https://') !== 0) {
            throw new Exception('Refusing to send credentials over plain http.');
        }
        if (self::$testTransport !== null) {
            return (self::$testTransport)($method, $url, $headers, $body);
        }

        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($verify) {
            sslApplyCurl($ch, true);
        } else {
            // The admin unticked "Verify certificate" for this server - usually
            // Proxmox's own self-signed certificate. Their decision, per server.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$responseHeaders) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $responseHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        });
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            throw new Exception($err);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$code, (string)$resp, $responseHeaders];
    }
}
