<?php
/**
 * Central SSL/TLS policy for outbound HTTPS.
 *
 * Every outbound cURL handle in the app routes its certificate-verification
 * setup through sslApplyCurl(). That gives us a single global switch
 * (SSL_VERIFY_PEER, in config.php) instead of the per-module "Verify SSL"
 * tick-boxes we used to scatter across settings pages — which were confusing
 * (some ANDed with the global, some independent) and, worse, let the UI imply
 * a security state the server couldn't actually deliver.
 *
 * Why CURLOPT_CAINFO rather than php.ini or an env var:
 *   On a stock Windows/WAMP install, Apache's php.ini has no `curl.cainfo`, so
 *   verification fails with "unable to get local issuer certificate". You can't
 *   fix that from PHP at runtime — `curl.cainfo` is PHP_INI_SYSTEM (ini_set has
 *   no effect) and this libcurl build ignores the CURL_CA_BUNDLE env var. The
 *   only mechanism that works without hand-editing php.ini is setting the bundle
 *   per-handle with CURLOPT_CAINFO, which is what we ship a cacert.pem for.
 */

/**
 * Resolve a CA bundle path for CURLOPT_CAINFO, or '' to leave cURL on its
 * compiled-in default.
 *
 * Priority:
 *   1. A bundle an admin (or the OS) already configured in php.ini — honour it.
 *   2. On Windows only, the cacert.pem we ship, since PHP-on-Windows has none.
 *   3. Otherwise '' — on Linux with no configured bundle, cURL's system trust
 *      store is correct and we must not override it with a possibly-staler copy.
 */
function sslResolveCaBundle(): string
{
    foreach (['curl.cainfo', 'openssl.cafile'] as $iniKey) {
        $p = ini_get($iniKey);
        if ($p && is_readable($p)) {
            return $p;
        }
    }
    if (stripos(PHP_OS, 'WIN') === 0) {
        $bundled = __DIR__ . '/cacert.pem';
        if (is_readable($bundled)) {
            return $bundled;
        }
    }
    return '';
}

/**
 * Apply the global TLS verification policy to a cURL handle.
 *
 * Sets VERIFYPEER/VERIFYHOST from SSL_VERIFY_PEER and, when verifying, points
 * cURL at a usable CA bundle. Call this once per handle, after curl_init(),
 * instead of setting CURLOPT_SSL_VERIFYPEER by hand.
 *
 * @param \CurlHandle|resource $ch
 * @param bool $alwaysVerify  Force verification on regardless of the global
 *                            switch — for traffic that must never be sent
 *                            unverified (webhooks carry record data to third
 *                            parties over the public internet). Still attaches
 *                            the CA bundle so it works out of the box.
 */
function sslApplyCurl($ch, bool $alwaysVerify = false): void
{
    $verify = $alwaysVerify || (defined('SSL_VERIFY_PEER') ? (bool)SSL_VERIFY_PEER : true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
    if ($verify && defined('SSL_CA_BUNDLE') && SSL_CA_BUNDLE !== '') {
        curl_setopt($ch, CURLOPT_CAINFO, SSL_CA_BUNDLE);
    }
}
