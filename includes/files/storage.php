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
 */

require_once __DIR__ . '/../uploads.php';

const FILES_DEFAULT_MAX_UPLOAD_MB = 4096;
/** Each upload piece. Under PHP's default post_max_size (8M) with room to spare. */
const FILES_CHUNK_BYTES = 4 * 1024 * 1024;

function filesSetting(PDO $conn, string $key, string $default = ''): string
{
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        $cache[$key] = ($v === false || $v === null) ? null : (string)$v;
    }
    return ($cache[$key] === null || $cache[$key] === '') ? $default : $cache[$key];
}

function filesSaveSetting(PDO $conn, string $key, ?string $value): void
{
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value]);
}

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

function filesMaxUploadBytes(PDO $conn): int
{
    $mb = (int)filesSetting($conn, 'files_max_upload_mb', (string)FILES_DEFAULT_MAX_UPLOAD_MB);
    if ($mb <= 0) $mb = FILES_DEFAULT_MAX_UPLOAD_MB;
    return $mb * 1024 * 1024;
}

/** A fresh relative path for today: 2026/10/10/<hex>.bin (directories created). */
function filesNewStoragePath(PDO $conn): string
{
    $root = filesStorageRoot($conn);
    $rel  = gmdate('Y/m/d') . '/' . bin2hex(random_bytes(16)) . '.bin';
    $dir  = dirname($root . '/' . $rel);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new Exception('The storage folder could not be created.');
    }
    return $rel;
}

/**
 * The absolute path of a stored version, or null if the row's path is not one
 * we could have written. A row reading "../../config.php" must never be served.
 */
function filesAbsolutePath(PDO $conn, string $rel): ?string
{
    if (!preg_match('#^\d{4}/\d{2}/\d{2}/[0-9a-f]{32}\.bin$#', $rel)) return null;
    return filesStorageRoot($conn) . '/' . $rel;
}

/** Where pieces of an in-progress upload collect. */
function filesIncomingPath(PDO $conn, string $token): string
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) throw new Exception('Bad upload token.');
    $dir = filesStorageRoot($conn) . '/_incoming';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new Exception('The upload folder could not be created.');
    }
    return $dir . '/' . $token . '.part';
}

/** A file or folder name as a person typed it, made safe to store and display. */
function filesCleanName(string $name): string
{
    return uploadCleanName($name);
}

/** The mime type we record for a stored file: sniffed, else guessed from the name. */
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
    } catch (Throwable $e) { /* the column may not exist before Database Verify */ }

    if ($url === null) {
        require_once __DIR__ . '/../branding.php';
        $url = brandingLogoUrl($conn);
    }
    return ['url' => $url, 'position' => $pos];
}

/** A company logo lives in system/uploads/branding/companies/ under our own random name. */
function filesCompanyLogoPathIsSafe(string $p): bool
{
    return (bool)preg_match('#^system/uploads/branding/companies/[0-9a-f]{32}\.(png|jpe?g|gif|webp)$#', $p);
}
