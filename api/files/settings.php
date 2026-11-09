<?php
/**
 * Files -> Settings.
 *
 *   GET  ?tab=storage   Cap::FILES_STORAGE  where files are stored (a folder or
 *        S3-compatible storage), size limit, usage, and any move in progress.
 *        Never the S3 secret: only whether one is set.
 *   POST {tab: storage, driver, storage_root, max_upload_mb, s3: {...}}
 *   POST {tab: storage_test, s3: {...}}        write/read/delete a test file
 *   POST {tab: storage_migration, action: retry_failed|remove_old}
 *   GET  ?tab=folders   Cap::FILES_FOLDERS  EVERY folder that exists, including
 *        ones the caller cannot open - names, paths and how many entries each has,
 *        never their files. This is "admins can see a folder exists" (Ed's
 *        rule): the way back into an orphaned folder is Take ownership, which is
 *        permissions.php and is audited.
 *
 * Changing WHERE files are stored moves them: the files are copied in the
 * background by the storage_migrate job (never inside this request) and read
 * from the old location until each copy is verified. The rules are in
 * includes/services/files_storage.php.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';
require_once __DIR__ . '/../../includes/services/files_storage.php';

filesApiRun(function () use ($conn, $analystId) {
    $isGet = $_SERVER['REQUEST_METHOD'] === 'GET';
    $in  = $isGet ? [] : filesApiBody();
    $tab = $isGet ? (string)($_GET['tab'] ?? '') : (string)($in['tab'] ?? '');

    if (in_array($tab, ['storage', 'storage_test', 'storage_migration'], true)) {
        requireCapabilityJson(Cap::FILES_STORAGE, $conn);
        if ($tab === 'storage' && $isGet) filesApiOk(FilesStorageService::describe($conn));
        if ($isGet) filesApiFail('Unknown settings tab.');
        try {
            if ($tab === 'storage_test') {
                $s = (array)($in['s3'] ?? []);
                $stored = settingGetDecrypted($conn, 'files_s3_secret');
                if (FilesStorageService::secretUnchanged((string)($s['secret'] ?? ''), $stored)) $s['secret'] = $stored;
                filesApiOk(FilesStorageService::test($s));
            }
            if ($tab === 'storage_migration') {
                FilesStorageService::migrationAction($conn, $analystId, (string)($in['action'] ?? ''));
                filesApiOk();
            }
            FilesStorageService::save($conn, $analystId, $in);
            filesApiOk();
        } catch (InvalidArgumentException $e) {
            filesApiFail($e->getMessage());
        }
    }

    if ($tab === 'folders' && $isGet) {
        requireCapabilityJson(Cap::FILES_FOLDERS, $conn);
        $tree   = FilesAcl::tree($conn);
        $mine   = FilesAcl::levels($conn, $analystId);
        $counts = $conn->query("SELECT folder_id, COUNT(*) FROM files_permissions GROUP BY folder_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $files  = $conn->query("SELECT folder_id, COUNT(*) FROM files_items WHERE deleted_datetime IS NULL GROUP BY folder_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [];
        foreach ($tree as $id => $f) {
            $out[] = [
                'id'       => $id,
                'path'     => FilesAcl::path($conn, $id),
                'inherit'  => (bool)$f['inherit'],
                'top'      => $f['parent_id'] === null,
                'entries'  => (int)($counts[$id] ?? 0),
                'files'    => (int)($files[$id] ?? 0),
                'my_level' => $mine[$id] ?? 0,
            ];
        }
        usort($out, fn($a, $b) => strnatcasecmp($a['path'], $b['path']));
        filesApiOk(['folders' => $out]);
    }

    filesApiFail('Unknown settings tab.');
});
