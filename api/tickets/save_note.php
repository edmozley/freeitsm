<?php
/**
 * API Endpoint: Save a new note
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $ticket_id = $data['ticket_id'] ?? null;
    $note_text = $data['note_text'] ?? '';
    $is_internal = $data['is_internal'] ?? true;

    if (!$ticket_id) {
        throw new Exception('Ticket ID is required');
    }

    if (empty(trim($note_text))) {
        throw new Exception('Note text is required');
    }

    $conn = connectToDatabase();

    $sql = "INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal)
            VALUES (?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $ticket_id,
        $_SESSION['analyst_id'],
        $note_text,
        $is_internal ? 1 : 0
    ]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
