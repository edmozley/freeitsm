<?php
/**
 * Delete Ticket Origin API
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

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['id'])) {
        throw new Exception('Origin ID is required');
    }

    $id = intval($input['id']);

    $conn = connectToDatabase();

    // Check if origin is in use
    $checkSql = "SELECT COUNT(*) as count FROM tickets WHERE origin_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$id]);
    $count = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];

    if ($count > 0) {
        throw new Exception("Cannot delete: this origin is assigned to $count ticket(s). Set to inactive instead.");
    }

    // Delete the origin
    $sql = "DELETE FROM ticket_origins WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);

    echo json_encode([
        'success' => true,
        'message' => 'Ticket origin deleted successfully'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
