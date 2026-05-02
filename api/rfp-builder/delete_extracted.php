<?php
/**
 * Delete a single extracted requirement.
 * Cascade to rfp_consolidated_sources is handled by FK ON DELETE CASCADE.
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
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('Missing id');

    $conn = connectToDatabase();
    $look = $conn->prepare("SELECT rfp_id FROM rfp_extracted_requirements WHERE id = ?");
    $look->execute([$id]);
    $rfpId = $look->fetchColumn();
    if (!$rfpId) throw new Exception('Requirement not found');

    $del = $conn->prepare("DELETE FROM rfp_extracted_requirements WHERE id = ?");
    $del->execute([$id]);

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([$rfpId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
