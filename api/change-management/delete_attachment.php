<?php
/**
 * API Endpoint: Delete an attachment from a change
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

$input = json_decode(file_get_contents('php://input'), true);
$attachmentId = (int)($input['id'] ?? 0);

if (!$attachmentId) {
    echo json_encode(['success' => false, 'error' => 'Attachment ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get file path (+ owning change for the scope check) before deleting
    $sql = "SELECT change_id, file_path FROM change_attachments WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$attachmentId]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        echo json_encode(['success' => false, 'error' => 'Attachment not found']);
        exit;
    }

    // Company isolation: can't delete an attachment on an out-of-scope change.
    if (!analystCanAccessChange($conn, (int)$_SESSION['analyst_id'], (int)$attachment['change_id'])) {
        echo json_encode(['success' => false, 'error' => 'Attachment not found']);
        exit;
    }

    // Delete from DB
    $delSql = "DELETE FROM change_attachments WHERE id = ?";
    $delStmt = $conn->prepare($delSql);
    $delStmt->execute([$attachmentId]);

    // Delete file from disk
    $filePath = dirname(dirname(__DIR__)) . '/change-management/attachments/' . $attachment['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    echo json_encode(['success' => true, 'message' => 'Attachment deleted']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
