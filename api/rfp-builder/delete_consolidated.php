<?php
/**
 * Delete a single consolidated requirement.
 * The M:N source links and any conflicts referencing this row are
 * cleaned up automatically by ON DELETE CASCADE.
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
    if ($id <= 0) throw new Exception('Missing or invalid id');

    $conn = connectToDatabase();

    $row = $conn->prepare("SELECT rfp_id FROM rfp_consolidated_requirements WHERE id = ?");
    $row->execute([$id]);
    $existing = $row->fetch(PDO::FETCH_ASSOC);
    if (!$existing) throw new Exception('Consolidated requirement not found');

    $del = $conn->prepare("DELETE FROM rfp_consolidated_requirements WHERE id = ?");
    $del->execute([$id]);

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([(int)$existing['rfp_id']]);

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
