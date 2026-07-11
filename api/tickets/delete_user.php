<?php
/**
 * API Endpoint: Delete an end user
 *
 * Refuses to delete if the user is referenced by any tickets or asset assignments,
 * so analyst-visible history is never silently broken.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$data = json_decode(file_get_contents('php://input'), true);
$id = isset($data['id']) ? (int)$data['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'User id is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ?");
    $stmt->execute([$id]);
    $ticketCount = (int)$stmt->fetchColumn();

    if ($ticketCount > 0) {
        echo json_encode([
            'success' => false,
            'error'   => "Cannot delete: this user is the requester on $ticketCount ticket(s). Reassign or close those tickets first."
        ]);
        exit;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM users_assets WHERE user_id = ?");
    $stmt->execute([$id]);
    $assetCount = (int)$stmt->fetchColumn();

    if ($assetCount > 0) {
        echo json_encode([
            'success' => false,
            'error'   => "Cannot delete: this user has $assetCount asset assignment(s). Unassign those assets first."
        ]);
        exit;
    }

    $del = $conn->prepare("DELETE FROM users WHERE id = ?");
    $del->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'User deleted']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
