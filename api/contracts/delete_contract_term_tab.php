<?php
/**
 * API Endpoint: Delete contract term tab
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('contracts');
requireCapabilityJson(Cap::CONTRACTS_CONTRACT_TERMS);   // Contracts settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;

    if (!$id) {
        throw new Exception('ID is required');
    }

    $conn = connectToDatabase();

    // Delete any term values referencing this tab
    $stmt = $conn->prepare("DELETE FROM contract_term_values WHERE term_tab_id = ?");
    $stmt->execute([$id]);

    $name = $conn->query("SELECT name FROM contract_term_tabs WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM contract_term_tabs WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('contract_term_tab', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
