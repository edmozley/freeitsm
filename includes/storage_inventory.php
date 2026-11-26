<?php
/**
 * System -> Storage usage (system/storage-usage/): where FreeITSM keeps files, and how much is in each place.
 *
 * READ ONLY. Nothing here moves, creates or deletes anything - not even a missing
 * folder (filesStorageRoot() would create one, so it is not called here).
 *
 * The list starts from storagePersistenceDirectories() - the same list the Docker
 * storage checks warn from, kept in step with docker-compose.yml by
 * tests/docker-storage-volumes.php - so this page can never forget a folder they
 * know about. Two additions:
 *   - `uploads` holds several unrelated kinds of file, so it is shown as its
 *     subfolders (documents, Files, imports...), plus anything else found there;
 *   - Files can keep its bytes somewhere else entirely: a folder of its own, or
 *     S3-compatible storage (Files -> Settings -> Storage). Its row says so.
 *
 * Counting can take a while on a big folder, so the page lists the folders at once
 * and asks api/system/storage_stats.php for each one's count and size afterwards.
 * That endpoint takes a row's KEY, never a path: it rebuilds this list and counts
 * only a folder on it, so it cannot be pointed anywhere else on the server.
 */

require_once __DIR__ . '/storage_persistence.php';

/** Subfolders of uploads/ that FreeITSM writes, and what each holds (an i18n key under system.storage.what). */
const STORAGE_UPLOADS_KNOWN = [
    'documents'        => 'documents',
    'files'            => 'files',
    'asset-imports'    => 'asset_imports',
    'report-downloads' => 'report_downloads',
    'api-log-archive'  => 'api_log_archive',
    'api-incoming'     => 'api_incoming',
];

/** storagePersistenceDirectories()' folders, by path relative to the app, to their i18n key. */
const STORAGE_TOP_KNOWN = [
    'tickets/attachments'           => 'ticket_attachments',
    'change-management/attachments' => 'change_attachments',
    'recordings'                    => 'recordings',
    'lms/content'                   => 'lms',
    'contracts/rfp-builder/uploads' => 'rfp',
    'system/uploads/branding'       => 'branding',
    'war-room/attachments'          => 'warroom',
    'forms/images'                  => 'forms_images',
    'system/uploads/label-fonts'    => 'label_fonts',
];

/**
 * Every place files are kept, in a stable order. Each row:
 *   key    stable id for storage_stats.php (a hash of the path)
 *   what   i18n key suffix under system.storage.what (null = an unknown folder; `name` says what it is)
 *   name   the folder's own name, for an unknown one
 *   path   absolute, with forward slashes
 *   note   optional i18n key suffix under system.storage.note
 *   s3     optional ['bucket' => ..., 'endpoint' => ...] when Files keeps new files in S3
 *   critical  true for the encryption key's folder
 *
 * @param ?PDO $conn  for where Files keeps its bytes; null = assume the default folder
 */
function storageInventory(?PDO $conn = null): array
{
    $root = str_replace('\\', '/', dirname(__DIR__));
    $rows = [];
    $add = function (array $r) use (&$rows) {
        $r['path'] = rtrim(str_replace('\\', '/', $r['path']), '/');
        $r['key'] = substr(hash('sha256', strtolower($r['path'])), 0, 16);
        foreach ($rows as $x) if ($x['key'] === $r['key']) return;   // one row per folder
        $rows[] = $r + ['what' => null, 'name' => basename($r['path']), 'note' => null, 's3' => null, 'critical' => false];
    };

    // Where Files keeps its bytes (Files -> Settings -> Storage), read without side effects.
    $filesRoot = $root . '/uploads/files';
    $filesS3 = null;
    if ($conn) {
        try {
            require_once __DIR__ . '/files/storage.php';
            if (function_exists('filesSetting')) {
                $set = (string)filesSetting($conn, 'files_storage_root', '');
                if ($set !== '') $filesRoot = $set;
                if (filesSetting($conn, 'files_storage_driver', 'local') === 's3') {
                    $filesS3 = ['bucket' => (string)filesSetting($conn, 'files_s3_bucket'), 'endpoint' => (string)filesSetting($conn, 'files_s3_endpoint')];
                }
            }
        } catch (Throwable $e) {
            // The default folder is still the right answer to show.
        }
    }

    foreach (storagePersistenceDirectories() as $d) {
        $rel = str_replace('\\', '/', (string)$d['rel']);
        if ($rel === 'uploads') {
            // Its parts, each on its own row.
            $up = $root . '/uploads';
            foreach (STORAGE_UPLOADS_KNOWN as $sub => $what) {
                if ($sub === 'files') continue;   // Files has its own row below
                $add(['what' => $what, 'path' => "$up/$sub"]);
            }
            $add(['what' => 'files', 'path' => $filesRoot, 's3' => $filesS3, 'note' => $filesS3 ? 'files_s3' : null]);
            if (is_dir($up)) {
                foreach (@scandir($up) ?: [] as $name) {
                    if ($name[0] === '.' || !is_dir("$up/$name") || isset(STORAGE_UPLOADS_KNOWN[$name])) continue;
                    $add(['what' => null, 'name' => 'uploads/' . $name, 'path' => "$up/$name", 'note' => 'unknown']);
                }
            }
            continue;
        }
        if (!empty($d['critical'])) {
            $add(['what' => 'encryption_key', 'path' => (string)$d['path'], 'critical' => true, 'note' => 'encryption_key']);
            continue;
        }
        $add(['what' => STORAGE_TOP_KNOWN[$rel] ?? null, 'name' => $rel, 'path' => (string)$d['path']]);
    }
    return $rows;
}

/**
 * Files and bytes in one folder, counted recursively. Leaves out the small files
 * FreeITSM puts there to keep a folder private (.htaccess, web.config, index.html
 * and the like) - they are not anybody's data.
 *
 * @param string $path  an absolute folder from storageInventory()
 * @param float $maxSeconds  stop counting after this long and say the figures are partial
 * @return array{exists:bool, readable:bool, files:int, bytes:int, complete:bool, newest:?int}
 */
function storageFolderStats(string $path, float $maxSeconds = 8.0): array
{
    $out = ['exists' => is_dir($path), 'readable' => false, 'files' => 0, 'bytes' => 0, 'complete' => true, 'newest' => null];
    if (!$out['exists']) return $out;
    $out['readable'] = is_readable($path);
    if (!$out['readable']) { $out['complete'] = false; return $out; }
    $guard = ['.htaccess' => 1, 'web.config' => 1, 'index.html' => 1, 'index.php' => 1, '.gitkeep' => 1, '.gitignore' => 1];
    $stop = microtime(true) + $maxSeconds;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD   // an unreadable subfolder is skipped, not fatal
        );
        foreach ($it as $f) {
            if (!$f->isFile() || isset($guard[$f->getFilename()])) continue;
            $out['files']++;
            $out['bytes'] += (int)$f->getSize();
            $m = (int)$f->getMTime();
            if ($out['newest'] === null || $m > $out['newest']) $out['newest'] = $m;
            if (($out['files'] & 255) === 0 && microtime(true) > $stop) { $out['complete'] = false; break; }
        }
    } catch (Throwable $e) {
        $out['complete'] = false;
    }
    return $out;
}
