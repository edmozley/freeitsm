<?php
/**
 * API Endpoint: Save contract term values (bulk upsert for a contract)
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

    $contract_id = $data['contract_id'] ?? null;
    $terms = $data['terms'] ?? [];

    if (!$contract_id) {
        throw new Exception('contract_id is required');
    }

    $conn = connectToDatabase();

    foreach ($terms as $term) {
        $term_tab_id = $term['term_tab_id'] ?? null;
        $content = $term['content'] ?? '';

        if (!$term_tab_id) continue;

        // Check if a row already exists
        $stmt = $conn->prepare("SELECT id FROM contract_term_values WHERE contract_id = ? AND term_tab_id = ?");
        $stmt->execute([$contract_id, $term_tab_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $conn->prepare("UPDATE contract_term_values SET content = ?, updated_datetime = NOW() WHERE id = ?");
            $stmt->execute([$content, $existing['id']]);
        } else {
            $stmt = $conn->prepare("INSERT INTO contract_term_values (contract_id, term_tab_id, content) VALUES (?, ?, ?)");
            $stmt->execute([$contract_id, $term_tab_id, $content]);
        }
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
