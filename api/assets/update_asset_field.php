<?php
/**
 * API Endpoint: Update a single field on an asset (type or status)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $asset_id = $data['asset_id'] ?? null;
    $field = $data['field'] ?? '';
    $value = $data['value'] ?? null;

    if (!$asset_id) {
        throw new Exception('Asset ID is required');
    }

    // Whitelist allowed fields to prevent SQL injection
    $allowedFields = ['asset_type_id', 'asset_status_id'];
    if (!in_array($field, $allowedFields)) {
        throw new Exception('Invalid field');
    }

    // Convert empty string to null
    if ($value === '' || $value === null) {
        $value = null;
    }

    $conn = connectToDatabase();

    $sql = "UPDATE assets SET $field = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$value, $asset_id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
