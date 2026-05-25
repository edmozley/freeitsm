<?php
/**
 * API Endpoint: Get files referencing a specific database table
 * Query param: ?table=tickets
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$tableName = isset($_GET['table']) ? trim($_GET['table']) : '';
if (empty($tableName)) {
    echo json_encode(['success' => false, 'error' => 'Table name required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $scanStmt = $conn->prepare("SELECT id FROM wiki_scan_runs WHERE status = 'completed' ORDER BY id DESC LIMIT 1");
    $scanStmt->execute();
    $scan = $scanStmt->fetch(PDO::FETCH_ASSOC);

    if (!$scan) {
        echo json_encode(['success' => true, 'table_name' => $tableName, 'references' => []]);
        exit;
    }

    $scanId = (int)$scan['id'];

    $stmt = $conn->prepare("SELECT dr.reference_type, dr.line_number,
                                   f.id as file_id, f.file_path, f.file_name, f.folder_path
                            FROM wiki_db_references dr
                            INNER JOIN wiki_files f ON dr.file_id = f.id
                            WHERE dr.table_name = ? AND f.scan_id = $scanId
                            ORDER BY dr.reference_type, f.file_path");
    $stmt->execute([$tableName]);
    $references = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'table_name' => $tableName,
        'references' => $references
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
