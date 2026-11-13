<?php
/**
 * API Endpoint: Serve email attachment
 * Returns attachment file by ID or content_id
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/uploads.php';   // attachmentSendHeaders()

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    exit('Not authenticated');
}
requireModuleAccessJson('tickets');

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

    // The bytes are in the tickets store (a server folder or S3, System -> Storage).
    // ⚠️ Containment is the store's job now: it refuses a key that could leave the
    // store, and - on a server folder - a file that RESOLVES outside it (a symlink
    // planted inside), exactly as this endpoint used to check by hand. A stored
    // `../../config.php`, from a future bug, an older row or anyone with a foothold
    // in the database, is "not found".
    require_once __DIR__ . '/../../includes/storage/stores.php';
    $key = (string)$attachment['file_path'];
    try {
        $actual = storeSize($conn, 'tickets', $key);
    } catch (StorageUnavailable $e) {
        http_response_code(503);
        exit('Storage cannot be reached right now. Try again in a few minutes.');
    }
    if ($actual === null) {
        http_response_code(404);
        exit('Attachment file not found');
    }

    // ⚠️ The stored content_type is whatever the SENDER put on the MIME part, and it
    // used to be echoed straight into the header — so `image/svg+xml` satisfied the
    // old `strpos($ct, 'image/') === 0` inline test, and inbox.js opens attachments
    // with window.open(), i.e. as a top-level document on our own origin. SVG is XML
    // and runs <script>. nosniff cannot help when the declared type is the executable
    // one. The type is now derived from the file extension against our own map, and
    // anything unrecognised downloads as octet-stream. See includes/uploads.php.
    $code = storeServe($conn, 'tickets', $key, 0, max(0, $actual - 1), function () use ($attachment) {
        // For inline images, allow browser caching
        header('Cache-Control: private, max-age=86400');
        attachmentSendHeaders((string)$attachment['filename'], (int)$attachment['file_size']);
    });
    if ($code !== 0) {
        http_response_code($code === 503 ? 503 : 404);
        exit($code === 503 ? 'Storage cannot be reached right now.' : 'Attachment file not found');
    }

} catch (Exception $e) {
    http_response_code(500);
    exit('Error: ' . $e->getMessage());
}

?>
