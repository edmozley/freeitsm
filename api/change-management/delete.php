<?php
/**
 * API Endpoint: Delete a change record
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$changeId = (int)($input['id'] ?? 0);

if (!$changeId) {
    echo json_encode(['success' => false, 'error' => 'Change ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Delete attachment files from disk
    $attSql = "SELECT file_path FROM change_attachments WHERE change_id = ?";
    $attStmt = $conn->prepare($attSql);
    $attStmt->execute([$changeId]);
    $attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($attachments as $att) {
        $filePath = dirname(dirname(__DIR__)) . '/change-management/attachments/' . $att['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    // Delete attachments from DB (cascade should handle this, but be explicit)
    $delAttSql = "DELETE FROM change_attachments WHERE change_id = ?";
    $delAttStmt = $conn->prepare($delAttSql);
    $delAttStmt->execute([$changeId]);

    // Delete the change
    $sql = "DELETE FROM changes WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$changeId]);

    // Try to clean up the attachment directory
    $dir = dirname(dirname(__DIR__)) . '/change-management/attachments/' . $changeId;
    if (is_dir($dir)) {
        @rmdir($dir);
    }

    echo json_encode(['success' => true, 'message' => 'Change deleted']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
