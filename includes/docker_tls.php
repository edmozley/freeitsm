<?php
/**
 * HTTPS for the Docker image, with a certificate FreeITSM makes itself.
 *
 * System → Docker lets an administrator type the name people will use
 * (freeitsm.internal) and get a working HTTPS certificate for it, without
 * knowing anything about certificates.
 *
 * 🔑 WHY A PRIVATE CERTIFICATE AUTHORITY AND NOT LET'S ENCRYPT.
 * A public authority only signs names that exist on the public internet and
 * that you can prove you own. `.internal` is reserved for private use, so nobody
 * owns it and nobody public will ever sign it. So this makes a small authority
 * of its own, signs the server certificate with it, and the administrator
 * installs the authority's certificate on their PCs once.
 *
 * 🔒 THE AUTHORITY IS NAME-CONSTRAINED. Its certificate ends up trusted by every
 * PC in the company, and its private key lives in a folder the web server can
 * write to. Unconstrained, anybody who ever read that key could mint a trusted
 * certificate for any website at all. Constrained, the worst they can do is sign
 * the one name (and IP) it was made for. The price: a new name means a new
 * authority, and the new one has to be installed on the PCs again. The screen
 * says so before it happens.
 *
 * Files, all in DOCKER_TLS_DIR (a Docker volume, so a rebuild keeps them):
 *   ca.crt        the authority's certificate — the ONLY file to hand out
 *   ca.key        the authority's private key — never leaves this folder
 *   server.crt    the server certificate followed by ca.crt (the full chain)
 *   server.key    the server certificate's private key
 *   settings.json the name and IP the certificates were made for
 *   loaded.txt    written by docker/entrypoint.sh: the fingerprint of the
 *                 certificate Apache actually started with. Comparing it with
 *                 server.crt is how the screen knows a restart is still due.
 *
 * ⚠️ PHP cannot switch HTTPS on by itself. Apache reads the certificate at start
 * and runs as root to do it; PHP runs as www-data. So generating writes the
 * files and the screen tells the operator to restart the container, where
 * docker/entrypoint.sh checks the files and only then turns HTTPS on. A broken
 * or mismatched certificate leaves the container on plain HTTP, never down.
 */

const DOCKER_TLS_DIR = '/var/www/tls';

/** The Apache site the image ships. Absent means the image predates this. */
const DOCKER_TLS_APACHE_SITE_AVAILABLE = '/etc/apache2/sites-available/freeitsm-ssl.conf';

/** Days the server certificate lasts. 825 is the most Apple devices accept. */
const DOCKER_TLS_SERVER_DAYS = 825;

/** Days the authority lasts. Long, because replacing it means visiting every PC. */
const DOCKER_TLS_CA_DAYS = 3650;

/**
 * Tidy what someone typed into a bare host name, or null if it is not one.
 *
 * Forgiving about what people paste — "https://FreeITSM.internal:8443/" becomes
 * "freeitsm.internal" — and strict about the result, because it goes into a
 * certificate and an OpenSSL config file.
 */
function dockerTlsNormaliseHost(string $input): ?string
{
    $h = strtolower(trim($input));
    $h = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $h);   // scheme
    $h = preg_replace('#[/?\#].*$#', '', $h);               // path, query
    $h = preg_replace('#:\d+$#', '', $h);                   // port
    $h = rtrim($h, '.');

    if ($h === '' || strlen($h) > 253) return null;
    if (filter_var($h, FILTER_VALIDATE_IP)) return null;    // an IP is not a name

    $labels = explode('.', $h);
    foreach ($labels as $label) {
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) return null;
    }
    // A name whose last part is all digits reads as a broken IP address.
    if (ctype_digit(end($labels))) return null;

    return $h;
}

/** A valid IPv4 or IPv6 address, or null. Empty input is allowed and gives null. */
function dockerTlsNormaliseIp(string $input): ?string
{
    $ip = trim($input, " \t\n\r\0\x0B[]");
    if ($ip === '') return null;
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

/**
 * What the browser used to reach this page: host, port and whether host is an IP.
 *
 * 🔑 THIS IS HOW THE SCREEN KNOWS THE IP. Inside a container PHP only sees its
 * own internal address (172.x), which is no use to anybody. The address in the
 * browser's address bar is the one the PCs actually reach.
 */
function dockerTlsBrowserAddress(): array
{
    $raw  = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $host = $raw;
    $port = null;

    if (preg_match('/^\[(.+)\](?::(\d+))?$/', $raw, $m)) {          // [IPv6]:port
        $host = $m[1];
        $port = isset($m[2]) ? (int) $m[2] : null;
    } elseif (substr_count($raw, ':') === 1) {                       // host:port
        [$host, $p] = explode(':', $raw);
        $port = ctype_digit($p) ? (int) $p : null;
    }

    return [
        'host'  => strtolower($host),
        'port'  => $port,
        'is_ip' => (bool) filter_var($host, FILTER_VALIDATE_IP),
        'https' => dockerTlsRequestIsHttps(),
    ];
}

function dockerTlsRequestIsHttps(): bool
{
    return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

/** Lower-case hex with no separators, so PHP's and the openssl CLI's agree. */
function dockerTlsFingerprintNormalise(string $fp): string
{
    if (strpos($fp, '=') !== false) $fp = substr($fp, strpos($fp, '=') + 1);
    return strtolower(preg_replace('/[^0-9a-f]/i', '', $fp));
}

/** Read one PEM certificate's details, or null. */
function dockerTlsCertInfo(string $pem): ?array
{
    $x = @openssl_x509_read($pem);
    if ($x === false) return null;
    $p = openssl_x509_parse($x);
    if (!$p) return null;

    return [
        'subject'     => $p['subject']['CN'] ?? '',
        'san'         => $p['extensions']['subjectAltName'] ?? '',
        'valid_from'  => (int) $p['validFrom_time_t'],
        'valid_to'    => (int) $p['validTo_time_t'],
        'fingerprint' => dockerTlsFingerprintNormalise((string) openssl_x509_fingerprint($x, 'sha256')),
    ];
}

/**
 * Everything the screen needs to know, in one place.
 */
function dockerTlsState(string $dir = DOCKER_TLS_DIR): array
{
    $state = [
        'dir'             => $dir,
        'openssl'         => extension_loaded('openssl'),
        'image_supports'  => @file_exists(DOCKER_TLS_APACHE_SITE_AVAILABLE),
        'dir_exists'      => @is_dir($dir),
        'dir_writable'    => @is_dir($dir) && @is_writable($dir),
        'persisted'       => null,
        'settings'        => null,
        'server'          => null,
        'ca'              => null,
        'loaded'          => null,
        'restart_needed'  => false,
        'active'          => false,
    ];

    if (function_exists('storagePersistenceStatus') && $state['dir_exists']) {
        // 'persisted' | 'at_risk' | 'unknown'. at_risk = not on a volume, so the
        // next `docker compose up -d` would take the certificate with it.
        $state['persisted'] = storagePersistenceStatus($dir);
    }

    $settings = @file_get_contents($dir . '/settings.json');
    if ($settings !== false) {
        $decoded = json_decode($settings, true);
        if (is_array($decoded)) $state['settings'] = $decoded;
    }

    $server = @file_get_contents($dir . '/server.crt');
    if ($server !== false) $state['server'] = dockerTlsCertInfo($server);

    $ca = @file_get_contents($dir . '/ca.crt');
    if ($ca !== false) $state['ca'] = dockerTlsCertInfo($ca);

    $loaded = @file_get_contents($dir . '/loaded.txt');
    if ($loaded !== false && trim($loaded) !== '') {
        $state['loaded'] = dockerTlsFingerprintNormalise($loaded);
    }

    // A key that does not match the certificate is the one case where restarting
    // does nothing: entrypoint.sh refuses the pair and stays on HTTP. The screen
    // has to say "make it again" instead.
    $state['broken'] = false;
    if ($server !== false) {
        $key = @file_get_contents($dir . '/server.key');
        $state['broken'] = !$state['server'] || $key === false
            || !@openssl_x509_check_private_key($server, $key);
    }

    if ($state['server'] && !$state['broken']) {
        $state['active']         = $state['loaded'] === $state['server']['fingerprint'];
        $state['restart_needed'] = !$state['active'];
    }

    return $state;
}

/**
 * Would making a certificate for this name and IP need a NEW authority?
 *
 * True when there is none yet, when it was made for something else (it is
 * name-constrained, so it cannot sign anything else), or when it would expire
 * before the new server certificate does.
 */
function dockerTlsNeedsNewCa(string $host, ?string $ip, string $dir = DOCKER_TLS_DIR): bool
{
    if (!is_file($dir . '/ca.crt') || !is_file($dir . '/ca.key')) return true;

    $settings = json_decode((string) @file_get_contents($dir . '/settings.json'), true);
    if (!is_array($settings)) return true;
    if (($settings['host'] ?? null) !== $host) return true;
    if (($settings['ip'] ?? null) !== $ip) return true;

    $ca = dockerTlsCertInfo((string) @file_get_contents($dir . '/ca.crt'));
    if (!$ca) return true;
    return $ca['valid_to'] < time() + DOCKER_TLS_SERVER_DAYS * 86400;
}

/** The netmask that pins a name constraint to exactly one address. */
function dockerTlsSingleAddressMask(string $ip): string
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
        ? implode(':', array_fill(0, 8, 'ffff'))
        : '255.255.255.255';
}

/**
 * The OpenSSL config both certificates are made from.
 *
 * PHP's openssl functions can only add extensions (subjectAltName, name
 * constraints and so on) through a config file, so one is written to a temp
 * file for the duration. Passing it explicitly also avoids depending on the
 * system openssl.cnf, which on some PHP builds is missing altogether.
 */
function dockerTlsOpensslConfig(string $host, ?string $ip): string
{
    $san = 'DNS:' . $host . ($ip !== null ? ', IP:' . $ip : '');
    $nc  = 'permitted;DNS:' . $host
         . ($ip !== null ? ', permitted;IP:' . $ip . '/' . dockerTlsSingleAddressMask($ip) : '');

    return <<<CNF
[ req ]
distinguished_name = req_dn
prompt             = no

[ req_dn ]
CN = FreeITSM

[ v3_ca ]
basicConstraints       = critical, CA:TRUE, pathlen:0
keyUsage               = critical, keyCertSign, cRLSign
subjectKeyIdentifier   = hash
nameConstraints        = critical, {$nc}

[ v3_server ]
basicConstraints       = critical, CA:FALSE
keyUsage               = critical, digitalSignature, keyEncipherment
extendedKeyUsage       = serverAuth
subjectKeyIdentifier   = hash
authorityKeyIdentifier = keyid
subjectAltName         = {$san}

CNF;
}

/** Write a file by renaming a temp copy over it, so a reader never sees half of one. */
function dockerTlsWriteFile(string $path, string $contents, int $mode): void
{
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $contents) === false) {
        throw new RuntimeException('Could not write ' . basename($path) . ' in ' . dirname($path));
    }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace ' . basename($path) . ' in ' . dirname($path));
    }
}

/**
 * Make the certificates. Returns ['new_ca' => bool, 'server' => info, 'ca' => info].
 *
 * Reuses the existing authority when it was made for the same name and IP, so
 * renewing the server certificate never means visiting the PCs again.
 *
 * @throws RuntimeException with a message fit to show an administrator.
 */
function dockerTlsGenerate(string $host, ?string $ip, string $dir = DOCKER_TLS_DIR): array
{
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('PHP\'s openssl extension is not loaded.');
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('The certificate folder ' . $dir . ' is missing or not writable.');
    }

    $cnfPath = tempnam(sys_get_temp_dir(), 'fitsm-tls');
    if ($cnfPath === false || file_put_contents($cnfPath, dockerTlsOpensslConfig($host, $ip)) === false) {
        throw new RuntimeException('Could not write a temporary OpenSSL config file.');
    }

    try {
        $keyOpts = ['config' => $cnfPath, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        $newCa   = dockerTlsNeedsNewCa($host, $ip, $dir);

        if ($newCa) {
            $caKey = openssl_pkey_new($keyOpts);
            if ($caKey === false) throw new RuntimeException('Could not create the authority key: ' . dockerTlsOpensslError());

            // The name is what the PCs' certificate store will show. It says what
            // it is for and when, so an admin tidying the store years later knows.
            $caDn = [
                'commonName'       => 'FreeITSM local CA - ' . $host . ' - ' . gmdate('Y-m-d'),
                'organizationName' => 'FreeITSM',
            ];
            $caCsr = openssl_csr_new($caDn, $caKey, ['config' => $cnfPath, 'digest_alg' => 'sha256']);
            if ($caCsr === false) throw new RuntimeException('Could not create the authority request: ' . dockerTlsOpensslError());

            $caCert = openssl_csr_sign($caCsr, null, $caKey, DOCKER_TLS_CA_DAYS,
                ['config' => $cnfPath, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3_ca'],
                random_int(1, PHP_INT_MAX));
            if ($caCert === false) throw new RuntimeException('Could not sign the authority certificate: ' . dockerTlsOpensslError());

            openssl_x509_export($caCert, $caCertPem);
            openssl_pkey_export($caKey, $caKeyPem, null, ['config' => $cnfPath]);
        } else {
            $caCertPem = (string) file_get_contents($dir . '/ca.crt');
            $caKeyPem  = (string) file_get_contents($dir . '/ca.key');
            $caCert    = openssl_x509_read($caCertPem);
            $caKey     = openssl_pkey_get_private($caKeyPem);
            if ($caCert === false || $caKey === false) {
                throw new RuntimeException('The existing authority files could not be read.');
            }
        }

        $srvKey = openssl_pkey_new($keyOpts);
        if ($srvKey === false) throw new RuntimeException('Could not create the server key: ' . dockerTlsOpensslError());

        $srvCsr = openssl_csr_new(['commonName' => $host], $srvKey, ['config' => $cnfPath, 'digest_alg' => 'sha256']);
        if ($srvCsr === false) throw new RuntimeException('Could not create the server request: ' . dockerTlsOpensslError());

        $srvCert = openssl_csr_sign($srvCsr, $caCert, $caKey, DOCKER_TLS_SERVER_DAYS,
            ['config' => $cnfPath, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3_server'],
            random_int(1, PHP_INT_MAX));
        if ($srvCert === false) throw new RuntimeException('Could not sign the server certificate: ' . dockerTlsOpensslError());

        openssl_x509_export($srvCert, $srvCertPem);
        openssl_pkey_export($srvKey, $srvKeyPem, null, ['config' => $cnfPath]);
    } finally {
        @unlink($cnfPath);
    }

    // A failure part-way can leave server.key not matching server.crt. That is
    // survivable: entrypoint.sh checks the pair matches before turning HTTPS on,
    // and stays on plain HTTP if it does not.
    if ($newCa) {
        dockerTlsWriteFile($dir . '/ca.key', $caKeyPem, 0600);
        dockerTlsWriteFile($dir . '/ca.crt', $caCertPem, 0644);
    }
    dockerTlsWriteFile($dir . '/server.key', $srvKeyPem, 0600);
    dockerTlsWriteFile($dir . '/server.crt', $srvCertPem . $caCertPem, 0644);
    dockerTlsWriteFile($dir . '/settings.json', json_encode([
        'host'         => $host,
        'ip'           => $ip,
        'generated_at' => gmdate('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0644);

    return [
        'new_ca' => $newCa,
        'server' => dockerTlsCertInfo($srvCertPem),
        'ca'     => dockerTlsCertInfo($caCertPem),
    ];
}

/** Drain OpenSSL's error queue into one line. */
function dockerTlsOpensslError(): string
{
    $errs = [];
    while (($e = openssl_error_string()) !== false) $errs[] = $e;
    return $errs ? implode('; ', $errs) : 'unknown OpenSSL error';
}
