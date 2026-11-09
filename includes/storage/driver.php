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
 * True when two drivers turn out to store in the SAME place - one folder typed two
 * ways (case on Windows, a symlink, a bind mount, "/./"), or one bucket reached
 * through two endpoint spellings. Settings that look different can be one place,
 * and a "move" between them verifies every file against itself, after which
 * removing the "old" copies would delete the only ones.
 *
 * Writes a small marker through $a and looks for it through $b. Any failure
 * answers false (could not prove they are the same).
 */
function storageSameLocation(StorageDriver $a, StorageDriver $b): bool
{
    $key = '_freeitsm_probe/' . bin2hex(random_bytes(16)) . '.txt';
    $tmp = tempnam(sys_get_temp_dir(), 'sprb');
    try {
        file_put_contents($tmp, 'freeitsm storage identity probe');
        $a->putFile($key, $tmp);
        try {
            return $b->size($key) !== null;
        } finally {
            try { $a->delete($key); } catch (Throwable) {}
        }
    } catch (Throwable) {
        return false;
    } finally {
        @unlink($tmp);
    }
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
