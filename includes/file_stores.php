<?php
/**
 * Where FreeITSM keeps uploaded files - one list, one helper.
 *
 * Step 1 of wiki File-Storage-Locations-Design (Ed, 21 Nov 2026): every piece of
 * code that saves, reads or deletes a store's files asks fileStoreDir() /
 * fileStorePath() where that store lives, instead of building the path itself
 * from its own position (`dirname(__DIR__) . '/forms/images'`), which used to be
 * repeated in up to 21 files per store.
 *
 * 🔴 In step 1 the answer is ALWAYS today's folder inside the application. Nothing
 * moves, nothing is read from the database. Step 2 adds a per-store setting that
 * fileStoreDir() reads - and because every caller already asks here, a setting can
 * never be honoured in 20 places and missed in the 21st (which would save a file
 * in one folder and look for it in another).
 *
 * TRAP: do not build a store's path anywhere else. tests/file-stores.php fails if a
 * converted store's folder appears in a path built by hand.
 *
 * Files (the Files module) is not here: it already has its own location setting,
 * files_storage_root, and its own safe move (includes/files/storage.php).
 */

/**
 * Every store. Keys are stable ids (they will name settings in step 2 - never rename one).
 *   folder  today's folder, relative to the application root, forward slashes
 *   fixed   true = stays inside the application in this design (fetched by the
 *           browser straight from the folder; Ed, 21 Nov 2026: revisit in a later release)
 */
const FILE_STORES = [
    'ticket-attachments' => ['folder' => 'tickets/attachments'],
    'change-attachments' => ['folder' => 'change-management/attachments'],
    'documents'          => ['folder' => 'uploads/documents'],
    'knowledge-images'   => ['folder' => 'uploads/knowledge'],
    'report-downloads'   => ['folder' => 'uploads/report-downloads'],
    'api-log-archive'    => ['folder' => 'uploads/api-log-archive'],
    'asset-imports'      => ['folder' => 'uploads/asset-imports'],
    'recordings'         => ['folder' => 'recordings'],
    'rfp-uploads'        => ['folder' => 'contracts/rfp-builder/uploads'],
    'war-room'           => ['folder' => 'war-room/attachments'],
    'form-images'        => ['folder' => 'forms/images'],
    'label-fonts'        => ['folder' => 'system/uploads/label-fonts'],
    'branding'           => ['folder' => 'system/uploads/branding', 'fixed' => true],
    'lms-content'        => ['folder' => 'lms/content',             'fixed' => true],
];

/** The application root, as every caller used to work it out (dirname of includes/). */
function fileStoreAppRoot(): string
{
    return dirname(__DIR__);
}

/**
 * A store's folder: absolute, no trailing slash. Today's folder in step 1.
 *
 * @param string $store  a FILE_STORES key, e.g. 'form-images'
 */
function fileStoreDir(string $store): string
{
    if (!isset(FILE_STORES[$store])) {
        throw new InvalidArgumentException("Unknown file store: $store");
    }
    return fileStoreAppRoot() . '/' . FILE_STORES[$store]['folder'];
}

/**
 * A path inside a store: its folder, then $relative as the database holds it
 * (e.g. '12/345/<hex>.png'). Only joins - each caller keeps its own checks that
 * the result really is inside the folder, exactly as before.
 *
 * @param string $store     a FILE_STORES key
 * @param string $relative  the path inside the store, forward slashes; '' = the folder itself
 */
function fileStorePath(string $store, string $relative): string
{
    $relative = ltrim($relative, '/\\');
    return $relative === '' ? fileStoreDir($store) : fileStoreDir($store) . '/' . $relative;
}
