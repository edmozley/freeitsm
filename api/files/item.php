<?php
/**
 * POST {action, id, ...} - change a file.
 *
 *   rename  {id, name}   Modify on its folder
 *   delete  {id}         Modify. Soft: to the recycle bin; the bytes stay on disk.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

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

    filesApiFail('Unknown action.');
});
