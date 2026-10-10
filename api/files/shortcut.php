<?php
/**
 * Shortcuts - Windows-style links to a file or folder.
 *
 *   POST {action: create, target_type: folder|item, target_id, where: folder|desktop}
 *        folder  = beside the target, in the folder that holds it (Upload there).
 *                  Not for a top-level folder - there is nowhere beside it.
 *        desktop = on the caller's own desktop. Nobody else sees it.
 *        Either way the caller must be able to see the target.
 *   POST {action: rename, id, name}   folder shortcut: Modify on its folder;
 *                                     desktop shortcut: its owner.
 *   POST {action: delete, id}         the same. The target is never touched.
 *   POST {action: place, id, to: <folder id>|desktop, copy?}  drag and drop:
 *        move it (or copy it, Ctrl) into a folder (Upload there) or onto the
 *        caller's own desktop.
 *   GET  ?desktop=1                   the caller's desktop shortcuts, each with
 *                                     whether its target can still be opened.
 *
 * ⚠️ A SHORTCUT GRANTS NOTHING. Opening one goes through the target's own
 * permissions. In a folder listing (list.php) a shortcut whose target the viewer
 * cannot see is left out altogether - otherwise its name would tell them the
 * target exists, which is exactly what "hidden means not found" forbids.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

filesApiRun(function () use ($conn, $analystId) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $st = $conn->prepare("SELECT id, target_type, target_id, name FROM files_shortcuts
                               WHERE analyst_id = ? AND folder_id IS NULL ORDER BY created_datetime, id");
        $st->execute([$analystId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $t = filesShortcutTarget($conn, $analystId, $s['target_type'], (int)$s['target_id']);
            $out[] = [
                'id' => (int)$s['id'], 'name' => $s['name'], 'target_type' => $s['target_type'], 'target_id' => (int)$s['target_id'],
                // Unavailable = deleted, or no longer shared with this person. The
                // name it was given stays (they saw it when they made it); nothing
                // about the target's current state is revealed beyond "gone".
                'ok' => $t !== null,
                'folder_id' => $t['folder_id'] ?? null,
                'target_name' => $t['name'] ?? null,
                'path' => $t['path'] ?? null,
            ];
        }
        filesApiOk(['shortcuts' => $out]);
    }

    $in = filesApiBody();
    $action = (string)($in['action'] ?? '');

    if ($action === 'create') {
        $type = ($in['target_type'] ?? '') === 'folder' ? 'folder' : 'item';
        $tid  = (int)($in['target_id'] ?? 0);
        $t = filesShortcutTarget($conn, $analystId, $type, $tid);
        if (!$t) filesApiFail($type === 'folder' ? 'Folder not found.' : 'File not found.');

        $name = filesCleanName($t['name'] . ' - Shortcut');
        if (($in['where'] ?? '') === 'desktop') {
            $conn->prepare("INSERT INTO files_shortcuts (folder_id, analyst_id, target_type, target_id, name, created_by, created_datetime)
                            VALUES (NULL, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")->execute([$analystId, $type, $tid, $name, $analystId]);
            $id = (int)$conn->lastInsertId();
            filesAudit($conn, $analystId, 'shortcut', $type === 'folder' ? ['folder_id' => $tid] : ['folder_id' => $t['folder_id'], 'item_id' => $tid, 'item_name' => $t['name']],
                       ['created' => 'desktop', 'name' => $name]);
            filesApiOk(['id' => $id, 'name' => $name, 'where' => 'desktop']);
        }

        // Beside the target: in the folder that holds it.
        $home = $type === 'item' ? $t['folder_id'] : (FilesAcl::tree($conn)[$tid]['parent_id'] ?? null);
        if (!$home) filesApiFail('A top-level folder has nowhere beside it for a shortcut. Put it on your desktop instead.');
        filesNeed($conn, $analystId, (int)$home, FilesAcl::UPLOAD, 'shortcut');
        $name = filesFreeName($conn, (int)$home, $name);
        $conn->prepare("INSERT INTO files_shortcuts (folder_id, analyst_id, target_type, target_id, name, created_by, created_datetime)
                        VALUES (?, NULL, ?, ?, ?, ?, UTC_TIMESTAMP())")->execute([(int)$home, $type, $tid, $name, $analystId]);
        $id = (int)$conn->lastInsertId();
        filesAudit($conn, $analystId, 'shortcut', ['folder_id' => (int)$home, 'item_name' => $name],
                   ['created' => 'folder', 'target_type' => $type, 'target_id' => $tid, 'target' => $t['path']]);
        filesApiOk(['id' => $id, 'name' => $name, 'where' => 'folder', 'folder_id' => (int)$home]);
    }

    $id = (int)($in['id'] ?? 0);
    $st = $conn->prepare("SELECT * FROM files_shortcuts WHERE id = ?");
    $st->execute([$id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) filesApiFail('Shortcut not found.');
    if ($s['folder_id'] === null) {
        if ((int)$s['analyst_id'] !== $analystId) filesApiFail('Shortcut not found.');   // someone else's desktop
    } else {
        // In a folder: invisible (so "not found") unless the caller can see the
        // folder AND the target, and changing it needs Modify on the folder.
        if (!isset(FilesAcl::tree($conn)[(int)$s['folder_id']]) || !filesShortcutTarget($conn, $analystId, $s['target_type'], (int)$s['target_id'])) {
            filesApiFail('Shortcut not found.');
        }
        // Copying (Ctrl-drag) only reads it; everything else changes it.
        $readOnly = $action === 'place' && !empty($in['copy']);
        filesNeed($conn, $analystId, (int)$s['folder_id'], $readOnly ? FilesAcl::VIEW : FilesAcl::MODIFY, 'shortcut');
    }
    $where = $s['folder_id'] === null ? ['path' => '(Desktop) / ' . $s['name']] : ['folder_id' => (int)$s['folder_id'], 'item_name' => $s['name']];

    if ($action === 'place') {
        // Drag and drop: move (or with copy, duplicate) this shortcut to a folder
        // or to the caller's desktop. Moving needs what deleting needs (checked
        // above for a folder shortcut; a desktop one is the caller's own); the
        // destination needs Upload, or is the caller's own desktop.
        $copy = !empty($in['copy']);
        $to   = $in['to'] ?? '';
        $t = filesShortcutTarget($conn, $analystId, $s['target_type'], (int)$s['target_id']);
        if (!$t) filesApiFail('What this shortcut points to is no longer available.');
        if ($to === 'desktop') {
            if (!$copy && $s['folder_id'] === null) filesApiOk(['id' => $id]);   // already there
            $name = $s['name'];
            if ($copy) {
                $conn->prepare("INSERT INTO files_shortcuts (folder_id, analyst_id, target_type, target_id, name, created_by, created_datetime)
                                VALUES (NULL, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")->execute([$analystId, $s['target_type'], (int)$s['target_id'], $name, $analystId]);
                $newId = (int)$conn->lastInsertId();
            } else {
                $conn->prepare("UPDATE files_shortcuts SET folder_id = NULL, analyst_id = ? WHERE id = ?")->execute([$analystId, $id]);
                $newId = $id;
            }
            filesAudit($conn, $analystId, 'shortcut', $where, [$copy ? 'copied_to' : 'moved_to' => 'desktop', 'name' => $name]);
            filesApiOk(['id' => $newId]);
        }
        $dest = (int)$to;
        if ($dest <= 0) filesApiFail('Shortcuts go inside a folder or on the desktop.');
        if (!$copy && (int)$s['folder_id'] === $dest) filesApiOk(['id' => $id]);
        filesNeed($conn, $analystId, $dest, FilesAcl::UPLOAD, 'shortcut');
        $name = filesFreeName($conn, $dest, $s['name']);
        if ($copy) {
            $conn->prepare("INSERT INTO files_shortcuts (folder_id, analyst_id, target_type, target_id, name, created_by, created_datetime)
                            VALUES (?, NULL, ?, ?, ?, ?, UTC_TIMESTAMP())")->execute([$dest, $s['target_type'], (int)$s['target_id'], $name, $analystId]);
            $newId = (int)$conn->lastInsertId();
        } else {
            $conn->prepare("UPDATE files_shortcuts SET folder_id = ?, analyst_id = NULL, name = ? WHERE id = ?")->execute([$dest, $name, $id]);
            $newId = $id;
        }
        filesAudit($conn, $analystId, 'shortcut', ['folder_id' => $dest, 'item_name' => $name],
                   [$copy ? 'copied_from' : 'moved_from' => $s['folder_id'] === null ? 'desktop' : FilesAcl::path($conn, (int)$s['folder_id'])]);
        filesApiOk(['id' => $newId, 'folder_id' => $dest]);
    }

    if ($action === 'rename') {
        $name = filesCleanName((string)($in['name'] ?? ''));
        if ($name === '') filesApiFail('Please give the shortcut a name.');
        if ($name === $s['name']) filesApiOk(['name' => $name]);
        if ($s['folder_id'] !== null && filesNameTaken($conn, (int)$s['folder_id'], $name, 'shortcut', $id)) {
            filesApiFail('There is already something called "' . $name . '" here.');
        }
        $conn->prepare("UPDATE files_shortcuts SET name = ? WHERE id = ?")->execute([$name, $id]);
        filesAudit($conn, $analystId, 'shortcut', $where, ['renamed' => ['from' => $s['name'], 'to' => $name]]);
        filesApiOk(['name' => $name]);
    }

    if ($action === 'delete') {
        $conn->prepare("DELETE FROM files_shortcuts WHERE id = ?")->execute([$id]);
        filesAudit($conn, $analystId, 'shortcut', $where, ['deleted' => $s['name'], 'target_type' => $s['target_type'], 'target_id' => (int)$s['target_id']]);
        filesApiOk();
    }

    filesApiFail('Unknown action.');
});
