<?php
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
    $search = $_GET['search'] ?? '';

    $sql = "SELECT r.id, r.name, r.status, r.contract_id, r.chosen_supplier_id,
                   r.created_by_analyst_id, r.created_datetime, r.updated_datetime,
                   a.full_name AS created_by_name,
                   c.title AS contract_title,
                   s.legal_name AS chosen_supplier_name,
                   (SELECT COUNT(*) FROM rfp_documents WHERE rfp_id = r.id) AS document_count,
                   (SELECT COUNT(*) FROM rfp_consolidated_requirements WHERE rfp_id = r.id) AS consolidated_count,
                   (SELECT COUNT(*) FROM rfp_invited_suppliers WHERE rfp_id = r.id) AS supplier_count
            FROM rfps r
            LEFT JOIN analysts a ON r.created_by_analyst_id = a.id
            LEFT JOIN contracts c ON r.contract_id = c.id
            LEFT JOIN suppliers s ON r.chosen_supplier_id = s.id";

    $params = [];
    if (!empty($search)) {
        $sql .= " WHERE r.name LIKE ?";
        $params = ['%' . $search . '%'];
    }

    $sql .= " ORDER BY r.updated_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rfps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'rfps' => $rfps]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
