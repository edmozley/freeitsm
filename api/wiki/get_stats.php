<?php
/**
 * API Endpoint: Get wiki dashboard statistics
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get latest completed scan
    $scanStmt = $conn->prepare("SELECT id, started_at, completed_at, files_scanned, functions_found, classes_found, TIMESTAMPDIFF(SECOND, started_at, completed_at) as duration_seconds FROM wiki_scan_runs WHERE status = 'completed' ORDER BY id DESC LIMIT 1");
    $scanStmt->execute();
    $scan = $scanStmt->fetch(PDO::FETCH_ASSOC);

    if (!$scan) {
        echo json_encode(['success' => true, 'stats' => null, 'message' => 'No scan data available. Run the scanner first.']);
        exit;
    }

    $scanId = (int)$scan['id'];

    // Count distinct DB tables referenced
    $tableStmt = $conn->query("SELECT COUNT(DISTINCT dr.table_name) as cnt FROM wiki_db_references dr INNER JOIN wiki_files f ON dr.file_id = f.id WHERE f.scan_id = $scanId");
    $tableCount = $tableStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

    // Count PHP vs JS files
    $phpStmt = $conn->query("SELECT COUNT(*) as cnt FROM wiki_files WHERE scan_id = $scanId AND file_type = 'PHP'");
    $phpCount = $phpStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

    $jsStmt = $conn->query("SELECT COUNT(*) as cnt FROM wiki_files WHERE scan_id = $scanId AND file_type = 'JS'");
    $jsCount = $jsStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

    // Count distinct folders
    $folderStmt = $conn->query("SELECT COUNT(DISTINCT folder_path) as cnt FROM wiki_files WHERE scan_id = $scanId");
    $folderCount = $folderStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

    echo json_encode([
        'success' => true,
        'stats' => [
            'total_files' => (int)$scan['files_scanned'],
            'php_files' => (int)$phpCount,
            'js_files' => (int)$jsCount,
            'total_functions' => (int)$scan['functions_found'],
            'total_classes' => (int)$scan['classes_found'],
            'total_tables' => (int)$tableCount,
            'total_folders' => (int)$folderCount,
            'last_scan' => $scan['completed_at'],
            'scan_duration_seconds' => (int)$scan['duration_seconds']
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
