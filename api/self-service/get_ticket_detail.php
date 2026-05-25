<?php
/**
 * API: Get Ticket Detail for Self-Service User
 * GET ?ticket_id=X - Returns ticket info, email thread, and non-internal notes
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = (int)$_SESSION['ss_user_id'];
$ticketId = (int)($_GET['ticket_id'] ?? 0);

if (!$ticketId) {
    echo json_encode(['success' => false, 'error' => 'Ticket ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Fetch ticket - validate ownership
    $ticketStmt = $conn->prepare(
        "SELECT t.id, t.ticket_number, t.subject,
                ts.name AS status, ts.colour AS status_colour,
                tp.name AS priority,
                t.created_datetime, t.updated_datetime,
                d.name as department_name
         FROM tickets t
         LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
         LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
         LEFT JOIN departments d ON t.department_id = d.id
         WHERE t.id = ? AND t.user_id = ?"
    );
    $ticketStmt->execute([$ticketId, $userId]);
    $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    // Fetch email thread
    $threadStmt = $conn->prepare(
        "SELECT id, from_name, received_datetime, body_content, direction
         FROM emails
         WHERE ticket_id = ?
         ORDER BY received_datetime ASC"
    );
    $threadStmt->execute([$ticketId]);
    $thread = $threadStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch non-internal notes only
    $notesStmt = $conn->prepare(
        "SELECT n.note_text, n.created_datetime, a.full_name as analyst_name
         FROM ticket_notes n
         LEFT JOIN analysts a ON n.analyst_id = a.id
         WHERE n.ticket_id = ? AND n.is_internal = 0
         ORDER BY n.created_datetime ASC"
    );
    $notesStmt->execute([$ticketId]);
    $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Screen recordings attached to the ticket
    $recordings = [];
    try {
        $recStmt = $conn->prepare(
            "SELECT id, original_filename, content_type, file_size, duration_seconds, has_audio, created_at
             FROM ticket_recordings WHERE ticket_id = ? ORDER BY created_at ASC"
        );
        $recStmt->execute([$ticketId]);
        $recordings = $recStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table may not exist on installs that haven't run db_verify yet
    }

    echo json_encode([
        'success' => true,
        'ticket' => $ticket,
        'thread' => $thread,
        'notes' => $notes,
        'recordings' => $recordings
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
