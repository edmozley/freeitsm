<?php
/**
 * Upload a .docx requirements document for an RFP.
 * Saves the file to disk under contracts/rfp-builder/uploads/, inserts
 * a row in rfp_documents, and runs DOCX text extraction inline.
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

const RFP_UPLOAD_MAX_BYTES = 20 * 1024 * 1024; // 20 MB
$uploadDir = __DIR__ . '/../../contracts/rfp-builder/uploads/';

try {
    $rfp_id = isset($_POST['rfp_id']) ? (int)$_POST['rfp_id'] : 0;
    if ($rfp_id <= 0) {
        throw new Exception('Missing or invalid rfp_id');
    }
    $department_id = (isset($_POST['department_id']) && $_POST['department_id'] !== '')
        ? (int)$_POST['department_id'] : null;

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        throw new Exception('No file uploaded');
    }
    $file = $_FILES['file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large (server limit)',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file selected',
            UPLOAD_ERR_NO_TMP_DIR => 'Server has no temp directory',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the file',
            default               => 'Upload failed (error code ' . $file['error'] . ')',
        };
        throw new Exception($msg);
    }
    if ($file['size'] > RFP_UPLOAD_MAX_BYTES) {
        throw new Exception('File too large (max 20 MB)');
    }
    $original = $file['name'];
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($ext !== 'docx') {
        throw new Exception('Only .docx files are supported');
    }

    $conn = connectToDatabase();

    // Verify the RFP exists.
    $check = $conn->prepare("SELECT id FROM rfps WHERE id = ?");
    $check->execute([$rfp_id]);
    if (!$check->fetch()) {
        throw new Exception('RFP not found');
    }

    // Verify the department, if supplied, exists and is active.
    if ($department_id !== null) {
        $deptCheck = $conn->prepare("SELECT id FROM rfp_departments WHERE id = ? AND is_active = 1");
        $deptCheck->execute([$department_id]);
        if (!$deptCheck->fetch()) {
            throw new Exception('Department not found or inactive');
        }
    }

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new Exception('Failed to create upload directory');
        }
    }

    $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($original, PATHINFO_FILENAME));
    $safeBase = trim((string)$safeBase, '_');
    if ($safeBase === '') $safeBase = 'doc';
    $safeBase = substr($safeBase, 0, 60);
    $stored = sprintf('rfp%d_%d_%s_%s.docx', $rfp_id, time(), bin2hex(random_bytes(4)), $safeBase);
    $destPath = $uploadDir . $stored;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new Exception('Failed to save uploaded file');
    }

    $insert = $conn->prepare(
        "INSERT INTO rfp_documents (rfp_id, department_id, filename, original_filename, file_path, status)
         VALUES (?, ?, ?, ?, ?, 'uploaded')"
    );
    $insert->execute([$rfp_id, $department_id, $stored, $original, $destPath]);
    $documentId = (int)$conn->lastInsertId();

    // Bump RFP last-activity timestamp.
    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")->execute([$rfp_id]);

    // Try to extract text inline. Failure here doesn't roll back the upload — the
    // user can re-extract once they've fixed the file (or it's a corrupt .docx).
    try {
        $rawText = rfpExtractDocxText($destPath);
        $upd = $conn->prepare(
            "UPDATE rfp_documents
                SET raw_text = ?, status = 'extracted', updated_datetime = CURRENT_TIMESTAMP
              WHERE id = ?"
        );
        $upd->execute([$rawText, $documentId]);

        echo json_encode([
            'success'    => true,
            'id'         => $documentId,
            'status'     => 'extracted',
            'word_count' => str_word_count($rawText),
            'char_count' => mb_strlen($rawText),
        ]);
    } catch (Exception $extractErr) {
        $upd = $conn->prepare(
            "UPDATE rfp_documents SET status = 'error', updated_datetime = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $upd->execute([$documentId]);
        echo json_encode([
            'success'           => true,
            'id'                => $documentId,
            'status'            => 'error',
            'extraction_error'  => $extractErr->getMessage(),
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
