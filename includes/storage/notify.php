<?php
/**
 * Telling administrators about storage changes (storage series N1, PR #318 review).
 *
 * Pointing storage somewhere new copies every stored file there - the change an
 * administrator most needs to hear about, as soon as it happens. So every active
 * administrator gets a bell and, when a mailbox can send, an email whenever a storage
 * location changes, a move starts or a move ends: Files (Files -> Settings -> Storage)
 * and each store on System -> Storage. The same pattern as the API threat protection
 * alerts (apiSecurityNotifyAdmins in includes/api_security.php).
 *
 * The administrator who made the change is not told about their own action. Never
 * throws: telling people must never break the change itself.
 */

/**
 * @param string $title    one line, e.g. 'Files storage location changed'
 * @param string $message  what happened, never a secret
 * @param string $entity   'storage' (System -> Storage) or 'files_storage' (Files -> Settings -> Storage)
 * @param int    $actorId  who did it (not told); 0 = the system (a job)
 */
function storageNotifyAdmins(PDO $conn, string $title, string $message, string $entity, int $actorId = 0): void
{
    try {
        $admins = $conn->query("SELECT id, email FROM analysts WHERE is_admin = 1 AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[storage] could not list administrators to tell: ' . $e->getMessage());
        return;
    }
    $admins = array_values(array_filter($admins, fn($a) => (int)$a['id'] !== $actorId));
    if (!$admins) return;
    try {
        require_once __DIR__ . '/../services/notifications.php';
        foreach ($admins as $a) {
            NotificationsService::notify($conn, [
                'analyst_id' => (int)$a['id'], 'event_type' => 'storage.changed', 'entity_type' => $entity, 'entity_id' => 1,
                'title' => mb_substr($title, 0, 255), 'body' => mb_substr($message, 0, 500),
                'actor_id' => $actorId > 0 ? $actorId : null, 'actor_name' => null,
            ]);
        }
    } catch (Throwable $e) {
        error_log('[storage] bell: ' . $e->getMessage());
    }
    try {
        require_once __DIR__ . '/../mailbox_send.php';
        require_once __DIR__ . '/../mailbox_graph.php';
        $mailbox = null;
        foreach ($conn->query("SELECT * FROM target_mailboxes WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $c = decryptMailboxRow($row);
            if (mailboxCanSend($c)) { $mailbox = $c; break; }
        }
        if (!$mailbox) { error_log('[storage] no mailbox can send: administrators were told by the bell only (' . $title . ')'); return; }
        $where = $entity === 'files_storage' ? 'Files &rarr; Settings &rarr; Storage' : 'System &rarr; Storage';
        $html = '<p>' . htmlspecialchars($message) . '</p><p>' . $where . ' has the details.</p>';
        foreach ($admins as $a) {
            if (filter_var((string)$a['email'], FILTER_VALIDATE_EMAIL)) mailboxSendSimple($conn, $mailbox, (string)$a['email'], 'FreeITSM: ' . $title, $html, 'storage');
        }
    } catch (Throwable $e) {
        error_log('[storage] email: ' . $e->getMessage());
    }
}
