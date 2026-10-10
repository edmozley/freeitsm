<?php
/**
 * Files — the audit trail. The ONE writer of files_audit.
 *
 * Ed's rule: EVERY action is audited - opening a folder, viewing, downloading,
 * uploading, renaming, moving, copying, deleting, permission changes, taking
 * ownership, and every refusal. Unlike Knowledge (which keeps one view row per
 * person per day) nothing is collapsed: the point of this module is being able
 * to say exactly when somebody looked at something.
 *
 * The person's name and the item's path are COPIED into the row, because the
 * trail has to outlive the analyst, the file and the folder it mentions.
 *
 * Best effort: an unwritable log must never fail the action - but the failure
 * goes to error_log, because a silently broken audit table is the one failure
 * nobody would notice.
 */

require_once __DIR__ . '/acl.php';

/** The actions the trail knows. Anything else is a programming error. */
const FILES_AUDIT_ACTIONS = [
    'browse', 'search', 'view', 'download', 'upload', 'new_version',
    'create_folder', 'rename', 'move', 'copy', 'delete',
    'permissions', 'inheritance', 'watermark', 'shortcut', 'take_ownership', 'denied', 'settings',
];

/**
 * @param array $t ['folder_id'=>?, 'item_id'=>?, 'version_id'=>?, 'path'=>?] -
 *                 path is worked out from folder_id (+ item name) when omitted.
 */
function filesAudit(PDO $conn, int $analystId, string $action, array $t = [], array $detail = []): void
{
    if (!in_array($action, FILES_AUDIT_ACTIONS, true)) {
        error_log("files audit: unknown action '$action'");
        return;
    }
    try {
        $path = $t['path'] ?? null;
        if ($path === null && !empty($t['folder_id'])) {
            $path = FilesAcl::path($conn, (int)$t['folder_id']);
            if (!empty($t['item_name'])) $path .= ' / ' . $t['item_name'];
        }
        static $name = [];
        if (!array_key_exists($analystId, $name)) {
            $st = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
            $st->execute([$analystId]);
            $name[$analystId] = $st->fetchColumn() ?: null;
        }
        $conn->prepare(
            "INSERT INTO files_audit (analyst_id, analyst_name, action, folder_id, item_id, version_id,
                                      target_path, detail, ip_address, user_agent, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([
            $analystId ?: null,
            $name[$analystId],
            $action,
            $t['folder_id'] ?? null,
            $t['item_id'] ?? null,
            $t['version_id'] ?? null,
            $path !== null ? mb_substr($path, 0, 1000) : null,
            $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('files audit write failed: ' . $e->getMessage());
    }
}
