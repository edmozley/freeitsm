<?php
/**
 * POST {action, ...} - change a folder.
 *
 *   create   {parent_id (0 = top level), name}   Upload on the parent; at the top
 *            level, Cap::FILES_FOLDERS - and the creator is granted Full control,
 *            because a new top-level folder grants nothing and they would
 *            otherwise lock themselves out of what they just made.
 *   rename   {id, name}                          Modify
 *   delete   {id}                                Modify. Soft: the folder and
 *            everything under it go to the recycle bin (deleted_datetime).
 *   inherit  {id, inherit: bool, copy: bool}     Full control. Turning it OFF
 *            asks, like Windows, whether to copy the inherited entries in as the
 *            folder's own (copy=true) or start from just its own (copy=false).
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

filesApiRun(function () use ($conn, $analystId) {
    $in     = filesApiBody();
    $action = (string)($in['action'] ?? '');

    if ($action === 'create') {
        $parentId = (int)($in['parent_id'] ?? 0);
        $name     = filesCleanName((string)($in['name'] ?? ''));
        if ($name === '' || $name === 'file') $name = 'New folder';

        if ($parentId > 0) {
            filesNeed($conn, $analystId, $parentId, FilesAcl::UPLOAD, 'create_folder');
        } elseif (!filesHasCap($conn, $analystId, Cap::FILES_FOLDERS)) {
            filesApiFail('You cannot create folders at the top level. Ask an administrator for the Files "Folders" permission.');
        }
        $name = filesFreeName($conn, $parentId ?: null, $name);

        $conn->beginTransaction();
        $conn->prepare("INSERT INTO files_folders (parent_id, name, inherit_permissions, created_by, created_datetime)
                        VALUES (?, ?, 1, ?, UTC_TIMESTAMP())")->execute([$parentId ?: null, $name, $analystId]);
        $id = (int)$conn->lastInsertId();
        if (!$parentId) {
            $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level, granted_by, granted_datetime)
                            VALUES (?, 'analyst', ?, ?, ?, UTC_TIMESTAMP())")->execute([$id, $analystId, FilesAcl::FULL, $analystId]);
        }
        $conn->commit();
        FilesAcl::reset();
        filesAudit($conn, $analystId, 'create_folder', ['folder_id' => $id], $parentId ? [] : ['top_level' => true, 'creator_granted' => 'full']);
        filesApiOk(['id' => $id, 'name' => $name]);
    }

    $id = (int)($in['id'] ?? 0);
    $tree = FilesAcl::tree($conn);
    if ($id <= 0 || !isset($tree[$id])) filesApiFail('Folder not found.');

    if ($action === 'rename') {
        filesNeed($conn, $analystId, $id, FilesAcl::MODIFY, 'rename');
        $name = filesCleanName((string)($in['name'] ?? ''));
        if ($name === '' ) filesApiFail('Please give the folder a name.');
        $old = $tree[$id]['name'];
        if ($name === $old) filesApiOk(['name' => $name]);
        if (filesNameTaken($conn, $tree[$id]['parent_id'], $name, 'folder', $id)) {
            filesApiFail('There is already something called "' . $name . '" here.');
        }
        $conn->prepare("UPDATE files_folders SET name = ?, updated_by = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$name, $analystId, $id]);
        FilesAcl::reset();
        filesAudit($conn, $analystId, 'rename', ['folder_id' => $id], ['from' => $old, 'to' => $name]);
        filesApiOk(['name' => $name]);
    }

    if ($action === 'delete') {
        filesNeed($conn, $analystId, $id, FilesAcl::MODIFY, 'delete');
        $path = FilesAcl::path($conn, $id);
        $conn->prepare("UPDATE files_folders SET deleted_by = ?, deleted_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$analystId, $id]);
        FilesAcl::reset();
        filesAudit($conn, $analystId, 'delete', ['folder_id' => $id, 'path' => $path], ['type' => 'folder']);
        filesApiOk();
    }

    if ($action === 'inherit') {
        filesNeed($conn, $analystId, $id, FilesAcl::FULL, 'inheritance');
        $on   = !empty($in['inherit']);
        $copy = !empty($in['copy']);
        if ($on === (bool)$tree[$id]['inherit']) filesApiOk();

        $conn->beginTransaction();
        $copied = 0;
        if (!$on && $copy && $tree[$id]['parent_id'] !== null) {
            // The parent's effective entries become this folder's own. Where a
            // principal already has an entry here, the higher level wins.
            $best = [];
            foreach (FilesAcl::entries($conn, $tree[$id]['parent_id']) as $e) {
                $k = $e['principal_type'] . ':' . $e['principal_id'];
                $best[$k] = max($best[$k] ?? 0, $e['level']);
            }
            $ins = $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level, granted_by, granted_datetime)
                                   VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
                                   ON DUPLICATE KEY UPDATE level = GREATEST(level, VALUES(level))");
            foreach ($best as $k => $lvl) {
                [$type, $pid] = explode(':', $k);
                $ins->execute([$id, $type, (int)$pid, $lvl, $analystId]);
                $copied++;
            }
        }
        $conn->prepare("UPDATE files_folders SET inherit_permissions = ?, updated_by = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$on ? 1 : 0, $analystId, $id]);
        $conn->commit();
        FilesAcl::reset();
        filesAudit($conn, $analystId, 'inheritance', ['folder_id' => $id],
                   ['inherit' => $on, 'copied_entries' => $on ? null : $copied]);
        filesApiOk(['level' => FilesAcl::level($conn, $analystId, $id)]);
    }

    filesApiFail('Unknown action.');
});
