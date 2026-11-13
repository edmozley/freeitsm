<?php
/**
 * Where stored bytes live: a folder on the server, or S3-compatible storage.
 *
 * Callers never touch the filesystem or the network directly; they ask a
 * StorageDriver for a key. A key is always relative ("2026/11/08/<hex>.bin")
 * and is validated by the caller's own strict pattern before it gets here; the
 * drivers refuse anything that could climb out of their root as well.
 *
 *   LocalDriver  (local_driver.php)  <root>/<key>
 *   S3Driver     (s3_driver.php)     s3://<bucket>/<prefix><store>/<key>
 *
 * Errors a caller must tell apart:
 *   StorageNotFound    the object is not there (a 410 to the person asking)
 *   StorageUnavailable the store cannot be reached or refused us (a 503: try later)
 */

/** The store cannot be reached, timed out, refused our credentials, or failed (5xx). */
class StorageUnavailable extends RuntimeException {}

/** The object does not exist in the store. */
class StorageNotFound extends RuntimeException {}

interface StorageDriver
{
    /**
     * Stores a local file under $key, replacing any object already there.
     *
     * @param bool $move  the source may be consumed (the local driver renames it)
     */
    public function putFile(string $key, string $localPath, bool $move = false): void;

    /**
     * Sends bytes $start..$end (inclusive) of $key to $sink in chunks.
     *
     * @param callable $sink  fn(string $chunk): bool - return false to stop early
     */
    public function readRange(string $key, int $start, int $end, callable $sink): void;

    /** Copies the whole object into a local file. */
    public function readTo(string $key, string $localPath): void;

    /** The object's size in bytes, or null when it is not there. */
    public function size(string $key): ?int;

    /**
     * Deletes the object; one that is already gone is not an error. Throws when it
     * is still there afterwards (RuntimeException), or the store cannot be reached.
     */
    public function delete(string $key): void;

    /** Where this driver stores things, for people to read. Never contains a secret. */
    public function label(): string;
}

require_once __DIR__ . '/local_driver.php';

/**
 * Whether two drivers store in the same place - one folder typed two ways (case on
 * Windows, a symlink, a bind mount, "/./"), or one bucket reached through two
 * endpoint spellings. Settings that look different can be one place, and a "move"
 * between them verifies every file against itself, after which removing the "old"
 * copies would delete the only ones.
 *
 * Answers one of three things, and only ever proves "different":
 *   'same'       a marker written through one is seen through the other
 *   'different'  a marker written through $a is seen through $a and NOT through $b,
 *                and the same the other way round
 *   'unknown'    anything else - an error, a timeout, a marker its own side cannot see
 *
 * TRAP: never treat 'unknown' as 'different'. The first version answered a bool and
 * returned false ("not the same") on any error, and the cleanup took that as leave
 * to delete the old copies - on a flaky gateway in front of ONE bucket, that deletes
 * the only copy of every file (PR #318 review).
 */
function storageLocationRelation(StorageDriver $a, StorageDriver $b): string
{
    $seen = function (StorageDriver $from, StorageDriver $to): ?bool {
        $key = '_freeitsm_probe/' . bin2hex(random_bytes(16)) . '.txt';
        $tmp = tempnam(sys_get_temp_dir(), 'sprb');
        try {
            file_put_contents($tmp, 'freeitsm storage identity probe');
            $from->putFile($key, $tmp);
            try {
                if ($from->size($key) === null) return null;     // cannot even see its own marker
                return $to->size($key) !== null;
            } finally {
                try { $from->delete($key); } catch (Throwable) {}
            }
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
    };
    $ab = $seen($a, $b);
    if ($ab === true) return 'same';
    if ($ab === null) return 'unknown';
    $ba = $seen($b, $a);
    if ($ba === true) return 'same';
    if ($ba === null) return 'unknown';
    return 'different';
}

/** True only when the two drivers are proved to be one place. */
function storageSameLocation(StorageDriver $a, StorageDriver $b): bool
{
    return storageLocationRelation($a, $b) === 'same';
}

/**
 * Why a storage server address may not be used, or null. The server sends requests
 * to whatever an administrator types; a link-local address is the cloud metadata
 * service, which hands out the server's own credentials. Private-network and
 * loopback addresses stay allowed - a MinIO on the LAN, or beside FreeITSM in Docker.
 *
 * @param string $ip  an IPv4 or IPv6 address
 */
function storageAddressRefusal(string $ip): ?string
{
    $bin = @inet_pton($ip);
    if ($bin === false) return 'That storage address could not be read.';
    if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xFF\xFF")) $bin = substr($bin, 12);   // ::ffff:a.b.c.d
    $bad = strlen($bin) === 4
        ? ($bin[0] === "\xA9" && $bin[1] === "\xFE") || $bin[0] === "\x00"                // 169.254/16, 0/8
        : (ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80)                          // fe80::/10
          || $bin === inet_pton('fd00:ec2::254')                                             // AWS metadata (IPv6)
          || $bin === str_repeat("\0", 16);                                                  // ::
    return $bad ? 'That storage address is not allowed: ' . $ip . ' is a link-local (cloud metadata) address.' : null;
}

/**
 * Every address $host resolves to (IPv4 and IPv6), checked. Throws StorageUnavailable
 * when it does not resolve or any address is refused; returns the first good address,
 * for CURLOPT_RESOLVE to pin, so the name cannot resolve somewhere else between this
 * check and the connection (DNS rebinding).
 */
function storageCheckedAddress(string $host): string
{
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            foreach (@dns_get_record($host, $type) ?: [] as $r) if (!empty($r[$field])) $ips[] = $r[$field];
        }
        if (!$ips) $ips = gethostbynamel($host) ?: [];      // hosts file, or a resolver dns_get_record cannot use
    }
    if (!$ips) throw new StorageUnavailable('Storage could not be reached: ' . $host . ' does not resolve.');
    foreach ($ips as $ip) {
        if (($why = storageAddressRefusal($ip)) !== null) throw new StorageUnavailable($why);
    }
    return $ips[0];
}

/**
 * The driver for a storage configuration.
 *
 * @param array  $cfg    ['driver' => 'local', 'root' => ...] or
 *                       ['driver' => 's3', 'endpoint', 'region', 'bucket', 'access_key_id', 'secret', 'prefix', 'path_style']
 * @param string $store  which store's bytes ('files'); S3 keeps each store under <prefix><store>/
 */
function storageDriver(array $cfg, string $store): StorageDriver
{
    if (($cfg['driver'] ?? 'local') === 's3') {
        require_once __DIR__ . '/s3_driver.php';
        return new S3Driver($cfg, $store);
    }
    return new LocalDriver((string)$cfg['root']);
}
