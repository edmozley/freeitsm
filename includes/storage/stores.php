<?php
/**
 * The stores FreeITSM keeps bytes in, besides Files (#284): ticket attachments, change
 * attachments, documents, screen recordings and war-room attachments. Each one lives in
 * its folder on the server or, when System -> Storage switches it, in the S3-compatible
 * bucket Files uses (one connection, one bucket, a prefix per store).
 *
 * Callers never build a path to these folders themselves: they ask this layer, by store
 * id and KEY - the value the database row already holds:
 *
 *   tickets     tickets/attachments            email_attachments.file_path   {floor(id/1000)}/{id}/{name}
 *   changes     change-management/attachments  change_attachments.file_path  {changeId}/{name}
 *   documents   uploads/documents              documents.storage_key         {name}   (rows may share one)
 *   recordings  recordings                     ticket_recordings.file_path minus "recordings/"
 *   warroom     war-room/attachments           warroom_attachments.stored_name
 *
 * On a server folder every operation does what the code did before: uploads go through
 * uploadStoreFile()/uploadStoreBytes() into the same folder (guard files included), and
 * reads come from the same file. In S3, an upload is checked the same way in
 * <folder>/_staging, sent to the bucket and the staged copy removed.
 *
 * While a store is MOVING (storage_<store>_previous is set): writes go to the new place,
 * reads try the new place and then the old, deletes remove both. See
 * includes/storage/store_migration.php.
 */

require_once __DIR__ . '/driver.php';
require_once __DIR__ . '/../uploads.php';
require_once __DIR__ . '/../encryption.php';

/** Store id => folder relative to the app root. */
const STORAGE_STORES = [
    'tickets'    => 'tickets/attachments',
    'changes'    => 'change-management/attachments',
    'documents'  => 'uploads/documents',
    'recordings' => 'recordings',
    'warroom'    => 'war-room/attachments',
];

/** Where an upload to a store kept in S3 is checked before it is sent. */
const STORAGE_STAGING_DIR = '_staging';

/** Per-request caches; storeReset() empties them (after a setting changes, and in tests). */
final class StoreCache
{
    public static array $settings = [];
    /** @var array<string, array{active?: StorageDriver, previous?: ?StorageDriver}> */
    public static array $drivers = [];
    /** Test hook: store id => local folder to use instead of the app's. */
    public static array $roots = [];
    /** Test hook: fn(array $cfg, string $store): StorageDriver - instead of storageDriver(). */
    public static ?Closure $factory = null;
}

/** The driver for a store's configuration (storageDriver(), or the test hook). */
function storeMakeDriver(array $cfg, string $store): StorageDriver
{
    return StoreCache::$factory ? (StoreCache::$factory)($cfg, $store) : storageDriver($cfg, $store);
}

function storeReset(): void
{
    StoreCache::$settings = [];
    StoreCache::$drivers = [];
}

function storeCheckId(string $store): void
{
    if (!isset(STORAGE_STORES[$store])) throw new InvalidArgumentException("Unknown store: $store");
}

/** The store's folder on the server (absolute, forward slashes, no trailing slash). */
function storeLocalRoot(string $store): string
{
    storeCheckId($store);
    if (isset(StoreCache::$roots[$store])) return rtrim(str_replace('\\', '/', StoreCache::$roots[$store]), '/');
    return str_replace('\\', '/', dirname(__DIR__, 2)) . '/' . STORAGE_STORES[$store];
}

/**
 * A key a row may hold: relative, forward slashes, no empty, "." or ".." segment, no NUL.
 * ".." INSIDE a name ("report..final.pdf") is fine; as a whole segment it would climb out.
 */
function storeKeyIsValid(string $key): bool
{
    if ($key === '' || $key[0] === '/' || str_contains($key, '\\') || str_contains($key, "\0")) return false;
    foreach (explode('/', $key) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') return false;
    }
    return true;
}

function storeCheckKey(string $key): void
{
    if (!storeKeyIsValid($key)) throw new InvalidArgumentException('Bad storage key.');
}

/** A setting, cached per request; '' when absent. */
function storeSetting(PDO $conn, string $key): string
{
    if (!array_key_exists($key, StoreCache::$settings)) {
        try {
            $s = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
            $s->execute([$key]);
            $v = $s->fetchColumn();
            StoreCache::$settings[$key] = ($v === false || $v === null) ? '' : (string)$v;
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') throw $e;
            StoreCache::$settings[$key] = '';   // before Database Verification: everything local
        }
    }
    return StoreCache::$settings[$key];
}

function storeSaveSetting(PDO $conn, string $key, ?string $value): void
{
    if ($value === null) {
        $conn->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute([$key]);
    } else {
        $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
    }
    unset(StoreCache::$settings[$key]);
}

/**
 * The one S3 connection (System -> Storage), or null when none is set. Until it is saved
 * there, the connection Files was given in #283 (files_s3_*) is used.
 *
 * @return ?array ['driver' => 's3', endpoint, region, bucket, access_key_id, secret, prefix, path_style]
 */
function storageS3Connection(PDO $conn): ?array
{
    $p = storeSetting($conn, 'storage_s3_bucket') !== '' ? 'storage_s3_' : 'files_s3_';
    $bucket = storeSetting($conn, $p . 'bucket');
    if ($bucket === '') return null;
    $secret = storeSetting($conn, $p . 'secret');
    if (str_starts_with($secret, 'ENC:')) {
        try { $secret = (string)decryptValue($secret); } catch (Throwable $e) { $secret = ''; }
    }
    return [
        'driver'        => 's3',
        'endpoint'      => storeSetting($conn, $p . 'endpoint'),
        'region'        => storeSetting($conn, $p . 'region') ?: 'us-east-1',
        'bucket'        => $bucket,
        'access_key_id' => storeSetting($conn, $p . 'access_key_id'),
        'secret'        => $secret,
        'prefix'        => storeSetting($conn, $p . 'prefix'),
        'path_style'    => storeSetting($conn, $p . 'path_style') === '1',
    ];
}

/** 'local' or 's3': where the store's switch points. */
function storeLocation(PDO $conn, string $store): string
{
    storeCheckId($store);
    return storeSetting($conn, "storage_{$store}_location") === 's3' ? 's3' : 'local';
}

/** Where the store keeps bytes now (writes go here). */
function storeConfig(PDO $conn, string $store): array
{
    if (storeLocation($conn, $store) === 's3') {
        $c = storageS3Connection($conn);
        if ($c === null) throw new StorageUnavailable('This store is set to S3-compatible storage, but no connection is saved on System -> Storage.');
        return $c;
    }
    return ['driver' => 'local', 'root' => storeLocalRoot($store)];
}

/** Where the store kept bytes before, while a move runs; null otherwise. */
function storePreviousConfig(PDO $conn, string $store): ?array
{
    storeCheckId($store);
    $raw = storeSetting($conn, "storage_{$store}_previous");
    if ($raw === '') return null;
    try {
        $cfg = json_decode((string)decryptValue($raw), true);
    } catch (Throwable $e) {
        throw new StorageUnavailable('The previous storage location could not be decrypted (has the encryption key changed?).');
    }
    if (!is_array($cfg) || !isset($cfg['driver'])) return null;
    if ($cfg['driver'] === 'local') $cfg['root'] = storeLocalRoot($store);   // never a path taken from the database
    return $cfg;
}

function storeActive(PDO $conn, string $store): StorageDriver
{
    return StoreCache::$drivers[$store]['active'] ??= storeMakeDriver(storeConfig($conn, $store), $store);
}

function storePrevious(PDO $conn, string $store): ?StorageDriver
{
    if (!array_key_exists('previous', StoreCache::$drivers[$store] ?? [])) {
        $cfg = storePreviousConfig($conn, $store);
        StoreCache::$drivers[$store]['previous'] = $cfg ? storeMakeDriver($cfg, $store) : null;
    }
    return StoreCache::$drivers[$store]['previous'];
}

/** True when $d is the store's own folder on this server, so a path into it can be used directly. */
function storeIsOwnFolder(StorageDriver $d, string $store): bool
{
    return $d instanceof LocalDriver && $d->root() === storeLocalRoot($store);
}

/** True when the store is a server folder and is not moving: bytes are where they always were. */
function storeIsPlainLocal(PDO $conn, string $store): bool
{
    return storeActive($conn, $store) instanceof LocalDriver && storePrevious($conn, $store) === null;
}

/**
 * The driver holding $key now: the active one, or - during a move, when the key has not
 * reached the new place yet - the previous one. Throws StorageNotFound when neither has it.
 */
function storeHolder(PDO $conn, string $store, string $key): StorageDriver
{
    storeCheckKey($key);
    $active = storeActive($conn, $store);
    $prev = storePrevious($conn, $store);
    $d = $active;
    if ($prev !== null && $active->size($key) === null) {
        if ($prev->size($key) === null) throw new StorageNotFound('Stored file not found.');
        $d = $prev;
    }
    storeCheckContained($d, $store, $key);
    return $d;
}

/**
 * On the store's own folder, the file must RESOLVE inside it: realpath() follows a
 * symlink planted in the folder, which the key check alone cannot see. (What the
 * download endpoints each checked by hand before.) A file that escapes is "not there".
 */
function storeCheckContained(StorageDriver $d, string $store, string $key): void
{
    if (!storeIsOwnFolder($d, $store)) return;
    $file = storeLocalRoot($store) . '/' . $key;
    if (!file_exists($file)) return;
    $realRoot = realpath(storeLocalRoot($store));
    $realFile = realpath($file);
    if ($realRoot === false || $realFile === false
        || strncmp($realFile, $realRoot . DIRECTORY_SEPARATOR, strlen($realRoot) + 1) !== 0) {
        error_log("storage: refused {$store}/{$key}: it resolves outside the store's folder");
        throw new StorageNotFound('Stored file not found.');
    }
}

// ── Writing ──────────────────────────────────────────────────────────────────

/** The folder inside the tickets store for an email's attachments: {floor(id/1000)}/{id}. */
function storeTicketDir(int $emailId): string
{
    return intdiv($emailId, 1000) . '/' . $emailId;
}

/** "dir/name", or "name" for a flat store. */
function storeJoinKey(string $dir, string $name): string
{
    $dir = trim(str_replace('\\', '/', $dir), '/');
    return $dir === '' ? $name : $dir . '/' . $name;
}

/** Where an upload is checked before it is stored: the folder itself, or its staging area. */
function storeWorkDir(PDO $conn, string $store, string $dir): string
{
    if (storeActive($conn, $store) instanceof LocalDriver) {
        $d = trim(str_replace('\\', '/', $dir), '/');
        return storeLocalRoot($store) . ($d === '' ? '' : '/' . $d);
    }
    return storeLocalRoot($store) . '/' . STORAGE_STAGING_DIR;
}

/**
 * Sends a checked file from the staging area to the store (S3), and removes it.
 * A failure removes the staged file too and throws StorageUnavailable.
 */
function storeFinishStaged(PDO $conn, string $store, string $key, string $path): void
{
    try {
        storeActive($conn, $store)->putFile($key, $path, true);
    } finally {
        if (is_file($path)) @unlink($path);
    }
}

/**
 * uploadStoreFile() into a store. $dir is the folder inside the store ('' for a flat store).
 *
 * @return array uploadStoreFile()'s result plus 'key' and 'sha256'; 'path' is the file on the server when
 *               the store is a server folder, and null when it went to S3
 * @throws Exception as uploadStoreFile does; StorageUnavailable when S3 cannot be reached
 */
function storeSaveUpload(PDO $conn, string $store, string $dir, array $file, array $allowed = UPLOAD_TYPES_ATTACHMENT, ?int $maxBytes = null): array
{
    $local = storeActive($conn, $store) instanceof LocalDriver;
    $r = uploadStoreFile($file, storeWorkDir($conn, $store, $dir), $allowed, $maxBytes);
    $r['key'] = storeJoinKey($dir, $r['stored_name']);
    storeCheckKey($r['key']);
    $r['sha256'] = hash_file('sha256', $r['path']);   // callers record it; read before the file can leave
    if (!$local) {
        storeFinishStaged($conn, $store, $r['key'], $r['path']);
        $r['path'] = null;
    }
    return $r;
}

/**
 * uploadStoreBytes() into a store. Same result, plus 'key' (null when not stored) and
 * 'path' null when it went to S3. A store that cannot be reached is reported like a
 * failed write ('stored' false, a reason), as uploadStoreBytes() reports a full disk.
 */
function storeSaveBytes(PDO $conn, string $store, string $dir, string $bytes, string $originalName,
                        string $policy = ATTACHMENT_POLICY_STORE, array $allowed = UPLOAD_TYPES_ATTACHMENT, ?int $maxBytes = null): array
{
    try {
        $local = storeActive($conn, $store) instanceof LocalDriver;
    } catch (StorageUnavailable $e) {
        return ['stored' => false, 'quarantined' => false, 'stored_name' => null, 'path' => null, 'key' => null,
                'original_name' => uploadCleanName($originalName), 'size' => strlen($bytes), 'mime' => 'application/octet-stream',
                'reason' => 'storage could not be reached'];
    }
    $r = uploadStoreBytes($bytes, $originalName, storeWorkDir($conn, $store, $dir), $policy, $allowed, $maxBytes);
    $r['key'] = $r['stored'] ? storeJoinKey($dir, (string)$r['stored_name']) : null;
    if ($r['stored'] && !$local) {
        try {
            storeFinishStaged($conn, $store, $r['key'], $r['path']);
            $r['path'] = null;
        } catch (Throwable $e) {
            error_log("storage: could not store {$store}/{$r['key']}: " . $e->getMessage());
            return array_merge($r, ['stored' => false, 'quarantined' => false, 'stored_name' => null, 'path' => null, 'key' => null,
                                    'reason' => 'storage could not be reached']);
        }
    }
    return $r;
}

/** Stores a local file under $key (a restore, a move back, a generated file). */
function storePutFile(PDO $conn, string $store, string $key, string $localPath, bool $move = false): void
{
    storeCheckKey($key);
    storeActive($conn, $store)->putFile($key, $localPath, $move);
}

// ── Reading ──────────────────────────────────────────────────────────────────

/** Size in bytes, or null when the file is in neither place. Throws StorageUnavailable. */
function storeSize(PDO $conn, string $store, string $key): ?int
{
    if (!storeKeyIsValid($key)) return null;
    try {
        return storeHolder($conn, $store, $key)->size($key);
    } catch (StorageNotFound $e) {
        return null;
    }
}

/** The whole file as a string. @throws StorageNotFound|StorageUnavailable */
function storeGetString(PDO $conn, string $store, string $key): string
{
    $d = storeHolder($conn, $store, $key);
    if (storeIsOwnFolder($d, $store)) {
        $p = storeLocalRoot($store) . '/' . $key;
        if (!is_file($p)) throw new StorageNotFound('Stored file not found.');
        $s = @file_get_contents($p);
        if ($s === false) throw new StorageUnavailable('The stored file could not be read.');
        return $s;
    }
    $out = '';
    $size = $d->size($key);
    if ($size === null) throw new StorageNotFound('Stored file not found.');
    if ($size > 0) $d->readRange($key, 0, $size - 1, function (string $c) use (&$out) { $out .= $c; return true; });
    return $out;
}

/**
 * A path on the server a library can open (text extraction, a CURLFile, an email part).
 * On a server folder: the file itself (temp false). In S3: a temporary copy (temp true) -
 * hand the result to storeReleaseLocal() when done.
 *
 * @return array{path: string, temp: bool}
 * @throws StorageNotFound|StorageUnavailable
 */
function storeLocalPath(PDO $conn, string $store, string $key): array
{
    $d = storeHolder($conn, $store, $key);
    $direct = storeLocalRoot($store) . '/' . $key;
    if (storeIsOwnFolder($d, $store)) {
        if (!is_file($direct)) throw new StorageNotFound('Stored file not found.');
        return ['path' => $direct, 'temp' => false];
    }
    $ext = pathinfo($key, PATHINFO_EXTENSION);
    $tmp = tempnam(sys_get_temp_dir(), 'fst');
    $path = $tmp . ($ext !== '' ? '.' . preg_replace('/[^A-Za-z0-9]/', '', $ext) : '');
    if ($path !== $tmp) @rename($tmp, $path);
    try {
        $d->readTo($key, $path);
    } catch (Throwable $e) {
        @unlink($path);
        throw $e;
    }
    return ['path' => $path, 'temp' => true];
}

/** Removes a temporary copy from storeLocalPath(); a direct path is left alone. */
function storeReleaseLocal(?array $lp): void
{
    if ($lp !== null && !empty($lp['temp']) && is_file($lp['path'])) @unlink($lp['path']);
}

/**
 * Streams bytes $start..$end of $key to the output, calling $begin() (send headers) only
 * once the store has answered - so a missing or unreachable store still gets a proper
 * status from the caller.
 *
 * @return int 0 when sent; otherwise the status the caller should answer: 404 (not a key),
 *             410 (not there), 503 (store unreachable)
 */
function storeServe(PDO $conn, string $store, string $key, int $start, int $end, callable $begin): int
{
    if (!storeKeyIsValid($key)) return 404;
    $started = false;
    try {
        storeHolder($conn, $store, $key)->readRange($key, $start, $end, function (string $chunk) use (&$started, $begin) {
            if (!$started) { $started = true; $begin(); }
            echo $chunk;
            flush();
            return !connection_aborted();
        });
        if (!$started) $begin();
        return 0;
    } catch (StorageNotFound $e) {
        if ($started) return 0;
        return 410;
    } catch (StorageUnavailable $e) {
        error_log("storage: {$store} unavailable reading {$key}: " . $e->getMessage());
        return $started ? 0 : 503;
    }
}

/**
 * Parses a single "bytes=a-b" Range header against $size.
 *
 * @return ?array [start, end] or null for "the whole file"; false-like [-1, -1] when unsatisfiable
 */
function storeParseRange(?string $header, int $size): ?array
{
    if ($header === null || $header === '' || $size <= 0) return null;
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) || ($m[1] === '' && $m[2] === '')) return null;
    if ($m[1] === '') {                    // the last N bytes
        $n = (int)$m[2];
        if ($n <= 0) return [-1, -1];
        return [max(0, $size - $n), $size - 1];
    }
    $start = (int)$m[1];
    $end = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
    if ($start >= $size || $start > $end) return [-1, -1];
    return [$start, $end];
}

// ── Removing, renaming, listing ──────────────────────────────────────────────

/**
 * Deletes the file - from both places while the store is moving. One already gone is not
 * an error. Returns false when it could not be removed (logged), as the @unlink calls it
 * replaces returned.
 */
function storeDelete(PDO $conn, string $store, string $key): bool
{
    if (!storeKeyIsValid($key)) return false;
    $ok = true;
    try {
        $drivers = [storeActive($conn, $store)];
        $prev = storePrevious($conn, $store);
        if ($prev !== null) $drivers[] = $prev;
    } catch (Throwable $e) {
        error_log("storage: {$store} delete {$key}: " . $e->getMessage());
        return false;
    }
    foreach ($drivers as $d) {
        try {
            $d->delete($key);
        } catch (Throwable $e) {
            error_log("storage: {$store} delete {$key}: " . $e->getMessage());
            $ok = false;
        }
    }
    return $ok;
}

/**
 * Renames a file within the store (a recording claimed by its ticket; a risky extension
 * renamed to .bin). On a server folder this is a rename(); in S3 a copy and a delete.
 * @throws StorageNotFound|StorageUnavailable|RuntimeException
 */
function storeRename(PDO $conn, string $store, string $from, string $to): void
{
    storeCheckKey($from);
    storeCheckKey($to);
    $active = storeActive($conn, $store);
    $holder = storeHolder($conn, $store, $from);
    $root = storeLocalRoot($store);
    if ($holder === $active && storeIsOwnFolder($active, $store)) {
        $src = $root . '/' . $from;
        $dst = $root . '/' . $to;
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('The folder could not be created.');
        if (!@rename($src, $dst)) throw new RuntimeException('The file could not be renamed.');
        return;
    }
    $lp = storeLocalPath($conn, $store, $from);
    try {
        $active->putFile($to, $lp['path'], false);
    } finally {
        storeReleaseLocal($lp);
    }
    storeDelete($conn, $store, $from);
}

/**
 * Every file in the store: key => [size, mtime]. While a store moves, both places
 * (the new place wins). Guard files, dot files and the staging area are not files.
 *
 * @throws StorageUnavailable
 */
function storeList(PDO $conn, string $store): array
{
    $out = [];
    $drivers = [];
    $prev = storePrevious($conn, $store);
    if ($prev !== null) $drivers[] = $prev;
    $drivers[] = storeActive($conn, $store);
    foreach ($drivers as $d) {
        foreach ($d instanceof StorageLister ? $d->listObjects('') : [] as $k => $o) {
            if (!storeListedKeyIsFile($k)) continue;
            $out[$k] = $o;
        }
    }
    ksort($out, SORT_STRING);
    return $out;
}

/** False for guard files, anything starting with a dot, the staging area and probes. */
function storeListedKeyIsFile(string $key): bool
{
    $name = basename($key);
    if ($name === '' || $name[0] === '.' || strcasecmp($name, 'web.config') === 0) return false;
    $first = explode('/', $key, 2)[0];
    return !in_array($first, [STORAGE_STAGING_DIR, '_freeitsm_probe', '_freeitsm_test'], true) && storeKeyIsValid($key);
}
