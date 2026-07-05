<?php
/**
 * API Endpoint: Serve/download an attachment file
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

$attachmentId = (int)($_GET['id'] ?? 0);

if (!$attachmentId) {
    http_response_code(400);
    echo 'Attachment ID required';
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT change_id, file_name, file_path, file_type FROM change_attachments WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$attachmentId]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        http_response_code(404);
        echo 'Attachment not found';
        exit;
    }

    // Company isolation: don't serve a file on a change outside the analyst's scope.
    if (!analystCanAccessChange($conn, (int)$_SESSION['analyst_id'], (int)$attachment['change_id'])) {
        http_response_code(404);
        echo 'Attachment not found';
        exit;
    }

    $filePath = dirname(dirname(__DIR__)) . '/change-management/attachments/' . $attachment['file_path'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        echo 'File not found on disk';
        exit;
    }

    // Set headers for download
    header('Content-Type: ' . ($attachment['file_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . $attachment['file_name'] . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache');

    readfile($filePath);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage();
}
?>
