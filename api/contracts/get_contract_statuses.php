<?php
/**
 * API Endpoint: Get contract statuses
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
    $stmt = $conn->query("SELECT id, name, description, is_active, display_order, created_datetime FROM contract_statuses ORDER BY display_order, name");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as &$item) {
        $item['is_active'] = (bool)$item['is_active'];
    }

    echo json_encode(['success' => true, 'contract_statuses' => $items]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
