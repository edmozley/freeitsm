<?php
/**
 * Delete a document. The on-disk file is removed too.
 * Cascade to rfp_extracted_requirements / rfp_consolidated_sources is
 * handled by FK ON DELETE CASCADE.
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
    if ($id <= 0) {
        throw new Exception('Missing or invalid id');
    }

    $conn = connectToDatabase();
    $stmt = $conn->prepare("SELECT file_path, rfp_id FROM rfp_documents WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        throw new Exception('Document not found');
    }

    $del = $conn->prepare("DELETE FROM rfp_documents WHERE id = ?");
    $del->execute([$id]);

    if ($doc['file_path'] && file_exists($doc['file_path'])) {
        @unlink($doc['file_path']);
    }

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([$doc['rfp_id']]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
