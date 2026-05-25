<?php
/**
 * API Endpoint: Get ticket audit history
 * Returns all audit entries for a ticket
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
    echo json_encode(['success' => false, 'error' => 'Ticket ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT
                ta.id,
                ta.ticket_id,
                ta.field_name,
                ta.old_value,
                ta.new_value,
                ta.created_datetime,
                a.full_name as analyst_name
            FROM ticket_audit ta
            LEFT JOIN analysts a ON ta.analyst_id = a.id
            WHERE ta.ticket_id = ?
            ORDER BY ta.created_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticketId]);
    $audit = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'audit' => $audit
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
