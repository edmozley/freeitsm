<?php
/**
 * API Endpoint: Get function detail with callers
 * Query param: ?id=456
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$funcId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($funcId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Function ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get function record with file info
    $stmt = $conn->prepare("SELECT fn.*, f.file_path, f.file_name, f.folder_path, f.file_type
                            FROM wiki_functions fn
                            INNER JOIN wiki_files f ON fn.file_id = f.id
                            WHERE fn.id = ?");
    $stmt->execute([$funcId]);
    $func = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$func) {
        echo json_encode(['success' => false, 'error' => 'Function not found']);
        exit;
    }

    // Find callers: files that call this function (excluding the defining file)
    $defFileId = (int)$func['file_id'];
    $callerStmt = $conn->prepare("SELECT fc.line_number, f.id as file_id, f.file_path, f.file_name
                                  FROM wiki_function_calls fc
                                  INNER JOIN wiki_files f ON fc.file_id = f.id
                                  WHERE fc.function_name = ? AND f.id != $defFileId
                                  ORDER BY f.file_path, fc.line_number");
    $callerStmt->execute([$func['function_name']]);
    $callers = $callerStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'function' => $func,
        'callers' => $callers
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
