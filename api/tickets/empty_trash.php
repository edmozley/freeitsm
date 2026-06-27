<?php
/**
 * API Endpoint: Empty the trash — permanently delete every trashed ticket in
 * the analyst's active company (and all their emails/attachments/notes/etc.).
 * Irreversible. Scoped to the active company so it matches the Trash view.
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
$analystId = (int)$_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();

    // Trashed tickets in the active company (no-op tenant filter at N=1).
    list($ttSql, $ttParams) = ticketTenantFilter($conn, $analystId, 't');
    $idStmt = $conn->prepare("SELECT t.id FROM tickets t WHERE t.deleted_datetime IS NOT NULL" . $ttSql);
    $idStmt->execute($ttParams);
    $ids = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN));

    if (!$ids) {
        echo json_encode(['success' => true, 'deleted' => 0]);
        exit;
    }
    $place = implode(',', array_fill(0, count($ids), '?'));

    // Attachment file paths to remove after the rows are gone.
    $pathStmt = $conn->prepare(
        "SELECT file_path FROM email_attachments
          WHERE email_id IN (SELECT id FROM emails WHERE ticket_id IN ($place))"
    );
    $pathStmt->execute($ids);
    $attachmentPaths = $pathStmt->fetchAll(PDO::FETCH_COLUMN);

    $conn->beginTransaction();
    // FK-safe order (mirrors permanently_delete_ticket.php).
    $conn->prepare("DELETE FROM email_attachments WHERE email_id IN (SELECT id FROM emails WHERE ticket_id IN ($place))")->execute($ids);
    $conn->prepare("DELETE FROM emails WHERE ticket_id IN ($place)")->execute($ids);
    $conn->prepare("DELETE FROM ticket_notes WHERE ticket_id IN ($place)")->execute($ids);
    $conn->prepare("DELETE FROM ticket_audit WHERE ticket_id IN ($place)")->execute($ids);
    $conn->prepare("DELETE FROM ticket_time_entries WHERE ticket_id IN ($place)")->execute($ids);
    $conn->prepare("DELETE FROM tickets WHERE id IN ($place)")->execute($ids);
    $conn->commit();

    // Best-effort file cleanup.
    $attachBase = dirname(dirname(__DIR__)) . '/tickets/attachments/';
    foreach ($attachmentPaths as $rel) {
        $full = $attachBase . $rel;
        if (is_file($full)) @unlink($full);
    }

    echo json_encode(['success' => true, 'deleted' => count($ids)]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
