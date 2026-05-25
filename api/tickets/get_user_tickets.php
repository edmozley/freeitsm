<?php
/**
 * API Endpoint: Get tickets for a specific user
 * Returns all tickets associated with a user
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

// Get user ID from request
$userId = $_GET['user_id'] ?? null;

if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'User ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT
                t.id,
                t.ticket_number,
                t.subject,
                ts.name AS status,
                ts.colour AS status_colour,
                tp.name AS priority,
                t.created_datetime,
                t.updated_datetime,
                d.name as department_name,
                a.full_name as assigned_analyst_name
            FROM tickets t
            LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
            LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
            LEFT JOIN departments d ON t.department_id = d.id
            LEFT JOIN analysts a ON t.assigned_analyst_id = a.id
            WHERE t.user_id = ?
            ORDER BY t.created_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$userId]);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'tickets' => $tickets
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
