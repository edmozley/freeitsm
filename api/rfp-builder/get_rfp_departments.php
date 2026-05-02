<?php
/**
 * API Endpoint: Get RFP departments
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
    $conn = connectToDatabase();

    $sql = "SELECT id, name, colour, sort_order, is_active, created_datetime
              FROM rfp_departments
             ORDER BY sort_order, name";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['is_active'] = (bool)$r['is_active'];
        $r['sort_order'] = (int)$r['sort_order'];
    }

    echo json_encode(['success' => true, 'rfp_departments' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
