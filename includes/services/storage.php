<?php
/**
 * StorageService - System -> Storage (#284): the install's one S3-compatible connection,
 * and a switch per store (ticket attachments, change attachments, documents, screen
 * recordings, war-room attachments) between its server folder and that bucket. The write
 * rules, ONCE; api/system/storage.php only passes input in and output out.
 *
 *   describe()          what the page shows - never the secret
 *   test()              write, read back and delete a small file through a connection
 *   saveConnection()    the connection (tested before it is saved)
 *   setLocation()       flip a store's switch; starts its move (includes/storage/store_migration.php)
 *   moveAction()        retry / skip missing / remove old copies / finish for a running move
 *
 * Files keeps its own switch on Files -> Settings -> Storage; it uses this connection.
 * Throws InvalidArgumentException with a message for the person saving.
 */

require_once __DIR__ . '/../storage/stores.php';
require_once __DIR__ . '/../storage/store_migration.php';
require_once __DIR__ . '/files_storage.php';

final class StorageService
{
    /** Each store's rows: how many files and bytes it holds, for the page. */
    private const USAGE_SQL = [
        'tickets'    => 'SELECT COUNT(*), COALESCE(SUM(file_size), 0) FROM email_attachments',
        'changes'    => 'SELECT COUNT(*), COALESCE(SUM(file_size), 0) FROM change_attachments',
        'documents'  => "SELECT COUNT(DISTINCT storage_key), COALESCE(SUM(size_bytes), 0) FROM documents WHERE kind = 'file' AND storage_key IS NOT NULL",
        'recordings' => 'SELECT COUNT(*), COALESCE(SUM(file_size), 0) FROM ticket_recordings',
        'warroom'    => 'SELECT COUNT(*), COALESCE(SUM(size_bytes), 0) FROM warroom_attachments',
    ];

    public static function describe(PDO $conn): array
    {
        storeReset();
        $c = storageS3Connection($conn);
        $stores = [];
        foreach (array_keys(STORAGE_STORES) as $s) {
            $usage = self::usage($conn, self::USAGE_SQL[$s]);
            $stores[] = [
                'store'    => $s,
                'location' => storeLocation($conn, $s),
                'files'    => $usage[0],
                'bytes'    => $usage[1],
                'folder'   => STORAGE_STORES[$s],
                'move'     => storeMoveSummary($conn, $s),
            ];
        }
        $filesUsage = self::usage($conn, "SELECT COUNT(DISTINCT storage_path), COALESCE(SUM(size_bytes), 0)
                                          FROM (SELECT storage_path, MAX(size_bytes) AS size_bytes FROM files_versions GROUP BY storage_path) s");
        return [
            'connection' => $c === null ? null : [
                'endpoint'      => $c['endpoint'],
                'region'        => $c['region'],
                'bucket'        => $c['bucket'],
                'access_key_id' => $c['access_key_id'],
                'prefix'        => $c['prefix'],
                'path_style'    => $c['path_style'],
                'secret_set'    => $c['secret'] !== '',
                'secret'        => $c['secret'] !== '' ? maskSecret($c['secret']) : '',
                'sse'           => $c['sse'] ?? '',
                // Plain http to a public address (storage series S1): shown as a warning.
                'insecure'      => storageEndpointInsecure((string)$c['endpoint']),
            ],
            // Saved here, or still the one Files was given (files_s3_*) until it is saved here.
            'connection_source' => $c === null ? null : (storeSetting($conn, 'storage_s3_bucket') !== '' ? 'storage' : 'files'),
            // The place (endpoint, bucket, prefix) cannot change while anything is kept there.
            'connection_in_use' => self::connectionInUse($conn),
            'stores'     => $stores,
            'files'      => [
                'location' => filesSetting($conn, 'files_storage_driver', 'local') === 's3' ? 's3' : 'local',
                'files'    => $filesUsage[0],
                'bytes'    => $filesUsage[1],
                'moving'   => filesPreviousStorageConfig($conn) !== null,
            ],
            'curl'       => extension_loaded('curl'),
            'scheduler_running' => (function () use ($conn) { try { return jobSchedulerRunning($conn); } catch (Throwable) { return null; } })(),
        ];
    }

    /**
     * Writes, reads back and deletes a small file through a connection (Files' tested
     * check, with its refusal of link-local / cloud-metadata endpoints).
     *
     * @param array $in  endpoint, region, bucket, access_key_id, secret (masked = the saved one), prefix, path_style
     */
    public static function test(PDO $conn, array $in, int $by): array
    {
        if ($by < 1) throw new InvalidArgumentException('Not signed in.');
        try {
            $cfg = self::connectionFromInput($conn, $in);
            $t = FilesStorageService::test($cfg);
        } catch (InvalidArgumentException $e) {
            $cfg = null;
            $t = ['ok' => false, 'step' => 'connect', 'error' => $e->getMessage()];
        }
        // Storage series S4: a failed Test is audited - where it pointed and why it failed,
        // never the secret - so repeated tries against odd addresses leave a trace.
        if (!$t['ok']) {
            $secret = (string)($cfg['secret'] ?? ($in['secret'] ?? ''));
            $error  = (string)$t['error'];
            if ($secret !== '') $error = str_replace($secret, '***', $error);
            storeMoveAudit($conn, $by, 'connection', ['test_failed' => [
                'endpoint' => mb_substr((string)($cfg['endpoint'] ?? ($in['endpoint'] ?? '')), 0, 200),
                'bucket'   => mb_substr((string)($cfg['bucket'] ?? ($in['bucket'] ?? '')), 0, 100),
                'step'     => (string)$t['step'],
                'error'    => mb_substr($error, 0, 300),
            ]]);
        }
        return $t;
    }

    /** Saves the connection once it passes test(). The place cannot change while anything is kept there. */
    public static function saveConnection(PDO $conn, array $in, int $by): void
    {
        if ($by < 1) throw new InvalidArgumentException('Not signed in.');
        $cfg = self::connectionFromInput($conn, $in);
        $old = storageS3Connection($conn);
        // A move out of S3 reads the old place with the keys it started with; rotate them at
        // the provider and every file not yet copied is stranded. So the keys wait until it ends.
        if ($old !== null && self::credentialsKey($old) !== self::credentialsKey($cfg) && self::moveReadsS3($conn)) {
            throw new InvalidArgumentException('A move is still reading files from the bucket with the current keys. Change the keys, '
                . 'secret, region or path style once that move has finished (Remove or Finish).');
        }
        $t = FilesStorageService::test($cfg);
        if (!$t['ok']) throw new InvalidArgumentException('Those S3 settings did not work (failed at ' . $t['step'] . '): ' . $t['error']);

        $placeChanged = $old !== null && self::placeKey($old) !== self::placeKey($cfg);
        if ($placeChanged && self::connectionInUse($conn)) {
            throw new InvalidArgumentException('Files are kept in that bucket now. To use another endpoint, bucket or prefix, move every store '
                . '(and Files) back to the server folder first. The keys, region and path style can be changed whenever no move is reading from the bucket.');
        }

        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            foreach (['endpoint', 'region', 'bucket', 'access_key_id', 'prefix'] as $k) storeSaveSetting($conn, "storage_s3_$k", (string)$cfg[$k]);
            storeSaveSetting($conn, 'storage_s3_secret', encryptValue($cfg['secret']));
            storeSaveSetting($conn, 'storage_s3_path_style', $cfg['path_style'] ? '1' : '0');
            storeSaveSetting($conn, 'storage_s3_sse', $cfg['sse'] === 'AES256' ? 'AES256' : null);
            // The connection Files was given before (#283) lives here from now on.
            foreach (['endpoint', 'region', 'bucket', 'access_key_id', 'secret', 'prefix', 'path_style'] as $k) storeSaveSetting($conn, "files_s3_$k", null);
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        storeReset();
        filesStorageReset();
        storeMoveAudit($conn, $by, 'connection', ['saved' => 's3://' . $cfg['bucket'] . '/' . $cfg['prefix'] . ' (' . $cfg['endpoint'] . ')']);
        if ($old === null || $placeChanged) {
            require_once __DIR__ . '/../storage/notify.php';
            storageNotifyAdmins($conn, 'S3 storage connection saved', 'The S3-compatible connection on System -> Storage now points at '
                . 's3://' . $cfg['bucket'] . '/' . $cfg['prefix'] . ' (' . $cfg['endpoint'] . ').', 'storage', $by);
        }
    }

    /** Points a store at its server folder or the bucket; starts the move. Returns how many files will move. */
    public static function setLocation(PDO $conn, string $store, string $to, int $by): int
    {
        if ($by < 1) throw new InvalidArgumentException('Not signed in.');
        if (!isset(STORAGE_STORES[$store])) throw new InvalidArgumentException('Unknown store.');
        if ($to === 's3' && !extension_loaded('curl')) throw new InvalidArgumentException('S3-compatible storage needs the PHP curl extension, which this server does not have.');
        if ($to === 's3') self::adoptFilesConnection($conn);
        try {
            return storeMoveStart($conn, $store, $to, $by);
        } catch (StorageUnavailable $e) {
            throw new InvalidArgumentException('The current location cannot be read, so its files cannot be listed: ' . $e->getMessage());
        }
    }

    /** retry_failed | skip_missing | remove_old | finish_keep, for a store's running move. */
    public static function moveAction(PDO $conn, string $store, string $action, int $by): void
    {
        if ($by < 1) throw new InvalidArgumentException('Not signed in.');
        if (!isset(STORAGE_STORES[$store])) throw new InvalidArgumentException('Unknown store.');
        storeMoveAction($conn, $store, $action, $by);
    }

    /**
     * A store going to S3 on the connection Files was given (#283) makes it the shared one,
     * as saving it here would: from then on the Files tab shows it read-only and its place is
     * locked while in use - the Files tab can no longer point the stores at another bucket.
     * The values are copied as stored (the secret stays encrypted).
     */
    private static function adoptFilesConnection(PDO $conn): void
    {
        if (storeSetting($conn, 'storage_s3_bucket') !== '' || storeSetting($conn, 'files_s3_bucket') === '') return;
        $keys = ['endpoint', 'region', 'bucket', 'access_key_id', 'secret', 'prefix', 'path_style'];
        $own = !$conn->inTransaction();   // join the caller's transaction, or own one
        if ($own) $conn->beginTransaction();
        try {
            foreach ($keys as $k) storeSaveSetting($conn, "storage_s3_$k", storeSetting($conn, "files_s3_$k"));
            foreach ($keys as $k) storeSaveSetting($conn, "files_s3_$k", null);
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own) $conn->rollBack();
            throw $e;
        }
        storeReset();
        filesStorageReset();
    }

    /**
     * [files, bytes] for the page. A table Database Verification has not created yet holds
     * nothing: [0, 0]. Any other failure is unknown - [null, null], never "empty".
     */
    private static function usage(PDO $conn, string $sql): array
    {
        try {
            $r = $conn->query($sql)->fetch(PDO::FETCH_NUM) ?: [0, 0];
            return [(int)$r[0], (int)$r[1]];
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') return [0, 0];
            error_log('[storage] usage: ' . $e->getMessage());
            return [null, null];
        }
    }

    /** True while Files or any store keeps files in the bucket, or is moving. */
    public static function connectionInUse(PDO $conn): bool
    {
        if (filesSetting($conn, 'files_storage_driver', 'local') === 's3' || filesPreviousStorageConfig($conn) !== null) return true;
        foreach (array_keys(STORAGE_STORES) as $s) {
            if (storeLocation($conn, $s) === 's3' || storeMoveSetting($conn, $s, 'previous') !== '') return true;
        }
        return false;
    }

    /** A validated connection from the page's input; a masked secret means the saved one. */
    private static function connectionFromInput(PDO $conn, array $in): array
    {
        if (!extension_loaded('curl')) throw new InvalidArgumentException('S3-compatible storage needs the PHP curl extension, which this server does not have.');
        $saved = storageS3Connection($conn);
        $secret = (string)($in['secret'] ?? '');
        if (FilesStorageService::secretUnchanged($secret, (string)($saved['secret'] ?? ''))) $secret = (string)($saved['secret'] ?? '');
        try {
            $endpoint = s3NormaliseEndpoint((string)($in['endpoint'] ?? ''));
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('The endpoint must start with http:// or https://');
        }
        $cfg = [
            'driver'        => 's3',
            'endpoint'      => $endpoint,
            'region'        => trim((string)($in['region'] ?? '')) ?: 'us-east-1',
            'bucket'        => trim((string)($in['bucket'] ?? '')),
            'access_key_id' => trim((string)($in['access_key_id'] ?? '')),
            'secret'        => $secret,
            'prefix'        => s3NormalisePrefix((string)($in['prefix'] ?? '')),
            'path_style'    => !empty($in['path_style']),
            'sse'           => ($in['sse'] ?? '') === 'AES256' ? 'AES256' : '',
        ];
        foreach (['endpoint' => 'the endpoint', 'bucket' => 'the bucket', 'access_key_id' => 'the access key ID', 'secret' => 'the secret access key'] as $k => $what) {
            if ($cfg[$k] === '') throw new InvalidArgumentException('Enter ' . $what . '.');
        }
        return $cfg;
    }

    /** True while Files or a store is moving OUT of S3 (its previous place is the bucket). */
    private static function moveReadsS3(PDO $conn): bool
    {
        try {
            if ((filesPreviousStorageConfig($conn)['driver'] ?? '') === 's3') return true;
        } catch (Throwable $e) {}
        foreach (array_keys(STORAGE_STORES) as $s) {
            try {
                if ((storePreviousConfig($conn, $s)['driver'] ?? '') === 's3') return true;
            } catch (Throwable $e) {}   // undecryptable: that move cannot read anything anyway
        }
        return false;
    }

    /** What a move reading the bucket depends on besides the place. */
    private static function credentialsKey(array $c): string
    {
        return json_encode([(string)$c['access_key_id'], (string)$c['secret'], (string)($c['region'] ?? ''), !empty($c['path_style'])]);
    }

    /** What identifies the place: another endpoint, bucket or prefix is another place. */
    private static function placeKey(array $c): string
    {
        return rtrim((string)$c['endpoint'], '/') . '|' . $c['bucket'] . '|' . s3NormalisePrefix((string)($c['prefix'] ?? ''));
    }
}
