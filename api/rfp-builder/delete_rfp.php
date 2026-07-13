<?php
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
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;
    if (!$id) {
        throw new Exception('ID is required');
    }

    $conn = connectToDatabase();

    // Collect file paths for any uploaded documents so we can clean them up after the cascade delete.
    $stmt = $conn->prepare("SELECT file_path FROM rfp_documents WHERE rfp_id = ?");
    $stmt->execute([$id]);
    $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Cascade is handled by FK ON DELETE CASCADE on every child table.
    $del = $conn->prepare("DELETE FROM rfps WHERE id = ?");
    $del->execute([$id]);

    foreach ($paths as $p) {
        if ($p && file_exists($p)) {
            @unlink($p);
        }
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
