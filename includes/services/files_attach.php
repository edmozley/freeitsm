<?php
/**
 * Attach from Files - a file in Files on a task, ticket or any record's Documents
 * panel (GH #303, from pappas81). Like iManage, two ways, and an administrator
 * decides which are offered (Files -> Settings -> Sharing, files_attach_mode):
 *
 *   link  a document of kind 'files' pointing at the Files item. Opening it goes
 *         THROUGH Files (files/?item=ID): the folder's permissions, View only and
 *         the watermark all still apply, and it is always the latest version.
 *         Someone who cannot open the folder sees neither the file nor its NAME
 *         ("hidden means not found" - Files' own rule). Never sent to the portal.
 *   copy  a snapshot of the current version, stored as an ordinary document, seen
 *         by everyone who can see the record. Needs Download on the folder (they
 *         could download and re-upload anyway), checked by uploadStoreBytes() like
 *         any upload, recorded in the Files audit as a download.
 *
 * files_attach_mode = 'link' (the default - Files' rules stay intact) | 'copy' | 'both'.
 *
 * TRAP: a 'files' document has no bytes of its own. Anything that serves or sends a
 * document must treat kind 'files' as "go to Files" (api/documents/download.php)
 * or leave it out (the portal, email). Never as a stored file.
 */

require_once __DIR__ . '/../documents.php';
require_once __DIR__ . '/../files/acl.php';
require_once __DIR__ . '/../files/storage.php';
require_once __DIR__ . '/../files/audit.php';
require_once __DIR__ . '/../uploads.php';
require_once __DIR__ . '/documents.php';

const FILES_ATTACH_MODES = ['link', 'copy', 'both'];

final class FilesAttach
{
    /** 'link', 'copy' or 'both' - what Attach from Files offers. */
    public static function mode(PDO $conn): string
    {
        $m = filesSetting($conn, 'files_attach_mode', 'link');
        return in_array($m, FILES_ATTACH_MODES, true) ? $m : 'link';
    }

    /** Saves the Sharing tab. @throws InvalidArgumentException a mode that does not exist */
    public static function saveMode(PDO $conn, int $analystId, string $mode): void
    {
        if (!in_array($mode, FILES_ATTACH_MODES, true)) throw new InvalidArgumentException('Choose how files are attached from the list.');
        $was = self::mode($conn);
        filesSaveSetting($conn, 'files_attach_mode', $mode);
        filesStorageReset();
        filesAudit($conn, $analystId, 'settings', ['path' => '(Settings: sharing)'], ['attach_mode' => $mode, 'was' => $was]);
    }

    /**
     * Files the analyst can see whose name contains $q - for the Documents panel's
     * "attach something already in FreeITSM" box. Only folders they can open are
     * searched, so nothing they could not find in Files appears here.
     *
     * @return list<array{item_id:int, name:string, path:string, size:int, can_link:bool, can_copy:bool}>
     */
    public static function search(PDO $conn, int $analystId, string $q, int $limit = 10): array
    {
        if (mb_strlen(trim($q)) < 2 || !analystCanAccessModule($conn, $analystId, 'files')) return [];
        $levels = array_filter(FilesAcl::levels($conn, $analystId), fn($l) => $l >= FilesAcl::VIEW);
        if (!$levels) return [];
        $mode = self::mode($conn);
        $ids = array_map('intval', array_keys($levels));
        $st = $conn->prepare("SELECT id, folder_id, name, size_bytes FROM files_items
                               WHERE deleted_datetime IS NULL AND name LIKE ?
                                 AND folder_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                            ORDER BY updated_datetime IS NULL, updated_datetime DESC, created_datetime DESC LIMIT " . (int)$limit);
        $st->execute(array_merge(['%' . trim($q) . '%'], $ids));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lvl = $levels[(int)$r['folder_id']] ?? 0;
            $out[] = [
                'item_id'  => (int)$r['id'],
                'name'     => $r['name'],
                'path'     => FilesAcl::path($conn, (int)$r['folder_id']),
                'size'     => (int)$r['size_bytes'],
                'can_link' => $mode !== 'copy',
                'can_copy' => $mode !== 'link' && $lvl >= FilesAcl::DOWNLOAD,
            ];
        }
        return $out;
    }

    /**
     * Attaches a Files item to a record, as a link or a copy.
     *
     * @param string $how  'link' or 'copy' - must be offered by the setting
     * @return int  the new document's id
     * @throws ServiceError forbidden / not_found / validation
     */
    public static function attach(PDO $conn, int $analystId, ?array $allowed, string $parentType, int $parentId, int $itemId, string $how): int
    {
        if (!documentEntityDef($parentType) || $parentId <= 0) throw new ServiceError('validation', 'invalid_field', 'A valid parent_type and parent_id are required.');
        if (!documentCanWriteParent($conn, $analystId, $allowed, $parentType, $parentId)) {
            throw new ServiceError('forbidden', 'forbidden', 'You do not have access to that record.');
        }
        $mode = self::mode($conn);
        if (!in_array($how, ['link', 'copy'], true) || ($mode !== 'both' && $mode !== $how)) {
            throw new ServiceError('validation', 'invalid_field', 'That way of attaching is not offered - see Files -> Settings -> Sharing.');
        }
        if (!analystCanAccessModule($conn, $analystId, 'files')) throw new ServiceError('not_found', 'not_found', 'File not found.');
        $st = $conn->prepare("SELECT * FROM files_items WHERE id = ? AND deleted_datetime IS NULL");
        $st->execute([$itemId]);
        $it = $st->fetch(PDO::FETCH_ASSOC);
        $lvl = $it ? FilesAcl::level($conn, $analystId, (int)$it['folder_id']) : 0;
        // The same answer for "no such file" and "not yours": probing must not map out Files.
        if (!$it || $lvl < FilesAcl::VIEW) throw new ServiceError('not_found', 'not_found', 'File not found.');
        $target = ['folder_id' => (int)$it['folder_id'], 'item_id' => $itemId, 'item_name' => $it['name']];
        $tenant = getActiveTenantId($conn, $analystId) ?: null;

        if ($how === 'link') {
            if (!DocumentsService::filesLinkReady($conn)) {
                throw new ServiceError('validation', 'not_ready', 'Attaching as a link needs System -> Database Verification to be run first. A copy works now.');
            }
            $own = !$conn->inTransaction();   // join a caller's transaction rather than fail
            if ($own) $conn->beginTransaction();
            try {
                $conn->prepare("INSERT INTO documents (kind, title, files_item_id, tenant_id, uploaded_by_id) VALUES ('files', ?, ?, ?, ?)")
                     ->execute([mb_substr($it['name'], 0, 255), $itemId, $tenant, $analystId]);
                $docId = (int)$conn->lastInsertId();
                documentInsertLink($conn, $docId, $parentType, $parentId, $analystId);
                if ($own) $conn->commit();
            } catch (Throwable $e) {
                if ($own && $conn->inTransaction()) $conn->rollBack();
                throw $e;
            }
            filesAudit($conn, $analystId, 'attach', $target, ['attached_as' => 'link', 'to' => "$parentType:$parentId"]);
            return $docId;
        }

        // A copy: the current version's bytes, through the same checks as any upload.
        if ($lvl < FilesAcl::DOWNLOAD) throw new ServiceError('forbidden', 'forbidden', 'You can view this file but not download it, so it cannot be copied - attach it as a link instead.');
        $v = $conn->prepare("SELECT * FROM files_versions WHERE id = ?");
        $v->execute([(int)$it['current_version_id']]);
        $ver = $v->fetch(PDO::FETCH_ASSOC);
        if (!$ver || !filesStorageKeyIsValid((string)$ver['storage_path'])) throw new ServiceError('not_found', 'not_found', 'File not found.');
        if ((int)$ver['size_bytes'] > attachmentMaxBytes()) {
            throw new ServiceError('validation', 'too_large', 'That file is larger than the ' . uploadFormatBytes(attachmentMaxBytes()) . ' attachment limit - attach it as a link instead.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'fat');
        try {
            filesStoreFor($conn, (string)$ver['storage_path'])->readTo((string)$ver['storage_path'], $tmp);
            $s = uploadStoreBytes((string)file_get_contents($tmp), $it['name'], dirname(__DIR__, 2) . '/uploads/' . DOCUMENT_STORAGE_DIR,
                                  ATTACHMENT_POLICY_DROP, attachmentAllowedTypes($conn));
        } catch (StorageUnavailable $e) {
            throw new ServiceError('validation', 'unavailable', filesStoreUnavailableMessage());
        } finally {
            @unlink($tmp);
        }
        if (empty($s['stored'])) {
            throw new ServiceError('validation', 'invalid_file', 'That kind of file cannot be attached here' . (!empty($s['reason']) ? ' (' . $s['reason'] . ')' : '') . ' - attach it as a link instead.');
        }
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $conn->prepare("INSERT INTO documents (kind, title, description, storage_key, original_name, mime_type, size_bytes, content_hash, tenant_id, uploaded_by_id)
                            VALUES ('file', ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                 ->execute([mb_substr($it['name'], 0, 255), 'Copied from Files: ' . FilesAcl::path($conn, (int)$it['folder_id']),
                            documentStorageKey($s['stored_name']), $s['original_name'], $s['mime'], $s['size'], $ver['sha256'], $tenant, $analystId]);
            $docId = (int)$conn->lastInsertId();
            documentInsertLink($conn, $docId, $parentType, $parentId, $analystId);
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            if (!empty($s['path']) && is_file($s['path'])) @unlink($s['path']);
            throw $e;
        }
        filesAudit($conn, $analystId, 'download', $target + ['version_id' => (int)$ver['id']], ['attached_as' => 'copy', 'to' => "$parentType:$parentId", 'size' => (int)$ver['size_bytes']]);
        try {
            require_once __DIR__ . '/../search/documents_index.php';
            documentTextEnqueue($conn, $docId, DOCUMENT_KIND_FILE, $s['original_name']);
            searchIndexDocument($conn, $docId);
        } catch (Throwable $e) { error_log('[FilesAttach] indexing: ' . $e->getMessage()); }
        return $docId;
    }

    /**
     * What a viewer may know about a 'files' document: its name and a way in when
     * they can open the folder; otherwise nothing but that it exists.
     *
     * @return array{access:bool, title:?string, open_url:?string, gone:bool}
     */
    public static function viewFor(PDO $conn, int $analystId, int $itemId): array
    {
        $st = $conn->prepare("SELECT id, folder_id, name, deleted_datetime FROM files_items WHERE id = ?");
        $st->execute([$itemId]);
        $it = $st->fetch(PDO::FETCH_ASSOC);
        if (!$it || $it['deleted_datetime'] !== null) return ['access' => false, 'title' => null, 'open_url' => null, 'gone' => true];
        $ok = analystCanAccessModule($conn, $analystId, 'files') && FilesAcl::level($conn, $analystId, (int)$it['folder_id']) >= FilesAcl::VIEW;
        return $ok ? ['access' => true, 'title' => $it['name'], 'open_url' => BASE_URL . 'files/?item=' . (int)$it['id'], 'gone' => false]
                   : ['access' => false, 'title' => null, 'open_url' => null, 'gone' => false];
    }
}
