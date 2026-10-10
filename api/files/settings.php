<?php
/**
 * Files -> Settings.
 *
 *   GET  ?tab=storage   Cap::FILES_STORAGE  the storage root, size limit, usage
 *   POST {tab: storage, storage_root, max_upload_mb}
 *   GET  ?tab=folders   Cap::FILES_FOLDERS  EVERY folder that exists, including
 *        ones the caller cannot open - names, paths and how many entries each has,
 *        never their files. This is "admins can see a folder exists" (Ed's
 *        rule): the way back into an orphaned folder is Take ownership, which is
 *        permissions.php and is audited.
 *
 * ⚠️ Changing the storage root does NOT move anything. Stored paths are relative
 * (yyyy/mm/dd/x.bin), so the operator copies the old root's contents to the new
 * one first; the page says so. Moving gigabytes inside a web request is not
 * something to attempt.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

filesApiRun(function () use ($conn, $analystId) {
    $isGet = $_SERVER['REQUEST_METHOD'] === 'GET';
    $in  = $isGet ? [] : filesApiBody();
    $tab = $isGet ? (string)($_GET['tab'] ?? '') : (string)($in['tab'] ?? '');

    if ($tab === 'storage') {
        requireCapabilityJson(Cap::FILES_STORAGE, $conn);
        if ($isGet) {
            $root = rtrim(str_replace('\\', '/', filesSetting($conn, 'files_storage_root', filesDefaultStorageRoot())), '/');
            $usage = $conn->query("SELECT COUNT(DISTINCT storage_path) AS n, COALESCE(SUM(size_bytes), 0) AS bytes
                                     FROM (SELECT storage_path, MAX(size_bytes) AS size_bytes FROM files_versions GROUP BY storage_path) s")->fetch(PDO::FETCH_ASSOC);
            filesApiOk([
                'storage_root'   => $root,
                'default_root'   => filesDefaultStorageRoot(),
                'is_default'     => filesSetting($conn, 'files_storage_root', '') === '',
                'writable'       => is_dir($root) ? is_writable($root) : is_writable(dirname($root)),
                'max_upload_mb'  => (int)filesSetting($conn, 'files_max_upload_mb', (string)FILES_DEFAULT_MAX_UPLOAD_MB),
                'stored_files'   => (int)$usage['n'],
                'stored_bytes'   => (int)$usage['bytes'],
                'free_bytes'     => @disk_free_space(is_dir($root) ? $root : dirname($root)) ?: null,
            ]);
        }
        $root = trim(str_replace('\\', '/', (string)($in['storage_root'] ?? '')));
        $mb   = (int)($in['max_upload_mb'] ?? 0);
        if ($mb < 1 || $mb > 1024 * 1024) filesApiFail('The largest upload must be between 1 MB and 1 TB.');
        $was = filesSetting($conn, 'files_storage_root', '');
        if ($root === '' || rtrim($root, '/') === filesDefaultStorageRoot()) {
            $root = '';
        } else {
            if (strpos($root, '..') !== false) filesApiFail('Give the folder as a full path, without "..".');
            if (!is_dir($root)) filesApiFail('That folder does not exist on the server. Create it first, then save.');
            if (!is_writable($root)) filesApiFail('The web server cannot write to that folder.');
            $root = rtrim($root, '/');
        }
        filesSaveSetting($conn, 'files_storage_root', $root === '' ? null : $root);
        filesSaveSetting($conn, 'files_max_upload_mb', (string)$mb);
        filesAudit($conn, $analystId, 'settings', ['path' => '(Settings: storage)'],
                   ['storage_root' => $root ?: '(default)', 'storage_root_was' => $was ?: '(default)', 'max_upload_mb' => $mb]);
        filesApiOk();
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
