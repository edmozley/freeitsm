<?php
/**
 * Return a single document's extracted plain text plus word/char counts.
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
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        throw new Exception('Missing or invalid id');
    }

    $conn = connectToDatabase();
    $stmt = $conn->prepare(
        "SELECT id, rfp_id, original_filename, status, raw_text
           FROM rfp_documents WHERE id = ?"
    );
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        throw new Exception('Document not found');
    }

    $text = $doc['raw_text'] ?? '';

    echo json_encode([
        'success'  => true,
        'document' => [
            'id'                => (int)$doc['id'],
            'rfp_id'            => (int)$doc['rfp_id'],
            'original_filename' => $doc['original_filename'],
            'status'            => $doc['status'],
            'raw_text'          => $text,
            'word_count'        => $text === '' ? 0 : str_word_count($text),
            'char_count'        => mb_strlen($text),
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
