<?php
/**
 * FilesStorageService - where Files keeps its bytes, and moving them. The write
 * rules for Files -> Settings -> Storage, ONCE; api/files/settings.php only
 * passes input in and output out.
 *
 *   describe()  what the Storage tab shows - never the S3 secret
 *   test()      write, read back and delete a small file in an S3 bucket
 *   save()      a local folder or S3-compatible storage, plus the upload limit
 *
 * A change of LOCATION (another folder, local <-> S3, another bucket, endpoint
 * or prefix) starts a move: the current location is kept as
 * files_storage_previous, every stored path is queued in
 * storage_migration_items, and the storage_migrate job copies and verifies
 * them (includes/files/storage_migration.php). Until a file's copy is verified
 * it is still read from the old location (filesStoreFor). Nothing is deleted
 * from the old location until someone chooses "Remove old copies".
 *
 * Throws InvalidArgumentException with a message for the person saving.
 */

require_once __DIR__ . '/../files/storage.php';
require_once __DIR__ . '/../files/audit.php';
require_once __DIR__ . '/../storage/s3_driver.php';
require_once __DIR__ . '/../job_scheduler.php';

final class FilesStorageService
{
    /**
     * True when a submitted secret means "keep the saved one": empty, the page's
     * all-asterisk placeholder, or the "****abcd" mask of the saved secret. A NEW
     * secret that merely starts with "*" is a new secret (the shared
     * isMaskedNoChangeValue() would throw it away).
     */
    public static function secretUnchanged(string $submitted, string $stored): bool
    {
        return $submitted === '' || preg_match('/^\*+$/', $submitted) === 1
            || ($stored !== '' && hash_equals(maskSecret($stored), $submitted));
    }

    /**
     * Why an endpoint may not be used, or null. Test connection makes the server send
     * requests to whatever is typed; a link-local address is the cloud metadata
     * service, which hands out the server's own credentials. Private-network
     * addresses (a MinIO on the LAN) stay allowed.
     */
    private static function endpointRefusal(string $endpoint): ?string
    {
        $host = (string)parse_url($endpoint, PHP_URL_HOST);
        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            $bin = @inet_pton($ip);
            if ($bin === false) continue;
            $linkLocal = strlen($bin) === 4
                ? ($bin[0] === "\xA9" && $bin[1] === "\xFE") || $bin[0] === "\x00"            // 169.254/16, 0/8
                : (ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80)                     // fe80::/10
                  || $bin === inet_pton('fd00:ec2::254')                                        // AWS metadata (IPv6)
                  || (str_starts_with($bin, str_repeat("\0", 10) . "\xFF\xFF") && substr($bin, 12, 2) === "\xA9\xFE");
            if ($linkLocal) return 'That endpoint is not allowed: ' . $ip . ' is a link-local (cloud metadata) address.';
        }
        return null;
    }
    /** The Storage tab's data. The S3 secret is reported as set or not, never sent. */
    public static function describe(PDO $conn): array
    {
        $root  = rtrim(str_replace('\\', '/', filesSetting($conn, 'files_storage_root', filesDefaultStorageRoot())), '/');
        $usage = $conn->query("SELECT COUNT(DISTINCT storage_path) AS n, COALESCE(SUM(size_bytes), 0) AS bytes
                                 FROM (SELECT storage_path, MAX(size_bytes) AS size_bytes FROM files_versions GROUP BY storage_path) s")->fetch(PDO::FETCH_ASSOC);
        $driver = filesSetting($conn, 'files_storage_driver', 'local') === 's3' ? 's3' : 'local';
        $out = [
            'driver'         => $driver,
            'location'       => self::label(filesStorageConfig($conn)),
            'storage_root'   => $root,
            'default_root'   => filesDefaultStorageRoot(),
            'is_default'     => filesSetting($conn, 'files_storage_root', '') === '',
            'writable'       => is_dir($root) ? is_writable($root) : is_writable(dirname($root)),
            'max_upload_mb'  => (int)filesSetting($conn, 'files_max_upload_mb', (string)FILES_DEFAULT_MAX_UPLOAD_MB),
            'stored_files'   => (int)$usage['n'],
            'stored_bytes'   => (int)$usage['bytes'],
            'free_bytes'     => $driver === 'local' ? (@disk_free_space(is_dir($root) ? $root : dirname($root)) ?: null) : null,
            's3'             => [
                'endpoint'      => filesSetting($conn, 'files_s3_endpoint'),
                'region'        => filesSetting($conn, 'files_s3_region', 'us-east-1'),
                'bucket'        => filesSetting($conn, 'files_s3_bucket'),
                'access_key_id' => filesSetting($conn, 'files_s3_access_key_id'),
                'prefix'        => filesSetting($conn, 'files_s3_prefix'),
                'path_style'    => filesSetting($conn, 'files_s3_path_style', '0') === '1',
                'secret_set'    => filesSetting($conn, 'files_s3_secret') !== '',
            ],
            'migration'      => self::migrationSummary($conn),
            // A move only progresses when background jobs run; without the scheduler it
            // would sit at "0 of N" with no hint why. null = could not tell (a failed
            // lookup is not a "no": the page then says nothing rather than something false).
            'scheduler_running' => (function () use ($conn) { try { return jobSchedulerRunning($conn); } catch (Throwable) { return null; } })(),
        ];
        return $out;
    }

    /**
     * Writes, reads back, compares and deletes a 32-byte file in the bucket.
     *
     * @param array $cfg  endpoint, region, bucket, access_key_id, secret, prefix, path_style
     * @return array ['ok' => bool, 'step' => 'connect'|'write'|'read'|'compare'|'delete'|null, 'error' => ?string]
     */
    public static function test(array $cfg): array
    {
        $step = 'connect';
        $tmp  = tempnam(sys_get_temp_dir(), 'fst');
        $key  = '_freeitsm_test/' . bin2hex(random_bytes(16)) . '.txt';
        try {
            $refusal = self::endpointRefusal(s3NormaliseEndpoint((string)($cfg['endpoint'] ?? '')));
            if ($refusal !== null) throw new InvalidArgumentException($refusal);
            $d = new S3Driver(['driver' => 's3'] + $cfg, 'files');
            $bytes = random_bytes(32);
            file_put_contents($tmp, $bytes);
            $step = 'write';
            $d->putFile($key, $tmp);
            $step = 'read';
            $d->readTo($key, $tmp);
            $step = 'compare';
            if (file_get_contents($tmp) !== $bytes) throw new RuntimeException('The file read back was not the file written.');
            $step = 'delete';
            $d->delete($key);
            return ['ok' => true, 'step' => null, 'error' => null];
        } catch (Throwable $e) {
            if ($step === 'write' && str_starts_with($e->getMessage(), 'Storage could not be reached')) $step = 'connect';
            return ['ok' => false, 'step' => $step, 'error' => $e->getMessage()];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Saves the Storage tab.
     *
     * @param array $in  driver ('local'|'s3'), storage_root, max_upload_mb,
     *                   s3: {endpoint, region, bucket, access_key_id, secret (masked = keep), prefix, path_style}
     */
    public static function save(PDO $conn, int $analystId, array $in): void
    {
        $driver = ($in['driver'] ?? 'local') === 's3' ? 's3' : 'local';
        $mb = (int)($in['max_upload_mb'] ?? 0);
        if ($mb < 1 || $mb > 1024 * 1024) throw new InvalidArgumentException('The largest upload must be between 1 MB and 1 TB.');

        // The local folder: where files live (local), or where uploads are assembled (S3).
        $root = trim(str_replace('\\', '/', (string)($in['storage_root'] ?? '')));
        if ($root === '' || rtrim($root, '/') === filesDefaultStorageRoot()) {
            $root = '';
        } else {
            if (str_contains($root, '..')) throw new InvalidArgumentException('Give the folder as a full path, without "..".');
            if (!is_dir($root)) throw new InvalidArgumentException('That folder does not exist on the server. Create it first, then save.');
            if (!is_writable($root)) throw new InvalidArgumentException('The web server cannot write to that folder.');
            $root = rtrim($root, '/');
        }
        $rootAbs = $root === '' ? filesDefaultStorageRoot() : $root;

        $s3 = null;
        if ($driver === 's3') {
            if (!extension_loaded('curl')) throw new InvalidArgumentException('S3-compatible storage needs the PHP curl extension, which this server does not have.');
            $s = (array)($in['s3'] ?? []);
            $secret = (string)($s['secret'] ?? '');
            $stored = settingGetDecrypted($conn, 'files_s3_secret');
            if (self::secretUnchanged($secret, $stored)) $secret = $stored;
            $s3 = [
                'driver'        => 's3',
                'endpoint'      => s3NormaliseEndpoint((string)($s['endpoint'] ?? '')),
                'region'        => trim((string)($s['region'] ?? '')) ?: 'us-east-1',
                'bucket'        => trim((string)($s['bucket'] ?? '')),
                'access_key_id' => trim((string)($s['access_key_id'] ?? '')),
                'secret'        => $secret,
                'prefix'        => s3NormalisePrefix((string)($s['prefix'] ?? '')),
                'path_style'    => !empty($s['path_style']),
            ];
            foreach (['bucket' => 'the bucket', 'access_key_id' => 'the access key ID', 'secret' => 'the secret access key'] as $k => $what) {
                if ($s3[$k] === '') throw new InvalidArgumentException('Enter ' . $what . '.');
            }
            $t = self::test($s3);
            if (!$t['ok']) throw new InvalidArgumentException('Those S3 settings did not work (failed at ' . $t['step'] . '): ' . $t['error']);
        }

        $current = filesStorageConfig($conn);
        $next    = $s3 ?? ['driver' => 'local', 'root' => $rootAbs];
        $moving  = self::locationKey($current) !== self::locationKey($next)
                   // Different settings, same place (another spelling of one folder or bucket):
                   // nothing to move - and a "move" onto itself must never reach cleanup.
                   && !storageSameLocation(storageDriver($next, 'files'), storageDriver($current, 'files'));
        if ($moving && filesPreviousStorageConfig($conn) !== null) {
            throw new InvalidArgumentException('A move between storage locations is still running. Wait for it to finish (or remove the old copies) before changing again.');
        }

        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $queued = 0;
            if ($moving) {
                $conn->exec("DELETE FROM storage_migration_items WHERE store = 'files'");
                $queued = $conn->exec("INSERT INTO storage_migration_items (store, object_key, size_bytes, sha256, state, updated_datetime)
                                       SELECT 'files', storage_path, MAX(size_bytes), MAX(sha256), 'pending', UTC_TIMESTAMP()
                                         FROM files_versions GROUP BY storage_path");
                filesSaveSetting($conn, 'files_storage_previous', $queued > 0 ? encryptValue(json_encode($current)) : null);
            }
            filesSaveSetting($conn, 'files_storage_driver', $driver);
            filesSaveSetting($conn, 'files_storage_root', $root === '' ? null : $root);
            filesSaveSetting($conn, 'files_max_upload_mb', (string)$mb);
            if ($s3 !== null) {
                filesSaveSetting($conn, 'files_s3_endpoint', $s3['endpoint']);
                filesSaveSetting($conn, 'files_s3_region', $s3['region']);
                filesSaveSetting($conn, 'files_s3_bucket', $s3['bucket']);
                filesSaveSetting($conn, 'files_s3_access_key_id', $s3['access_key_id']);
                filesSaveSetting($conn, 'files_s3_secret', encryptValue($s3['secret']));
                filesSaveSetting($conn, 'files_s3_prefix', $s3['prefix']);
                filesSaveSetting($conn, 'files_s3_path_style', $s3['path_style'] ? '1' : '0');
            }
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        filesStorageReset();

        filesAudit($conn, $analystId, 'settings', ['path' => '(Settings: storage)'], [
            'storage'        => self::label($next),
            'storage_was'    => self::label($current),
            'moving_files'   => $moving ? $queued : 0,
            'max_upload_mb'  => $mb,
        ]);
    }

    /**
     * Called by an upload after its row is committed. If someone saved a new location
     * while the upload was being stored, its bytes went to the location it started
     * with - which the move's queue (built at save time) does not know about. Queue
     * it, so it is copied like the rest and read from the old place until then.
     *
     * @param array $usedCfg  filesStorageConfig() as it was when the bytes were stored
     */
    public static function queueIfMoved(PDO $conn, array $usedCfg, string $key, int $size, ?string $sha): void
    {
        filesStorageReset();
        if (self::locationKey($usedCfg) === self::locationKey(filesStorageConfig($conn))) return;
        $prev = filesPreviousStorageConfig($conn);
        if ($prev !== null && self::locationKey($prev) !== self::locationKey($usedCfg)) {
            // Cannot happen while a second move is refused during a move; log rather than guess.
            error_log("files: stored $key in a location that is neither the current nor the previous one");
            return;
        }
        if ($prev === null) filesSaveSetting($conn, 'files_storage_previous', encryptValue(json_encode($usedCfg)));
        $conn->prepare("INSERT IGNORE INTO storage_migration_items (store, object_key, size_bytes, sha256, state, updated_datetime)
                        VALUES ('files', ?, ?, ?, 'pending', UTC_TIMESTAMP())")->execute([$key, $size, $sha]);
        filesStorageReset();
    }

    /**
     * What an administrator can do with a running move:
     *   retry_failed  failed copies go back in the queue
     *   skip_missing  failed files whose OLD copy no longer exists (already lost before
     *                 the move) are set aside, so they cannot block the move for ever
     *   remove_old    once every file is copied: delete the old copies (the job does it)
     *   finish_keep   once every file is copied: end the move and keep the old copies
     *
     * @param string $action  one of the above
     */
    public static function migrationAction(PDO $conn, int $analystId, string $action): void
    {
        $m = self::migrationSummary($conn);
        if ($m === null) throw new InvalidArgumentException('No move between storage locations is running.');
        $copiedAll = $m['pending'] === 0 && $m['failed'] === 0;
        if ($action === 'retry_failed') {
            $conn->exec("UPDATE storage_migration_items SET state = 'pending', attempts = 0, last_error = NULL, updated_datetime = UTC_TIMESTAMP()
                          WHERE store = 'files' AND state = 'failed'");
        } elseif ($action === 'skip_missing') {
            $prev = filesPreviousStore($conn);
            $rows = $conn->query("SELECT id, object_key FROM storage_migration_items WHERE store = 'files' AND state = 'failed'")->fetchAll(PDO::FETCH_ASSOC);
            $skip = $conn->prepare("UPDATE storage_migration_items SET state = 'skipped', updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
            try {
                foreach ($rows as $r) if ($prev->size((string)$r['object_key']) === null) $skip->execute([$r['id']]);
            } catch (StorageUnavailable $e) {
                throw new InvalidArgumentException('The old location cannot be reached, so missing files cannot be told apart: ' . $e->getMessage());
            }
        } elseif ($action === 'remove_old') {
            if (!$copiedAll) throw new InvalidArgumentException('Old copies can only be removed once every file has been copied.');
            filesSaveSetting($conn, 'files_storage_cleanup', '1');
        } elseif ($action === 'finish_keep') {
            if (!$copiedAll) throw new InvalidArgumentException('The move can only be finished once every file has been copied.');
            filesSaveSetting($conn, 'files_storage_previous', null);
            filesSaveSetting($conn, 'files_storage_cleanup', null);
            $conn->exec("DELETE FROM storage_migration_items WHERE store = 'files'");
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        filesStorageReset();
        filesAudit($conn, $analystId, 'settings', ['path' => '(Settings: storage)'], ['storage_move' => $action, 'from' => $m['from_label']]);
    }

    /** Progress of the running move, or null when none is running. */
    public static function migrationSummary(PDO $conn): ?array
    {
        $prev = filesPreviousStorageConfig($conn);
        if ($prev === null) return null;
        $counts = $conn->query("SELECT state, COUNT(*) FROM storage_migration_items WHERE store = 'files' GROUP BY state")->fetchAll(PDO::FETCH_KEY_PAIR);
        $m = ['pending' => 0, 'verified' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0];
        foreach ($counts as $state => $n) if (isset($m[$state])) $m[$state] = (int)$n;
        $m['total'] = array_sum($m);
        $m['from_label'] = self::label($prev);
        $m['to_label'] = self::label(filesStorageConfig($conn));
        $m['cleanup_running'] = filesSetting($conn, 'files_storage_cleanup') === '1';
        $m['last_error'] = $m['failed'] > 0
            ? (string)$conn->query("SELECT last_error FROM storage_migration_items WHERE store = 'files' AND state = 'failed' ORDER BY updated_datetime DESC LIMIT 1")->fetchColumn()
            : null;
        return $m;
    }

    /** What identifies a location: moving between two different ones moves the files. */
    private static function locationKey(array $cfg): string
    {
        if (($cfg['driver'] ?? 'local') === 's3') {
            return 's3|' . rtrim((string)$cfg['endpoint'], '/') . '|' . $cfg['bucket'] . '|' . s3NormalisePrefix((string)($cfg['prefix'] ?? ''));
        }
        return 'local|' . rtrim(str_replace('\\', '/', (string)$cfg['root']), '/');
    }

    /** A location for people to read; never contains a secret. */
    private static function label(array $cfg): string
    {
        if (($cfg['driver'] ?? 'local') === 's3') {
            return 's3://' . $cfg['bucket'] . '/' . s3NormalisePrefix((string)($cfg['prefix'] ?? '')) . 'files/ (' . rtrim((string)$cfg['endpoint'], '/') . ')';
        }
        return rtrim(str_replace('\\', '/', (string)$cfg['root']), '/');
    }
}
