<?php
/**
 * POST {action, id, ...} - change a file.
 *
 *   rename  {id, name}   Modify on its folder
 *   delete  {id}         Modify. Soft: to the recycle bin; the bytes stay on disk.
 *   restore_version {id, version_id}  Upload. The version becomes current again as a NEW
 *           version sharing its bytes; nothing is lost (GH #303).
 *   delete_version  {id, version_id}  Modify. An earlier version, never the current one;
 *           its bytes go only if nothing else uses them.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';
require_once __DIR__ . '/../../includes/services/files_versions.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

filesApiRun(function () use ($conn, $analystId) {
    $in     = filesApiBody();
    $action = (string)($in['action'] ?? '');
    $id     = (int)($in['id'] ?? 0);

    if ($action === 'rename') {
        $it   = filesNeedItem($conn, $analystId, $id, FilesAcl::MODIFY, 'rename');
        $name = filesCleanName((string)($in['name'] ?? ''));
        if ($name === '') filesApiFail('Please give the file a name.');
        if ($name === $it['name']) filesApiOk(['name' => $name]);
        if (filesNameTaken($conn, (int)$it['folder_id'], $name, 'item', $id)) {
            filesApiFail('There is already something called "' . $name . '" here.');
        }
        $conn->prepare("UPDATE files_items SET name = ?, updated_by = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$name, $analystId, $id]);
        filesAudit($conn, $analystId, 'rename', ['folder_id' => (int)$it['folder_id'], 'item_id' => $id, 'item_name' => $name],
                   ['from' => $it['name'], 'to' => $name]);
        filesApiOk(['name' => $name]);
    }

    if ($action === 'delete') {
        $it = filesNeedItem($conn, $analystId, $id, FilesAcl::MODIFY, 'delete');
        $conn->prepare("UPDATE files_items SET deleted_by = ?, deleted_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$analystId, $id]);
        filesAudit($conn, $analystId, 'delete', ['folder_id' => (int)$it['folder_id'], 'item_id' => $id, 'item_name' => $it['name']],
                   ['type' => 'file']);
        filesApiOk();
    }

    if ($action === 'restore_version' || $action === 'delete_version') {
        $restore = $action === 'restore_version';
        $it = filesNeedItem($conn, $analystId, $id, $restore ? FilesAcl::UPLOAD : FilesAcl::MODIFY, $restore ? 'restore version' : 'delete version');
        try {
            if ($restore) filesApiOk(['version_no' => FilesVersionsService::restore($conn, $analystId, $it, (int)($in['version_id'] ?? 0))]);
            FilesVersionsService::deleteVersion($conn, $analystId, $it, (int)($in['version_id'] ?? 0));
            filesApiOk();
        } catch (InvalidArgumentException $e) {
            filesApiFail($e->getMessage());
        }
    }

    filesApiFail('Unknown action.');
});
