<?php
/**
 * API Endpoint: Get supplier statuses
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
    $conn = connectToDatabase();

    $sql = "SELECT id, name, description, is_active, display_order, created_datetime
            FROM supplier_statuses
            ORDER BY display_order, name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $supplier_statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($supplier_statuses as &$status) {
        $status['is_active'] = (bool)$status['is_active'];
    }

    echo json_encode([
        'success' => true,
        'supplier_statuses' => $supplier_statuses
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
