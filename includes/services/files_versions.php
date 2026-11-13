<?php
/**
 * Files: versions, and what happens when a file of the same name is uploaded (GH #303).
 *
 *   Settings (Files -> Settings -> Versions, Cap::FILES_VERSIONS):
 *     files_conflict_choices  which choices the same-name dialog offers, a comma list of
 *                             FILES_CONFLICT_CHOICES - default 'version,rename,skip', so
 *                             Overwrite appears only once someone switches it on
 *     files_conflict_default  the highlighted choice - default 'version'
 *     files_keep_versions     0 = every version (default), else keep the newest N
 *
 *   The four choices:
 *     version    the upload becomes the file's new version; the old one stays in its history
 *     rename     keep both - the upload is saved as "name (2).ext"
 *     overwrite  the upload replaces the file and EVERY earlier version is deleted
 *     skip       nothing is uploaded
 *
 *   Restore makes a NEW version that shares the old version's bytes (as a copied file
 *   already does), so restoring never loses anything - the current version stays in
 *   the history beneath it.
 *
 * TRAP: bytes are SHARED - a copied file's version 1 points at its source's storage
 * path, and a restored version at the version it restores. Never delete bytes because
 * a version row went: filesPurgeBytesIfUnused() deletes them only when no files_versions
 * row (live or binned file) still names that path.
 */

require_once __DIR__ . '/../files/storage.php';
require_once __DIR__ . '/../files/audit.php';

const FILES_CONFLICT_CHOICES = ['version', 'rename', 'overwrite', 'skip'];
const FILES_CONFLICT_DEFAULT_CHOICES = 'version,rename,skip';
/** The choices for "How many versions to keep" - a dropdown, never a typed number. 0 = all. */
const FILES_KEEP_VERSIONS_CHOICES = [0, 2, 3, 5, 10, 20, 50];

final class FilesVersionsService
{
    /**
     * The same-name and version settings, as the desktop and the settings tab need them.
     *
     * @return array{choices: list<string>, default: string, keep: int}
     */
    public static function settings(PDO $conn): array
    {
        $choices = array_values(array_intersect(FILES_CONFLICT_CHOICES,
            array_map('trim', explode(',', filesSetting($conn, 'files_conflict_choices', FILES_CONFLICT_DEFAULT_CHOICES)))));
        if (!array_diff($choices, ['skip'])) $choices = explode(',', FILES_CONFLICT_DEFAULT_CHOICES);   // never only "Skip"
        $default = filesSetting($conn, 'files_conflict_default', 'version');
        if (!in_array($default, $choices, true) || $default === 'skip') $default = array_values(array_diff($choices, ['skip']))[0];
        $keep = (int)filesSetting($conn, 'files_keep_versions', '0');
        if (!in_array($keep, FILES_KEEP_VERSIONS_CHOICES, true)) $keep = 0;
        return ['choices' => $choices, 'default' => $default, 'keep' => $keep];
    }

    /**
     * Saves the Versions tab.
     *
     * @param array $in  choices (list), default (string), keep (int, one of FILES_KEEP_VERSIONS_CHOICES)
     * @throws InvalidArgumentException  a choice that does not exist, none but Skip, or a keep value not offered
     */
    public static function saveSettings(PDO $conn, int $analystId, array $in): void
    {
        $choices = array_values(array_intersect(FILES_CONFLICT_CHOICES, array_map('strval', (array)($in['choices'] ?? []))));
        if (!array_diff($choices, ['skip'])) {
            throw new InvalidArgumentException('Tick at least one of New version, Keep both or Overwrite - Skip alone would mean a file can never be uploaded over another.');
        }
        $default = (string)($in['default'] ?? '');
        if (!in_array($default, $choices, true) || $default === 'skip') {
            throw new InvalidArgumentException('Choose which of the ticked choices is highlighted.');
        }
        $keep = (int)($in['keep'] ?? 0);
        if (!in_array($keep, FILES_KEEP_VERSIONS_CHOICES, true)) throw new InvalidArgumentException('Choose how many versions to keep from the list.');
        $was = self::settings($conn);
        filesSaveSetting($conn, 'files_conflict_choices', implode(',', $choices));
        filesSaveSetting($conn, 'files_conflict_default', $default);
        filesSaveSetting($conn, 'files_keep_versions', (string)$keep);
        filesStorageReset();
        filesAudit($conn, $analystId, 'settings', ['path' => '(Settings: versions)'], [
            'choices' => implode(',', $choices), 'default' => $default, 'keep_versions' => $keep,
            'was' => implode(',', $was['choices']) . ' / ' . $was['default'] . ' / ' . $was['keep'],
        ]);
    }

    /**
     * Makes version $versionId the file's current one again, as a NEW version that
     * shares its bytes. The version that was current stays in the history.
     *
     * @return int  the new version's number
     * @throws InvalidArgumentException  the version is not this file's, or is already current
     */
    public static function restore(PDO $conn, int $analystId, array $item, int $versionId): int
    {
        $v = self::version($conn, (int)$item['id'], $versionId);
        if ((int)$item['current_version_id'] === $versionId) throw new InvalidArgumentException('That is already the current version.');
        $own = !$conn->inTransaction();   // join a caller's transaction rather than fail
        if ($own) $conn->beginTransaction();
        try {
            $no = 1 + (int)$conn->query("SELECT COALESCE(MAX(version_no), 0) FROM files_versions WHERE item_id = " . (int)$item['id'])->fetchColumn();
            $conn->prepare("INSERT INTO files_versions (item_id, version_no, storage_path, size_bytes, mime_type, sha256, uploaded_by, uploaded_datetime, note, restored_from_no)
                            VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?)")
                 ->execute([(int)$item['id'], $no, $v['storage_path'], $v['size_bytes'], $v['mime_type'], $v['sha256'], $analystId, $v['note'], (int)$v['version_no']]);
            $newId = (int)$conn->lastInsertId();
            $conn->prepare("UPDATE files_items SET current_version_id = ?, size_bytes = ?, mime_type = ?, updated_by = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$newId, $v['size_bytes'], $v['mime_type'], $analystId, (int)$item['id']]);
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        filesAudit($conn, $analystId, 'restore_version',
                   ['folder_id' => (int)$item['folder_id'], 'item_id' => (int)$item['id'], 'version_id' => $newId, 'item_name' => $item['name']],
                   ['restored_from' => (int)$v['version_no'], 'version' => $no]);
        self::applyKeep($conn, (int)$item['id']);
        return $no;
    }

    /**
     * Deletes one earlier version (never the current one). Its bytes go only if
     * nothing else still uses them.
     *
     * @throws InvalidArgumentException  the version is not this file's, or is the current one
     */
    public static function deleteVersion(PDO $conn, int $analystId, array $item, int $versionId): void
    {
        $v = self::version($conn, (int)$item['id'], $versionId);
        if ((int)$item['current_version_id'] === $versionId) throw new InvalidArgumentException('The current version cannot be deleted - restore another one first, or delete the file.');
        $conn->prepare("DELETE FROM files_versions WHERE id = ?")->execute([$versionId]);
        filesPurgeBytesIfUnused($conn, (string)$v['storage_path']);
        filesAudit($conn, $analystId, 'delete_version',
                   ['folder_id' => (int)$item['folder_id'], 'item_id' => (int)$item['id'], 'item_name' => $item['name']],
                   ['version' => (int)$v['version_no'], 'sha256' => $v['sha256']]);
    }

    /**
     * Overwrite: every version but $keepVersionId is deleted (the upload that just
     * became current). Called by upload.php when the person chose Overwrite.
     */
    public static function overwrite(PDO $conn, int $analystId, array $item, int $keepVersionId): void
    {
        $st = $conn->prepare("SELECT id, version_no, storage_path FROM files_versions WHERE item_id = ? AND id <> ?");
        $st->execute([(int)$item['id'], $keepVersionId]);
        $gone = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$gone) return;
        $conn->prepare("DELETE FROM files_versions WHERE item_id = ? AND id <> ?")->execute([(int)$item['id'], $keepVersionId]);
        foreach ($gone as $g) filesPurgeBytesIfUnused($conn, (string)$g['storage_path']);
        filesAudit($conn, $analystId, 'overwrite',
                   ['folder_id' => (int)$item['folder_id'], 'item_id' => (int)$item['id'], 'version_id' => $keepVersionId, 'item_name' => $item['name']],
                   ['versions_deleted' => implode(',', array_column($gone, 'version_no'))]);
    }

    /**
     * Keeps the newest N versions of one file (the setting), deleting older ones. The
     * current version always stays, even if it is not the newest number.
     *
     * @return int  how many versions were deleted
     */
    public static function applyKeep(PDO $conn, int $itemId, ?int $keep = null): int
    {
        $keep ??= self::settings($conn)['keep'];
        if ($keep <= 0) return 0;
        $st = $conn->prepare("SELECT v.id, v.version_no, v.storage_path, (v.id = i.current_version_id) AS is_current
                                FROM files_versions v JOIN files_items i ON i.id = v.item_id
                               WHERE v.item_id = ? ORDER BY is_current DESC, v.version_no DESC");
        $st->execute([$itemId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $old = array_slice($rows, $keep);
        foreach ($old as $o) {
            $conn->prepare("DELETE FROM files_versions WHERE id = ?")->execute([(int)$o['id']]);
            filesPurgeBytesIfUnused($conn, (string)$o['storage_path']);
        }
        if ($old) filesAudit($conn, 0, 'delete_version', ['item_id' => $itemId], ['kept' => $keep, 'versions_deleted' => implode(',', array_column($old, 'version_no'))]);
        return count($old);
    }

    /** One version of a file, or InvalidArgumentException. */
    private static function version(PDO $conn, int $itemId, int $versionId): array
    {
        $st = $conn->prepare("SELECT * FROM files_versions WHERE id = ? AND item_id = ?");
        $st->execute([$versionId, $itemId]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) throw new InvalidArgumentException('Version not found.');
        return $v;
    }
}

/**
 * Deletes stored bytes ONLY when no files_versions row still names them - a copied
 * file, a restored version or a binned file's history may share them. During a
 * storage move the bytes may be in the old place, the new place, or both: both are
 * cleared, and the move forgets the key. A store that cannot be reached leaves the
 * bytes behind (logged) rather than failing what the person asked for.
 */
function filesPurgeBytesIfUnused(PDO $conn, string $key): void
{
    if (!filesStorageKeyIsValid($key)) return;
    $st = $conn->prepare("SELECT 1 FROM files_versions WHERE storage_path = ? LIMIT 1");
    $st->execute([$key]);
    if ($st->fetchColumn()) return;
    $stores = [filesActiveStore($conn)];
    try { if (($prev = filesPreviousStore($conn)) !== null) $stores[] = $prev; } catch (Throwable) {}
    foreach ($stores as $store) {
        try { $store->delete($key); }
        catch (Throwable $e) { error_log("files: could not delete unused bytes $key from " . $store->label() . ': ' . $e->getMessage()); }
    }
    $conn->prepare("DELETE FROM storage_migration_items WHERE store = 'files' AND object_key = ?")->execute([$key]);
}
