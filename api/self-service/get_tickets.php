<?php
/**
 * API: Get Self-Service User's Tickets
 * GET - Returns all tickets for the logged-in user, with optional status filter
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = (int)$_SESSION['ss_user_id'];
$statusFilter = $_GET['status'] ?? '';

try {
    $conn = connectToDatabase();

    $sql = "SELECT t.id, t.ticket_number, t.subject, t.status, t.priority,
                   t.created_datetime, t.updated_datetime,
                   d.name as department_name,
                   a.full_name as assigned_analyst_name
            FROM tickets t
            LEFT JOIN departments d ON t.department_id = d.id
            LEFT JOIN analysts a ON t.assigned_analyst_id = a.id
            WHERE t.user_id = ?";
    $params = [$userId];

    if (!empty($statusFilter)) {
        $sql .= " AND t.status = ?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY t.created_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'tickets' => $tickets]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
