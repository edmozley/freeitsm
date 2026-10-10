<?php
/**
 * GET - the start menu's Recent list: files this person last uploaded,
 * downloaded or viewed, newest first, read back out of the audit trail - and
 * filtered through the CURRENT permissions, so a file they have since lost
 * access to (or that was deleted) drops off rather than leaking its name.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

filesApiRun(function () use ($conn, $analystId) {
    $st = $conn->prepare("SELECT item_id, MAX(id) AS last_id, MAX(created_datetime) AS at
                            FROM files_audit
                           WHERE analyst_id = ? AND item_id IS NOT NULL
                             AND action IN ('view', 'download', 'upload', 'new_version')
                        GROUP BY item_id ORDER BY last_id DESC LIMIT 40");
    $st->execute([$analystId]);
    $levels = FilesAcl::levels($conn, $analystId);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $it = filesItem($conn, (int)$r['item_id']);
        if (!$it || !isset($levels[(int)$it['folder_id']])) continue;
        $out[] = [
            'id' => (int)$it['id'], 'name' => $it['name'], 'folder_id' => (int)$it['folder_id'],
            'size' => (int)$it['size_bytes'], 'at' => $r['at'],
            'path' => FilesAcl::path($conn, (int)$it['folder_id']),
        ];
        if (count($out) >= 12) break;
    }
    filesApiOk(['recent' => $out]);
});
