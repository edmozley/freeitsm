<?php
/**
 * The Files recycle bin.
 *
 *   GET  (no query)        what the caller can see in the bin, newest first, and
 *                          the retention period (days; 0 = never purged)
 *   GET  ?folder={id}      the files inside a deleted folder (read-only)
 *   POST {action: restore, type: folder|item, id}   Modify - back where it was;
 *        a name clash restores it as "Name (2)". While the folder it was in is in
 *        the bin too, the reply names that folder (restore_first) instead.
 *   POST {action: purge, type, id}                  Modify - deleted for good,
 *        stored bytes included
 *   POST {action: empty}   purges every entry the caller may purge (Modify on it and
 *        on every folder inside it), about 20 seconds' worth per call: the reply's
 *        'more' says to call again; 'skipped' counts entries left for others
 *
 * Permissions are those the folder had before it was deleted; an entry the caller
 * could not View is "not found". The rules are in includes/services/files_recycle.php.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';
require_once __DIR__ . '/../../includes/services/files_recycle.php';

filesApiRun(function () use ($conn, $analystId) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            if (isset($_GET['folder'])) filesApiOk(FilesRecycleService::contents($conn, $analystId, (int)$_GET['folder']));
        } catch (InvalidArgumentException $e) {
            filesApiFail($e->getMessage());
        }
        filesApiOk([
            'entries' => FilesRecycleService::entries($conn, $analystId),
            'days'    => FilesRecycleService::retentionDays($conn) ?: 0,
        ]);
    }

    // Changes data: never on a GET, which a link on another site can send (csrf.php).
    csrfRequireWriteMethod();
    $in     = filesApiBody();
    $action = (string)($in['action'] ?? '');
    $type   = (string)($in['type'] ?? '');
    $id     = (int)($in['id'] ?? 0);

    try {
        if ($action === 'restore') filesApiOk(FilesRecycleService::restore($conn, $analystId, $type, $id));
        if ($action === 'purge')   filesApiOk(FilesRecycleService::purge($conn, $analystId, $type, $id));
        // A bounded slice per request: a big bin (or slow storage) must not outrun
        // max_execution_time. The page asks again while 'more' is true.
        if ($action === 'empty')   filesApiOk(FilesRecycleService::emptyBin($conn, $analystId, time() + 20));
    } catch (FilesRecycleBlocked $e) {
        filesApiFail($e->getMessage(), 200, $e->first ? ['restore_first' => $e->first] : []);
    } catch (InvalidArgumentException $e) {
        filesApiFail($e->getMessage());
    }
    filesApiFail('Unknown action.');
});
