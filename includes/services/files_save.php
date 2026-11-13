<?php
/**
 * Save to Files - an email attachment, or a stored document, copied into a folder in
 * Files (GH #303, from pappas81: "Email attachments in Tickets are download only ...
 * it would be nice to have a 2nd option like Save to Files. Maybe a pre-chosen
 * directory with a new folder named after the ticket").
 *
 *   - The SOURCE is checked exactly as downloading it is: an email attachment through
 *     its ticket (analystCanAccessTicket - company AND department) with the path kept
 *     inside tickets/attachments; a document through documentCanView().
 *   - The FOLDER needs Upload in Files, as uploading does.
 *   - A file of the same name gets the same choices as an upload, from Files -> Settings
 *     -> Versions (New version, Keep both, Overwrite, Skip).
 *   - Files -> Settings -> Sharing can set a default folder, and put each ticket's files
 *     in a sub-folder named after the ticket ("ABC-123 - Printer jammed"), made on first
 *     use inside the chosen folder and reused after.
 *
 * TRAP: this writes a Files item and version the way api/files/upload.php's finish step
 * does (store bytes, then the rows, then queueIfMoved, then keep-N / overwrite). If one
 * changes, change the other - filesStoreLocalFile() is the place.
 */

require_once __DIR__ . '/../documents.php';
require_once __DIR__ . '/../service_context.php';   // ServiceError
require_once __DIR__ . '/../files/acl.php';
require_once __DIR__ . '/../files/storage.php';
require_once __DIR__ . '/../files/audit.php';
require_once __DIR__ . '/files_storage.php';
require_once __DIR__ . '/files_versions.php';

final class FilesSave
{
    /**
     * Folders the analyst can save into (Upload or better), with the Sharing defaults.
     *
     * @return array{folders: list<array{id:int, path:string}>, default_folder: int, ticket_folder: bool}
     */
    public static function targets(PDO $conn, int $analystId): array
    {
        $out = [];
        if (analystCanAccessModule($conn, $analystId, 'files')) {
            foreach (FilesAcl::levels($conn, $analystId) as $fid => $lvl) {
                if ($lvl >= FilesAcl::UPLOAD) $out[] = ['id' => (int)$fid, 'path' => FilesAcl::path($conn, (int)$fid)];
            }
            usort($out, fn($a, $b) => strnatcasecmp($a['path'], $b['path']));
        }
        $default = (int)filesSetting($conn, 'files_save_default_folder', '0');
        if (!in_array($default, array_column($out, 'id'), true)) $default = 0;   // not one they can use
        return ['folders' => $out, 'default_folder' => $default, 'ticket_folder' => filesSetting($conn, 'files_save_ticket_folder', '0') === '1'];
    }

    /** Saves the Sharing tab's two Save-to-Files settings. */
    public static function saveDefaults(PDO $conn, int $analystId, int $folderId, bool $ticketFolder): void
    {
        if ($folderId > 0 && !isset(FilesAcl::tree($conn)[$folderId])) throw new InvalidArgumentException('That folder no longer exists.');
        filesSaveSetting($conn, 'files_save_default_folder', $folderId > 0 ? (string)$folderId : null);
        filesSaveSetting($conn, 'files_save_ticket_folder', $ticketFolder ? '1' : '0');
        filesStorageReset();
        filesAudit($conn, $analystId, 'settings', ['path' => '(Settings: sharing)'], ['save_default_folder' => $folderId, 'save_ticket_folder' => $ticketFolder]);
    }

    /**
     * The file to copy, checked as downloading it is.
     *
     * @param string $source  'email_attachment' or 'document'
     * @return array{path:string, name:string, ticket_id:?int}
     * @throws ServiceError not_found (the same for "no such" and "not yours")
     */
    public static function source(PDO $conn, int $analystId, ?array $allowed, string $source, int $id): array
    {
        $none = new ServiceError('not_found', 'not_found', 'That file was not found.');
        if ($source === 'email_attachment') {
            $st = $conn->prepare("SELECT a.filename, a.file_path, e.ticket_id FROM email_attachments a JOIN emails e ON e.id = a.email_id WHERE a.id = ?");
            $st->execute([$id]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if (!$a || !$a['ticket_id'] || !analystCanAccessTicket($conn, $analystId, (int)$a['ticket_id'])) throw $none;
            // The same containment as get_attachment.php: the file must be INSIDE tickets/attachments.
            $base = realpath(dirname(__DIR__, 2) . '/tickets/attachments');
            $real = realpath(dirname(__DIR__, 2) . '/tickets/attachments/' . $a['file_path']);
            if ($base === false || $real === false || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) throw $none;
            return ['path' => $real, 'name' => (string)$a['filename'], 'ticket_id' => (int)$a['ticket_id']];
        }
        if ($source === 'document') {
            if (!documentCanView($conn, $analystId, $allowed, $id)) throw $none;
            $st = $conn->prepare("SELECT kind, title, original_name, storage_key FROM documents WHERE id = ? AND deleted_datetime IS NULL");
            $st->execute([$id]);
            $d = $st->fetch(PDO::FETCH_ASSOC);
            if (!$d || $d['kind'] !== DOCUMENT_KIND_FILE) throw $none;   // a web link or a Files link has no bytes here
            $path = documentStoragePath((string)$d['storage_key']);
            if (!is_file($path)) throw $none;
            $t = $conn->prepare("SELECT parent_id FROM document_links WHERE document_id = ? AND parent_type = 'ticket' LIMIT 1");
            $t->execute([$id]);
            $tid = $t->fetchColumn();
            return ['path' => $path, 'name' => (string)($d['original_name'] ?: $d['title']), 'ticket_id' => $tid ? (int)$tid : null];
        }
        throw $none;
    }

    /**
     * The sub-folder for a ticket's files inside $parentId - "ABC-123 - Printer jammed" -
     * reused when it is already there, made (inheriting the parent's permissions) when not.
     */
    public static function ticketFolder(PDO $conn, int $analystId, int $parentId, int $ticketId): int
    {
        $st = $conn->prepare("SELECT ticket_number, subject FROM tickets WHERE id = ?");
        $st->execute([$ticketId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return $parentId;
        $name = filesCleanName(mb_substr(trim($t['ticket_number'] . ' - ' . preg_replace('/\s+/u', ' ', (string)$t['subject'])), 0, 120));
        if ($name === '') return $parentId;
        $f = $conn->prepare("SELECT id FROM files_folders WHERE parent_id = ? AND name = ? AND deleted_datetime IS NULL LIMIT 1");
        $f->execute([$parentId, $name]);
        if ($id = $f->fetchColumn()) return (int)$id;
        $conn->prepare("INSERT INTO files_folders (parent_id, name, inherit_permissions, created_by, created_datetime) VALUES (?, ?, 1, ?, UTC_TIMESTAMP())")
             ->execute([$parentId, $name, $analystId]);
        $id = (int)$conn->lastInsertId();
        FilesAcl::reset();
        filesAudit($conn, $analystId, 'create_folder', ['folder_id' => $id], ['for_ticket' => $t['ticket_number']]);
        return $id;
    }

    /**
     * Save to Files.
     *
     * @param string $onConflict  'ask' first; then the person's choice
     * @return array  ['conflict' => true, 'name', 'choices', 'default'] or ['item_id', 'version_no', 'name', 'folder_id']
     */
    public static function save(PDO $conn, int $analystId, ?array $allowed, string $source, int $id, int $folderId, bool $intoTicketFolder, string $onConflict = 'ask'): array
    {
        if (!analystCanAccessModule($conn, $analystId, 'files')) throw new ServiceError('forbidden', 'forbidden', 'You do not have access to Files.');
        $src = self::source($conn, $analystId, $allowed, $source, $id);
        if (FilesAcl::level($conn, $analystId, $folderId) < FilesAcl::UPLOAD) throw new ServiceError('forbidden', 'forbidden', 'You cannot save into that folder - it needs Upload.');
        if ($intoTicketFolder && $src['ticket_id']) $folderId = self::ticketFolder($conn, $analystId, $folderId, $src['ticket_id']);
        return filesStoreLocalFile($conn, $analystId, $folderId, $src['path'], $src['name'], $onConflict) + ['folder_id' => $folderId];
    }
}

/**
 * Copies a local file into a Files folder as a new file or a new version, with the
 * same-name rules of an upload. The source is never moved or changed.
 *
 * @return array  ['conflict' => true, 'name', 'choices', 'default'] when the name is taken and
 *                $onConflict is 'ask'; ['skipped' => true] for skip; else ['item_id', 'version_no', 'name']
 * @throws ServiceError  a choice not offered, Overwrite without Modify, storage unavailable
 */
function filesStoreLocalFile(PDO $conn, int $analystId, int $folderId, string $localPath, string $name, string $onConflict = 'ask'): array
{
    $name = filesCleanName($name);
    if ($name === '') $name = 'file';
    $vs = FilesVersionsService::settings($conn);
    if ($onConflict !== 'ask' && !in_array($onConflict, $vs['choices'], true)) {
        throw new ServiceError('validation', 'invalid_field', 'That choice is not offered here - see Files -> Settings -> Versions.');
    }
    if ($onConflict === 'overwrite' && FilesAcl::level($conn, $analystId, $folderId) < FilesAcl::MODIFY) {
        throw new ServiceError('forbidden', 'forbidden', 'Overwrite needs Modify on the folder.');
    }
    $size = (int)filesize($localPath);
    if ($size > filesMaxUploadBytes($conn)) throw new ServiceError('validation', 'too_large', 'That file is larger than the largest upload set in Files settings.');

    $st = $conn->prepare("SELECT id, name FROM files_items WHERE folder_id = ? AND deleted_datetime IS NULL AND LOWER(name) = LOWER(?)");
    $st->execute([$folderId, $name]);
    $existing = $st->fetch(PDO::FETCH_ASSOC);
    $itemId = null;
    if ($existing) {
        if ($onConflict === 'ask') return ['conflict' => true, 'name' => $existing['name'], 'choices' => $vs['choices'], 'default' => $vs['default']];
        if ($onConflict === 'skip') return ['skipped' => true];
        if ($onConflict === 'rename') $name = filesFreeName($conn, $folderId, $name);
        else { $itemId = (int)$existing['id']; $name = $existing['name']; }
    } elseif (filesNameTaken($conn, $folderId, $name)) {
        $name = filesFreeName($conn, $folderId, $name);
    }

    $rel  = filesNewStoragePath($conn);
    $sha  = hash_file('sha256', $localPath);
    $mime = filesDetectMime($localPath, $name);
    $usedCfg = filesStorageConfig($conn);
    try {
        filesActiveStore($conn)->putFile($rel, $localPath, false);   // a copy: the source stays where it is
    } catch (StorageUnavailable $e) {
        throw new ServiceError('validation', 'unavailable', filesStoreUnavailableMessage());
    }
    $own = !$conn->inTransaction();
    if ($own) $conn->beginTransaction();
    try {
        $isNew = $itemId === null;
        if ($isNew) {
            $conn->prepare("INSERT INTO files_items (folder_id, name, size_bytes, mime_type, created_by, created_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$folderId, $name, $size, $mime, $analystId]);
            $itemId = (int)$conn->lastInsertId();
            $versionNo = 1;
        } else {
            $versionNo = 1 + (int)$conn->query("SELECT COALESCE(MAX(version_no), 0) FROM files_versions WHERE item_id = " . (int)$itemId)->fetchColumn();
        }
        $conn->prepare("INSERT INTO files_versions (item_id, version_no, storage_path, size_bytes, mime_type, sha256, uploaded_by, uploaded_datetime) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$itemId, $versionNo, $rel, $size, $mime, $sha, $analystId]);
        $versionId = (int)$conn->lastInsertId();
        $conn->prepare("UPDATE files_items SET current_version_id = ?, size_bytes = ?, mime_type = ?" . ($isNew ? '' : ', updated_by = ' . (int)$analystId . ', updated_datetime = UTC_TIMESTAMP()') . " WHERE id = ?")
             ->execute([$versionId, $size, $mime, $itemId]);
        if ($own) $conn->commit();
    } catch (Throwable $e) {
        if ($own && $conn->inTransaction()) $conn->rollBack();
        try { filesActiveStore($conn)->delete($rel); } catch (Throwable) {}   // the bytes just written are now an orphan
        throw $e;
    }
    FilesStorageService::queueIfMoved($conn, $usedCfg, $rel, $size, $sha);
    filesAudit($conn, $analystId, $isNew ? 'upload' : 'new_version',
               ['folder_id' => $folderId, 'item_id' => $itemId, 'version_id' => $versionId, 'item_name' => $name],
               ['size' => $size, 'sha256' => $sha, 'version' => $versionNo, 'saved_from' => 'freeitsm']);
    if (!$isNew) {
        $item = ['id' => $itemId, 'folder_id' => $folderId, 'name' => $name];
        if ($onConflict === 'overwrite') {
            FilesVersionsService::overwrite($conn, $analystId, $item, $versionId);
            $conn->prepare("UPDATE files_versions SET version_no = 1 WHERE id = ?")->execute([$versionId]);
            $versionNo = 1;
        } else {
            FilesVersionsService::applyKeep($conn, $itemId);
        }
    }
    return ['item_id' => $itemId, 'version_no' => $versionNo, 'name' => $name];
}
