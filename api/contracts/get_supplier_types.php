<?php
/**
 * API Endpoint: Get supplier types
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
            FROM supplier_types
            ORDER BY display_order, name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $supplier_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($supplier_types as &$type) {
        $type['is_active'] = (bool)$type['is_active'];
    }

    echo json_encode([
        'success' => true,
        'supplier_types' => $supplier_types
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
