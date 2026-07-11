<?php
/**
 * API Endpoint: PERMANENTLY delete a ticket and all associated data.
 * Irreversible — only allowed on a ticket already in the trash. Reached from
 * the Trash view ("Delete permanently" / "Empty trash").
 *
 * Children are removed in foreign-key-safe order inside a transaction. Several
 * child tables have FKs with NO "ON DELETE CASCADE" (email_attachments→emails,
 * ticket_notes/ticket_audit/ticket_time_entries→tickets), so they must be
 * deleted explicitly and in the right order — otherwise a ticket whose emails
 * carry attachments fails with "1451 Cannot delete or update a parent row".
 * The remaining children (ticket_recordings, ticket_csat_responses,
 * ticket_cmdb_objects, sla_notifications_sent, tasks) have CASCADE / SET NULL
 * rules and clean themselves up.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['ticket_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}
$ticketId = (int)$data['ticket_id'];

try {
    $conn = connectToDatabase();

    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    // Safety: only permanently remove a ticket that's already in the trash.
    $checkStmt = $conn->prepare("SELECT id FROM tickets WHERE id = ? AND deleted_datetime IS NOT NULL");
    $checkStmt->execute([$ticketId]);
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Ticket must be in the trash before it can be permanently deleted']);
        exit;
    }

    // Collect attachment file paths up front so we can remove the physical files
    // after the rows are gone (filesystem ops aren't transactional).
    $pathStmt = $conn->prepare(
        "SELECT file_path FROM email_attachments
         WHERE email_id IN (SELECT id FROM emails WHERE ticket_id = ?)"
    );
    $pathStmt->execute([$ticketId]);
    $attachmentPaths = $pathStmt->fetchAll(PDO::FETCH_COLUMN);

    $conn->beginTransaction();

    // 1. Email attachments (child of emails — must go before emails)
    $conn->prepare(
        "DELETE FROM email_attachments
         WHERE email_id IN (SELECT id FROM emails WHERE ticket_id = ?)"
    )->execute([$ticketId]);

    // 2. Emails linked to the ticket
    $conn->prepare("DELETE FROM emails WHERE ticket_id = ?")->execute([$ticketId]);

    // 3. Notes
    $conn->prepare("DELETE FROM ticket_notes WHERE ticket_id = ?")->execute([$ticketId]);

    // 4. Audit history
    $conn->prepare("DELETE FROM ticket_audit WHERE ticket_id = ?")->execute([$ticketId]);

    // 5. Time entries
    $conn->prepare("DELETE FROM ticket_time_entries WHERE ticket_id = ?")->execute([$ticketId]);

    // 6. The ticket itself (CASCADE/SET NULL children go with it)
    $conn->prepare("DELETE FROM tickets WHERE id = ?")->execute([$ticketId]);

    $conn->commit();

    // Best-effort removal of the now-orphaned attachment files. Failures here
    // never undo the delete — the rows are already gone.
    $attachBase = dirname(dirname(__DIR__)) . '/tickets/attachments/';
    foreach ($attachmentPaths as $rel) {
        $full = $attachBase . $rel;
        if (is_file($full)) {
            @unlink($full);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Ticket permanently deleted']);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
