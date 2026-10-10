<?php
/**
 * GET ?type=item|folder&id= - the Properties window: details, and for a file
 * its versions with their SHA-256 (so someone can prove the copy they hold is
 * the one that was uploaded). Audited as a browse - looking at a file's details
 * is still looking.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

filesApiRun(function () use ($conn, $analystId) {
    $type = ($_GET['type'] ?? '') === 'folder' ? 'folder' : 'item';
    $id   = (int)($_GET['id'] ?? 0);

    if ($type === 'folder') {
        $lvl = filesNeed($conn, $analystId, $id, FilesAcl::VIEW);
        $st = $conn->prepare("SELECT f.*, c.full_name AS created_by_name, u.full_name AS updated_by_name
                                FROM files_folders f
                           LEFT JOIN analysts c ON c.id = f.created_by
                           LEFT JOIN analysts u ON u.id = f.updated_by
                               WHERE f.id = ?");
        $st->execute([$id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        $ids = FilesAcl::descendants($conn, $id);
        $in  = implode(',', array_map('intval', $ids));
        $agg = $conn->query("SELECT COUNT(*) AS n, COALESCE(SUM(size_bytes), 0) AS bytes FROM files_items
                              WHERE folder_id IN ($in) AND deleted_datetime IS NULL")->fetch(PDO::FETCH_ASSOC);
        filesAudit($conn, $analystId, 'browse', ['folder_id' => $id], ['properties' => true]);
        filesApiOk(['properties' => [
            'type'       => 'folder',
            'name'       => $f['name'],
            'path'       => FilesAcl::path($conn, $id),
            'created'    => $f['created_datetime'],
            'created_by' => $f['created_by_name'],
            'modified'   => $f['updated_datetime'],
            'modified_by'=> $f['updated_by_name'],
            'inherit'    => (bool)$f['inherit_permissions'],
            'folders'    => count($ids) - 1,
            'files'      => (int)$agg['n'],
            'size'       => (int)$agg['bytes'],
            'level'      => $lvl,
        ]]);
    }

    $it = filesNeedItem($conn, $analystId, $id, FilesAcl::VIEW);
    $lvl = FilesAcl::level($conn, $analystId, (int)$it['folder_id']);
    $st = $conn->prepare("SELECT v.id, v.version_no, v.size_bytes, v.mime_type, v.sha256, v.uploaded_datetime, a.full_name AS uploaded_by
                            FROM files_versions v LEFT JOIN analysts a ON a.id = v.uploaded_by
                           WHERE v.item_id = ? ORDER BY v.version_no DESC");
    $st->execute([$id]);
    $c = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
    $c->execute([(int)$it['created_by']]);
    filesAudit($conn, $analystId, 'browse', ['folder_id' => (int)$it['folder_id'], 'item_id' => $id, 'item_name' => $it['name']], ['properties' => true]);
    filesApiOk(['properties' => [
        'type'       => 'item',
        'name'       => $it['name'],
        'path'       => FilesAcl::path($conn, (int)$it['folder_id']),
        'size'       => (int)$it['size_bytes'],
        'mime'       => $it['mime_type'],
        'created'    => $it['created_datetime'],
        'created_by' => $c->fetchColumn() ?: null,
        'modified'   => $it['updated_datetime'],
        'versions'   => array_map(fn($v) => [
            'id' => (int)$v['id'], 'version_no' => (int)$v['version_no'], 'size' => (int)$v['size_bytes'],
            'sha256' => $v['sha256'], 'uploaded' => $v['uploaded_datetime'], 'uploaded_by' => $v['uploaded_by'],
        ], $st->fetchAll(PDO::FETCH_ASSOC)),
        'current_version_id' => (int)$it['current_version_id'],
        'level'      => $lvl,
    ]]);
});
