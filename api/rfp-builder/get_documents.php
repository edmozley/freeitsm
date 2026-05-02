<?php
/**
 * List documents for an RFP, with department info and a character count.
 * raw_text itself is NOT returned — fetch via get_document_text.php.
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
    $rfp_id = isset($_GET['rfp_id']) ? (int)$_GET['rfp_id'] : 0;
    if ($rfp_id <= 0) {
        throw new Exception('Missing or invalid rfp_id');
    }

    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT d.id, d.rfp_id, d.department_id, d.filename, d.original_filename,
                d.status, d.uploaded_datetime, d.updated_datetime,
                COALESCE(CHAR_LENGTH(d.raw_text), 0) AS char_count,
                CASE WHEN d.raw_text IS NULL OR d.raw_text = '' THEN 0 ELSE 1 END AS has_text,
                (SELECT COUNT(*) FROM rfp_extracted_requirements er WHERE er.document_id = d.id) AS extracted_count,
                dept.name AS department_name, dept.colour AS department_colour
           FROM rfp_documents d
      LEFT JOIN rfp_departments dept ON d.department_id = dept.id
          WHERE d.rfp_id = ?
       ORDER BY d.uploaded_datetime DESC"
    );
    $stmt->execute([$rfp_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['has_text']        = (bool)$r['has_text'];
        $r['char_count']      = (int)$r['char_count'];
        $r['extracted_count'] = (int)$r['extracted_count'];
    }

    echo json_encode(['success' => true, 'documents' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
