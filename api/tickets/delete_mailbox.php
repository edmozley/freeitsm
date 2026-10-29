<?php
/**
 * API Endpoint: Delete target mailbox
 *
 * Two calls from the delete dialog (GH #227):
 *   {id, preview: true}     what is still attached to the mailbox, and the mailboxes
 *                           its emails could move to. Changes nothing.
 *   {id, move_to: N|null}   the delete itself, all or nothing in one transaction.
 *
 * Five tables point at a mailbox. email_send_log and calendar_connections are
 * ON DELETE SET NULL and look after themselves. The other three have no rule, so a
 * plain DELETE failed with "1451 ... fk_emails_mailbox" whenever the mailbox had
 * ever received mail - and purging every ticket did not help, because mail that
 * never became a ticket (ticket_id NULL) is not purged with any ticket.
 *
 *   emails on a ticket   moved to move_to, or their mailbox cleared. TRAP: a ticket
 *                        whose emails have no mailbox can no longer be replied to by
 *                        email - getMailboxForTicket() (send_email.php) finds nothing
 *                        and there is no default mailbox to fall back on. That is why
 *                        the dialog offers a move and says so when there is none.
 *   emails on no ticket  moved with the rest, or deleted (with their attachments)
 *                        when there is nowhere to move them.
 *   whitelist, activity  belong to this mailbox alone, deleted with it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_MAILBOXES);   // settings tab — see docs/design/rbac.md

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing mailbox ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    $id = (int)$data['id'];

    // Check if mailbox exists
    $checkStmt = $conn->prepare("SELECT id, name, tenant_id FROM target_mailboxes WHERE id = ?");
    $checkStmt->execute([$id]);
    $mailbox = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$mailbox) {
        echo json_encode(['success' => false, 'error' => 'Mailbox not found']);
        exit;
    }

    // Where its emails may go: another mailbox of the same company, or a shared
    // one. Never a different company's - replies on those tickets would then go
    // out from that company's address.
    $targetStmt = $conn->prepare(
        "SELECT id, name FROM target_mailboxes
          WHERE id <> ? AND (tenant_id IS NULL OR tenant_id <=> ?)
          ORDER BY is_active DESC, name"
    );
    $targetStmt->execute([$id, $mailbox['tenant_id']]);
    $targets = $targetStmt->fetchAll(PDO::FETCH_ASSOC);

    // "On a ticket" means a ticket that still exists. An email whose ticket was
    // purged by some older route counts as loose, like one that never had a ticket.
    $countStmt = $conn->prepare(
        "SELECT SUM(t.id IS NOT NULL) AS on_ticket, SUM(t.id IS NULL) AS loose
           FROM emails e LEFT JOIN tickets t ON t.id = e.ticket_id
          WHERE e.mailbox_id = ?"
    );
    $countStmt->execute([$id]);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);
    $onTicket = (int)($counts['on_ticket'] ?? 0);
    $loose    = (int)($counts['loose'] ?? 0);

    $ksStmt = $conn->prepare(
        "SELECT 1 FROM system_settings WHERE setting_key = 'knowledge_email_mailbox_id' AND setting_value = ?"
    );
    $ksStmt->execute([(string)$id]);
    $usedForKnowledge = (bool)$ksStmt->fetchColumn();

    if (!empty($data['preview'])) {
        echo json_encode([
            'success'            => true,
            'name'               => $mailbox['name'],
            'emails_on_tickets'  => $onTicket,
            'emails_loose'       => $loose,
            'used_for_knowledge' => $usedForKnowledge,
            'move_targets'       => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']], $targets),
        ]);
        exit;
    }

    $moveTo = !empty($data['move_to']) ? (int)$data['move_to'] : null;
    if ($moveTo !== null && !in_array($moveTo, array_map(fn($r) => (int)$r['id'], $targets), true)) {
        echo json_encode(['success' => false, 'error' => 'The emails cannot be moved to that mailbox']);
        exit;
    }

    // Loose emails are deleted only when there is nowhere to move them. Their
    // attachment files are read first and removed after the commit, the same
    // order as permanently_delete_ticket.php (files are not transactional).
    $looseWhere = "mailbox_id = ? AND (ticket_id IS NULL OR ticket_id NOT IN (SELECT id FROM tickets))";
    $attachmentPaths = [];
    if ($moveTo === null && $loose > 0) {
        $pathStmt = $conn->prepare(
            "SELECT file_path FROM email_attachments WHERE email_id IN (SELECT id FROM emails WHERE $looseWhere)"
        );
        $pathStmt->execute([$id]);
        $attachmentPaths = $pathStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $conn->beginTransaction();

    if ($moveTo !== null) {
        $conn->prepare("UPDATE emails SET mailbox_id = ? WHERE mailbox_id = ?")->execute([$moveTo, $id]);
    } else {
        // Attachments before their emails: fk_email_attachments_email has no cascade.
        $conn->prepare(
            "DELETE FROM email_attachments WHERE email_id IN (SELECT id FROM (SELECT id FROM emails WHERE $looseWhere) x)"
        )->execute([$id]);
        $conn->prepare("DELETE FROM emails WHERE $looseWhere")->execute([$id]);
        $conn->prepare("UPDATE emails SET mailbox_id = NULL WHERE mailbox_id = ?")->execute([$id]);
    }

    $conn->prepare("DELETE FROM mailbox_email_whitelist WHERE mailbox_id = ?")->execute([$id]);
    $conn->prepare("DELETE FROM mailbox_activity_log WHERE mailbox_id = ?")->execute([$id]);

    // Knowledge article sharing would otherwise keep pointing at a mailbox that
    // is gone; blank it so Knowledge settings asks for a new one.
    if ($usedForKnowledge) {
        $conn->prepare(
            "UPDATE system_settings SET setting_value = '', updated_datetime = UTC_TIMESTAMP()
              WHERE setting_key = 'knowledge_email_mailbox_id'"
        )->execute();
    }

    $conn->prepare("DELETE FROM target_mailboxes WHERE id = ?")->execute([$id]);

    $conn->commit();

    $attachBase = dirname(dirname(__DIR__)) . '/tickets/attachments/';
    foreach ($attachmentPaths as $rel) {
        $full = $attachBase . $rel;
        if (is_file($full)) {
            @unlink($full);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Mailbox deleted successfully'
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
