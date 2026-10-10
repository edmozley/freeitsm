<?php
/**
 * GET ?folder=<id> - what is inside a folder, for an Explorer window.
 * GET ?folder=0     - "Documents": the top of what this person can see.
 * &tree=1           - subfolders only, for expanding the left-hand tree. Not
 *                     audited: it shows names the person is already allowed to
 *                     see, and the tree expands on hover-and-click far too often
 *                     for a row each time to mean anything. Opening a folder IS.
 *
 * "The top" is not the real top level: it is every folder you can see whose
 * parent you cannot. Someone granted only Finance/Invoices sees Invoices at the
 * top, with its full path as a hint, and never learns what else is in Finance.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

filesApiRun(function () use ($conn, $analystId) {
    $folderId = (int)($_GET['folder'] ?? 0);
    $treeOnly = !empty($_GET['tree']);
    $levels   = FilesAcl::levels($conn, $analystId);
    $tree     = FilesAcl::tree($conn);

    if ($folderId > 0 && !isset($levels[$folderId])) filesApiFail('Folder not found.');

    // Subfolders the caller can see.
    $childIds = [];
    foreach ($levels as $fid => $lvl) {
        $f = $tree[$fid] ?? null;
        if (!$f) continue;
        if ($folderId > 0 ? $f['parent_id'] === $folderId
                          : ($f['parent_id'] === null || !isset($levels[$f['parent_id']]))) {
            $childIds[] = $fid;
        }
    }

    $folders = [];
    if ($childIds) {
        $in = implode(',', array_fill(0, count($childIds), '?'));
        $st = $conn->prepare("SELECT f.id, f.name, f.parent_id, f.created_datetime, COALESCE(f.updated_datetime, f.created_datetime) AS modified,
                                     (SELECT COUNT(*) FROM files_folders c WHERE c.parent_id = f.id AND c.deleted_datetime IS NULL) AS sub_count
                                FROM files_folders f WHERE f.id IN ($in)");
        $st->execute($childIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['id'];
            $folders[] = [
                'id'        => $id,
                'name'      => $r['name'],
                'modified'  => $r['modified'],
                'level'     => $levels[$id],
                // Whether the tree should offer an expander. Counts subfolders the
                // caller may not see, which is fine: expanding one reveals nothing.
                'has_children' => (int)$r['sub_count'] > 0,
                'path'      => $folderId === 0 && $r['parent_id'] !== null ? FilesAcl::path($conn, $id) : null,
            ];
        }
        usort($folders, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    }

    if ($treeOnly) filesApiOk(['folders' => $folders]);

    $items = [];
    if ($folderId > 0) {
        $st = $conn->prepare("SELECT i.id, i.name, i.size_bytes, i.mime_type,
                                     COALESCE(i.updated_datetime, i.created_datetime) AS modified,
                                     COALESCE(u.full_name, c.full_name) AS modified_by,
                                     (SELECT COUNT(*) FROM files_versions v WHERE v.item_id = i.id) AS versions
                                FROM files_items i
                           LEFT JOIN analysts c ON c.id = i.created_by
                           LEFT JOIN analysts u ON u.id = i.updated_by
                               WHERE i.folder_id = ? AND i.deleted_datetime IS NULL");
        $st->execute([$folderId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'id'          => (int)$r['id'],
                'name'        => $r['name'],
                'size'        => (int)$r['size_bytes'],
                'mime'        => $r['mime_type'],
                'modified'    => $r['modified'],
                'modified_by' => $r['modified_by'],
                'versions'    => (int)$r['versions'],
            ];
        }
        usort($items, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    }

    // Breadcrumbs: up through the ancestors the caller can see, and no further.
    $crumbs = [];
    $f = $folderId > 0 ? $tree[$folderId] : null;
    while ($f && isset($levels[$f['id']])) {
        array_unshift($crumbs, ['id' => $f['id'], 'name' => $f['name']]);
        $f = $f['parent_id'] !== null ? ($tree[$f['parent_id']] ?? null) : null;
    }

    $level = $folderId > 0 ? $levels[$folderId] : 0;
    filesAudit($conn, $analystId, 'browse', $folderId > 0 ? ['folder_id' => $folderId] : ['path' => '(Documents)']);

    filesApiOk([
        'folder'  => $folderId > 0 ? [
            'id'      => $folderId,
            'name'    => $tree[$folderId]['name'],
            'inherit' => (bool)$tree[$folderId]['inherit'],
            'level'   => $level,
            'parent'  => ($p = $tree[$folderId]['parent_id']) !== null && isset($levels[$p]) ? $p : 0,
        ] : null,
        'crumbs'  => $crumbs,
        'folders' => $folders,
        'items'   => $items,
        'can'     => [
            'upload' => $folderId > 0 ? $level >= FilesAcl::UPLOAD : filesHasCap($conn, $analystId, Cap::FILES_FOLDERS),
            'modify' => $level >= FilesAcl::MODIFY,
            'full'   => $level >= FilesAcl::FULL,
            'download' => $level >= FilesAcl::DOWNLOAD,
        ],
    ]);
});
