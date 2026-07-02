<?php
/**
 * API Endpoint (internal, session): permanently delete a REST API v1 key.
 * Its rate-limit rows cascade away with it.
 */
session_start(['read_and_close' => true]);
require_once '../../../config.php';
require_once '../../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || empty($input['id'])) {
        throw new Exception('Key ID is required');
    }

    $conn = connectToDatabase();
    $stmt = $conn->prepare("DELETE FROM api_keys WHERE id = ?");
    $stmt->execute([(int)$input['id']]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Key not found');
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
