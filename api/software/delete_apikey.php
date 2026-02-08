<?php
/**
 * API Endpoint: Delete API Key
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? (int)$input['id'] : null;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Key ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "DELETE FROM apikeys WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'API key deleted']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Key not found']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
