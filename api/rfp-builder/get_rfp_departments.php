<?php
/**
 * API Endpoint: Get RFP departments
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// The RFP Builder is part of the Contracts module — its PAGES have always checked
// this, its endpoints never did. Any logged-in analyst could read, edit or delete
// any RFP by calling the API directly. (Found by debug tool D005.)
requireModuleAccessJson('contracts');
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
