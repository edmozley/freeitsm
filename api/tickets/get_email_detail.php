<?php
/**
 * API Endpoint: Get email details
 * Returns full email content for display in reading pane
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

// Get email ID or ticket ID from request
$emailId = $_GET['id'] ?? null;
$ticketId = $_GET['ticket_id'] ?? null;

if (!$emailId && !$ticketId) {
    echo json_encode(['success' => false, 'error' => 'Email ID or Ticket ID required']);
    exit;
}

try {
    // Connect to database
    $conn = connectToDatabase();

    // Query full email details with ticket information
    $sql = "SELECT
                e.id,
                e.exchange_message_id,
                e.from_address,
                e.from_name,
                e.to_recipients,
                e.cc_recipients,
                e.received_datetime,
                e.body_preview,
                e.body_content,
                e.body_type,
                e.has_attachments,
                e.importance,
                e.is_read,
                e.ticket_id,
                e.is_initial,
                e.direction,
                t.ticket_number,
                t.subject,
                ts.name AS status,
                tp.name AS priority,
                t.priority_id,
                t.department_id,
                t.ticket_type_id,
                t.assigned_analyst_id,
                t.origin_id,
                t.first_time_fix,
                t.it_training_provided,
                t.owner_id,
                t.work_start_datetime,
                t.created_datetime as ticket_created,
                t.updated_datetime as ticket_updated
            FROM emails e
            INNER JOIN tickets t ON e.ticket_id = t.id
            LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
            LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
            WHERE ";

    // Look up by email ID or by ticket ID (gets the initial email for the ticket)
    if ($emailId) {
        $sql .= "e.id = ?";
        $param = $emailId;
    } else {
        $sql .= "e.ticket_id = ? AND e.is_initial = 1";
        $param = $ticketId;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute([$param]);
    $email = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$email) {
        echo json_encode(['success' => false, 'error' => 'Email not found']);
        exit;
    }

    // Clean body_content of control characters and Unicode replacement characters
    if ($email['body_content']) {
        // Remove control characters (0x00-0x1F except tab, newline, carriage return, and 0x7F)
        $email['body_content'] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $email['body_content']);
        // Remove Unicode replacement character (U+FFFD = 0xEF 0xBF 0xBD in UTF-8)
        $email['body_content'] = str_replace("\xEF\xBF\xBD", '', $email['body_content']);
    }

    // Format date for display
    if ($email['received_datetime']) {
        $email['received_datetime'] = date('Y-m-d\TH:i:s', strtotime($email['received_datetime']));
    }

    // Convert bit fields to boolean
    $email['is_read'] = (bool)$email['is_read'];
    $email['has_attachments'] = (bool)$email['has_attachments'];
    $email['first_time_fix'] = $email['first_time_fix'] === null ? null : (bool)$email['first_time_fix'];
    $email['it_training_provided'] = $email['it_training_provided'] === null ? null : (bool)$email['it_training_provided'];

    // Mark email as read if not already
    if (!$email['is_read']) {
        $updateSql = "UPDATE emails SET is_read = 1 WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$emailId]);
    }

    // Screen recordings attached to the ticket
    $recordings = [];
    try {
        $recStmt = $conn->prepare(
            "SELECT id, original_filename, content_type, file_size, duration_seconds, has_audio, created_at
             FROM ticket_recordings WHERE ticket_id = ? ORDER BY created_at ASC"
        );
        $recStmt->execute([(int)$email['ticket_id']]);
        $recordings = $recStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table may not exist on installs that haven't run db_verify yet
    }

    echo json_encode([
        'success' => true,
        'email' => $email,
        'recordings' => $recordings
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
