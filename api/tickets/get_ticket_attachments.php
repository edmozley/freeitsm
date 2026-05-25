<?php
/**
 * API Endpoint: Get attachments for a ticket
 * Returns all attachments from all emails in a ticket
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$ticketId = $_GET['ticket_id'] ?? null;

if (!$ticketId) {
    echo json_encode(['success' => false, 'error' => 'Ticket ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get all attachments for all emails in this ticket (including inline attachments)
    $sql = "SELECT
                ea.id,
                ea.email_id,
                ea.filename,
                ea.content_type,
                ea.file_path,
                ea.file_size,
                ea.is_inline,
                e.from_address,
                e.from_name,
                e.received_datetime
            FROM email_attachments ea
            INNER JOIN emails e ON ea.email_id = e.id
            WHERE e.ticket_id = ?
            ORDER BY ea.is_inline ASC, e.received_datetime DESC, ea.filename";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticketId]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format dates
    foreach ($attachments as &$attachment) {
        if ($attachment['received_datetime']) {
            $attachment['received_datetime'] = date('Y-m-d\TH:i:s', strtotime($attachment['received_datetime']));
        }
    }

    echo json_encode([
        'success' => true,
        'attachments' => $attachments,
        'count' => count($attachments)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
