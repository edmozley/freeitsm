<?php
/**
 * API Endpoint: Serve/download an attachment file
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}
requireModuleAccessJson('changes');

$attachmentId = (int)($_GET['id'] ?? 0);

if (!$attachmentId) {
    http_response_code(400);
    echo 'Attachment ID required';
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT change_id, file_name, file_path, file_type FROM change_attachments WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$attachmentId]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        http_response_code(404);
        echo 'Attachment not found';
        exit;
    }

    // Company isolation: don't serve a file on a change outside the analyst's scope.
    if (!analystCanAccessChange($conn, (int)$_SESSION['analyst_id'], (int)$attachment['change_id'])) {
        http_response_code(404);
        echo 'Attachment not found';
        exit;
    }

    // The bytes are in the changes store (a server folder or S3, System -> Storage).
    // ⚠️ file_path comes from a database row this endpoint does not write: the store
    // refuses a key that could leave it and, on a server folder, a file that resolves
    // outside it. A stored value of "../../config.php" is "not found".
    require_once __DIR__ . '/../../includes/storage/stores.php';
    $key = (string)$attachment['file_path'];
    try {
        $size = storeSize($conn, 'changes', $key);
    } catch (StorageUnavailable $e) {
        http_response_code(503);
        echo 'Storage cannot be reached right now. Try again in a few minutes.';
        exit;
    }
    if ($size === null) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    // ⚠️ Always a download, never rendered. `attachment` plus nosniff means a
    // file whose contents look like HTML cannot run in the analyst's session
    // even if it slipped past validation, and octet-stream ensures the browser
    // is never invited to interpret it.
    $safeName = str_replace(['"', "\r", "\n"], '', (string)$attachment['file_name']);
    if ($safeName === '') $safeName = 'attachment';

    $code = storeServe($conn, 'changes', $key, 0, max(0, $size - 1), function () use ($safeName, $size) {
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
        header('Content-Length: ' . $size);
        header('Cache-Control: no-cache');
    });
    if ($code !== 0) {
        http_response_code($code === 503 ? 503 : 404);
        echo $code === 503 ? 'Storage cannot be reached right now.' : 'File not found';
    }
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage();
}
?>
