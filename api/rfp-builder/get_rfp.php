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
    $id = $_GET['id'] ?? null;
    if (!$id) {
        throw new Exception('ID is required');
    }

    $conn = connectToDatabase();

    $sql = "SELECT r.id, r.name, r.status, r.contract_id, r.chosen_supplier_id, r.style_guide,
                   r.created_by_analyst_id, r.created_datetime, r.updated_datetime,
                   a.full_name AS created_by_name,
                   c.title AS contract_title,
                   s.legal_name AS chosen_supplier_name,
                   (SELECT COUNT(*) FROM rfp_documents WHERE rfp_id = r.id) AS document_count,
                   (SELECT COUNT(*) FROM rfp_extracted_requirements WHERE rfp_id = r.id) AS extracted_count,
                   (SELECT COUNT(*) FROM rfp_consolidated_requirements WHERE rfp_id = r.id) AS consolidated_count,
                   (SELECT COUNT(*) FROM rfp_consolidated_requirements WHERE rfp_id = r.id AND is_locked = 1) AS locked_count,
                   (SELECT COUNT(*) FROM rfp_conflicts WHERE rfp_id = r.id AND resolution = 'open') AS open_conflicts,
                   (SELECT COUNT(*) FROM rfp_categories WHERE rfp_id = r.id) AS category_count,
                   (SELECT COUNT(*) FROM rfp_output_sections WHERE rfp_id = r.id) AS section_count,
                   (SELECT COUNT(*) FROM rfp_invited_suppliers WHERE rfp_id = r.id) AS supplier_count
            FROM rfps r
            LEFT JOIN analysts a ON r.created_by_analyst_id = a.id
            LEFT JOIN contracts c ON r.contract_id = c.id
            LEFT JOIN suppliers s ON r.chosen_supplier_id = s.id
            WHERE r.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);
    $rfp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rfp) {
        throw new Exception('RFP not found');
    }

    echo json_encode(['success' => true, 'rfp' => $rfp]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
