<?php
/**
 * API Endpoint: Delete supplier type
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
    $id = $data['id'] ?? null;

    if (!$id) {
        throw new Exception('ID is required');
    }

    $conn = connectToDatabase();

    // Nullify any suppliers referencing this type before deleting
    $stmt = $conn->prepare("UPDATE suppliers SET supplier_type_id = NULL WHERE supplier_type_id = ?");
    $stmt->execute([$id]);

    $stmt = $conn->prepare("DELETE FROM supplier_types WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
