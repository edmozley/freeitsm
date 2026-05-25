<?php
/**
 * API Endpoint: Search tickets
 * Searches tickets by ticket number, email address, or subject
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
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$ticketNumber = trim($input['ticket_number'] ?? '');
$email = trim($input['email'] ?? '');
$subject = trim($input['subject'] ?? '');

// Validate at least one search criterion
if (empty($ticketNumber) && empty($email) && empty($subject)) {
    echo json_encode(['success' => false, 'error' => 'Please provide at least one search criterion']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Build the query dynamically based on provided criteria
    $conditions = [];
    $params = [];

    if (!empty($ticketNumber)) {
        $conditions[] = "t.ticket_number LIKE ?";
        $params[] = '%' . $ticketNumber . '%';
    }

    if (!empty($email)) {
        $conditions[] = "(e.from_address LIKE ? OR e.to_recipients LIKE ?)";
        $params[] = '%' . $email . '%';
        $params[] = '%' . $email . '%';
    }

    if (!empty($subject)) {
        $conditions[] = "(t.subject LIKE ? OR e.subject LIKE ?)";
        $params[] = '%' . $subject . '%';
        $params[] = '%' . $subject . '%';
    }

    $whereClause = implode(' OR ', $conditions);

    $sql = "SELECT
                e.id as email_id,
                t.id as ticket_id,
                t.ticket_number,
                t.subject,
                ts.name AS status,
                e.from_address,
                e.from_name,
                e.received_datetime
            FROM tickets t
            INNER JOIN emails e ON e.ticket_id = t.id AND e.is_initial = 1
            LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
            WHERE ({$whereClause})
            ORDER BY e.received_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'results' => $results,
        'count' => count($results)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
