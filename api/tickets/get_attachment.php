<?php
/**
 * API Endpoint: Serve email attachment
 * Returns attachment file by ID or content_id
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    exit('Not authenticated');
}

// Get attachment identifier
$attachmentId = $_GET['id'] ?? null;
$contentId = $_GET['cid'] ?? null;
$emailId = $_GET['email_id'] ?? null;

if (!$attachmentId && !$contentId) {
    http_response_code(400);
    exit('Attachment ID or Content-ID required');
}

try {
    $conn = connectToDatabase();

    // Build query based on lookup method
    if ($attachmentId) {
        $sql = "SELECT id, email_id, filename, content_type, file_path, file_size
                FROM email_attachments WHERE id = ?";
        $params = [$attachmentId];
    } else {
        // Lookup by content_id (for inline images)
        $sql = "SELECT id, email_id, filename, content_type, file_path, file_size
                FROM email_attachments WHERE content_id = ?";
        $params = [$contentId];

        // If email_id provided, add it to narrow down results
        if ($emailId) {
            $sql .= " AND email_id = ?";
            $params[] = $emailId;
        }
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        http_response_code(404);
        exit('Attachment not found');
    }

    // Multi-tenancy: gate on the attachment's ticket (via its email) so an analyst
    // can't fetch another company's attachment by enumerating ids. No-op at N=1.
    $tq = $conn->prepare("SELECT ticket_id FROM emails WHERE id = ?");
    $tq->execute([$attachment['email_id']]);
    $attTicketId = $tq->fetchColumn();
    if ($attTicketId === false || !analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], (int)$attTicketId)) {
        http_response_code(404);
        exit('Attachment not found');
    }

    // Build full file path
    $filePath = dirname(dirname(__DIR__)) . '/tickets/attachments/' . $attachment['file_path'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        exit('Attachment file not found');
    }

    // Set headers for file download/display
    header('Content-Type: ' . $attachment['content_type']);
    header('Content-Length: ' . $attachment['file_size']);

    // For inline images, allow browser caching
    header('Cache-Control: private, max-age=86400');

    // If it's an image, display inline; otherwise, offer download
    $isImage = strpos($attachment['content_type'], 'image/') === 0;
    if ($isImage) {
        header('Content-Disposition: inline; filename="' . $attachment['filename'] . '"');
    } else {
        header('Content-Disposition: attachment; filename="' . $attachment['filename'] . '"');
    }

    // Output file contents
    readfile($filePath);

} catch (Exception $e) {
    http_response_code(500);
    exit('Error: ' . $e->getMessage());
}

?>
