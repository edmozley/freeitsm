<?php
/**
 * API Endpoint: Toggle whether a supplier is available for assets.
 * Flips suppliers.supplies_assets for a given supplier id.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('assets');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = !empty($data['id']) ? (int)$data['id'] : null;
    $value = !empty($data['supplies_assets']) ? 1 : 0;
    if (!$id) {
        throw new Exception('Missing supplier id');
    }
    $conn = connectToDatabase();
    $stmt = $conn->prepare("UPDATE suppliers SET supplies_assets = ? WHERE id = ?");
    $stmt->execute([$value, $id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
