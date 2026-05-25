<?php
/**
 * API Endpoint: Get all database tables with reference counts
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

    $scanStmt = $conn->prepare("SELECT id FROM wiki_scan_runs WHERE status = 'completed' ORDER BY id DESC LIMIT 1");
    $scanStmt->execute();
    $scan = $scanStmt->fetch(PDO::FETCH_ASSOC);

    if (!$scan) {
        echo json_encode(['success' => true, 'tables' => []]);
        exit;
    }

    $scanId = (int)$scan['id'];

    $stmt = $conn->prepare("SELECT dr.table_name,
                                   COUNT(*) as reference_count,
                                   COUNT(DISTINCT dr.file_id) as file_count,
                                   SUM(CASE WHEN dr.reference_type = 'SELECT' THEN 1 ELSE 0 END) as select_count,
                                   SUM(CASE WHEN dr.reference_type = 'INSERT' THEN 1 ELSE 0 END) as insert_count,
                                   SUM(CASE WHEN dr.reference_type = 'UPDATE' THEN 1 ELSE 0 END) as update_count,
                                   SUM(CASE WHEN dr.reference_type = 'DELETE' THEN 1 ELSE 0 END) as delete_count,
                                   SUM(CASE WHEN dr.reference_type = 'JOIN' THEN 1 ELSE 0 END) as join_count
                            FROM wiki_db_references dr
                            INNER JOIN wiki_files f ON dr.file_id = f.id
                            WHERE f.scan_id = $scanId
                            GROUP BY dr.table_name
                            ORDER BY dr.table_name");
    $stmt->execute();
    $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tables as &$t) {
        $t['reference_count'] = (int)$t['reference_count'];
        $t['file_count'] = (int)$t['file_count'];
        $t['select_count'] = (int)$t['select_count'];
        $t['insert_count'] = (int)$t['insert_count'];
        $t['update_count'] = (int)$t['update_count'];
        $t['delete_count'] = (int)$t['delete_count'];
        $t['join_count'] = (int)$t['join_count'];
    }

    echo json_encode(['success' => true, 'tables' => $tables]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
