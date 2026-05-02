<?php
/**
 * Re-run DOCX text extraction on the on-disk file for a document.
 * Useful when initial extraction failed (status = 'error') or after the
 * file has been replaced.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rfp_docx_parser.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$conn = null;
$doc  = null;

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) {
        throw new Exception('Missing or invalid id');
    }

    $conn = connectToDatabase();
    $stmt = $conn->prepare("SELECT id, file_path, rfp_id FROM rfp_documents WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        throw new Exception('Document not found');
    }

    if (!$doc['file_path'] || !file_exists($doc['file_path'])) {
        $upd = $conn->prepare(
            "UPDATE rfp_documents SET status = 'error', updated_datetime = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $upd->execute([$id]);
        throw new Exception('Source file is missing on disk');
    }

    $rawText = rfpExtractDocxText($doc['file_path']);

    $upd = $conn->prepare(
        "UPDATE rfp_documents
            SET raw_text = ?, status = 'extracted', updated_datetime = CURRENT_TIMESTAMP
          WHERE id = ?"
    );
    $upd->execute([$rawText, $id]);

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([$doc['rfp_id']]);

    echo json_encode([
        'success'    => true,
        'id'         => $id,
        'status'     => 'extracted',
        'word_count' => str_word_count($rawText),
        'char_count' => mb_strlen($rawText),
    ]);
} catch (Exception $e) {
    if ($conn !== null && $doc !== null) {
        try {
            $conn->prepare(
                "UPDATE rfp_documents SET status = 'error', updated_datetime = CURRENT_TIMESTAMP WHERE id = ?"
            )->execute([$doc['id']]);
        } catch (Exception $_) { /* swallow */ }
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
