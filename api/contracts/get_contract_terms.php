<?php
/**
 * API Endpoint: Get contract term values for a contract
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
    $contract_id = $_GET['contract_id'] ?? null;
    if (!$contract_id) {
        throw new Exception('contract_id is required');
    }

    $conn = connectToDatabase();
    $stmt = $conn->prepare("SELECT id, contract_id, term_tab_id, content, created_datetime, updated_datetime FROM contract_term_values WHERE contract_id = ?");
    $stmt->execute([$contract_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'contract_terms' => $items]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
