<?php
/**
 * API Endpoint: Delete a ticket and all associated data
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

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['ticket_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$ticketId = (int)$data['ticket_id'];

try {
    $conn = connectToDatabase();

    // Check if ticket exists
    $checkSql = "SELECT id FROM tickets WHERE id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$ticketId]);
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    // Delete in order to respect foreign key constraints
    // 1. Delete notes associated with the ticket
    $deleteNotesSql = "DELETE FROM ticket_notes WHERE ticket_id = ?";
    $deleteNotesStmt = $conn->prepare($deleteNotesSql);
    $deleteNotesStmt->execute([$ticketId]);

    // 2. Delete emails associated with the ticket
    $deleteEmailsSql = "DELETE FROM emails WHERE ticket_id = ?";
    $deleteEmailsStmt = $conn->prepare($deleteEmailsSql);
    $deleteEmailsStmt->execute([$ticketId]);

    // 3. Delete the ticket itself
    $deleteTicketSql = "DELETE FROM tickets WHERE id = ?";
    $deleteTicketStmt = $conn->prepare($deleteTicketSql);
    $deleteTicketStmt->execute([$ticketId]);

    echo json_encode(['success' => true, 'message' => 'Ticket deleted successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
