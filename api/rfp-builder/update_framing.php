<?php
/**
 * Manually edit a framing section's content — the analyst can hand-tune
 * what the AI drafted. Marks the row is_manually_edited so future
 * regenerations are gated on `force=1` (we don't trample edits silently).
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

    $content = $data['section_content'] ?? '';
    if (!is_string($content)) throw new Exception('Section content must be a string');

    $conn = connectToDatabase();

    $row = $conn->prepare("SELECT rfp_id FROM rfp_document_sections WHERE id = ?");
    $row->execute([$id]);
    $existing = $row->fetch(PDO::FETCH_ASSOC);
    if (!$existing) throw new Exception('Framing section not found');

    $upd = $conn->prepare(
        "UPDATE rfp_document_sections
            SET section_content    = ?,
                is_manually_edited = 1,
                edited_datetime    = CURRENT_TIMESTAMP
          WHERE id = ?"
    );
    $upd->execute([$content, $id]);

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([(int)$existing['rfp_id']]);

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
