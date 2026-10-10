<?php
/**
 * The Permissions window, and taking ownership.
 *
 *   GET  ?folder=<id>                      View: who has what here - the folder's
 *        own entries and (while inheriting) every ancestor's, marked inherited.
 *   POST {action: save, folder, entries: [{principal_type, principal_id, level}]}
 *        Full control. REPLACES the folder's own entries; inherited ones are
 *        changed on the folder they come from, as in Windows. Each change is a
 *        line in the audit row (added / changed / removed).
 *   POST {action: take_ownership, folder}
 *        Cap::FILES_FOLDERS - the break-glass. Works on a folder the caller
 *        CANNOT see (that is the point: an orphaned folder, its owner gone), and
 *        gives them Full control on it. Loudly audited.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

filesApiRun(function () use ($conn, $analystId) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $folderId = (int)($_GET['folder'] ?? 0);
        $lvl = filesNeed($conn, $analystId, $folderId, FilesAcl::VIEW);
        $tree = FilesAcl::tree($conn);
        $parent = $tree[$folderId]['parent_id'];
        filesApiOk([
            'folder'      => ['id' => $folderId, 'name' => $tree[$folderId]['name'], 'path' => FilesAcl::path($conn, $folderId)],
            'inherit'     => (bool)$tree[$folderId]['inherit'],
            'has_parent'  => $parent !== null,
            'entries'     => FilesAcl::entries($conn, $folderId),
            'my_level'    => $lvl,
            'can_edit'    => $lvl >= FilesAcl::FULL,
            // The viewer watermark: this folder's own setting (null = as the parent), and what that comes to.
            'watermark'   => $tree[$folderId]['watermark'],
            'watermark_effective' => FilesAcl::watermark($conn, $folderId),
            'watermark_parent'    => $parent !== null ? FilesAcl::watermark($conn, $parent) : false,
        ]);
    }

    $in     = filesApiBody();
    $action = (string)($in['action'] ?? '');
    $folderId = (int)($in['folder'] ?? 0);

    if ($action === 'take_ownership') {
        if (!filesHasCap($conn, $analystId, Cap::FILES_FOLDERS)) filesApiFail('You do not have permission to take ownership.');
        if (!isset(FilesAcl::tree($conn)[$folderId])) filesApiFail('Folder not found.');
        $before = FilesAcl::level($conn, $analystId, $folderId);
        $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level, granted_by, granted_datetime)
                        VALUES (?, 'analyst', ?, ?, ?, UTC_TIMESTAMP())
                        ON DUPLICATE KEY UPDATE level = VALUES(level), granted_by = VALUES(granted_by), granted_datetime = VALUES(granted_datetime)")
             ->execute([$folderId, $analystId, FilesAcl::FULL, $analystId]);
        FilesAcl::reset();
        filesAudit($conn, $analystId, 'take_ownership', ['folder_id' => $folderId], ['level_before' => FilesAcl::levelKey($before)]);
        filesApiOk();
    }

    if ($action === 'save') {
        filesNeed($conn, $analystId, $folderId, FilesAcl::FULL, 'permissions');

        // The new list, validated: real analysts and teams, levels 1-5, one row each.
        $want = [];
        foreach ((array)($in['entries'] ?? []) as $e) {
            $type = ($e['principal_type'] ?? '') === 'team' ? 'team' : 'analyst';
            $pid  = (int)($e['principal_id'] ?? 0);
            $lvl  = max(FilesAcl::VIEW, min(FilesAcl::FULL, (int)($e['level'] ?? 0)));
            if ($pid > 0) $want["$type:$pid"] = $lvl;
        }
        if (count($want) > 500) filesApiFail('That is too many entries for one folder.');
        foreach (array_keys($want) as $k) {
            [$type, $pid] = explode(':', $k);
            $st = $conn->prepare($type === 'team' ? "SELECT name FROM teams WHERE id = ?" : "SELECT full_name FROM analysts WHERE id = ?");
            $st->execute([(int)$pid]);
            if ($st->fetchColumn() === false) filesApiFail('One of the people or teams no longer exists.');
        }

        $st = $conn->prepare("SELECT principal_type, principal_id, level FROM files_permissions WHERE folder_id = ?");
        $st->execute([$folderId]);
        $have = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['principal_type'] . ':' . $r['principal_id']] = (int)$r['level'];

        $changes = [];
        $conn->beginTransaction();
        $del = $conn->prepare("DELETE FROM files_permissions WHERE folder_id = ? AND principal_type = ? AND principal_id = ?");
        $ups = $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level, granted_by, granted_datetime)
                               VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
                               ON DUPLICATE KEY UPDATE level = VALUES(level), granted_by = VALUES(granted_by), granted_datetime = VALUES(granted_datetime)");
        foreach ($have as $k => $lvl) {
            if (!isset($want[$k])) {
                [$type, $pid] = explode(':', $k);
                $del->execute([$folderId, $type, (int)$pid]);
                $changes[] = ['who' => $k, 'removed' => FilesAcl::levelKey($lvl)];
            }
        }
        foreach ($want as $k => $lvl) {
            if (($have[$k] ?? 0) === $lvl) continue;
            [$type, $pid] = explode(':', $k);
            $ups->execute([$folderId, $type, (int)$pid, $lvl, $analystId]);
            $changes[] = isset($have[$k])
                ? ['who' => $k, 'from' => FilesAcl::levelKey($have[$k]), 'to' => FilesAcl::levelKey($lvl)]
                : ['who' => $k, 'added' => FilesAcl::levelKey($lvl)];
        }
        $conn->commit();
        FilesAcl::reset();

        if ($changes) {
            // Names alongside the ids, so the trail reads on its own later.
            foreach ($changes as &$c) {
                [$type, $pid] = explode(':', $c['who']);
                $st = $conn->prepare($type === 'team' ? "SELECT name FROM teams WHERE id = ?" : "SELECT full_name FROM analysts WHERE id = ?");
                $st->execute([(int)$pid]);
                $c['name'] = ($type === 'team' ? 'Team: ' : '') . ($st->fetchColumn() ?: $c['who']);
            }
            unset($c);
            filesAudit($conn, $analystId, 'permissions', ['folder_id' => $folderId], ['changes' => $changes]);
        }
        filesApiOk(['changed' => count($changes), 'my_level' => FilesAcl::level($conn, $analystId, $folderId)]);
    }

    filesApiFail('Unknown action.');
});
