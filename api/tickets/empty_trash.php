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
requireModuleAccessJson('tickets');
$analystId = (int)$_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();

    // 🔴 NEVER EMPTIES THE TRASH ACROSS COMPANIES (#1554).
    //
    // This endpoint shares ticketTenantFilter() with every ticket LIST, and that
    // filter widens to the whole accessible set in the consolidated view. For a
    // list that is the point; here it would turn one button into a permanent
    // deletion across all three schools at once, which is not what anybody
    // clicking "empty trash" while looking at a combined board expects — and it
    // cannot be undone.
    //
    // So this one deliberately does NOT follow the view. It always scopes to the
    // single active company, and the UI tells the analyst which that is.
    list($ttSql, $ttParams) = ticketTenantFilter($conn, $analystId, 't', true);   // true = one company only
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

    // Document links on the notes about to be deleted (discussion #69).
    // ⚠️ MUST run before the notes go — document_links.parent_id is polymorphic,
    // so no foreign key cleans up after it, and once the note rows are gone
    // there is nothing left to match the links against. The nightly orphan sweep
    // in documentsCollectOrphans() would catch them either way, but a link that
    // resolves to nothing should not survive the transaction that made it so.
    require_once '../../includes/documents.php';
    $noteIdStmt = $conn->prepare("SELECT id FROM ticket_notes WHERE ticket_id IN ($place)");
    $noteIdStmt->execute($ids);
    $noteIds = $noteIdStmt->fetchAll(PDO::FETCH_COLUMN);

    $conn->beginTransaction();
    foreach ($noteIds as $noteId) {
        documentsDetachParent($conn, 'ticket_note', (int) $noteId);
    }
    foreach ($ids as $tid) {
        documentsDetachParent($conn, 'ticket', (int) $tid);
    }
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
