<?php
/**
 * API Endpoint: Global search across files, functions, and tables
 * Query params: ?q=searchterm&type=all|files|functions|tables
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : 'all';

if (strlen($q) < 2) {
    echo json_encode(['success' => false, 'error' => 'Search query must be at least 2 characters']);
    exit;
}

try {
    $conn = connectToDatabase();

    $scanStmt = $conn->prepare("SELECT id FROM wiki_scan_runs WHERE status = 'completed' ORDER BY id DESC LIMIT 1");
    $scanStmt->execute();
    $scan = $scanStmt->fetch(PDO::FETCH_ASSOC);

    if (!$scan) {
        echo json_encode(['success' => true, 'results' => ['files' => [], 'functions' => [], 'tables' => []]]);
        exit;
    }

    $scanId = (int)$scan['id'];
    $pattern = '%' . $q . '%';
    $results = ['files' => [], 'functions' => [], 'tables' => []];

    // Search files
    if ($type === 'all' || $type === 'files') {
        $stmt = $conn->prepare("SELECT f.id, f.file_path, f.file_name, f.folder_path, f.file_type, f.line_count, f.description
                                FROM wiki_files f
                                WHERE f.scan_id = $scanId AND (f.file_name LIKE ? OR f.file_path LIKE ? OR f.description LIKE ?)
                                ORDER BY f.file_path");
        $stmt->execute([$pattern, $pattern, $pattern]);
        $results['files'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Search functions
    if ($type === 'all' || $type === 'functions') {
        $stmt = $conn->prepare("SELECT fn.id, fn.function_name, fn.line_number, fn.parameters, fn.description,
                                       f.id as file_id, f.file_path, f.file_name
                                FROM wiki_functions fn
                                INNER JOIN wiki_files f ON fn.file_id = f.id
                                WHERE f.scan_id = $scanId AND (fn.function_name LIKE ? OR fn.description LIKE ?)
                                ORDER BY fn.function_name");
        $stmt->execute([$pattern, $pattern]);
        $results['functions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Search tables
    if ($type === 'all' || $type === 'tables') {
        $stmt = $conn->prepare("SELECT dr.table_name,
                                       COUNT(*) as reference_count,
                                       COUNT(DISTINCT dr.file_id) as file_count
                                FROM wiki_db_references dr
                                INNER JOIN wiki_files f ON dr.file_id = f.id
                                WHERE f.scan_id = $scanId AND dr.table_name LIKE ?
                                GROUP BY dr.table_name
                                ORDER BY dr.table_name");
        $stmt->execute([$pattern]);
        $results['tables'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'results' => $results]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
