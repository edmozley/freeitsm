<?php
/**
 * API Endpoint: Upload file attachment to a change
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId = (int)$_SESSION['analyst_id'];
$changeId = (int)($_POST['change_id'] ?? 0);

if (!$changeId) {
    echo json_encode(['success' => false, 'error' => 'Change ID required']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['file'];
$fileName = basename($file['name']);
$fileSize = $file['size'];
$fileType = $file['type'];

// Create attachment directory if it doesn't exist
$uploadDir = dirname(dirname(__DIR__)) . '/change-management/attachments/' . $changeId;
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Ensure unique filename if file already exists
$destPath = $uploadDir . '/' . $fileName;
if (file_exists($destPath)) {
    $info = pathinfo($fileName);
    $base = $info['filename'];
    $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
    $counter = 1;
    while (file_exists($destPath)) {
        $fileName = $base . '_' . $counter . $ext;
        $destPath = $uploadDir . '/' . $fileName;
        $counter++;
    }
}

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit;
}

// Store relative path in DB
$relativePath = $changeId . '/' . $fileName;

try {
    $conn = connectToDatabase();

    if (!analystCanAccessChange($conn, $analystId, $changeId)) {
        echo json_encode(['success' => false, 'error' => 'Change not found']);
        exit;
    }

    $sql = "INSERT INTO change_attachments (change_id, file_name, file_path, file_size, file_type, uploaded_by_id, uploaded_datetime)
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$changeId, $fileName, $relativePath, $fileSize, $fileType, $analystId]);
    $attachmentId = $conn->lastInsertId();

    echo json_encode([
        'success' => true,
        'attachment_id' => $attachmentId,
        'message' => 'File uploaded successfully'
    ]);

} catch (Exception $e) {
    // Clean up file if DB insert failed
    if (file_exists($destPath)) {
        unlink($destPath);
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
