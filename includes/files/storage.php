<?php
/**
 * Files — where the bytes live, and the module's settings.
 *
 * Every file is stored as <storage root>/yyyy/mm/dd/<32 hex>.bin, dated by the
 * day it was uploaded. Nothing user-controlled ever reaches the filesystem: the
 * original name, type, size and SHA-256 live in files_versions only. A .bin
 * cannot be executed by any web server, and cannot be fetched at all - the root
 * is denied outright (uploadPrepareDir) and every read goes through
 * api/files/download.php, which checks the folder's permissions first.
 *
 * The root defaults to uploads/files/ inside the app (nginx's /uploads/ block
 * and the generated .htaccess/web.config deny it). Files -> Settings -> Storage
 * can move it anywhere the web server can write - ideally outside the web root.
 * On Docker it is the `files` volume.
 *
 * Or the bytes live in S3-compatible storage instead (files_storage_driver =
 * 's3'): every read and write goes through a StorageDriver (includes/storage/),
 * chosen by filesActiveStore() / filesStoreFor(). The local root is then only
 * where uploads are assembled (_incoming/). Changing location moves the files in
 * the background - see includes/services/files_storage.php.
 */

require_once __DIR__ . '/../uploads.php';
require_once __DIR__ . '/../encryption.php';
require_once __DIR__ . '/../storage/driver.php';

const FILES_DEFAULT_MAX_UPLOAD_MB = 4096;
/** Each upload piece. Under PHP's default post_max_size (8M) with room to spare. */
const FILES_CHUNK_BYTES = 4 * 1024 * 1024;

/** Per-request caches of Files settings and storage drivers; filesStorageReset() empties them. */
final class FilesStorageCache
{
    public static array $settings = [];
    public static array $drivers = [];
}

/**
 * Reads a Files setting from system_settings, cached per request; blank or missing gives $default.
 *
 * @param string $key      the system_settings.setting_key, e.g. 'files_storage_root' or 'files_max_upload_mb'
 * @param string $default  what to return when the setting is missing or blank
 */
function filesSetting(PDO $conn, string $key, string $default = ''): string
{
    $cache = &FilesStorageCache::$settings;
    if (!array_key_exists($key, $cache)) {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        $cache[$key] = ($v === false || $v === null) ? null : (string)$v;
    }
    return ($cache[$key] === null || $cache[$key] === '') ? $default : $cache[$key];
}

/** Forgets the cached Files settings and drivers - after saving settings, and in tests. */
function filesStorageReset(): void
{
    FilesStorageCache::$settings = [];
    FilesStorageCache::$drivers = [];
}

/**
 * Where Files stores bytes now, from Files -> Settings -> Storage.
 * No files_storage_driver saved (every install before S3 existed) = the local folder.
 *
 * @return array ['driver' => 'local', 'root' => ...] or the S3 settings (secret decrypted)
 */
function filesStorageConfig(PDO $conn): array
{
    if (filesSetting($conn, 'files_storage_driver', 'local') !== 's3') {
        return ['driver' => 'local', 'root' => filesStorageRoot($conn)];
    }
    return [
        'driver'        => 's3',
        'endpoint'      => filesSetting($conn, 'files_s3_endpoint'),
        'region'        => filesSetting($conn, 'files_s3_region', 'us-east-1'),
        'bucket'        => filesSetting($conn, 'files_s3_bucket'),
        'access_key_id' => filesSetting($conn, 'files_s3_access_key_id'),
        'secret'        => settingGetDecrypted($conn, 'files_s3_secret'),
        'prefix'        => filesSetting($conn, 'files_s3_prefix'),
        'path_style'    => filesSetting($conn, 'files_s3_path_style', '0') === '1',
    ];
}

/**
 * The location files are being moved FROM, while a move is running; null otherwise.
 * Stored encrypted (it can hold an S3 secret) as JSON in files_storage_previous.
 */
function filesPreviousStorageConfig(PDO $conn): ?array
{
    $raw = filesSetting($conn, 'files_storage_previous');
    if ($raw === '') return null;
    try {
        $plain = (string)decryptValue($raw);
    } catch (Throwable $e) {
        // Damaged, or the encryption key was replaced: the old location is unknown, so
        // files not yet moved cannot be read - "unavailable", never a 500.
        throw new StorageUnavailable('The previous storage location could not be decrypted (has the encryption key changed?).');
    }
    $cfg = json_decode($plain, true);
    return is_array($cfg) && isset($cfg['driver']) ? $cfg : null;
}

/** The driver new files are written to. */
function filesActiveStore(PDO $conn): StorageDriver
{
    return FilesStorageCache::$drivers['active'] ??= storageDriver(filesStorageConfig($conn), 'files');
}

/** The driver for the old location during a move, or null. */
function filesPreviousStore(PDO $conn): ?StorageDriver
{
    if (!array_key_exists('previous', FilesStorageCache::$drivers)) {
        $cfg = filesPreviousStorageConfig($conn);
        FilesStorageCache::$drivers['previous'] = $cfg ? storageDriver($cfg, 'files') : null;
    }
    return FilesStorageCache::$drivers['previous'];
}

/**
 * The driver that holds a stored file now. During a move, a file whose copy has
 * not been verified yet is still read from the old location.
 *
 * @param string $key  files_versions.storage_path
 */
function filesStoreFor(PDO $conn, string $key): StorageDriver
{
    $prev = filesPreviousStore($conn);
    if ($prev !== null) {
        $st = $conn->prepare("SELECT state FROM storage_migration_items WHERE store = 'files' AND object_key = ?");
        $st->execute([$key]);
        $state = $st->fetchColumn();
        if ($state !== false && !in_array($state, ['verified', 'removed'], true)) return $prev;
    }
    return filesActiveStore($conn);
}

/** What a person is told when the store cannot be reached. */
function filesStoreUnavailableMessage(): string
{
    $key = 'files.err.storage_unavailable';
    $s = function_exists('t') ? t($key) : $key;
    return ($s !== $key && $s !== '') ? $s
        : "File storage can't be reached right now. Try again in a few minutes, or tell your administrator.";
}

/** True for a storage path we could have written: yyyy/mm/dd/<32 hex>.bin. */
function filesStorageKeyIsValid(string $rel): bool
{
    return (bool)preg_match('#^\d{4}/\d{2}/\d{2}/[0-9a-f]{32}\.bin$#', $rel);
}

/**
 * Streams bytes $start..$end of a stored version to the output.
 *
 * $begin() is called just before the first byte, so headers are only sent once
 * the store has answered - a store that cannot be reached still gets a proper
 * error status. Returns 0 when bytes were sent, otherwise the HTTP status to
 * send instead: 404 (a path we could not have written), 410 (the stored copy is
 * missing), 503 (the store cannot be reached).
 *
 * @param array $version  a files_versions row (storage_path is used)
 */
function filesServeBytes(PDO $conn, array $version, int $start, int $end, callable $begin): int
{
    $key = (string)($version['storage_path'] ?? '');
    if (!filesStorageKeyIsValid($key)) return 404;
    $started = false;
    try {
        filesStoreFor($conn, $key)->readRange($key, $start, $end, function (string $chunk) use (&$started, $begin) {
            if (!$started) { $started = true; $begin(); }
            echo $chunk;
            flush();
            return !connection_aborted();
        });
        if (!$started) $begin();   // an empty range still needs its headers
        return 0;
    } catch (StorageNotFound $e) {
        if ($started) return 0;
        error_log("files: stored bytes missing ({$key})");
        return 410;
    } catch (StorageUnavailable $e) {
        error_log("files: storage unavailable reading {$key}: " . $e->getMessage());
        return $started ? 0 : 503;
    }
}

/**
 * Saves one Files setting to system_settings (insert or update).
 *
 * @param string $key     the system_settings.setting_key, e.g. 'files_storage_root'
 * @param ?string $value  the value to store; null stores NULL, which filesSetting() reads as unset
 */
function filesSaveSetting(PDO $conn, string $key, ?string $value): void
{
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value]);
}

/** The storage folder used when none is set: uploads/files under the application root. */
function filesDefaultStorageRoot(): string
{
    return str_replace('\\', '/', dirname(__DIR__, 2)) . '/uploads/files';
}

/** The storage root, created and guarded on first use. No trailing slash. */
function filesStorageRoot(PDO $conn): string
{
    $root = rtrim(str_replace('\\', '/', filesSetting($conn, 'files_storage_root', filesDefaultStorageRoot())), '/');
    uploadPrepareDir($root);
    return $root;
}

/** The largest file an analyst may upload, in bytes, from the files_max_upload_mb setting. */
function filesMaxUploadBytes(PDO $conn): int
{
    $mb = (int)filesSetting($conn, 'files_max_upload_mb', (string)FILES_DEFAULT_MAX_UPLOAD_MB);
    if ($mb <= 0) $mb = FILES_DEFAULT_MAX_UPLOAD_MB;
    return $mb * 1024 * 1024;
}

/**
 * A fresh storage key for today: 2026/10/10/<hex>.bin. The driver creates any
 * folders when the bytes are written (filesActiveStore()->putFile()).
 */
function filesNewStoragePath(PDO $conn): string
{
    return gmdate('Y/m/d') . '/' . bin2hex(random_bytes(16)) . '.bin';
}

/**
 * Where pieces of an in-progress upload collect.
 *
 * @param string $token  the upload's token from the client - must be 32 lower-case hex characters or it throws
 */
function filesIncomingPath(PDO $conn, string $token): string
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) throw new Exception('Bad upload token.');
    $dir = filesStorageRoot($conn) . '/_incoming';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new Exception('The upload folder could not be created.');
    }
    return $dir . '/' . $token . '.part';
}

/**
 * A file or folder name as a person typed it, made safe to store and display.
 *
 * @param string $name  the name as typed or uploaded (untrusted)
 */
function filesCleanName(string $name): string
{
    return uploadCleanName($name);
}

/**
 * The mime type we record for a stored file: sniffed, else guessed from the name.
 *
 * @param string $absPath  the full path of the file on disk, whose content is sniffed
 * @param string $name     the file's display name, whose extension is the fallback guess
 */
function filesDetectMime(string $absPath, string $name): string
{
    $m = uploadDetectMime($absPath);
    if ($m && $m !== 'application/octet-stream') return $m;
    $rules = attachmentServeRules($name);
    return $rules['type'];
}

/** Per-person desktop preferences, in user_preferences with a files_ prefix. */
function filesGetPrefs(PDO $conn, int $analystId): array
{
    $st = $conn->prepare("SELECT preference_key, preference_value FROM user_preferences
                           WHERE analyst_id = ? AND preference_key LIKE 'files\\_%'");
    $st->execute([$analystId]);
    $raw = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $windows = json_decode($raw['files_windows'] ?? '', true);
    return [
        'desktop_colour' => preg_match('/^#[0-9a-fA-F]{6}$/', $raw['files_desktop_colour'] ?? '') ? $raw['files_desktop_colour'] : '#1e3a5f',
        'navbar'         => in_array($raw['files_navbar'] ?? '', ['on', 'auto', 'off'], true) ? $raw['files_navbar'] : 'on',
        'view'           => in_array($raw['files_view'] ?? '', ['icons', 'details'], true) ? $raw['files_view'] : 'icons',
        'windows'        => is_array($windows) ? $windows : new stdClass(),
    ];
}

/**
 * Saves one of the analyst's own Files preferences (insert or update).
 *
 * @param string $key    the preference_key with its files_ prefix, e.g. 'files_view' or 'files_windows'
 * @param string $value  the value to store, already checked by the caller (JSON for 'files_windows')
 * @writes user_preferences(preference_value) - per-analyst choices such as the view
 */
function filesSavePref(PDO $conn, int $analystId, string $key, string $value): void
{
    $conn->prepare("INSERT INTO user_preferences (analyst_id, preference_key, preference_value, updated_datetime)
                    VALUES (?, ?, ?, UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value), updated_datetime = UTC_TIMESTAMP()")
         ->execute([$analystId, $key, $value]);
}

/**
 * The logo for the desktop corner, per System -> Branding:
 *   files_logo_mode     'company' (default) | 'none'
 *   files_logo_position 'top-right' (default) | 'bottom-right'
 * 'company' = the analyst's active company's logo, else the organisation logo.
 */
function filesDesktopLogo(PDO $conn, int $analystId): array
{
    $mode = filesSetting($conn, 'files_logo_mode', 'company');
    $pos  = filesSetting($conn, 'files_logo_position', 'top-right') === 'bottom-right' ? 'bottom-right' : 'top-right';
    if ($mode === 'none') return ['url' => null, 'position' => $pos];

    $url = null;
    try {
        require_once __DIR__ . '/../tenancy.php';
        $tid = getActiveTenantId($conn, $analystId);
        if ($tid) {
            $st = $conn->prepare("SELECT logo_path FROM tenants WHERE id = ?");
            $st->execute([$tid]);
            $p = (string)$st->fetchColumn();
            if ($p !== '' && filesCompanyLogoPathIsSafe($p) && file_exists(dirname(__DIR__, 2) . '/' . $p)) {
                $url = BASE_URL . $p;
            }
        }
    } catch (Throwable) { /* the column may not exist before Database Verify */ }

    if ($url === null) {
        require_once __DIR__ . '/../branding.php';
        $url = brandingLogoUrl($conn);
    }
    return ['url' => $url, 'position' => $pos];
}

/**
 * A company logo lives in system/uploads/branding/companies/ under our own random name.
 *
 * @param string $p  tenants.logo_path, relative to the application root
 */
function filesCompanyLogoPathIsSafe(string $p): bool
{
    return (bool)preg_match('#^system/uploads/branding/companies/[0-9a-f]{32}\.(png|jpe?g|gif|webp)$#', $p);
}
